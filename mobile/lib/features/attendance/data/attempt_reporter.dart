import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';

import '../../../core/location/location_service.dart';
import '../../../core/network/api_client.dart';
import '../../../core/network/api_endpoints.dart';
import '../../../core/network/api_exception.dart';
import '../../../core/storage/session_storage.dart';
import '../domain/models.dart';
import '../domain/punch.dart';
import 'attendance_repository.dart';

/// Marcación que la app bloqueó antes de enviarla (`POST /attendance/attempts`).
class AppAttempt {
  AppAttempt({
    required this.action,
    required this.code,
    required this.occurredAt,
    this.latitude,
    this.longitude,
    this.accuracy,
    this.distance,
    this.allowedRadius,
    this.branchId,
    this.isMocked = false,
    String? clientUuid,
  }) : clientUuid = clientUuid ?? newClientUuid();

  static const mockCode = 'MOCK_LOCATION_DETECTED';
  static const accuracyCode = 'GPS_ACCURACY_TOO_LOW';
  static const geofenceCode = 'GEOFENCE_VIOLATION';

  final String clientUuid;
  final ClockAction action;
  final String code;
  final DateTime occurredAt;
  final double? latitude;
  final double? longitude;
  final double? accuracy;
  final double? distance;
  final int? allowedRadius;
  final int? branchId;
  final bool isMocked;

  /// El bloqueo que corresponde a un error de GPS (solo simulado o impreciso;
  /// sin permiso o sin señal no hay nada que reportar).
  static AppAttempt? fromLocationError(ClockAction action, LocationException error, {DateTime? at}) {
    final code = switch (error.failure) {
      LocationFailure.mocked => mockCode,
      LocationFailure.lowAccuracy => accuracyCode,
      _ => null,
    };
    if (code == null) return null;
    final p = error.position;
    return AppAttempt(
      action: action,
      code: code,
      occurredAt: at ?? DateTime.now(),
      latitude: p?.latitude,
      longitude: p?.longitude,
      accuracy: p?.accuracy,
      isMocked: error.isMocked,
    );
  }

  /// Fuera del radio de la sede más cercana (verificación previa de la app).
  static AppAttempt outside(
    ClockAction action,
    LocationFix fix, {
    required BranchGeofence branch,
    required double distance,
    DateTime? at,
  }) =>
      AppAttempt(
        action: action,
        code: geofenceCode,
        occurredAt: at ?? DateTime.now(),
        latitude: fix.latitude,
        longitude: fix.longitude,
        accuracy: fix.accuracy,
        distance: distance,
        allowedRadius: branch.radius.round(),
        branchId: branch.id,
        isMocked: fix.isMocked,
      );

  Map<String, dynamic> toJson() => {
        'client_uuid': clientUuid,
        'type': action.apiName,
        'code': code,
        'occurred_at': occurredAt.toUtc().toIso8601String(),
        'latitude': ?latitude,
        'longitude': ?longitude,
        'accuracy_meters': ?accuracy,
        'distance_meters': ?distance,
        'allowed_radius': ?allowedRadius,
        'branch_id': ?branchId,
        'is_mocked': isMocked,
      };

  static AppAttempt? fromJson(Map<String, dynamic> json) {
    final action = ClockAction.fromApi(json['type'] as String?);
    final at = DateTime.tryParse(json['occurred_at']?.toString() ?? '');
    if (action == null || at == null) return null;
    return AppAttempt(
      clientUuid: json['client_uuid'] as String?,
      action: action,
      code: json['code'] as String,
      occurredAt: at,
      latitude: (json['latitude'] as num?)?.toDouble(),
      longitude: (json['longitude'] as num?)?.toDouble(),
      accuracy: (json['accuracy_meters'] as num?)?.toDouble(),
      distance: (json['distance_meters'] as num?)?.toDouble(),
      allowedRadius: (json['allowed_radius'] as num?)?.toInt(),
      branchId: (json['branch_id'] as num?)?.toInt(),
      isMocked: json['is_mocked'] == true,
    );
  }
}

/// Envía los bloqueos de la app para que RRHH los vea en «Intentos rechazados».
/// Nunca lanza ni demora la marcación: sin conexión (o con 5xx / 429) el
/// intento queda en el teléfono y se reenvía con el siguiente reporte; un
/// rechazo de validación lo descarta. El servidor es idempotente por `client_uuid`.
class AttemptReporter {
  AttemptReporter(this._api, this._prefs, this._storage, {this.appVersion = '0.0.0'});

  static const queueKey = 'attendance_attempt_queue_v1';

  /// Tope de la cola: los más antiguos se descartan primero.
  static const maxQueued = 30;

  final ApiClient _api;
  final SharedPreferences _prefs;
  final SessionStorage _storage;
  final String appVersion;

  Future<void> report(AppAttempt attempt) async {
    try {
      final pending = [..._queued(), attempt];
      final failed = <AppAttempt>[];
      for (final a in pending) {
        if (!await _send(a)) failed.add(a);
      }
      await _save(failed);
    } catch (_) {
      // Reportar nunca debe romper la pantalla de marcación
    }
  }

  /// true si el servidor lo recibió (o lo descartó por inválido).
  Future<bool> _send(AppAttempt attempt) async {
    try {
      await _api.post(ApiEndpoints.attendanceAttempts, data: {
        ...attempt.toJson(),
        'device_id': await _storage.installationId(),
        'device_os': AttendanceRepository.deviceOs,
        'app_version': appVersion,
      });
      return true;
    } on ApiException catch (e) {
      return !isRetryable(e);
    }
  }

  static bool isRetryable(ApiException e) =>
      e.isNetwork || e.statusCode == null || e.statusCode == 429 || e.statusCode! >= 500;

  List<AppAttempt> _queued() {
    final raw = _prefs.getString(queueKey);
    if (raw == null) return [];
    try {
      return (jsonDecode(raw) as List)
          .map((e) => AppAttempt.fromJson(Map<String, dynamic>.from(e as Map)))
          .whereType<AppAttempt>()
          .toList();
    } catch (_) {
      return [];
    }
  }

  Future<void> _save(List<AppAttempt> attempts) async {
    if (attempts.isEmpty) {
      await _prefs.remove(queueKey);
      return;
    }
    final kept = attempts.length > maxQueued ? attempts.sublist(attempts.length - maxQueued) : attempts;
    await _prefs.setString(queueKey, jsonEncode([for (final a in kept) a.toJson()]));
  }
}
