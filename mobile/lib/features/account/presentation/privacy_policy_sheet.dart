import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';

import '../../../core/widgets/async_views.dart';
import '../domain/models.dart';
import 'account_providers.dart';

/// Política de privacidad dentro de la app (hoja modal, sin abrir el navegador). El texto lo
/// administra la empresa o la plataforma en Marca y app › Políticas de la app.
Future<void> showPrivacyPolicy(BuildContext context) => showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      showDragHandle: true,
      builder: (_) => const _PrivacyPolicySheet(),
    );

class _PrivacyPolicySheet extends ConsumerWidget {
  const _PrivacyPolicySheet();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final policy = ref.watch(privacyPolicyProvider);

    return DraggableScrollableSheet(
      expand: false,
      initialChildSize: 0.92,
      minChildSize: 0.5,
      maxChildSize: 0.95,
      builder: (context, controller) => policy.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (e, _) => Padding(
          padding: const EdgeInsets.all(24),
          child: ErrorView(error: e, onRetry: () => ref.invalidate(privacyPolicyProvider)),
        ),
        data: (p) => _PolicyBody(policy: p, controller: controller),
      ),
    );
  }
}

class _PolicyBody extends StatelessWidget {
  const _PolicyBody({required this.policy, required this.controller});

  final PrivacyPolicy policy;
  final ScrollController controller;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final body = theme.textTheme.bodyMedium?.copyWith(height: 1.55);

    return ListView(
      key: const Key('privacy-policy-sheet'),
      controller: controller,
      padding: const EdgeInsets.fromLTRB(20, 0, 20, 32),
      children: [
        Row(children: [
          Icon(Icons.shield_outlined, size: 18, color: scheme.primary),
          const SizedBox(width: 6),
          Text('PRIVACIDAD', style: theme.textTheme.labelMedium?.copyWith(color: scheme.primary, fontWeight: FontWeight.w700, letterSpacing: 0.6)),
        ]),
        const SizedBox(height: 6),
        Text('Política de privacidad', style: theme.textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.w700)),
        const SizedBox(height: 4),
        Text(
          [
            policy.appName,
            if (policy.updatedOn != null) 'Actualizada el ${DateFormat('dd/MM/yyyy').format(policy.updatedOn!)}',
          ].where((s) => s.isNotEmpty).join(' · '),
          style: theme.textTheme.bodySmall?.copyWith(color: scheme.onSurfaceVariant),
        ),
        const SizedBox(height: 16),
        for (final block in policy.blocks) _Block(block: block, style: body),
        if (policy.contact.isNotEmpty) ...[
          const SizedBox(height: 20),
          Card(
            margin: EdgeInsets.zero,
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text('Contacto sobre privacidad', style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
                const SizedBox(height: 8),
                if (policy.contact['email'] != null) _ContactLine(icon: Icons.mail_outline_rounded, text: policy.contact['email']!),
                if (policy.contact['phone'] != null) _ContactLine(icon: Icons.phone_outlined, text: policy.contact['phone']!),
                if (policy.contact['address'] != null) _ContactLine(icon: Icons.place_outlined, text: policy.contact['address']!),
              ]),
            ),
          ),
        ],
      ],
    );
  }
}

class _Block extends StatelessWidget {
  const _Block({required this.block, required this.style});

  final PolicyBlock block;
  final TextStyle? style;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    switch (block.type) {
      case 'heading':
        return Padding(
          padding: const EdgeInsets.only(top: 18, bottom: 8),
          child: Text(block.text.replaceAll('**', ''), style: theme.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700)),
        );
      case 'bullet':
        return Padding(
          padding: const EdgeInsets.only(bottom: 8),
          child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Padding(
              padding: const EdgeInsets.only(top: 8, right: 10),
              child: Icon(Icons.circle, size: 6, color: theme.colorScheme.primary),
            ),
            Expanded(child: Text.rich(_rich(block.text, style), style: style)),
          ]),
        );
      default:
        return Padding(
          padding: const EdgeInsets.only(bottom: 12),
          child: Text.rich(_rich(block.text, style), style: style),
        );
    }
  }

  /// "Texto con **negrita**" → TextSpan con los tramos en negrita.
  static TextSpan _rich(String text, TextStyle? style) {
    final parts = text.split('**');
    return TextSpan(children: [
      for (var i = 0; i < parts.length; i++)
        if (parts[i].isNotEmpty)
          TextSpan(text: parts[i], style: i.isOdd ? const TextStyle(fontWeight: FontWeight.w700) : null),
    ]);
  }
}

class _ContactLine extends StatelessWidget {
  const _ContactLine({required this.icon, required this.text});

  final IconData icon;
  final String text;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(bottom: 6),
        child: Row(children: [
          Icon(icon, size: 18),
          const SizedBox(width: 8),
          Expanded(child: SelectableText(text)),
        ]),
      );
}
