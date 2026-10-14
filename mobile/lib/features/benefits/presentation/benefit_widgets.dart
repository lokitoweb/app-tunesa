import 'package:flutter/material.dart';

import '../../../core/widgets/module_icons.dart';
import '../domain/models.dart';

/// Imagen del beneficio; sin imagen, el ícono de su categoría sobre su color.
class BenefitImage extends StatelessWidget {
  const BenefitImage({super.key, required this.benefit, this.height = 140, this.iconSize = 44});

  final Benefit benefit;
  final double height;
  final double iconSize;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final color = hexColor(benefit.category?.color, scheme.primary);
    final placeholder = Container(
      height: height,
      width: double.infinity,
      decoration: BoxDecoration(
        gradient: LinearGradient(
          colors: [color.withValues(alpha: 0.85), Color.lerp(color, scheme.secondary, 0.5)!.withValues(alpha: 0.85)],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
      ),
      child: Icon(moduleIcon(benefit.category?.icon, fallback: Icons.card_giftcard_rounded), size: iconSize, color: Colors.white),
    );
    if (benefit.imageUrl == null) return placeholder;

    return SizedBox(
      height: height,
      width: double.infinity,
      child: Image.network(benefit.imageUrl!, fit: BoxFit.cover, errorBuilder: (_, _, _) => placeholder),
    );
  }
}

/// Etiqueta del destacado ("20% dscto").
class HighlightBadge extends StatelessWidget {
  const HighlightBadge(this.text, {super.key});

  final String text;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(color: scheme.inverseSurface, borderRadius: BorderRadius.circular(20)),
      child: Text(text, style: TextStyle(color: scheme.onInverseSurface, fontWeight: FontWeight.w800, fontSize: 13)),
    );
  }
}
