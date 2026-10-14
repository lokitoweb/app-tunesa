/// Configuración pública de la app (`GET /api/v1/app/config?tenant_slug=`).
///
/// Es la única fuente de verdad para marca blanca (colores, logos), reglas de
/// marcación del teléfono, versión mínima y contacto de la empresa. Todo campo
/// puede faltar o venir null: el parseo nunca lanza y usa valores por defecto.
library;

String? _str(Object? v) {
  if (v == null) return null;
  final s = v.toString().trim();
  return s.isEmpty ? null : s;
}

/// Solo enlaces https:// (el enlace de descarga lo abre el sistema).
String? _httpsUrl(Object? v) {
  final s = _str(v);
  final uri = s == null ? null : Uri.tryParse(s);
  return uri != null && uri.scheme == 'https' && uri.host.isNotEmpty ? s : null;
}

/// Enlaces http(s) del propio backend (http solo en desarrollo).
String? _httpUrl(Object? v) {
  final s = _str(v);
  final uri = s == null ? null : Uri.tryParse(s);
  return uri != null && (uri.scheme == 'https' || uri.scheme == 'http') && uri.host.isNotEmpty ? s : null;
}

bool _bool(Object? v, bool fallback) {
  if (v is bool) return v;
  if (v is num) return v != 0;
  if (v is String) {
    final s = v.toLowerCase();
    if (s == 'true' || s == '1') return true;
    if (s == 'false' || s == '0') return false;
  }
  return fallback;
}

int? _int(Object? v) {
  if (v is int) return v;
  if (v is num) return v.round();
  if (v is String) return int.tryParse(v.trim()) ?? double.tryParse(v.trim())?.round();
  return null;
}

Map<String, dynamic> _map(Object? v) => v is Map ? Map<String, dynamic>.from(v) : const {};

class TenantInfo {
  const TenantInfo({required this.slug, this.companyName, this.tradeName});

  final String slug;
  final String? companyName;
  final String? tradeName;

  /// Nombre comercial si existe; si no, la razón social.
  String get displayName => tradeName ?? companyName ?? slug;

  static TenantInfo? fromJson(Object? raw) {
    final json = _map(raw);
    final slug = _str(json['slug']);
    if (slug == null) return null;
    return TenantInfo(slug: slug, companyName: _str(json['company_name']), tradeName: _str(json['trade_name']));
  }

  Map<String, dynamic> toJson() => {'slug': slug, 'company_name': companyName, 'trade_name': tradeName};
}

class BrandingConfig {
  const BrandingConfig({
    this.logoUrl,
    this.logoDarkUrl,
    this.faviconUrl,
    this.splashUrl,
    this.primaryColor,
    this.secondaryColor,
    this.accentColor,
    this.navbarColor,
  });

  final String? logoUrl;
  final String? logoDarkUrl;
  final String? faviconUrl;
  final String? splashUrl;
  final String? primaryColor;
  final String? secondaryColor;
  final String? accentColor;
  final String? navbarColor;

  /// Logo para el brillo indicado (el oscuro solo si existe).
  String? logoFor({required bool dark}) => dark ? (logoDarkUrl ?? logoUrl) : (logoUrl ?? logoDarkUrl);

  factory BrandingConfig.fromJson(Object? raw) {
    final json = _map(raw);
    return BrandingConfig(
      logoUrl: _str(json['logo_url']),
      logoDarkUrl: _str(json['logo_dark_url']),
      faviconUrl: _str(json['favicon_url']),
      splashUrl: _str(json['splash_url']),
      primaryColor: _str(json['primary_color']),
      secondaryColor: _str(json['secondary_color']),
      accentColor: _str(json['accent_color']),
      navbarColor: _str(json['navbar_color']),
    );
  }

  Map<String, dynamic> toJson() => {
        'logo_url': logoUrl,
        'logo_dark_url': logoDarkUrl,
        'favicon_url': faviconUrl,
        'splash_url': splashUrl,
        'primary_color': primaryColor,
        'secondary_color': secondaryColor,
        'accent_color': accentColor,
        'navbar_color': navbarColor,
      };
}

class MobileConfig {
  const MobileConfig({
    this.apiBaseUrl,
    this.minSupportedVersion,
    this.latestVersion,
    this.forceUpdate = false,
    this.updateUrl,
    this.selfieRequired = true,
    this.faceDetectionRequired = false,
    this.offlineModeEnabled = false,
    this.offlineMaxHours = 24,
    this.maxGpsAccuracyMeters,
    this.geofenceStrictMode = true,
    this.mockLocationPolicy = MockLocationPolicy.reject,
    this.maxTimeDriftSeconds,
    this.geocodingProvider,
    this.fcmEnabled = true,
    this.selfPasswordChange = true,
  });

  final String? apiBaseUrl;
  final String? minSupportedVersion;
  final String? latestVersion;
  final bool forceUpdate;

  /// Enlace de descarga (tienda o APK) para el botón "Actualizar"; null = sin botón.
  final String? updateUrl;
  final bool selfieRequired;
  final bool faceDetectionRequired;
  final bool offlineModeEnabled;
  final int offlineMaxHours;

