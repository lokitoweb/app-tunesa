import 'dart:math';

import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';
import 'package:geolocator/geolocator.dart';

import '../config/app_config.dart';
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
  const LocationException(this.failure, this.message, {this.position, this.isMocked = false});

  final LocationFailure failure;
  final String message;

  /// Lectura que se descartó ([LocationFailure.mocked] / [LocationFailure.lowAccuracy]):
  /// se reporta al servidor como intento bloqueado.
  final Position? position;
  final bool isMocked;

  /// El usuario puede resolverlo desde Ajustes del sistema.
  bool get canOpenSettings =>
      failure == LocationFailure.serviceDisabled || failure == LocationFailure.permissionDeniedForever;

  @override
  String toString() => message;
}

/// Qué hacer con una lectura según `mock_location_policy`.
enum MockDecision {
  /// Ubicación real: se envía normal.
  allow,

  /// Simulada con política `flag`: se envía con `is_mocked=1` y se avisa.
  flag,

  /// Simulada con política `reject`: no se envía.
  block,
}

MockDecision decideMock({required bool isMocked, required MockLocationPolicy policy}) {
  if (!isMocked) return MockDecision.allow;
  return policy == MockLocationPolicy.flag ? MockDecision.flag : MockDecision.block;
}

/// Mensaje para el colaborador cuando la marcación se envía con ubicación simulada.
const mockFlagWarning =
    'Detectamos una ubicación simulada. Tu marcación quedará en revisión.';

/// Lectura GPS verificada (con el resultado de ubicación simulada combinado).
class LocationFix {
  const LocationFix(this.position, {required this.isMocked});

  final Position position;

  /// `Position.isMocked` (Android / iOS) o el chequeo nativo de iOS 15+.
  final bool isMocked;

  double get latitude => position.latitude;
  double get longitude => position.longitude;
  double get accuracy => position.accuracy;
  DateTime get timestamp => position.timestamp;
}

/// iOS 15+: `CLLocation.sourceInformation` (isSimulatedBySoftware /
/// isProducedByAccessory) con una lectura puntual de CLLocationManager
/// (ios/Runner/AppDelegate.swift). En Android o si falla: false.
class LocationIntegrityChannel {
  const LocationIntegrityChannel([this._channel = const MethodChannel('tunesa/location_integrity')]);

  final MethodChannel _channel;

  Future<bool> isSimulated() async {
    if (kIsWeb || defaultTargetPlatform != TargetPlatform.iOS) return false;
    try {
      final result = await _channel.invokeMapMethod<String, Object?>('checkSimulated').timeout(const Duration(seconds: 6));
      return result?['simulated'] == true || result?['accessory'] == true;
    } catch (_) {
      return false;
    }
  }
}

/// Lectura GPS "estricta" para marcar asistencia.
///
/// 1. Servicio de ubicación encendido.
/// 2. Permiso concedido (lo solicita si hace falta).
/// 3. Lecturas frescas de alta precisión (no se usa la última posición
///    cacheada): hasta [maxSamples] en [sampleWindow], se queda con la más
///    precisa y corta antes si una baja de [goodEnoughMeters].
/// 4. Ubicaciones simuladas (mock GPS / apps de GPS falso) según la política
///    de la empresa: `reject` las bloquea, `flag` las deja pasar marcadas.
/// 5. Precisión peor que `max_gps_accuracy_meters` (respaldo:
///    [Env.maxGpsAccuracyMeters]): se acepta solo si todo el círculo de error
///    cae dentro de alguna de [sites] (es seguro que está en la sede); si no,
///    se rechaza. Decisión automática: sí o no, igual que el backend.
///
/// El backend vuelve a validar mock, precisión y geocerca (Haversine).
/// Centro y radio de una sede (para confirmar la ubicación con GPS impreciso).
typedef GeoSite = ({double lat, double lng, double radius});

class LocationService {
  LocationService({this._integrity = const LocationIntegrityChannel()});

  static const double _earthRadiusMeters = 6371000;

  /// Lecturas por marcación, ventana para tomarlas y precisión que ya basta.
  static const int maxSamples = 6;
  static const Duration sampleWindow = Duration(seconds: 20);
  static const double goodEnoughMeters = 15;

  final LocationIntegrityChannel _integrity;

