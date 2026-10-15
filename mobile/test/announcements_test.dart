import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:tunesa_mobile/core/config/app_config.dart';
import 'package:tunesa_mobile/core/push/push_options.dart';
import 'package:tunesa_mobile/core/theme/app_theme.dart';
import 'package:tunesa_mobile/features/announcements/domain/models.dart';
import 'package:tunesa_mobile/features/announcements/presentation/announcement_detail_screen.dart';
import 'package:tunesa_mobile/features/announcements/presentation/announcement_home_card.dart';
import 'package:tunesa_mobile/features/announcements/presentation/announcement_providers.dart';
import 'package:tunesa_mobile/features/announcements/presentation/announcements_screen.dart';
import 'package:tunesa_mobile/features/notifications/domain/models.dart';

Map<String, dynamic> _item(int id, String title, {String category = 'general', bool important = false, bool read = false}) => {
      'id': id,
      'title': title,
      'excerpt': 'Detalle de $title',
      'category': category,
      'category_label': {'meeting': 'Reunión', 'urgent': 'Urgente'}[category] ?? 'Aviso general',
      'is_important': important,
      'event_at': category == 'meeting' ? '2026-10-20T15:00:00-05:00' : null,
      'sent_at': '2026-10-15T09:00:00-05:00',
      'read': read,
    };

final _feed = AnnouncementFeed(items: [
  Announcement.fromJson(_item(1, 'Corte de agua', category: 'urgent')),
  Announcement.fromJson(_item(2, 'Reunión general', category: 'meeting', important: true)),
  Announcement.fromJson(_item(3, 'Nuevo uniforme', read: true, important: true)),
  Announcement.fromJson(_item(4, 'Cambio de horario')),
], unread: 3);

Future<void> _pump(WidgetTester tester, Widget child, {List overrides = const []}) async {
  await tester.pumpWidget(ProviderScope(
    overrides: [...overrides],
    child: MaterialApp(theme: AppTheme.light(), home: child),
  ));
  await tester.pump();
  await tester.pump();
}

void main() {
  setUpAll(() => initializeDateFormatting('es'));

  test('modelo, módulo y rutas de los avisos', () {
    expect(_feed.highlighted.map((a) => a.id), [1, 2]); // urgentes e importantes sin leer
    expect(Announcement.fromJson({'id': 9, 'title': 'X', 'location': ' '}).location, isNull);
    expect(ModulesConfig.fromJson({'announcements': false}).announcements, isFalse);
    expect(const ModulesConfig().announcements, isTrue);

    expect(pushRouteFor({'screen': 'announcements', 'announcement_id': 7}), '/announcements/7');
    expect(pushRouteFor({'screen': 'announcements'}), '/announcements');
    expect(AppNotification.routeFor('announcement.urgent', {'announcement_id': '4'}), '/announcements/4');
    expect(AppNotification.routeFor('announcement.published'), '/announcements');
  });

  testWidgets('Lista de comunicados con tipo y fecha del evento', (tester) async {
    await _pump(tester, const AnnouncementsScreen(), overrides: [announcementFeedProvider.overrideWith((ref) async => _feed)]);
    expect(find.text('Corte de agua'), findsOneWidget);
    expect(find.text('URGENTE'), findsOneWidget);
    expect(find.text('REUNIÓN'), findsOneWidget);
    expect(find.byIcon(Icons.event_rounded), findsOneWidget);
  });

  testWidgets('Inicio: solo los importantes sin leer; oculto con el módulo apagado', (tester) async {
    await _pump(tester, const Scaffold(body: AnnouncementHomeCard()), overrides: [
      announcementFeedProvider.overrideWith((ref) async => _feed),
      announcementsEnabledProvider.overrideWithValue(true),
    ]);
    expect(find.text('Corte de agua'), findsOneWidget);
    expect(find.text('Reunión general'), findsOneWidget);
    expect(find.text('Nuevo uniforme'), findsNothing);
    expect(find.text('Ver los 3 sin leer'), findsOneWidget);

    await _pump(tester, const Scaffold(body: AnnouncementHomeCard()), overrides: [
      announcementFeedProvider.overrideWith((ref) async => _feed),
      announcementsEnabledProvider.overrideWithValue(false),
    ]);
    expect(find.text('Corte de agua'), findsNothing);
  });

  testWidgets('Detalle: mensaje completo, lugar y enlace', (tester) async {
    final detail = Announcement.fromJson(_item(2, 'Reunión general', category: 'meeting')
      ..addAll({'body': 'Traer laptop.', 'location': 'Sala 2', 'url': 'https://meet.example.com'}));
    await _pump(tester, const AnnouncementDetailScreen(id: 2), overrides: [announcementDetailProvider(2).overrideWith((ref) async => detail)]);
    expect(find.text('Reunión general'), findsOneWidget);
    expect(find.text('Traer laptop.'), findsOneWidget);
    expect(find.text('Sala 2'), findsOneWidget);
    expect(find.text('Abrir enlace'), findsOneWidget);
  });
}
