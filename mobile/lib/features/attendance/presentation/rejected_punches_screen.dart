import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/widgets/async_views.dart';
import '../domain/punch.dart';
import 'offline_sync.dart';
import '../../../core/widgets/page_padding.dart';

/// "Marcaciones no aceptadas": marcaciones de la cola offline que el servidor
/// rechazó o que vencieron sin enviarse, con el motivo. El colaborador las
/// descarta cuando ya las vio (por ejemplo, tras justificar con RRHH).
final rejectedPunchesProvider = FutureProvider.autoDispose<List<RejectedPunch>>((ref) {
  ref.watch(offlineSyncProvider.select((s) => s.rejected));
  return ref.read(offlineSyncProvider.notifier).rejectedList();
});

class RejectedPunchesScreen extends ConsumerWidget {
  const RejectedPunchesScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final list = ref.watch(rejectedPunchesProvider);
    final hasItems = list.value?.isNotEmpty ?? false;

    return Scaffold(
      appBar: AppBar(
        title: const Text('Marcaciones no aceptadas'),
        actions: [
          if (hasItems)
            TextButton(
              onPressed: () => ref.read(offlineSyncProvider.notifier).clearRejected(),
              child: const Text('Descartar todas'),
            ),
        ],
      ),
      body: list.when(
        loading: () => const LoadingView(),
        error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(rejectedPunchesProvider)),
        data: (items) => items.isEmpty
            ? const Center(
                child: Padding(
                  padding: EdgeInsets.all(24),
                  child: Text('No tienes marcaciones rechazadas.', textAlign: TextAlign.center),
                ),
              )
            : ListView.separated(
                padding: pagePadding(context),
                itemCount: items.length + 1,
                separatorBuilder: (_, _) => const SizedBox(height: 8),
                itemBuilder: (context, i) {
                  if (i == 0) {
                    return const Text(
                      'Estas marcaciones se tomaron sin conexión y no quedaron registradas. '
                      'Si corresponde, vuelve a marcar o justifica la asistencia con RRHH.',
                    );
                  }
                  final item = items[i - 1];
                  return Card(
                    child: ListTile(
                      leading: Icon(Icons.block, color: Theme.of(context).colorScheme.error),
                      title: Text('${item.action.label} · ${item.timeLabel}'),
                      subtitle: Text(item.message),
                      trailing: IconButton(
                        tooltip: 'Descartar',
                        icon: const Icon(Icons.close),
                        onPressed: () => ref.read(offlineSyncProvider.notifier).dismissRejected(item.clientUuid),
                      ),
                    ),
                  );
                },
              ),
      ),
    );
  }
}
