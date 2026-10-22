import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:tunesa_mobile/core/providers.dart';
import 'package:tunesa_mobile/core/config/app_config.dart';
import 'package:tunesa_mobile/core/push/push_options.dart';
import 'package:tunesa_mobile/core/theme/app_theme.dart';
import 'package:tunesa_mobile/features/benefits/domain/models.dart';
import 'package:tunesa_mobile/features/benefits/presentation/benefit_detail_screen.dart';
import 'package:tunesa_mobile/features/benefits/presentation/benefit_providers.dart';
import 'package:tunesa_mobile/features/benefits/presentation/benefits_screen.dart';
import 'package:tunesa_mobile/features/birthdays/domain/models.dart';
import 'package:tunesa_mobile/features/birthdays/presentation/birthday_celebration.dart';
import 'package:tunesa_mobile/features/birthdays/presentation/birthday_home_card.dart';
import 'package:tunesa_mobile/features/birthdays/presentation/birthday_providers.dart';
import 'package:tunesa_mobile/features/birthdays/presentation/birthdays_screen.dart';
import 'package:tunesa_mobile/features/company_docs/domain/models.dart';
import 'package:tunesa_mobile/features/company_docs/presentation/company_document_providers.dart';
import 'package:tunesa_mobile/features/company_docs/presentation/company_documents_screen.dart';
import 'package:tunesa_mobile/features/notifications/domain/models.dart';

Map<String, dynamic> _person(int id, String first, {int day = 14, int month = 2, bool today = false, int until = 5}) => {
      'id': id,
      'name': '$first Pérez',
      'first_name': first,
      'initials': '${first[0]}P',
      'position': 'Flebotomista',
      'department': 'Laboratorio',
      'branch': 'Lima Centro',
      'avatar_url': null,
      'day': day,
      'month': month,
      'date_label': '${day.toString().padLeft(2, '0')} de febrero',
      'is_today': today,
      'days_until': until,
    };

final _month = BirthdayMonth.fromJson({
  'month': 2,
  'month_name': 'Febrero',
  'today': [_person(1, 'Hoy', day: 10, today: true, until: 0)],
  'upcoming': [_person(2, 'Luego', day: 20, until: 10)],
  'past': [_person(3, 'Pasado', day: 5, until: 360)],
  'team': [_person(2, 'Luego', day: 20, until: 10)],
});

final _catalog = BenefitCatalog(
  items: [
    Benefit.fromJson({
      'id': 1,
      'title': 'Gimnasio',
      'partner': 'Smart Fit',
      'highlight': '30% dscto',
      'is_featured': true,
      'category': {'id': 1, 'name': 'Salud y bienestar', 'icon': 'heart-pulse', 'color': '#E0457B'},
    }),
    Benefit.fromJson({
      'id': 2,
      'title': 'Almuerzo',
      'category': {'id': 2, 'name': 'Alimentación', 'icon': 'cup-hot', 'color': '#F08C2E'},
    }),
  ],
  categories: [
    BenefitCategory.fromJson({'id': 1, 'name': 'Salud y bienestar', 'icon': 'heart-pulse', 'count': 1}),
    BenefitCategory.fromJson({'id': 2, 'name': 'Alimentación', 'icon': 'cup-hot', 'count': 1}),
  ],
);

