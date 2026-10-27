import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:tunesa_mobile/core/utils/formatters.dart';
import 'package:tunesa_mobile/features/attendance/domain/models.dart';
import 'package:tunesa_mobile/features/home/presentation/home_screen.dart';

DaySchedule _day({bool works = true, String? name, String? branch, String? start = '08:00', String? end = '13:00'}) =>
    DaySchedule(date: DateTime(2026, 10, 26), isWorkday: works, shiftName: name, start: start, end: end, branch: branch);

Future<void> _pump(WidgetTester tester, DaySchedule? schedule) => tester.pumpWidget(
      MaterialApp(home: Scaffold(body: ShiftCard(schedule: schedule))),
    );

void main() {
  setUpAll(() => initializeDateFormatting('es'));

  group('Nombre del horario sin repetir la hora', () {
    test('quita el rango de horas del final, con cualquier nombre', () {
      expect(_day(name: 'TR-ADM-SB(08:00-13:00)').displayName, 'TR-ADM-SB');
      expect(_day(name: 'Turno Mañana (08:00 - 17:00)').displayName, 'Turno Mañana');
      expect(_day(name: 'Caja [8.00–16.30]').displayName, 'Caja');
      expect(_day(name: 'Noche 22:00 a 06:00').displayName, 'Noche 22:00 a 06:00'); // sin paréntesis: no se toca
    });

    test('deja el nombre si no trae hora, si es solo el rango o si no hay nombre', () {
      expect(_day(name: 'Administrativo').displayName, 'Administrativo');
      expect(_day(name: '(08:00-13:00)').displayName, '(08:00-13:00)');
      expect(_day(name: '  ').displayName, isNull);
      expect(_day().displayName, isNull);
    });
  });

  test('los minutos se muestran en horas y minutos', () {
    expect(Fmt.minutes(0), '0 min');
    expect(Fmt.minutes(45), '45 min');
    expect(Fmt.minutes(60), '1 h');
    expect(Fmt.minutes(70), '1 h 10 min');
    expect(Fmt.minutes(626), '10 h 26 min');
    expect(Fmt.minutes(null), '0 min');
    expect(Fmt.minutes(-5), '0 min');
  });

  test('el día de hoy es el de la empresa (server_time), no el del teléfono', () {
    // 23:50 en Lima es ya el día siguiente en UTC: vale la fecha de la empresa
    expect(TodayStatus.companyDate('2026-10-26T23:50:00-05:00'), '2026-10-26');
    expect(TodayStatus.companyDate('2026-10-27T00:05:00+09:00'), '2026-10-27');
    expect(TodayStatus.companyDate(null), isNull);
    expect(TodayStatus.companyDate('no es fecha'), isNull);
    expect(TodayStatus.companyDate('2026-10-26'), isNull, reason: 'sin hora no se sabe la zona');
  });

  group('Tarjeta "Tu horario de hoy"', () {
    testWidgets('con horario: etiqueta, la hora una sola vez, y nombre con agencia', (tester) async {
      await _pump(tester, _day(name: 'TR-ADM-SB(08:00-13:00)', branch: 'AG. CAJABAMBA'));

      expect(find.text('Tu horario de hoy'), findsOneWidget);
      final title = tester.widget<Text>(find.descendant(of: find.byKey(const Key('shift-title')), matching: find.byType(Text))).data!;
      expect(title, contains('–'));
      expect(title, isNot(contains('ADM')));
      expect(find.text('TR-ADM-SB · AG. CAJABAMBA'), findsOneWidget);
      expect(find.textContaining('08:00-13:00'), findsNothing, reason: 'la hora no se repite en el nombre');
    });

    testWidgets('sin agencia ni nombre: no deja huecos ni puntos sueltos', (tester) async {
      await _pump(tester, _day(name: null, branch: null));
      expect(find.text('Agencia asignada'), findsOneWidget);
      await _pump(tester, _day(name: 'Administrativo', branch: null));
      expect(find.text('Administrativo'), findsOneWidget);
    });

    testWidgets('día libre o sin horario: mensaje claro', (tester) async {
      await _pump(tester, _day(works: false, start: null, end: null));
      expect(find.text('Hoy'), findsOneWidget);
      expect(find.text('Sin horario programado'), findsOneWidget);
      expect(find.text('Día libre o sin horario asignado para hoy.'), findsOneWidget);

      await _pump(tester, null);
      expect(find.text('Sin horario programado'), findsOneWidget);
    });

    testWidgets('pantalla angosta y texto grande: no se desborda', (tester) async {
      tester.view.physicalSize = const Size(320 * 2, 640 * 2);
      tester.view.devicePixelRatio = 2;
      addTearDown(tester.view.reset);
      await tester.pumpWidget(MediaQuery(
        data: const MediaQueryData(textScaler: TextScaler.linear(1.6)),
        child: MaterialApp(home: Scaffold(body: ShiftCard(schedule: _day(name: 'Horario administrativo de lunes a viernes', branch: 'AG. TRUJILLO - AV CESAR VALLEJO')))),
      ));
      expect(tester.takeException(), isNull);
    });
  });
}
