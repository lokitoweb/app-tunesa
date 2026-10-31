import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:tunesa_mobile/core/config/app_config.dart';
import 'package:tunesa_mobile/core/config/app_config_controller.dart';
import 'package:tunesa_mobile/core/config/version_gate.dart';
import 'package:tunesa_mobile/features/app_update/presentation/update_screen.dart';

class _FakeConfig extends AppConfigController {
  @override
  AppConfigState build() => const AppConfigState(
        config: AppConfig(mobile: MobileConfig(latestVersion: '1.6.60', updateUrl: 'https://example.test/app')),
        ready: true,
      );
}

Future<void> _pump(WidgetTester tester, String installed) => tester.pumpWidget(ProviderScope(
      overrides: [
        appConfigProvider.overrideWith(_FakeConfig.new),
        appVersionProvider.overrideWithValue(installed),
      ],
      child: const MaterialApp(home: Scaffold(body: Column(children: [UpdateBanner()]))),
    ));

void main() {
  testWidgets('versión vieja: tarjeta con título, versión y botón; se cierra con la X', (tester) async {
    await _pump(tester, '1.0.0');

    expect(find.byKey(const Key('update-banner')), findsOneWidget);
    expect(find.text('Nueva versión disponible'), findsOneWidget);
    expect(find.text('Versión 1.6.60 con mejoras y correcciones.'), findsOneWidget);
    expect(find.widgetWithText(FilledButton, 'Actualizar'), findsOneWidget);

    await tester.tap(find.byTooltip('Ahora no'));
    await tester.pump();
    expect(find.byKey(const Key('update-banner')), findsNothing);
  });

  testWidgets('la versión instalada ya es la más reciente (o más nueva): sin aviso', (tester) async {
    await _pump(tester, '1.6.60');
    expect(find.byKey(const Key('update-banner')), findsNothing);

    await _pump(tester, '1.6.61');
    expect(find.byKey(const Key('update-banner')), findsNothing);
  });
}
