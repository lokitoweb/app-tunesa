import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:tunesa_mobile/app.dart';
import 'package:tunesa_mobile/core/providers.dart';
import 'package:tunesa_mobile/core/widgets/brand_logo.dart';
import 'package:tunesa_mobile/features/auth/data/auth_repository.dart';
import 'package:tunesa_mobile/features/auth/presentation/auth_controller.dart';

/// Registra el correo con que se pidió ayuda (sin red)
class _FakeAuth extends AuthRepository {
  _FakeAuth(super.api, super.storage);

  String? askedFor;

  @override
  Future<String> requestPasswordHelp(String email) async {
    askedFor = email;
    return 'RR. HH. recibió tu pedido.';
  }
}

Future<void> _pumpApp(WidgetTester tester, Map<String, Object> prefsValues, {List overrides = const []}) async {
  SharedPreferences.setMockInitialValues(prefsValues);
  final prefs = await SharedPreferences.getInstance();
  await tester.pumpWidget(ProviderScope(
    overrides: [sharedPreferencesProvider.overrideWithValue(prefs), ...overrides],
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

  testWidgets('olvidé mi contraseña avisa a RR. HH. con el correo escrito', (tester) async {
    _FakeAuth? fake;
    await _pumpApp(tester, {
      'tenant_slug': 'empresa-demo',
      'tenant_branding': '{"slug":"empresa-demo","company_name":"Empresa Demo SAC"}',
    }, overrides: [
      authRepositoryProvider.overrideWith((ref) => fake = _FakeAuth(ref.watch(apiClientProvider), ref.watch(sessionStorageProvider))),
    ]);
    await tester.enterText(find.widgetWithText(TextFormField, 'Correo'), 'ana@empresa.pe');
    await tester.tap(find.text('¿Olvidaste tu contraseña?'));
    await tester.pumpAndSettle();
    expect(find.text('ana@empresa.pe'), findsWidgets);
    await tester.tap(find.byKey(const Key('password-help-send')));
    await tester.pumpAndSettle();
    expect(fake?.askedFor, 'ana@empresa.pe');
    expect(find.byKey(const Key('password-help-done')), findsOneWidget);
  });

  testWidgets('el login muestra fijo el logo oficial de Grupo Negreiros sin deformarlo', (tester) async {
    tester.view.physicalSize = const Size(320 * 3, 640 * 3); // teléfono angosto
    tester.view.devicePixelRatio = 3;
    addTearDown(tester.view.reset);
    await _pumpApp(tester, {});
    expect(find.byType(BrandLogo), findsNothing); // el de la empresa solo dentro de la app
    final logo = tester.widget<Image>(find.descendant(of: find.byType(OfficialLogo), matching: find.byType(Image)));
    expect((logo.image as AssetImage).assetName, OfficialLogo.asset);
    expect(logo.fit, BoxFit.contain);
    expect(tester.takeException(), isNull); // sin desbordes
  });
}
