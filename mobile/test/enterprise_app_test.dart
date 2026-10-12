import 'package:flutter/foundation.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:tunesa_mobile/core/config/app_config.dart';
import 'package:tunesa_mobile/core/location/location_service.dart';
import 'package:tunesa_mobile/core/push/push_controller.dart';
import 'package:tunesa_mobile/core/push/push_options.dart';
import 'package:tunesa_mobile/features/attendance/data/photo_compressor.dart';
import 'package:tunesa_mobile/features/attendance/data/reverse_geocoder.dart';
import 'package:tunesa_mobile/features/attendance/domain/models.dart';

void main() {
  group('Config: campos nuevos de mobile', () {
    test('lee mock_location_policy, drift, geocoding y fcm', () {
      final config = AppConfig.fromJson({
        'data': {
          'mobile': {
            'mock_location_policy': 'flag',
            'max_time_drift_seconds': 120,
            'geocoding_provider': 'nominatim',
            'fcm_enabled': false,
          },
        },
      });
      final m = config.mobile;
      expect(m.mockLocationPolicy, MockLocationPolicy.flag);
      expect(m.maxTimeDriftSeconds, 120);
      expect(m.geocodingProvider, 'nominatim');
      expect(m.fcmEnabled, isFalse);

      // Sobrevive a la caché (toJson → fromJson)
      final again = AppConfig.fromJson(config.toJson()).mobile;
      expect(again.mockLocationPolicy, MockLocationPolicy.flag);
      expect(again.maxTimeDriftSeconds, 120);
      expect(again.fcmEnabled, isFalse);
    });

    test('valores ausentes o raros: reject, sin límite de desfase, push activo', () {
      final m = AppConfig.fromJson({
        'data': {
          'mobile': {'mock_location_policy': 'quizas', 'max_time_drift_seconds': '0', 'fcm_enabled': 'x'},
        },
      }).mobile;
      expect(m.mockLocationPolicy, MockLocationPolicy.reject);
      expect(m.maxTimeDriftSeconds, isNull);
      expect(m.geocodingProvider, isNull);
      expect(m.fcmEnabled, isTrue);
      expect(const MobileConfig().mockLocationPolicy, MockLocationPolicy.reject);
      expect(MockLocationPolicy.fromApi('FLAG'), MockLocationPolicy.flag);
    });
  });

  group('Política de ubicación simulada', () {
    test('real: se envía; simulada: reject bloquea, flag envía en revisión', () {
      for (final policy in MockLocationPolicy.values) {
        expect(decideMock(isMocked: false, policy: policy), MockDecision.allow);
      }
      expect(decideMock(isMocked: true, policy: MockLocationPolicy.reject), MockDecision.block);
      expect(decideMock(isMocked: true, policy: MockLocationPolicy.flag), MockDecision.flag);
      expect(mockFlagWarning, contains('Tu marcación quedará en revisión'));
    });

    test('fuera de iOS el canal nativo responde false sin llamar a la plataforma', () async {
      expect(await const LocationIntegrityChannel().isSimulated(), isFalse);
    });
  });

  group('Push', () {
    const full = FirebaseDefines(
      apiKey: 'key',
      androidAppId: '1:123:android:abc',
      messagingSenderId: '123',
      projectId: 'tunesa',
      iosAppId: '1:123:ios:def',
      iosBundleId: 'pe.tunesa.tunesaMobile',
    );

    test('opciones completas por plataforma', () {
      final android = firebaseOptionsFor(TargetPlatform.android, full)!;
      expect(android.appId, '1:123:android:abc');
      expect(android.projectId, 'tunesa');
      final ios = firebaseOptionsFor(TargetPlatform.iOS, full)!;
      expect(ios.appId, '1:123:ios:def');
      expect(ios.iosBundleId, 'pe.tunesa.tunesaMobile');
    });

    test('falta algún valor (o build sin FIREBASE_*): push desactivado', () {
      expect(firebaseOptionsFor(TargetPlatform.android, const FirebaseDefines()), isNull);
      expect(firebaseOptionsFor(TargetPlatform.android, FirebaseDefines.compiled), isNull);
      const noAndroidId = FirebaseDefines(apiKey: 'k', messagingSenderId: '1', projectId: 'p', iosAppId: 'i', iosBundleId: 'b');
      expect(firebaseOptionsFor(TargetPlatform.android, noAndroidId), isNull);
      expect(firebaseOptionsFor(TargetPlatform.iOS, noAndroidId), isNotNull);
      const noBundle = FirebaseDefines(apiKey: 'k', messagingSenderId: '1', projectId: 'p', iosAppId: 'i', iosBundleId: ' ');
      expect(firebaseOptionsFor(TargetPlatform.iOS, noBundle), isNull);
      expect(firebaseOptionsFor(TargetPlatform.linux, full), isNull);

      expect(pushAvailability(hasOptions: false, fcmEnabled: true, authenticated: true), PushStatus.unavailable);
      expect(pushAvailability(hasOptions: true, fcmEnabled: false, authenticated: true), PushStatus.disabledByCompany);
      expect(pushAvailability(hasOptions: true, fcmEnabled: true, authenticated: false), PushStatus.signedOut);
      expect(pushAvailability(hasOptions: true, fcmEnabled: true, authenticated: true), PushStatus.enabled);
    });

    test('al tocar un push navega por data.screen o por el tipo', () {
      expect(pushRouteFor({'screen': 'leave_requests'}), '/requests');
      expect(pushRouteFor({'screen': 'payslips', 'type': 'leave_request.approved'}), '/payslips');
      expect(pushRouteFor({'type': 'document.rejected'}), '/profile/documents');
      expect(pushRouteFor({'type': 'leave_request.submitted'}), '/approvals');
      expect(pushRouteFor({}), '/notifications');
      expect(pushRouteFor({'type': 'app.update_available', 'screen': 'app_update'}), '/home');
      expect(pushRouteFor({'type': 'shift.assigned'}), '/home');
      expect(pushRouteFor({'type': 'attendance.entry_reminder', 'screen': 'home'}), '/home');
    });

    test('reconoce el push de nueva versión', () {
      expect(isAppUpdatePush({'type': 'app.update_available'}), isTrue);
      expect(isAppUpdatePush({'screen': 'app_update'}), isTrue);
      expect(isAppUpdatePush({'type': 'payslip.published'}), isFalse);
    });

    test('el enlace de descarga solo se acepta con https', () {
      expect(MobileConfig.fromJson({'update_url': 'https://tunesa.app/app.apk'}).updateUrl, 'https://tunesa.app/app.apk');
      expect(MobileConfig.fromJson({'update_url': 'http://tunesa.app/app.apk'}).updateUrl, isNull);
      expect(MobileConfig.fromJson({'update_url': 'javascript:alert(1)'}).updateUrl, isNull);
      expect(MobileConfig.fromJson({}).updateUrl, isNull);
    });
  });

  group('Dirección legible', () {
    test('reusa la dirección dentro de ~50 m y consulta de nuevo más lejos', () async {
      final calls = <(double, double)>[];
      final geocoder = ReverseGeocoder((lat, lng) async {
        calls.add((lat, lng));
        return 'Av. Javier Prado Este ${calls.length}';
      });

      expect(await geocoder.resolve(-12.0912, -77.0245), 'Av. Javier Prado Este 1');
      // ~22 m más al norte: misma dirección, sin consultar
      expect(await geocoder.resolve(-12.0910, -77.0245), 'Av. Javier Prado Este 1');
      expect(calls.length, 1);
      // ~220 m: nueva consulta
      expect(await geocoder.resolve(-12.0892, -77.0245), 'Av. Javier Prado Este 2');
      expect(calls.length, 2);
    });

    test('un error o una dirección vacía no rompe nada', () async {
      final failing = ReverseGeocoder((_, _) async => throw Exception('sin red'));
      expect(await failing.resolve(0, 0), isNull);
      final empty = ReverseGeocoder((_, _) async => '  ');
      expect(await empty.resolve(0, 0), isNull);
      expect(empty.cached(0, 0), isNull);
    });
  });

  test('compresión: lado mayor a 1280 px sin importar la orientación', () {
    expect(PhotoCompressor.targetMinSide(1920, 1080), 720);
    expect(PhotoCompressor.targetMinSide(1080, 1920), 720);
    expect(PhotoCompressor.targetMinSide(720, 480), 480); // ya cabe: sin reducir
    expect(PhotoCompressor.targetMinSide(0, 0), PhotoCompressor.maxSide);
  });

  test('desfase de hora con el servidor según max_time_drift_seconds', () {
    final skew = TodayStatus.skewFrom('2026-10-09T08:00:00-05:00', DateTime.utc(2026, 10, 9, 13, 3));
    expect(skew, const Duration(minutes: 3));
    final status = TodayStatus(actions: const [], clockSkew: skew);
    expect(status.driftBeyond(120), 180);
    expect(status.driftBeyond(300), isNull);
    expect(status.driftBeyond(null), isNull);
    expect(TodayStatus.skewFrom(null, DateTime.now()), isNull);
  });
}