  /// null = usar el valor compilado (Env.maxGpsAccuracyMeters).
  final int? maxGpsAccuracyMeters;
  final bool geofenceStrictMode;

  /// Ubicación simulada: `reject` (no se envía) o `flag` (se envía y queda en revisión).
  final MockLocationPolicy mockLocationPolicy;

  /// Desfase máximo tolerado entre la hora del teléfono y la del servidor (null = sin límite).
  final int? maxTimeDriftSeconds;

  /// Proveedor de direcciones del backend (solo informativo).
  final String? geocodingProvider;

  /// La empresa envía notificaciones push (si es false no se registra el token).
  final bool fcmEnabled;

  /// El colaborador puede cambiar su contraseña desde Mi perfil (panel de la empresa).
  final bool selfPasswordChange;

  factory MobileConfig.fromJson(Object? raw) {
    final json = _map(raw);
    final hours = _int(json['offline_max_hours']);
    final accuracy = _int(json['max_gps_accuracy_meters']);
    final drift = _int(json['max_time_drift_seconds']);
    return MobileConfig(
      apiBaseUrl: _str(json['api_base_url']),
      minSupportedVersion: _str(json['min_supported_version']),
      latestVersion: _str(json['latest_version']),
      forceUpdate: _bool(json['force_update'], false),
      updateUrl: _httpsUrl(json['update_url']),
      selfieRequired: _bool(json['selfie_required'], true),
      faceDetectionRequired: _bool(json['face_detection_required'], false),
      offlineModeEnabled: _bool(json['offline_mode_enabled'], false),
      offlineMaxHours: hours != null && hours > 0 ? hours : 24,
      maxGpsAccuracyMeters: accuracy != null && accuracy > 0 ? accuracy : null,
      geofenceStrictMode: _bool(json['geofence_strict_mode'], true),
      mockLocationPolicy: MockLocationPolicy.fromApi(json['mock_location_policy']),
      maxTimeDriftSeconds: drift != null && drift > 0 ? drift : null,
      geocodingProvider: _str(json['geocoding_provider']),
      fcmEnabled: _bool(json['fcm_enabled'], true),
      selfPasswordChange: _bool(json['self_password_change'], true),
    );
  }

  Map<String, dynamic> toJson() => {
        'api_base_url': apiBaseUrl,
        'min_supported_version': minSupportedVersion,
        'latest_version': latestVersion,
        'force_update': forceUpdate,
        'update_url': updateUrl,
        'selfie_required': selfieRequired,
        'face_detection_required': faceDetectionRequired,
        'offline_mode_enabled': offlineModeEnabled,
        'offline_max_hours': offlineMaxHours,
        'max_gps_accuracy_meters': maxGpsAccuracyMeters,
        'geofence_strict_mode': geofenceStrictMode,
        'mock_location_policy': mockLocationPolicy.apiName,
        'max_time_drift_seconds': maxTimeDriftSeconds,
        'geocoding_provider': geocodingProvider,
        'fcm_enabled': fcmEnabled,
        'self_password_change': selfPasswordChange,
      };
}

/// Qué hacer con una ubicación simulada (`mobile.mock_location_policy`).
enum MockLocationPolicy {
  /// No se envía: se bloquea en el teléfono con una explicación.
  reject('reject'),

  /// Se envía con `is_mocked=1` y RRHH la revisa.
  flag('flag');

  const MockLocationPolicy(this.apiName);

  final String apiName;

  /// Valor desconocido o ausente = `reject` (lo más seguro).
  static MockLocationPolicy fromApi(Object? raw) =>
      _str(raw)?.toLowerCase() == 'flag' ? MockLocationPolicy.flag : MockLocationPolicy.reject;
}

class RulesConfig {
  const RulesConfig({this.graceMinutes, this.lunchMinutes, this.lunchPaid, this.defaultGeofenceRadius});

  final int? graceMinutes;
  final int? lunchMinutes;
  final bool? lunchPaid;
  final int? defaultGeofenceRadius;

  factory RulesConfig.fromJson(Object? raw) {
    final json = _map(raw);
    return RulesConfig(
      graceMinutes: _int(json['grace_minutes']),
      lunchMinutes: _int(json['lunch_minutes']),
      lunchPaid: json['lunch_paid'] == null ? null : _bool(json['lunch_paid'], false),
      defaultGeofenceRadius: _int(json['default_geofence_radius']),
    );
  }

  Map<String, dynamic> toJson() => {
        'grace_minutes': graceMinutes,
        'lunch_minutes': lunchMinutes,
        'lunch_paid': lunchPaid,
        'default_geofence_radius': defaultGeofenceRadius,
      };
}

class ContactConfig {
  const ContactConfig({this.email, this.phone, this.website, this.address});

  final String? email;
  final String? phone;
  final String? website;
  final String? address;

  bool get isEmpty => email == null && phone == null && website == null && address == null;

