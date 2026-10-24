import '../../../core/config/app_config.dart';

/// Empresa elegida en el teléfono (se guarda con la sesión). Los colores y
/// logos vigentes salen de [AppConfig]; estos campos son solo respaldo.
class Branding {
  const Branding({
    required this.slug,
    required this.companyName,
    this.logoUrl,
    this.primaryColor,
    this.secondaryColor,
    this.timezone,
  });

  final String slug;
  final String companyName;
  final String? logoUrl;
  final String? primaryColor;
  final String? secondaryColor;
  final String? timezone;

  factory Branding.fromConfig(String slug, AppConfig config) => Branding(
        slug: slug,
        companyName: config.tenant?.displayName ?? slug,
        logoUrl: config.branding.logoUrl,
        primaryColor: config.branding.primaryColor,
        secondaryColor: config.branding.secondaryColor,
      );

  factory Branding.fromJson(Map<String, dynamic> json) => Branding(
        slug: json['slug']?.toString() ?? '',
        companyName: json['company_name']?.toString() ?? '',
        logoUrl: json['logo_url'] as String?,
        primaryColor: json['primary_color'] as String?,
        secondaryColor: json['secondary_color'] as String?,
        timezone: json['timezone'] as String?,
      );

  Map<String, dynamic> toJson() => {
        'slug': slug,
        'company_name': companyName,
        'logo_url': logoUrl,
        'primary_color': primaryColor,
        'secondary_color': secondaryColor,
        'timezone': timezone,
      };
}

/// Colaborador autenticado (UserResource del backend).
class AppUser {
  const AppUser({
    required this.id,
    required this.fullName,
    required this.firstName,
    required this.email,
    required this.role,
    this.employeeCode,
    this.position,
    this.companyName,
    this.avatarUrl,
    this.mustChangePassword = false,
    this.onboardingStatus = 'approved',
    this.permissions = const {},
  });

  final int id;
  final String fullName;
  final String firstName;
  final String email;
  final String role;
  final String? employeeCode;
  final String? position;

  /// Foto de perfil (null: se muestran las iniciales).
  final String? avatarUrl;

  /// Iniciales del nombre y del apellido ("Rosa Elena Quispe Díaz" → "RQ").
  String get initials {
    final words = fullName.trim().split(RegExp(r'\s+')).where((w) => w.isNotEmpty).toList();
    if (words.isEmpty) return '?';
    final firstWords = firstName.trim().split(RegExp(r'\s+')).where((w) => w.isNotEmpty).length;
    final last = words.length > firstWords ? words[firstWords] : (words.length > 1 ? words.last : '');
    return (words.first[0] + (last.isEmpty ? '' : last[0])).toUpperCase();
  }
  final String? companyName;

  /// Cuenta creada por RRHH con contraseña temporal: debe cambiarla primero.
  final bool mustChangePassword;

  /// Incorporación (pending, submitted, approved, rejected). Respaldo sin
  /// conexión del candado: la fuente es GET /api/onboarding.
  final String onboardingStatus;

  /// Permisos que envía el backend (UserResource.permissions): flags
  /// `can_*` o, si algún día llega como lista, slugs (`leave.approve_team`).
  final Map<String, bool> permissions;

  /// Roles que ven el área de equipo según RolesAndPermissionsSeeder
  /// (respaldo para sesiones guardadas antes de leer los permisos).
  static const _managerRoles = {'supervisor', 'hr_manager', 'manager', 'company_admin'};

  bool? _flag(List<String> keys) {
    bool? result;
    for (final key in keys) {
      final value = permissions[key];
      if (value == true) return true;
      if (value == false) result = false;
    }
    return result;
  }

  /// Puede aprobar o rechazar solicitudes (leave.approve_team / approve_all).
  bool get canApproveRequests =>
      _flag(const ['can_approve_requests', 'leave.approve_team', 'leave.approve_all']) ?? _managerRoles.contains(role);

  /// Puede ver la asistencia en vivo de su equipo (attendance.view_team).
  bool get canViewTeam => _flag(const ['attendance.view_team']) ?? _managerRoles.contains(role);

  /// Administra la empresa (RRHH o administrador): ve herramientas técnicas
  /// como la prueba de avisos en este teléfono.
  bool get isAdmin => const {'hr_manager', 'company_admin', 'super_admin'}.contains(role);

  /// Incorporación aprobada por RRHH: recién entonces puede marcar asistencia.
  bool get isOnboarded => onboardingStatus == 'approved';

  /// Firma APP: apoderado designado que autoriza órdenes de pago (lo decide el
  /// servidor; sin el flag, no: no hay rol que lo implique).
  bool get canAuthorizePayments => permissions['can_authorize_payments'] == true;

  /// Muestra la sección "Mi equipo" en Inicio.
  bool get hasTeamArea => canApproveRequests || canViewTeam || canAuthorizePayments;

  static Map<String, bool> parsePermissions(Object? raw) {
    if (raw is Map) return {for (final e in raw.entries) '${e.key}': e.value == true};
    if (raw is List) return {for (final slug in raw) '$slug': true};
    return const {};
  }

  factory AppUser.fromJson(Map<String, dynamic> json) => AppUser(
        id: (json['id'] as num).toInt(),
        fullName: json['full_name']?.toString() ?? '',
        firstName: json['first_name']?.toString() ?? '',
        email: json['email']?.toString() ?? '',
        role: json['role']?.toString() ?? 'employee',
        employeeCode: json['employee_code'] as String?,
        position: json['position'] as String?,
        avatarUrl: json['avatar_url'] as String?,
        companyName: (json['company'] as Map?)?['name'] as String? ?? json['company_name'] as String?,
        mustChangePassword: json['must_change_password'] == true,
        onboardingStatus: json['onboarding_status']?.toString() ?? 'approved',
        permissions: parsePermissions(json['permissions']),
      );

  AppUser copyWith({bool? mustChangePassword}) => AppUser(
        id: id,
        fullName: fullName,
        firstName: firstName,
        email: email,
        role: role,
        employeeCode: employeeCode,
        position: position,
        companyName: companyName,
        avatarUrl: avatarUrl,
        mustChangePassword: mustChangePassword ?? this.mustChangePassword,
        onboardingStatus: onboardingStatus,
        permissions: permissions,
      );

  Map<String, dynamic> toJson() => {
        'id': id,
        'full_name': fullName,
        'first_name': firstName,
        'email': email,
        'role': role,
        'employee_code': employeeCode,
        'position': position,
        'company_name': companyName,
        'avatar_url': avatarUrl,
        'must_change_password': mustChangePassword,
        'onboarding_status': onboardingStatus,
        'permissions': permissions,
      };
}
