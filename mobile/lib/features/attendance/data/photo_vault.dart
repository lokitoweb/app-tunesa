import 'dart:io';
import 'dart:typed_data';

import 'package:cryptography/cryptography.dart';

/// Selfies de la cola offline, cifradas en reposo.
abstract class PhotoVault {
  /// Guarda [bytes] cifrados para la marcación [id] y devuelve la ruta.
  Future<String> save(String id, List<int> bytes);

  /// Foto descifrada, o null si falta o no se puede descifrar.
  Future<Uint8List?> read(String path);

  Future<void> delete(String path);
}

/// AES-256-GCM (paquete `cryptography`) con una llave aleatoria del
/// almacenamiento seguro. Archivo: nonce (12) + texto cifrado + MAC (16), en
/// `<documentos de la app>/offline_punches/` (privado de la app).
class EncryptedPhotoVault implements PhotoVault {
  EncryptedPhotoVault({required this._key, required this._directory});

  final Future<List<int>> Function() _key;
  final Future<Directory> Function() _directory;
  final _algorithm = AesGcm.with256bits();

  static const _folder = 'offline_punches';

  Future<SecretKey> _secret() async => SecretKey(await _key());

  @override
  Future<String> save(String id, List<int> bytes) async {
    final dir = Directory('${(await _directory()).path}/$_folder');
    await dir.create(recursive: true);
    final box = await _algorithm.encrypt(bytes, secretKey: await _secret());
    final file = File('${dir.path}/$id.enc');
    await file.writeAsBytes(box.concatenation(), flush: true);
    return file.path;
  }

  @override
  Future<Uint8List?> read(String path) async {
    try {
      final file = File(path);
      if (!await file.exists()) return null;
      final box = SecretBox.fromConcatenation(
        await file.readAsBytes(),
        nonceLength: _algorithm.nonceLength,
        macLength: _algorithm.macAlgorithm.macLength,
      );
      return Uint8List.fromList(await _algorithm.decrypt(box, secretKey: await _secret()));
    } catch (_) {
      return null;
    }
  }

  @override
  Future<void> delete(String path) async {
    try {
      final file = File(path);
      if (await file.exists()) await file.delete();
    } catch (_) {}
  }
}
