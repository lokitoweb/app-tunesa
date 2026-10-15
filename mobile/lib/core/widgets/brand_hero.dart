import 'package:flutter/material.dart';

import '../theme/app_theme.dart';

/// Tarjeta destacada con el azul de la marca (degradado profundo) y un brillo
/// verde: saludo de Inicio y cabecera de Mi perfil.
class BrandHero extends StatelessWidget {
  const BrandHero({super.key, required this.child, this.padding = const EdgeInsets.all(20)});

  final Widget child;
  final EdgeInsetsGeometry padding;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final deep = Color.lerp(scheme.primary, Colors.black, 0.35)!;

    return Container(
      clipBehavior: Clip.antiAlias,
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(AppTheme.radius),
        gradient: LinearGradient(colors: [scheme.primary, deep], begin: Alignment.topLeft, end: Alignment.bottomRight),
        boxShadow: [BoxShadow(color: scheme.primary.withValues(alpha: 0.25), blurRadius: 18, offset: const Offset(0, 8))],
      ),
      child: Stack(children: [
        Positioned(
          right: -40,
          top: -50,
          child: Container(
            width: 160,
            height: 160,
            decoration: BoxDecoration(shape: BoxShape.circle, color: AppTheme.success.withValues(alpha: 0.22)),
          ),
        ),
        Positioned(
          right: 40,
          bottom: -60,
          child: Container(
            width: 110,
            height: 110,
            decoration: BoxDecoration(shape: BoxShape.circle, color: Colors.white.withValues(alpha: 0.07)),
          ),
        ),
        Padding(padding: padding, child: child),
      ]),
    );
  }
}
