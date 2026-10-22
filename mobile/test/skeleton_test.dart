import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:tunesa_mobile/core/theme/app_theme.dart';
import 'package:tunesa_mobile/core/widgets/async_views.dart';
import 'package:tunesa_mobile/core/widgets/skeleton.dart';

Widget _app(Widget body, {Brightness brightness = Brightness.light, bool reduceMotion = false}) => MaterialApp(
      theme: brightness == Brightness.dark ? AppTheme.dark() : AppTheme.light(),
      home: MediaQuery(
        data: MediaQueryData(size: const Size(320, 640), disableAnimations: reduceMotion),
        child: Scaffold(body: body),
      ),
    );

void main() {
  group('Skeleton loaders', () {
    for (final brightness in Brightness.values) {
      testWidgets('cada silueta se dibuja sin desbordes a 320 dp (${brightness.name})', (tester) async {
        tester.view.physicalSize = const Size(320, 640);
        tester.view.devicePixelRatio = 1;
        addTearDown(tester.view.reset);
        for (final skeleton in const <Widget>[
          LoadingView(),
          LoadingView(message: 'Descargando boleta…'),
          SkeletonList(header: true, trailing: true),
          SkeletonList(leading: SkeletonLeading.square, lines: 3),
          SkeletonProfile(),
          SkeletonDetail(image: true),
        ]) {
          await tester.pumpWidget(_app(skeleton, brightness: brightness));
          await tester.pump(const Duration(milliseconds: 300));
          expect(tester.takeException(), isNull, reason: '$skeleton');
          expect(find.byType(CircularProgressIndicator), findsNothing);
          expect(find.byType(Shimmer), findsWidgets);
        }
      });
    }

    testWidgets('dentro de un ListView (alto sin límite) no rompe el layout', (tester) async {
      await tester.pumpWidget(_app(ListView(children: const [SkeletonPunchBoard(), SkeletonList(padding: EdgeInsets.zero)])));
      await tester.pump(const Duration(milliseconds: 300));
      expect(tester.takeException(), isNull);
      expect(find.byType(SkeletonTile), findsNWidgets(4));
    });

    testWidgets('con "reducir movimiento" la silueta queda fija (sin brillo animado)', (tester) async {
      await tester.pumpWidget(_app(const SkeletonList(), reduceMotion: true));
      expect(find.descendant(of: find.byType(Shimmer), matching: find.byType(ShaderMask)), findsNothing);
      // Sin animación pendiente: pumpAndSettle termina
      await tester.pumpAndSettle();
    });

    testWidgets('el lector de pantalla oye un solo "Cargando…"', (tester) async {
      final handle = tester.ensureSemantics();
      await tester.pumpWidget(_app(const SkeletonList()));
      expect(find.bySemanticsLabel('Cargando…'), findsOneWidget);
      handle.dispose();
    });

    testWidgets('whenSkeleton funde de la silueta a los datos y quita la silueta', (tester) async {
      final completer = Completer<String>();
      final provider = FutureProvider<String>((_) => completer.future);
      await tester.pumpWidget(ProviderScope(
        child: _app(Consumer(
          builder: (_, ref, _) => ref.watch(provider).whenSkeleton(
                loading: () => const SkeletonList(),
                error: (e, _) => ErrorView(error: e),
                data: (v) => Text(v),
              ),
        )),
      ));
      expect(find.byType(SkeletonList), findsOneWidget);

      completer.complete('Boleta de octubre');
      await tester.pump();
      // A mitad del fundido conviven las dos capas, alineadas arriba
      await tester.pump(skeletonFade ~/ 2);
      expect(find.text('Boleta de octubre'), findsOneWidget);
      await tester.pumpAndSettle();
      expect(find.byType(SkeletonList), findsNothing);
      expect(find.text('Boleta de octubre'), findsOneWidget);
    });
  });
}
