/// Modelos del autoservicio del colaborador (GET /api/employee/profile).
library;

import '../../../core/utils/json_num.dart';

Map<String, dynamic> _map(Object? v) => v is Map ? Map<String, dynamic>.from(v) : <String, dynamic>{};
List<Map<String, dynamic>> _list(Object? v) => v is List ? v.whereType<Map>().map(Map<String, dynamic>.from).toList() : [];
Map<String, String> _labels(Object? v) => _map(v).map((k, val) => MapEntry(k, '$val'));
List<String> _strings(Object? v) => v is List ? v.map((e) => '$e').toList() : const [];

/// Qué módulos ve y edita el colaborador (flags de la empresa).
class CompanyConfig {
  const CompanyConfig({
    this.payslips = true,
    this.documents = true,
    this.paymentInfo = true,
    this.uniforms = true,
    this.uploadDocuments = true,
    this.editPaymentInfo = true,
    this.editUniforms = true,
    this.requiredDocuments = const ['dni', 'cv', 'proof_of_address'],
    this.maxUploadMb = 5,
  });

  final bool payslips;
  final bool documents;
  final bool paymentInfo;
  final bool uniforms;
  final bool uploadDocuments;
  final bool editPaymentInfo;
  final bool editUniforms;
  final List<String> requiredDocuments;
  final int maxUploadMb;

  factory CompanyConfig.fromJson(Map<String, dynamic> json) {
    final modules = _map(json['modules']);
    final perms = _map(json['permissions']);
    bool flag(Map<String, dynamic> m, String k) => m[k] != false;

    return CompanyConfig(
      payslips: flag(modules, 'payslips'),
      documents: flag(modules, 'documents'),
      paymentInfo: flag(modules, 'payment_info'),
      uniforms: flag(modules, 'uniforms'),
      uploadDocuments: flag(perms, 'upload_documents'),
      editPaymentInfo: flag(perms, 'edit_payment_info'),
      editUniforms: flag(perms, 'edit_uniforms'),
      requiredDocuments: json['required_documents'] == null ? const ['dni', 'cv', 'proof_of_address'] : _strings(json['required_documents']),
      maxUploadMb: asInt(json['max_upload_mb']) ?? 5,
    );
  }

  /// Hay al menos una sección de "Mi perfil" además de la cuenta.
  bool get hasEmployeeFile => documents || paymentInfo || uniforms;
}

enum DocumentStatus {
  pending('Por revisar'),
  approved('Aprobado'),
  rejected('Rechazado'),
  superseded('Reemplazado');

  const DocumentStatus(this.label);
  final String label;

  static DocumentStatus parse(String? v) => DocumentStatus.values.firstWhere((s) => s.name == v, orElse: () => pending);
}

class EmployeeDocument {
  const EmployeeDocument({
    required this.id,
    required this.type,
    required this.typeLabel,
    required this.status,
    required this.originalName,
    required this.mimeType,
    this.sizeKb,
    this.rejectionReason,
    this.expiresAt,
    this.uploadedAt,
  });

  final int id;
  final String type;
  final String typeLabel;
  final DocumentStatus status;
  final String originalName;
  final String mimeType;
  final double? sizeKb;
  final String? rejectionReason;
  final DateTime? expiresAt;
  final DateTime? uploadedAt;

  bool get isImage => mimeType.startsWith('image/');
  bool get canDelete => status == DocumentStatus.pending || status == DocumentStatus.rejected;

  factory EmployeeDocument.fromJson(Map<String, dynamic> json) => EmployeeDocument(
        id: asInt(json['id'])!,
        type: '${json['type']}',
        typeLabel: json['type_label']?.toString() ?? '${json['type']}',
        status: DocumentStatus.parse(json['status'] as String?),
        originalName: json['original_name']?.toString() ?? '',
        mimeType: json['mime_type']?.toString() ?? '',
        sizeKb: asDouble(json['size_kb']),
        rejectionReason: json['rejection_reason'] as String?,
        expiresAt: DateTime.tryParse('${json['expires_at']}'),
        uploadedAt: DateTime.tryParse('${json['uploaded_at']}'),
      );
}

