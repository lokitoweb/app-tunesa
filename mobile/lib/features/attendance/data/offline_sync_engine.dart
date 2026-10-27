import 'dart:async';
import 'dart:math';

import '../../../core/network/api_exception.dart';
import '../domain/punch.dart';
import 'offline_punch_store.dart';
import 'photo_vault.dart';

/// Marcación de la cola lista para enviar (con la selfie ya descifrada).
class OutgoingPunch {
  const OutgoingPunch(this.punch, this.photo);

  final QueuedPunch punch;
  final PunchPhoto? photo;
}

/// Envío al backend (lo implementa AttendanceRepository).
abstract class PunchTransport {
  /// `POST /v1/attendances/sync-batch`: resultado por `client_uuid`.
  Future<Map<String, BatchItemResult>> sendBatch(List<OutgoingPunch> punches);

  /// Envío individual (backend sin lote). `duplicate: true` cuenta como éxito.
  Future<void> sendOne(OutgoingPunch punch);
}

/// Resultado de un intento de sincronización.
class OfflineSyncResult {
  const OfflineSyncResult({
    this.sent = const [],
    this.duplicates = 0,
    this.expired = const [],
    this.rejected = const [],
    this.remaining = 0,
    this.networkFailed = false,
    this.syncedAt,
  });

  /// Aceptadas por el servidor (creadas o ya existentes).
  final List<QueuedPunch> sent;
  final int duplicates;

  /// Vencidas por superar `offline_max_hours`.
  final List<QueuedPunch> expired;

  /// Rechazadas por el servidor: (marcación, mensaje).
  final List<(QueuedPunch, String)> rejected;
  final int remaining;

  /// Sin conexión, servidor caído, sesión vencida o límite: se reintenta.
  final bool networkFailed;

  /// Hora en que el servidor respondió (null si no hubo respuesta).
  final DateTime? syncedAt;

  bool get hasNews => sent.isNotEmpty || expired.isNotEmpty || rejected.isNotEmpty;
}

/// Sincronización de la cola cifrada.
///
/// - Lotes de hasta [batchSize] por `sync-batch`; `created`/`duplicate` se
///   quitan (y se borra la foto); `rejected` pasa a "no aceptadas".
/// - Red caída, 5xx, 401, 408, 425, 426 o 429: se conserva todo y se programa
///   el próximo intento con espera exponencial (30 s … 30 min).
/// - Si el backend no tiene el lote (otro 4xx al lote) se envían una por una.
/// - Las que superan `offline_max_hours` se descartan con aviso.
class OfflineSyncEngine {
  OfflineSyncEngine(this.store, this.vault, {DateTime Function()? now, this.batchSize = 20})
      : _now = now ?? DateTime.now;

  final OfflinePunchStore store;
  final PhotoVault vault;
  final DateTime Function() _now;
  final int batchSize;

  Future<OfflineSyncResult>? _syncing;

  static const expiredCode = 'OFFLINE_EXPIRED';

  /// Espera antes del próximo intento automático tras [attempts] fallos.
  static Duration backoff(int attempts) {
    final n = max(1, attempts);
    final seconds = 30 * pow(2, min(n - 1, 10)).toInt();
    return Duration(seconds: min(seconds, 30 * 60));
  }

  /// Errores que no son culpa de la marcación: se reintenta más tarde.
  /// 403 es de la cuenta (contraseña por cambiar, incorporación pendiente, permiso
  /// quitado), no de la marcación: se conserva hasta que se arregle o venza `offline_max_hours`.
  static bool isRetryable(ApiException e) {
    final status = e.statusCode;
    if (status == null || e.isNetwork) return true;
    return status >= 500 || const {401, 403, 408, 425, 426, 429}.contains(status);
  }

  /// Envía lo pendiente. Llamadas simultáneas comparten el mismo envío.
  /// Con [userId] solo se envían las de ese colaborador (y las sin usuario);
  /// con [server], solo las marcadas en ese servidor (y las sin servidor).
  /// [force] ignora la espera entre reintentos (deslizar / "Enviar ahora").
  Future<OfflineSyncResult> sync({
    required PunchTransport transport,
    required Duration maxAge,
    int? userId,
    String? server,
    bool force = false,
  }) =>
      _syncing ??= _sync(transport, maxAge, userId, server, force).whenComplete(() => _syncing = null);

