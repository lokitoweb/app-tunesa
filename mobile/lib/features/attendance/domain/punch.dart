import 'dart:typed_data';

import 'package:uuid/uuid.dart';

import '../../../core/utils/formatters.dart';
import 'models.dart';

const _uuid = Uuid();

/// Nuevo `client_uuid` (v4). Se genera una sola vez por captura y se reusa
/// en cada reintento: el backend responde `duplicate: true` si ya la tiene.
String newClientUuid() => _uuid.v4();

/// ISO 8601 en UTC con milisegundos: 2026-10-09T13:01:02.123Z.
String isoUtc(DateTime d) => d.toUtc().toIso8601String();

/// Foto lista para enviar (WebP comprimido, o JPEG si el teléfono no pudo).
class PunchPhoto {
  const PunchPhoto(this.bytes, this.mimeType);

  final Uint8List bytes;
  final String mimeType;

  String get extension => mimeType == 'image/webp' ? 'webp' : (mimeType == 'image/png' ? 'png' : 'jpg');
}

/// Datos tomados en el momento de la marcación (al disparar la selfie o, sin
/// selfie, al confirmar): GPS fresco con precisión, hora UTC del teléfono y
/// resultado de rostro / ubicación simulada.
class PunchCapture {
  PunchCapture({
    String? clientUuid,
    required this.action,
    required this.latitude,
    required this.longitude,
    required this.accuracy,
    required this.isMocked,
    required DateTime deviceTime,
    this.faceDetected,
    this.photo,
  })  : clientUuid = clientUuid ?? newClientUuid(),
        deviceTime = deviceTime.toUtc();

  final String clientUuid;
  final ClockAction action;
  final double latitude;
  final double longitude;
  final double accuracy;
  final bool isMocked;
  final DateTime deviceTime;
  final bool? faceDetected;
  final PunchPhoto? photo;

  /// Misma captura con la foto ya comprimida (conserva el `client_uuid`).
  PunchCapture withPhoto(PunchPhoto? photo) => PunchCapture(
        clientUuid: clientUuid,
        action: action,
        latitude: latitude,
        longitude: longitude,
        accuracy: accuracy,
        isMocked: isMocked,
        deviceTime: deviceTime,
        faceDetected: faceDetected,
        photo: photo,
      );
}

/// Campos de texto de una marcación (`clock-in`, `break-*`, `clock-out` y
/// cada `punches[i]` del lote). La foto va aparte como archivo.
Map<String, String> punchFields({
  required String clientUuid,
  required DateTime deviceTime,
  required double latitude,
  required double longitude,
  required double accuracy,
  required bool isMocked,
  bool? faceDetected,
  required String deviceId,
  required String deviceOs,
  required String appVersion,
  String? offlineRecordedAt,
}) =>
    {
      'client_uuid': clientUuid,
      'device_time': isoUtc(deviceTime),
      'latitude': '$latitude',
      'longitude': '$longitude',
      'accuracy_meters': '$accuracy',
      'is_mocked': isMocked ? '1' : '0',
      if (faceDetected != null) 'face_detected': faceDetected ? '1' : '0',
      'device_id': deviceId,
      'device_os': deviceOs,
      'app_version': appVersion,
      'offline_recorded_at': ?offlineRecordedAt,
    };

/// Marcación guardada en la cola cifrada.
class QueuedPunch {
  const QueuedPunch({
    required this.clientUuid,
    required this.action,
    required this.latitude,
    required this.longitude,
    required this.accuracy,
    required this.deviceTime,
    this.userId,
    this.isMocked = false,
    this.faceDetected,
    this.deviceId,
    this.deviceOs,
    this.appVersion,
    this.photoPath,
    this.photoMime,
    this.attempts = 0,
    this.nextAttemptAt,
    this.lastError,
  });

  final String clientUuid;
  final ClockAction action;
  final double latitude;
  final double longitude;
  final double accuracy;

  /// Hora real (UTC) de la captura; viaja como `device_time` y `offline_recorded_at`.
  final DateTime deviceTime;
  final int? userId;
  final bool isMocked;
  final bool? faceDetected;
  final String? deviceId;
  final String? deviceOs;
  final String? appVersion;

  /// Selfie cifrada en los documentos privados de la app.
  final String? photoPath;
  final String? photoMime;
  final int attempts;
  final DateTime? nextAttemptAt;
  final String? lastError;

  String get timeLabel => '${Fmt.date(deviceTime)} ${Fmt.time(deviceTime)}';

  static QueuedPunch fromCapture(
    PunchCapture c, {
    int? userId,
    required String deviceId,
    required String deviceOs,
    required String appVersion,
    String? photoPath,
  }) =>
      QueuedPunch(
        clientUuid: c.clientUuid,
        action: c.action,
        latitude: c.latitude,
        longitude: c.longitude,
        accuracy: c.accuracy,
        deviceTime: c.deviceTime,
        userId: userId,
        isMocked: c.isMocked,
        faceDetected: c.faceDetected,
        deviceId: deviceId,
        deviceOs: deviceOs,
        appVersion: appVersion,
        photoPath: photoPath,
        photoMime: photoPath == null ? null : c.photo?.mimeType,
      );

  Map<String, Object?> toRow() => {
        'client_uuid': clientUuid,
        'action': action.apiName,
        'latitude': latitude,
        'longitude': longitude,
        'accuracy': accuracy,
        'device_time': isoUtc(deviceTime),
        'user_id': userId,
        'is_mocked': isMocked ? 1 : 0,
        'face_detected': faceDetected == null ? null : (faceDetected! ? 1 : 0),
        'device_id': deviceId,
        'device_os': deviceOs,
        'app_version': appVersion,
        'photo_path': photoPath,
        'photo_mime': photoMime,
        'attempts': attempts,
        'next_attempt_at': nextAttemptAt == null ? null : isoUtc(nextAttemptAt!),
        'last_error': lastError,
      };

