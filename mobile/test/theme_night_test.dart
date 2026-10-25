import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:tunesa_mobile/core/theme/app_theme.dart';

void main() {
  test('modo noche: fondo carbón, tarjetas elevadas y texto secundario legible', () {
    final t = AppTheme.dark();
    expect(t.scaffoldBackgroundColor, AppTheme.nightBackground);
    expect(t.cardTheme.color, AppTheme.nightCard);
    expect(t.colorScheme.onSurfaceVariant, AppTheme.nightTextMuted);
    // El texto secundario cumple AA (4.5:1) sobre las tarjetas
    final l1 = AppTheme.nightTextMuted.computeLuminance(), l2 = AppTheme.nightCard.computeLuminance();
    expect((l1 + 0.05) / (l2 + 0.05), greaterThan(4.5));
  });

  test('el modo claro no cambia', () {
    final t = AppTheme.light();
    expect(t.cardTheme.color, Colors.white);
    expect(t.colorScheme.primary, AppTheme.defaultPrimary);
  });
}
