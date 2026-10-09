import 'package:dio/dio.dart';

/// Error de red o de negocio ya traducido a un mensaje para el colaborador.
///
/// El backend responde `{ success: false, message, code, errors? }`; aquí se
/// conserva el `code` (ej: GEOFENCE_VIOLATION, MOCK_LOCATION_DETECTED) para
/// que la UI pueda reaccionar a casos concretos.
class ApiException implements Exception {
  const ApiException(
    this.message, {
    this.code,
    this.statusCode,
    this.fieldErrors = const {},
  });

  final String message;
  final String? code;
  final int? statusCode;
  final Map<String, List<String>> fieldErrors;

  bool get isUnauthorized => statusCode == 401;
  bool get isNetwork => code == 'NETWORK' || code == 'TIMEOUT';

  /// Primer error de validación de un campo, si lo hay.
  String? fieldError(String field) => fieldErrors[field]?.first;

  factory ApiException.fromDio(DioException e) {
    switch (e.type) {
      case DioExceptionType.connectionTimeout:
      case DioExceptionType.sendTimeout:
      case DioExceptionType.receiveTimeout:
      case DioExceptionType.transformTimeout:
        return const ApiException(
          'El servidor tardó demasiado en responder. Revisa tu conexión e inténtalo de nuevo.',
          code: 'TIMEOUT',
        );
      case DioExceptionType.connectionError:
        return const ApiException(
          'Sin conexión con el servidor. Verifica tu internet o datos móviles.',
          code: 'NETWORK',
        );
      case DioExceptionType.badCertificate:
        return const ApiException(
          'No se pudo verificar la seguridad del servidor (certificado inválido).',
          code: 'BAD_CERTIFICATE',
        );
      case DioExceptionType.cancel:
        return const ApiException('Solicitud cancelada.', code: 'CANCELLED');
      case DioExceptionType.badResponse:
      case DioExceptionType.unknown:
        break;
    }

    final response = e.response;
    final status = response?.statusCode;
    final data = response?.data;

    if (data is Map) {
      final errors = <String, List<String>>{};
      final rawErrors = data['errors'];
      if (rawErrors is Map) {
        rawErrors.forEach((key, value) {
          if (value is List) errors['$key'] = value.map((v) => '$v').toList();
        });
      }

      final message = errors.isNotEmpty ? errors.values.first.first : data['message']?.toString();

      return ApiException(
        message ?? _fallbackMessage(status),
        code: data['code']?.toString(),
        statusCode: status,
        fieldErrors: errors,
      );
    }

    if (response == null) {
      return const ApiException(
        'Sin conexión con el servidor. Verifica tu internet o datos móviles.',
        code: 'NETWORK',
      );
    }

    return ApiException(_fallbackMessage(status), statusCode: status);
  }

  static String _fallbackMessage(int? status) => switch (status) {
        401 => 'Tu sesión expiró. Vuelve a iniciar sesión.',
        403 => 'No tienes permiso para realizar esta acción.',
        404 => 'No se encontró el recurso solicitado.',
        422 => 'Revisa los datos enviados.',
        429 => 'Demasiados intentos. Espera un momento e inténtalo de nuevo.',
        final s? when s >= 500 => 'El servidor tuvo un problema. Inténtalo más tarde.',
        _ => 'Ocurrió un error inesperado.',
      };

  @override
  String toString() => message;
}
