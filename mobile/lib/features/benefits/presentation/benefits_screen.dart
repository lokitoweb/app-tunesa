import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/theme/app_theme.dart';
import '../../../core/utils/formatters.dart';
import '../../../core/widgets/async_views.dart';
import '../../../core/widgets/module_icons.dart';
import '../../../core/widgets/page_padding.dart';
import '../domain/models.dart';
import 'benefit_providers.dart';
import 'benefit_widgets.dart';
import '../../../core/widgets/skeleton.dart';

/// Beneficios de la persona: destacados arriba, filtro por categoría y la lista.
class BenefitsScreen extends ConsumerStatefulWidget {
  const BenefitsScreen({super.key});

  @override
  ConsumerState<BenefitsScreen> createState() => _BenefitsScreenState();
}

class _BenefitsScreenState extends ConsumerState<BenefitsScreen> {
  int? _category;

  @override
  Widget build(BuildContext context) {
    final catalog = ref.watch(benefitCatalogProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Beneficios')),
      body: catalog.whenSkeleton(
        loading: () => const SkeletonList(leading: SkeletonLeading.square, lines: 3),
        error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(benefitCatalogProvider)),
        data: (c) {
          final items = _category == null ? c.items : c.items.where((b) => b.category?.id == _category).toList();
          final featured = _category == null ? c.items.where((b) => b.isFeatured).toList() : const <Benefit>[];

          return RefreshIndicator(
            onRefresh: () => ref.refresh(benefitCatalogProvider.future),
            child: c.items.isEmpty
                ? ListView(children: const [
                    SizedBox(height: 80),
                    EmptyView(icon: Icons.card_giftcard_rounded, message: 'Pronto tendrás beneficios aquí: descuentos, convenios y promociones de tu empresa.'),
                  ])
                : ListView(
                    padding: pagePadding(context, horizontal: 0),
                    children: [
                      if (featured.isNotEmpty) ...[
                        const _Heading('Destacados'),
                        SizedBox(
                          height: 210,
                          child: ListView.separated(
                            scrollDirection: Axis.horizontal,
                            padding: const EdgeInsets.symmetric(horizontal: 16),
                            itemCount: featured.length,
                            separatorBuilder: (_, _) => const SizedBox(width: 12),
                            itemBuilder: (_, i) => SizedBox(width: 280, child: BenefitCard(benefit: featured[i], compact: true)),
                          ),
                        ),
                      ],
                      if (c.categories.length > 1)
                        SizedBox(
                          height: 56,
                          child: ListView(
                            scrollDirection: Axis.horizontal,
                            padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
                            children: [
                              _CategoryChip(label: 'Todos', selected: _category == null, onTap: () => setState(() => _category = null)),
                              for (final cat in c.categories)
                                _CategoryChip(
                                  label: '${cat.name} (${cat.count})',
                                  icon: moduleIcon(cat.icon, fallback: Icons.local_offer_rounded),
                                  selected: _category == cat.id,
                                  onTap: () => setState(() => _category = cat.id),
                                ),
                            ],
                          ),
                        ),
                      _Heading(_category == null ? 'Todos los beneficios' : c.categories.firstWhere((x) => x.id == _category).name),
                      Padding(
                        padding: const EdgeInsets.symmetric(horizontal: 16),
                        child: Column(children: [for (final b in items) BenefitCard(benefit: b)]),
                      ),
                    ],
                  ),
          );
        },
      ),
    );
  }
}

class _Heading extends StatelessWidget {
  const _Heading(this.text);

  final String text;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 10),
        child: Text(text, style: Theme.of(context).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w800)),
      );
}

class _CategoryChip extends StatelessWidget {
  const _CategoryChip({required this.label, required this.selected, required this.onTap, this.icon});

  final String label;
  final IconData? icon;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return Padding(
      padding: const EdgeInsets.only(right: 8),
      child: ChoiceChip(
        avatar: icon == null ? null : Icon(icon, size: 18, color: selected ? scheme.onPrimary : scheme.primary),
        label: Text(label),
        selected: selected,
        showCheckmark: false,
        selectedColor: scheme.primary,
        labelStyle: TextStyle(fontWeight: FontWeight.w600, color: selected ? scheme.onPrimary : null),
        shape: const StadiumBorder(),
        onSelected: (_) => onTap(),
      ),
    );
  }
}

/// Tarjeta del beneficio: imagen, destacado, título, aliado y vigencia.
class BenefitCard extends StatelessWidget {
  const BenefitCard({super.key, required this.benefit, this.compact = false});

  final Benefit benefit;

  /// Carrusel de destacados: sin márgenes ni resumen.
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final b = benefit;

    return Card(
      margin: compact ? EdgeInsets.zero : const EdgeInsets.only(bottom: 14),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: () => context.push('/benefits/${b.id}'),
        borderRadius: BorderRadius.circular(AppTheme.radius),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Stack(children: [
            BenefitImage(benefit: b, height: compact ? 110 : 140),
            if (b.highlight != null) Positioned(left: 10, top: 10, child: HighlightBadge(b.highlight!)),
            if (b.isFeatured && !compact)
              const Positioned(right: 10, top: 10, child: Icon(Icons.star_rounded, color: Colors.amber, size: 26)),
          ]),
          Padding(
            padding: const EdgeInsets.fromLTRB(14, 12, 14, 14),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(b.title, maxLines: 2, overflow: TextOverflow.ellipsis, style: theme.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w800)),
              if (b.partner != null || b.category != null)
                Padding(
                  padding: const EdgeInsets.only(top: 2),
                  child: Text(
                    [b.partner, b.category?.name].whereType<String>().join(' · '),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.onSurfaceVariant),
                  ),
                ),
              if (!compact && b.summary != null)
                Padding(padding: const EdgeInsets.only(top: 6), child: Text(b.summary!, maxLines: 2, overflow: TextOverflow.ellipsis)),
              if (!compact && b.validUntil != null)
                Padding(
                  padding: const EdgeInsets.only(top: 6),
                  child: Text('Vigente hasta el ${Fmt.date(b.validUntil)}',
                      style: theme.textTheme.labelSmall?.copyWith(color: theme.colorScheme.onSurfaceVariant)),
                ),
            ]),
          ),
        ]),
      ),
    );
  }
}
