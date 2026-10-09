import 'package:flutter/foundation.dart';
import 'package:google_mlkit_face_detection/google_mlkit_face_detection.dart';

/// Detección de rostro en el teléfono (ML Kit) sobre la selfie ya tomada.
/// Solo se usa si la empresa exige `mobile.face_detection_required`; el
/// resultado viaja como `face_detected` (0/1) y el backend decide.
class FaceDetectionService {
  /// true / false si se pudo analizar; null si la plataforma no lo soporta
  /// o ML Kit falló (en ese caso no se envía `face_detected`).
  Future<bool?> hasFace(String imagePath) async {
    if (kIsWeb || !(defaultTargetPlatform == TargetPlatform.android || defaultTargetPlatform == TargetPlatform.iOS)) {
      return null;
    }
    final detector = FaceDetector(options: FaceDetectorOptions(performanceMode: FaceDetectorMode.fast));
    try {
      final faces = await detector.processImage(InputImage.fromFilePath(imagePath));
      return faces.isNotEmpty;
    } catch (_) {
      return null;
    } finally {
      try {
        await detector.close();
      } catch (_) {}
    }
  }
}
