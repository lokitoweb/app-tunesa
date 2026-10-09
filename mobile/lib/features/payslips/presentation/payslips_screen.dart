import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/providers.dart';
import '../../../core/utils/formatters.dart';
import '../../../core/widgets/async_views.dart';
import '../data/payslip_repository.dart';
import '../domain/models.dart';

final payslipRepositoryProvider = Provider<PayslipRepository>((ref) => PayslipRepository(ref.watch(apiClientProvider)));

final payslipsProvider = FutureProvider.autoDispose<List<Payslip>>((ref) => ref.watch(payslipRepositoryProvider).list());

class PayslipsScreen extends ConsumerWidget {
  const PayslipsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final payslips = ref.watch(payslipsProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Mis boletas')),
      body: payslips.when(
        loading: () => const LoadingView(),
        error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(payslipsProvider)),
        data: (list) => RefreshIndicator(
          onRefresh: () => ref.refresh(payslipsProvider.future),
          child: list.isEmpty
              ? ListView(children: const [
                  SizedBox(height: 80),
                  EmptyView(icon: Icons.receipt_long_outlined, message: 'Aún no tienes boletas publicadas.'),
                ])
              : ListView.separated(
                  padding: const EdgeInsets.all(16),
                  itemCount: list.length,
                  separatorBuilder: (_, _) => const SizedBox(height: 8),
                  itemBuilder: (_, i) {
                    final p = list[i];
                    return Card(
                      child: ListTile(
                        leading: Icon(Icons.picture_as_pdf, color: Theme.of(context).colorScheme.primary),
                        title: Text(p.periodLabel, style: TextStyle(fontWeight: p.isRead ? FontWeight.normal : FontWeight.bold)),
                        subtitle: Text('${p.title} · publicada ${Fmt.date(p.publishedAt)}'),
                        trailing: p.isRead ? const Icon(Icons.chevron_right) : const Badge(label: Text('Nueva')),
                        onTap: () async {
                          await context.push('/payslips/${p.id}', extra: p.periodLabel);
                          ref.invalidate(payslipsProvider);
                        },
                      ),
                    );
                  },
                ),
        ),
      ),
    );
  }
}
