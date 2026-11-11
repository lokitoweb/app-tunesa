import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:tunesa_mobile/core/widgets/skeleton.dart';

void main() {
  testWidgets('el inicio completo carga en skeleton: saludo, horario, marcas y accesos', (tester) async {
    await tester.pumpWidget(const MaterialApp(home: Scaffold(body: SingleChildScrollView(child: SkeletonHome()))));

    expect(find.byType(SkeletonCircle), findsOneWidget); // saludo con avatar
    expect(find.byType(SkeletonPunchBoard), findsOneWidget); // horario y tablero de marcas
    expect(find.byType(SkeletonCard), findsAtLeastNWidgets(7)); // saludo + 2 del tablero + 4 accesos
    expect(tester.takeException(), isNull);
  });
}
