import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';

import '../../../core/network/api_client.dart';
import '../../../core/network/api_endpoints.dart';
import '../../../core/storage/session_storage.dart';
import '../../../core/utils/attachment_picker.dart';
import '../../../core/utils/formatters.dart';
import '../domain/models.dart';
import '../domain/punch.dart';
import 'offline_sync_engine.dart';
import 'photo_vault.dart';

class AttendanceRepository implements PunchTransport {
  AttendanceRepository(this._api, this._storage, {this.appVersion = '0.0.0'});

  final ApiClient _api;
  final SessionStorage _storage;

  /// Versión instalada (PackageInfo), enviada como `app_version`.
  final String appVersion;

  Future<TodayStatus> today() async {
    final now = DateTime.now();
    final results = await Future.wait([
      _api.get(ApiEndpoints.attendanceToday),
      _api.get(ApiEndpoints.schedule, query: {'from': Fmt.apiDate(now), 'to': Fmt.apiDate(now)}),
    ]);
    final today = results[0];
    final days = (results[1]['data'] as List?) ?? const [];

    return TodayStatus(
      attendance: today['data'] is Map ? Attendance.fromJson(Map<String, dynamic>.from(today['data'] as Map)) : null,
      actions: TodayStatus.parseActions(today['next_action'] as Map?),
      schedule: days.isEmpty ? null : DaySchedule.fromJson(Map<String, dynamic>.from(days.first as Map)),
      clockSkew: TodayStatus.skewFrom(today['server_time'], DateTime.now()),
      notScheduledMessage: TodayStatus.parseNotScheduled(today['schedule']),
    );
  }

  Future<AttendanceHistory> history({required int month, required int year}) async {
    final json = await _api.get(ApiEndpoints.attendanceHistory, query: {'month': month, 'year': year});
    final items = ((json['data'] as List?) ?? const [])
        .map((e) => Attendance.fromJson(Map<String, dynamic>.from(e as Map)))
        .toList();
    return AttendanceHistory(items, HistorySummary.fromJson(Map<String, dynamic>.from((json['summary'] as Map?) ?? {})));
  }

  Future<List<DaySchedule>> schedule(DateTime from, DateTime to) async {
    final json = await _api.get(ApiEndpoints.schedule, query: {'from': Fmt.apiDate(from), 'to': Fmt.apiDate(to)});
    return ((json['data'] as List?) ?? const [])
        .map((e) => DaySchedule.fromJson(Map<String, dynamic>.from(e as Map)))
        .toList();
  }

  Future<List<BranchGeofence>> myBranches() async {
    final json = await _api.get(ApiEndpoints.myBranches);
    return ((json['data'] as List?) ?? const [])
        .map((e) => BranchGeofence.fromJson(Map<String, dynamic>.from(e as Map)))
        .toList();
  }

  /// Dirección legible (`GET /geocode/reverse`), o null si no hay.
  Future<String?> reverseGeocode(double lat, double lng) async {
    final json = await _api.get(ApiEndpoints.geocodeReverse, query: {'lat': lat, 'lng': lng});
    final data = json['data'];
    return data is Map ? data['address']?.toString() : null;
  }

  static String _pathFor(ClockAction action) => switch (action) {
        ClockAction.clockIn => ApiEndpoints.clockIn,
        ClockAction.breakStart => ApiEndpoints.breakStart,
        ClockAction.breakEnd => ApiEndpoints.breakEnd,
        ClockAction.clockOut => ApiEndpoints.clockOut,
      };

  static String get deviceOs => defaultTargetPlatform == TargetPlatform.iOS ? 'ios' : 'android';

  static MultipartFile _photoFile(PunchPhoto photo) {
    final [type, subtype] = photo.mimeType.split('/');
    return MultipartFile.fromBytes(photo.bytes, filename: 'selfie.${photo.extension}', contentType: DioMediaType(type, subtype));
  }

