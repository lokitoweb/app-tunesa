import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:tunesa_mobile/core/security/device_integrity.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  test('las señales nativas se convierten en la lista que va al servidor', () {
    expect(DeviceIntegrity.flagsFrom({'rooted': true, 'emulator': false, 'debugger': false, 'hooked': true}, ''), ['rooted', 'hooked']);
    expect(DeviceIntegrity.flagsFrom({'rooted': false}, ''), isEmpty);
  });

  test('APK re-firmado: solo si la build trae la huella esperada', () {
    const ours = 'AA:BB:CC:01';
    // Sin huella configurada no se revisa la firma
    expect(DeviceIntegrity.flagsFrom({'signature': 'FF:FF'}, ''), isEmpty);
    // Misma llave (con o sin dos puntos, mayúsculas o no) o la de Google Play
    expect(DeviceIntegrity.flagsFrom({'signature': ours}, 'aabbcc01'), isEmpty);
    expect(DeviceIntegrity.flagsFrom({'signature': 'DD:EE'}, '$ours, dd:ee'), isEmpty);
    // Otra llave
    expect(DeviceIntegrity.flagsFrom({'signature': 'DE:AD:BE:EF'}, ours), ['tampered']);
  });

  test('sin canal nativo no acusa al teléfono', () async {
    final flags = await DeviceIntegrity(channel: const MethodChannel('tunesa/no-existe'), expectedSignatures: '').flags();
    expect(flags, isEmpty);
  });

  test('lee el canal nativo una sola vez', () async {
    const channel = MethodChannel('tunesa/device_integrity_test');
    var calls = 0;
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger.setMockMethodCallHandler(channel, (call) async {
      calls++;
      return {'rooted': true, 'signature': 'AA'};
    });
    final integrity = DeviceIntegrity(channel: channel, expectedSignatures: 'BB');
    expect(await integrity.flags(), ['rooted', 'tampered']);
    expect(await integrity.flags(), ['rooted', 'tampered']);
    expect(calls, 1);
  });
}
