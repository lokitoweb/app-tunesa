import 'dart:convert';
import 'dart:math';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Persistencia de la sesión.
///
/// - El token Sanctum va en almacenamiento seguro (Keychain / Keystore).
/// - Empresa, marca blanca y datos del usuario (no sensibles) en SharedPreferences.
class SessionStorage {
  SessionStorage(this._prefs, {FlutterSecureStorage? secure})
      : _secure = secure ?? const FlutterSecureStorage();

  final SharedPreferences _prefs;
  final FlutterSecureStorage _secure;

  static const _tokenKey = 'auth_token';
  static const _tenantKey = 'tenant_slug';
  static const _brandingKey = 'tenant_branding';
  static const _userKey = 'auth_user';
  static const _installationIdKey = 'installation_id';

  String? _tokenCache;

  // --- Token ---------------------------------------------------------------

  Future<String?> readToken() async => _tokenCache ??= await _secure.read(key: _tokenKey);

  Future<void> saveToken(String token) async {
    _tokenCache = token;
    await _secure.write(key: _tokenKey, value: token);
  }

  // --- Empresa ---------------------------------------------------------------

  String? get tenantSlug => _prefs.getString(_tenantKey);

  Map<String, dynamic>? get branding => _decode(_prefs.getString(_brandingKey));

  Future<void> saveTenant(String slug, Map<String, dynamic> branding) async {
    await _prefs.setString(_tenantKey, slug);
    await _prefs.setString(_brandingKey, jsonEncode(branding));
  }

  Future<void> clearTenant() async {
    await _prefs.remove(_tenantKey);
    await _prefs.remove(_brandingKey);
  }

  // --- Usuario ---------------------------------------------------------------

  Map<String, dynamic>? get user => _decode(_prefs.getString(_userKey));

  Future<void> saveUser(Map<String, dynamic> user) => _prefs.setString(_userKey, jsonEncode(user));

  /// Identificador estable de esta instalación (auditoría de marcaciones).
  Future<String> installationId() async {
    final existing = _prefs.getString(_installationIdKey);
    if (existing != null) return existing;

    final random = Random.secure();
    final id = List.generate(16, (_) => random.nextInt(256).toRadixString(16).padLeft(2, '0')).join();
    await _prefs.setString(_installationIdKey, id);
    return id;
  }

  /// Cierra la sesión: borra token y usuario, conserva la empresa elegida.
  Future<void> clearSession() async {
    _tokenCache = null;
    await _secure.delete(key: _tokenKey);
    await _prefs.remove(_userKey);
  }

  Map<String, dynamic>? _decode(String? raw) {
    if (raw == null) return null;
    try {
      return Map<String, dynamic>.from(jsonDecode(raw) as Map);
    } catch (_) {
      return null;
    }
  }
}