  /// Envía la marcación capturada (selfie opcional en todas). Un reenvío con
  /// el mismo `client_uuid` responde 200 `duplicate: true`: también es éxito.
  Future<PunchOutcome> sendPunch(PunchCapture capture) async {
    final fields = punchFields(
      clientUuid: capture.clientUuid,
      deviceTime: capture.deviceTime,
      latitude: capture.latitude,
      longitude: capture.longitude,
      accuracy: capture.accuracy,
      isMocked: capture.isMocked,
      faceDetected: capture.faceDetected,
      deviceId: await _storage.installationId(),
      deviceOs: deviceOs,
      appVersion: appVersion,
      deviceSentAt: DateTime.now(),
    );
    final photo = capture.photo;
    final json = await _api.postMultipart(
      _pathFor(capture.action),
      FormData.fromMap({...fields, 'photo': ?(photo == null ? null : _photoFile(photo))}),
    );
    return PunchOutcome.fromJson(json);
  }

  /// Copia de la marcación para la cola offline: la selfie (ya comprimida)
  /// se guarda cifrada en los documentos privados de la app.
  Future<QueuedPunch> toQueued(PunchCapture capture, PhotoVault vault, {int? userId, String? server}) async {
    final photo = capture.photo;
    final path = photo == null ? null : await vault.save(capture.clientUuid, photo.bytes);
    return QueuedPunch.fromCapture(
      capture,
      userId: userId,
      deviceId: await _storage.installationId(),
      deviceOs: deviceOs,
      appVersion: appVersion,
      photoPath: path,
      server: server,
    );
  }

  Future<Map<String, String>> _queuedFields(QueuedPunch p) async => p.fields(
        fallbackDeviceId: await _storage.installationId(),
        fallbackOs: deviceOs,
        fallbackVersion: appVersion,
      );

  @override
  Future<Map<String, BatchItemResult>> sendBatch(List<OutgoingPunch> punches) async {
    final map = <String, dynamic>{};
    for (var i = 0; i < punches.length; i++) {
      final out = punches[i];
      (await _queuedFields(out.punch)).forEach((k, v) => map['punches[$i][$k]'] = v);
      // Tipo de marcación: el contrato no fija el nombre del campo, se envía
      // como `type` y `action` (clock_in | break_start | break_end | clock_out).
      map['punches[$i][type]'] = out.punch.action.apiName;
      map['punches[$i][action]'] = out.punch.action.apiName;
      final photo = out.photo;
      if (photo != null) map['punches[$i][photo]'] = _photoFile(photo);
    }
    final json = await _api.postMultipart(ApiEndpoints.syncBatch, FormData.fromMap(map, ListFormat.multiCompatible));
    return parseBatchResults(json);
  }

  @override
  Future<void> sendOne(OutgoingPunch punch) async {
    final photo = punch.photo;
    await _api.postMultipart(
      _pathFor(punch.punch.action),
      FormData.fromMap({...await _queuedFields(punch.punch), 'photo': ?(photo == null ? null : _photoFile(photo))}),
    );
  }

  Future<void> submitJustification(int attendanceId, String notes, {PlatformAttachment? file}) async {
    final form = FormData.fromMap({
      'notes': notes,
      if (file != null) 'file': file.toMultipart(),
    });
    await _api.postMultipart(ApiEndpoints.justification(attendanceId), form);
  }
}

/// Respuesta de una marcación individual.
class PunchOutcome {
  const PunchOutcome({required this.message, this.duplicate = false});

  final String message;

  /// El servidor ya tenía esta marcación (mismo `client_uuid`).
  final bool duplicate;

  factory PunchOutcome.fromJson(Map<String, dynamic> json) {
    final data = json['data'];
    final duplicate = json['duplicate'] == true || (data is Map && data['duplicate'] == true);
    return PunchOutcome(
      message: json['message']?.toString() ?? (duplicate ? 'Tu marcación ya estaba registrada.' : 'Marcación registrada.'),
      duplicate: duplicate,
    );
  }
}
