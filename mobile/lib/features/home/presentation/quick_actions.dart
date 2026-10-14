import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/config/app_config_controller.dart';
import '../../../core/theme/app_theme.dart';
import '../../benefits/presentation/benefit_providers.dart';
import '../../company_docs/presentation/company_document_providers.dart';

/// Accesos directos de Inicio. Cada uno aparece solo si la empresa tiene
/// activo su módulo (se administra en el panel: Empresas › Módulos).
class QuickActions extends ConsumerWidget {
  const QuickActions({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final scheme = Theme.of(context).colorScheme;
    final docsOn = ref.watch(companyDocumentsEnabledProvider);
    final pendingDocs = docsOn ? (ref.watch(documentLibraryProvider).value?.pendingAcknowledgement ?? 0) : 0;
    final requestsOn = ref.watch(appConfigProvider.select((s) => s.config.modules.requests));

    final actions = <_Action>[
      if (requestsOn) const _Action('Pedir', 'Vacaciones', Icons.beach_access_rounded, AppTheme.success, '/requests/new?type=vacation'),
      _Action('Mis', 'Horas extra', Icons.more_time_rounded, scheme.primary, '/overtime'),
      if (ref.watch(benefitsEnabledProvider)) const _Action('Mis', 'Beneficios', Icons.card_giftcard_rounded, Color(0xFF0EA5A4), '/benefits'),
      if (docsOn) _Action('Ver', 'Documentos', Icons.folder_copy_rounded, Color.lerp(scheme.primary, Colors.black, 0.2)!, '/company-documents', badge: pendingDocs),
    ];
    if (actions.isEmpty) return const SizedBox.shrink();

    return Padding(
      padding: const EdgeInsets.only(bottom: 16),
      // Alto fijo (no proporción): con letra grande o pantallas angostas no se desborda
      child: GridView(
        shrinkWrap: true,
        physics: const NeverScrollableScrollPhysics(),
        gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
          crossAxisCount: 2,
          mainAxisSpacing: 12,
          crossAxisSpacing: 12,
          mainAxisExtent: 112 + 28 * (MediaQuery.textScalerOf(context).scale(1) - 1).clamp(0, 2),
        ),
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

/// Tarjeta blanca: ícono en un recuadro suave de su color, verbo y nombre.
class _ActionTile extends StatelessWidget {
  const _ActionTile({required this.action});

  final _Action action;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final a = action;

    return Card(
      margin: EdgeInsets.zero,
      child: InkWell(
        key: Key('quick-${a.label}'),
        borderRadius: BorderRadius.circular(AppTheme.radius),
        onTap: () => context.push(a.route),
        child: Container(
          // Filo de color a la izquierda: identifica el acceso sin recargar la tarjeta
          decoration: BoxDecoration(border: Border(left: BorderSide(color: a.color, width: 3))),
          padding: const EdgeInsets.fromLTRB(13, 14, 14, 14),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              Container(
                width: 38,
                height: 38,
                decoration: BoxDecoration(color: a.color.withValues(alpha: 0.12), borderRadius: BorderRadius.circular(8)),
                child: Icon(a.icon, color: a.color, size: 22),
              ),
              const Spacer(),
              if (a.badge > 0)
                Badge(label: Text(a.badge > 99 ? '99+' : '${a.badge}'))
              else
                Icon(Icons.arrow_forward_rounded, size: 18, color: scheme.onSurfaceVariant.withValues(alpha: 0.6)),
            ]),
            const Spacer(),
            Text(a.verb, style: theme.textTheme.labelMedium?.copyWith(color: scheme.onSurfaceVariant)),
            FittedBox(
              fit: BoxFit.scaleDown,
              alignment: Alignment.centerLeft,
              child: Text(a.label, maxLines: 1, style: theme.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w800)),
            ),
          ]),
        ),
      ),
    );
  }
}
