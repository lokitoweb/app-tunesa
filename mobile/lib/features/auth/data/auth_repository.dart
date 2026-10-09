import 'package:flutter/foundation.dart';

import '../../../core/network/api_client.dart';
import '../../../core/network/api_endpoints.dart';
import '../../../core/storage/session_storage.dart';
import '../domain/models.dart';

class AuthRepository {
  AuthRepository(this._api, this._storage);

  final ApiClient _api;
  final SessionStorage _storage;

  /// Valida el código de empresa y guarda su marca blanca.
  Future<Branding> selectTenant(String slug) async {
    final clean = slug.trim().toLowerCase();
    final json = await _api.getPublic(ApiEndpoints.branding, tenant: clean);
    final branding = Branding.fromJson(Map<String, dynamic>.from(json['data'] as Map));
    await _storage.saveTenant(clean, branding.toJson());
    return branding;
  }

  Future<AppUser> login({required String email, required String password}) async {
    final json = await _api.post(
      ApiEndpoints.login,
      auth: false,
      data: {
        'email': email.trim(),
        'password': password,
        'device_name': 'app_${defaultTargetPlatform.name}_${await _storage.installationId()}',
      },
    );
    final data = Map<String, dynamic>.from(json['data'] as Map);
    await _storage.saveToken(data['token'] as String);

    final user = AppUser.fromJson(Map<String, dynamic>.from(data['user'] as Map));
    await _storage.saveUser(user.toJson());
    return user;
  }

  /// Refresca los datos del usuario; lanza ApiException 401 si el token caducó.
  Future<AppUser> me() async {
    final json = await _api.get(ApiEndpoints.me);
    final user = AppUser.fromJson(Map<String, dynamic>.from(json['data'] as Map));
    await _storage.saveUser(user.toJson());
    return user;
  }

  Future<void> changePassword({required String current, required String next}) => _api.put(
        ApiEndpoints.changePassword,
        data: {'current_password': current, 'new_password': next, 'new_password_confirmation': next},
      );

  Future<void> forgotPassword(String email) => _api.post(ApiEndpoints.forgotPassword, auth: false, data: {'email': email.trim()});

  Future<void> logout() async {
    try {
      await _api.post(ApiEndpoints.logout);
    } catch (_) {
      // Sin red o token ya inválido: igual se limpia la sesión local.
    }
    await _storage.clearSession();
  }
}
