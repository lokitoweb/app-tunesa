import 'package:flutter/material.dart';

/// Tema Material 3 generado a partir de los colores de marca de la empresa.
class AppTheme {
  const AppTheme._();

  static const Color defaultPrimary = Color(0xFF1E40AF);
  static const Color defaultSecondary = Color(0xFFF59E0B);

  static ThemeData light({Color? primary, Color? secondary}) {
    final scheme = ColorScheme.fromSeed(
      seedColor: primary ?? defaultPrimary,
      secondary: secondary ?? defaultSecondary,
    );

    return ThemeData(
      colorScheme: scheme,
      useMaterial3: true,
      appBarTheme: AppBarTheme(
        backgroundColor: scheme.primary,
        foregroundColor: scheme.onPrimary,
        centerTitle: false,
      ),
      inputDecorationTheme: const InputDecorationTheme(border: OutlineInputBorder()),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          minimumSize: const Size.fromHeight(52),
          textStyle: const TextStyle(fontSize: 16, fontWeight: FontWeight.w600),
        ),
      ),
      cardTheme: const CardThemeData(margin: EdgeInsets.zero),
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
