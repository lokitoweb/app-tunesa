import 'dart:io';
import 'package:dio/dio.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../constants/api_constants.dart';

/// Cliente HTTP robusto con Dio para comunicación con el backend Laravel
class ApiClient {
  static final ApiClient _instance = ApiClient._internal();
  factory ApiClient() => _instance;

  late final Dio _dio;

  ApiClient._internal() {
    _dio = Dio(
      BaseOptions(
        baseUrl: ApiConstants.baseUrl,
        connectTimeout: const Duration(seconds: 15),
        receiveTimeout: const Duration(seconds: 15),
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
        },
      ),
    );

    // Interceptor para inyectar Token Sanctum y Tenant Context
    _dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (options, handler) async {
          final prefs = await SharedPreferences.getInstance();
          final token = prefs.getString(ApiConstants.tokenKey);
          final tenantId = prefs.getString(ApiConstants.tenantIdKey);

          if (token != null && token.isNotEmpty) {
            options.headers['Authorization'] = 'Bearer $token';
          }

          if (tenantId != null && tenantId.isNotEmpty) {
            options.headers['X-Tenant-ID'] = tenantId;
          }

          return handler.next(options);
        },
        onError: (DioException error, handler) {
          // Log controlado
          return handler.next(error);
        },
      ),
    );
  }

  Dio get dio => _dio;

  /// Petición GET
  Future<Response> get(String path, {Map<String, dynamic>? queryParameters}) async {
    try {
      return await _dio.get(path, queryParameters: queryParameters);
    } catch (e) {
      rethrow;
    }
  }

  /// Petición POST JSON
  Future<Response> post(String path, {dynamic data}) async {
    try {
      return await _dio.post(path, data: data);
    } catch (e) {
      rethrow;
    }
  }

  /// Petición POST Multipart (para envío de Selfie de marcación o archivos)
  Future<Response> postMultipart(
    String path, {
    required Map<String, dynamic> fields,
    File? file,
    String fileField = 'photo',
  }) async {
    try {
      final Map<String, dynamic> formMap = Map.from(fields);

      if (file != null && await file.exists()) {
        final fileName = file.path.split('/').last.split('\\').last;
        formMap[fileField] = await MultipartFile.fromFile(
          file.path,
          filename: fileName,
        );
      }

      final formData = FormData.fromMap(formMap);

      return await _dio.post(
        path,
        data: formData,
        options: Options(
          headers: {
            'Content-Type': 'multipart/form-data',
          },
        ),
      );
    } catch (e) {
      rethrow;
    }
  }
}
