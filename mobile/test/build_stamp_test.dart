import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:tunesa_mobile/features/home/presentation/home_screen.dart';

void main() {
  test('la fecha de compilación se muestra como DD/MM/YYYY', () {
    expect(BuildStamp.label('2026-11-17'), 'Última actualización 17/11/2026');
    expect(BuildStamp.label('raro'), 'Última actualización raro');
  });

  testWidgets('cabe en una pantalla angosta junto al logo sin desbordar', (tester) async {
    tester.view.physicalSize = const Size(320, 640);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(
        appBar: AppBar(title: const Row(children: [SizedBox(width: 90, height: 32), Expanded(child: Center(child: BuildStamp('2026-11-17')))])),
      ),
    ));
    expect(find.text('Última actualización 17/11/2026'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });
}
