import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:tunesa_mobile/features/attendance/domain/models.dart';
import 'package:tunesa_mobile/features/attendance/presentation/clock_screen.dart';
import 'package:tunesa_mobile/features/attendance/presentation/punch_board.dart';

Attendance _day({DateTime? clockIn, DateTime? clockOut, String? breakStart, String? breakEnd, bool onBreak = false}) =>
    Attendance(
      id: 1,
      workDate: DateTime(2026, 10, 10),
      status: 'present',
      statusLabel: 'Presente',
      clockIn: clockIn,
      clockOut: clockOut,
      breakStart: breakStart,
      breakEnd: breakEnd,
      isOnBreak: onBreak,
    );

void main() {
  final eight = DateTime(2026, 10, 10, 8, 13);
  setUpAll(() => initializeDateFormatting('es'));

  test('cada marcación bloqueada explica qué falta', () {
    const none = TodayStatus(actions: [ClockAction.clockIn]);
    expect(PunchBoard.lockedReason(ClockAction.breakStart, none), 'Primero marca tu hora de entrada.');
    expect(PunchBoard.lockedReason(ClockAction.clockOut, none), 'Primero marca tu hora de entrada.');

    final working = TodayStatus(attendance: _day(clockIn: eight), actions: const [ClockAction.breakStart, ClockAction.clockOut]);
    expect(PunchBoard.lockedReason(ClockAction.breakEnd, working), 'Primero marca el inicio de tu break.');

    final onBreak = TodayStatus(
      attendance: _day(clockIn: eight, breakStart: '12:03', onBreak: true),
      actions: const [ClockAction.breakEnd],
    );
    expect(PunchBoard.lockedReason(ClockAction.clockOut, onBreak), 'Marca el fin de tu break para continuar.');

    final done = TodayStatus(attendance: _day(clockIn: eight, clockOut: DateTime(2026, 10, 10, 18)), actions: const []);
    expect(PunchBoard.lockedReason(ClockAction.breakStart, done), 'Ya completaste tus marcaciones de hoy.');
  });

  testWidgets('muestra las cuatro marcaciones con su hora y solo abre la disponible', (tester) async {
    final opened = <ClockAction>[];
    final status = TodayStatus(
      attendance: _day(clockIn: eight, breakStart: '12:03', onBreak: true),
      actions: const [ClockAction.breakEnd],
    );
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(body: PunchBoard(status: status, onPunch: opened.add)),
    ));

    expect(find.text('Marcación incompleta'), findsOneWidget);
    for (final label in ['Entrada', 'Inicio break', 'Fin break', 'Salida']) {
      expect(find.text(label), findsOneWidget);
    }
    expect(find.text('08:13 am'), findsOneWidget);
    expect(find.text('12:03 pm'), findsOneWidget);
    expect(find.text('--:--'), findsNWidgets(2));

    // Salida sin terminar el break: aviso, no abre la pantalla de marcación
    await tester.tap(find.byKey(const Key('punch-clock_out')));
    await tester.pump();
    expect(opened, isEmpty);
    expect(find.text('Marca el fin de tu break para continuar.'), findsOneWidget);

    await tester.tap(find.byKey(const Key('punch-break_end')));
    expect(opened, [ClockAction.breakEnd]);
  });

  test('distancia legible a la sede', () {
    expect(formatDistance(85), '85 m');
    expect(formatDistance(1530), '1,5 km');
    expect(formatDistance(493532), '494 km');
  });

  test('lee el bloqueo por turno de /attendance/today', () {
    expect(TodayStatus.parseNotScheduled({'can_clock_in': true}), isNull);
    expect(TodayStatus.parseNotScheduled(null), isNull);
    expect(TodayStatus.parseNotScheduled({'can_clock_in': false, 'message': 'Hoy no tienes turno programado. Pide a RR. HH.'}),
        'Hoy no tienes turno programado. Pide a RR. HH.');
    expect(TodayStatus.parseNotScheduled({'can_clock_in': false}), TodayStatus.notScheduledFallback);
  });

  testWidgets('sin turno hoy: botones bloqueados y el motivo a la vista', (tester) async {
    final opened = <ClockAction>[];
    const status = TodayStatus(actions: [], notScheduledMessage: 'Hoy no tienes turno programado. Si debes trabajar, pide a RR. HH. que autorice tu marcación.');
    await tester.pumpWidget(MaterialApp(home: Scaffold(body: PunchBoard(status: status, onPunch: opened.add))));

    expect(find.text('Hoy no tienes turno programado'), findsOneWidget);
    expect(find.byKey(const Key('punch-not-scheduled')), findsOneWidget);

    await tester.tap(find.byKey(const Key('punch-clock_in')));
    await tester.pump();
    expect(opened, isEmpty);
    // El aviso al tocar repite el motivo (además del recuadro)
    expect(find.textContaining('pide a RR. HH. que autorice'), findsNWidgets(2));
  });

  test('si ya marcó entrada (autorizado), el bloqueo no aplica', () {
    final s = TodayStatus(attendance: _day(clockIn: eight), actions: const [ClockAction.clockOut], notScheduledMessage: 'x');
    expect(s.isNotScheduled, isFalse);
  });
}