/// Cuenta de abono y previsión (los números llegan enmascarados).
class PaymentInfo {
  const PaymentInfo({
    this.bankName,
    this.accountType,
    this.currency,
    this.accountNumberMasked,
    this.interbankMasked,
    this.status = 'incomplete',
    this.statusLabel = 'Incompleto',
    this.rejectionReason,
    this.pensionSystem,
    this.pensionFund,
    this.pensionCodeMasked,
    this.healthInsurance,
    this.laborRegimeLabel,
    this.hireDate,
    this.ctsBankName,
  });

  final String? bankName;
  final String? accountType;
  final String? currency;
  final String? accountNumberMasked;
  final String? interbankMasked;
  final String status;
  final String statusLabel;
  final String? rejectionReason;
  final String? pensionSystem;
  final String? pensionFund;
  final String? pensionCodeMasked;
  final String? healthInsurance;
  final String? laborRegimeLabel;
  final String? hireDate;
  final String? ctsBankName;

  bool get isVerified => status == 'verified';
  bool get isComplete => bankName != null && accountNumberMasked != null && pensionSystem != null;

  factory PaymentInfo.fromProfile(Map<String, dynamic> profile) {
    final payment = _map(profile['payment']);
    final pension = _map(profile['pension']);
    final employment = _map(profile['employment']);

    return PaymentInfo(
      bankName: payment['bank_name'] as String?,
      accountType: payment['bank_account_type'] as String?,
      currency: payment['bank_account_currency'] as String?,
      accountNumberMasked: payment['bank_account_number'] as String?,
      interbankMasked: payment['interbank_code'] as String?,
      status: payment['status']?.toString() ?? 'incomplete',
      statusLabel: payment['status_label']?.toString() ?? 'Incompleto',
      rejectionReason: payment['rejection_reason'] as String?,
      pensionSystem: pension['pension_system'] as String?,
      pensionFund: pension['pension_fund'] as String?,
      pensionCodeMasked: pension['pension_code'] as String?,
      healthInsurance: pension['health_insurance'] as String?,
      laborRegimeLabel: employment['labor_regime_label'] as String?,
      hireDate: employment['hire_date'] as String?,
      ctsBankName: employment['cts_bank_name'] as String?,
    );
  }
}

class UniformSizes {
  const UniformSizes({this.shirt, this.pants, this.shoes, this.shoeType, this.notes});

  final String? shirt;
  final String? pants;
  final String? shoes;
  final String? shoeType;
  final String? notes;

  bool get isComplete => shirt != null && pants != null && shoes != null;

  factory UniformSizes.fromJson(Map<String, dynamic> json) => UniformSizes(
        shirt: json['shirt_size'] as String?,
        pants: json['pants_size'] as String?,
        shoes: json['shoe_size'] as String?,
        shoeType: json['shoe_type'] as String?,
        notes: json['notes'] as String?,
      );
}

class UniformDelivery {
  const UniformDelivery({required this.itemLabel, this.size, required this.quantity, this.deliveredAt});

  final String itemLabel;
  final String? size;
  final int quantity;
  final DateTime? deliveredAt;

  factory UniformDelivery.fromJson(Map<String, dynamic> json) => UniformDelivery(
        itemLabel: json['item_label']?.toString() ?? '${json['item']}',
        size: json['size'] as String?,
        quantity: asInt(json['quantity']) ?? 1,
        deliveredAt: DateTime.tryParse('${json['delivered_at']}'),
      );
}

/// Catálogos para los selectores (vienen del backend).
class Catalogs {
  const Catalogs({
    this.bankAccountTypes = const {},
    this.pensionSystems = const {},
    this.healthInsurances = const {},
    this.documentTypes = const {},
    this.shirtSizes = const [],
    this.pantsSizes = const [],
    this.shoeSizes = const [],
    this.shoeTypes = const {},
    this.banks = const [],
    this.pensionFunds = const [],
  });

