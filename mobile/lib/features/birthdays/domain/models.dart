import '../../../core/utils/json_num.dart';

/// Cumpleaños de una persona (solo día y mes: el backend nunca envía el año).
class Birthday {
  const Birthday({
    required this.id,
    required this.name,
    required this.firstName,
    required this.initials,
    required this.day,
    required this.month,
    required this.dateLabel,
    required this.isToday,
    required this.daysUntil,
    this.position,
    this.department,
    this.branch,
    this.avatarUrl,
  });

  final int id;
  final String name;
  final String firstName;
  final String initials;
  final String? position;
  final String? department;
  final String? branch;
  final String? avatarUrl;
  final int day;
  final int month;

  /// "14 de febrero"
  final String dateLabel;
  final bool isToday;

  /// Días para el próximo cumpleaños (0 = hoy).
  final int daysUntil;

  factory Birthday.fromJson(Map<String, dynamic> json) => Birthday(
        id: asInt(json['id'])!,
        name: json['name']?.toString() ?? '',
        firstName: json['first_name']?.toString() ?? '',
        initials: json['initials']?.toString() ?? '?',
        position: json['position']?.toString(),
        department: json['department']?.toString(),
        branch: json['branch']?.toString(),
        avatarUrl: json['avatar_url']?.toString(),
        day: asInt(json['day']) ?? 1,
        month: asInt(json['month']) ?? 1,
        dateLabel: json['date_label']?.toString() ?? '',
        isToday: json['is_today'] == true,
        daysUntil: asInt(json['days_until']) ?? 0,
      );

  /// "Hoy", "Mañana", "En 5 días"
  String get whenLabel => isToday ? '¡Hoy!' : (daysUntil == 1 ? 'Mañana' : 'En $daysUntil días');

  /// "14 feb" (para el carrusel de Inicio)
  String get shortDate => '$day ${_months[month - 1]}';

  static const _months = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
}

/// Cumpleaños de un mes, separados en hoy / próximos / pasados, más el
/// carrusel "Mi equipo" (próximos de su sucursal en el año).
class BirthdayMonth {
  const BirthdayMonth({
    required this.month,
    required this.monthName,
    required this.today,
    required this.upcoming,
    required this.past,
    required this.team,
  });

  final int month;
  final String monthName;
  final List<Birthday> today;
  final List<Birthday> upcoming;
  final List<Birthday> past;
  final List<Birthday> team;

  bool get isEmpty => today.isEmpty && upcoming.isEmpty && past.isEmpty;

  factory BirthdayMonth.fromJson(Map<String, dynamic> json) {
    List<Birthday> list(Object? raw) =>
        ((raw as List?) ?? const []).map((e) => Birthday.fromJson(Map<String, dynamic>.from(e as Map))).toList();
    return BirthdayMonth(
      month: asInt(json['month']) ?? 1,
      monthName: json['month_name']?.toString() ?? '',
      today: list(json['today']),
      upcoming: list(json['upcoming']),
      past: list(json['past']),
      team: list(json['team']),
    );
  }
}

/// Próximos cumpleaños (Inicio): los primeros, cuántos hay en total y, solo el
/// día del cumpleaños de quien usa la app, el saludo de la empresa.
class UpcomingBirthdays {
  const UpcomingBirthdays({required this.items, required this.total, this.greeting});

  final List<Birthday> items;
  final int total;
  final BirthdayGreeting? greeting;

  static const empty = UpcomingBirthdays(items: [], total: 0);
}

/// Saludo de cumpleaños para quien usa la app (modal de celebración).
class BirthdayGreeting {
  const BirthdayGreeting({
    required this.date,
    required this.firstName,
    required this.company,
    required this.title,
    required this.body,
    this.userId,
    this.gender,
  });

  /// Fecha del cumpleaños (yyyy-MM-dd, zona horaria de la empresa).
  final String date;
  final String firstName;
  final String company;
  final String title;
  final String body;

  /// Id de quien cumple (para ver sus regalos).
  final int? userId;

  /// "male", "female", "other" o null: elige la paleta de la tarjeta.
  final String? gender;

