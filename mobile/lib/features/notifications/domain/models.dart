/// Notificación in-app (GET /notifications).
class AppNotification {
  const AppNotification({
    required this.id,
    required this.type,
    required this.title,
    required this.body,
    this.data = const {},
    this.readAt,
    this.createdAt,
  });

  final String id;

  /// Ej: `leave_request.approved`, `payslip.published`, `document.rejected`.
  final String type;
  final String title;
  final String body;
  final Map<String, dynamic> data;
  final DateTime? readAt;
  final DateTime? createdAt;

  bool get isRead => readAt != null;

  /// Pantalla de la app a la que lleva la notificación, o null si no aplica.
  String? get route => routeFor(type, data);

  /// Destino según el tipo (prefijo antes del punto) o, en su defecto, el
  /// `data.type` que envíe el backend.
  static String? routeFor(String type, [Map<String, dynamic> data = const {}]) {
    final kind = (type.isNotEmpty ? type : data['type']?.toString() ?? '').split('.').first;
    return switch (kind) {
      'payslip' || 'payslips' => '/payslips',
      'leave_request' || 'leave_requests' || 'leave' => _isForApprover(type) ? '/approvals' : '/requests',
      'document' || 'documents' => '/profile/documents',
      'approval' || 'approvals' => '/approvals',
      'shift' || 'app' => '/home',
      _ => null,
    };
  }

  /// Avisos al aprobador (nueva solicitud de su equipo) en vez de al solicitante.
  static bool _isForApprover(String type) =>
      const {'leave_request.submitted', 'leave_request.created', 'leave_request.pending_approval'}.contains(type);

  AppNotification markedRead([DateTime? at]) => AppNotification(
        id: id,
        type: type,
        title: title,
        body: body,
        data: data,
        readAt: readAt ?? at ?? DateTime.now(),
        createdAt: createdAt,
      );

  factory AppNotification.fromJson(Map<String, dynamic> json) => AppNotification(
        id: json['id']?.toString() ?? '',
        type: json['type']?.toString() ?? '',
        title: json['title']?.toString() ?? '',
        body: json['body']?.toString() ?? '',
        data: json['data'] is Map ? Map<String, dynamic>.from(json['data'] as Map) : const {},
        readAt: DateTime.tryParse(json['read_at']?.toString() ?? ''),
        createdAt: DateTime.tryParse(json['created_at']?.toString() ?? ''),
      );
}
