import 'dart:io';
import 'dart:math';

import 'package:camera/camera.dart';
import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:geolocator/geolocator.dart';
import 'package:path_provider/path_provider.dart';

import '../../../core/network/api_client.dart';
import '../../../core/network/api_endpoints.dart';
import '../../../core/storage/session_storage.dart';
import '../../../core/utils/attachment_picker.dart';
import '../../../core/utils/formatters.dart';
import '../domain/models.dart';
import 'offline_clock_queue.dart';

class AttendanceRepository {
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

  static String _pathFor(ClockAction action) => switch (action) {
        ClockAction.clockIn => ApiEndpoints.clockIn,
        ClockAction.breakStart => ApiEndpoints.breakStart,
        ClockAction.breakEnd => ApiEndpoints.breakEnd,
        ClockAction.clockOut => ApiEndpoints.clockOut,
      };

  static String get _deviceOs => defaultTargetPlatform == TargetPlatform.iOS ? 'ios' : 'android';

  /// Envía la marcación. Devuelve el mensaje del servidor.
  ///
  /// [selfie] es opcional si la empresa desactivó `selfie_required`;
  /// [faceDetected] solo se envía si se pudo analizar la foto.
  Future<String> clock(ClockAction action, Position position, {XFile? selfie, bool? faceDetected}) async {
    return _post(
      action,
      latitude: position.latitude,
      longitude: position.longitude,
      accuracy: position.accuracy,
      isMocked: position.isMocked,
      deviceId: await _storage.installationId(),
      photo: selfie == null ? null : await selfie.readAsBytes(),
      faceDetected: faceDetected,
    );
  }

  /// Marcación tomada sin conexión: copia la selfie a los documentos de la
  /// app (la cámara la deja en caché, que el sistema puede borrar).
  Future<PendingClock> buildPending(
    ClockAction action,
    Position position, {
    int? userId,
    XFile? selfie,
    bool? faceDetected,
  }) async {
    final now = DateTime.now();
    final id = '${now.microsecondsSinceEpoch}-${Random.secure().nextInt(1 << 32)}';
    String? photoPath;
    if (selfie != null) {
      final dir = await getApplicationDocumentsDirectory();
      final file = File('${dir.path}/offline_clocks/$id.jpg');
      await file.parent.create(recursive: true);
      await file.writeAsBytes(await selfie.readAsBytes(), flush: true);
      photoPath = file.path;
    }
    return PendingClock(
      id: id,
      action: action,
      latitude: position.latitude,
      longitude: position.longitude,
      accuracy: position.accuracy,
      isMocked: position.isMocked,
      recordedAt: Fmt.isoWithOffset(now),
      userId: userId,
      deviceId: await _storage.installationId(),
      deviceOs: _deviceOs,
      appVersion: appVersion,
      photoPath: photoPath,
      faceDetected: faceDetected,
    );
  }

  /// Envía una marcación de la cola offline con su hora real (`offline_recorded_at`).
  Future<String> sendPending(PendingClock item) async {
    List<int>? photo;
    final path = item.photoPath;
    if (path != null) {
      final file = File(path);
      if (await file.exists()) photo = await file.readAsBytes();
    }
    return _post(
      item.action,
      latitude: item.latitude,
      longitude: item.longitude,
      accuracy: item.accuracy,
      isMocked: item.isMocked,
      deviceId: item.deviceId ?? await _storage.installationId(),
      deviceOs: item.deviceOs,
      version: item.appVersion,
      photo: photo,
      faceDetected: item.faceDetected,
      offlineRecordedAt: item.recordedAt,
    );
  }

  Future<String> _post(
    ClockAction action, {
    required double latitude,
    required double longitude,
    required double accuracy,
    required bool isMocked,
    required String deviceId,
    String? deviceOs,
    String? version,
    List<int>? photo,
    bool? faceDetected,
    String? offlineRecordedAt,
  }) async {
    final fields = <String, dynamic>{
      'latitude': latitude,
      'longitude': longitude,
      'accuracy_meters': accuracy,
      'is_mocked': isMocked ? 1 : 0,
      'offline_recorded_at': ?offlineRecordedAt,
    };

    final Map<String, dynamic> json;
    if (action.requiresSelfie) {
      // Entrada y salida: multipart con datos del equipo; la foto es opcional
      // si la empresa no exige selfie (el backend valida).
      final form = FormData.fromMap({
        ...fields,
        'device_id': deviceId,
        'device_os': deviceOs ?? _deviceOs,
        'app_version': version ?? appVersion,
        if (faceDetected != null) 'face_detected': faceDetected ? 1 : 0,
        if (photo != null)
          'photo': MultipartFile.fromBytes(photo, filename: 'selfie.jpg', contentType: DioMediaType('image', 'jpeg')),
      });
      json = await _api.postMultipart(_pathFor(action), form);
    } else {
      json = await _api.post(_pathFor(action), data: fields);
    }
    return json['message']?.toString() ?? 'Marcación registrada.';
  }

  Future<void> submitJustification(int attendanceId, String notes, {PlatformAttachment? file}) async {
    final form = FormData.fromMap({
      'notes': notes,
      if (file != null) 'file': file.toMultipart(),
    });
    await _api.postMultipart(ApiEndpoints.justification(attendanceId), form);
  }
}
