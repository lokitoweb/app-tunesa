import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/config/app_config_controller.dart';
import '../../../core/network/server_url.dart';
import '../../../core/providers.dart';
import '../../auth/presentation/auth_controller.dart';
import '../data/face_detection_service.dart';
import '../data/offline_queue.dart';
import '../data/offline_sync_engine.dart';
import '../data/photo_compressor.dart';
import '../domain/punch.dart';
import 'attendance_providers.dart';

final offlineQueueProvider = Provider<OfflineQueue>((ref) => OfflineQueue.device());

final faceDetectionServiceProvider = Provider<FaceDetectionService>((ref) => FaceDetectionService());

final photoCompressorProvider = Provider<PhotoCompressor>((ref) => const PhotoCompressor());

class OfflineSyncState {
  const OfflineSyncState({this.pending = 0, this.rejected = 0, this.lastSyncAt, this.last, this.seq = 0, this.syncing = false});

  /// Marcaciones guardadas sin conexión que faltan enviar.
  final int pending;

  /// Marcaciones no aceptadas que el colaborador aún no descartó.
  final int rejected;

  /// Última vez que el servidor respondió a un envío de la cola.
  final DateTime? lastSyncAt;

  /// Resultado del último envío (para avisar al colaborador).
  final OfflineSyncResult? last;
  final int seq;
  final bool syncing;

  OfflineSyncState copyWith({int? pending, int? rejected, DateTime? lastSyncAt, bool? syncing}) => OfflineSyncState(
        pending: pending ?? this.pending,
        rejected: rejected ?? this.rejected,
        lastSyncAt: lastSyncAt ?? this.lastSyncAt,
        last: last,
        seq: seq,
        syncing: syncing ?? this.syncing,
      );
}

/// Envía la cola offline al recuperar señal, al volver a la app, cada 60 s
/// mientras la app está abierta y haya pendientes, y al deslizar para
/// actualizar en Inicio.
class OfflineSyncController extends Notifier<OfflineSyncState> {
  static const period = Duration(seconds: 60);

  Timer? _timer;
  bool _foreground = true;

  @override
  OfflineSyncState build() {
    ref.listen(connectivityProvider, (previous, next) {
      if (previous?.value == false && next.value == true) sync();
    });
    final lifecycle = AppLifecycleListener(
      onResume: () {
        _foreground = true;
        sync();
      },
      onPause: () => _foreground = false,
    );
    _timer = Timer.periodic(period, (_) {
      if (_foreground && state.pending > 0) sync();
    });
    ref.onDispose(() {
      lifecycle.dispose();
      _timer?.cancel();
    });
    Future.microtask(sync);
    return const OfflineSyncState();
  }

  OfflineQueue get _queue => ref.read(offlineQueueProvider);

  int? get _userId => ref.read(authControllerProvider).user?.id;

  /// Servidor en uso: la cola solo se envía (y se cuenta) en el servidor donde se marcó.
  String get _server => ref.read(baseUrlProvider);

  Future<void> refreshCounts() async {
    try {
      await _queue.ready();
      final store = _queue.store;
      final pending = await store.count(userId: _userId, server: _server);
      final rejected = (await store.rejected(userId: _userId, server: _server)).length;
      final lastSync = await store.lastSyncAt();
      if (ref.mounted) state = state.copyWith(pending: pending, rejected: rejected, lastSyncAt: lastSync);
    } catch (_) {
      // base no disponible (p. ej. pruebas o Keystore bloqueado): se reintenta luego
    }
  }

  /// Guarda la marcación (con la foto cifrada) y la cuenta como pendiente.
  Future<void> enqueue(PunchCapture capture) async {
    await _queue.ready();
    final item = await ref.read(attendanceRepositoryProvider).toQueued(capture, _queue.vault, userId: _userId, server: _server);
    await _queue.store.insert(item);
    await refreshCounts();
  }

