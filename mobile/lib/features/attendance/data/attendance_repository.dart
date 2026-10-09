import 'package:camera/camera.dart';
import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:geolocator/geolocator.dart';
import 'package:package_info_plus/package_info_plus.dart';

import '../../../core/network/api_client.dart';
import '../../../core/network/api_endpoints.dart';
import '../../../core/storage/session_storage.dart';
import '../../../core/utils/attachment_picker.dart';
import '../../../core/utils/formatters.dart';
import '../domain/models.dart';

class AttendanceRepository {
  AttendanceRepository(this._api, this._storage);

  final ApiClient _api;
  final SessionStorage _storage;

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

  /// Envía la marcación. Devuelve el mensaje del servidor.
  Future<String> clock(ClockAction action, Position position, {XFile? selfie}) async {
    final path = switch (action) {
      ClockAction.clockIn => ApiEndpoints.clockIn,
      ClockAction.breakStart => ApiEndpoints.breakStart,
      ClockAction.breakEnd => ApiEndpoints.breakEnd,
      ClockAction.clockOut => ApiEndpoints.clockOut,
    };

    final fields = <String, dynamic>{
      'latitude': position.latitude,
      'longitude': position.longitude,
      'accuracy_meters': position.accuracy,
      'is_mocked': position.isMocked ? 1 : 0,
    };

    final Map<String, dynamic> json;
    if (action.requiresSelfie) {
      if (selfie == null) throw ArgumentError('La selfie es obligatoria para ${action.label}.');
      final info = await PackageInfo.fromPlatform();
      final form = FormData.fromMap({
        ...fields,
        'device_id': await _storage.installationId(),
        'device_os': defaultTargetPlatform == TargetPlatform.iOS ? 'ios' : 'android',
        'app_version': info.version,
        'photo': MultipartFile.fromBytes(
          await selfie.readAsBytes(),
          filename: 'selfie.jpg',
          contentType: DioMediaType('image', 'jpeg'),
        ),
      });
      json = await _api.postMultipart(path, form);
    } else {
      json = await _api.post(path, data: fields);
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
