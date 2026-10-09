/// Comparación de versiones "1.2.3" (ignora `+build` y `-prerelease`).
class Semver {
  const Semver._();

  static List<int> _parts(String version) {
    final core = version.trim().split('+').first.split('-').first;
    return core.split('.').map((p) => int.tryParse(p.trim()) ?? 0).toList();
  }

  /// < 0 si [a] es anterior a [b], 0 si son iguales, > 0 si es posterior.
  static int compare(String a, String b) {
    final pa = _parts(a);
    final pb = _parts(b);
    final length = pa.length > pb.length ? pa.length : pb.length;
    for (var i = 0; i < length; i++) {
      final x = i < pa.length ? pa[i] : 0;
      final y = i < pb.length ? pb[i] : 0;
      if (x != y) return x < y ? -1 : 1;
    }
    return 0;
  }

  /// true si [current] es anterior a [target]. Un [target] vacío o null nunca obliga.
  static bool isLower(String current, String? target) =>
      target != null && target.trim().isNotEmpty && compare(current, target) < 0;
}
