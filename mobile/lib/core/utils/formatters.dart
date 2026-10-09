import 'package:intl/intl.dart';

/// Formatos de fecha/hora en español (Perú).
class Fmt {
  const Fmt._();

  static final _date = DateFormat('dd/MM/yyyy', 'es');
  static final _dayLong = DateFormat("EEEE d 'de' MMMM", 'es');
  static final _time = DateFormat('HH:mm', 'es');
  static final _apiDate = DateFormat('yyyy-MM-dd');

  static String date(DateTime? d) => d == null ? '—' : _date.format(d.toLocal());
  static String dayLong(DateTime d) {
    final s = _dayLong.format(d);
    return s[0].toUpperCase() + s.substring(1);
  }

  static String time(DateTime? d) => d == null ? '--:--' : _time.format(d.toLocal());
  static String apiDate(DateTime d) => _apiDate.format(d);

  static DateTime? parse(Object? value) => value == null ? null : DateTime.tryParse(value.toString());
}
