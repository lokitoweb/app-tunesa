import 'package:intl/intl.dart';

/// Formatos de fecha/hora en español (Perú).
class Fmt {
  const Fmt._();

  static final _date = DateFormat('dd/MM/yyyy', 'es');
  static final _dayLong = DateFormat("EEEE d 'de' MMMM", 'es');
  static final _time = DateFormat('HH:mm', 'es');
  static final _apiDate = DateFormat('yyyy-MM-dd');
  static final _isoLocal = DateFormat("yyyy-MM-dd'T'HH:mm:ss");

  static String date(DateTime? d) => d == null ? '—' : _date.format(d.toLocal());
  static String dayLong(DateTime d) {
    final s = _dayLong.format(d);
    return s[0].toUpperCase() + s.substring(1);
  }

  static String time(DateTime? d) => d == null ? '--:--' : _time.format(d.toLocal());
  static String apiDate(DateTime d) => _apiDate.format(d);

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
