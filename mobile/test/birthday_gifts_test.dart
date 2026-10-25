import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:lottie/lottie.dart';
import 'package:tunesa_mobile/core/theme/app_theme.dart';
import 'package:tunesa_mobile/features/birthdays/domain/models.dart';
import 'package:tunesa_mobile/features/birthdays/presentation/birthday_celebration.dart';
import 'package:tunesa_mobile/features/birthdays/presentation/birthday_gifts.dart';
import 'package:tunesa_mobile/features/birthdays/presentation/birthday_providers.dart';

const _catalog = [
  {'type': 'lion', 'label': 'León rugiendo', 'emoji': '🦁'},
  {'type': 'crown', 'label': 'Corona brillante', 'emoji': '👑'},
  {'type': 'galaxy', 'label': 'Galaxia en movimiento', 'emoji': '🌌'},
  {'type': 'space_whale', 'label': 'Ballena espacial', 'emoji': '🐋'},
  {'type': 'cake', 'label': 'Pastel de fiesta', 'emoji': '🎂'},
  {'type': 'fireworks', 'label': 'Fuegos artificiales', 'emoji': '🎆'},
];

Map<String, dynamic> _gift({required bool playable}) => {
      'id': 9,
      'type': 'crown',
      'label': 'Corona brillante',
      'emoji': '👑',
      'date': '2026-06-15',
      'playable': playable,
      'sender': {'id': 3, 'name': 'Luis Rojas', 'first_name': 'Luis', 'initials': 'LR', 'avatar_url': null},
    };

GiftBoard _board({required bool today, required bool canSend, bool self = false}) => GiftBoard.fromJson({
      'is_today': today,
      'is_self': self,
      'can_send': canSend,
      'expires_at': today ? '2026-0615T23:59:59-05:00' : null,
      'sent_by_me': canSend ? ['crown'] : [],
      'catalog': _catalog,
      'gifts': [_gift(playable: today)],
    });

Future<void> _openSheet(WidgetTester tester, GiftBoard board) async {
  await tester.pumpWidget(ProviderScope(
    overrides: [giftBoardProvider.overrideWith((ref, id) async => board)],
    child: MaterialApp(
      theme: AppTheme.dark(),
      builder: (context, child) => MediaQuery(data: MediaQuery.of(context).copyWith(disableAnimations: true), child: child!),
      home: Builder(builder: (context) => Scaffold(
            body: TextButton(onPressed: () => showGiftsSheet(context, userId: 1, firstName: 'Ana'), child: const Text('abrir')),
          )),
    ),
  ));
  await tester.tap(find.text('abrir'));
  await tester.pumpAndSettle();
}

void main() {
  group('Modelos de regalos', () {
    test('el tablero se lee aunque los números lleguen como texto', () {
      final b = GiftBoard.fromJson({
        'is_today': true,
        'can_send': true,
        'sent_by_me': ['lion'],
        'catalog': _catalog,
        'gifts': [
          {..._gift(playable: true), 'id': '12'},
        ],
      });
      expect(b.catalog.map((g) => g.asset), contains('assets/gifts/space_whale.json'));
      expect(b.sentByMe, {'lion'});
      expect(b.gifts.single.id, 12);
      expect(b.gifts.single.sender.firstName, 'Luis');
    });

    test('la paleta de la tarjeta depende del género registrado', () {
      expect(CelebrationPalette.forGender('male'), same(CelebrationPalette.male));
      expect(CelebrationPalette.forGender('female'), same(CelebrationPalette.female));
      expect(CelebrationPalette.forGender(null), same(CelebrationPalette.neutral));
      final g = BirthdayGreeting.fromJson({'date': '2026-06-15', 'first_name': 'Ana', 'user_id': '4', 'gender': 'female'})!;
      expect([g.userId, g.gender], [4, 'female']);
    });
  });

  test('cada regalo tiene una animación Lottie válida a 60 FPS', () async {
    for (final g in [..._catalog.map((e) => e['type']), 'celebration']) {
      final bytes = await File('assets/gifts/$g.json').readAsBytes();
      final composition = await LottieComposition.fromBytes(bytes);
      expect(composition.frameRate, 60, reason: g);
      expect(composition.duration, const Duration(seconds: 3), reason: g);
      expect(composition.layers, isNotEmpty, reason: g);
    }
  });

  testWidgets('el día del cumpleaños: galería para enviar y el ya enviado deshabilitado', (tester) async {
    await _openSheet(tester, _board(today: true, canSend: true));
    expect(find.text('Regalos para Ana 🎁'), findsOneWidget);
    expect(find.byKey(const Key('gift-catalog')), findsOneWidget);
    for (final g in _catalog) {
      expect(find.byKey(Key('gift-option-${g['type']}')), findsOneWidget);
    }
    expect(find.text('Enviado ✓'), findsOneWidget); // corona ya enviada
  });

  testWidgets('caducado: sin galería y el regalo recibido queda como historial', (tester) async {
    await _openSheet(tester, _board(today: false, canSend: false));
    expect(find.byKey(const Key('gift-catalog')), findsNothing);
    expect(find.text('Los regalos solo se pueden enviar el día de su cumpleaños.'), findsOneWidget);
    expect(find.byIcon(Icons.lock_clock_rounded), findsOneWidget);
    await tester.tap(find.byKey(const Key('received-gift-9')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('gift-player')), findsNothing); // no se reproduce
  });

  testWidgets('el regalo se reproduce a pantalla completa con quién lo envió', (tester) async {
    await _openSheet(tester, _board(today: true, canSend: false, self: true));
    expect(find.text('Tus regalos 🎁'), findsOneWidget);
    await tester.tap(find.byKey(const Key('received-gift-9')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('gift-player')), findsOneWidget);
    expect(find.text('De parte de'), findsOneWidget);
    expect(find.text('Luis Rojas'), findsWidgets);
    await tester.tap(find.byKey(const Key('gift-player')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('gift-player')), findsNothing);
  });
}
