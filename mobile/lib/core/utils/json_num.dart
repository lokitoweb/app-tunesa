/// Lectura tolerante de números del JSON: la API puede mandar `5`, `5.0` o
/// `"5"` según el driver de MySQL del servidor. Nunca lanza por el tipo.
int? asInt(Object? v) {
  if (v is int) return v;
  if (v is num) return v.round();
  if (v is String) return int.tryParse(v.trim()) ?? double.tryParse(v.trim())?.round();
  return null;
}

double? asDouble(Object? v) {
  if (v is num) return v.toDouble();
  if (v is String) return double.tryParse(v.trim());
  return null;
}
