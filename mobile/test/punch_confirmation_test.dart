import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:tunesa_mobile/core/network/api_client.dart';
import 'package:tunesa_mobile/core/network/api_exception.dart';
import 'package:tunesa_mobile/core/storage/session_storage.dart';
import 'package:tunesa_mobile/features/attendance/data/attendance_repository.dart';
import 'package:tunesa_mobile/features/attendance/domain/models.dart';
import 'package:tunesa_mobile/features/attendance/domain/punch.dart';

/// Respuestas en orden: un int es el status (con JSON), 'timeout' simula la señal lenta.
class _Script implements HttpClientAdapter {
  _Script(this.replies);

  final List<Object> replies;
  final requests = <RequestOptions>[];

  @override
  Future<ResponseBody> fetch(RequestOptions options, Stream<Uint8List>? requestStream, Future<void>? cancelFuture) async {
    requests.add(options);
    final reply = replies.removeAt(0);
    if (reply == 'timeout') {
      throw DioException(requestOptions: options, type: DioExceptionType.receiveTimeout);
    }
    final status = reply as int;
    final body = status == 404
        ? {'success': false, 'message': 'La marcación aún no llega al servidor.', 'code': 'PUNCH_NOT_FOUND'}
        : {'success': true, 'duplicate': status == 200, 'message': '✅ Entrada registrada correctamente.'};
    return ResponseBody.fromString(jsonEncode(body), status, headers: {Headers.contentTypeHeader: ['application/json']});
  }

  @override
  void close({bool force = false}) {}
}

Future<(AttendanceRepository, _Script)> _repo(List<Object> replies) async {
  FlutterSecureStorage.setMockInitialValues({});
  SharedPreferences.setMockInitialValues({'tenant_slug': 'acme'});
  final storage = SessionStorage(await SharedPreferences.getInstance());
  final script = _Script(replies);
  final dio = Dio(BaseOptions(baseUrl: 'https://api.test/api'))..httpClientAdapter = script;
  return (AttendanceRepository(ApiClient(storage, dio: dio), storage), script);
}

PunchCapture _capture() => PunchCapture(
      action: ClockAction.clockIn,
      latitude: -12.0765,
      longitude: -77.0266,
      accuracy: 26,
      isMocked: false,
      deviceTime: DateTime.utc(2026, 11, 17, 18, 31, 44),
    );

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  const fast = [Duration.zero, Duration.zero, Duration.zero];

  test('sin respuesta pero la marca llegó: se confirma sin reenviar la foto', () async {
    final (repo, script) = await _repo(['timeout', 404, 200]);
    var confirming = false;
    final capture = _capture();

    final outcome = await repo.sendPunchConfirmed(capture, onConfirming: () => confirming = true, waits: fast);

    expect(outcome.duplicate, isTrue);
    expect(confirming, isTrue);
    expect(script.requests.map((r) => '${r.method} ${r.path}'), [
      'POST /attendance/clock-in',
      'GET /attendance/punches/${capture.clientUuid}',
      'GET /attendance/punches/${capture.clientUuid}',
    ]);
  });

  test('no llegó: se reenvía una sola vez con el mismo identificador', () async {
    final (repo, script) = await _repo(['timeout', 404, 404, 404, 201]);

    final outcome = await repo.sendPunchConfirmed(_capture(), waits: fast);

    expect(outcome.duplicate, isFalse);
    expect(script.requests.where((r) => r.method == 'POST').length, 2);
  });

  test('el identificador de cada marcación es un UUID v4 válido y único', () {
    final ids = List.generate(500, (_) => newClientUuid());
    final v4 = RegExp(r'^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$');
    expect(ids.every(v4.hasMatch), isTrue);
    expect(ids.toSet(), hasLength(500));
  });

  test('un error de negocio no se confirma: sube a la pantalla', () async {
    final (repo, script) = await _repo([422]);

    await expectLater(repo.sendPunchConfirmed(_capture(), waits: fast), throwsA(isA<ApiException>()));
    expect(script.requests.length, 1);
  });
}
