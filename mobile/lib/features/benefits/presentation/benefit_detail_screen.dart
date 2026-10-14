import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../core/utils/formatters.dart';
import '../../../core/widgets/async_views.dart';
import '../../../core/widgets/page_padding.dart';
import '../domain/models.dart';
import 'benefit_providers.dart';
import 'benefit_widgets.dart';

/// Detalle del beneficio: cómo se usa, código (se copia con un toque), enlace,
/// contacto y condiciones.
class BenefitDetailScreen extends ConsumerWidget {
  const BenefitDetailScreen({super.key, required this.id});

  final int id;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final detail = ref.watch(benefitDetailProvider(id));

    return Scaffold(
      appBar: AppBar(title: const Text('Beneficio')),
      body: detail.when(
        loading: () => const LoadingView(),
        error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(benefitDetailProvider(id))),
        data: (b) => _Body(benefit: b),
      ),
    );
  }
}

class _Body extends StatelessWidget {
  const _Body({required this.benefit});

  final Benefit benefit;

  Future<void> _open(BuildContext context, String url) async {
    final ok = await launchUrl(Uri.parse(url), mode: LaunchMode.externalApplication);
    if (!ok && context.mounted) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('No se pudo abrir el enlace.')));
    }
  }

  @override
  Widget build(BuildContext context) {
    final b = benefit;
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;

    Widget section(String title, String? text) => text == null
        ? const SizedBox.shrink()
        : Padding(
            padding: const EdgeInsets.only(top: 20),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(title, style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w800)),
              const SizedBox(height: 6),
              Text(text, style: theme.textTheme.bodyMedium?.copyWith(height: 1.4)),
            ]),
          );

    return ListView(
      padding: pagePadding(context, top: 0, horizontal: 0),
      children: [
        Stack(children: [
          BenefitImage(benefit: b, height: 200, iconSize: 72),
          if (b.highlight != null) Positioned(left: 16, bottom: 16, child: HighlightBadge(b.highlight!)),
        ]),
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 16, 16, 0),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            if (b.category != null)
              Text(b.category!.name.toUpperCase(), style: theme.textTheme.labelMedium?.copyWith(color: scheme.primary, fontWeight: FontWeight.w800)),
            const SizedBox(height: 4),
            Text(b.title, style: theme.textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.w800)),
            if (b.partner != null) Text(b.partner!, style: theme.textTheme.titleMedium?.copyWith(color: scheme.onSurfaceVariant)),
            if (b.summary != null) Padding(padding: const EdgeInsets.only(top: 10), child: Text(b.summary!, style: theme.textTheme.bodyLarge)),
            if (b.promoCode != null) ...[
              const SizedBox(height: 20),
              Material(
                color: scheme.primaryContainer,
                borderRadius: BorderRadius.circular(14),
                child: InkWell(
                  borderRadius: BorderRadius.circular(14),
                  onTap: () async {
                    await Clipboard.setData(ClipboardData(text: b.promoCode!));
                    if (context.mounted) {
                      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Código copiado')));
                    }
                  },
                  child: Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
                    child: Row(children: [
                      Icon(Icons.confirmation_number_outlined, color: scheme.onPrimaryContainer),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          Text('Tu código', style: theme.textTheme.labelMedium?.copyWith(color: scheme.onPrimaryContainer)),
                          Text(b.promoCode!,
                              style: theme.textTheme.titleLarge?.copyWith(
                                  color: scheme.onPrimaryContainer, fontWeight: FontWeight.w800, letterSpacing: 1.5, fontFamily: 'monospace')),
                        ]),
                      ),
                      Icon(Icons.copy_rounded, color: scheme.onPrimaryContainer),
                    ]),
                  ),
                ),
              ),
            ],
            section('¿Cómo lo uso?', b.howToUse),
            section('Detalle', b.description),
            if (b.location != null || b.contact != null || b.validUntil != null) const SizedBox(height: 12),
            if (b.location != null) _Info(icon: Icons.place_outlined, text: b.location!),
            if (b.contact != null) _Info(icon: Icons.call_outlined, text: b.contact!),
            if (b.validUntil != null) _Info(icon: Icons.event_outlined, text: 'Vigente hasta el ${Fmt.date(b.validUntil)}'),
            section('Términos y condiciones', b.terms),
            if (b.url != null) ...[
              const SizedBox(height: 24),
              FilledButton.icon(
                onPressed: () => _open(context, b.url!),
                icon: const Icon(Icons.open_in_new_rounded),
                label: const Text('Ir al beneficio'),
                style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(48)),
              ),
            ],
          ]),
        ),
      ],
    );
  }
}

class _Info extends StatelessWidget {
  const _Info({required this.icon, required this.text});

  final IconData icon;
  final String text;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 4),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Icon(icon, size: 20, color: Theme.of(context).colorScheme.primary),
          const SizedBox(width: 10),
          Expanded(child: Text(text)),
        ]),
      );
}
