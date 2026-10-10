import 'package:path_provider/path_provider.dart';
import 'package:sqflite_sqlcipher/sqflite.dart' as cipher;

import '../../../core/storage/secure_key_store.dart';
import 'legacy_offline_queue.dart';
import 'offline_punch_store.dart';
import 'offline_sync_engine.dart';
import 'photo_vault.dart';

/// Cola offline completa: base cifrada + fotos cifradas + sincronización.
class OfflineQueue {
  OfflineQueue({required this.store, required this.vault, this.migration, DateTime Function()? now})
      : engine = OfflineSyncEngine(store, vault, now: now);

  /// En el teléfono: SQLCipher y AES-GCM con llaves aleatorias del Keychain /
  /// Keystore, y migración de la cola anterior (flutter_secure_storage).
  factory OfflineQueue.device({SecureKeyValueStore secure = const FlutterSecureKeyValueStore()}) {
    final keys = LocalKeys(secure);
    final store = OfflinePunchStore(() => openEncryptedDatabase(keys));
    final vault = EncryptedPhotoVault(
      key: () => keys.bytes(LocalKeys.photoKey),
      directory: getApplicationDocumentsDirectory,
    );
    return OfflineQueue(store: store, vault: vault, migration: LegacyOfflineQueueMigration(secure, store, vault));
  }

  final OfflinePunchStore store;
  final PhotoVault vault;
  final LegacyOfflineQueueMigration? migration;
  final OfflineSyncEngine engine;

  Future<void>? _ready;

  /// Migra la cola anterior una sola vez (si falla se reintenta en el próximo arranque).
  Future<void> ready() => _ready ??= () async {
        try {
          await migration?.run();
        } catch (_) {
          // la cola vieja sigue en el almacenamiento seguro
        }
      }();
}

/// Abre (o crea) `tunesa_offline.db` cifrada con SQLCipher. Si la llave ya no
/// coincide (p. ej. el sistema borró el Keystore al restaurar un respaldo) el
/// archivo es ilegible: se borra y se crea de nuevo.
Future<cipher.Database> openEncryptedDatabase(LocalKeys keys) async {
  final password = await keys.hex(LocalKeys.databaseKey);
  final path = '${await cipher.getDatabasesPath()}/tunesa_offline.db';
  Future<cipher.Database> open() => cipher.openDatabase(
        path,
        password: password,
        version: OfflinePunchStore.schemaVersion,
        onCreate: OfflinePunchStore.createSchema,
      );
  try {
    final db = await open();
    await db.rawQuery('SELECT count(*) FROM sqlite_master');
    return db;
  } on cipher.DatabaseException catch (e) {
    // Solo "file is not a database" (SQLITE_NOTADB = 26); cualquier otro
    // error se propaga y la cola se reintenta sin perder nada.
    if (e.getResultCode() != 26 && !e.toString().contains('not a database')) rethrow;
    await cipher.deleteDatabase(path);
    return open();
  }
}
