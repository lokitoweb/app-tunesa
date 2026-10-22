import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../config/env.dart';
import '../providers.dart';

/// Resultado de validar una URL de servidor escrita por un tester o enviada
/// por el backend (`mobile.api_base_url`).
class ServerUrlCheck {
  const ServerUrlCheck.ok(String this.url) : error = null;
  const ServerUrlCheck.invalid(String this.error) : url = null;

  /// Dominio normalizado (sin `/api` ni barras finales).
  final String? url;
  final String? error;

  bool get isValid => url != null;
}

class ServerUrl {
  const ServerUrl._();

  /// Exige https; `http://` solo si [allowHttp] (builds dev).
  static ServerUrlCheck validate(String input, {bool? allowHttp}) {
    var raw = input.trim();
    if (raw.isEmpty) return const ServerUrlCheck.invalid('Escribe la URL del servidor.');
    // Solo el dominio ("erp.miempresa.com"): se asume https
    if (!raw.contains('://') && RegExp(r'^[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)+(:\d+)?(/.*)?$').hasMatch(raw)) {
      raw = 'https://$raw';
    }
    final uri = Uri.tryParse(raw);
    if (uri == null || !uri.hasScheme || uri.host.isEmpty) {
      return const ServerUrlCheck.invalid('Dominio inválido. Ejemplo: erp.miempresa.com');
    }
    final http = allowHttp ?? Env.allowsHttp;
    if (uri.scheme != 'https' && !(http && uri.scheme == 'http')) {
      return ServerUrlCheck.invalid(
          http ? 'La URL debe empezar con https:// o http://' : 'La URL debe empezar con https://');
    }
    if (uri.hasQuery || uri.hasFragment || uri.userInfo.isNotEmpty) {
      return const ServerUrlCheck.invalid('Escribe solo el dominio, sin parámetros.');
    }
    return ServerUrlCheck.ok(Env.normalizeBaseUrl(raw));
  }
}

/// Comprueba que un dominio responda: `GET {url}/up` o `GET {url}/api/v1/app/config`.
typedef ServerHealthCheck = Future<bool> Function(String baseUrl);

Future<bool> defaultServerHealthCheck(String baseUrl) async {
  final base = Env.normalizeBaseUrl(baseUrl);
  final dio = Dio(BaseOptions(
    connectTimeout: const Duration(seconds: 8),
    receiveTimeout: const Duration(seconds: 8),
    headers: {'Accept': 'application/json'},
    validateStatus: (_) => true,
  ));
  try {
    for (final url in ['$base/up', '${Env.apiUrlFor(base)}/v1/app/config']) {
      try {
        final response = await dio.get<dynamic>(url);
        if (response.statusCode == 200) return true;
      } on DioException {
        // probar la siguiente ruta
      }
    }
    return false;
  } finally {
    dio.close(force: true);
  }
}

final serverHealthCheckProvider = Provider<ServerHealthCheck>((ref) => defaultServerHealthCheck);

/// Dominio del backend en uso: override guardado (tester o servidor) o el
/// compilado en `Env.apiBaseUrl`. ApiClient lo escucha y cambia su baseUrl
/// sin reiniciar la app.
class BaseUrlController extends Notifier<String> {
  static const overrideKey = 'api_base_url_override';

  /// Fallos de red seguidos antes de probar el dominio compilado.
  static const failureThreshold = 3;

  int _failures = 0;
  bool _checkingFallback = false;

  @override
  String build() => _override ?? Env.normalizeBaseUrl(Env.apiBaseUrl);

  String? get _override {
    final saved = ref.read(sharedPreferencesProvider).getString(overrideKey);
    return saved == null || saved.isEmpty ? null : saved;
  }

  bool get hasOverride => _override != null;

  String get compiled => Env.normalizeBaseUrl(Env.apiBaseUrl);

  Future<void> setOverride(String url) async {
    final normalized = Env.normalizeBaseUrl(url);
    _failures = 0;
    if (normalized == compiled) return reset();
    await ref.read(sharedPreferencesProvider).setString(overrideKey, normalized);
    state = normalized;
  }

  Future<void> reset() async {
    _failures = 0;
    await ref.read(sharedPreferencesProvider).remove(overrideKey);
    state = compiled;
  }

  /// ApiClient informa cada respuesta (true) o error de conexión (false).
  /// Con override y varios fallos seguidos, vuelve al dominio compilado si
  /// este sí responde (si el teléfono está sin red, ninguno responde y se
  /// conserva el override).
  void reportNetworkResult({required bool ok}) {
    if (ok) {
      _failures = 0;
      return;
    }
    _failures++;
    if (_failures < failureThreshold || !hasOverride || _checkingFallback) return;
    _checkingFallback = true;
    ref.read(serverHealthCheckProvider)(compiled).then((healthy) async {
      if (healthy && ref.mounted) await reset();
    }).whenComplete(() {
      _checkingFallback = false;
      _failures = 0;
    });
  }
}

final baseUrlProvider = NotifierProvider<BaseUrlController, String>(BaseUrlController.new);
