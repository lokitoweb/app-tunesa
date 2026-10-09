/// Estado actual de la asistencia diaria del colaborador
class AttendanceStatusModel {
  final int? id;
  final String nextAction; // 'clock_in' | 'break_or_clock_out' | 'break_end' | 'clock_out' | 'completed'
  final DateTime? clockInAt;
  final DateTime? breakStartAt;
  final DateTime? breakEndAt;
  final DateTime? clockOutAt;
  final String? status;
  final int lateMinutes;
  final double workedHours;
  final double? clockInDistance;
  final bool withinGeofence;
  final String? clockInPhotoUrl;

  AttendanceStatusModel({
    this.id,
    required this.nextAction,
    this.clockInAt,
    this.breakStartAt,
    this.breakEndAt,
    this.clockOutAt,
    this.status,
    this.lateMinutes = 0,
    this.workedHours = 0.0,
    this.clockInDistance,
    this.withinGeofence = false,
    this.clockInPhotoUrl,
  });

  bool get hasClockedIn => clockInAt != null;
  bool get isOnBreak => breakStartAt != null && breakEndAt == null;
  bool get isCompleted => nextAction == 'completed' || clockOutAt != null;

  factory AttendanceStatusModel.fromJson(Map<String, dynamic> json) {
    DateTime? parseDate(dynamic val) {
      if (val == null) return null;
      try {
        return DateTime.parse(val.toString());
      } catch (_) {
        return null;
      }
    }

    return AttendanceStatusModel(
      id: json['id'],
      nextAction: json['next_action'] ?? 'clock_in',
      clockInAt: parseDate(json['clock_in_at']),
      breakStartAt: parseDate(json['break_start_at']),
      breakEndAt: parseDate(json['break_end_at']),
      clockOutAt: parseDate(json['clock_out_at']),
      status: json['status'],
      lateMinutes: json['late_minutes'] ?? 0,
      workedHours: double.tryParse(json['worked_hours']?.toString() ?? '0') ?? 0.0,
      clockInDistance: double.tryParse(json['clock_in_distance_meters']?.toString() ?? '0'),
      withinGeofence: json['clock_in_within_geofence'] == true,
      clockInPhotoUrl: json['clock_in_photo_url'],
    );
  }
}
