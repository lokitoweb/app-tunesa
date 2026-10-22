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
        id: (json['id'] as num).toInt(),
        name: json['name']?.toString() ?? '',
        firstName: json['first_name']?.toString() ?? '',
        initials: json['initials']?.toString() ?? '?',
        position: json['position']?.toString(),
        department: json['department']?.toString(),
        branch: json['branch']?.toString(),
        avatarUrl: json['avatar_url']?.toString(),
        day: (json['day'] as num?)?.toInt() ?? 1,
        month: (json['month'] as num?)?.toInt() ?? 1,
        dateLabel: json['date_label']?.toString() ?? '',
        isToday: json['is_today'] == true,
        daysUntil: (json['days_until'] as num?)?.toInt() ?? 0,
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
      month: (json['month'] as num?)?.toInt() ?? 1,
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
  const BirthdayGreeting({required this.date, required this.firstName, required this.company, required this.title, required this.body});

  /// Fecha del cumpleaños (yyyy-MM-dd, zona horaria de la empresa).
  final String date;
  final String firstName;
  final String company;
  final String title;
  final String body;

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
    );
  }
}