  factory ContactConfig.fromJson(Object? raw) {
    final json = _map(raw);
    return ContactConfig(
      email: _str(json['email']),
      phone: _str(json['phone']),
      website: _str(json['website']),
      address: _str(json['address']),
    );
  }

  Map<String, dynamic> toJson() => {'email': email, 'phone': phone, 'website': website, 'address': address};
}

class ModulesConfig {
  const ModulesConfig({
    this.payslips = true,
    this.documents = true,
    this.paymentInfo = true,
    this.uniforms = true,
    this.trainings = true,
    this.ethics = true,
    this.benefits = true,
    this.birthdays = true,
    this.companyDocuments = true,
  });

  final bool payslips;
  final bool documents;
  final bool paymentInfo;
  final bool uniforms;

  /// Capacitaciones (módulo Training de la empresa).
  final bool trainings;

  /// Canal ético (módulo Ethics de la empresa).
  final bool ethics;

  /// Beneficios para el personal (módulo Benefits).
  final bool benefits;

  /// Cumpleaños del personal (módulo Birthdays).
  final bool birthdays;

  /// Documentos de la empresa: políticas, reglamentos... (módulo CompanyDocs).
  final bool companyDocuments;

  factory ModulesConfig.fromJson(Object? raw) {
    final json = _map(raw);
    return ModulesConfig(
      payslips: _bool(json['payslips'], true),
      documents: _bool(json['documents'], true),
      paymentInfo: _bool(json['payment_info'], true),
      uniforms: _bool(json['uniforms'], true),
      trainings: _bool(json['trainings'], true),
      ethics: _bool(json['ethics'], true),
      benefits: _bool(json['benefits'], true),
      birthdays: _bool(json['birthdays'], true),
      companyDocuments: _bool(json['company_documents'], true),
    );
  }

  Map<String, dynamic> toJson() =>
      {'payslips': payslips, 'documents': documents, 'payment_info': paymentInfo, 'uniforms': uniforms, 'trainings': trainings,
        'ethics': ethics,
        'benefits': benefits,
        'birthdays': birthdays,
        'company_documents': companyDocuments,
      };
}

/// Páginas públicas que piden Google Play y App Store (`legal`).
class LegalConfig {
  const LegalConfig({this.privacyPolicyUrl, this.accountDeletionUrl});

  final String? privacyPolicyUrl;
  final String? accountDeletionUrl;

  factory LegalConfig.fromJson(Object? raw) {
    final json = _map(raw);
    return LegalConfig(
      privacyPolicyUrl: _httpUrl(json['privacy_policy_url']),
      accountDeletionUrl: _httpUrl(json['account_deletion_url']),
    );
  }

  Map<String, dynamic> toJson() => {'privacy_policy_url': privacyPolicyUrl, 'account_deletion_url': accountDeletionUrl};
}

class AppConfig {
  const AppConfig({
    this.tenant,
    this.branding = const BrandingConfig(),
    this.mobile = const MobileConfig(),
    this.rules = const RulesConfig(),
    this.contact = const ContactConfig(),
    this.modules = const ModulesConfig(),
    this.legal = const LegalConfig(),
    this.configVersion,
  });

  /// Valores de la plataforma sin empresa ni red (primer arranque offline).
  static const defaults = AppConfig();

  final TenantInfo? tenant;
  final BrandingConfig branding;
  final MobileConfig mobile;
  final RulesConfig rules;
  final ContactConfig contact;
  final ModulesConfig modules;
  final LegalConfig legal;
  final String? configVersion;

  String get displayName => tenant?.displayName ?? 'Tunesa';

  /// Acepta la respuesta completa (`{success, data, meta}`) o solo `data`.
  factory AppConfig.fromJson(Map<String, dynamic> json) {
    final data = json['data'] is Map ? _map(json['data']) : json;
    final meta = _map(json['meta']);
    return AppConfig(
      tenant: TenantInfo.fromJson(data['tenant']),
      branding: BrandingConfig.fromJson(data['branding']),
      mobile: MobileConfig.fromJson(data['mobile']),
      rules: RulesConfig.fromJson(data['rules']),
      contact: ContactConfig.fromJson(data['contact']),
      modules: ModulesConfig.fromJson(data['modules']),
      legal: LegalConfig.fromJson(data['legal']),
      configVersion: _str(meta['config_version']) ?? _str(data['config_version']),
    );
  }

  /// Marca guardada por versiones anteriores (`GET /tenant/branding`).
  factory AppConfig.fromLegacyBranding(Map<String, dynamic> json) => AppConfig(
        tenant: TenantInfo.fromJson({'slug': json['slug'], 'company_name': json['company_name']}),
        branding: BrandingConfig.fromJson(json),
      );

  Map<String, dynamic> toJson() => {
        'data': {
          'tenant': tenant?.toJson(),
          'branding': branding.toJson(),
          'mobile': mobile.toJson(),
          'rules': rules.toJson(),
          'contact': contact.toJson(),
          'modules': modules.toJson(),
          'legal': legal.toJson(),
        },
        'meta': {'config_version': configVersion},
      };
}
