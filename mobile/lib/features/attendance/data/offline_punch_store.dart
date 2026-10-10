import 'package:sqflite_sqlcipher/sqlite_api.dart';

import '../domain/punch.dart';

/// Cola de marcaciones sin conexión sobre SQLite (en el teléfono, SQLCipher
/// con llave aleatoria del almacenamiento seguro; en pruebas, sqflite_ffi en memoria).
///
/// Tablas: `punches` (pendientes), `rejected_punches` ("Marcaciones no
/// aceptadas") y `meta` (última sincronización).
class OfflinePunchStore {
  OfflinePunchStore(this._open);

  final Future<Database> Function() _open;
  Future<Database>? _db;

  static const schemaVersion = 1;
  static const lastSyncKey = 'last_sync_at';

  Future<Database> get _database => _db ??= _open().catchError((Object e) {
        _db = null;
        throw e;
      });

  static Future<void> createSchema(Database db, int version) async {
    final batch = db.batch()
      ..execute('''
        CREATE TABLE punches (
          client_uuid TEXT PRIMARY KEY,
          action TEXT NOT NULL,
          latitude REAL NOT NULL,
          longitude REAL NOT NULL,
          accuracy REAL NOT NULL,
          device_time TEXT NOT NULL,
          user_id INTEGER,
          is_mocked INTEGER NOT NULL DEFAULT 0,
          face_detected INTEGER,
          device_id TEXT,
          device_os TEXT,
          app_version TEXT,
          photo_path TEXT,
          photo_mime TEXT,
          attempts INTEGER NOT NULL DEFAULT 0,
          next_attempt_at TEXT,
          last_error TEXT
        )''')
      ..execute('''
        CREATE TABLE rejected_punches (
          client_uuid TEXT PRIMARY KEY,
          action TEXT NOT NULL,
          device_time TEXT NOT NULL,
          message TEXT NOT NULL,
          code TEXT,
          rejected_at TEXT NOT NULL,
          user_id INTEGER
        )''')
      ..execute('CREATE TABLE meta (key TEXT PRIMARY KEY, value TEXT)');
    await batch.commit(noResult: true);
  }

  // Marcaciones del usuario, o sin usuario (anteriores a la sesión).
  static (String?, List<Object?>?) _userFilter(int? userId) =>
      userId == null ? (null, null) : ('user_id IS NULL OR user_id = ?', [userId]);

  Future<void> insert(QueuedPunch punch) async =>
      (await _database).insert('punches', punch.toRow(), conflictAlgorithm: ConflictAlgorithm.replace);

  /// Pendientes en orden cronológico (de todos los usuarios si [userId] es null).
  Future<List<QueuedPunch>> all({int? userId}) async {
    final (where, args) = _userFilter(userId);
    final rows = await (await _database).query('punches', where: where, whereArgs: args, orderBy: 'device_time ASC');
    return [for (final r in rows) ?QueuedPunch.fromRow(r)];
  }

  Future<int> count({int? userId}) async {
    final (where, args) = _userFilter(userId);
    final rows = await (await _database).query('punches', columns: ['COUNT(*) AS n'], where: where, whereArgs: args);
    return (rows.first['n'] as num?)?.toInt() ?? 0;
  }

  Future<QueuedPunch?> get(String clientUuid) async {
    final rows = await (await _database).query('punches', where: 'client_uuid = ?', whereArgs: [clientUuid]);
    return rows.isEmpty ? null : QueuedPunch.fromRow(rows.first);
  }

  Future<void> delete(String clientUuid) async =>
      (await _database).delete('punches', where: 'client_uuid = ?', whereArgs: [clientUuid]);

  /// Reintento fallido: suma un intento y programa el siguiente.
  Future<void> recordFailure(String clientUuid, {required DateTime nextAttemptAt, String? error}) async {
    await (await _database).rawUpdate(
      'UPDATE punches SET attempts = attempts + 1, next_attempt_at = ?, last_error = ? WHERE client_uuid = ?',
      [isoUtc(nextAttemptAt), error, clientUuid],
    );
  }

  /// Quita la marcación de la cola y la deja en "no aceptadas".
  Future<void> reject(RejectedPunch rejected) async {
    await (await _database).transaction((txn) async {
      await txn.delete('punches', where: 'client_uuid = ?', whereArgs: [rejected.clientUuid]);
      await txn.insert('rejected_punches', rejected.toRow(), conflictAlgorithm: ConflictAlgorithm.replace);
    });
  }

  Future<List<RejectedPunch>> rejected({int? userId}) async {
    final (where, args) = _userFilter(userId);
    final rows =
        await (await _database).query('rejected_punches', where: where, whereArgs: args, orderBy: 'rejected_at DESC');
    return [for (final r in rows) ?RejectedPunch.fromRow(r)];
  }

  Future<void> dismissRejected(String clientUuid) async =>
      (await _database).delete('rejected_punches', where: 'client_uuid = ?', whereArgs: [clientUuid]);

  Future<void> clearRejected({int? userId}) async {
    final (where, args) = _userFilter(userId);
    await (await _database).delete('rejected_punches', where: where, whereArgs: args);
  }

  Future<String?> meta(String key) async {
    final rows = await (await _database).query('meta', where: 'key = ?', whereArgs: [key]);
    return rows.isEmpty ? null : rows.first['value'] as String?;
  }

  Future<void> setMeta(String key, String value) async =>
      (await _database).insert('meta', {'key': key, 'value': value}, conflictAlgorithm: ConflictAlgorithm.replace);

  Future<DateTime?> lastSyncAt() async => DateTime.tryParse(await meta(lastSyncKey) ?? '');
}
