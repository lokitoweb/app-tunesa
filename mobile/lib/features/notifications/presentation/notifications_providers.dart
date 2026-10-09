import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/providers.dart';
import '../../../core/utils/paged.dart';
import '../../auth/presentation/auth_controller.dart';
import '../data/notification_repository.dart';
import '../domain/models.dart';

final notificationRepositoryProvider = Provider<NotificationRepository>(
  (ref) => NotificationRepository(ref.watch(apiClientProvider)),
);

/// No leídas para la campana de Inicio. Se consulta al abrir Inicio, al volver
/// a la app y con pull-to-refresh (sin servicio en segundo plano). Si falla
/// (sin red, endpoint no disponible) muestra 0: es solo un aviso.
class UnreadCountController extends AsyncNotifier<int> {
  @override
  Future<int> build() async {
    // Se reinicia al cambiar de usuario (login / logout)
    final userId = ref.watch(
      authControllerProvider.select((s) => s.status == AuthStatus.authenticated ? s.user?.id : null),
    );
    if (userId == null) return 0;
    try {
      return await ref.read(notificationRepositoryProvider).unreadCount();
    } on ApiException {
      return 0;
    }
  }

  void set(int value) => state = AsyncData(value < 0 ? 0 : value);

  void decrement() => set((state.value ?? 0) - 1);
}

final unreadCountProvider = AsyncNotifierProvider<UnreadCountController, int>(UnreadCountController.new);

/// Bandeja de notificaciones con scroll infinito.
class NotificationsController extends PagedController<AppNotification> {
  NotificationRepository get _repo => ref.read(notificationRepositoryProvider);

  @override
  Future<Paged<AppNotification>> fetchPage(int page) => _repo.list(page: page);

  @override
  Future<Paged<AppNotification>> build() async {
    final first = await fetchPage(1);
    // La bandeja trae el contador real: se sincroniza la campana
    if (ref.mounted) ref.read(unreadCountProvider.notifier).set(NotificationRepository.unreadOf(first));
    return first;
  }

  /// Marca como leída al tocarla (optimista: no bloquea la navegación).
  Future<void> markRead(AppNotification notification) async {
    if (notification.isRead) return;
    updateItems((items) => [for (final n in items) n.id == notification.id ? n.markedRead() : n]);
    ref.read(unreadCountProvider.notifier).decrement();
    try {
      await _repo.markRead(notification.id);
    } on ApiException {
      // Se vuelve a sincronizar al refrescar la bandeja
    }
  }

  /// Lanza [ApiException] para que la UI muestre el error.
  Future<void> markAllRead() async {
    await _repo.markAllRead();
    if (!ref.mounted) return;
    final now = DateTime.now();
    updateItems((items) => [for (final n in items) n.markedRead(now)]);
    ref.read(unreadCountProvider.notifier).set(0);
  }
}

final notificationsProvider = AsyncNotifierProvider.autoDispose<NotificationsController, Paged<AppNotification>>(
  NotificationsController.new,
);
