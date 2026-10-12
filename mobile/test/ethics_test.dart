import 'dart:convert';
import 'dart:math';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:tunesa_mobile/core/config/app_config.dart';
import 'package:tunesa_mobile/core/network/api_client.dart';
import 'package:tunesa_mobile/core/storage/secure_key_store.dart';
import 'package:tunesa_mobile/core/storage/session_storage.dart';
import 'package:tunesa_mobile/core/theme/app_theme.dart';
import 'package:tunesa_mobile/features/ethics/data/ethics_repository.dart';
import 'package:tunesa_mobile/features/ethics/data/ethics_vault.dart';
import 'package:tunesa_mobile/features/ethics/domain/models.dart';
import 'package:tunesa_mobile/features/ethics/presentation/ethics_case_screen.dart';
import 'package:tunesa_mobile/features/ethics/presentation/ethics_providers.dart';
import 'package:tunesa_mobile/features/ethics/presentation/ethics_report_screen.dart';
import 'package:tunesa_mobile/features/notifications/domain/models.dart';

class _Adapter implements HttpClientAdapter {
  final requests = <RequestOptions>[];

  @override
  Future<ResponseBody> fetch(RequestOptions options, Stream<Uint8List>? requestStream, Future<void>? cancelFuture) async {
    requests.add(options);
    return ResponseBody.fromString(
      jsonEncode({'success': true, 'data': _case}),
      options.method == 'POST' ? 201 : 200,
      headers: {Headers.contentTypeHeader: ['application/json']},
    );
  }

  @override
  void close({bool force = false}) {}
}

class _MemoryStore implements SecureKeyValueStore {
  final values = <String, String>{};

  @override
  Future<String?> read(String key) async => values[key];

  @override
  Future<void> write(String key, String value) async => values[key] = value;

  @override
  Future<void> delete(String key) async => values.remove(key);
}

