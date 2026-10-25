import 'package:flutter/material.dart';

import '../theme/app_theme.dart';

/// Tarjeta destacada: saludo de Inicio y cabecera de Mi perfil.
/// Claro: azul de la marca en degradado profundo con un brillo verde.
/// Oscuro: superficie carbón con borde fino y un velo muy tenue de la marca,
/// para no chocar con el fondo de la app.
class BrandHero extends StatelessWidget {
  const BrandHero({super.key, required this.child, this.padding = const EdgeInsets.all(20)});

  final Widget child;
  final EdgeInsetsGeometry padding;

  /// Aro del avatar dentro del hero: blanco en claro, color de marca en oscuro.
  static Color ringColor(BuildContext context) {
    final theme = Theme.of(context);
    return theme.brightness == Brightness.dark ? theme.colorScheme.primary.withValues(alpha: 0.7) : Colors.white;
  }

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final dark = Theme.of(context).brightness == Brightness.dark;
    final deep = Color.lerp(scheme.primary, Colors.black, 0.35)!;

    return Container(
      clipBehavior: Clip.antiAlias,
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(AppTheme.radius),
        gradient: dark
            ? LinearGradient(
                colors: [Color.lerp(AppTheme.nightRaised, scheme.primary, 0.12)!, AppTheme.nightRaised],
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
              )
            : LinearGradient(colors: [scheme.primary, deep], begin: Alignment.topLeft, end: Alignment.bottomRight),
        border: dark ? Border.all(color: AppTheme.nightBorder) : null,
        boxShadow: dark ? null : [BoxShadow(color: scheme.primary.withValues(alpha: 0.25), blurRadius: 18, offset: const Offset(0, 8))],
      ),
      child: Stack(children: [
        Positioned(
          right: -40,
          top: -50,
          child: Container(
            width: 160,
            height: 160,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              color: dark ? scheme.primary.withValues(alpha: 0.08) : AppTheme.success.withValues(alpha: 0.22),
            ),
          ),
        ),
        Positioned(
          right: 40,
          bottom: -60,
          child: Container(
            width: 110,
            height: 110,
            decoration: BoxDecoration(shape: BoxShape.circle, color: Colors.white.withValues(alpha: dark ? 0.03 : 0.07)),
          ),
        ),
        Padding(padding: padding, child: child),
      ]),
    );
  }
}
