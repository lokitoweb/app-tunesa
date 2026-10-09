/// Modelo de Boleta de Pago en la app móvil
class PayslipModel {
  final int id;
  final String title;
  final int periodMonth;
  final int periodYear;
  final double grossSalary;
  final double deductions;
  final double netSalary;
  final String? publishedAt;
  final String? readAt;
  final String? downloadUrl;

  PayslipModel({
    required this.id,
    required this.title,
    required this.periodMonth,
    required this.periodYear,
    required this.grossSalary,
    required this.deductions,
    required this.netSalary,
    this.publishedAt,
    this.readAt,
    this.downloadUrl,
  });

  bool get isRead => readAt != null;

  factory PayslipModel.fromJson(Map<String, dynamic> json) {
    return PayslipModel(
      id: json['id'],
      title: json['title'] ?? 'Boleta de Pago',
      periodMonth: json['period_month'] ?? 1,
      periodYear: json['period_year'] ?? 2026,
      grossSalary: double.tryParse(json['gross_salary']?.toString() ?? '0') ?? 0.0,
      deductions: double.tryParse(json['deductions']?.toString() ?? '0') ?? 0.0,
      netSalary: double.tryParse(json['net_salary']?.toString() ?? '0') ?? 0.0,
      publishedAt: json['published_at'],
      readAt: json['read_at'],
      downloadUrl: json['download_url'],
    );
  }
}
