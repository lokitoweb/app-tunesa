import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:tunesa_mobile/core/config/app_config.dart';
import 'package:tunesa_mobile/core/config/app_config_controller.dart';
import 'package:tunesa_mobile/core/config/version_gate.dart';
import 'package:tunesa_mobile/core/network/api_client.dart';
import 'package:tunesa_mobile/core/network/api_exception.dart';
import 'package:tunesa_mobile/core/network/server_url.dart';
import 'package:tunesa_mobile/core/providers.dart';
import 'package:tunesa_mobile/core/storage/session_storage.dart';
import 'package:tunesa_mobile/core/theme/app_theme.dart';
import 'package:tunesa_mobile/core/utils/formatters.dart';
import 'package:tunesa_mobile/core/utils/semver.dart';

const _full = {
  'success': true,
  'data': {
    'tenant': {'slug': 'acme', 'company_name': 'ACME SAC', 'trade_name': 'Acme'},
    'branding': {
      'logo_url': 'https://cdn.x/logo.png',
      'logo_dark_url': 'https://cdn.x/logo-dark.png',
      'favicon_url': null,
      'splash_url': null,
      'primary_color': '#0F766E',
      'secondary_color': '#F59E0B',
      'accent_color': '#7C3AED',
      'navbar_color': '#111827',
    },
    'mobile': {
      'api_base_url': 'https://api.acme.pe',
      'min_supported_version': '1.2.0',
      'latest_version': '1.4.0',
      'force_update': true,
      'selfie_required': false,
      'face_detection_required': true,
      'offline_mode_enabled': true,
      'offline_max_hours': 12,
      'max_gps_accuracy_meters': 35,
      'geofence_strict_mode': false,
    },
    'rules': {'grace_minutes': 10, 'lunch_minutes': 60, 'lunch_paid': false, 'default_geofence_radius': 150},
    'contact': {'email': 'rrhh@acme.pe', 'phone': '+51 1 555', 'website': null, 'address': 'Lima'},
    'modules': {'payslips': true, 'documents': false, 'payment_info': true, 'uniforms': false},
  },
  'meta': {'config_version': '2026-10-09T10:00:00-05:00'},
};

/// Adaptador HTTP falso: registra las solicitudes y responde con [handler].
class _FakeAdapter implements HttpClientAdapter {
  _FakeAdapter(this.handler);

  final ResponseBody Function(RequestOptions options) handler;
  final requests = <RequestOptions>[];

  @override
  Future<ResponseBody> fetch(RequestOptions options, Stream<Uint8List>? requestStream, Future<void>? cancelFuture) async {
    requests.add(options);
    return handler(options);
  }

  @override
  void close({bool force = false}) {}
}

class _FakeConfigRepository extends AppConfigRepository {
  _FakeConfigRepository(SharedPreferences prefs, this.result) : super(ApiClient(SessionStorage(prefs)), prefs);

  final AppConfig result;
  int fetches = 0;

  @override
  Future<AppConfig> fetch(String? slug) async {
    fetches++;
    return result;
  }
}

