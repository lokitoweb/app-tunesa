import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:flutter_test/flutter_test.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';
import 'package:tunesa_mobile/core/network/api_exception.dart';
import 'package:tunesa_mobile/core/storage/secure_key_store.dart';
import 'package:tunesa_mobile/features/attendance/data/attendance_repository.dart';
import 'package:tunesa_mobile/features/attendance/data/legacy_offline_queue.dart';
import 'package:tunesa_mobile/features/attendance/data/offline_punch_store.dart';
import 'package:tunesa_mobile/features/attendance/data/offline_sync_engine.dart';
import 'package:tunesa_mobile/features/attendance/data/photo_vault.dart';
import 'package:tunesa_mobile/features/attendance/domain/models.dart';
import 'package:tunesa_mobile/features/attendance/domain/punch.dart';
import 'package:tunesa_mobile/features/attendance/presentation/offline_sync.dart';

class _MemoryKeys implements SecureKeyValueStore {
  final values = <String, String>{};

  @override
  Future<String?> read(String key) async => values[key];

  @override
  Future<void> write(String key, String value) async => values[key] = value;

  @override
  Future<void> delete(String key) async => values.remove(key);
}

/// Fotos "cifradas" en memoria (el cifrado real se prueba aparte).
class _MemoryVault implements PhotoVault {
  final files = <String, Uint8List>{};
  final deleted = <String>[];

  @override
  Future<String> save(String id, List<int> bytes) async {
    final path = '/vault/$id.enc';
    files[path] = Uint8List.fromList(bytes);
    return path;
  }

  @override
  Future<Uint8List?> read(String path) async => files[path];

  @override
  Future<void> delete(String path) async {
    deleted.add(path);
    files.remove(path);
  }
}

/// Backend falso: responde según [onBatch] / [onOne] y registra lo enviado.
class _FakeTransport implements PunchTransport {
  _FakeTransport({this.onBatch, this.onOne});

  Map<String, BatchItemResult> Function(List<OutgoingPunch>)? onBatch;
  void Function(OutgoingPunch)? onOne;
  final batches = <List<OutgoingPunch>>[];
  final singles = <OutgoingPunch>[];

  @override
  Future<Map<String, BatchItemResult>> sendBatch(List<OutgoingPunch> punches) async {
    batches.add(punches);
    final handler = onBatch;
    if (handler != null) return handler(punches);
    return {
      for (final p in punches)
        p.punch.clientUuid: BatchItemResult(clientUuid: p.punch.clientUuid, status: BatchItemStatus.created),
    };
  }

  @override
  Future<void> sendOne(OutgoingPunch punch) async {
    singles.add(punch);
    onOne?.call(punch);
  }
}

