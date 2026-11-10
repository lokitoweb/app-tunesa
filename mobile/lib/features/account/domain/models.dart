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

/// Política de privacidad administrable (GET /api/legal/privacy): bloques simples que la app dibuja.
class PrivacyPolicy {
  const PrivacyPolicy({required this.appName, required this.updatedOn, required this.blocks, this.contact = const {}, this.url});

  final String appName;
  final DateTime? updatedOn;
  final List<PolicyBlock> blocks;
  final Map<String, String> contact;
  final String? url;

  factory PrivacyPolicy.fromJson(Map<String, dynamic> j) => PrivacyPolicy(
        appName: j['app_name']?.toString() ?? '',
        updatedOn: DateTime.tryParse(j['updated_on']?.toString() ?? ''),
        blocks: ((j['blocks'] as List?) ?? const [])
            .whereType<Map>()
            .map((b) => PolicyBlock(type: b['type']?.toString() ?? 'paragraph', text: b['text']?.toString() ?? ''))
            .toList(),
        contact: {
          for (final e in ((j['contact'] as Map?) ?? const {}).entries)
            if (e.value != null && e.value.toString().isNotEmpty) e.key.toString(): e.value.toString(),
        },
        url: j['url']?.toString(),
      );
}

/// heading, paragraph o bullet; el texto puede traer **negrita**.
class PolicyBlock {
  const PolicyBlock({required this.type, required this.text});

  final String type;
  final String text;
}