ResponseBody _json(int status, Object body) => ResponseBody.fromString(
      jsonEncode(body),
      status,
      headers: {
        Headers.contentTypeHeader: ['application/json'],
      },
    );

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  FlutterSecureStorage.setMockInitialValues({});

  group('AppConfig.fromJson', () {
    test('lee el contrato completo de /v1/app/config', () {
      final c = AppConfig.fromJson(Map<String, dynamic>.from(_full));
      expect(c.tenant!.slug, 'acme');
      expect(c.displayName, 'Acme');
      expect(c.branding.logoFor(dark: false), 'https://cdn.x/logo.png');
      expect(c.branding.logoFor(dark: true), 'https://cdn.x/logo-dark.png');
      expect(c.branding.navbarColor, '#111827');
      expect(c.mobile.apiBaseUrl, 'https://api.acme.pe');
      expect(c.mobile.forceUpdate, isTrue);
      expect(c.mobile.selfieRequired, isFalse);
      expect(c.mobile.faceDetectionRequired, isTrue);
      expect(c.mobile.offlineModeEnabled, isTrue);
      expect(c.mobile.offlineMaxHours, 12);
      expect(c.mobile.maxGpsAccuracyMeters, 35);
      expect(c.rules.graceMinutes, 10);
      expect(c.rules.lunchPaid, isFalse);
      expect(c.contact.email, 'rrhh@acme.pe');
      expect(c.contact.website, isNull);
      expect(c.modules.documents, isFalse);
      expect(c.configVersion, '2026-10-09T10:00:00-05:00');
    });

    test('sin empresa (tenant null) y sin campos usa los valores por defecto', () {
      final c = AppConfig.fromJson({
        'success': true,
        'data': {'tenant': null},
      });
      expect(c.tenant, isNull);
      expect(c.displayName, 'Tunesa');
      expect(c.branding.primaryColor, isNull);
      expect(c.branding.logoFor(dark: true), isNull);
      expect(c.mobile.selfieRequired, isTrue);
      expect(c.mobile.faceDetectionRequired, isFalse);
      expect(c.mobile.offlineModeEnabled, isFalse);
      expect(c.mobile.offlineMaxHours, 24);
      expect(c.mobile.maxGpsAccuracyMeters, isNull);
      expect(c.mobile.forceUpdate, isFalse);
      expect(c.mobile.minSupportedVersion, isNull);
      expect(c.modules.payslips, isTrue);
      expect(c.contact.isEmpty, isTrue);
    });

    test('colores null o vacíos y tipos laxos no rompen el parseo', () {
      final c = AppConfig.fromJson({
        'data': {
          'tenant': {'slug': 'x', 'company_name': 'X SA', 'trade_name': ''},
          'branding': {'primary_color': null, 'secondary_color': '', 'logo_url': null, 'logo_dark_url': 'https://d.png'},
          'mobile': {
            'force_update': 1,
            'selfie_required': '0',
            'offline_max_hours': '48',
            'max_gps_accuracy_meters': 0,
          },
          'branding_extra': 'ignorado',
          'modules': 'no es un mapa',
        },
      });
      expect(c.displayName, 'X SA');
      expect(c.branding.primaryColor, isNull);
      expect(c.branding.secondaryColor, isNull);
      // Sin logo claro se usa el oscuro como respaldo
      expect(c.branding.logoFor(dark: false), 'https://d.png');
      expect(c.mobile.forceUpdate, isTrue);
      expect(c.mobile.selfieRequired, isFalse);
      expect(c.mobile.offlineMaxHours, 48);
      expect(c.mobile.maxGpsAccuracyMeters, isNull);
      expect(c.modules.uniforms, isTrue);

      final theme = AppTheme.fromBranding(c.branding, Brightness.light);
      expect(theme.colorScheme.primary, isNotNull);
    });

    test('toJson → fromJson conserva todo (caché)', () {
      final c = AppConfig.fromJson(Map<String, dynamic>.from(_full));
      final again = AppConfig.fromJson(jsonDecode(jsonEncode(c.toJson())) as Map<String, dynamic>);
      expect(jsonEncode(again.toJson()), jsonEncode(c.toJson()));
    });

    test('marca guardada por versiones anteriores', () {
      final c = AppConfig.fromLegacyBranding({'slug': 'demo', 'company_name': 'Demo SAC', 'primary_color': '#0F766E'});
      expect(c.tenant!.slug, 'demo');
      expect(c.displayName, 'Demo SAC');
      expect(c.branding.primaryColor, '#0F766E');
    });
  });

  group('AppConfigRepository', () {
    late SharedPreferences prefs;

    setUp(() async {
      SharedPreferences.setMockInitialValues({});
      prefs = await SharedPreferences.getInstance();
    });

    ApiClient client(_FakeAdapter adapter) {
      final dio = Dio(BaseOptions(baseUrl: 'https://api.test/api'))..httpClientAdapter = adapter;
      return ApiClient(SessionStorage(prefs), dio: dio);
    }

    test('la caché es por empresa y tolera datos corruptos', () async {
      final repo = AppConfigRepository(client(_FakeAdapter((_) => _json(200, _full))), prefs);
      final config = AppConfig.fromJson(Map<String, dynamic>.from(_full));
      await repo.save('acme', config);

      expect(repo.cached('acme')!.displayName, 'Acme');
      expect(repo.cached('otra'), isNull);
      expect(repo.cached(null), isNull);

      await prefs.setString(AppConfigRepository.cacheKey, '{no es json');
      expect(repo.cached('acme'), isNull);
    });

    test('envía tenant_slug, sin token ni X-Tenant-Slug', () async {
      final adapter = _FakeAdapter((_) => _json(200, _full));
      final repo = AppConfigRepository(client(adapter), prefs);
      final config = await repo.fetch('acme');

      expect(config.tenant!.slug, 'acme');
      final request = adapter.requests.single;
      expect(request.path, '/v1/app/config');
      expect(request.queryParameters, {'tenant_slug': 'acme'});
      expect(request.headers.containsKey('X-Tenant-Slug'), isFalse);
    });

    test('404 TENANT_NOT_FOUND se propaga', () async {
      final adapter = _FakeAdapter((_) => _json(404, {'success': false, 'code': 'TENANT_NOT_FOUND'}));
      final repo = AppConfigRepository(client(adapter), prefs);
      expect(
        () => repo.fetch('nadie'),
        throwsA(isA<ApiException>().having((e) => e.code, 'code', 'TENANT_NOT_FOUND')),
      );
    });

    test('backend sin /v1/app/config: usa /tenant/branding', () async {
      final adapter = _FakeAdapter((o) => o.path == '/v1/app/config'
          ? _json(404, {'message': 'Not Found'})
          : _json(200, {
              'data': {'slug': 'demo', 'company_name': 'Demo SAC', 'primary_color': '#0F766E'},
            }));
      final repo = AppConfigRepository(client(adapter), prefs);
      final config = await repo.fetch('demo');
      expect(config.displayName, 'Demo SAC');
      expect(adapter.requests.last.path, '/tenant/branding');
    });
  });

  group('ApiClient', () {
    test('envía X-App-Version, avisa un 426 y cambia de dominio en caliente', () async {
      SharedPreferences.setMockInitialValues({});
      final prefs = await SharedPreferences.getInstance();
      final adapter = _FakeAdapter((o) => o.path == '/old'
          ? _json(426, {
              'code': 'APP_UPDATE_REQUIRED',
              'data': {'min_supported_version': '2.0.0', 'latest_version': '2.1.0'},
            })
          : _json(200, {'ok': true}));
      final dio = Dio(BaseOptions(baseUrl: 'https://a.test/api'))..httpClientAdapter = adapter;
      String? min;
      final api = ApiClient(SessionStorage(prefs), dio: dio, appVersion: '1.0.0', onUpdateRequired: (m, _) => min = m);

      await expectLater(api.get('/old'), throwsA(isA<ApiException>()));
      expect(min, '2.0.0');
      expect(adapter.requests.last.headers['X-App-Version'], '1.0.0');

      api.baseUrl = 'https://b.test/api/';
      await api.get('/new');
      expect(adapter.requests.last.uri.toString(), 'https://b.test/api/new');
    });
  });

  group('Dominio del backend', () {
    late SharedPreferences prefs;

    setUp(() async {
      SharedPreferences.setMockInitialValues({});
      prefs = await SharedPreferences.getInstance();
    });

    test('el servidor anuncia api_base_url: se cambia solo si responde', () async {
      final checked = <String>[];
      var healthy = false;
      final container = ProviderContainer(overrides: [
        sharedPreferencesProvider.overrideWithValue(prefs),
        serverHealthCheckProvider.overrideWithValue((url) async {
          checked.add(url);
          return healthy;
        }),
        appConfigRepositoryProvider.overrideWithValue(
          _FakeConfigRepository(prefs, const AppConfig(mobile: MobileConfig(apiBaseUrl: 'https://nuevo.acme.pe/api'))),
        ),
      ]);
      addTearDown(container.dispose);

      final compiled = container.read(baseUrlProvider);
      await container.read(appConfigProvider.notifier).refresh();
      expect(checked, ['https://nuevo.acme.pe']);
      expect(container.read(baseUrlProvider), compiled);

      healthy = true;
      await container.read(appConfigProvider.notifier).refresh();
      expect(container.read(baseUrlProvider), 'https://nuevo.acme.pe');
      expect(prefs.getString(BaseUrlController.overrideKey), 'https://nuevo.acme.pe');
      expect(container.read(appConfigProvider).ready, isTrue);
    });

    test('con override y fallos de red seguidos vuelve al dominio compilado si responde', () async {
      var compiledHealthy = false;
      final container = ProviderContainer(overrides: [
        sharedPreferencesProvider.overrideWithValue(prefs),
        serverHealthCheckProvider.overrideWithValue((_) async => compiledHealthy),
      ]);
      addTearDown(container.dispose);
      final controller = container.read(baseUrlProvider.notifier);

      await controller.setOverride('https://tunel.trycloudflare.com');
      for (var i = 0; i < BaseUrlController.failureThreshold; i++) {
        controller.reportNetworkResult(ok: false);
      }
      await pumpEventQueue();
      expect(container.read(baseUrlProvider), 'https://tunel.trycloudflare.com');

      compiledHealthy = true;
      for (var i = 0; i < BaseUrlController.failureThreshold; i++) {
        controller.reportNetworkResult(ok: false);
      }
      await pumpEventQueue();
      expect(controller.hasOverride, isFalse);
      expect(container.read(baseUrlProvider), controller.compiled);
    });
  });

  group('Semver y VersionGate', () {
    test('compara ignorando +build', () {
      expect(Semver.compare('1.2.3+45', '1.2.3'), 0);
      expect(Semver.compare('1.2', '1.2.0'), 0);
      expect(Semver.compare('1.10.0', '1.9.9'), greaterThan(0));
      expect(Semver.compare('0.1.0', '1.0.0'), lessThan(0));
      expect(Semver.isLower('1.0.0', null), isFalse);
      expect(Semver.isLower('1.0.0', ''), isFalse);
    });

    test('bloquea, avisa o deja pasar', () {
      const forced = MobileConfig(minSupportedVersion: '1.2.0', forceUpdate: true);
      const soft = MobileConfig(minSupportedVersion: '1.2.0');
      const latest = MobileConfig(minSupportedVersion: '1.0.0', latestVersion: '1.5.0');
      expect(VersionGate.evaluate('1.1.9+7', forced), UpdateLevel.required);
      expect(VersionGate.evaluate('1.2.0', forced), UpdateLevel.none);
      expect(VersionGate.evaluate('1.1.0', soft), UpdateLevel.optional);
      expect(VersionGate.evaluate('1.4.0', latest), UpdateLevel.optional);
      expect(VersionGate.evaluate('1.5.0', latest), UpdateLevel.none);
      expect(VersionGate.evaluate('9.9.9', const MobileConfig(), serverRejected: true), UpdateLevel.required);
      expect(VersionGate.evaluate('0.1.0', const MobileConfig()), UpdateLevel.none);
    });
  });

  group('ServerUrl.validate', () {
    test('exige https salvo en dev y normaliza', () {
      expect(ServerUrl.validate('https://abc.trycloudflare.com/api/', allowHttp: false).url,
          'https://abc.trycloudflare.com');
      expect(ServerUrl.validate('http://192.168.1.20:8000', allowHttp: false).isValid, isFalse);
      expect(ServerUrl.validate('http://192.168.1.20:8000', allowHttp: true).url, 'http://192.168.1.20:8000');
      expect(ServerUrl.validate('abc.com', allowHttp: true).isValid, isFalse);
      expect(ServerUrl.validate('', allowHttp: true).isValid, isFalse);
      expect(ServerUrl.validate('ftp://x.com', allowHttp: true).isValid, isFalse);
    });
  });

  group('Tema de marca', () {
    test('navbar_color define AppBar y barra inferior con texto legible', () {
      const branding = BrandingConfig(primaryColor: '#0F766E', navbarColor: '#111827', accentColor: '#7C3AED');
      final theme = AppTheme.fromBranding(branding, Brightness.light);
      expect(theme.appBarTheme.backgroundColor, const Color(0xFF111827));
      expect(theme.appBarTheme.foregroundColor, Colors.white);
      expect(theme.navigationBarTheme.backgroundColor, const Color(0xFF111827));
      expect(AppTheme.foregroundOn(const Color(0xFFFDE68A)), isNot(Colors.white));
    });

    test('sin navbar_color las barras son claras con el acento de la marca', () {
      final dark = AppTheme.fromBranding(const BrandingConfig(), Brightness.dark);
      expect(dark.appBarTheme.backgroundColor, dark.colorScheme.surface);
      expect(dark.brightness, Brightness.dark);

      final light = AppTheme.fromBranding(const BrandingConfig(primaryColor: '#0050A0'), Brightness.light);
      expect(light.appBarTheme.backgroundColor, light.colorScheme.surface);
      expect(light.navigationBarTheme.backgroundColor, light.colorScheme.surface);
      expect(light.colorScheme.primary, const Color(0xFF0050A0));
    });
  });

  test('Fmt.isoWithOffset incluye el desfase horario', () {
    final iso = Fmt.isoWithOffset(DateTime(2026, 10, 9, 8, 1, 2));
    expect(iso, matches(RegExp(r'^2026-10-09T08:01:02[+-]\d{2}:\d{2}$')));
    expect(DateTime.parse(iso).toLocal(), DateTime(2026, 10, 9, 8, 1, 2));
  });
}