  final Map<String, String> bankAccountTypes;
  final Map<String, String> pensionSystems;
  final Map<String, String> healthInsurances;
  final Map<String, String> documentTypes;
  final List<String> shirtSizes;
  final List<String> pantsSizes;
  final List<String> shoeSizes;
  final Map<String, String> shoeTypes;
  final List<String> banks;
  final List<String> pensionFunds;

  factory Catalogs.fromJson(Map<String, dynamic> json) => Catalogs(
        bankAccountTypes: _labels(json['bank_account_types']),
        pensionSystems: _labels(json['pension_systems']),
        healthInsurances: _labels(json['health_insurances']),
        documentTypes: _labels(json['document_types']),
        shirtSizes: _strings(json['shirt_sizes']),
        pantsSizes: _strings(json['pants_sizes']),
        shoeSizes: _strings(json['shoe_sizes']),
        shoeTypes: _labels(json['shoe_types']),
        banks: _strings(json['banks']),
        pensionFunds: _strings(json['pension_funds']),
      );
}

/// Todo lo que la app necesita para "Mi perfil".
class EmployeeFile {
  const EmployeeFile({
    required this.fullName,
    required this.email,
    this.position,
    this.employeeCode,
    this.documentNumber,
    this.hireDate,
    this.address,
    this.emergencyContactName,
    this.emergencyContactPhone,
    required this.payment,
    this.sizes,
    this.deliveries = const [],
    this.documents = const [],
    this.missingDocuments = const [],
    this.steps = const {},
    this.progress = 0,
    required this.config,
    required this.catalogs,
  });

  final String fullName;
  final String email;
  final String? position;
  final String? employeeCode;
  final String? documentNumber;
  final String? hireDate;
  final String? address;
  final String? emergencyContactName;
  final String? emergencyContactPhone;
  final PaymentInfo payment;
  final UniformSizes? sizes;
  final List<UniformDelivery> deliveries;
  final List<EmployeeDocument> documents;
  final List<String> missingDocuments;
  final Map<String, bool> steps;
  final int progress;
  final CompanyConfig config;
  final Catalogs catalogs;

  /// Documento vigente (pendiente o aprobado) más reciente de un tipo.
  EmployeeDocument? currentDocument(String type) {
    final ofType = documents.where((d) => d.type == type && d.status != DocumentStatus.superseded).toList();
    return ofType.isEmpty ? null : ofType.first;
  }

  factory EmployeeFile.fromJson(Map<String, dynamic> json) {
    final user = _map(json['user']);
    final profile = _map(json['profile']);
    final contact = _map(profile['contact']);
    final uniforms = _map(json['uniforms']);
    final docs = _map(json['documents']);
    final onboarding = _map(json['onboarding']);

    return EmployeeFile(
      fullName: user['full_name']?.toString() ?? '',
      email: user['email']?.toString() ?? '',
      position: user['position'] as String?,
      employeeCode: user['employee_code'] as String?,
      documentNumber: user['document_number'] as String?,
      hireDate: user['hire_date'] as String?,
      address: contact['address'] as String?,
      emergencyContactName: contact['emergency_contact_name'] as String?,
      emergencyContactPhone: contact['emergency_contact_phone'] as String?,
      payment: PaymentInfo.fromProfile(profile),
      sizes: uniforms['sizes'] is Map ? UniformSizes.fromJson(_map(uniforms['sizes'])) : null,
      deliveries: _list(uniforms['deliveries']).map(UniformDelivery.fromJson).toList(),
      documents: _list(docs['items']).map(EmployeeDocument.fromJson).toList(),
      missingDocuments: _strings(docs['missing']),
      steps: _map(onboarding['steps']).map((k, v) => MapEntry(k, v == true)),
      progress: asInt(onboarding['progress']) ?? 0,
      config: CompanyConfig.fromJson(_map(json['config'])),
      catalogs: Catalogs.fromJson(_map(json['catalogs'])),
    );
  }
}
