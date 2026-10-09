import 'dart:math';
import 'package:geolocator/geolocator.dart';

/// Resultado del cálculo geográfico de geocerca en el móvil
class GeofenceValidationResult {
  final double latitude;
  final double longitude;
  final double accuracyMeters;
  final double distanceMeters;
  final bool isWithinGeofence;
  final bool isMocked;
  final String message;

  GeofenceValidationResult({
    required this.latitude,
    required this.longitude,
    required this.accuracyMeters,
    required this.distanceMeters,
    required this.isWithinGeofence,
    required this.isMocked,
    required this.message,
  });
}

/// Servicio de Geolocalización y Validación Satelital de Geocercas
class LocationService {
  static const double earthRadiusMeters = 6371000.0;

  /// Solicita permisos y obtiene la posición satelital precisa actual
  static Future<Position> getCurrentLocation() async {
    bool serviceEnabled = await Geolocator.isLocationServiceEnabled();
    if (!serviceEnabled) {
      throw Exception('Los servicios de ubicación GPS están desactivados en tu dispositivo.');
    }

    LocationPermission permission = await Geolocator.checkPermission();
    if (permission == LocationPermission.denied) {
      permission = await Geolocator.requestPermission();
      if (permission == LocationPermission.denied) {
        throw Exception('El permiso de ubicación fue denegado.');
      }
    }

    if (permission == LocationPermission.deniedForever) {
      throw Exception('Los permisos de ubicación están permanentemente denegados. Habilítalos en Ajustes.');
    }

    return await Geolocator.getCurrentPosition(
      desiredAccuracy: LocationAccuracy.best,
      timeLimit: const Duration(seconds: 12),
    );
  }

  /// Calcula la distancia Haversine exacta entre dos puntos GPS en metros
  static double calculateHaversineDistance({
    required double startLat,
    required double startLng,
    required double endLat,
    required double endLng,
  }) {
    final double dLat = _toRadians(endLat - startLat);
    final double dLng = _toRadians(endLng - startLng);

    final double lat1Rad = _toRadians(startLat);
    final double lat2Rad = _toRadians(endLat);

    final double a = sin(dLat / 2) * sin(dLat / 2) +
        cos(lat1Rad) * cos(lat2Rad) * sin(dLng / 2) * sin(dLng / 2);

    final double c = 2 * atan2(sqrt(a), sqrt(1 - a));

    return earthRadiusMeters * c;
  }

  /// Valida la posición actual del usuario contra la geocerca de una sucursal
  static Future<GeofenceValidationResult> validateGeofence({
    required double branchLatitude,
    required double branchLongitude,
    required int allowedRadiusMeters,
  }) async {
    final Position position = await getCurrentLocation();

    // Detección de Fake GPS / Ubicación simulada
    final bool isMocked = position.isMocked;

    final double distance = calculateHaversineDistance(
      startLat: position.latitude,
      startLng: position.longitude,
      endLat: branchLatitude,
      endLng: branchLongitude,
    );

    final bool isWithin = distance <= allowedRadiusMeters;

    String message;
    if (isMocked) {
      message = 'ALERTA: Se detectó una aplicación de GPS falso o ubicación simulada.';
    } else if (isWithin) {
      message = 'Estás dentro de la geocerca (${distance.toStringAsFixed(1)}m de ${allowedRadiusMeters}m).';
    } else {
      final double diff = distance - allowedRadiusMeters;
      message = 'Fuera del perímetro permitido. Estás a ${distance.toStringAsFixed(1)}m (a ${diff.toStringAsFixed(1)}m del límite).';
    }

    return GeofenceValidationResult(
      latitude: position.latitude,
      longitude: position.longitude,
      accuracyMeters: position.accuracy,
      distanceMeters: double.parse(distance.toStringAsFixed(1)),
      isWithinGeofence: isWithin && !isMocked,
      isMocked: isMocked,
      message: message,
    );
  }

  static double _toRadians(double degree) {
    return degree * (pi / 180.0);
  }
}
