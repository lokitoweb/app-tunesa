import '../../../core/utils/json_num.dart';

/// Un dispositivo con sesión abierta en la cuenta.
class DeviceSession {
  const DeviceSession({
    required this.id,
    required this.label,
    required this.current,
    this.lastUsedAt,
    this.createdAt,
  });

  final int id;
  final String label;
  final bool current;
  final DateTime? lastUsedAt;
  final DateTime? createdAt;

  factory DeviceSession.fromJson(Map<String, dynamic> j) => DeviceSession(
        id: asInt(j['id'])!,
        label: (j['label'] ?? j['device'] ?? 'Dispositivo').toString(),
        current: j['current'] == true,
        lastUsedAt: DateTime.tryParse('${j['last_used_at'] ?? ''}'),
        createdAt: DateTime.tryParse('${j['created_at'] ?? ''}'),
      );
}

/// Una categoría de avisos push que la persona puede apagar.
class NotificationPreference {
  const NotificationPreference({required this.key, required this.title, required this.description, required this.enabled});

  final String key;
  final String title;
  final String description;
  final bool enabled;

  NotificationPreference copyWith({bool? enabled}) =>
      NotificationPreference(key: key, title: title, description: description, enabled: enabled ?? this.enabled);

  factory NotificationPreference.fromJson(Map<String, dynamic> j) => NotificationPreference(
        key: '${j['key']}',
        title: '${j['title'] ?? j['key']}',
        description: '${j['description'] ?? ''}',
        enabled: j['enabled'] != false,
      );
}
