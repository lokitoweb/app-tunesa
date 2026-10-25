import '../../attendance/domain/models.dart';
import '../../requests/domain/models.dart';
import '../../../core/utils/json_num.dart';

/// Solicitud de un colaborador pendiente de mi aprobación
/// (GET /approvals/leave-requests: la solicitud + `employee`).
class ApprovalRequest {
  const ApprovalRequest({
    required this.request,
    required this.employeeName,
    this.employeeId,
    this.employeeCode,
    this.createdAt,
  });

  final LeaveRequest request;
  final int? employeeId;
  final String employeeName;
  final String? employeeCode;
  final DateTime? createdAt;

  int get id => request.id;

  factory ApprovalRequest.fromJson(Map<String, dynamic> json) {
    final employee = json['employee'] as Map?;
    return ApprovalRequest(
      request: LeaveRequest.fromJson(json),
      employeeId: asInt(employee?['id']),
      employeeName: employee?['name']?.toString() ?? 'Colaborador',
      employeeCode: employee?['employee_code']?.toString(),
      createdAt: DateTime.tryParse(json['created_at']?.toString() ?? ''),
    );
  }
}

/// Integrante de mi equipo (GET /supervisor/team, UserResource).
class TeamMember {
  const TeamMember({required this.id, required this.fullName, this.employeeCode, this.position});

  final int id;
  final String fullName;
  final String? employeeCode;
  final String? position;

  factory TeamMember.fromJson(Map<String, dynamic> json) => TeamMember(
        id: asInt(json['id'])!,
        fullName: json['full_name']?.toString() ?? '',
        employeeCode: json['employee_code']?.toString(),
        position: json['position']?.toString(),
      );
}

/// Totales del equipo hoy (GET /supervisor/attendance/live → summary).
class TeamSummary {
  const TeamSummary({
    this.teamSize = 0,
    this.present = 0,
    this.late = 0,
    this.onBreak = 0,
    this.clockedOut = 0,
    this.absent = 0,
  });

  final int teamSize;
  final int present;
  final int late;
  final int onBreak;
  final int clockedOut;
  final int absent;

  factory TeamSummary.fromJson(Map<String, dynamic> json) {
    int n(String key) => asInt(json[key]) ?? 0;
    return TeamSummary(
      teamSize: n('team_size'),
      present: n('present'),
      late: n('late'),
      onBreak: n('on_break'),
      clockedOut: n('clocked_out'),
      absent: n('absent'),
    );
  }
}

/// Marcación de hoy de un integrante del equipo (AttendanceResource).
///
/// El recurso solo trae el colaborador si el backend lo incluye (`user` o
/// `employee`); si no viene, la tarjeta muestra la sucursal y el turno.
class TeamAttendance {
  const TeamAttendance({
    required this.attendance,
    this.userId,
    this.userName,
    this.clockInWithinGeofence,
    this.clockOutWithinGeofence,
  });

  final Attendance attendance;
  final int? userId;
  final String? userName;
  final bool? clockInWithinGeofence;
  final bool? clockOutWithinGeofence;

  bool get isLate => attendance.status == 'late' || attendance.lateMinutes > 0;

  /// Alguna marcación de hoy quedó fuera de la geocerca de la sucursal.
  bool get outOfGeofence => clockInWithinGeofence == false || clockOutWithinGeofence == false;

  factory TeamAttendance.fromJson(Map<String, dynamic> json) {
    final user = (json['user'] ?? json['employee']) as Map?;
    bool? within(Object? block) => block is Map ? block['within_geofence'] as bool? : null;

    return TeamAttendance(
      attendance: Attendance.fromJson(json),
      userId: asInt(user?['id']) ?? asInt(json['user_id']),
      userName: (user?['full_name'] ?? user?['name'])?.toString(),
      clockInWithinGeofence: within(json['clock_in']),
      clockOutWithinGeofence: within(json['clock_out']),
    );
  }
}

/// Estado del equipo hoy: totales, marcaciones e integrantes.
class TeamOverview {
  const TeamOverview({
    required this.summary,
    required this.attendances,
    required this.members,
    this.serverTime,
  });

  final TeamSummary summary;
  final List<TeamAttendance> attendances;
  final List<TeamMember> members;
  final DateTime? serverTime;

  /// Marcación de hoy de un integrante, si el backend identifica al colaborador.
  TeamAttendance? attendanceOf(int userId) {
    for (final a in attendances) {
      if (a.userId == userId) return a;
    }
    return null;
  }

  /// true si las marcaciones traen al colaborador y se pueden cruzar con el equipo.
  bool get canMatchMembers => attendances.isNotEmpty && attendances.every((a) => a.userId != null);

  factory TeamOverview.fromJson(Map<String, dynamic> live, Map<String, dynamic> team) => TeamOverview(
        summary: TeamSummary.fromJson(Map<String, dynamic>.from((live['summary'] as Map?) ?? const {})),
        attendances: ((live['attendances'] ?? live['data']) as List? ?? const [])
            .map((e) => TeamAttendance.fromJson(Map<String, dynamic>.from(e as Map)))
            .toList(),
        members: ((team['data'] as List?) ?? const [])
            .map((e) => TeamMember.fromJson(Map<String, dynamic>.from(e as Map)))
            .toList(),
        serverTime: DateTime.tryParse(live['server_time']?.toString() ?? ''),
      );
}
