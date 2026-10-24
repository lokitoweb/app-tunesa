import 'dart:math';

import '../../../core/utils/formatters.dart';

/// Acciones de marcación. Entrada y salida exigen selfie; el descanso solo GPS.
enum ClockAction {
  clockIn('clock_in', 'Marcar entrada', requiresSelfie: true),
  breakStart('break_start', 'Iniciar descanso', requiresSelfie: false),
  breakEnd('break_end', 'Terminar descanso', requiresSelfie: false),
  clockOut('clock_out', 'Marcar salida', requiresSelfie: true);

  const ClockAction(this.apiName, this.label, {required this.requiresSelfie});

  final String apiName;
  final String label;
  final bool requiresSelfie;

  static ClockAction? fromApi(String? name) {
    for (final a in values) {
      if (a.apiName == name) return a;
    }
    return null;
  }
}

/// Registro de asistencia de un día (AttendanceResource).
class Attendance {
  const Attendance({
    required this.id,
    required this.workDate,
    required this.status,
    required this.statusLabel,
    this.clockIn,
    this.clockOut,
    this.breakStart,
    this.breakEnd,
    this.isOnBreak = false,
    this.lateMinutes = 0,
    this.workedHours = 0,
    this.branchName,
    this.shiftName,
    this.justificationStatus,
    this.justificationNotes,
    this.overtimeHours = 0,
    this.overtimeStatus,
    this.overtimeApprovedHours,
  });

  final int id;
  final DateTime workDate;
  final String status;
  final String statusLabel;
  final DateTime? clockIn;
  final DateTime? clockOut;
  final String? breakStart;
  final String? breakEnd;
  final bool isOnBreak;
  final int lateMinutes;
  final double workedHours;

  /// Horas de jornada: lo trabajado con el turno como tope (8 h aunque se
  /// quede más). El exceso va en [overtimeHours] y cuenta solo si se aprueba.
  double get regularHours => (workedHours - overtimeHours).clamp(0, double.infinity).toDouble();
  final String? branchName;
  final String? shiftName;
  final String? justificationStatus;
  final String? justificationNotes;

  /// Horas extra calculadas y su aprobación por jefatura
  /// (null: no aplica; pending | approved | rejected).
  final double overtimeHours;
  final String? overtimeStatus;
  final double? overtimeApprovedHours;

  /// Texto corto para el historial, o null si no hubo horas extra que aprobar.
  String? get overtimeLabel {
    String h(double v) => '${v.toStringAsFixed(2).replaceFirst(RegExp(r'\.?0+$'), '')} h';
    return switch (overtimeStatus) {
      'pending' => '${h(overtimeHours)} extra por aprobar',
      'approved' => '${h(overtimeApprovedHours ?? overtimeHours)} extra aprobadas',
      'rejected' => 'Horas extra no aprobadas',
      _ => null,
    };
  }

  /// Tardanzas, faltas y jornadas incompletas se pueden justificar mientras
  /// la justificación no esté aprobada.
  bool get canJustify =>
      const {'late', 'absent', 'incomplete', 'half_day'}.contains(status) && justificationStatus != 'approved';

  factory Attendance.fromJson(Map<String, dynamic> json) {
    final clockIn = json['clock_in'] as Map?;
    final clockOut = json['clock_out'] as Map?;
    final brk = json['break'] as Map?;
    final summary = json['summary'] as Map?;
    final justification = json['justification'] as Map?;

    return Attendance(
      id: (json['id'] as num).toInt(),
      workDate: DateTime.parse(json['work_date'] as String),
      status: json['status']?.toString() ?? '',
      statusLabel: json['status_label']?.toString() ?? '',
      clockIn: Fmt.parse(clockIn?['timestamp']),
      clockOut: Fmt.parse(clockOut?['timestamp']),
      breakStart: (brk?['start_time'] as String?)?.substring(0, 5),
      breakEnd: (brk?['end_time'] as String?)?.substring(0, 5),
      isOnBreak: json['is_on_break'] == true,
      lateMinutes: (summary?['late_minutes'] as num?)?.toInt() ?? 0,
      workedHours: (summary?['worked_hours'] as num?)?.toDouble() ?? 0,
      branchName: (json['branch'] as Map?)?['name'] as String?,
      shiftName: (json['shift'] as Map?)?['name'] as String?,
      justificationStatus: justification?['status'] as String?,
      justificationNotes: justification?['notes'] as String?,
      overtimeHours: (summary?['overtime_hours'] as num?)?.toDouble() ?? 0,
      overtimeStatus: summary?['overtime_status'] as String?,
      overtimeApprovedHours: (summary?['overtime_approved_hours'] as num?)?.toDouble(),
    );
  }
}

/// Turno programado para un día (GET /schedule).
class DaySchedule {
  const DaySchedule({required this.date, required this.isWorkday, this.shiftName, this.start, this.end, this.branch});

  final DateTime date;
  final bool isWorkday;
  final String? shiftName;
  final String? start;
  final String? end;
  final String? branch;

