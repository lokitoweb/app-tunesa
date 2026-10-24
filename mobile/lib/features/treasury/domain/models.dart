/// Orden de pago programada que espera la firma del apoderado designado
/// (GET /treasury/authorizations).
class PaymentAuthorization {
  const PaymentAuthorization({
    required this.id,
    required this.amount,
    required this.currency,
    this.order,
    this.requisition,
    this.detail,
    this.supplierName,
    this.supplierRuc,
    this.invoice,
    this.installment = 1,
    this.installmentsTotal,
    this.scheduledFor,
    this.dueDate,
  });

  final int id;
  final double amount;
  final String currency;
  final String? order;
  final String? requisition;
  final String? detail;
  final String? supplierName;
  final String? supplierRuc;
  final String? invoice;
  final int installment;
  final int? installmentsTotal;
  final DateTime? scheduledFor;
  final DateTime? dueDate;

  /// "S/ 1,200.00" (US$ / € según la moneda de la orden).
  String get amountLabel {
    final symbol = switch (currency) { 'USD' => r'US$', 'EUR' => '€', _ => 'S/' };
    final parts = amount.toStringAsFixed(2).split('.');
    final whole = parts[0].replaceAllMapped(RegExp(r'\B(?=(\d{3})+(?!\d))'), (_) => ',');
    return '$symbol $whole.${parts[1]}';
  }

  factory PaymentAuthorization.fromJson(Map<String, dynamic> json) {
    final supplier = json['supplier'] as Map?;
    return PaymentAuthorization(
      id: (json['id'] as num).toInt(),
      amount: (json['amount'] as num?)?.toDouble() ?? 0,
      currency: json['currency']?.toString() ?? 'PEN',
      order: json['order']?.toString(),
      requisition: json['requisition']?.toString(),
      detail: json['detail']?.toString(),
      supplierName: supplier?['name']?.toString(),
      supplierRuc: supplier?['ruc']?.toString(),
      invoice: json['invoice']?.toString(),
      installment: (json['installment'] as num?)?.toInt() ?? 1,
      installmentsTotal: (json['of'] as num?)?.toInt(),
      scheduledFor: DateTime.tryParse(json['scheduled_for']?.toString() ?? ''),
      dueDate: DateTime.tryParse(json['due_date']?.toString() ?? ''),
    );
  }
}