const _case = {
  'code': 'ET-7KQ4PX',
  'token': 'AAAA-BBBB-CCCC-DDDD-EEEE-FFFF',
  'category_label': 'Acoso laboral o sexual',
  'subject': 'Trato humillante',
  'body': 'El encargado grita al personal.',
  'status': 'needs_info',
  'reported_on': '2026-10-12',
  'resolution': null,
  'photos': 1,
  'messages': [
    {'from': 'investigator', 'body': '¿Hubo testigos?', 'sent_on': '2026-10-12', 'photos': 0},
  ],
};

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  setUpAll(() => initializeDateFormatting('es'));

  test('lee el caso, el estado y la conversación', () {
    final c = EthicsCaseView.fromJson(Map<String, dynamic>.from(_case));
    expect(c.status, EthicsStatus.needsInfo);
    expect(c.messages.single.fromReporter, isFalse);
    expect(c.isClosed, isFalse);
    expect(EthicsStatus.fromApi('closed').label, 'Cerrada');
    expect(const ModulesConfig().ethics, isTrue);
    expect(ModulesConfig.fromJson({'ethics': false}).ethics, isFalse);
  });

  test('las llamadas van sin sesión ni señales del teléfono, con el token en cabecera', () async {
    FlutterSecureStorage.setMockInitialValues({});
    SharedPreferences.setMockInitialValues({'tenant_slug': 'acme'});
    final storage = SessionStorage(await SharedPreferences.getInstance());
    await storage.saveToken('secreto-de-sesion');
    final adapter = _Adapter();
    final dio = Dio(BaseOptions(baseUrl: 'https://api.test/api'))..httpClientAdapter = adapter;
    final api = ApiClient(storage, dio: dio)..deviceFlags = ['rooted'];
    final repo = EthicsRepository(api);

    await repo.lookup('et-7kq4px', 'AAAA-BBBB-CCCC-DDDD-EEEE-FFFF');
    await repo.report(category: 'harassment', subject: 'Trato humillante', body: 'Detalle suficiente para enviar', channel: 'canal');

    for (final r in adapter.requests) {
      expect(r.headers.containsKey('Authorization'), isFalse);
      expect(r.headers.containsKey('X-Device-Integrity'), isFalse);
    }
    expect(adapter.requests.first.path, '/ethics/cases/ET-7KQ4PX');
    expect(adapter.requests.first.headers['X-Ethics-Token'], 'AAAA-BBBB-CCCC-DDDD-EEEE-FFFF');
  });

  test('solo la denuncia identificada (opcional) va con sesión y a su propia ruta', () async {
    FlutterSecureStorage.setMockInitialValues({});
    SharedPreferences.setMockInitialValues({'tenant_slug': 'acme'});
    final storage = SessionStorage(await SharedPreferences.getInstance());
    await storage.saveToken('secreto-de-sesion');
    final adapter = _Adapter();
    final dio = Dio(BaseOptions(baseUrl: 'https://api.test/api'))..httpClientAdapter = adapter;
    final repo = EthicsRepository(ApiClient(storage, dio: dio));

    await repo.report(category: 'theft', subject: 'Faltante', body: 'Detalle suficiente para enviar', identified: true);

    expect(adapter.requests.single.path, '/ethics/cases/identified');
    expect(adapter.requests.single.headers['Authorization'], 'Bearer secreto-de-sesion');
  });

  test('el cofre guarda y quita denuncias; el canal es aleatorio', () async {
    final vault = EthicsVault(_MemoryStore());
    const item = SavedEthicsCase(code: 'ET-7KQ4PX', token: 'T', subject: 'Asunto', channel: 'abc');
    await vault.save(item);
    await vault.save(item);
    expect((await vault.list()).single.channel, 'abc');
    await vault.remove('ET-7KQ4PX');
    expect(await vault.list(), isEmpty);

    final channel = const EthicsTopics().newChannel(Random(1));
    expect(channel, matches(RegExp(r'^[a-z0-9]{32}$')));
    expect(const EthicsTopics().newChannel(Random(2)), isNot(channel));
  });

  test('el aviso de novedades abre el canal ético; el de RRHH no', () {
    expect(AppNotification.routeFor('', {'type': 'ethics.update'}), '/ethics');
    expect(AppNotification.routeFor('ethics.case_received'), isNull);
  });

  testWidgets('la pantalla del caso pide información y deja responder', (tester) async {
    const saved = SavedEthicsCase(code: 'ET-7KQ4PX', token: 'T', subject: 'Trato humillante');
    await tester.pumpWidget(ProviderScope(
      overrides: [
        ethicsCaseProvider((code: 'ET-7KQ4PX', token: 'T'))
            .overrideWith((ref) async => EthicsCaseView.fromJson(Map<String, dynamic>.from(_case))),
      ],
      child: MaterialApp(theme: AppTheme.light(), home: const EthicsCaseScreen(saved: saved)),
    ));
    await tester.pump();
    await tester.pump();

    expect(find.text('Necesita más información'), findsOneWidget);
    expect(find.text('¿Hubo testigos?'), findsOneWidget);
    expect(find.text('Quien investiga'), findsOneWidget);
    expect(find.byKey(const Key('ethics-reply')), findsOneWidget);
  });

  testWidgets('el formulario exige tipo, asunto y detalle', (tester) async {
    await tester.pumpWidget(ProviderScope(
      overrides: [
        ethicsCategoriesProvider.overrideWith((ref) async => const [EthicsCategory('harassment', 'Acoso laboral o sexual')]),
      ],
      child: MaterialApp(theme: AppTheme.light(), home: const EthicsReportScreen()),
    ));
    await tester.pump();
    await tester.pump();

    await tester.tap(find.byKey(const Key('ethics-submit')));
    await tester.pump();
    expect(find.text('Elige el tipo'), findsOneWidget);
    expect(find.text('Escribe al menos 5 caracteres'), findsOneWidget);
    expect(find.textContaining('mínimo 20 caracteres'), findsOneWidget);
  });
}
