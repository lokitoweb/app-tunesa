import 'dart:convert';
import 'dart:math';
import 'dart:typed_data';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Almacenamiento clave/valor cifrado (Keychain / Keystore). Interfaz para
/// poder probar con un almacenamiento en memoria.
abstract class SecureKeyValueStore {
  Future<String?> read(String key);
  Future<void> write(String key, String value);
  Future<void> delete(String key);
}

class FlutterSecureKeyValueStore implements SecureKeyValueStore {
  const FlutterSecureKeyValueStore([this._secure = const FlutterSecureStorage()]);

  final FlutterSecureStorage _secure;

  @override
  Future<String?> read(String key) => _secure.read(key: key);

  @override
  Future<void> write(String key, String value) => _secure.write(key: key, value: value);

  @override
  Future<void> delete(String key) => _secure.delete(key: key);
}

/// Llaves aleatorias de la app (base de datos y fotos de la cola offline).
/// Se generan una sola vez con [Random.secure] y viven solo en el
/// almacenamiento seguro.
class LocalKeys {
  LocalKeys(this._store, {Random? random}) : _random = random ?? Random.secure();

  final SecureKeyValueStore _store;
  final Random _random;
  final _pending = <String, Future<Uint8List>>{};

  static const databaseKey = 'offline_db_key_v1';
  static const photoKey = 'offline_photo_key_v1';

  /// Llave de [length] bytes guardada bajo [name] (la crea si no existe).
  Future<Uint8List> bytes(String name, {int length = 32}) =>
      _pending[name] ??= _load(name, length).whenComplete(() {
        _pending.remove(name);
      });

  /// La misma llave en hexadecimal (contraseña de SQLCipher).
  Future<String> hex(String name, {int length = 32}) async =>
      (await bytes(name, length: length)).map((b) => b.toRadixString(16).padLeft(2, '0')).join();

  Future<Uint8List> _load(String name, int length) async {
    final existing = await _store.read(name);
    if (existing != null) {
      try {
        final decoded = base64Decode(existing);
        if (decoded.length == length) return decoded;
      } catch (_) {
        // valor corrupto: se reemplaza
      }
    }
    final key = Uint8List.fromList(List.generate(length, (_) => _random.nextInt(256)));
    await _store.write(name, base64Encode(key));
    return key;
  }
}
