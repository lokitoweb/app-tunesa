import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:geolocator/geolocator.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:tunesa_mobile/app.dart';
import 'package:tunesa_mobile/core/config/app_config.dart';
import 'package:tunesa_mobile/core/config/app_config_controller.dart';
import 'package:tunesa_mobile/core/config/version_gate.dart';
import 'package:tunesa_mobile/core/location/location_service.dart';
import 'package:tunesa_mobile/core/providers.dart';
import 'package:tunesa_mobile/features/attendance/domain/models.dart';
import 'package:tunesa_mobile/features/attendance/presentation/attendance_providers.dart';
import 'package:tunesa_mobile/features/attendance/presentation/clock_screen.dart';
import 'package:tunesa_mobile/features/attendance/presentation/offline_sync.dart';
import 'package:tunesa_mobile/features/attendance/presentation/selfie_screen.dart';

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
  Future<LocationFix> getVerifiedFix({
    int? maxAccuracyMeters,
    MockLocationPolicy mockPolicy = MockLocationPolicy.reject,
  }) async => throw const LocationException(LocationFailure.timeout, 'Sin GPS en pruebas');
}

/// GPS fijo en (lat, lng) con buena precisión.
class _FixedGps extends LocationService {
  _FixedGps(this.lat, this.lng);

  final double lat;
  final double lng;

  @override
  Future<LocationFix> getVerifiedFix({
    int? maxAccuracyMeters,
    MockLocationPolicy mockPolicy = MockLocationPolicy.reject,
  }) async => LocationFix(
    Position(
      latitude: lat,
      longitude: lng,
      timestamp: DateTime(2026, 10, 11, 8),
      accuracy: 8,
      altitude: 0,
      altitudeAccuracy: 0,
      heading: 0,
      headingAccuracy: 0,
      speed: 0,
      speedAccuracy: 0,
    ),
    isMocked: false,
  );
}

/// Agencia de pruebas con radio de 100 m.
const _agency = BranchGeofence(id: 1, name: 'Agencia Centro', lat: -12.0464, lng: -77.0428, radius: 100);

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
    LocationService? gps,
    bool settle = true,
  }) async {
    SharedPreferences.setMockInitialValues({AppConfigRepository.cacheKey: _cache(mobile: mobile)});
    final prefs = await SharedPreferences.getInstance();
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          sharedPreferencesProvider.overrideWithValue(prefs),
          locationServiceProvider.overrideWithValue(gps ?? _NoGps()),
          myBranchesProvider.overrideWith((ref) async => const [_agency]),
          connectivityProvider.overrideWith((ref) => Stream.value(true)),
          offlineSyncProvider.overrideWith(() => _FakeSync(sync)),
        ],
        child: MaterialApp(
          locale: const Locale('es'),
          supportedLocales: const [Locale('es')],
          localizationsDelegates: GlobalMaterialLocalizations.delegates,
          home: ClockScreen(action: action),
        ),
      ),
    );
    if (settle) {
      await tester.pumpAndSettle();
    } else {
      // La vista previa de la cámara no termina de cargar en pruebas (sin plugin nativo)
      for (var i = 0; i < 10; i++) {
        await tester.pump(const Duration(milliseconds: 200));
      }
    }
  }

  testWidgets('en la agencia la entrada abre la cámara sin pasos previos', (tester) async {
    await pumpClock(tester, gps: _FixedGps(_agency.lat, _agency.lng), settle: false);
    expect(find.byType(SelfieScreen), findsOneWidget);
    expect(find.text('Marcar entrada'), findsWidgets);
  });

  testWidgets('fuera del perímetro no abre la cámara y explica el motivo', (tester) async {
    // ~1,1 km al norte de la agencia
    await pumpClock(tester, gps: _FixedGps(_agency.lat + 0.01, _agency.lng));
    expect(find.byType(SelfieScreen), findsNothing);
    expect(find.text('Estás fuera del perímetro'), findsOneWidget);
    expect(find.textContaining('Acércate a Agencia Centro para marcar'), findsOneWidget);
    expect(find.text('Volver a verificar'), findsOneWidget);
  });

  testWidgets('sin geocerca estricta, fuera del radio igual abre la cámara', (tester) async {
    await pumpClock(
      tester,
      mobile: {'geofence_strict_mode': false},
      gps: _FixedGps(_agency.lat + 0.01, _agency.lng),
      settle: false,
    );
    expect(find.byType(SelfieScreen), findsOneWidget);
  });

  testWidgets('sin GPS muestra el error y no abre la cámara', (tester) async {
    await pumpClock(tester);
    expect(find.byType(SelfieScreen), findsNothing);
    expect(find.text('No pudimos ubicarte'), findsOneWidget);
    expect(find.text('Sin GPS en pruebas'), findsOneWidget);
  });

  testWidgets('con selfie_required=false no hay cámara: un solo botón de confirmar', (tester) async {
    await pumpClock(tester, mobile: {'selfie_required': false}, gps: _FixedGps(_agency.lat, _agency.lng));
    expect(find.byType(SelfieScreen), findsNothing);
    expect(find.text('Confirmar marcar entrada'), findsOneWidget);
    expect(find.text('Estás en Agencia Centro'), findsOneWidget);
  });

  testWidgets('en el descanso la selfie es opcional: al cerrar la cámara se puede marcar sin ella', (tester) async {
    await pumpClock(tester, action: ClockAction.breakStart, gps: _FixedGps(_agency.lat, _agency.lng), settle: false);
    expect(find.byType(SelfieScreen), findsOneWidget);
    await tester.tap(find.byTooltip('Cancelar'));
    for (var i = 0; i < 5; i++) {
      await tester.pump(const Duration(milliseconds: 200));
    }
    expect(find.byType(SelfieScreen), findsNothing);
    expect(find.text('Marcar sin selfie'), findsOneWidget);
    expect(find.text('Tomar selfie'), findsOneWidget);
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

  testWidgets('versión bajo el mínimo con force_update bloquea la app', (tester) async {
    SharedPreferences.setMockInitialValues({
      AppConfigRepository.cacheKey: _cache(
        mobile: {'min_supported_version': '2.0.0', 'force_update': true},
        contact: {'email': 'soporte@tunesa.app'},
      ),
    });
    final prefs = await SharedPreferences.getInstance();
    await tester.pumpWidget(
      ProviderScope(
        overrides: [sharedPreferencesProvider.overrideWithValue(prefs), appVersionProvider.overrideWithValue('1.0.0')],
        child: const TunesaApp(),
      ),
    );
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
    await tester.pumpWidget(
      ProviderScope(
        overrides: [sharedPreferencesProvider.overrideWithValue(prefs), appVersionProvider.overrideWithValue('1.0.0')],
        child: const TunesaApp(),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('Actualiza la app'), findsNothing);
    expect(find.text('Ingresar'), findsOneWidget);
  });
}