  Future<LocationFix> getVerifiedFix({
    int? maxAccuracyMeters,
    MockLocationPolicy mockPolicy = MockLocationPolicy.reject,
    List<GeoSite> sites = const [],
  }) async {
    final maxAccuracy = maxAccuracyMeters ?? Env.maxGpsAccuracyMeters;
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

    final List<Position> samples;
    final simulated = _integrity.isSimulated();
    try {
      samples = await _sample(maxAccuracy: maxAccuracy, sites: sites);
    } on Exception catch (e) {
      if (e is LocationServiceDisabledException) {
        throw const LocationException(LocationFailure.serviceDisabled, 'El GPS se desactivó durante la lectura.');
      }
      throw const LocationException(
        LocationFailure.timeout,
        'No se pudo obtener tu ubicación a tiempo. Sal a un lugar despejado e inténtalo de nuevo.',
      );
    }

    final position = bestSample(samples);
    // Una sola lectura simulada basta para tratar la marcación como simulada
    final isMocked = samples.any((p) => p.isMocked) || await simulated;
    if (decideMock(isMocked: isMocked, policy: mockPolicy) == MockDecision.block) {
      throw LocationException(
        LocationFailure.mocked,
        'Se detectó una ubicación simulada y tu empresa no permite marcar así. Desactiva cualquier app de '
        'GPS falso o las opciones de desarrollador de ubicación y vuelve a intentarlo.',
        position: position,
        isMocked: true,
      );
    }

    if (position.accuracy > maxAccuracy && !confirmedInside(position, sites)) {
      throw LocationException(
        LocationFailure.lowAccuracy,
        'La señal GPS es imprecisa (±${position.accuracy.round()} m). Acércate a una ventana o sal al exterior y reintenta.',
        position: position,
        isMocked: isMocked,
      );
    }

    return LocationFix(position, isMocked: isMocked);
  }

  Future<void> openSettings(LocationFailure failure) => failure == LocationFailure.serviceDisabled
      ? Geolocator.openLocationSettings()
      : Geolocator.openAppSettings();

  /// La primera lectura espera hasta [Env.gpsTimeout] (si falla, falla la
  /// marcación); las siguientes solo lo que quede de [sampleWindow] y su
  /// error se ignora: ya hay una lectura válida.
  /// Sigue leyendo mientras la mejor lectura no sea aceptable (precisión
  /// dentro del máximo o círculo de error dentro de la sede).
  Future<List<Position>> _sample({required int maxAccuracy, required List<GeoSite> sites}) async {
    final samples = [await Geolocator.getCurrentPosition(locationSettings: _settings(Env.gpsTimeout))];
    final deadline = DateTime.now().add(sampleWindow);
    bool settled() {
      final best = bestSample(samples);
      return best.accuracy <= goodEnoughMeters || (best.accuracy <= maxAccuracy && samples.length >= 2) || confirmedInside(best, sites);
    }

    while (samples.length < maxSamples && !settled()) {
      final left = deadline.difference(DateTime.now());
      if (left <= Duration.zero) break;
      try {
        samples.add(await Geolocator.getCurrentPosition(locationSettings: _settings(left)));
      } on Exception {
        break;
      }
    }
    return samples;
  }

  /// ¿Todo el círculo de error de la lectura cae dentro de alguna sede?
  /// (distancia al centro + precisión ≤ radio). Misma regla que el backend.
  static bool confirmedInside(Position p, List<GeoSite> sites) =>
      sites.any((s) => haversineMeters(p.latitude, p.longitude, s.lat, s.lng) + p.accuracy <= s.radius);

  /// La lectura más precisa (menor `accuracy`); ante empate, la más reciente.
  @visibleForTesting
  static Position bestSample(List<Position> samples) =>
      samples.reduce((best, p) => p.accuracy <= best.accuracy ? p : best);

  LocationSettings _settings(Duration timeLimit) {
    if (defaultTargetPlatform == TargetPlatform.android) {
      // bestForNavigation: GPS + Wi-Fi + redes (fused) con la máxima prioridad
      return AndroidSettings(accuracy: LocationAccuracy.bestForNavigation, timeLimit: timeLimit);
    }
    if (defaultTargetPlatform == TargetPlatform.iOS) {
      return AppleSettings(accuracy: LocationAccuracy.bestForNavigation, timeLimit: timeLimit);
    }
    return LocationSettings(accuracy: LocationAccuracy.best, timeLimit: timeLimit);
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
