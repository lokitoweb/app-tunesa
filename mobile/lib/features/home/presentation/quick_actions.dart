import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/theme/app_theme.dart';
import '../../benefits/presentation/benefit_providers.dart';
import '../../company_docs/presentation/company_document_providers.dart';

/// Accesos directos de Inicio: pedir vacaciones, horas extra, beneficios y
/// documentos (estos dos solo si la empresa usa el módulo).
class QuickActions extends ConsumerWidget {
  const QuickActions({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final scheme = Theme.of(context).colorScheme;
    final docsOn = ref.watch(companyDocumentsEnabledProvider);
    final pendingDocs = docsOn ? (ref.watch(documentLibraryProvider).value?.pendingAcknowledgement ?? 0) : 0;

    final actions = <_Action>[
      _Action('Pedir', 'Vacaciones', Icons.beach_access_rounded, const Color(0xFFF5A623), '/requests/new?type=vacation'),
      _Action('Mis', 'Horas extra', Icons.alarm_rounded, scheme.primary, '/overtime'),
      if (ref.watch(benefitsEnabledProvider)) _Action('Mis', 'Beneficios', Icons.card_giftcard_rounded, const Color(0xFFE0457B), '/benefits'),
      if (docsOn) _Action('Ver', 'Documentos', Icons.folder_open_rounded, const Color(0xFF2E86DE), '/company-documents', badge: pendingDocs),
    ];

    return Padding(
      padding: const EdgeInsets.only(bottom: 16),
      child: GridView.count(
        crossAxisCount: 2,
        shrinkWrap: true,
        physics: const NeverScrollableScrollPhysics(),
        mainAxisSpacing: 12,
        crossAxisSpacing: 12,
        childAspectRatio: 2.1,
        children: [for (final a in actions) _ActionTile(action: a)],
      ),
    );
  }
}

class _Action {
  const _Action(this.verb, this.label, this.icon, this.color, this.route, {this.badge = 0});

  final String verb;
  final String label;
  final IconData icon;
  final Color color;
  final String route;
  final int badge;
}

class _ActionTile extends StatelessWidget {
  const _ActionTile({required this.action});

  final _Action action;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final a = action;

    return Card(
      margin: EdgeInsets.zero,
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        key: Key('quick-${a.label}'),
        borderRadius: BorderRadius.circular(AppTheme.radius),
        onTap: () => context.push(a.route),
        child: Stack(children: [
          // Esquinas de color, como un sello del acceso
          Positioned(
            right: -14,
            bottom: -14,
            child: Container(
              width: 58,
              height: 58,
              decoration: BoxDecoration(color: a.color, shape: BoxShape.circle),
              child: Align(alignment: const Alignment(-0.25, -0.25), child: Icon(a.icon, color: Colors.white, size: 24)),
            ),
          ),
          Positioned(
            left: -12,
            top: -12,
            child: Container(width: 30, height: 30, decoration: BoxDecoration(color: a.color.withValues(alpha: 0.35), shape: BoxShape.circle)),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 12, 44, 12),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisAlignment: MainAxisAlignment.center, children: [
              Text(a.verb, style: theme.textTheme.bodyMedium?.copyWith(color: theme.colorScheme.onSurfaceVariant)),
              FittedBox(
                fit: BoxFit.scaleDown,
                alignment: Alignment.centerLeft,
                child: Text(a.label, maxLines: 1, style: theme.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w800)),
              ),
            ]),
          ),
          if (a.badge > 0)
            Positioned(top: 8, right: 8, child: Badge(label: Text(a.badge > 99 ? '99+' : '${a.badge}'))),
        ]),
      ),
    );
  }
}
