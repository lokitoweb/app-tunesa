/// Modelo de Usuario / Colaborador
class UserModel {
  final int id;
  final int tenantId;
  final String firstName;
  final String lastName;
  final String email;
  final String role;
  final String? employeeCode;
  final String? position;
  final String? avatarUrl;
  final List<BranchAssignment> branches;

  UserModel({
    required this.id,
    required this.tenantId,
    required this.firstName,
    required this.lastName,
    required this.email,
    required this.role,
    this.employeeCode,
    this.position,
    this.avatarUrl,
    required this.branches,
  });

  String get fullName => '$firstName $lastName';

  factory UserModel.fromJson(Map<String, dynamic> json) {
    var rawBranches = json['branches'] as List? ?? [];
    List<BranchAssignment> branchList = rawBranches
        .map((b) => BranchAssignment.fromJson(b))
        .toList();

    return UserModel(
      id: json['id'],
      tenantId: json['tenant_id'] ?? 1,
      firstName: json['first_name'] ?? '',
      lastName: json['last_name'] ?? '',
      email: json['email'] ?? '',
      role: json['role'] ?? 'employee',
      employeeCode: json['employee_code'],
      position: json['position'],
      avatarUrl: json['avatar_path'],
      branches: branchList,
    );
  }
}

/// Sucursal asignada con su geocerca
class BranchAssignment {
  final int id;
  final String name;
  final String address;
  final double latitude;
  final double longitude;
  final int allowedRadiusMeters;
  final bool isPrimary;

  BranchAssignment({
    required this.id,
    required this.name,
    required this.address,
    required this.latitude,
    required this.longitude,
    required this.allowedRadiusMeters,
    required this.isPrimary,
  });

  factory BranchAssignment.fromJson(Map<String, dynamic> json) {
    return BranchAssignment(
      id: json['id'],
      name: json['name'] ?? '',
      address: json['address'] ?? '',
      latitude: double.tryParse(json['latitude']?.toString() ?? '0') ?? 0.0,
      longitude: double.tryParse(json['longitude']?.toString() ?? '0') ?? 0.0,
      allowedRadiusMeters: json['allowed_radius_meters'] is int
          ? json['allowed_radius_meters']
          : int.tryParse(json['allowed_radius_meters']?.toString() ?? '50') ?? 50,
      isPrimary: json['is_primary'] == true || json['pivot']?['is_primary'] == 1,
    );
  }
}
