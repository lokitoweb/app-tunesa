import 'package:dio/dio.dart';
import 'package:file_picker/file_picker.dart';

/// Archivo adjunto ya leído en memoria (sustento PDF o imagen).
class PlatformAttachment {
  const PlatformAttachment(this.name, this.bytes);

  final String name;
  final List<int> bytes;

  static const maxBytes = 5 * 1024 * 1024; // mismo límite que el backend (5 MB)

  MultipartFile toMultipart() => MultipartFile.fromBytes(bytes, filename: name);

  /// Abre el selector de archivos (PDF, JPG, PNG). Devuelve null si se cancela.
  /// Lanza [AttachmentTooLarge] si supera 5 MB.
  static Future<PlatformAttachment?> pick() async {
    final file = await FilePicker.pickFile(type: FileType.custom, allowedExtensions: const ['pdf', 'jpg', 'jpeg', 'png']);
    if (file == null) return null;
    final bytes = await file.readAsBytes();
    if (bytes.length > maxBytes) throw const AttachmentTooLarge();
    return PlatformAttachment(file.name, bytes);
  }
}

class AttachmentTooLarge implements Exception {
  const AttachmentTooLarge();

  @override
  String toString() => 'El archivo supera 5 MB. Elige uno más liviano.';
}
