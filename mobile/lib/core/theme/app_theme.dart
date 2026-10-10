import 'package:flutter/material.dart';

import '../config/app_config.dart';

/// Sistema visual de la app: Material 3 generado desde los colores de marca
/// de la empresa ([BrandingConfig] de `/v1/app/config`), con variante clara y
/// oscura. AppBar y barra inferior toman `navbar_color` con texto legible.
class AppTheme {
  const AppTheme._();

  static const Color defaultPrimary = Color(0xFF1E40AF);
  static const Color defaultSecondary = Color(0xFFF59E0B);

  static const double radius = 16;

  static ThemeData light({Color? primary, Color? secondary, Color? accent, Color? navbar}) =>
      _build(Brightness.light, primary: primary, secondary: secondary, accent: accent, navbar: navbar);

  static ThemeData dark({Color? primary, Color? secondary, Color? accent, Color? navbar}) =>
      _build(Brightness.dark, primary: primary, secondary: secondary, accent: accent, navbar: navbar);

  /// Tema claro u oscuro desde la marca de [AppConfig] (colores null → por defecto).
  static ThemeData fromBranding(BrandingConfig branding, Brightness brightness) => _build(
        brightness,
        primary: parseHex(branding.primaryColor),
        secondary: parseHex(branding.secondaryColor),
        accent: parseHex(branding.accentColor),
        navbar: parseHex(branding.navbarColor),
      );

  /// Texto/íconos legibles sobre [background] (blanco o casi negro según su luminancia).
  static Color foregroundOn(Color background) =>
      ThemeData.estimateBrightnessForColor(background) == Brightness.dark ? Colors.white : const Color(0xFF111827);

  static ThemeData _build(Brightness brightness, {Color? primary, Color? secondary, Color? accent, Color? navbar}) {
    final scheme = ColorScheme.fromSeed(
      seedColor: primary ?? defaultPrimary,
      secondary: secondary ?? defaultSecondary,
      tertiary: accent,
      brightness: brightness,
    );
    final isDark = brightness == Brightness.dark;
    final shape = RoundedRectangleBorder(borderRadius: BorderRadius.circular(radius));
    final barForeground = navbar == null ? null : foregroundOn(navbar);

    return ThemeData(
      colorScheme: scheme,
      useMaterial3: true,
      brightness: brightness,
      scaffoldBackgroundColor: isDark ? scheme.surface : scheme.surfaceContainerLowest,
      visualDensity: VisualDensity.standard,
      appBarTheme: AppBarTheme(
        backgroundColor: navbar ?? Colors.transparent,
        foregroundColor: barForeground ?? scheme.onSurface,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        scrolledUnderElevation: 0,
        centerTitle: false,
        titleTextStyle: TextStyle(fontSize: 20, fontWeight: FontWeight.w700, color: barForeground ?? scheme.onSurface),
      ),
      cardTheme: CardThemeData(
        margin: EdgeInsets.zero,
        elevation: 0,
        color: isDark ? scheme.surfaceContainerHigh : scheme.surface,
        shape: shape.copyWith(side: BorderSide(color: scheme.outlineVariant.withValues(alpha: 0.5))),
        clipBehavior: Clip.antiAlias,
      ),
      listTileTheme: ListTileThemeData(shape: shape, iconColor: scheme.primary),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: isDark ? scheme.surfaceContainerHighest : scheme.surfaceContainerLow,
        border: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: BorderSide.none),
        enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: BorderSide.none),
        // Sin borde al enfocar: el campo se distingue por su relleno
        focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: BorderSide.none),
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 16),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          minimumSize: const Size.fromHeight(52),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
          textStyle: const TextStyle(fontSize: 16, fontWeight: FontWeight.w600),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
          textStyle: const TextStyle(fontWeight: FontWeight.w600),
        ),
      ),
      chipTheme: ChipThemeData(shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10))),
      navigationBarTheme: NavigationBarThemeData(
        height: 68,
        backgroundColor: navbar ?? (isDark ? scheme.surfaceContainer : scheme.surface),
        indicatorColor: barForeground?.withValues(alpha: 0.18) ?? scheme.primaryContainer,
        iconTheme: barForeground == null
            ? null
            : WidgetStateProperty.resolveWith((states) => IconThemeData(
                color: states.contains(WidgetState.selected) ? barForeground : barForeground.withValues(alpha: 0.75))),
        labelTextStyle: WidgetStatePropertyAll(
          TextStyle(
            fontSize: 12,
            fontWeight: FontWeight.w600,
            color: barForeground?.withValues(alpha: 0.9) ?? scheme.onSurfaceVariant,
          ),
        ),
      ),
      snackBarTheme: SnackBarThemeData(
        behavior: SnackBarBehavior.floating,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      ),
      bottomSheetTheme: const BottomSheetThemeData(showDragHandle: true),
      dividerTheme: DividerThemeData(color: scheme.outlineVariant.withValues(alpha: 0.5), space: 1),
    );
  }

  /// "#1E40AF" → Color. Devuelve null si el valor no es un hex válido.
  static Color? parseHex(String? hex) {
    if (hex == null) return null;
    final clean = hex.replaceFirst('#', '');
    if (clean.length != 6) return null;
    final value = int.tryParse(clean, radix: 16);
    return value == null ? null : Color(0xFF000000 | value);
  }
}
