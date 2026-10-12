import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:tunesa_mobile/core/providers.dart';
import 'package:tunesa_mobile/core/theme/app_theme.dart';
import 'package:tunesa_mobile/core/widgets/page_padding.dart';
import 'package:tunesa_mobile/features/requests/presentation/new_request_screen.dart';

void main() {
  setUpAll(() => initializeDateFormatting('es'));

  testWidgets('el margen inferior suma la barra de navegación del teléfono', (tester) async {
    late EdgeInsets padding;
    await tester.pumpWidget(MediaQuery(
      data: const MediaQueryData(padding: EdgeInsets.only(bottom: 48)),
      child: Builder(builder: (context) {
        padding = pagePadding(context);
        return const SizedBox();
      }),
    ));
    expect(padding, const EdgeInsets.fromLTRB(16, 16, 16, 80));
  });

  testWidgets('Nueva solicitud: el tipo se elige con tarjetas dentro de los márgenes', (tester) async {
    SharedPreferences.setMockInitialValues({});
    final prefs = await SharedPreferences.getInstance();
    tester.view.physicalSize = const Size(360, 780);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(ProviderScope(
      overrides: [sharedPreferencesProvider.overrideWithValue(prefs)],
      child: MaterialApp(theme: AppTheme.light(), home: const NewRequestScreen()),
    ));
    await tester.pumpAndSettle();

    for (final key in ['leave-type-vacation', 'leave-type-permission', 'leave-type-medical_leave']) {
      final rect = tester.getRect(find.byKey(Key(key)));
      expect(rect.left, greaterThanOrEqualTo(16));
      expect(rect.right, lessThanOrEqualTo(344));
    }
    expect(find.text('Sustento (opcional)'), findsOneWidget);

    await tester.tap(find.byKey(const Key('leave-type-medical_leave')));
    await tester.pumpAndSettle();
    expect(find.text('Certificado médico (obligatorio)'), findsOneWidget);

    await tester.tap(find.byKey(const Key('leave-type-permission')));
    await tester.pumpAndSettle();
    expect(find.text('Permiso por horas'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });
}
