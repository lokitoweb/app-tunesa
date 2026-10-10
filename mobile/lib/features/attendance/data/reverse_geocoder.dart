import '../../../core/location/location_service.dart';

/// Dirección legible de una coordenada (`GET /geocode/reverse`) con caché en
/// memoria: dentro de [cacheRadiusMeters] de una consulta previa se reusa la
/// misma dirección. Nunca lanza: sin red o sin dirección devuelve null.
class ReverseGeocoder {
  ReverseGeocoder(this._fetch, {this.cacheRadiusMeters = 50, this.maxEntries = 30});

  final Future<String?> Function(double lat, double lng) _fetch;
  final double cacheRadiusMeters;
  final int maxEntries;

  final _cache = <({double lat, double lng, String address})>[];
  final _inFlight = <String, Future<String?>>{};

  /// Dirección guardada cerca de esta coordenada, sin consultar.
  String? cached(double lat, double lng) {
    for (final e in _cache) {
      if (LocationService.haversineMeters(lat, lng, e.lat, e.lng) <= cacheRadiusMeters) return e.address;
    }
    return null;
  }

  Future<String?> resolve(double lat, double lng) async {
    final hit = cached(lat, lng);
    if (hit != null) return hit;
    // ~11 m de rejilla: dos pedidos casi iguales comparten la consulta
    final key = '${lat.toStringAsFixed(4)},${lng.toStringAsFixed(4)}';
    return _inFlight[key] ??= _load(lat, lng).whenComplete(() {
      _inFlight.remove(key);
    });
  }

  Future<String?> _load(double lat, double lng) async {
    try {
      final address = (await _fetch(lat, lng))?.trim();
      if (address == null || address.isEmpty) return null;
      _cache.insert(0, (lat: lat, lng: lng, address: address));
      if (_cache.length > maxEntries) _cache.removeLast();
      return address;
    } catch (_) {
      return null;
    }
  }
}