final _library = DocumentLibrary(
  documents: [
    CompanyDocument.fromJson({
      'id': 5,
      'title': 'Reglamento Interno de Trabajo',
      'folder_id': 1,
      'folder': 'Reglamentos',
      'file_name': 'rit.pdf',
      'file_size': 2097152,
      'requires_acknowledgement': true,
      'pending_acknowledgement': true,
      'read': false,
    }),
    CompanyDocument.fromJson({'id': 6, 'title': 'Política de Calidad', 'folder_id': 2, 'file_name': 'p.pdf', 'file_size': 1000, 'read': true}),
  ],
  folders: [
    DocumentFolder.fromJson({'id': 1, 'name': 'Reglamentos', 'icon': 'journal-text', 'count': 1}),
    DocumentFolder.fromJson({'id': 2, 'name': 'Políticas', 'icon': 'shield-check', 'count': 1}),
  ],
  pendingAcknowledgement: 1,
);

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

  group('Modelos', () {
    test('cumpleaños: etiquetas y nunca el año', () {
      final b = Birthday.fromJson(_person(1, 'Ana', day: 14, until: 1));
      expect(b.whenLabel, 'Mañana');
      expect(b.shortDate, '14 feb');
      expect(Birthday.fromJson(_person(2, 'Hoy', today: true, until: 0)).whenLabel, '¡Hoy!');
      expect(_month.isEmpty, isFalse);
      expect(BirthdayGreeting.fromJson(null), isNull);
      expect(BirthdayGreeting.fromJson({'date': '2026-10-22', 'first_name': 'Ana'})!.title, '¡Feliz cumpleaños, Ana!');
    });

    test('beneficios y documentos se leen sin datos opcionales', () {
      final b = Benefit.fromJson({'id': 3, 'title': 'X', 'summary': '  ', 'promo_code': 'ABC'});
      expect(b.summary, isNull);
      expect(b.promoCode, 'ABC');
      expect(b.category, isNull);
      final d = CompanyDocument.fromJson({'id': 1, 'title': 'Y', 'file_size': 2097152});
      expect(d.sizeLabel, '2.0 MB');
      expect(_library.pending.single.id, 5);
      expect(_library.inFolder(2).single.title, 'Política de Calidad');
    });

    test('módulos de la app y rutas de los avisos push', () {
      expect(const ModulesConfig().benefits, isTrue);
      final m = ModulesConfig.fromJson({'benefits': false, 'birthdays': false, 'company_documents': false});
      expect([m.benefits, m.birthdays, m.companyDocuments], [false, false, false]);
      expect(m.toJson()['company_documents'], isFalse);

      expect(pushRouteFor({'screen': 'benefits'}), '/benefits');
      expect(pushRouteFor({'screen': 'birthdays'}), '/birthdays');
      expect(pushRouteFor({'screen': 'company_documents'}), '/company-documents');
      expect(AppNotification.routeFor('birthday.colleague'), '/birthdays');
      expect(AppNotification.routeFor('company_document.published'), '/company-documents');
      expect(AppNotification.routeFor('benefit.published'), '/benefits');
    });
  });

  group('Pantallas', () {
    testWidgets('Cumpleaños: mi equipo, hoy, próximos y pasados; al tocar se ve la ficha', (tester) async {
      await _pump(tester, const BirthdaysScreen(), overrides: [
        birthdayMonthProvider.overrideWith((ref, q) async => _month),
      ]);

      expect(find.text('Mi equipo'), findsOneWidget);
      expect(find.text('🎉 Hoy'), findsOneWidget);
      expect(find.text('Próximos cumpleaños'), findsOneWidget);
      expect(find.text('Cumpleaños pasados'), findsOneWidget);
      expect(find.text('HOY PÉREZ'), findsOneWidget);
      expect(find.text('Febrero'), findsOneWidget);

      await tester.ensureVisible(find.text('PASADO PÉREZ'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('PASADO PÉREZ'));
      await tester.pumpAndSettle();
      expect(find.text('Pasado Pérez'), findsOneWidget);
      expect(find.text('Lima Centro'), findsOneWidget);
    });

    testWidgets('Inicio: próximos cumpleaños con "Ver todos" y oculto si el módulo está apagado', (tester) async {
      final upcoming = UpcomingBirthdays(items: [Birthday.fromJson(_person(1, 'Milagros', today: true, until: 0))], total: 17);
      await _pump(tester, const Scaffold(body: BirthdayHomeCard()), overrides: [
        upcomingBirthdaysProvider.overrideWith((ref) async => upcoming),
        birthdaysEnabledProvider.overrideWithValue(true),
      ]);
      expect(find.text('Próximos cumpleaños'), findsOneWidget);
      expect(find.text('MILAGROS'), findsOneWidget);
      expect(find.text('16+'), findsOneWidget);

      await _pump(tester, const Scaffold(body: BirthdayHomeCard()), overrides: [
        upcomingBirthdaysProvider.overrideWith((ref) async => upcoming),
        birthdaysEnabledProvider.overrideWithValue(false),
      ]);
      expect(find.text('Próximos cumpleaños'), findsNothing);
    });

    testWidgets('Inicio: carrusel horizontal con hasta 5 cumpleaños y aviso si nadie cumple', (tester) async {
      final seven = [for (var i = 1; i <= 7; i++) Birthday.fromJson(_person(i, 'Persona$i', day: 20 + i, until: i))];
      await _pump(tester, const Scaffold(body: BirthdayHomeCard()), overrides: [
        upcomingBirthdaysProvider.overrideWith((ref) async => UpcomingBirthdays(items: seven, total: 9)),
        birthdaysEnabledProvider.overrideWithValue(true),
      ]);
      final carousel = tester.widget<ListView>(find.byKey(const Key('home-birthdays-carousel')));
      expect(carousel.scrollDirection, Axis.horizontal);
      expect(find.text('PERSONA5'), findsOneWidget);
      expect(find.text('PERSONA6'), findsNothing, reason: 'Máximo 5 en Inicio');
      expect(find.text('4+'), findsOneWidget);

      await tester.pumpWidget(const SizedBox());
      await _pump(tester, const Scaffold(body: BirthdayHomeCard()), overrides: [
        upcomingBirthdaysProvider.overrideWith((ref) async => UpcomingBirthdays.empty),
        birthdaysEnabledProvider.overrideWithValue(true),
      ]);
      expect(find.byKey(const Key('home-birthdays-empty')), findsOneWidget);
    });

    testWidgets('Cumpleaños propio: el modal festivo sale una sola vez ese día', (tester) async {
      SharedPreferences.setMockInitialValues({});
      final prefs = await SharedPreferences.getInstance();
      final greeting = BirthdayGreeting.fromJson({
        'date': '2026-10-22',
        'first_name': 'Fidel',
        'company': 'TUNESA',
        'title': '🎉 ¡Feliz cumpleaños, Fidel!',
        'body': 'Todo el equipo de TUNESA te desea un día increíble.',
      })!;

      await tester.pumpWidget(MaterialApp(
        theme: AppTheme.light(),
        home: Builder(builder: (context) => Scaffold(
          body: TextButton(
            onPressed: () => BirthdayCelebration.maybeShow(context, prefs, 7, greeting),
            child: const Text('abrir'),
          ),
        )),
      ));

      await tester.tap(find.text('abrir'));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('birthday-celebration')), findsOneWidget);
      expect(find.text('¡Feliz Cumpleaños, Fidel!'), findsOneWidget);
      expect(find.text('Con cariño, el equipo de TUNESA'), findsOneWidget);
      await tester.tap(find.byKey(const Key('birthday-celebration-close')));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('birthday-celebration')), findsNothing);

      // Mismo día: ya no vuelve a salir
      await tester.tap(find.text('abrir'));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('birthday-celebration')), findsNothing);
      expect(prefs.getString(BirthdayCelebration.prefsKey(7)), '2026-10-22');

      // Otro usuario en el mismo teléfono sí lo ve
      expect(BirthdayCelebration.alreadyShown(prefs, 8, greeting), isFalse);
    });

    testWidgets('Beneficios: destacados y filtro por categoría', (tester) async {
      await _pump(tester, const BenefitsScreen(), overrides: [
        benefitCatalogProvider.overrideWith((ref) async => _catalog),
      ]);
      expect(find.text('Destacados'), findsOneWidget);
      expect(find.text('Gimnasio'), findsNWidgets(2));
      expect(find.text('Almuerzo'), findsOneWidget);

      await tester.tap(find.text('Alimentación (1)'));
      await tester.pumpAndSettle();
      expect(find.text('Gimnasio'), findsNothing);
      expect(find.text('Almuerzo'), findsOneWidget);
    });

    testWidgets('Detalle del beneficio: código para copiar y cómo usarlo', (tester) async {
      final detail = Benefit.fromJson({
        'id': 1,
        'title': 'Gimnasio',
        'highlight': '30% dscto',
        'promo_code': 'TUNESA30',
        'how_to_use': 'Muestra tu fotocheck',
        'terms': 'Solo sedes de Lima',
      });
      await _pump(tester, const BenefitDetailScreen(id: 1), overrides: [
        benefitDetailProvider.overrideWith((ref, id) async => detail),
      ]);
      tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(SystemChannels.platform, (call) async => null);
      expect(find.text('TUNESA30'), findsOneWidget);
      expect(find.text('¿Cómo lo uso?'), findsOneWidget);
      await tester.tap(find.text('TUNESA30'));
      await tester.pump();
      expect(find.text('Código copiado'), findsOneWidget);
    });

    testWidgets('Documentos: carpetas y pestaña "Por confirmar"', (tester) async {
      SharedPreferences.setMockInitialValues({});
      final prefs = await SharedPreferences.getInstance();
      await _pump(tester, const CompanyDocumentsScreen(), overrides: [
        documentLibraryProvider.overrideWith((ref) async => _library),
        sharedPreferencesProvider.overrideWithValue(prefs),
      ]);
      expect(find.text('Reglamentos'), findsOneWidget);
      expect(find.text('Políticas'), findsOneWidget);
      expect(find.text('Por confirmar (1)'), findsOneWidget);

      await tester.tap(find.text('Por confirmar (1)'));
      await tester.pumpAndSettle();
      expect(find.text('Reglamento Interno de Trabajo'), findsOneWidget);
      expect(find.text('Por confirmar'), findsOneWidget);
    });
  });
}
