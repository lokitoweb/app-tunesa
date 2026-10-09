import 'dart:async';

import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/config/app_config_controller.dart';
import '../../../core/providers.dart';
import '../../../core/widgets/async_views.dart';
import '../../auth/presentation/auth_controller.dart';
import '../data/face_detection_service.dart';
import '../data/offline_clock_queue.dart';
import 'attendance_providers.dart';

final offlineClockQueueProvider = Provider<OfflineClockQueue>(
  (ref) => OfflineClockQueue(const FlutterSecureKeyValueStore()),
);

final faceDetectionServiceProvider = Provider<FaceDetectionService>((ref) => FaceDetectionService());

class OfflineSyncState {
  const OfflineSyncState({this.pending = 0, this.last, this.seq = 0});

  /// Marcaciones guardadas sin conexión que faltan enviar.
  final int pending;

  /// Resultado del último envío (para avisar al colaborador).
  final OfflineSyncResult? last;
  final int seq;
}

/// Envía la cola offline al recuperar señal, al volver a la app y con
/// "deslizar para actualizar" en Inicio.
class OfflineSyncController extends Notifier<OfflineSyncState> {
  @override
  OfflineSyncState build() {
    ref.listen(connectivityProvider, (previous, next) {
      if (previous?.value == false && next.value == true) sync();
    });
    final lifecycle = AppLifecycleListener(onResume: () => sync());
    ref.onDispose(lifecycle.dispose);
    Future.microtask(sync);
    return const OfflineSyncState();
  }

  OfflineClockQueue get _queue => ref.read(offlineClockQueueProvider);

  Future<void> _reloadCount() async {
    final pending = await _queue.count();
    if (ref.mounted) state = OfflineSyncState(pending: pending, last: state.last, seq: state.seq);
  }

  Future<void> enqueue(PendingClock item) async {
    await _queue.add(item);
    await _reloadCount();
  }

  Future<OfflineSyncResult?> sync() async {
    try {
      final auth = ref.read(authControllerProvider);
      if (auth.status != AuthStatus.authenticated || await _queue.count() == 0) {
        await _reloadCount();
        return null;
      }
      final hours = ref.read(appConfigProvider).config.mobile.offlineMaxHours;
      final repo = ref.read(attendanceRepositoryProvider);
      final result = await _queue.sync(
        send: repo.sendPending,
        maxAge: Duration(hours: hours),
        userId: auth.user?.id,
      );
      if (!ref.mounted) return result;
      state = OfflineSyncState(pending: result.remaining, last: result, seq: state.seq + 1);
      if (result.sent.isNotEmpty || result.rejected.isNotEmpty) ref.invalidate(todayStatusProvider);
      return result;
    } catch (_) {
      // Almacenamiento seguro no disponible: se reintenta en el próximo evento.
      return null;
    }
  }
}

final offlineSyncProvider = NotifierProvider<OfflineSyncController, OfflineSyncState>(OfflineSyncController.new);

/// Mensajes para el colaborador tras sincronizar.
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
      showSnack(context, messages.map((m) => m.$1).join('\n'), error: error);
    });
    return const SizedBox.shrink();
  }
}
