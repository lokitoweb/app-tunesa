import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// Requisitos de tienda que viven en archivos nativos (no se ven en el código Dart).
void main() {
  test('iOS: manifiesto de privacidad presente, sin rastreo y empaquetado en Runner', () {
    final manifest = File('ios/Runner/PrivacyInfo.xcprivacy').readAsStringSync();
    expect(manifest, contains('<key>NSPrivacyTracking</key>\n\t<false/>'));
    expect(manifest, contains('NSPrivacyCollectedDataTypePreciseLocation'));

    final project = File('ios/Runner.xcodeproj/project.pbxproj').readAsStringSync();
    expect(project, contains('PrivacyInfo.xcprivacy in Resources */,'));
  });

  test('Android: release con R8 y sin permisos de audio ni almacenamiento', () {
    final gradle = File('android/app/build.gradle.kts').readAsStringSync();
    expect(gradle, contains('isMinifyEnabled = true'));
    final manifest = File('android/app/src/main/AndroidManifest.xml').readAsStringSync();
    for (final p in ['RECORD_AUDIO', 'WRITE_EXTERNAL_STORAGE', 'READ_EXTERNAL_STORAGE']) {
      expect(manifest, contains('android.permission.$p" tools:node="remove"'));
    }
    expect(manifest, isNot(contains('ACCESS_BACKGROUND_LOCATION')));
  });
}
