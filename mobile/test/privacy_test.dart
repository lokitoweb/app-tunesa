import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:tunesa_mobile/core/config/app_config.dart';
import 'package:tunesa_mobile/core/network/api_client.dart';
import 'package:tunesa_mobile/core/providers.dart';
import 'package:tunesa_mobile/core/storage/session_storage.dart';
import 'package:tunesa_mobile/core/theme/app_theme.dart';
import 'package:tunesa_mobile/features/account/data/account_repository.dart';
import 'package:tunesa_mobile/features/account/domain/models.dart';
import 'package:tunesa_mobile/features/account/presentation/account_providers.dart';
import 'package:tunesa_mobile/features/account/presentation/privacy_screen.dart';

class _FakeAccount extends AccountRepository {
  _FakeAccount(super.api);

  final reasons = <String?>[];

  @override
  Future<String> requestDeletion({String? reason}) async {
    reasons.add(reason);
    return 'Recibimos tu pedido.';
  }

  @override
  Future<PrivacyPolicy> privacyPolicy() async => PrivacyPolicy.fromJson({
        'app_name': 'Assist Conmigo',
        'updated_on': '2026-11-09',
        'blocks': [
          {'type': 'paragraph', 'text': 'Assist Conmigo protege tus datos.'},
          {'type': 'heading', 'text': '1. Datos que usamos'},
          {'type': 'bullet', 'text': '**Ubicación:** solo mientras marcas.'},
        ],
        'contact': {'email': 'privacidad@demo.pe'},
      });
}

void main() {
  test('legal: lee los enlaces y descarta los que no son http(s)', () {
    final config = AppConfig.fromJson({
      'data': {
        'legal': {'privacy_policy_url': 'https://api.tunesa.app/privacidad', 'account_deletion_url': 'javascript:alert(1)'},
      },
    });
    expect(config.legal.privacyPolicyUrl, 'https://api.tunesa.app/privacidad');
    expect(config.legal.accountDeletionUrl, isNull);
    expect(AppConfig.fromJson(config.toJson()).legal.privacyPolicyUrl, 'https://api.tunesa.app/privacidad');
    expect(AppConfig.fromJson(const {}).legal.privacyPolicyUrl, isNull);
  });

  testWidgets('Privacidad y datos: pedir la eliminación de la cuenta con motivo', (tester) async {
    SharedPreferences.setMockInitialValues({});
    FlutterSecureStorage.setMockInitialValues({});
    final prefs = await SharedPreferences.getInstance();
    final fake = _FakeAccount(ApiClient(SessionStorage(prefs)));

    await tester.pumpWidget(ProviderScope(
      overrides: [
        sharedPreferencesProvider.overrideWithValue(prefs),
        accountRepositoryProvider.overrideWithValue(fake),
      ],
      child: MaterialApp(theme: AppTheme.light(), home: const PrivacyScreen()),
    ));
    await tester.pumpAndSettle();

    expect(find.textContaining('Nunca en segundo plano'), findsOneWidget);
    expect(find.byKey(const Key('privacy-policy')), findsOneWidget);

    // Cancelar no envía nada
    await tester.tap(find.byKey(const Key('privacy-delete-account')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Cancelar'));
    await tester.pumpAndSettle();
    expect(fake.reasons, isEmpty);

    await tester.tap(find.byKey(const Key('privacy-delete-account')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField), 'Renuncié');
    await tester.tap(find.byKey(const Key('confirm-delete-account')));
    await tester.pumpAndSettle();

    expect(fake.reasons, ['Renuncié']);
    expect(find.text('Recibimos tu pedido.'), findsOneWidget);

    // La política se lee dentro de la app (hoja modal), con el texto que administra la empresa
    await tester.tap(find.byKey(const Key('privacy-policy')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('privacy-policy-sheet')), findsOneWidget);
    expect(find.text('1. Datos que usamos'), findsOneWidget);
    final sheet = find.byKey(const Key('privacy-policy-sheet'));
    expect(find.descendant(of: sheet, matching: find.textContaining('solo mientras marcas')), findsOneWidget);
    expect(find.textContaining('**'), findsNothing); // la negrita se dibuja, no se ven los asteriscos
    expect(find.text('privacidad@demo.pe'), findsOneWidget);
  });
}
