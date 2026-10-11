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
  final String? companyName;

  /// Cuenta creada por RRHH con contraseña temporal: debe cambiarla primero.
  final bool mustChangePassword;

  /// Permisos que envía el backend (UserResource.permissions): flags
  /// `can_*` o, si algún día llega como lista, slugs (`leave.approve_team`).
  final Map<String, bool> permissions;

  /// Roles que ven el área de equipo según RolesAndPermissionsSeeder
  /// (respaldo para sesiones guardadas antes de leer los permisos).
  static const _managerRoles = {'supervisor', 'hr_manager', 'company_admin'};

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

  /// Muestra la sección "Mi equipo" en Inicio.
  bool get hasTeamArea => canApproveRequests || canViewTeam;

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
        'permissions': permissions,
      };
}
