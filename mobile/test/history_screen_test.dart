import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:tunesa_mobile/core/theme/app_theme.dart';
import 'package:tunesa_mobile/features/attendance/domain/models.dart';
import 'package:tunesa_mobile/features/attendance/presentation/attendance_providers.dart';
import 'package:tunesa_mobile/features/attendance/presentation/history_screen.dart';
import 'package:tunesa_mobile/features/attendance/presentation/overtime_screen.dart';
import 'package:tunesa_mobile/features/onboarding/presentation/onboarding_providers.dart';

void main() {
  setUpAll(() => initializeDateFormatting('es'));

  final now = DateTime.now();
  final day = DateTime(now.year, now.month, 1);
  final history = AttendanceHistory([
    Attendance(
      id: 1,
      workDate: day,
      status: 'present',
      statusLabel: 'Normal', // etiqueta que envía hoy el API
      clockIn: DateTime(day.year, day.month, day.day, 8, 0),
      clockOut: DateTime(day.year, day.month, day.day, 17, 30),
      workedHours: 8.75,
      overtimeHours: 0.5,
      overtimeStatus: 'pending',
    ),
    Attendance(
      id: 2,
      workDate: day.add(const Duration(days: 1)),
      status: 'late',
      statusLabel: 'Tardanza',
      clockIn: DateTime(day.year, day.month, day.day + 1, 8, 20),
      clockOut: DateTime(day.year, day.month, day.day + 1, 17, 0),
      lateMinutes: 20,
      workedHours: 7.9,
      overtimeHours: 1,
      overtimeStatus: 'approved',
      overtimeApprovedHours: 1,
    ),
  ], const HistorySummary(present: 1, late: 1, totalHours: 16.6));

  Widget app(Widget home, {bool canPunch = true}) => ProviderScope(
        overrides: [
          canPunchProvider.overrideWith((ref) => canPunch),
          attendanceHistoryProvider.overrideWith((ref, period) async => history),
        ],
        child: MaterialApp(theme: AppTheme.light(), home: home),
      );

  testWidgets('Mis asistencias: tarjeta por día con ENTRADA y SALIDA en 12 h y su estado', (tester) async {
    await tester.pumpWidget(app(const HistoryScreen()));
    await tester.pump();

    expect(find.text('ENTRADA'), findsNWidgets(2));
    expect(find.text('SALIDA'), findsNWidgets(2));
    expect(find.text('08:00 am'), findsOneWidget);
    expect(find.text('05:30 pm'), findsOneWidget);
    expect(find.text('Normal'), findsWidgets); // estado del día y total del resumen
    expect(find.text('Tardanza'), findsWidgets);
    expect(find.byKey(const Key('open-overtime')), findsOneWidget);
  });

  testWidgets('sin incorporación aprobada no se ve la asistencia', (tester) async {
    await tester.pumpWidget(app(const HistoryScreen(), canPunch: false));
    await tester.pump();

    expect(find.byKey(const Key('onboarding-gate')), findsOneWidget);
    expect(find.text('ENTRADA'), findsNothing);
  });

  testWidgets('Mis horas extra: totales por estado y cada día', (tester) async {
    await tester.pumpWidget(app(const OvertimeScreen()));
    await tester.pump();

    expect(find.byKey(const Key('overtime-summary')), findsOneWidget);
    expect(find.text('Por aprobar'), findsNWidgets(2));
    expect(find.text('Aprobadas'), findsNWidgets(2));
    expect(find.text('0.5 h'), findsNWidgets(2));
    expect(find.text('1 h'), findsNWidgets(2));
  });
}
