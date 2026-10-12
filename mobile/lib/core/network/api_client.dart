import 'package:dio/dio.dart';

import '../config/env.dart';
import '../storage/session_storage.dart';
import 'api_exception.dart';

/// Cliente HTTP único de la app.
///
/// - Agrega `Authorization: Bearer`, `X-Tenant-Slug`, `X-App-Version` y
///   `Accept: application/json`.
/// - Convierte todo error de Dio en [ApiException] con mensaje en español.
/// - Ante un 401 en una ruta autenticada avisa con [onUnauthorized] para
///   cerrar la sesión y volver al login.
/// - Ante un 426 (APP_UPDATE_REQUIRED) avisa con [onUpdateRequired].
/// - El dominio puede cambiar en caliente con [baseUrl] (override de testers
///   o `mobile.api_base_url` del servidor).
class ApiClient {
  ApiClient(
    this._storage, {
    this.onUnauthorized,
    this.onUpdateRequired,
    this.onNetworkResult,
    String? baseUrl,
    String? appVersion,
    Dio? dio,
  }) : _dio = dio ??
            Dio(BaseOptions(
              baseUrl: baseUrl == null ? Env.apiUrl : Env.apiUrlFor(baseUrl),
              connectTimeout: Env.connectTimeout,
              receiveTimeout: Env.receiveTimeout,
              sendTimeout: Env.receiveTimeout,
              headers: {'Accept': 'application/json'},
            )) {
    if (appVersion != null) _dio.options.headers['X-App-Version'] = appVersion;
    _dio.interceptors.add(InterceptorsWrapper(
      onRequest: (options, handler) async {
        final token = await _storage.readToken();
        final slug = options.extra['tenant'] as String? ?? _storage.tenantSlug;

        if (token != null && options.extra['auth'] != false) {
          options.headers['Authorization'] = 'Bearer $token';
        }
        if (slug != null && slug.isNotEmpty) {
          options.headers['X-Tenant-Slug'] = slug;
        }
        if (options.extra['anonymous'] == true) {
          options.headers.remove('X-Device-Integrity');
        }
        handler.next(options);
      },
      onResponse: (response, handler) {
        onNetworkResult?.call(true);
        handler.next(response);
      },
      onError: (error, handler) {
        final status = error.response?.statusCode;
        if (status == 401 && error.requestOptions.extra['auth'] != false) {
          onUnauthorized?.call();
        }
        if (status == 426) {
          final body = error.response?.data;
          final data = body is Map && body['data'] is Map ? body['data'] as Map : const {};
          onUpdateRequired?.call(data['min_supported_version']?.toString(), data['latest_version']?.toString());
        }
        if (error.response != null) {
          onNetworkResult?.call(true);
        } else if (const {
          DioExceptionType.connectionError,
          DioExceptionType.connectionTimeout,
          DioExceptionType.unknown,
        }.contains(error.type)) {
          onNetworkResult?.call(false);
        }
        handler.next(error);
      },
    ));
  }

  /// Señales de integridad del teléfono (cabecera X-Device-Integrity).
  set deviceFlags(List<String> flags) {
    if (flags.isEmpty) {
      _dio.options.headers.remove('X-Device-Integrity');
    } else {
      _dio.options.headers['X-Device-Integrity'] = flags.join(',');
    }
  }

  /// Cambia el dominio del backend sin recrear el cliente.
  set baseUrl(String base) => _dio.options.baseUrl = Env.apiUrlFor(base);

  String get apiUrl => _dio.options.baseUrl;

  final Dio _dio;
  final SessionStorage _storage;
  final void Function()? onUnauthorized;
  final void Function(String? minVersion, String? latestVersion)? onUpdateRequired;
  final void Function(bool ok)? onNetworkResult;

  Future<Map<String, dynamic>> get(String path, {Map<String, dynamic>? query}) =>
      _send(() => _dio.get(path, queryParameters: query));

  Future<Map<String, dynamic>> post(String path, {Object? data, bool auth = true, String? tenant}) =>
      _send(() => _dio.post(path, data: data, options: Options(extra: {'auth': auth, 'tenant': ?tenant})));

  Future<Map<String, dynamic>> put(String path, {Object? data}) => _send(() => _dio.put(path, data: data));

  Future<Map<String, dynamic>> delete(String path) => _send(() => _dio.delete(path));

  /// GET sin token con un slug de empresa explícito (antes de elegir empresa).
  Future<Map<String, dynamic>> getPublic(String path, {required String tenant}) =>
      _send(() => _dio.get(path, options: Options(extra: {'auth': false, 'tenant': tenant})));

  /// GET público sin token ni empresa (configuración de arranque).
  Future<Map<String, dynamic>> getAnonymous(String path, {Map<String, dynamic>? query}) =>
      _send(() => _dio.get(path, queryParameters: query, options: Options(extra: {'auth': false, 'tenant': ''})));

  /// Envío multipart (selfie de marcación, sustentos).
  Future<Map<String, dynamic>> postMultipart(String path, FormData form) =>
      _send(() => _dio.post(path, data: form));

  /// Descarga binaria autenticada (PDF de boletas). No se guarda en disco.
  Future<List<int>> download(String path) async {
    try {
      final response = await _dio.get<List<int>>(
        path,
        options: Options(responseType: ResponseType.bytes, headers: {'Accept': 'application/pdf'}),
      );
      return response.data ?? const [];
    } on DioException catch (e) {
      throw ApiException.fromDio(e);
    }
  }

  /// Descarga una URL firmada temporal (legajo). No lleva token: la firma
  /// es la autorización y vence en minutos.
  Future<List<int>> downloadSigned(String url) async {
    try {
      final response = await _dio.get<List<int>>(
        url,
        options: Options(responseType: ResponseType.bytes, extra: {'auth': false}),
      );
      return response.data ?? const [];
    } on DioException catch (e) {
      throw ApiException.fromDio(e);
    }
  }

  /// Canal ético: sin `Authorization` ni señales del teléfono, solo la empresa.
  /// El servidor además descarta IP y user agent de estas rutas.
  Future<Map<String, dynamic>> anonymous(String method, String path, {Object? data, Map<String, String> headers = const {}}) {
    final options = Options(method: method, extra: {'auth': false, 'anonymous': true}, headers: headers);
    return _send(() => _dio.request(path, data: data, options: options));
  }

  Future<Map<String, dynamic>> _send(Future<Response<dynamic>> Function() request) async {
    try {
      final response = await request();
      final data = response.data;
      if (data is Map<String, dynamic>) return data;
      if (data is Map) return Map<String, dynamic>.from(data);
      return {'data': data};
    } on DioException catch (e) {
      throw ApiException.fromDio(e);
    }
  }
}