  static QueuedPunch? fromRow(Map<String, Object?> row) {
    final action = ClockAction.fromApi(row['action'] as String?);
    final time = DateTime.tryParse(row['device_time']?.toString() ?? '');
    final uuid = row['client_uuid']?.toString();
    if (action == null || time == null || uuid == null) return null;
    final face = row['face_detected'] as int?;
    return QueuedPunch(
      clientUuid: uuid,
      action: action,
      latitude: (row['latitude'] as num).toDouble(),
      longitude: (row['longitude'] as num).toDouble(),
      accuracy: (row['accuracy'] as num?)?.toDouble() ?? 0,
      deviceTime: time.toUtc(),
      userId: (row['user_id'] as num?)?.toInt(),
      isMocked: row['is_mocked'] == 1,
      faceDetected: face == null ? null : face == 1,
      deviceId: row['device_id'] as String?,
      deviceOs: row['device_os'] as String?,
      appVersion: row['app_version'] as String?,
      photoPath: row['photo_path'] as String?,
      photoMime: row['photo_mime'] as String?,
      attempts: (row['attempts'] as num?)?.toInt() ?? 0,
      nextAttemptAt: DateTime.tryParse(row['next_attempt_at']?.toString() ?? '')?.toUtc(),
      lastError: row['last_error'] as String?,
    );
  }

  /// Campos para el envío desde la cola (con `offline_recorded_at`).
  Map<String, String> fields({required String fallbackDeviceId, required String fallbackOs, required String fallbackVersion}) =>
      punchFields(
        clientUuid: clientUuid,
        deviceTime: deviceTime,
        latitude: latitude,
        longitude: longitude,
        accuracy: accuracy,
        isMocked: isMocked,
        faceDetected: faceDetected,
        deviceId: deviceId ?? fallbackDeviceId,
        deviceOs: deviceOs ?? fallbackOs,
        appVersion: appVersion ?? fallbackVersion,
        offlineRecordedAt: isoUtc(deviceTime),
      );
}

/// Marcación de la cola que no se aceptó (o venció): se muestra en
/// "Marcaciones no aceptadas" hasta que el colaborador la descarte.
class RejectedPunch {
  const RejectedPunch({
    required this.clientUuid,
    required this.action,
    required this.deviceTime,
    required this.message,
    required this.rejectedAt,
    this.code,
    this.userId,
  });

  final String clientUuid;
  final ClockAction action;
  final DateTime deviceTime;
  final String message;
  final String? code;
  final DateTime rejectedAt;
  final int? userId;

  String get timeLabel => '${Fmt.date(deviceTime)} ${Fmt.time(deviceTime)}';

  Map<String, Object?> toRow() => {
        'client_uuid': clientUuid,
        'action': action.apiName,
        'device_time': isoUtc(deviceTime),
        'message': message,
        'code': code,
        'rejected_at': isoUtc(rejectedAt),
        'user_id': userId,
      };

  static RejectedPunch? fromRow(Map<String, Object?> row) {
    final action = ClockAction.fromApi(row['action'] as String?);
    final time = DateTime.tryParse(row['device_time']?.toString() ?? '');
    if (action == null || time == null) return null;
    return RejectedPunch(
      clientUuid: row['client_uuid'].toString(),
      action: action,
      deviceTime: time.toUtc(),
      message: row['message']?.toString() ?? '',
      code: row['code'] as String?,
      rejectedAt: DateTime.tryParse(row['rejected_at']?.toString() ?? '')?.toUtc() ?? time.toUtc(),
      userId: (row['user_id'] as num?)?.toInt(),
    );
  }
}

enum BatchItemStatus { created, duplicate, rejected }

/// Resultado de una marcación en `POST /v1/attendances/sync-batch`.
class BatchItemResult {
  const BatchItemResult({
    required this.clientUuid,
    required this.status,
    this.code,
    this.message,
    this.attendanceId,
    this.punchId,
  });

  final String clientUuid;
  final BatchItemStatus status;
  final String? code;
  final String? message;
  final int? attendanceId;
  final int? punchId;

  bool get accepted => status != BatchItemStatus.rejected;
}

/// Lee `data.results` del lote, indexado por `client_uuid`. Entradas sin
/// uuid o con estado desconocido se ignoran (la marcación se reintenta).
Map<String, BatchItemResult> parseBatchResults(Map<String, dynamic> json) {
  final data = json['data'];
  final results = data is Map ? data['results'] : null;
  if (results is! List) return const {};
  final map = <String, BatchItemResult>{};
  for (final raw in results) {
    if (raw is! Map) continue;
    final uuid = raw['client_uuid']?.toString();
    final status = switch (raw['status']?.toString()) {
      'created' => BatchItemStatus.created,
      'duplicate' => BatchItemStatus.duplicate,
      'rejected' => BatchItemStatus.rejected,
      _ => null,
    };
    if (uuid == null || uuid.isEmpty || status == null) continue;
    map[uuid] = BatchItemResult(
      clientUuid: uuid,
      status: status,
      code: raw['code']?.toString(),
      message: raw['message']?.toString(),
      attendanceId: (raw['attendance_id'] as num?)?.toInt(),
      punchId: (raw['punch_id'] as num?)?.toInt(),
    );
  }
  return map;
}
