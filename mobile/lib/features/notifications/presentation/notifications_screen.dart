import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/utils/formatters.dart';
import '../../../core/widgets/async_views.dart';
import '../domain/models.dart';
import 'notifications_providers.dart';
import '../../../core/widgets/page_padding.dart';
import '../../../core/widgets/skeleton.dart';

/// Pestañas de la navegación inferior: se abren con go() en vez de push().
const _tabRoutes = {'/home', '/history', '/requests', '/payslips', '/profile'};

/// Bandeja de notificaciones: tocar una la marca como leída y abre la
/// pantalla relacionada (boletas, solicitudes, documentos).
class NotificationsScreen extends ConsumerWidget {
  const NotificationsScreen({super.key});

  Future<void> _markAll(BuildContext context, WidgetRef ref) async {
    try {
      await ref.read(notificationsProvider.notifier).markAllRead();
      if (context.mounted) showSnack(context, 'Todas las notificaciones quedaron como leídas.');
    } on ApiException catch (e) {
      if (context.mounted) showSnack(context, e.message, error: true);
    }
  }

  void _open(BuildContext context, WidgetRef ref, AppNotification n) {
    ref.read(notificationsProvider.notifier).markRead(n);
    final route = n.route;
    if (route == null) return;
    if (_tabRoutes.contains(route)) {
      context.go(route);
    } else {
      context.push(route);
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final notifications = ref.watch(notificationsProvider);
    final hasUnread = notifications.value?.items.any((n) => !n.isRead) ?? false;

    return Scaffold(
      appBar: AppBar(
        title: const Text('Notificaciones'),
        actions: [
          TextButton(
            onPressed: hasUnread ? () => _markAll(context, ref) : null,
            child: const Text('Marcar todas'),
          ),
        ],
      ),
      body: notifications.whenSkeleton(
        loading: () => const LoadingView(),
        error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(notificationsProvider)),
        data: (paged) {
          final list = paged.items;
          final controller = ref.read(notificationsProvider.notifier);
          return RefreshIndicator(
            onRefresh: () => ref.refresh(notificationsProvider.future),
            child: list.isEmpty
                ? ListView(children: const [
                    SizedBox(height: 80),
                    EmptyView(icon: Icons.notifications_none_rounded, message: 'No tienes notificaciones.'),
                  ])
                : NotificationListener<ScrollNotification>(
                    onNotification: (n) => loadMoreOnScroll(n, controller.loadMore),
                    child: ListView.separated(
                      padding: pagePadding(context, top: 8, horizontal: 0, bottom: 16),
                      itemCount: list.length + 1,
                      separatorBuilder: (_, _) => const Divider(height: 1),
                      itemBuilder: (_, i) => i < list.length
                          ? _NotificationTile(notification: list[i], onTap: () => _open(context, ref, list[i]))
                          : LoadMoreFooter(
                              hasMore: paged.hasMore,
                              loading: paged.loadingMore,
                              error: paged.loadMoreError,
                              onLoadMore: () => controller.loadMore(retry: true),
                            ),
                    ),
                  ),
          );
        },
      ),
    );
  }
}

class _NotificationTile extends StatelessWidget {
  const _NotificationTile({required this.notification, required this.onTap});

  final AppNotification notification;
  final VoidCallback onTap;

  IconData get _icon {
    final kind = notification.type.split('.').first;
    return switch (kind) {
      'payslip' || 'payslips' => Icons.receipt_long_outlined,
      'leave_request' || 'leave_requests' || 'leave' => Icons.event_note_outlined,
      'document' || 'documents' => Icons.description_outlined,
      'attendance' => Icons.schedule_rounded,
      'onboarding' => Icons.assignment_ind_outlined,
      _ => Icons.notifications_outlined,
    };
  }

  @override
  Widget build(BuildContext context) {
    final n = notification;
    final scheme = Theme.of(context).colorScheme;
    final date = n.createdAt == null ? '' : '${Fmt.date(n.createdAt)} ${Fmt.time(n.createdAt)}';

    return ListTile(
      onTap: onTap,
      tileColor: n.isRead ? null : scheme.primaryContainer.withValues(alpha: 0.25),
      leading: CircleAvatar(
        backgroundColor: scheme.secondaryContainer,
        foregroundColor: scheme.onSecondaryContainer,
        child: Icon(_icon),
      ),
      title: Text(n.title, style: TextStyle(fontWeight: n.isRead ? FontWeight.normal : FontWeight.bold)),
      subtitle: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        if (n.body.isNotEmpty) Text(n.body, maxLines: 3, overflow: TextOverflow.ellipsis),
        if (date.isNotEmpty) Text(date, style: Theme.of(context).textTheme.bodySmall),
      ]),
      trailing: n.isRead ? null : Icon(Icons.circle, size: 10, color: scheme.primary),
      isThreeLine: n.body.isNotEmpty && date.isNotEmpty,
    );
  }
}

/// Campana con el contador de no leídas para la barra de Inicio.
class NotificationBell extends ConsumerWidget {
  const NotificationBell({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final unread = ref.watch(unreadCountProvider).value ?? 0;

    return IconButton(
      tooltip: unread > 0 ? 'Notificaciones ($unread sin leer)' : 'Notificaciones',
      onPressed: () async {
        await context.push('/notifications');
        if (context.mounted) ref.invalidate(unreadCountProvider);
      },
      icon: Badge(
        isLabelVisible: unread > 0,
        label: Text(unread > 99 ? '99+' : '$unread'),
        child: Icon(unread > 0 ? Icons.notifications_rounded : Icons.notifications_outlined),
      ),
    );
  }
}
