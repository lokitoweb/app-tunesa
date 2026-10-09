class Payslip {
  const Payslip({
    required this.id,
    required this.title,
    required this.periodLabel,
    required this.month,
    required this.year,
    required this.isRead,
    this.publishedAt,
  });

  final int id;
  final String title;
  final String periodLabel;
  final int month;
  final int year;
  final bool isRead;
  final DateTime? publishedAt;

  factory Payslip.fromJson(Map<String, dynamic> json) => Payslip(
        id: (json['id'] as num).toInt(),
        title: json['title']?.toString() ?? 'Boleta de pago',
        periodLabel: json['period_label']?.toString() ?? '${json['period_month']}/${json['period_year']}',
        month: (json['period_month'] as num?)?.toInt() ?? 0,
        year: (json['period_year'] as num?)?.toInt() ?? 0,
        isRead: json['is_read'] == true,
        publishedAt: DateTime.tryParse(json['published_at']?.toString() ?? ''),
      );
}
