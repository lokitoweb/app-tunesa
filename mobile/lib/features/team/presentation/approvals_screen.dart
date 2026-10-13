import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/utils/formatters.dart';
import '../../../core/widgets/async_views.dart';
import '../domain/models.dart';
import 'team_providers.dart';
import '../../../core/widgets/page_padding.dart';

/// Bandeja del supervisor / RRHH: solicitudes pendientes con aprobar y rechazar.
class ApprovalsScreen extends ConsumerWidget {
  const ApprovalsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final approvals = ref.watch(approvalsProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Aprobaciones')),
      body: approvals.when(
        loading: () => const LoadingView(),
        error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(approvalsProvider)),
        data: (paged) {
          final list = paged.items;
          final controller = ref.read(approvalsProvider.notifier);
          return RefreshIndicator(
            onRefresh: () => ref.refresh(approvalsProvider.future),
            child: list.isEmpty
                ? ListView(children: const [
                    SizedBox(height: 80),
                    EmptyView(icon: Icons.task_alt_rounded, message: 'No hay solicitudes pendientes de aprobación.'),
                  ])
                : NotificationListener<ScrollNotification>(
                    onNotification: (n) => loadMoreOnScroll(n, controller.loadMore),
                    child: ListView.builder(
                      padding: pagePadding(context),
                      itemCount: list.length + 2,
                      itemBuilder: (_, i) {
                        if (i == 0) {
                          return Padding(
                            padding: const EdgeInsets.only(bottom: 8),
                            child: Text('${paged.total} pendiente(s)', style: Theme.of(context).textTheme.labelLarge),
                          );
                        }
                        if (i <= list.length) return _ApprovalCard(key: ValueKey(list[i - 1].id), item: list[i - 1]);
                        return LoadMoreFooter(
                          hasMore: paged.hasMore,
                          loading: paged.loadingMore,
                          error: paged.loadMoreError,
                          onLoadMore: () => controller.loadMore(retry: true),
                        );
                      },
                    ),
                  ),
          );
        },
      ),
    );
  }
}

class _ApprovalCard extends ConsumerStatefulWidget {
  const _ApprovalCard({super.key, required this.item});

  final ApprovalRequest item;

  @override
  ConsumerState<_ApprovalCard> createState() => _ApprovalCardState();
}

class _ApprovalCardState extends ConsumerState<_ApprovalCard> {
  bool _busy = false;

  Future<void> _approve() async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => AlertDialog(
        title: const Text('Aprobar solicitud'),
        content: Text('¿Aprobar la solicitud de ${widget.item.employeeName}?'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(c, false), child: const Text('Volver')),
          FilledButton(onPressed: () => Navigator.pop(c, true), child: const Text('Aprobar')),
        ],
      ),
    );
    if (ok != true) return;
    await _review(approve: true);
  }

  Future<void> _reject() async {
    final reason = await showDialog<String>(
      context: context,
      builder: (_) => _RejectDialog(employeeName: widget.item.employeeName),
    );
    if (reason == null) return;
    await _review(approve: false, notes: reason);
  }

  Future<void> _review({required bool approve, String? notes}) async {
    setState(() => _busy = true);
    try {
      await ref.read(approvalsProvider.notifier).review(widget.item.id, approve: approve, notes: notes);
      if (mounted) showSnack(context, approve ? 'Solicitud aprobada.' : 'Solicitud rechazada.');
    } on ApiException catch (e) {
      if (mounted) {
        showSnack(context, e.isForbidden && e.code == 'INSUFFICIENT_PERMISSION' ? 'No tienes permiso para revisar solicitudes.' : e.message,
            error: true);
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final item = widget.item;
    final r = item.request;
    final theme = Theme.of(context);
    final range = r.startDate == r.endDate ? Fmt.date(r.startDate) : '${Fmt.date(r.startDate)} al ${Fmt.date(r.endDate)}';
    final hours = r.startTime != null ? ' · ${Fmt.clock(r.startTime)} – ${Fmt.clock(r.endTime)}' : '';
    final days = r.days == r.days.roundToDouble() ? r.days.toInt().toString() : r.days.toString();

    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            const Icon(Icons.person_outline_rounded, size: 20),
            const SizedBox(width: 6),
            Expanded(
              child: Text(
                item.employeeCode != null ? '${item.employeeName} · ${item.employeeCode}' : item.employeeName,
                style: theme.textTheme.titleMedium,
              ),
            ),
          ]),
          const SizedBox(height: 6),
          Text(r.typeLabel, style: theme.textTheme.titleSmall),
          Text('$range$hours · $days día(s)'),
          const SizedBox(height: 8),
          Text(r.reason, maxLines: 4, overflow: TextOverflow.ellipsis),
          if (r.hasAttachment) ...[
            const SizedBox(height: 6),
            Row(children: [
              Icon(Icons.attach_file_rounded, size: 16, color: theme.colorScheme.outline),
              const SizedBox(width: 4),
              Text('Con sustento adjunto (revísalo en la web)', style: theme.textTheme.bodySmall),
            ]),
          ],
          if (item.createdAt != null) ...[
            const SizedBox(height: 6),
            Text('Enviada el ${Fmt.date(item.createdAt)}', style: theme.textTheme.bodySmall),
          ],
          const SizedBox(height: 12),
          if (_busy)
            const Align(
              alignment: Alignment.centerRight,
              child: SizedBox.square(dimension: 28, child: CircularProgressIndicator(strokeWidth: 3)),
            )
          else
            Row(mainAxisAlignment: MainAxisAlignment.end, children: [
              OutlinedButton.icon(
                style: OutlinedButton.styleFrom(foregroundColor: theme.colorScheme.error),
                onPressed: _reject,
                icon: const Icon(Icons.close_rounded),
                label: const Text('Rechazar'),
              ),
              const SizedBox(width: 8),
              FilledButton.icon(onPressed: _approve, icon: const Icon(Icons.check_rounded), label: const Text('Aprobar')),
            ]),
        ]),
      ),
    );
  }
}

/// Pide el motivo del rechazo (obligatorio en el backend, máx. 1000 caracteres).
class _RejectDialog extends StatefulWidget {
  const _RejectDialog({required this.employeeName});

  final String employeeName;

  @override
  State<_RejectDialog> createState() => _RejectDialogState();
}

class _RejectDialogState extends State<_RejectDialog> {
  final _formKey = GlobalKey<FormState>();
  final _reason = TextEditingController();

  @override
  void dispose() {
    _reason.dispose();
    super.dispose();
  }

  void _submit() {
    if (_formKey.currentState?.validate() ?? false) Navigator.pop(context, _reason.text.trim());
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: const Text('Rechazar solicitud'),
      content: Form(
        key: _formKey,
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text('${widget.employeeName} verá este motivo.'),
          const SizedBox(height: 12),
          TextFormField(
            controller: _reason,
            autofocus: true,
            maxLines: 3,
            maxLength: 1000,
            textCapitalization: TextCapitalization.sentences,
            decoration: const InputDecoration(labelText: 'Motivo del rechazo', border: OutlineInputBorder()),
            validator: (v) => (v == null || v.trim().isEmpty) ? 'Escribe el motivo' : null,
          ),
        ]),
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context), child: const Text('Volver')),
        FilledButton(onPressed: _submit, child: const Text('Rechazar')),
      ],
    );
  }
}
