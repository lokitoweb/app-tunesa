import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:tunesa_mobile/core/config/env.dart';
import 'package:tunesa_mobile/core/location/location_service.dart';
import 'package:tunesa_mobile/core/network/api_exception.dart';
import 'package:tunesa_mobile/core/providers.dart';
import 'package:tunesa_mobile/core/theme/app_theme.dart';

DioException _response(int status, Object? data) {
  final options = RequestOptions(path: '/x');
  return DioException(
    requestOptions: options,
    type: DioExceptionType.badResponse,
    response: Response(requestOptions: options, statusCode: status, data: data),
  );
}

void main() {
  group('Env', () {
    test('apiUrl agrega /api a la URL base sin duplicar la barra', () {
      expect(Env.apiUrl, '${Env.apiBaseUrl.replaceAll(RegExp(r'/+$'), '')}/api');
      expect(Env.apiUrl.endsWith('//api'), isFalse);
      expect(Env.apiUrl.endsWith('/api/api'), isFalse);
    });
  });

  group('LocationService.haversineMeters', () {
    test('mismo punto es 0 m', () {
      expect(LocationService.haversineMeters(-12.0464, -77.0428, -12.0464, -77.0428), 0);
    });

    test('Plaza de Armas de Lima a Plaza San Martín ~ 810 m', () {
      final d = LocationService.haversineMeters(-12.0453, -77.0311, -12.0517, -77.0347);
      expect(d, closeTo(810, 60));
    });

    test('un grado de latitud ~ 111 km', () {
      expect(LocationService.haversineMeters(0, 0, 1, 0), closeTo(111195, 10));
    });
  });

  group('ApiException.fromDio', () {
    test('sin conexión se marca como error de red', () {
      final e = ApiException.fromDio(DioException.connectionError(
        requestOptions: RequestOptions(path: '/x'),
        reason: 'refused',
      ));
      expect(e.isNetwork, isTrue);
      expect(e.message, contains('Sin conexión'));
    });

    test('timeout se marca como error de red', () {
      final e = ApiException.fromDio(DioException.connectionTimeout(
        requestOptions: RequestOptions(path: '/x'),
        timeout: const Duration(seconds: 1),
      ));
      expect(e.code, 'TIMEOUT');
      expect(e.isNetwork, isTrue);
    });

    test('conserva mensaje y código de negocio del backend', () {
      final e = ApiException.fromDio(_response(422, {
        'success': false,
        'message': 'Se detectó una ubicación simulada.',
        'code': 'MOCK_LOCATION_DETECTED',
      }));
      expect(e.code, 'MOCK_LOCATION_DETECTED');
      expect(e.statusCode, 422);
      expect(e.message, 'Se detectó una ubicación simulada.');
    });

    test('errores de validación por campo', () {
      final e = ApiException.fromDio(_response(422, {
        'message': 'The given data was invalid.',
        'errors': {
          'email': ['Las credenciales proporcionadas son incorrectas.'],
        },
      }));
      expect(e.fieldError('email'), 'Las credenciales proporcionadas son incorrectas.');
      expect(e.message, 'Las credenciales proporcionadas son incorrectas.');
    });

    test('respuesta sin JSON usa mensaje por código HTTP', () {
      final e = ApiException.fromDio(_response(500, '<html>'));
      expect(e.message, contains('servidor'));
      expect(ApiException.fromDio(_response(401, null)).isUnauthorized, isTrue);
    });
  });

  group('AppTheme.parseHex', () {
    test('convierte colores de marca', () {
      expect(AppTheme.parseHex('#1E40AF'), const Color(0xFF1E40AF));
      expect(AppTheme.parseHex('1e40af'), const Color(0xFF1E40AF));
    });

    test('ignora valores inválidos', () {
      expect(AppTheme.parseHex(null), isNull);
      expect(AppTheme.parseHex('#FFF'), isNull);
      expect(AppTheme.parseHex('#GGGGGG'), isNull);
    });
  });

  test('providerRetry: un error del servidor (p. ej. código ético incorrecto) se muestra sin reintentar', () {
    expect(providerRetry(0, const ApiException('No encontrado', statusCode: 404)), isNull);
    expect(providerRetry(0, const ApiException('Sin conexión', code: 'NETWORK')), isNotNull);
    expect(providerRetry(1, const ApiException('Sin conexión', code: 'NETWORK')), isNull);
  });
}
