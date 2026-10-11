import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../config/app_config.dart';

/// Sistema visual de la app: Material 3 generado desde los colores de marca
/// de la empresa ([BrandingConfig] de `/v1/app/config`), con variante clara y
/// oscura. Barras blancas sobre fondo celeste; si la empresa eligió un
/// `navbar_color`, AppBar y barra inferior lo toman con texto legible.
class AppTheme {
  const AppTheme._();

  /// Azul y celeste del logo de Tunesa (cuando la empresa no definió colores).
  static const Color defaultPrimary = Color(0xFF0050A0);
  static const Color defaultSecondary = Color(0xFF00A0D0);

  /// Esquinas de tarjetas, campos y botones.
  static const double radius = 10;

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
    final isDark = brightness == Brightness.dark;
    final brand = primary ?? defaultPrimary;
    var scheme = ColorScheme.fromSeed(
      seedColor: brand,
      secondary: secondary ?? defaultSecondary,
      tertiary: accent,
      brightness: brightness,
    );
    // En claro el primario es el color exacto de la marca (el generado lo apaga)
    if (!isDark) scheme = scheme.copyWith(primary: brand, onPrimary: foregroundOn(brand));

    final rounded = BorderRadius.circular(radius);
    final shape = RoundedRectangleBorder(borderRadius: rounded);
    final barColor = navbar ?? scheme.surface;
    final barForeground = navbar == null ? scheme.onSurface : foregroundOn(navbar);
    final barAccent = navbar == null ? scheme.primary : barForeground;
    final fieldBorder = OutlineInputBorder(borderRadius: rounded, borderSide: BorderSide.none);
    final darkBar = ThemeData.estimateBrightnessForColor(barColor) == Brightness.dark;

    return ThemeData(
      colorScheme: scheme,
      useMaterial3: true,
      brightness: brightness,
      // Blanco con un toque celeste: las tarjetas blancas se despegan del fondo
      scaffoldBackgroundColor: isDark ? scheme.surface : Color.lerp(Colors.white, brand, 0.045),
      visualDensity: VisualDensity.standard,
      appBarTheme: AppBarTheme(
        backgroundColor: barColor,
        foregroundColor: barForeground,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        scrolledUnderElevation: 1,
        shadowColor: scheme.shadow.withValues(alpha: 0.25),
        centerTitle: false,
        systemOverlayStyle: darkBar ? SystemUiOverlayStyle.light : SystemUiOverlayStyle.dark,
        titleTextStyle: TextStyle(fontSize: 20, fontWeight: FontWeight.w700, color: barForeground),
      ),
      cardTheme: CardThemeData(
        margin: EdgeInsets.zero,
        elevation: isDark ? 0 : 1.5,
        shadowColor: brand.withValues(alpha: 0.18),
        surfaceTintColor: Colors.transparent,
        color: isDark ? scheme.surfaceContainerHigh : Colors.white,
        shape: shape.copyWith(side: BorderSide(color: scheme.outlineVariant.withValues(alpha: isDark ? 0.5 : 0.35))),
        clipBehavior: Clip.antiAlias,
      ),
      listTileTheme: ListTileThemeData(shape: shape, iconColor: scheme.primary),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: isDark ? scheme.surfaceContainerHighest : Color.lerp(Colors.white, brand, 0.07),
        border: fieldBorder,
        enabledBorder: fieldBorder,
        // Sin borde al enfocar: el campo se distingue por su relleno
        focusedBorder: fieldBorder,
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 16),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          // Ancho libre: en diálogos no ocupan todo el renglón; en formularios los estira la columna
          minimumSize: const Size(64, 50),
          shape: shape,
          textStyle: const TextStyle(fontSize: 16, fontWeight: FontWeight.w600),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          minimumSize: const Size(64, 46),
          shape: shape,
          side: BorderSide(color: scheme.outlineVariant),
          textStyle: const TextStyle(fontWeight: FontWeight.w600),
        ),
      ),
      textButtonTheme: TextButtonThemeData(style: TextButton.styleFrom(shape: shape)),
      chipTheme: ChipThemeData(shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8))),
      dialogTheme: DialogThemeData(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(radius + 2)),
        backgroundColor: isDark ? scheme.surfaceContainerHigh : Colors.white,
        surfaceTintColor: Colors.transparent,
      ),
      navigationBarTheme: NavigationBarThemeData(
        height: 66,
        backgroundColor: barColor,
        surfaceTintColor: Colors.transparent,
        elevation: 8,
        shadowColor: scheme.shadow,
        indicatorColor: barAccent.withValues(alpha: 0.14),
        indicatorShape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(radius)),
        iconTheme: WidgetStateProperty.resolveWith((states) => IconThemeData(
            color: states.contains(WidgetState.selected) ? barAccent : barForeground.withValues(alpha: 0.6))),
        labelTextStyle: WidgetStateProperty.resolveWith((states) => TextStyle(
              fontSize: 12,
              fontWeight: FontWeight.w600,
              color: states.contains(WidgetState.selected) ? barAccent : barForeground.withValues(alpha: 0.6),
            )),
      ),
      snackBarTheme: SnackBarThemeData(behavior: SnackBarBehavior.floating, shape: shape),
      bottomSheetTheme: BottomSheetThemeData(
        showDragHandle: true,
        backgroundColor: isDark ? scheme.surfaceContainerHigh : Colors.white,
        surfaceTintColor: Colors.transparent,
        shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(radius + 4))),
      ),
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
