import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';

import '../config/env.dart';

/// Señales de un teléfono manipulado (root/jailbreak, emulador, depurador,
/// Frida/Xposed o un APK re-firmado). Se calculan una vez por arranque en
/// código nativo (MainActivity.DeviceIntegrity / AppDelegate.DeviceIntegrity)
/// y viajan al servidor en la cabecera `X-Device-Integrity`; el servidor
/// acepta la marcación pero la deja como sospechosa para RRHH.
class DeviceIntegrity {
  DeviceIntegrity({MethodChannel? channel, String? expectedSignatures})
      : _channel = channel ?? const MethodChannel('tunesa/device_integrity'),
        _expected = expectedSignatures ?? Env.appCertSha256;

  final MethodChannel _channel;
  final String _expected;

  Future<List<String>>? _flags;

  Future<List<String>> flags() => _flags ??= _check();

  Future<List<String>> _check() async {
    if (kIsWeb || !(defaultTargetPlatform == TargetPlatform.android || defaultTargetPlatform == TargetPlatform.iOS)) {
      return const [];
    }
    try {
      final raw = await _channel.invokeMapMethod<String, dynamic>('check') ?? const {};
      return flagsFrom(raw, _expected);
    } catch (_) {
      return const []; // sin canal nativo (pruebas, plataforma vieja): no se acusa a nadie
    }
  }

  /// Respuesta nativa → lista de señales. `tampered` solo si la build trae la
  /// huella esperada (APP_CERT_SHA256) y el APK está firmado con otra llave.
  @visibleForTesting
  static List<String> flagsFrom(Map<String, dynamic> raw, String expectedSignatures) {
    final flags = [
      for (final key in const ['rooted', 'emulator', 'debugger', 'hooked'])
        if (raw[key] == true) key,
    ];
    final signature = _normalize(raw['signature']?.toString() ?? '');
    final expected = expectedSignatures.split(',').map(_normalize).where((s) => s.isNotEmpty).toSet();
    if (expected.isNotEmpty && signature.isNotEmpty && !expected.contains(signature)) {
      flags.add('tampered');
    }
    return flags;
  }

  static String _normalize(String s) => s.replaceAll(RegExp('[^0-9a-fA-F]'), '').toUpperCase();
}
