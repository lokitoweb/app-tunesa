/// Marca blanca de la empresa (GET /api/tenant/branding).
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
  });

  final int id;
  final String fullName;
  final String firstName;
  final String email;
  final String role;
  final String? employeeCode;
  final String? position;
  final String? companyName;

  factory AppUser.fromJson(Map<String, dynamic> json) => AppUser(
        id: (json['id'] as num).toInt(),
        fullName: json['full_name']?.toString() ?? '',
        firstName: json['first_name']?.toString() ?? '',
        email: json['email']?.toString() ?? '',
        role: json['role']?.toString() ?? 'employee',
        employeeCode: json['employee_code'] as String?,
        position: json['position'] as String?,
        companyName: (json['company'] as Map?)?['name'] as String? ?? json['company_name'] as String?,
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
      };
}