  /// [force]: ignora la espera entre reintentos (acción del colaborador).
  Future<OfflineSyncResult?> sync({bool force = false}) async {
    try {
      await _queue.ready();
      final auth = ref.read(authControllerProvider);
      if (auth.status != AuthStatus.authenticated || await _queue.store.count(userId: auth.user?.id, server: _server) == 0) {
        await refreshCounts();
        return null;
      }
      if (ref.mounted) state = state.copyWith(syncing: true);
      final hours = ref.read(appConfigProvider).config.mobile.offlineMaxHours;
      final result = await _queue.engine.sync(
        transport: ref.read(attendanceRepositoryProvider),
        maxAge: Duration(hours: hours),
        userId: auth.user?.id,
        server: _server,
        force: force,
      );
      if (!ref.mounted) return result;
      final rejected = (await _queue.store.rejected(userId: auth.user?.id, server: _server)).length;
      state = OfflineSyncState(
        pending: result.remaining,
        rejected: rejected,
        lastSyncAt: result.syncedAt ?? state.lastSyncAt,
        last: result,
        seq: state.seq + 1,
      );
      if (result.sent.isNotEmpty || result.rejected.isNotEmpty) ref.invalidate(todayStatusProvider);
      return result;
    } catch (_) {
      // Base cifrada no disponible: se reintenta en el próximo evento.
      if (ref.mounted && state.syncing) state = state.copyWith(syncing: false);
      return null;
    }
  }

  Future<List<RejectedPunch>> rejectedList() async {
    await _queue.ready();
    return _queue.store.rejected(userId: _userId, server: _server);
  }

  Future<void> dismissRejected(String clientUuid) async {
    await _queue.store.dismissRejected(clientUuid);
    await refreshCounts();
  }

  Future<void> clearRejected() async {
    await _queue.store.clearRejected(userId: _userId, server: _server);
    await refreshCounts();
  }
}

final offlineSyncProvider = NotifierProvider<OfflineSyncController, OfflineSyncState>(OfflineSyncController.new);

/// Mensajes para el colaborador tras sincronizar: (texto, es error).
List<(String, bool)> offlineSyncMessages(OfflineSyncResult result, {required int maxHours}) => [
      if (result.sent.isNotEmpty)
        (
          result.sent.length == 1
              ? 'Se envió 1 marcación guardada sin conexión.'
              : 'Se enviaron ${result.sent.length} marcaciones guardadas sin conexión.',
          false,
        ),
      for (final item in result.expired)
        (
          'Se descartó tu marcación sin conexión "${item.action.label}" del ${item.timeLabel}: '
              'superó el límite de $maxHours h sin enviarse.',
          true,
        ),
      for (final (item, message) in result.rejected)
        ('No se aceptó tu marcación sin conexión "${item.action.label}" del ${item.timeLabel}: $message', true),
    ];

/// Escucha los envíos de la cola y muestra los avisos. Va dentro del shell
/// autenticado (no dibuja nada).
class OfflineSyncListener extends ConsumerWidget {
  const OfflineSyncListener({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    ref.listen(offlineSyncProvider, (previous, next) {
      final result = next.last;
      if (result == null || next.seq == previous?.seq || !result.hasNews) return;
      final hours = ref.read(appConfigProvider).config.mobile.offlineMaxHours;
      final messages = offlineSyncMessages(result, maxHours: hours);
      final error = messages.any((m) => m.$2);
      final scheme = Theme.of(context).colorScheme;
      ScaffoldMessenger.of(context)
        ..hideCurrentSnackBar()
        ..showSnackBar(SnackBar(
          content: Text(messages.map((m) => m.$1).join('\n')),
          backgroundColor: error ? scheme.error : null,
          behavior: SnackBarBehavior.floating,
          action: error
              ? SnackBarAction(
                  label: 'Ver',
                  textColor: scheme.onError,
                  onPressed: () => GoRouter.of(context).push('/attendance/rejected'),
                )
              : null,
        ));
    });
    return const SizedBox.shrink();
  }
}
