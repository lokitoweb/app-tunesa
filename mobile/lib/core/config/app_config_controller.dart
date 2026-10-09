import 'dart:async';
import 'dart:convert';

import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../network/api_client.dart';
import '../network/api_endpoints.dart';
import '../network/api_exception.dart';
import '../network/server_url.dart';
import '../providers.dart';
import 'app_config.dart';
import 'env.dart';

/// Descarga y cachea la configuración pública de la app.
class AppConfigRepository {
  AppConfigRepository(this._api, this._prefs);

  final ApiClient _api;
  final SharedPreferences _prefs;

  static const cacheKey = 'app_config_cache';

  /// Última configuración buena guardada para [slug] (null = plataforma).
  AppConfig? cached(String? slug) {
    final raw = _prefs.getString(cacheKey);
    if (raw == null) return null;
    try {
      final json = Map<String, dynamic>.from(jsonDecode(raw) as Map);
      if ((json['slug'] ?? '') != (slug ?? '')) return null;
      return AppConfig.fromJson(Map<String, dynamic>.from(json['config'] as Map));
    } catch (_) {
      return null;
    }
  }

  Future<void> save(String? slug, AppConfig config) =>
      _prefs.setString(cacheKey, jsonEncode({'slug': slug ?? '', 'config': config.toJson()}));

  /// `GET /v1/app/config?tenant_slug=`. Lanza [ApiException] (404
  /// TENANT_NOT_FOUND si la empresa no existe). Si el backend todavía no
  /// tiene esa ruta (404 sin código) usa `/tenant/branding`.
  Future<AppConfig> fetch(String? slug) async {
    try {
      final json = await _api.getAnonymous(ApiEndpoints.appConfig, query: slug == null ? null : {'tenant_slug': slug});
      return AppConfig.fromJson(json);
    } on ApiException catch (e) {
      if (slug == null || e.statusCode != 404 || e.code == 'TENANT_NOT_FOUND') rethrow;
      final legacy = await _api.getPublic(ApiEndpoints.branding, tenant: slug);
      return AppConfig.fromLegacyBranding(Map<String, dynamic>.from(legacy['data'] as Map));
    }
  }
}

final appConfigRepositoryProvider = Provider<AppConfigRepository>(
  (ref) => AppConfigRepository(ref.watch(apiClientProvider), ref.watch(sharedPreferencesProvider)),
);

class AppConfigState {
  const AppConfigState({required this.config, required this.ready});

  final AppConfig config;

  /// false solo en el primer arranque sin caché, mientras se descarga
  /// (la app muestra el splash como máximo [AppConfigController.splashTimeout]).
  final bool ready;
}

/// Configuración vigente: caché inmediata y refresco en segundo plano al
/// abrir la app y al volver a ella. Si falla la red se conserva la caché y,
/// sin caché, los valores por defecto.
class AppConfigController extends Notifier<AppConfigState> {
  static const splashTimeout = Duration(seconds: 4);

  Future<void>? _inFlight;
  Timer? _splashTimer;

  @override
  AppConfigState build() {
    final cached = _cachedFor(currentSlug);

    final lifecycle = AppLifecycleListener(onResume: () => refresh());
    ref.onDispose(() {
      lifecycle.dispose();
      _splashTimer?.cancel();
    });

    if (cached == null) {
      _splashTimer = Timer(splashTimeout, _markReady);
    }
    Future.microtask(refresh);
    return AppConfigState(config: cached ?? AppConfig.defaults, ready: cached != null);
  }

  /// Empresa guardada o la fija del build (DEFAULT_TENANT).
  String? get currentSlug {
    final saved = ref.read(sessionStorageProvider).tenantSlug;
    if (saved != null && saved.isNotEmpty) return saved;
    return Env.defaultTenant.isEmpty ? null : Env.defaultTenant;
  }

  AppConfigRepository get _repo => ref.read(appConfigRepositoryProvider);

  AppConfig? _cachedFor(String? slug) {
    final cached = _repo.cached(slug);
    if (cached != null) return cached;
    // Instalaciones anteriores: marca guardada por /tenant/branding
    final legacy = ref.read(sessionStorageProvider).branding;
    if (slug != null && legacy != null && legacy['slug'] == slug) return AppConfig.fromLegacyBranding(legacy);
    return null;
  }

  void _markReady() {
    _splashTimer?.cancel();
    if (!state.ready && ref.mounted) state = AppConfigState(config: state.config, ready: true);
  }

  /// Refresca desde el servidor; nunca lanza.
  Future<void> refresh() => _inFlight ??= _refresh().whenComplete(() => _inFlight = null);

  Future<void> _refresh() async {
    final slug = currentSlug;
    try {
      final config = await _repo.fetch(slug);
      if (!ref.mounted || slug != currentSlug) return;
      await _repo.save(slug, config);
      _splashTimer?.cancel();
      state = AppConfigState(config: config, ready: true);
      await _maybeSwitchServer(config);
    } catch (_) {
      // Sin red / servidor caído / empresa inexistente: se conserva lo que hay.
      _markReady();
    }
  }

  /// Valida una empresa (pantalla de código) y aplica su configuración.
  /// Lanza [ApiException] (404 si no existe).
  Future<AppConfig> loadForTenant(String slug) async {
    final config = await _repo.fetch(slug);
    await _repo.save(slug, config);
    _splashTimer?.cancel();
    state = AppConfigState(config: config, ready: true);
    unawaited(_maybeSwitchServer(config));
    return config;
  }

  /// Al cambiar de empresa vuelve a la marca de la plataforma.
  Future<void> resetToPlatform() async {
    state = AppConfigState(config: _cachedFor(currentSlug) ?? AppConfig.defaults, ready: true);
    _inFlight = null; // un refresco en curso de la empresa anterior se descarta solo
    await refresh();
  }

  /// Si el servidor anuncia otro dominio (`mobile.api_base_url`), se cambia
  /// solo tras comprobar que responde. Sin avisar al usuario.
  Future<void> _maybeSwitchServer(AppConfig config) async {
    final target = config.mobile.apiBaseUrl;
    if (target == null) return;
    final check = ServerUrl.validate(target);
    if (!check.isValid) return;
    final baseUrl = ref.read(baseUrlProvider.notifier);
    if (check.url == ref.read(baseUrlProvider)) return;
    try {
      if (await ref.read(serverHealthCheckProvider)(check.url!) && ref.mounted) {
        await baseUrl.setOverride(check.url!);
      }
    } catch (_) {
      // se reintenta en el próximo refresco
    }
  }
}

final appConfigProvider = NotifierProvider<AppConfigController, AppConfigState>(AppConfigController.new);