  factory DaySchedule.fromJson(Map<String, dynamic> json) {
    final shift = json['shift'] as Map?;
    return DaySchedule(
      date: DateTime.parse(json['date'] as String),
      isWorkday: json['is_workday'] == true,
      shiftName: shift?['name'] as String?,
      start: shift?['start_time'] as String?,
      end: shift?['end_time'] as String?,
      branch: json['branch'] as String?,
    );
  }
}

/// Estado del día: registro + acciones disponibles según el backend.
class TodayStatus {
  const TodayStatus({this.attendance, required this.actions, this.schedule, this.clockSkew, this.notScheduledMessage});

  final Attendance? attendance;
  final List<ClockAction> actions;
  final DaySchedule? schedule;

  /// Motivo por el que hoy no se puede marcar entrada (día de descanso o sin
  /// programación y sin autorización de RR. HH.). null = puede marcar.
  final String? notScheduledMessage;

  /// Sin turno hoy y sin entrada marcada: los botones quedan bloqueados.
  bool get isNotScheduled => notScheduledMessage != null && attendance?.clockIn == null;

  /// Lee `schedule` de /attendance/today (ScheduleGate del backend).
  static String? parseNotScheduled(Object? schedule) {
    if (schedule is! Map || schedule['can_clock_in'] != false) return null;
    final message = schedule['message']?.toString().trim();
    return (message == null || message.isEmpty) ? notScheduledFallback : message;
  }

  static const notScheduledFallback = 'Hoy no tienes turno programado';

  /// Hora del teléfono menos la del servidor (`server_time`) al recibir la
  /// respuesta. null si el backend no la envía.
  final Duration? clockSkew;

  /// Desfase en segundos si supera [maxSeconds] (`max_time_drift_seconds`).
  int? driftBeyond(int? maxSeconds) {
    final skew = clockSkew;
    if (maxSeconds == null || skew == null) return null;
    final seconds = skew.inSeconds.abs();
    return seconds > maxSeconds ? seconds : null;
  }

  static Duration? skewFrom(Object? serverTime, DateTime deviceNow) {
    final server = DateTime.tryParse(serverTime?.toString() ?? '');
    return server == null ? null : deviceNow.toUtc().difference(server.toUtc());
  }

  bool get isDayComplete => attendance?.clockOut != null;

  /// Interpreta `next_action` de /attendance/today.
  static List<ClockAction> parseActions(Map? next) {
    if (next == null) return const [];
    final nested = next['actions'];
    if (nested is List) {
      return nested.map((a) => ClockAction.fromApi((a as Map)['action'] as String?)).whereType<ClockAction>().toList();
    }
    final single = ClockAction.fromApi(next['action'] as String?);
    return single == null ? const [] : [single];
  }
}

/// Sucursal asignada con su geocerca.
class BranchGeofence {
  const BranchGeofence({
    required this.id,
    required this.name,
    required this.lat,
    required this.lng,
    required this.radius,
    this.tolerance = defaultTolerance,
    this.maxAccuracy,
  });

  /// Margen de borde si el servidor no lo envía (Branch::DEFAULT_EDGE_TOLERANCE).
  static const double defaultTolerance = 15;

  final int id;
  final String name;
  final double lat;
  final double lng;
  final double radius;

  /// Margen máximo fuera del radio que cubre la imprecisión del GPS (m).
  final double tolerance;

  /// Precisión GPS máxima de la sede (null = la de la empresa).
  final int? maxAccuracy;

  /// Misma regla que GeofenceService::check: dentro, o fuera por menos de
  /// min(precisión, tolerancia) — la zona de borde, que el servidor acepta observada.
  bool admits(double distance, double accuracy) => distance <= radius + min(max(accuracy, 0), tolerance);

  factory BranchGeofence.fromJson(Map<String, dynamic> json) {
    final geo = Map<String, dynamic>.from(json['geofence'] as Map);
    return BranchGeofence(
      id: (json['id'] as num).toInt(),
      name: json['name']?.toString() ?? '',
      lat: (geo['latitude'] as num).toDouble(),
      lng: (geo['longitude'] as num).toDouble(),
      radius: (geo['allowed_radius_meters'] as num?)?.toDouble() ?? 100,
      tolerance: (geo['tolerance_meters'] as num?)?.toDouble() ?? defaultTolerance,
      maxAccuracy: (geo['max_gps_accuracy_meters'] as num?)?.toInt(),
    );
  }
}

class HistorySummary {
  const HistorySummary({this.present = 0, this.late = 0, this.absent = 0, this.totalHours = 0});

  final int present;
  final int late;
  final int absent;
  final double totalHours;

  factory HistorySummary.fromJson(Map<String, dynamic> json) => HistorySummary(
        present: (json['present'] as num?)?.toInt() ?? 0,
        late: (json['late'] as num?)?.toInt() ?? 0,
        absent: (json['absent'] as num?)?.toInt() ?? 0,
        totalHours: (json['total_hours'] as num?)?.toDouble() ?? 0,
      );
}

class AttendanceHistory {
  const AttendanceHistory(this.items, this.summary);

  final List<Attendance> items;
  final HistorySummary summary;
}
