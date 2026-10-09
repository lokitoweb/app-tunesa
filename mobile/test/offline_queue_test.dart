import 'package:flutter_test/flutter_test.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:tunesa_mobile/core/network/api_exception.dart';
import 'package:tunesa_mobile/core/utils/formatters.dart';
import 'package:tunesa_mobile/features/attendance/data/offline_clock_queue.dart';
import 'package:tunesa_mobile/features/attendance/domain/models.dart';
import 'package:tunesa_mobile/features/attendance/presentation/offline_sync.dart';

class _MemoryStore implements SecureKeyValueStore {
  final values = <String, String>{};

  @override
  Future<String?> read(String key) async => values[key];

  @override
  Future<void> write(String key, String value) async => values[key] = value;

  @override
  Future<void> delete(String key) async => values.remove(key);
}

void main() {
  setUpAll(() => initializeDateFormatting('es'));

  final now = DateTime(2026, 10, 9, 12);
  late _MemoryStore store;
  late List<String> deletedFiles;
  late OfflineClockQueue queue;

  PendingClock item(String id, Duration ago, {ClockAction action = ClockAction.clockIn, int? userId, String? photo}) =>
      PendingClock(
        id: id,
        action: action,
        latitude: -12.04,
        longitude: -77.03,
        accuracy: 12,
        recordedAt: Fmt.isoWithOffset(now.subtract(ago)),
        userId: userId,
        photoPath: photo,
      );

  setUp(() {
    store = _MemoryStore();
    deletedFiles = [];
    queue = OfflineClockQueue(store, now: () => now, deleteFile: (p) async => deletedFiles.add(p));
  });

  test('guarda cifrado (JSON en el almacenamiento seguro) y ordena por hora', () async {
    await queue.add(item('b', const Duration(minutes: 10), action: ClockAction.clockOut));
    await queue.add(item('a', const Duration(hours: 2), photo: '/docs/a.jpg'));

    final items = await queue.items();
    expect(items.map((i) => i.id), ['a', 'b']);
    expect(items.first.photoPath, '/docs/a.jpg');
    expect(items.last.action, ClockAction.clockOut);
    expect(store.values[OfflineClockQueue.storageKey], contains('"recorded_at"'));
  });

  test('envía en orden, borra la foto y vacía la cola', () async {
    await queue.add(item('a', const Duration(hours: 1), photo: '/docs/a.jpg'));
    await queue.add(item('b', const Duration(minutes: 5)));
    final sent = <String>[];

    final result = await queue.sync(send: (i) async => sent.add(i.id), maxAge: const Duration(hours: 24));

    expect(sent, ['a', 'b']);
    expect(result.sent.length, 2);
    expect(result.remaining, 0);
    expect(deletedFiles, ['/docs/a.jpg']);
    expect(store.values.containsKey(OfflineClockQueue.storageKey), isFalse);
  });

  test('error de red: conserva todo y se detiene', () async {
    await queue.add(item('a', const Duration(hours: 1)));
    await queue.add(item('b', const Duration(minutes: 5)));
    var calls = 0;

    final result = await queue.sync(
      send: (_) async {
        calls++;
        throw const ApiException('Sin conexión', code: 'NETWORK');
      },
      maxAge: const Duration(hours: 24),
    );

    expect(calls, 1);
    expect(result.networkFailed, isTrue);
    expect(result.remaining, 2);
    expect(deletedFiles, isEmpty);
  });

  test('error 5xx o sesión vencida (401): se reintenta después', () async {
    await queue.add(item('a', const Duration(hours: 1)));
    for (final status in [500, 401, 426]) {
      final result = await queue.sync(
        send: (_) async => throw ApiException('x', statusCode: status),
        maxAge: const Duration(hours: 24),
      );
      expect(result.remaining, 1, reason: 'status $status');
    }
  });

  test('error de negocio 4xx: se quita de la cola y se informa', () async {
    await queue.add(item('a', const Duration(hours: 1), photo: '/docs/a.jpg'));
    await queue.add(item('b', const Duration(minutes: 5)));

    final result = await queue.sync(
      send: (i) async {
        if (i.id == 'a') {
          throw const ApiException('La empresa no permite marcar sin conexión.',
              code: 'OFFLINE_NOT_ALLOWED', statusCode: 422);
        }
      },
      maxAge: const Duration(hours: 24),
    );

    expect(result.rejected.single.$1.id, 'a');
    expect(result.rejected.single.$2, contains('sin conexión'));
    expect(result.sent.single.id, 'b');
    expect(result.remaining, 0);
    expect(deletedFiles, ['/docs/a.jpg']);
  });

  test('descarta las que superan offline_max_hours sin enviarlas', () async {
    await queue.add(item('viejo', const Duration(hours: 13), photo: '/docs/v.jpg'));
    await queue.add(item('nuevo', const Duration(hours: 1)));
    final sent = <String>[];

    final result = await queue.sync(send: (i) async => sent.add(i.id), maxAge: const Duration(hours: 12));

    expect(sent, ['nuevo']);
    expect(result.expired.single.id, 'viejo');
    expect(deletedFiles, ['/docs/v.jpg']);

    final messages = offlineSyncMessages(result, maxHours: 12);
    expect(messages.any((m) => m.$2 && m.$1.contains('12 h')), isTrue);
    expect(messages.first.$1, contains('Se envió 1 marcación'));
  });

  test('solo envía las marcaciones del colaborador con sesión', () async {
    await queue.add(item('mia', const Duration(hours: 1), userId: 7));
    await queue.add(item('ajena', const Duration(minutes: 30), userId: 8));
    final sent = <String>[];

    final result = await queue.sync(send: (i) async => sent.add(i.id), maxAge: const Duration(hours: 24), userId: 7);

    expect(sent, ['mia']);
    expect(result.remaining, 1);
    expect((await queue.items()).single.id, 'ajena');
  });

  test('dos sincronizaciones simultáneas no envían dos veces', () async {
    await queue.add(item('a', const Duration(hours: 1)));
    var calls = 0;
    Future<void> send(PendingClock _) async {
      calls++;
      await Future<void>.delayed(const Duration(milliseconds: 10));
    }

    await Future.wait([
      queue.sync(send: send, maxAge: const Duration(hours: 24)),
      queue.sync(send: send, maxAge: const Duration(hours: 24)),
    ]);
    expect(calls, 1);
  });

  test('datos ilegibles en el almacenamiento no rompen la cola', () async {
    await store.write(OfflineClockQueue.storageKey, '[{"action":"desconocida"}, 3, "x"]');
    expect(await queue.items(), isEmpty);
    await store.write(OfflineClockQueue.storageKey, 'no-json');
    expect(await queue.count(), 0);
  });
}
