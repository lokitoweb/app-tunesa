import 'dart:convert';
import 'dart:io';
import '../../../core/utils/json_num.dart';

import '../../../core/storage/secure_key_store.dart';
import '../domain/models.dart';
import '../domain/punch.dart';
import 'offline_punch_store.dart';
import 'photo_vault.dart';

/// Migración de la cola anterior (JSON en flutter_secure_storage, selfie en
/// claro en los documentos de la app) a la base cifrada.
///
/// Corre una vez al abrir la cola: cada marcación recibe un `client_uuid`
/// nuevo, la foto se cifra y se borra la copia en claro, y al final se elimina
/// la clave vieja. Entradas ilegibles se descartan.
class LegacyOfflineQueueMigration {
  LegacyOfflineQueueMigration(this._secure, this._store, this._vault, {Future<void> Function(String path)? deleteFile})
      : _deleteFile = deleteFile ?? _deleteIfExists;

  /// Clave usada por versiones anteriores de la app.
  static const storageKey = 'offline_clock_queue';

  final SecureKeyValueStore _secure;
  final OfflinePunchStore _store;
  final PhotoVault _vault;
  final Future<void> Function(String path) _deleteFile;

  static Future<void> _deleteIfExists(String path) async {
    try {
      final file = File(path);
      if (await file.exists()) await file.delete();
    } catch (_) {}
  }

  /// Devuelve cuántas marcaciones se migraron.
  Future<int> run() async {
    final raw = await _secure.read(storageKey);
    if (raw == null || raw.isEmpty) return 0;
    var migrated = 0;
    List<Object?> list;
    try {
      final decoded = jsonDecode(raw);
      list = decoded is List ? decoded : const [];
    } catch (_) {
      list = const [];
    }

    for (final entry in list) {
      if (entry is! Map) continue;
      final legacy = parseLegacy(Map<String, dynamic>.from(entry));
      if (legacy == null) continue;
      final (punch, plainPhoto) = legacy;
      var row = punch;
      if (plainPhoto != null) {
        try {
          final file = File(plainPhoto);
          if (await file.exists()) {
            final path = await _vault.save(punch.clientUuid, await file.readAsBytes());
            row = _withPhoto(punch, path, 'image/jpeg');
          }
        } catch (_) {
          // sin foto: el servidor decidirá si la exige
        }
        await _deleteFile(plainPhoto);
      }
      await _store.insert(row);
      migrated++;
    }
    await _secure.delete(storageKey);
    return migrated;
  }

  static QueuedPunch _withPhoto(QueuedPunch p, String path, String mime) => QueuedPunch(
        clientUuid: p.clientUuid,
        action: p.action,
        latitude: p.latitude,
        longitude: p.longitude,
        accuracy: p.accuracy,
        deviceTime: p.deviceTime,
        userId: p.userId,
        isMocked: p.isMocked,
        faceDetected: p.faceDetected,
        deviceId: p.deviceId,
        deviceOs: p.deviceOs,
        appVersion: p.appVersion,
        photoPath: path,
        photoMime: mime,
      );

  /// Entrada del formato anterior → (marcación sin foto, ruta de la foto en claro).
  static (QueuedPunch, String?)? parseLegacy(Map<String, dynamic> json) {
    final action = ClockAction.fromApi(json['action'] as String?);
    final recordedAt = DateTime.tryParse(json['recorded_at']?.toString() ?? '');
    final lat = json['latitude'];
    final lng = json['longitude'];
    if (action == null || recordedAt == null || lat is! num || lng is! num) return null;
    return (
      QueuedPunch(
        clientUuid: newClientUuid(),
        action: action,
        latitude: lat.toDouble(),
        longitude: lng.toDouble(),
        accuracy: asDouble(json['accuracy']) ?? 0,
        deviceTime: recordedAt.toUtc(),
        userId: asInt(json['user_id']),
        isMocked: json['is_mocked'] == true,
        faceDetected: json['face_detected'] as bool?,
        deviceId: json['device_id'] as String?,
        deviceOs: json['device_os'] as String?,
        appVersion: json['app_version'] as String?,
      ),
      json['photo_path'] as String?,
    );
  }
}
