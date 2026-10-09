import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/utils/formatters.dart';
import '../domain/models.dart';

/// Marcación tomada sin conexión (solo si la empresa activó
/// `mobile.offline_mode_enabled`). Se envía después con `offline_recorded_at`.
class PendingClock {
  const PendingClock({
    required this.id,
    required this.action,
    required this.latitude,
    required this.longitude,
    required this.accuracy,
    required this.recordedAt,
    this.userId,
    this.isMocked = false,
    this.deviceId,
    this.deviceOs,
    this.appVersion,
    this.photoPath,
    this.faceDetected,
  });

  final String id;
  final ClockAction action;
  final double latitude;
  final double longitude;
  final double accuracy;

  /// ISO 8601 con desfase horario (hora real de la marcación).
  final String recordedAt;

  /// Colaborador que marcó: solo se envía con su propia sesión.
  final int? userId;
  final bool isMocked;
  final String? deviceId;
  final String? deviceOs;
  final String? appVersion;

  /// Copia de la selfie en el directorio de documentos de la app.
  final String? photoPath;
  final bool? faceDetected;

  DateTime get recordedAtTime => DateTime.parse(recordedAt);

  String get timeLabel => '${Fmt.date(recordedAtTime)} ${Fmt.time(recordedAtTime)}';

  static PendingClock? fromJson(Map<String, dynamic> json) {
    final action = ClockAction.fromApi(json['action'] as String?);
    final recordedAt = json['recorded_at'] as String?;
    if (action == null || recordedAt == null || DateTime.tryParse(recordedAt) == null) return null;
    return PendingClock(
      id: json['id']?.toString() ?? recordedAt,
      action: action,
      latitude: (json['latitude'] as num).toDouble(),
      longitude: (json['longitude'] as num).toDouble(),
      accuracy: (json['accuracy'] as num?)?.toDouble() ?? 0,
      recordedAt: recordedAt,
      userId: (json['user_id'] as num?)?.toInt(),
      isMocked: json['is_mocked'] == true,
      deviceId: json['device_id'] as String?,
      deviceOs: json['device_os'] as String?,
      appVersion: json['app_version'] as String?,
      photoPath: json['photo_path'] as String?,
      faceDetected: json['face_detected'] as bool?,
    );
  }

  Map<String, dynamic> toJson() => {
        'id': id,
        'action': action.apiName,
        'latitude': latitude,
        'longitude': longitude,
        'accuracy': accuracy,
        'recorded_at': recordedAt,
        'user_id': userId,
        'is_mocked': isMocked,
        'device_id': deviceId,
        'device_os': deviceOs,
        'app_version': appVersion,
        'photo_path': photoPath,
        'face_detected': faceDetected,
      };
}

/// Almacenamiento clave/valor cifrado (Keychain / Keystore). Interfaz para
/// poder probar la cola con un almacenamiento falso.
abstract class SecureKeyValueStore {
  Future<String?> read(String key);
  Future<void> write(String key, String value);
  Future<void> delete(String key);
}

class FlutterSecureKeyValueStore implements SecureKeyValueStore {
  const FlutterSecureKeyValueStore([this._secure = const FlutterSecureStorage()]);

  final FlutterSecureStorage _secure;

  @override
  Future<String?> read(String key) => _secure.read(key: key);

  @override
  Future<void> write(String key, String value) => _secure.write(key: key, value: value);

  @override
  Future<void> delete(String key) => _secure.delete(key: key);
}

/// Resultado de un intento de sincronización.
class OfflineSyncResult {
  const OfflineSyncResult({
    this.sent = const [],
    this.expired = const [],
    this.rejected = const [],
    this.remaining = 0,
    this.networkFailed = false,
  });

  final List<PendingClock> sent;

  /// Descartadas por superar `offline_max_hours`.
  final List<PendingClock> expired;

  /// Rechazadas por el servidor (error de negocio 4xx): (marcación, mensaje).
  final List<(PendingClock, String)> rejected;
  final int remaining;

  /// Sin conexión o servidor caído: lo pendiente se conserva.
  final bool networkFailed;

  bool get hasNews => sent.isNotEmpty || expired.isNotEmpty || rejected.isNotEmpty;
}