  Future<OfflineSyncResult> _sync(
      PunchTransport transport, Duration maxAge, int? userId, String? server, bool force) async {
    final now = _now().toUtc();
    final sent = <QueuedPunch>[];
    final expired = <QueuedPunch>[];
    final rejected = <(QueuedPunch, String)>[];
    var duplicates = 0;
    var networkFailed = false;
    DateTime? syncedAt;

    final due = <QueuedPunch>[];
    for (final item in await store.all()) {
      if (now.difference(item.deviceTime) > maxAge) {
        await _reject(
          item,
          code: expiredCode,
          message: 'Superó el límite de ${maxAge.inHours} h sin enviarse.',
        );
        expired.add(item);
        continue;
      }
      if (userId != null && item.userId != null && item.userId != userId) continue;
      if (server != null && item.server != null && item.server != server) continue;
      final next = item.nextAttemptAt;
      if (!force && next != null && next.isAfter(now)) continue;
      due.add(item);
    }

    var useSingles = false;
    for (var start = 0; start < due.length && !networkFailed; start += batchSize) {
      final chunk = due.sublist(start, min(start + batchSize, due.length));
      final outgoing = [for (final p in chunk) OutgoingPunch(p, await _photo(p))];

      if (!useSingles) {
        try {
          final results = await transport.sendBatch(outgoing);
          syncedAt = _now().toUtc();
          for (final item in chunk) {
            final r = results[item.clientUuid];
            if (r == null || r.status == BatchItemStatus.retry) {
              // Sin respuesta o fallo del servidor (no de la marcación): se reenvía después
              await _retryLater(item, now, r?.message ?? 'Sin respuesta para esta marcación');
            } else if (r.accepted) {
              await _accept(item);
              sent.add(item);
              if (r.status == BatchItemStatus.duplicate) duplicates++;
            } else {
              final message = r.message ?? 'El servidor no aceptó la marcación.';
              await _reject(item, code: r.code, message: message);
              rejected.add((item, message));
            }
          }
          continue;
        } on ApiException catch (e) {
          if (isRetryable(e)) {
            if (e.statusCode != null) syncedAt = _now().toUtc();
            for (final item in chunk) {
              await _retryLater(item, now, e.message);
            }
            networkFailed = true;
            break;
          }
          // Backend sin `sync-batch` o lote inválido: una por una.
          useSingles = true;
        }
      }

      for (final out in outgoing) {
        final item = out.punch;
        if (networkFailed) break;
        try {
          await transport.sendOne(out);
          syncedAt = _now().toUtc();
          await _accept(item);
          sent.add(item);
        } on ApiException catch (e) {
          if (isRetryable(e)) {
            await _retryLater(item, now, e.message);
            networkFailed = true;
          } else {
            syncedAt = _now().toUtc();
            await _reject(item, code: e.code, message: e.message);
            rejected.add((item, e.message));
          }
        }
      }
    }

    if (syncedAt != null) await store.setMeta(OfflinePunchStore.lastSyncKey, isoUtc(syncedAt));

    return OfflineSyncResult(
      sent: sent,
      duplicates: duplicates,
      expired: expired,
      rejected: rejected,
      remaining: await store.count(userId: userId, server: server),
      networkFailed: networkFailed,
      syncedAt: syncedAt,
    );
  }

  Future<PunchPhoto?> _photo(QueuedPunch p) async {
    final path = p.photoPath;
    if (path == null) return null;
    final bytes = await vault.read(path);
    return bytes == null ? null : PunchPhoto(bytes, p.photoMime ?? 'image/jpeg');
  }

  Future<void> _accept(QueuedPunch item) async {
    await store.delete(item.clientUuid);
    if (item.photoPath != null) await vault.delete(item.photoPath!);
  }

  Future<void> _reject(QueuedPunch item, {String? code, required String message}) async {
    await store.reject(RejectedPunch(
      clientUuid: item.clientUuid,
      action: item.action,
      deviceTime: item.deviceTime,
      message: message,
      code: code,
      rejectedAt: _now().toUtc(),
      userId: item.userId,
      server: item.server,
    ));
    if (item.photoPath != null) await vault.delete(item.photoPath!);
  }

  Future<void> _retryLater(QueuedPunch item, DateTime now, String error) =>
      store.recordFailure(item.clientUuid, nextAttemptAt: now.add(backoff(item.attempts + 1)), error: error);
}
