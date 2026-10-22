import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../providers.dart';

/// Mes elegido en un listado (Mis asistencias, Mis horas extra, Cumpleaños).
///
/// Vive fuera de la pantalla: se conserva al cambiar de pestaña, abrir otra
/// pantalla y volver, o minimizar la app. También se guarda en el teléfono
/// para recuperarlo si el sistema cerró la app en segundo plano; vence a las
/// [ttl] horas, así al día siguiente el listado vuelve al mes actual.
class MonthFilter extends Notifier<DateTime> {
  MonthFilter(this.key, {DateTime Function()? now}) : _now = now ?? DateTime.now;

  final String key;
  final DateTime Function() _now;

  static const ttl = Duration(hours: 8);

  String get _prefKey => 'list_filter.$key.month';

  DateTime get _current {
    final now = _now();
    return DateTime(now.year, now.month);
  }

  @override
  DateTime build() => _restore() ?? _current;

  /// Formato guardado: `2026-10|2026-10-22T01:00:00.000Z` (mes | guardado en).
  DateTime? _restore() {
    try {
      final raw = ref.read(sharedPreferencesProvider).getString(_prefKey);
      final parts = raw?.split('|');
      if (parts == null || parts.length != 2) return null;
      final savedAt = DateTime.tryParse(parts[1]);
      final month = DateTime.tryParse('${parts[0]}-01');
      if (savedAt == null || month == null || _now().difference(savedAt) > ttl) return null;
      return DateTime(month.year, month.month);
    } catch (_) {
      return null; // sin preferencias (pruebas): solo en memoria
    }
  }

  void select(DateTime month) {
    state = DateTime(month.year, month.month);
    try {
      final m = state.month.toString().padLeft(2, '0');
      ref.read(sharedPreferencesProvider).setString(_prefKey, '${state.year}-$m|${_now().toUtc().toIso8601String()}');
    } catch (_) {
      // solo en memoria
    }
  }

  void shift(int delta) => select(DateTime(state.year, state.month + delta));
}

final monthFilterProvider = NotifierProvider.family<MonthFilter, DateTime, String>(MonthFilter.new);
