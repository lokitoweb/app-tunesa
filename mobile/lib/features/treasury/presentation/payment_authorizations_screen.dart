import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/providers.dart';
import '../../../core/utils/formatters.dart';
import '../../../core/widgets/async_views.dart';
import '../../../core/widgets/page_padding.dart';
import '../../../core/widgets/skeleton.dart';
import '../../auth/presentation/auth_controller.dart';
import '../data/treasury_repository.dart';
import '../domain/models.dart';

final treasuryRepositoryProvider = Provider<TreasuryRepository>((ref) => TreasuryRepository(ref.watch(apiClientProvider)));

/// Órdenes de pago que esperan mi firma (pull-to-refresh).
final paymentAuthorizationsProvider = FutureProvider.autoDispose<List<PaymentAuthorization>>(
  (ref) => ref.watch(treasuryRepositoryProvider).pending(),
);

/// Contador en Inicio. Sin ser apoderado o sin red devuelve 0: es solo un aviso.
final pendingPaymentAuthorizationsCountProvider = FutureProvider.autoDispose<int>((ref) async {
  final user = ref.watch(authControllerProvider.select((s) => s.user));
  if (user == null || !user.canAuthorizePayments) return 0;
  try {
    return (await ref.watch(treasuryRepositoryProvider).pending()).length;
  } on ApiException {
    return 0;
  }
});

/// Firma APP: el apoderado designado autoriza las órdenes de pago programadas
/// por Tesorería. Tesorería las paga después, con voucher (otra persona).
class PaymentAuthorizationsScreen extends ConsumerWidget {
  const PaymentAuthorizationsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final pending = ref.watch(paymentAuthorizationsProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Autorizar pagos')),
      body: pending.whenSkeleton(
        loading: () => const SkeletonList(trailing: true),
        error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(paymentAuthorizationsProvider)),
        data: (list) => RefreshIndicator(
          onRefresh: () => ref.refresh(paymentAuthorizationsProvider.future),
          child: list.isEmpty
              ? ListView(children: const [
                  SizedBox(height: 80),
                  EmptyView(icon: Icons.verified_outlined, message: 'No hay órdenes de pago por autorizar.'),
                ])
              : ListView.builder(
                  padding: pagePadding(context),
                  itemCount: list.length + 1,
                  itemBuilder: (_, i) => i == 0
                      ? Padding(
                          padding: const EdgeInsets.only(bottom: 8),
                          child: Text('${list.length} por autorizar', style: Theme.of(context).textTheme.labelLarge),
                        )
                      : _AuthorizationCard(key: ValueKey(list[i - 1].id), item: list[i - 1]),
                ),
        ),
      ),
    );
  }
}

class _AuthorizationCard extends ConsumerStatefulWidget {
  const _AuthorizationCard({super.key, required this.item});

  final PaymentAuthorization item;

  @override
  ConsumerState<_AuthorizationCard> createState() => _AuthorizationCardState();
}

class _AuthorizationCardState extends ConsumerState<_AuthorizationCard> {
  bool _busy = false;

  Future<void> _authorize() async {
    final item = widget.item;
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => AlertDialog(
        title: const Text('Autorizar pago'),
        content: Text('¿Autorizar ${item.amountLabel} a ${item.supplierName ?? 'el beneficiario'} (${item.order ?? 'orden'})?'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(c, false), child: const Text('Volver')),
          FilledButton(onPressed: () => Navigator.pop(c, true), child: const Text('Autorizar')),
        ],
      ),
    );
    if (ok != true) return;

    setState(() => _busy = true);
    try {
      await ref.read(treasuryRepositoryProvider).authorize(item.id);
      if (!mounted) return;
      showSnack(context, 'Pago autorizado: Tesorería ya puede pagarlo.');
      ref.invalidate(paymentAuthorizationsProvider);
      ref.invalidate(pendingPaymentAuthorizationsCountProvider);
    } on ApiException catch (e) {
      if (!mounted) return;
      showSnack(context, e.message, error: true);
      // Ya autorizada o pagada por otro medio: se refresca la bandeja
      if (e.statusCode == 422) ref.invalidate(paymentAuthorizationsProvider);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final item = widget.item;
    final theme = Theme.of(context);
    final cuota = item.installmentsTotal != null && item.installmentsTotal! > 1 ? ' · cuota ${item.installment} de ${item.installmentsTotal}' : '';

    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(item.supplierName ?? 'Beneficiario', style: theme.textTheme.titleMedium),
                if (item.supplierRuc != null) Text(item.supplierRuc!, style: theme.textTheme.bodySmall),
              ]),
            ),
            const SizedBox(width: 8),
            Text(item.amountLabel, style: theme.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700)),
          ]),
          const SizedBox(height: 8),
          Text('${item.order ?? '—'}$cuota', style: theme.textTheme.titleSmall),
          if (item.detail != null) Text(item.detail!, maxLines: 3, overflow: TextOverflow.ellipsis),
          const SizedBox(height: 6),
          Wrap(spacing: 12, runSpacing: 4, children: [
            if (item.scheduledFor != null) Text('Se paga: ${Fmt.date(item.scheduledFor)}', style: theme.textTheme.bodySmall),
            if (item.dueDate != null) Text('Vence: ${Fmt.date(item.dueDate)}', style: theme.textTheme.bodySmall),
            if (item.invoice != null) Text('Factura ${item.invoice}', style: theme.textTheme.bodySmall),
          ]),
          const SizedBox(height: 12),
          Align(
            alignment: Alignment.centerRight,
            child: FilledButton.icon(
              onPressed: _busy ? null : _authorize,
              icon: _busy
                  ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))
                  : const Icon(Icons.verified_rounded),
              label: const Text('Autorizar'),
            ),
          ),
        ]),
      ),
    );
  }
}
