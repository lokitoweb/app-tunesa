enum LeaveType {
  vacation('vacation', 'Vacaciones'),
  permission('permission', 'Permiso'),
  medicalLeave('medical_leave', 'Licencia médica');

  const LeaveType(this.apiName, this.label);

  final String apiName;
  final String label;

  /// La licencia médica exige certificado adjunto (igual que el backend).
  bool get requiresAttachment => this == LeaveType.medicalLeave;

  static LeaveType fromApi(String? name) => values.firstWhere((t) => t.apiName == name, orElse: () => LeaveType.permission);
}

/// Solicitud de vacaciones, permiso o licencia (flujo pendiente → aprobado/rechazado).
class LeaveRequest {
  const LeaveRequest({
    required this.id,
    required this.type,
    required this.typeLabel,
    required this.startDate,
    required this.endDate,
    required this.days,
    required this.reason,
    required this.status,
    this.startTime,
    this.endTime,
    this.reviewNotes,
    this.reviewer,
    this.reviewers,
    this.hasAttachment = false,
  });

  final int id;
  final LeaveType type;
  final String typeLabel;
  final DateTime startDate;
  final DateTime endDate;
  final double days;
  final String reason;
  final String status;
  final String? startTime;
  final String? endTime;
  final String? reviewNotes;
  final String? reviewer;

  /// Quién la revisa mientras está pendiente (jefe inmediato o RR. HH.)
  final String? reviewers;
  final bool hasAttachment;

  bool get isPending => status == 'pending';

  String get statusLabel => switch (status) {
        'pending' => 'Pendiente',
        'approved' => 'Aprobada',
        'rejected' => 'Rechazada',
        'cancelled' => 'Cancelada',
        _ => status,
      };

  factory LeaveRequest.fromJson(Map<String, dynamic> json) => LeaveRequest(
        id: (json['id'] as num).toInt(),
        type: LeaveType.fromApi(json['type'] as String?),
        typeLabel: json['type_label']?.toString() ?? LeaveType.fromApi(json['type'] as String?).label,
        startDate: DateTime.parse(json['start_date'] as String),
        endDate: DateTime.parse(json['end_date'] as String),
        days: (json['days'] as num?)?.toDouble() ?? 0,
        reason: json['reason']?.toString() ?? '',
        status: json['status']?.toString() ?? 'pending',
        startTime: json['start_time'] as String?,
        endTime: json['end_time'] as String?,
        reviewNotes: json['review_notes'] as String?,
        reviewer: json['reviewer'] as String?,
        reviewers: json['reviewers'] as String?,
        hasAttachment: json['has_attachment'] == true,
      );
}
