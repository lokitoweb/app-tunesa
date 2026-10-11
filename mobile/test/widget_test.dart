import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:tunesa_mobile/app.dart';
import 'package:tunesa_mobile/core/providers.dart';

Future<void> _pumpApp(WidgetTester tester, Map<String, Object> prefsValues) async {
  SharedPreferences.setMockInitialValues(prefsValues);
  final prefs = await SharedPreferences.getInstance();
  await tester.pumpWidget(ProviderScope(
    overrides: [sharedPreferencesProvider.overrideWithValue(prefs)],
    child: const TunesaApp(),
  ));
  await tester.pumpAndSettle();
}

void main() {
  testWidgets('sin empresa conocida muestra el login directo, sin pedir código de empresa', (tester) async {
    await _pumpApp(tester, {});
    expect(find.text('Ingresar'), findsOneWidget);
    expect(find.text('Código de empresa'), findsNothing);
    expect(find.text('Entrar con otra empresa'), findsNothing);
  });

  testWidgets('con empresa y sin sesión muestra el login con su marca', (tester) async {
    await _pumpApp(tester, {
      'tenant_slug': 'empresa-demo',
      'tenant_branding': '{"slug":"empresa-demo","company_name":"Empresa Demo SAC","primary_color":"#0F766E"}',
    });
    // Solo el acceso: el logo ya identifica a la empresa y el servidor la reconoce por el correo
    expect(find.text('Iniciar sesión'), findsOneWidget);
    expect(find.text('Ingresar'), findsOneWidget);
    expect(find.text('Empresa Demo SAC'), findsNothing);
    expect(find.text('Entrar con otra empresa'), findsNothing);
  });

  testWidgets('el login valida el formulario antes de llamar a la API', (tester) async {
    await _pumpApp(tester, {
      'tenant_slug': 'empresa-demo',
      'tenant_branding': '{"slug":"empresa-demo","company_name":"Empresa Demo SAC"}',
    });
    await tester.tap(find.text('Ingresar'));
    await tester.pump();
    expect(find.text('Ingresa un correo válido'), findsOneWidget);
    expect(find.text('Mínimo 6 caracteres'), findsOneWidget);
  });

  testWidgets('el color de marca define el tema', (tester) async {
    await _pumpApp(tester, {
      'tenant_slug': 'empresa-demo',
      'tenant_branding': '{"slug":"empresa-demo","company_name":"X","primary_color":"#0F766E"}',
    });
    final context = tester.element(find.byType(Scaffold).first);
    expect(Theme.of(context).colorScheme.primary, isNot(equals(const Color(0xFF1E40AF))));
  });
}
