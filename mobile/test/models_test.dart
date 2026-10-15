import 'package:flutter_test/flutter_test.dart';
import 'package:tunesa_mobile/features/attendance/domain/models.dart';
import 'package:tunesa_mobile/features/requests/domain/models.dart';

void main() {
  group('TodayStatus.parseActions', () {
    test('sin marcación: solo entrada', () {
      expect(TodayStatus.parseActions({'action': 'clock_in', 'label': 'Marcar Entrada'}), [ClockAction.clockIn]);
    });

    test('en jornada: descanso o salida', () {
      final actions = TodayStatus.parseActions({
        'action': 'break_or_clock_out',
        'actions': [
          {'action': 'break_start'},
          {'action': 'clock_out'},
        ],
      });
      expect(actions, [ClockAction.breakStart, ClockAction.clockOut]);
    });

    test('jornada completa o acción desconocida: sin botones', () {
      expect(TodayStatus.parseActions({'action': 'done'}), isEmpty);
      expect(TodayStatus.parseActions(null), isEmpty);
    });

    test('solo entrada y salida exigen selfie', () {
      expect(ClockAction.values.where((a) => a.requiresSelfie), [ClockAction.clockIn, ClockAction.clockOut]);
    });
  });

  group('Attendance.fromJson', () {
    final json = {
      'id': 7,
      'work_date': '2026-10-08',
      'status': 'late',
      'status_label': 'Tardanza',
      'is_on_break': false,
      'clock_in': {'time': '08:17:00', 'timestamp': '2026-10-08T08:17:00-05:00'},
      'break': {'start_time': '13:00:00', 'end_time': null, 'duration_min': null},
      'summary': {'late_minutes': 17, 'worked_hours': 0},
      'justification': {'status': null, 'notes': null},
      'branch': {'id': 1, 'name': 'Sede Central'},
    };

    test('la jornada cuenta como máximo el turno; el exceso es hora extra', () {
      final long = Map<String, dynamic>.from(json)..['summary'] = {'worked_hours': 10.0, 'overtime_hours': 2.0};
      expect(Attendance.fromJson(long).regularHours, 8.0);
      final short = Map<String, dynamic>.from(json)..['summary'] = {'worked_hours': 6.5, 'overtime_hours': 0};
      expect(Attendance.fromJson(short).regularHours, 6.5);
    });

    test('lee horas, tardanza y sucursal', () {
      final a = Attendance.fromJson(json);
      expect(a.id, 7);
      expect(a.lateMinutes, 17);
      expect(a.breakStart, '13:00');
      expect(a.clockIn, isNotNull);
      expect(a.clockOut, isNull);
      expect(a.branchName, 'Sede Central');
    });

    test('una tardanza sin justificar se puede justificar; una aprobada no', () {
      expect(Attendance.fromJson(json).canJustify, isTrue);
      final approved = Map<String, dynamic>.from(json)..['justification'] = {'status': 'approved'};
      expect(Attendance.fromJson(approved).canJustify, isFalse);
      final present = Map<String, dynamic>.from(json)..['status'] = 'present';
      expect(Attendance.fromJson(present).canJustify, isFalse);
    });

    test('horas extra: por aprobar, aprobadas (parciales) o rechazadas', () {
      Attendance withOvertime(String? status, [double? approved]) => Attendance.fromJson(Map<String, dynamic>.from(json)
        ..['summary'] = {'late_minutes': 0, 'worked_hours': 10.5, 'overtime_hours': 2.5, 'overtime_status': status, 'overtime_approved_hours': approved});

      expect(Attendance.fromJson(json).overtimeLabel, isNull);
      expect(withOvertime(null).overtimeLabel, isNull);
      expect(withOvertime('pending').overtimeLabel, '2.5 h extra por aprobar');
      expect(withOvertime('approved', 2).overtimeLabel, '2 h extra aprobadas');
      expect(withOvertime('rejected').overtimeLabel, 'Horas extra no aprobadas');
    });
  });

  group('LeaveRequest', () {
    test('licencia médica exige adjunto', () {
      expect(LeaveType.medicalLeave.requiresAttachment, isTrue);
      expect(LeaveType.vacation.requiresAttachment, isFalse);
    });

    test('fromJson y etiquetas de estado', () {
      final r = LeaveRequest.fromJson({
        'id': 3,
        'type': 'vacation',
        'type_label': 'Vacaciones',
        'start_date': '2026-11-02',
        'end_date': '2026-11-06',
        'days': 5,
        'reason': 'Viaje familiar',
        'status': 'pending',
      });
      expect(r.type, LeaveType.vacation);
      expect(r.days, 5);
      expect(r.isPending, isTrue);
      expect(r.statusLabel, 'Pendiente');
    });
  });
}
