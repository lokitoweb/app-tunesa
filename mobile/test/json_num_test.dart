import 'package:flutter_test/flutter_test.dart';
import 'package:tunesa_mobile/core/utils/json_num.dart';
import 'package:tunesa_mobile/features/payslips/domain/models.dart';

void main() {
  test('asInt / asDouble aceptan números y texto sin lanzar', () {
    expect(asInt(5), 5);
    expect(asInt('7'), 7);
    expect(asInt(' 3.0 '), 3);
    expect(asInt('abc'), isNull);
    expect(asInt(null), isNull);
    expect(asDouble('12.50'), 12.5);
    expect(asDouble(4), 4.0);
    expect(asDouble(true), isNull);
  });

  test('boleta con mes y año como texto (MySQL sin tipos nativos)', () {
    final p = Payslip.fromJson({'id': '12', 'title': 'Boleta', 'period_month': '10', 'period_year': '2026', 'period_label': 'Octubre 2026'});
    expect(p.id, 12);
    expect(p.month, 10);
    expect(p.year, 2026);
  });
}
