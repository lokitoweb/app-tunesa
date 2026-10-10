import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:tunesa_mobile/app.dart';
import 'package:tunesa_mobile/core/config/app_config.dart';
import 'package:tunesa_mobile/core/config/app_config_controller.dart';
import 'package:tunesa_mobile/core/config/version_gate.dart';
import 'package:tunesa_mobile/core/location/location_service.dart';
import 'package:tunesa_mobile/core/providers.dart';
import 'package:tunesa_mobile/features/attendance/domain/models.dart';
import 'package:tunesa_mobile/features/attendance/presentation/clock_screen.dart';
import 'package:tunesa_mobile/features/attendance/presentation/offline_sync.dart';

/// Cola offline sin base de datos: solo el estado que dibuja la pantalla.
class _FakeSync extends OfflineSyncController {
  _FakeSync([this.initial = const OfflineSyncState()]);

  final OfflineSyncState initial;

  @override
  OfflineSyncState build() => initial;
}

/// Sin GPS en las pruebas: la pantalla muestra el error y sigue usable.
class _NoGps extends LocationService {
  @override
  Future<LocationFix> getVerifiedFix({int? maxAccuracyMeters, MockLocationPolicy mockPolicy = MockLocationPolicy.reject}) async =>
      throw const LocationException(LocationFailure.timeout, 'Sin GPS en pruebas');
}

String _cache({String slug = '', Map<String, dynamic> mobile = const {}, Map<String, dynamic> contact = const {}}) =>
    jsonEncode({
      'slug': slug,
      'config': AppConfig.fromJson({
        'data': {
          'tenant': slug.isEmpty ? null : {'slug': slug, 'company_name': 'Acme SAC'},
          'mobile': mobile,
          'contact': contact,
        },
      }).toJson(),
    });

void main() {
  setUpAll(() => initializeDateFormatting('es'));

  Future<void> pumpClock(
    WidgetTester tester, {
    Map<String, dynamic> mobile = const {},
    OfflineSyncState sync = const OfflineSyncState(),
    ClockAction action = ClockAction.clockIn,
  }) async {
    SharedPreferences.setMockInitialValues({AppConfigRepository.cacheKey: _cache(mobile: mobile)});
    final prefs = await SharedPreferences.getInstance();
    await tester.pumpWidget(ProviderScope(
      overrides: [
        sharedPreferencesProvider.overrideWithValue(prefs),
        locationServiceProvider.overrideWithValue(_NoGps()),
        connectivityProvider.overrideWith((ref) => Stream.value(true)),
        offlineSyncProvider.overrideWith(() => _FakeSync(sync)),
      ],
      child: MaterialApp(
        locale: const Locale('es'),
        supportedLocales: const [Locale('es')],
        localizationsDelegates: GlobalMaterialLocalizations.delegates,
        home: ClockScreen(action: action),
      ),
    ));
    await tester.pumpAndSettle();
  }

  testWidgets('con selfie_required=true la entrada pide selfie', (tester) async {
    await pumpClock(tester);
    expect(find.text('Selfie (cámara frontal)'), findsOneWidget);
    expect(find.text('Sin GPS en pruebas'), findsOneWidget);
  });

  testWidgets('con selfie_required=false se omite el paso de selfie', (tester) async {
    await pumpClock(tester, mobile: {'selfie_required': false});
    expect(find.text('Selfie (cámara frontal)'), findsNothing);
    expect(find.text('Ubicación GPS'), findsOneWidget);
  });

  testWidgets('muestra pendientes, última sincronización y no aceptadas', (tester) async {
    await pumpClock(
      tester,
      mobile: {'offline_mode_enabled': true},
      sync: OfflineSyncState(pending: 2, rejected: 1, lastSyncAt: DateTime(2026, 10, 9, 8, 30)),
    );
    expect(find.text('2 marcaciones pendientes de envío'), findsOneWidget);
    expect(find.textContaining('Última sincronización: 09/10/2026 08:30'), findsOneWidget);
    expect(find.text('1 marcación no aceptada'), findsOneWidget);
  });

  testWidgets('el descanso ofrece la selfie como opcional', (tester) async {
    await pumpClock(tester, action: ClockAction.breakStart);
    expect(find.text('Selfie (opcional)'), findsOneWidget);
    expect(find.text('Selfie (cámara frontal)'), findsNothing);
  });

  testWidgets('versión bajo el mínimo con force_update bloquea la app', (tester) async {
    SharedPreferences.setMockInitialValues({
      AppConfigRepository.cacheKey: _cache(
        mobile: {'min_supported_version': '2.0.0', 'force_update': true},
        contact: {'email': 'soporte@tunesa.app'},
      ),
    });
    final prefs = await SharedPreferences.getInstance();
    await tester.pumpWidget(ProviderScope(
      overrides: [
        sharedPreferencesProvider.overrideWithValue(prefs),
        appVersionProvider.overrideWithValue('1.0.0'),
      ],
      child: const TunesaApp(),
    ));
    await tester.pumpAndSettle();

    expect(find.text('Actualiza la app'), findsOneWidget);
    expect(find.text('soporte@tunesa.app'), findsOneWidget);
    expect(find.text('Código de empresa'), findsNothing);
  });

  testWidgets('versión bajo latest_version sin forzar: deja pasar', (tester) async {
    SharedPreferences.setMockInitialValues({
      AppConfigRepository.cacheKey: _cache(mobile: {'latest_version': '2.0.0'}),
    });
    final prefs = await SharedPreferences.getInstance();
    await tester.pumpWidget(ProviderScope(
      overrides: [
        sharedPreferencesProvider.overrideWithValue(prefs),
        appVersionProvider.overrideWithValue('1.0.0'),
      ],
      child: const TunesaApp(),
    ));
    await tester.pumpAndSettle();

    expect(find.text('Actualiza la app'), findsNothing);
    expect(find.text('Ingresar'), findsOneWidget);
  });
}
