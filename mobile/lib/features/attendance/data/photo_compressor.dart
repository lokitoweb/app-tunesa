import 'dart:ui' as ui;

import 'package:flutter/foundation.dart';
import 'package:flutter_image_compress/flutter_image_compress.dart';

import '../domain/punch.dart';

/// Comprime la selfie antes de enviarla o guardarla: WebP calidad 80 con el
/// lado mayor en 1280 px como máximo. Si el teléfono no puede codificar WebP
/// usa JPEG 80; si tampoco, la foto original (JPEG de la cámara).
class PhotoCompressor {
  const PhotoCompressor();

  static const maxSide = 1280;
  static const quality = 80;

  /// Lado mínimo a pedir a flutter_image_compress para que el lado mayor
  /// quede en [maxSide]. El plugin escala por `min(ancho/minW, alto/minH)`;
  /// con minW = minH = lado menor / factor el resultado no depende de la
  /// orientación EXIF. Sin reducir si ya cabe.
  static int targetMinSide(int width, int height) {
    final long = width > height ? width : height;
    final short = width > height ? height : width;
    if (long <= maxSide || short <= 0) return short <= 0 ? maxSide : short;
    return (short * maxSide / long).floor().clamp(1, maxSide);
  }

  Future<PunchPhoto> compress(Uint8List original) async {
    if (kIsWeb) return PunchPhoto(original, 'image/jpeg');
    final minSide = await _minSide(original);
    for (final (format, mime) in const [(CompressFormat.webp, 'image/webp'), (CompressFormat.jpeg, 'image/jpeg')]) {
      try {
        final out = await FlutterImageCompress.compressWithList(
          original,
          minWidth: minSide,
          minHeight: minSide,
          quality: quality,
          format: format,
        );
        if (out.isNotEmpty) return PunchPhoto(out, mime);
      } catch (_) {
        // formato no soportado en este equipo: se prueba el siguiente
      }
    }
    return PunchPhoto(original, 'image/jpeg');
  }

  static Future<int> _minSide(Uint8List bytes) async {
    try {
      final buffer = await ui.ImmutableBuffer.fromUint8List(bytes);
      final descriptor = await ui.ImageDescriptor.encoded(buffer);
      final side = targetMinSide(descriptor.width, descriptor.height);
      descriptor.dispose();
      buffer.dispose();
      return side;
    } catch (_) {
      return maxSide;
    }
  }
}
