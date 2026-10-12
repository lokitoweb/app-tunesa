import 'package:flutter_test/flutter_test.dart';
import 'package:geolocator/geolocator.dart';
import 'package:tunesa_mobile/core/location/location_service.dart';
import 'package:tunesa_mobile/features/attendance/domain/models.dart';
import 'package:tunesa_mobile/features/attendance/presentation/clock_screen.dart';

Position _pos(double accuracy, {double lat = -12.09418, bool mocked = false, int second = 0}) => Position(
      latitude: lat,
      longitude: -77.03560,
      timestamp: DateTime(2026, 10, 12, 8, 0, second),
      accuracy: accuracy,
      altitude: 0,
      altitudeAccuracy: 0,
      heading: 0,
      headingAccuracy: 0,
      speed: 0,
      speedAccuracy: 0,
      isMocked: mocked,
    );

BranchGeofence _branch({double tolerance = 15, int? maxAccuracy}) => BranchGeofence(
      id: 1,
      name: 'Trujillo',
      lat: -12.09418,
      lng: -77.03560,
      radius: 100,
      tolerance: tolerance,
      maxAccuracy: maxAccuracy,
    );

void main() {
  group('zona de borde (misma regla que GeofenceService::check)', () {
    test('dentro del radio siempre entra', () {
      expect(_branch().admits(99, 0), isTrue);
    });

    test('fuera del radio entra solo por min(precisión, tolerancia)', () {
      expect(_branch().admits(111, 5), isFalse, reason: 'margen de 5 m');
      expect(_branch().admits(111, 40), isTrue, reason: 'margen limitado a 15 m');
      expect(_branch().admits(133, 40), isFalse, reason: 'más allá de la tolerancia');
      expect(_branch(tolerance: 0).admits(101, 40), isFalse, reason: 'sin zona de borde');
    });

    test('nearestBranch usa la precisión de la lectura', () {
      // ~111 m al norte de la sede
      final edge = nearestBranch([_branch()], _pos(40, lat: -12.09318))!;
      expect(edge.inside, isTrue);
      expect(nearestBranch([_branch()], _pos(5, lat: -12.09318))!.inside, isFalse);
    });

    test('lee tolerancia y precisión del servidor, con valores por defecto', () {
      final json = {
        'id': 3,
        'name': 'Trujillo',
        'geofence': {'latitude': -8.11823, 'longitude': -79.02052, 'allowed_radius_meters': 100, 'tolerance_meters': 25, 'max_gps_accuracy_meters': 60},
      };
      final b = BranchGeofence.fromJson(json);
      expect(b.tolerance, 25);
      expect(b.maxAccuracy, 60);

      final old = BranchGeofence.fromJson({
        'id': 3,
        'name': 'Trujillo',
        'geofence': {'latitude': -8.11823, 'longitude': -79.02052, 'allowed_radius_meters': 100},
      });
      expect(old.tolerance, BranchGeofence.defaultTolerance);
      expect(old.maxAccuracy, isNull);
    });
  });

  group('varias lecturas de GPS', () {
    test('se queda con la más precisa', () {
      final best = LocationService.bestSample([_pos(35), _pos(12, second: 2), _pos(20, second: 4)]);
      expect(best.accuracy, 12);
    });

    test('ante empate, la más reciente', () {
      final best = LocationService.bestSample([_pos(12), _pos(12, second: 3)]);
      expect(best.timestamp.second, 3);
    });
  });

  group('precisión máxima en el teléfono', () {
    test('la más permisiva entre la empresa y las sedes asignadas', () {
      expect(effectiveMaxAccuracy(100, [_branch(maxAccuracy: 150), _branch()]), 150);
      expect(effectiveMaxAccuracy(100, [_branch(maxAccuracy: 30)]), 100);
      expect(effectiveMaxAccuracy(null, [_branch(maxAccuracy: 30)]), 30);
      expect(effectiveMaxAccuracy(null, const []), isNull);
    });
  });
}