/// Cola cifrada de marcaciones sin conexión.
///
/// - Se envían en orden cronológico; ante un error de red se detiene y
///   conserva el resto (no se pierde ni se reordena nada).
/// - Las que superan [maxAge] se descartan con aviso (el servidor las
///   rechazaría con OFFLINE_TOO_OLD).
/// - Un error de negocio 4xx (OFFLINE_NOT_ALLOWED, geocerca, duplicada…) la
///   quita de la cola y se muestra el mensaje.
class OfflineClockQueue {
  OfflineClockQueue(this._store, {DateTime Function()? now, Future<void> Function(String path)? deleteFile})
      : _now = now ?? DateTime.now,
        _deleteFile = deleteFile ?? _deleteIfExists;

  static const storageKey = 'offline_clock_queue';

  final SecureKeyValueStore _store;
  final DateTime Function() _now;
  final Future<void> Function(String path) _deleteFile;

  Future<OfflineSyncResult>? _syncing;

  static Future<void> _deleteIfExists(String path) async {
    try {
      final file = File(path);
      if (await file.exists()) await file.delete();
    } catch (_) {
      // archivo ya borrado o inaccesible
    }
  }

  Future<List<PendingClock>> items() async {
    final raw = await _store.read(storageKey);
    if (raw == null || raw.isEmpty) return [];
    try {
      final list = jsonDecode(raw) as List;
      final items = [
        for (final e in list)
          if (e is Map) ?PendingClock.fromJson(Map<String, dynamic>.from(e)),
      ];
      items.sort((a, b) => a.recordedAtTime.compareTo(b.recordedAtTime));
      return items;
    } catch (_) {
      return [];
    }
  }

  Future<int> count() async => (await items()).length;

  Future<void> _save(List<PendingClock> items) => items.isEmpty
      ? _store.delete(storageKey)
      : _store.write(storageKey, jsonEncode([for (final i in items) i.toJson()]));

  Future<void> add(PendingClock item) async {
    final list = await items();
    list.add(item);
    await _save(list);
  }

  Future<void> _remove(PendingClock item) async {
    final list = await items();
    list.removeWhere((i) => i.id == item.id);
    await _save(list);
    final photo = item.photoPath;
    if (photo != null) await _deleteFile(photo);
  }

  /// Errores que no son culpa de la marcación: se reintenta más tarde.
  static bool _isRetryable(ApiException e) {
    final status = e.statusCode;
    if (status == null || e.isNetwork) return true;
    return status >= 500 || const {401, 408, 425, 426, 429}.contains(status);
  }

  /// Envía lo pendiente con [send]. Llamadas simultáneas comparten el mismo envío.
  /// Con [userId] solo se envían las marcaciones de ese colaborador (las de
  /// otra cuenta en el mismo teléfono esperan a su sesión o vencen).
  Future<OfflineSyncResult> sync({
    required Future<void> Function(PendingClock item) send,
    required Duration maxAge,
    int? userId,
  }) =>
      _syncing ??= _sync(send, maxAge, userId).whenComplete(() => _syncing = null);

  Future<OfflineSyncResult> _sync(Future<void> Function(PendingClock) send, Duration maxAge, int? userId) async {
    final pending = await items();
    final sent = <PendingClock>[];
    final expired = <PendingClock>[];
    final rejected = <(PendingClock, String)>[];
    var networkFailed = false;

    for (final item in pending) {
      if (_now().difference(item.recordedAtTime) > maxAge) {
        await _remove(item);
        expired.add(item);
        continue;
      }
      if (userId != null && item.userId != null && item.userId != userId) continue;
      try {
        await send(item);
        await _remove(item);
        sent.add(item);
      } on ApiException catch (e) {
        if (_isRetryable(e)) {
          networkFailed = true;
          break;
        }
        await _remove(item);
        rejected.add((item, e.message));
      } catch (_) {
        // Error local inesperado: se conserva y se reintenta (o vence por antigüedad).
        break;
      }
    }

    return OfflineSyncResult(
      sent: sent,
      expired: expired,
      rejected: rejected,
      remaining: await count(),
      networkFailed: networkFailed,
    );
  }
}
