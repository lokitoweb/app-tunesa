import 'package:dio/dio.dart';

import '../config/env.dart';
import '../storage/session_storage.dart';
import 'api_exception.dart';

/// Cliente HTTP único de la app.
///
/// - Agrega `Authorization: Bearer`, `X-Tenant-Slug` y `Accept: application/json`.
/// - Convierte todo error de Dio en [ApiException] con mensaje en español.
/// - Ante un 401 en una ruta autenticada avisa con [onUnauthorized] para
///   cerrar la sesión y volver al login.
class ApiClient {
  ApiClient(this._storage, {this.onUnauthorized, Dio? dio})
      : _dio = dio ??
            Dio(BaseOptions(
              baseUrl: Env.apiUrl,
              connectTimeout: Env.connectTimeout,
              receiveTimeout: Env.receiveTimeout,
              sendTimeout: Env.receiveTimeout,
              headers: {'Accept': 'application/json'},
            )) {
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
        handler.next(options);
      },
      onError: (error, handler) {
        if (error.response?.statusCode == 401 && error.requestOptions.extra['auth'] != false) {
          onUnauthorized?.call();
        }
        handler.next(error);
      },
    ));
  }

  final Dio _dio;
  final SessionStorage _storage;
  final void Function()? onUnauthorized;

  Future<Map<String, dynamic>> get(String path, {Map<String, dynamic>? query}) =>
      _send(() => _dio.get(path, queryParameters: query));

  Future<Map<String, dynamic>> post(String path, {Object? data, bool auth = true, String? tenant}) =>
      _send(() => _dio.post(path, data: data, options: Options(extra: {'auth': auth, 'tenant': ?tenant})));

  Future<Map<String, dynamic>> put(String path, {Object? data}) => _send(() => _dio.put(path, data: data));

  Future<Map<String, dynamic>> delete(String path) => _send(() => _dio.delete(path));

  /// GET sin token con un slug de empresa explícito (antes de elegir empresa).
  Future<Map<String, dynamic>> getPublic(String path, {required String tenant}) =>
      _send(() => _dio.get(path, options: Options(extra: {'auth': false, 'tenant': tenant})));

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