void main() {
  sqfliteFfiInit();
  setUpAll(() => initializeDateFormatting('es'));

  final now = DateTime.utc(2026, 10, 9, 17);
  late OfflinePunchStore store;
  late _MemoryVault vault;
  late OfflineSyncEngine engine;
  var clock = now;

  QueuedPunch punch(
    String uuid,
    Duration ago, {
    ClockAction action = ClockAction.clockIn,
    int? userId,
    String? photo,
  }) =>
      QueuedPunch(
        clientUuid: uuid,
        action: action,
        latitude: -12.04,
        longitude: -77.03,
        accuracy: 12,
        deviceTime: now.subtract(ago),
        userId: userId,
        photoPath: photo,
        photoMime: photo == null ? null : 'image/webp',
      );

  setUp(() async {
    clock = now;
    final db = await databaseFactoryFfi.openDatabase(
      inMemoryDatabasePath,
      options: OpenDatabaseOptions(
        version: OfflinePunchStore.schemaVersion,
        onCreate: OfflinePunchStore.createSchema,
        singleInstance: false,
      ),
    );
    addTearDown(db.close);
    store = OfflinePunchStore(() async => db);
    vault = _MemoryVault();
    engine = OfflineSyncEngine(store, vault, now: () => clock);
  });

  group('OfflinePunchStore (SQLite)', () {
    test('guarda, ordena por hora y filtra por colaborador', () async {
      await store.insert(punch('b', const Duration(minutes: 10), action: ClockAction.clockOut, userId: 7));
      await store.insert(punch('a', const Duration(hours: 2), userId: 7, photo: '/vault/a.enc'));
      await store.insert(punch('c', const Duration(hours: 1), userId: 8));

      final all = await store.all();
      expect(all.map((p) => p.clientUuid), ['a', 'c', 'b']);
      expect(all.first.photoPath, '/vault/a.enc');
      expect(all.first.deviceTime, now.subtract(const Duration(hours: 2)));
      expect(await store.count(userId: 7), 2);
      expect(await store.count(), 3);
    });

    test('rechazar mueve la marcación a "no aceptadas"', () async {
      await store.insert(punch('a', const Duration(hours: 1), userId: 7));
      await store.reject(RejectedPunch(
        clientUuid: 'a',
        action: ClockAction.clockIn,
        deviceTime: now,
        message: 'Fuera de la geocerca',
        code: 'GEOFENCE_VIOLATION',
        rejectedAt: now,
        userId: 7,
      ));
      expect(await store.count(), 0);
      final rejected = await store.rejected(userId: 7);
      expect(rejected.single.message, 'Fuera de la geocerca');
      expect(rejected.single.code, 'GEOFENCE_VIOLATION');
      await store.dismissRejected('a');
      expect(await store.rejected(), isEmpty);
    });

    test('registra fallos con intentos y próxima hora', () async {
      await store.insert(punch('a', const Duration(hours: 1)));
      await store.recordFailure('a', nextAttemptAt: now.add(const Duration(minutes: 1)), error: 'red');
      await store.recordFailure('a', nextAttemptAt: now.add(const Duration(minutes: 2)), error: 'red');
      final item = await store.get('a');
      expect(item!.attempts, 2);
      expect(item.nextAttemptAt, now.add(const Duration(minutes: 2)));
      expect(item.lastError, 'red');
    });
  });

  group('OfflineSyncEngine', () {
    test('lote: created y duplicate se quitan (y su foto); rejected queda visible', () async {
      await store.insert(punch('ok', const Duration(hours: 3), photo: '/vault/ok.enc'));
      vault.files['/vault/ok.enc'] = Uint8List.fromList([1, 2, 3]);
      await store.insert(punch('dup', const Duration(hours: 2)));
      await store.insert(punch('bad', const Duration(hours: 1), photo: '/vault/bad.enc'));
      final transport = _FakeTransport(
        onBatch: (_) => parseBatchResults({
          'success': true,
          'data': {
            'results': [
              {'client_uuid': 'ok', 'status': 'created', 'attendance_id': 1, 'punch_id': 10},
              {'client_uuid': 'dup', 'status': 'duplicate', 'attendance_id': 1},
              {'client_uuid': 'bad', 'status': 'rejected', 'code': 'GEOFENCE_VIOLATION', 'message': 'Estás fuera del área.'},
            ],
          },
          'meta': {'created': 1, 'duplicates': 1, 'rejected': 1},
        }),
      );

      final result = await engine.sync(transport: transport, maxAge: const Duration(hours: 24));

      expect(transport.batches.single.first.photo!.bytes, [1, 2, 3]);
      expect(transport.batches.single.first.photo!.mimeType, 'image/webp');
      expect(result.sent.map((p) => p.clientUuid), ['ok', 'dup']);
      expect(result.duplicates, 1);
      expect(result.rejected.single.$2, 'Estás fuera del área.');
      expect(result.remaining, 0);
      expect(vault.deleted, containsAll(['/vault/ok.enc', '/vault/bad.enc']));
      expect((await store.rejected()).single.code, 'GEOFENCE_VIOLATION');
      expect(await store.lastSyncAt(), now);
    });

    test('envía en lotes de 5 (una selfie por marcación)', () async {
      for (var i = 0; i < 25; i++) {
        await store.insert(punch('p$i', Duration(minutes: 30 - i)));
      }
      final transport = _FakeTransport();
      final result = await engine.sync(transport: transport, maxAge: const Duration(hours: 24));
      expect(transport.batches.map((b) => b.length), [5, 5, 5, 5, 5]);
      expect(result.sent.length, 25);
    });

    test('error de red: conserva todo con espera; el reintento usa el mismo client_uuid', () async {
      await store.insert(punch('a', const Duration(hours: 1)));
      final transport = _FakeTransport(onBatch: (_) => throw const ApiException('Sin conexión', code: 'NETWORK'));

      final first = await engine.sync(transport: transport, maxAge: const Duration(hours: 24));
      expect(first.networkFailed, isTrue);
      expect(first.remaining, 1);
      final saved = await store.get('a');
      expect(saved!.attempts, 1);
      expect(saved.nextAttemptAt, now.add(OfflineSyncEngine.backoff(1)));

      // Antes de la espera no se reintenta solo…
      clock = now.add(const Duration(seconds: 10));
      await engine.sync(transport: transport, maxAge: const Duration(hours: 24));
      expect(transport.batches.length, 1);

      // …pero sí al forzar (deslizar / "Enviar ahora"), con el mismo uuid.
      transport.onBatch = null;
      final retry = await engine.sync(transport: transport, maxAge: const Duration(hours: 24), force: true);
      expect(transport.batches.length, 2);
      expect(transport.batches.last.single.punch.clientUuid, 'a');
      expect(retry.sent.single.clientUuid, 'a');
      expect(await store.count(), 0);
    });

    test('5xx, 401, 426 y 429 se reintentan; la espera crece hasta 30 min', () async {
      for (final status in [500, 401, 426, 429]) {
        expect(OfflineSyncEngine.isRetryable(ApiException('x', statusCode: status)), isTrue, reason: '$status');
      }
      expect(OfflineSyncEngine.isRetryable(const ApiException('x', statusCode: 422)), isFalse);
      expect(OfflineSyncEngine.backoff(1), const Duration(seconds: 30));
      expect(OfflineSyncEngine.backoff(2), const Duration(minutes: 1));
      expect(OfflineSyncEngine.backoff(20), const Duration(minutes: 30));
    });

    test('"retry" del servidor (fallo suyo, no de la marcación): se conserva con su foto', () async {
      await store.insert(punch('in', const Duration(hours: 2), photo: '/vault/in.enc'));
      vault.files['/vault/in.enc'] = Uint8List.fromList([9]);
      await store.insert(punch('out', const Duration(hours: 1)));
      final transport = _FakeTransport(
        onBatch: (_) => parseBatchResults({
          'data': {
            'results': [
              {'client_uuid': 'in', 'status': 'retry', 'code': 'SERVER_ERROR', 'message': 'Se reintentará.'},
              {'client_uuid': 'out', 'status': 'retry', 'code': 'SERVER_ERROR'},
            ],
          },
        }),
      );

      final result = await engine.sync(transport: transport, maxAge: const Duration(hours: 24));

      expect(result.rejected, isEmpty);
      expect(result.remaining, 2);
      expect(await store.rejected(), isEmpty);
      expect(vault.deleted, isNot(contains('/vault/in.enc')), reason: 'la selfie no se borra');
      expect((await store.get('in'))!.attempts, 1);
    });

    test('403 de la cuenta (contraseña por cambiar, incorporación): no rechaza la cola', () async {
      await store.insert(punch('a', const Duration(hours: 2)));
      await store.insert(punch('b', const Duration(hours: 1)));
      final transport = _FakeTransport(onBatch: (_) => throw const ApiException('Debes cambiar tu contraseña.', statusCode: 403));

      final result = await engine.sync(transport: transport, maxAge: const Duration(hours: 24));

      expect(OfflineSyncEngine.isRetryable(const ApiException('x', statusCode: 403)), isTrue);
      expect(result.rejected, isEmpty);
      expect(result.remaining, 2);
      expect(await store.rejected(), isEmpty);
    });

    test('marcación sin resultado en la respuesta: se reintenta luego', () async {
      await store.insert(punch('a', const Duration(hours: 1)));
      final transport = _FakeTransport(onBatch: (_) => const {});
      final result = await engine.sync(transport: transport, maxAge: const Duration(hours: 24));
      expect(result.remaining, 1);
      expect((await store.get('a'))!.attempts, 1);
    });

    test('backend sin sync-batch (404): envía una por una', () async {
      await store.insert(punch('a', const Duration(hours: 2)));
      await store.insert(punch('b', const Duration(hours: 1)));
      final transport = _FakeTransport(
        onBatch: (_) => throw const ApiException('No encontrado', statusCode: 404),
        onOne: (p) {
          if (p.punch.clientUuid == 'b') {
            throw const ApiException('Ya marcaste tu entrada.', code: 'ALREADY_CLOCKED_IN', statusCode: 409);
          }
        },
      );
      final result = await engine.sync(transport: transport, maxAge: const Duration(hours: 24));
      expect(transport.singles.map((p) => p.punch.clientUuid), ['a', 'b']);
      expect(result.sent.single.clientUuid, 'a');
      expect(result.rejected.single.$2, 'Ya marcaste tu entrada.');
    });

    test('vencidas por offline_max_hours: no se envían y quedan con aviso', () async {
      await store.insert(punch('vieja', const Duration(hours: 13), photo: '/vault/v.enc'));
      await store.insert(punch('nueva', const Duration(hours: 1)));
      final transport = _FakeTransport();
      final result = await engine.sync(transport: transport, maxAge: const Duration(hours: 12));

      expect(transport.batches.single.map((p) => p.punch.clientUuid), ['nueva']);
      expect(result.expired.single.clientUuid, 'vieja');
      expect(vault.deleted, contains('/vault/v.enc'));
      final rejected = (await store.rejected()).single;
      expect(rejected.code, OfflineSyncEngine.expiredCode);
      final messages = offlineSyncMessages(result, maxHours: 12);
      expect(messages.any((m) => m.$2 && m.$1.contains('12 h')), isTrue);
      expect(messages.first.$1, contains('Se envió 1 marcación'));
    });

    test('solo envía las del colaborador con sesión', () async {
      await store.insert(punch('mia', const Duration(hours: 1), userId: 7));
      await store.insert(punch('ajena', const Duration(minutes: 30), userId: 8));
      final transport = _FakeTransport();
      final result = await engine.sync(transport: transport, maxAge: const Duration(hours: 24), userId: 7);
      expect(transport.batches.single.single.punch.clientUuid, 'mia');
      expect(result.remaining, 0);
      expect((await store.all()).single.clientUuid, 'ajena');
    });

    test('app multiempresa: solo envía al servidor donde se marcó (mismo user_id en otro servidor)', () async {
      const a = 'https://erp.empresa-a.com';
      const b = 'https://erp.empresa-b.com';
      QueuedPunch at(String uuid, String? server) => QueuedPunch(
            clientUuid: uuid,
            action: ClockAction.clockIn,
            latitude: -12.04,
            longitude: -77.03,
            accuracy: 12,
            deviceTime: now.subtract(const Duration(hours: 1)),
            userId: 7,
            server: server,
          );
      await store.insert(at('en-a', a));
      await store.insert(at('en-b', b));
      await store.insert(at('antigua', null));

      final transport = _FakeTransport();
      final result = await engine.sync(transport: transport, maxAge: const Duration(hours: 24), userId: 7, server: b);

      expect(transport.batches.single.map((o) => o.punch.clientUuid), ['en-b', 'antigua']);
      expect(result.remaining, 0);
      expect((await store.all()).single.clientUuid, 'en-a');
      expect(await store.count(userId: 7, server: a), 1);
      expect((await store.get('en-a'))!.server, a);
    });

    test('dos sincronizaciones simultáneas no envían dos veces', () async {
      await store.insert(punch('a', const Duration(hours: 1)));
      final transport = _FakeTransport();
      await Future.wait([
        engine.sync(transport: transport, maxAge: const Duration(hours: 24)),
        engine.sync(transport: transport, maxAge: const Duration(hours: 24)),
      ]);
      expect(transport.batches.length, 1);
    });
  });

  group('client_uuid y campos', () {
    test('la captura conserva su uuid al comprimir la foto y al pasar a la cola', () {
      final capture = PunchCapture(
        action: ClockAction.clockOut,
        latitude: -12.1,
        longitude: -77.0,
        accuracy: 8.5,
        isMocked: true,
        deviceTime: DateTime.utc(2026, 10, 9, 13, 1, 2),
        faceDetected: true,
      );
      expect(RegExp(r'^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$').hasMatch(capture.clientUuid),
          isTrue);
      final withPhoto = capture.withPhoto(PunchPhoto(Uint8List(3), 'image/webp'));
      expect(withPhoto.clientUuid, capture.clientUuid);
      final queued = QueuedPunch.fromCapture(withPhoto,
          deviceId: 'dev', deviceOs: 'android', appVersion: '1.0.0', photoPath: '/vault/x.enc');
      expect(queued.clientUuid, capture.clientUuid);
      expect(queued.photoMime, 'image/webp');
      expect(PunchCapture(action: ClockAction.clockIn, latitude: 0, longitude: 0, accuracy: 1, isMocked: false, deviceTime: now)
          .clientUuid, isNot(capture.clientUuid));

      final fields = queued.fields(fallbackDeviceId: 'x', fallbackOs: 'ios', fallbackVersion: '0');
      expect(fields, {
        'client_uuid': capture.clientUuid,
        'device_time': '2026-10-09T13:01:02.000Z',
        'latitude': '-12.1',
        'longitude': '-77.0',
        'accuracy_meters': '8.5',
        'is_mocked': '1',
        'face_detected': '1',
        'device_id': 'dev',
        'device_os': 'android',
        'app_version': '1.0.0',
        'offline_recorded_at': '2026-10-09T13:01:02.000Z',
      });
    });

    test('envío individual: sin face_detected si no se analizó; duplicate es éxito', () {
      final fields = punchFields(
        clientUuid: 'u',
        deviceTime: DateTime.utc(2026),
        latitude: 1,
        longitude: 2,
        accuracy: 3,
        isMocked: false,
        deviceId: 'd',
        deviceOs: 'ios',
        appVersion: '1',
      );
      expect(fields.containsKey('face_detected'), isFalse);
      expect(fields.containsKey('offline_recorded_at'), isFalse);
      expect(fields['is_mocked'], '0');
      expect(fields.containsKey('device_sent_at'), isFalse);

      // En línea va la hora de envío: el servidor mide cuánto antes se tomó la selfie
      final online = punchFields(
        clientUuid: 'u',
        deviceTime: DateTime.utc(2026, 10, 9, 13),
        latitude: 1,
        longitude: 2,
        accuracy: 3,
        isMocked: false,
        deviceId: 'd',
        deviceOs: 'ios',
        appVersion: '1',
        deviceSentAt: DateTime.utc(2026, 10, 9, 13, 0, 40),
      );
      expect(online['device_sent_at'], '2026-10-09T13:00:40.000Z');

      final dup = PunchOutcome.fromJson({'success': true, 'duplicate': true, 'data': {'id': 3}});
      expect(dup.duplicate, isTrue);
      expect(dup.message, contains('ya estaba registrada'));
      expect(PunchOutcome.fromJson({'message': 'Entrada registrada.'}).duplicate, isFalse);
    });

    test('parseBatchResults ignora entradas inválidas', () {
      final results = parseBatchResults({
        'data': {
          'results': [
            {'client_uuid': 'a', 'status': 'created', 'attendance_id': 5, 'punch_id': 9},
            {'client_uuid': '', 'status': 'created'},
            {'client_uuid': 'b', 'status': 'raro'},
            'x',
          ],
        },
      });
      expect(results.keys, ['a']);
      expect(results['a']!.attendanceId, 5);
      expect(results['a']!.punchId, 9);
      expect(parseBatchResults({'data': null}), isEmpty);
    });
  });

  group('cifrado y migración', () {
    test('las fotos se guardan cifradas (AES-GCM) y se recuperan', () async {
      final dir = await Directory.systemTemp.createTemp('vault_test');
      addTearDown(() => dir.delete(recursive: true));
      final keys = LocalKeys(_MemoryKeys());
      final real = EncryptedPhotoVault(key: () => keys.bytes(LocalKeys.photoKey), directory: () async => dir);
      final photo = utf8.encode('selfie-de-prueba-' * 20);

      final path = await real.save('abc', photo);
      final onDisk = await File(path).readAsBytes();
      expect(utf8.decode(onDisk, allowMalformed: true), isNot(contains('selfie-de-prueba')));
      expect(await real.read(path), photo);

      // Con otra llave no se puede leer
      final other = EncryptedPhotoVault(
        key: () => LocalKeys(_MemoryKeys()).bytes(LocalKeys.photoKey),
        directory: () async => dir,
      );
      expect(await other.read(path), isNull);
      await real.delete(path);
      expect(await File(path).exists(), isFalse);
    });

    test('actualiza la base v1 a v2 sin perder las pendientes', () async {
      final dir = await Directory.systemTemp.createTemp('schema_test');
      addTearDown(() => dir.delete(recursive: true));
      final path = '${dir.path}/cola.db';
      final v1 = await databaseFactoryFfi.openDatabase(path,
          options: OpenDatabaseOptions(
            version: 1,
            onCreate: (db, _) async {
              await db.execute('CREATE TABLE punches (client_uuid TEXT PRIMARY KEY, action TEXT NOT NULL, '
                  'latitude REAL NOT NULL, longitude REAL NOT NULL, accuracy REAL NOT NULL, device_time TEXT NOT NULL, '
                  'user_id INTEGER, is_mocked INTEGER NOT NULL DEFAULT 0, face_detected INTEGER, device_id TEXT, '
                  'device_os TEXT, app_version TEXT, photo_path TEXT, photo_mime TEXT, '
                  'attempts INTEGER NOT NULL DEFAULT 0, next_attempt_at TEXT, last_error TEXT)');
              await db.execute('CREATE TABLE rejected_punches (client_uuid TEXT PRIMARY KEY, action TEXT NOT NULL, '
                  'device_time TEXT NOT NULL, message TEXT NOT NULL, code TEXT, rejected_at TEXT NOT NULL, user_id INTEGER)');
              await db.execute('CREATE TABLE meta (key TEXT PRIMARY KEY, value TEXT)');
            },
          ));
      await v1.insert('punches', {
        'client_uuid': 'vieja',
        'action': 'clock_in',
        'latitude': -12.0,
        'longitude': -77.0,
        'accuracy': 10,
        'device_time': '2026-10-09T13:00:00Z',
        'user_id': 7,
      });
      await v1.close();

      final v2 = await databaseFactoryFfi.openDatabase(path,
          options: OpenDatabaseOptions(
            version: OfflinePunchStore.schemaVersion,
            onCreate: OfflinePunchStore.createSchema,
            onUpgrade: OfflinePunchStore.upgradeSchema,
          ));
      addTearDown(v2.close);
      final upgraded = OfflinePunchStore(() async => v2);
      final item = (await upgraded.all(userId: 7, server: 'https://erp.empresa.com')).single;
      expect(item.clientUuid, 'vieja');
      expect(item.server, isNull);
      await upgraded.insert(punch('nueva', const Duration(minutes: 5), userId: 7));
      expect(await upgraded.count(), 2);
    });

    test('LocalKeys crea la llave una sola vez', () async {
      final secure = _MemoryKeys();
      final keys = LocalKeys(secure);
      final results = await Future.wait([keys.bytes('k'), keys.bytes('k')]);
      expect(results[0], results[1]);
      expect(results[0].length, 32);
      expect(await LocalKeys(secure).hex('k'), hasLength(64));
      expect(await LocalKeys(secure).bytes('k'), results[0]);
    });

    test('migra la cola anterior del almacenamiento seguro y cifra la foto', () async {
      final dir = await Directory.systemTemp.createTemp('legacy_test');
      addTearDown(() => dir.delete(recursive: true));
      final plain = File('${dir.path}/old.jpg')..writeAsBytesSync([9, 9, 9]);
      final secure = _MemoryKeys()
        ..values[LegacyOfflineQueueMigration.storageKey] = jsonEncode([
          {
            'id': 'x',
            'action': 'clock_in',
            'latitude': -12.0,
            'longitude': -77.0,
            'accuracy': 10,
            'recorded_at': '2026-10-09T08:00:00-05:00',
            'user_id': 7,
            'is_mocked': false,
            'photo_path': plain.path,
            'face_detected': true,
          },
          {'action': 'desconocida'},
          3,
        ]);

      final migrated = await LegacyOfflineQueueMigration(secure, store, vault).run();

      expect(migrated, 1);
      expect(secure.values.containsKey(LegacyOfflineQueueMigration.storageKey), isFalse);
      expect(plain.existsSync(), isFalse);
      final item = (await store.all()).single;
      expect(item.deviceTime, DateTime.utc(2026, 10, 9, 13));
      expect(item.userId, 7);
      expect(item.faceDetected, isTrue);
      expect(item.clientUuid, hasLength(36));
      expect(await vault.read(item.photoPath!), [9, 9, 9]);
      expect(await LegacyOfflineQueueMigration(secure, store, vault).run(), 0);
    });
  });
}
