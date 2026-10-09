import 'dart:math';

import 'package:flutter/foundation.dart';
import 'package:geolocator/geolocator.dart';

import '../config/env.dart';

/// Motivo por el que no se pudo obtener una ubicación confiable.
enum LocationFailure {
  serviceDisabled,
  permissionDenied,
  permissionDeniedForever,
  mocked,
  lowAccuracy,
  timeout,
}

class LocationException implements Exception {
  const LocationException(this.failure, this.message);

  final LocationFailure failure;
  final String message;

  /// El usuario puede resolverlo desde Ajustes del sistema.
  bool get canOpenSettings =>
      failure == LocationFailure.serviceDisabled || failure == LocationFailure.permissionDeniedForever;

  @override
  String toString() => message;
}

/// Lectura GPS "estricta" para marcar asistencia.
///
/// 1. Servicio de ubicación encendido.
/// 2. Permiso concedido (lo solicita si hace falta).
/// 3. Lectura fresca de alta precisión (no se usa la última posición cacheada).
/// 4. Rechaza ubicaciones simuladas (mock GPS / apps de GPS falso).
/// 5. Rechaza lecturas con precisión peor que [Env.maxGpsAccuracyMeters].
///
/// El backend vuelve a validar mock, precisión y geocerca (Haversine).
class LocationService {
  static const double _earthRadiusMeters = 6371000;

  Future<Position> getVerifiedPosition() async {
    if (!await Geolocator.isLocationServiceEnabled()) {
      throw const LocationException(
        LocationFailure.serviceDisabled,
        'El GPS está desactivado. Actívalo para marcar tu asistencia.',
      );
    }

    var permission = await Geolocator.checkPermission();
    if (permission == LocationPermission.denied) {
      permission = await Geolocator.requestPermission();
    }
    if (permission == LocationPermission.deniedForever) {
      throw const LocationException(
        LocationFailure.permissionDeniedForever,
        'El permiso de ubicación está bloqueado. Habilítalo en Ajustes > Aplicaciones > TuneSA.',
      );
    }
    if (permission == LocationPermission.denied || permission == LocationPermission.unableToDetermine) {
      throw const LocationException(
        LocationFailure.permissionDenied,
        'Necesitamos tu ubicación para validar que estás en la sucursal.',
      );
    }

    final Position position;
    try {
      position = await Geolocator.getCurrentPosition(locationSettings: _settings());
    } on Exception catch (e) {
      if (e is LocationServiceDisabledException) {
        throw const LocationException(LocationFailure.serviceDisabled, 'El GPS se desactivó durante la lectura.');
      }
      throw const LocationException(
        LocationFailure.timeout,
        'No se pudo obtener tu ubicación a tiempo. Sal a un lugar despejado e inténtalo de nuevo.',
      );
    }

    if (position.isMocked) {
      throw const LocationException(
        LocationFailure.mocked,
        'Se detectó una ubicación simulada. Desactiva cualquier app de GPS falso o las opciones de desarrollador de ubicación.',
      );
    }

    if (position.accuracy > Env.maxGpsAccuracyMeters) {
      throw LocationException(
        LocationFailure.lowAccuracy,
        'La señal GPS es imprecisa (±${position.accuracy.round()} m). Acércate a una ventana o sal al exterior y reintenta.',
      );
    }

    return position;
  }

  Future<void> openSettings(LocationFailure failure) => failure == LocationFailure.serviceDisabled
      ? Geolocator.openLocationSettings()
      : Geolocator.openAppSettings();

  LocationSettings _settings() {
    if (defaultTargetPlatform == TargetPlatform.android) {
      return AndroidSettings(accuracy: LocationAccuracy.best, timeLimit: Env.gpsTimeout);
    }
    if (defaultTargetPlatform == TargetPlatform.iOS) {
      return AppleSettings(accuracy: LocationAccuracy.best, timeLimit: Env.gpsTimeout);
    }
    return LocationSettings(accuracy: LocationAccuracy.best, timeLimit: Env.gpsTimeout);
  }

  /// Distancia Haversine en metros (misma fórmula que GeofenceService del backend).
  static double haversineMeters(double lat1, double lng1, double lat2, double lng2) {
    double rad(double deg) => deg * pi / 180;
    final dLat = rad(lat2 - lat1);
    final dLng = rad(lng2 - lng1);
    final a = sin(dLat / 2) * sin(dLat / 2) + cos(rad(lat1)) * cos(rad(lat2)) * sin(dLng / 2) * sin(dLng / 2);
    return _earthRadiusMeters * 2 * atan2(sqrt(a), sqrt(1 - a));
  }
}
