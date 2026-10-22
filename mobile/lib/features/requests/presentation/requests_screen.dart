import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/providers.dart';
import '../../../core/utils/formatters.dart';
import '../../../core/utils/paged.dart';
import '../../../core/widgets/async_views.dart';
import '../../../core/widgets/status_chip.dart';
import '../data/leave_repository.dart';
import '../domain/models.dart';
import '../../../core/widgets/page_padding.dart';
import '../../../core/widgets/skeleton.dart';

final leaveRepositoryProvider = Provider<LeaveRepository>((ref) => LeaveRepository(ref.watch(apiClientProvider)));

/// Mis solicitudes con scroll infinito (20 por página).
class LeaveRequestsController extends PagedController<LeaveRequest> {
  @override
  Future<Paged<LeaveRequest>> fetchPage(int page) => ref.read(leaveRepositoryProvider).list(page: page);
}

final leaveRequestsProvider = AsyncNotifierProvider.autoDispose<LeaveRequestsController, Paged<LeaveRequest>>(
  LeaveRequestsController.new,
);

/// Mis solicitudes de vacaciones, permisos y licencias.
class RequestsScreen extends ConsumerWidget {
  const RequestsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final requests = ref.watch(leaveRequestsProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Mis solicitudes')),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () async {
          final created = await context.push<bool>('/requests/new');
          if (created == true) ref.invalidate(leaveRequestsProvider);
        },
        icon: const Icon(Icons.add),
        label: const Text('Nueva'),
      ),
      body: requests.whenSkeleton(
        loading: () => const SkeletonList(leading: SkeletonLeading.square, trailing: true),
        error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(leaveRequestsProvider)),
        data: (paged) {
          final list = paged.items;
          final controller = ref.read(leaveRequestsProvider.notifier);
          return RefreshIndicator(
            onRefresh: () => ref.refresh(leaveRequestsProvider.future),
            child: list.isEmpty
                ? ListView(children: const [
                    SizedBox(height: 80),
                    EmptyView(icon: Icons.beach_access_outlined, message: 'Aún no tienes solicitudes.\nToca "Nueva" para pedir vacaciones o un permiso.'),
                  ])
                : NotificationListener<ScrollNotification>(
                    onNotification: (n) => loadMoreOnScroll(n, controller.loadMore),
                    child: ListView.builder(
                      padding: pagePadding(context, bottom: 96),
                      itemCount: list.length + 1,
                      itemBuilder: (_, i) => i < list.length
                          ? _RequestCard(request: list[i])
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

class _RequestCard extends ConsumerWidget {
  const _RequestCard({required this.request});

  final LeaveRequest request;

  Future<void> _cancel(BuildContext context, WidgetRef ref) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => AlertDialog(
        title: const Text('Cancelar solicitud'),
        content: const Text('La solicitud dejará de estar pendiente de aprobación.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(c, false), child: const Text('Volver')),
          FilledButton(onPressed: () => Navigator.pop(c, true), child: const Text('Cancelar solicitud')),
        ],
      ),
    );
    if (ok != true) return;
    try {
      await ref.read(leaveRepositoryProvider).cancel(request.id);
      ref.invalidate(leaveRequestsProvider);
      if (context.mounted) showSnack(context, 'Solicitud cancelada.');
    } on ApiException catch (e) {
      if (context.mounted) showSnack(context, e.message, error: true);
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final r = request;
    final range = r.startDate == r.endDate ? Fmt.date(r.startDate) : '${Fmt.date(r.startDate)} al ${Fmt.date(r.endDate)}';
    final hours = r.startTime != null ? ' · ${Fmt.clock(r.startTime)} – ${Fmt.clock(r.endTime)}' : '';
    final days = r.days == r.days.roundToDouble() ? r.days.toInt().toString() : r.days.toString();

    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Expanded(child: Text(r.typeLabel, style: Theme.of(context).textTheme.titleMedium)),
            StatusChip(status: r.status, label: r.statusLabel),
          ]),
          const SizedBox(height: 4),
          Text('$range$hours · $days día(s)'),
          const SizedBox(height: 8),
          Text(r.reason, maxLines: 3, overflow: TextOverflow.ellipsis),
          if (r.reviewNotes != null && r.reviewNotes!.isNotEmpty) ...[
            const SizedBox(height: 8),
            Text('Respuesta${r.reviewer != null ? ' de ${r.reviewer}' : ''}: ${r.reviewNotes}',
                style: Theme.of(context).textTheme.bodySmall),
          ],
          if (r.isPending)
            Align(
              alignment: Alignment.centerRight,
              child: TextButton(onPressed: () => _cancel(context, ref), child: const Text('Cancelar')),
            ),
        ]),
      ),
    );
  }
}
