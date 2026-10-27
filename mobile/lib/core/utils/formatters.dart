import 'package:intl/intl.dart';

/// Formatos de fecha/hora en español (Perú).
class Fmt {
  const Fmt._();

  static final _date = DateFormat('dd/MM/yyyy', 'es');
  static final _dayLong = DateFormat("EEEE d 'de' MMMM", 'es');
  static final _time = DateFormat('hh:mm', 'es');
  static final _apiDate = DateFormat('yyyy-MM-dd');
  static final _isoLocal = DateFormat("yyyy-MM-dd'T'HH:mm:ss");

  static String date(DateTime? d) => d == null ? '—' : _date.format(d.toLocal());
  static String dayLong(DateTime d) {
    final s = _dayLong.format(d);
    return s[0].toUpperCase() + s.substring(1);
  }

  /// Hora en formato de 12 horas: "08:00 am", "05:30 pm".
  static String time(DateTime? d) {
    if (d == null) return '--:--';
    final local = d.toLocal();
    return '${_time.format(local)} ${local.hour < 12 ? 'am' : 'pm'}';
  }

  /// Hora de un turno o break que llega como texto "HH:mm[:ss]" (24 h) en
  /// formato de 12 horas. Si no se entiende, la devuelve tal cual.
  static String clock(String? hhmm) {
    if (hhmm == null || hhmm.isEmpty) return '--:--';
    final parts = hhmm.split(':');
    final h = int.tryParse(parts[0]);
    final m = parts.length > 1 ? int.tryParse(parts[1]) : 0;
    if (h == null || m == null) return hhmm;
    return time(DateTime(2000, 1, 1, h, m));
  }
  static String apiDate(DateTime d) => _apiDate.format(d);

  /// Duración legible: 45 → "45 min", 60 → "1 h", 70 → "1 h 10 min", 626 → "10 h 26 min".
  static String minutes(int? total) {
    final m = (total ?? 0) < 0 ? 0 : (total ?? 0);
    if (m < 60) return '$m min';
    final h = m ~/ 60, rest = m % 60;
    return rest == 0 ? '$h h' : '$h h $rest min';
  }

  /// ISO 8601 en hora local con desfase: 2026-10-09T08:01:02-05:00.
  static String isoWithOffset(DateTime d) {
    final local = d.toLocal();
    final offset = local.timeZoneOffset;
    final sign = offset.isNegative ? '-' : '+';
    final minutes = offset.inMinutes.abs();
    String two(int n) => n.toString().padLeft(2, '0');
    return '${_isoLocal.format(local)}$sign${two(minutes ~/ 60)}:${two(minutes % 60)}';
  }

  static DateTime? parse(Object? value) => value == null ? null : DateTime.tryParse(value.toString());
}
