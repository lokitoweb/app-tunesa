import 'package:flutter_test/flutter_test.dart';
import 'package:geolocator/geolocator.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:tunesa_mobile/core/location/location_service.dart';
import 'package:tunesa_mobile/core/network/api_client.dart';
import 'package:tunesa_mobile/core/network/api_endpoints.dart';
import 'package:tunesa_mobile/core/network/api_exception.dart';
import 'package:tunesa_mobile/core/storage/session_storage.dart';
import 'package:tunesa_mobile/features/attendance/data/attempt_reporter.dart';
import 'package:tunesa_mobile/features/attendance/domain/models.dart';

Position _pos({double accuracy = 9, bool mocked = false}) => Position(
      latitude: -8.1172,
      longitude: -79.0205,
      timestamp: DateTime.utc(2026, 10, 12, 13),
      accuracy: accuracy,
      altitude: 0,
      altitudeAccuracy: 0,
      heading: 0,
      headingAccuracy: 0,
      speed: 0,
      speedAccuracy: 0,
      isMocked: mocked,
    );

/// Responde con los errores de [failures] (en orden) y luego con éxito.
class _FakeApi extends ApiClient {
  _FakeApi(super.storage, [List<ApiException?> failures = const []]) : _failures = [...failures];

  final List<ApiException?> _failures;
  final sent = <Map<String, dynamic>>[];

  @override
  Future<Map<String, dynamic>> post(String path, {Object? data, bool auth = true, String? tenant}) async {
    expect(path, ApiEndpoints.attendanceAttempts);
    final failure = _failures.isEmpty ? null : _failures.removeAt(0);
    if (failure != null) throw failure;
    sent.add(Map<String, dynamic>.from(data! as Map));
    return {'success': true};
  }
}

void main() {
  late SharedPreferences prefs;
  late SessionStorage storage;
  final at = DateTime.utc(2026, 10, 12, 13, 0, 5);

  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    prefs = await SharedPreferences.getInstance();
    storage = SessionStorage(prefs);
  });

  group('qué bloqueo se reporta', () {
    test('GPS falso e impreciso llevan la lectura descartada', () {
      final mock = AppAttempt.fromLocationError(
        ClockAction.clockIn,
        LocationException(LocationFailure.mocked, 'x', position: _pos(mocked: true), isMocked: true),
        at: at,
      )!;
      expect(mock.code, AppAttempt.mockCode);
      expect(mock.isMocked, isTrue);
      expect(mock.toJson(), containsPair('latitude', -8.1172));
      expect(mock.toJson()['occurred_at'], '2026-10-12T13:00:05.000Z');

      final low = AppAttempt.fromLocationError(
        ClockAction.clockOut,
        LocationException(LocationFailure.lowAccuracy, 'x', position: _pos(accuracy: 80)),
      )!;
      expect(low.code, AppAttempt.accuracyCode);
      expect(low.toJson(), containsPair('type', 'clock_out'));
      expect(low.toJson(), containsPair('accuracy_meters', 80.0));
    });

    test('sin permiso o sin señal no hay nada que reportar', () {
      for (final f in [LocationFailure.permissionDenied, LocationFailure.serviceDisabled, LocationFailure.timeout]) {
        expect(AppAttempt.fromLocationError(ClockAction.clockIn, LocationException(f, 'x')), isNull);
      }
    });

    test('fuera del radio lleva la sede, la distancia y el radio', () {
      const branch = BranchGeofence(id: 7, name: 'Trujillo', lat: -8.11823, lng: -79.02052, radius: 100);
      final json = AppAttempt.outside(ClockAction.clockIn, LocationFix(_pos(), isMocked: false), branch: branch, distance: 112.4)
          .toJson();
      expect(json['code'], AppAttempt.geofenceCode);
      expect(json['branch_id'], 7);
      expect(json['allowed_radius'], 100);
      expect(json['distance_meters'], 112.4);
    });
  });

  group('envío con cola local', () {
    AppAttempt attempt() => AppAttempt(action: ClockAction.clockIn, code: AppAttempt.mockCode, occurredAt: at, isMocked: true);

    test('envía con los datos del teléfono', () async {
      final api = _FakeApi(storage);
      await AttemptReporter(api, prefs, storage, appVersion: '2.4.0').report(attempt());
      expect(api.sent.single, containsPair('app_version', '2.4.0'));
      expect(api.sent.single['device_id'], isNotEmpty);
      expect(prefs.getString(AttemptReporter.queueKey), isNull);
    });

    test('sin señal queda en la cola y se reenvía con el siguiente', () async {
      final first = attempt();
      final offline = _FakeApi(storage, [const ApiException('sin red', code: 'NETWORK')]);
      await AttemptReporter(offline, prefs, storage).report(first);
      expect(offline.sent, isEmpty);
      expect(prefs.getString(AttemptReporter.queueKey), contains(first.clientUuid));

      final online = _FakeApi(storage);
      await AttemptReporter(online, prefs, storage).report(attempt());
      expect(online.sent.first['client_uuid'], first.clientUuid, reason: 'mismo client_uuid: el servidor no duplica');
      expect(online.sent, hasLength(2));
      expect(prefs.getString(AttemptReporter.queueKey), isNull);
    });

    test('un rechazo de validación se descarta; un 5xx se reintenta', () async {
      final api = _FakeApi(storage, [
        const ApiException('inválido', code: 'VALIDATION_ERROR', statusCode: 422),
        const ApiException('caído', statusCode: 503),
      ]);
      final reporter = AttemptReporter(api, prefs, storage);
      await reporter.report(attempt());
      expect(prefs.getString(AttemptReporter.queueKey), isNull);
      await reporter.report(attempt());
      expect(prefs.getString(AttemptReporter.queueKey), isNotNull);
    });

    test('la cola guarda como máximo los últimos ${AttemptReporter.maxQueued}', () async {
      final api = _FakeApi(storage, List.filled(AttemptReporter.maxQueued * 3, const ApiException('sin red', code: 'NETWORK')));
      final reporter = AttemptReporter(api, prefs, storage);
      // Cada reporte reintenta toda la cola: todas las solicitudes fallan
      for (var i = 0; i < 5; i++) {
        await reporter.report(attempt());
      }
      expect(RegExp('client_uuid').allMatches(prefs.getString(AttemptReporter.queueKey)!).length, 5);

      for (var i = 0; i < AttemptReporter.maxQueued; i++) {
        await AttemptReporter(_FakeApi(storage, List.filled(100, const ApiException('sin red', code: 'NETWORK'))), prefs, storage)
            .report(attempt());
      }
      expect(RegExp('client_uuid').allMatches(prefs.getString(AttemptReporter.queueKey)!).length, AttemptReporter.maxQueued);
    });
  });
}
