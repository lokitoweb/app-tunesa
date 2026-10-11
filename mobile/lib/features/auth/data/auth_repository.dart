import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';

import '../../../core/network/api_client.dart';
import '../../../core/network/api_endpoints.dart';
import '../../../core/storage/session_storage.dart';
import '../domain/models.dart';

class AuthRepository {
  AuthRepository(this._api, this._storage);

  final ApiClient _api;
  final SessionStorage _storage;

  /// Guarda la empresa elegida (la validación la hace AppConfigController
  /// contra `/v1/app/config`).
  Future<void> saveTenant(String slug, Branding branding) => _storage.saveTenant(slug, branding.toJson());

  /// Inicia sesión. Si la app aún no conoce la empresa (primer ingreso) usa el
  /// login por correo y devuelve también el código de la empresa del usuario.
  Future<({AppUser user, String tenantSlug})> login({required String email, required String password}) async {
    final knownTenant = _storage.tenantSlug;
    final json = await _api.post(
      ApiEndpoints.loginByEmail,
      auth: false,
      tenant: '',
      data: {
        'email': email.trim(),
        'password': password,
        'device_name': 'app_${defaultTargetPlatform.name}_${await _storage.installationId()}',
      },
    );
    final data = Map<String, dynamic>.from(json['data'] as Map);
    await _storage.saveToken(data['token'] as String);

    final userJson = Map<String, dynamic>.from(data['user'] as Map);
    final user = AppUser.fromJson(userJson);
    await _storage.saveUser(user.toJson());
    final slug = (userJson['company'] as Map?)?['slug']?.toString() ?? knownTenant ?? '';
    return (user: user, tenantSlug: slug);
  }

  /// Refresca los datos del usuario; lanza ApiException 401 si el token caducó.
  Future<AppUser> me() async {
    final json = await _api.get(ApiEndpoints.me);
    final user = AppUser.fromJson(Map<String, dynamic>.from(json['data'] as Map));
    await _storage.saveUser(user.toJson());
    return user;
  }

  /// Sube la foto de perfil (multipart por POST) y devuelve el usuario actualizado.
  Future<AppUser> uploadAvatar(List<int> bytes) async {
    final json = await _api.postMultipart(
      ApiEndpoints.profile,
      FormData.fromMap({'avatar': MultipartFile.fromBytes(bytes, filename: 'avatar.jpg')}),
    );
    final user = AppUser.fromJson(Map<String, dynamic>.from(json['data'] as Map));
    await _storage.saveUser(user.toJson());
    return user;
  }

  Future<void> changePassword({required String current, required String next}) => _api.put(
        ApiEndpoints.changePassword,
        data: {'current_password': current, 'new_password': next, 'new_password_confirmation': next},
      );

  Future<void> forgotPassword(String email) => _api.post(
        ApiEndpoints.forgotPasswordByEmail,
        auth: false,
        tenant: '',
        data: {'email': email.trim()},
      );

  Future<void> logout() async {
    try {
      await _api.post(ApiEndpoints.logout);
    } catch (_) {
      // Sin red o token ya inválido: igual se limpia la sesión local.
    }
    await _storage.clearSession();
  }
}
