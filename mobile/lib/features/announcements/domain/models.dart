/// Comunicado de RR. HH. (aviso, reunión, actualización...).
class Announcement {
  const Announcement({
    required this.id,
    required this.title,
    required this.category,
    required this.categoryLabel,
    this.excerpt = '',
    this.body,
    this.isImportant = false,
    this.eventAt,
    this.location,
    this.url,
    this.sentAt,
    this.read = false,
  });

  final int id;
  final String title;

  /// general | meeting | update | reminder | urgent
  final String category;
  final String categoryLabel;
  final String excerpt;

  /// Solo en el detalle.
  final String? body;
  final bool isImportant;
  final DateTime? eventAt;
  final String? location;
  final String? url;
  final DateTime? sentAt;
  final bool read;

  bool get isUrgent => category == 'urgent';

  factory Announcement.fromJson(Map<String, dynamic> json) {
    String? text(String key) {
      final v = json[key]?.toString().trim();
      return v == null || v.isEmpty ? null : v;
    }

    return Announcement(
      id: (json['id'] as num).toInt(),
      title: json['title']?.toString() ?? '',
      category: json['category']?.toString() ?? 'general',
      categoryLabel: json['category_label']?.toString() ?? 'Aviso',
      excerpt: json['excerpt']?.toString() ?? '',
      body: text('body'),
      isImportant: json['is_important'] == true,
      eventAt: DateTime.tryParse(json['event_at']?.toString() ?? ''),
      location: text('location'),
      url: text('url'),
      sentAt: DateTime.tryParse(json['sent_at']?.toString() ?? ''),
      read: json['read'] == true,
    );
  }
}

class AnnouncementFeed {
  const AnnouncementFeed({required this.items, required this.unread});

  final List<Announcement> items;
  final int unread;

  /// Importantes o urgentes sin leer: se destacan en Inicio.
  List<Announcement> get highlighted => items.where((a) => !a.read && (a.isImportant || a.isUrgent)).toList();
}