  static BirthdayGreeting? fromJson(Object? raw) {
    if (raw is! Map) return null;
    final json = Map<String, dynamic>.from(raw);
    final date = json['date']?.toString() ?? '';
    if (date.isEmpty) return null;
    final first = json['first_name']?.toString() ?? '';
    final company = json['company']?.toString() ?? '';
    return BirthdayGreeting(
      date: date,
      firstName: first,
      company: company,
      title: json['title']?.toString() ?? '¡Feliz cumpleaños, $first!',
      body: json['body']?.toString() ?? '',
      userId: asInt(json['user_id']),
      gender: json['gender']?.toString(),
    );
  }
}

/// Regalo del catálogo (animación Lottie en `assets/gifts/<type>.json`).
class GiftOption {
  const GiftOption({required this.type, required this.label, required this.emoji});

  final String type;
  final String label;
  final String emoji;

  String get asset => 'assets/gifts/$type.json';

  factory GiftOption.fromJson(Map<String, dynamic> json) => GiftOption(
        type: json['type']?.toString() ?? '',
        label: json['label']?.toString() ?? 'Regalo',
        emoji: json['emoji']?.toString() ?? '🎁',
      );
}

/// Quien envió un regalo.
class GiftSender {
  const GiftSender({required this.name, required this.firstName, required this.initials, this.id, this.avatarUrl});

  final int? id;
  final String name;
  final String firstName;
  final String initials;
  final String? avatarUrl;

  factory GiftSender.fromJson(Map<String, dynamic> json) => GiftSender(
        id: asInt(json['id']),
        name: json['name']?.toString() ?? 'Un compañero',
        firstName: json['first_name']?.toString() ?? '',
        initials: json['initials']?.toString() ?? '?',
        avatarUrl: json['avatar_url']?.toString(),
      );
}

/// Regalo recibido. [playable]: solo el día del cumpleaños (hora de la
/// empresa); después queda como historial sin animación.
class BirthdayGift {
  const BirthdayGift({
    required this.id,
    required this.gift,
    required this.sender,
    required this.date,
    required this.playable,
  });

  final int id;
  final GiftOption gift;
  final GiftSender sender;
  final String date;
  final bool playable;

  factory BirthdayGift.fromJson(Map<String, dynamic> json) => BirthdayGift(
        id: asInt(json['id']) ?? 0,
        gift: GiftOption.fromJson(json),
        sender: GiftSender.fromJson(Map<String, dynamic>.from((json['sender'] as Map?) ?? const {})),
        date: json['date']?.toString() ?? '',
        playable: json['playable'] == true,
      );
}

/// Regalos del último cumpleaños de una persona, catálogo y si hoy se puede enviar.
class GiftBoard {
  const GiftBoard({
    required this.isToday,
    required this.isSelf,
    required this.canSend,
    required this.catalog,
    required this.gifts,
    required this.sentByMe,
    this.expiresAt,
  });

  final bool isToday;

  /// Quien consulta es el cumpleañero.
  final bool isSelf;
  final bool canSend;
  final List<GiftOption> catalog;
  final List<BirthdayGift> gifts;

  /// Tipos que quien consulta ya envió hoy (no se repiten).
  final Set<String> sentByMe;

  /// Fin del día del cumpleaños: después el envío se desactiva.
  final DateTime? expiresAt;

  factory GiftBoard.fromJson(Map<String, dynamic> json) => GiftBoard(
        isToday: json['is_today'] == true,
        isSelf: json['is_self'] == true,
        canSend: json['can_send'] == true,
        expiresAt: DateTime.tryParse(json['expires_at']?.toString() ?? ''),
        sentByMe: ((json['sent_by_me'] as List?) ?? const []).map((e) => e.toString()).toSet(),
        catalog: ((json['catalog'] as List?) ?? const [])
            .map((e) => GiftOption.fromJson(Map<String, dynamic>.from(e as Map)))
            .toList(),
        gifts: ((json['gifts'] as List?) ?? const [])
            .map((e) => BirthdayGift.fromJson(Map<String, dynamic>.from(e as Map)))
            .toList(),
      );
}
