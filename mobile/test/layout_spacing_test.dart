import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:tunesa_mobile/core/config/version_gate.dart';
import 'package:tunesa_mobile/core/providers.dart';
import 'package:tunesa_mobile/core/theme/app_theme.dart';
import 'package:tunesa_mobile/core/widgets/system_bars_inset.dart';
import 'package:tunesa_mobile/features/account/domain/models.dart';
import 'package:tunesa_mobile/features/account/presentation/devices_screen.dart';
import 'package:tunesa_mobile/features/profile/domain/models.dart';
import 'package:tunesa_mobile/features/profile/presentation/documents_screen.dart';
import 'package:tunesa_mobile/features/profile/presentation/profile_providers.dart';
import 'package:tunesa_mobile/features/profile/presentation/profile_screen.dart';

/// Teléfono angosto (360 x 780 dp) con barra de 3 botones abajo (48 dp).
void _phone(WidgetTester tester, {double width = 360, double navBar = 48}) {
  tester.view.physicalSize = Size(width, 780);
  tester.view.devicePixelRatio = 1;
  tester.view.padding = FakeViewPadding(bottom: navBar);
  tester.view.viewPadding = FakeViewPadding(bottom: navBar);
  addTearDown(tester.view.reset);
}

EmployeeFile _file({bool withDni = false}) => EmployeeFile.fromJson({
      'user': {'full_name': 'Ana Torres', 'email': 'ana@demo.pe'},
      'documents': {
        'items': [
          if (withDni) {'id': 7, 'type': 'dni', 'status': 'approved', 'original_name': 'dni.jpg', 'mime_type': 'image/jpeg'},
        ],
        'required': ['dni', 'cv'],
        'missing': [if (!withDni) 'dni', 'cv'],
      },
      'config': {
        'modules': {'documents': true},
        'permissions': {'upload_documents': true},
        'required_documents': ['dni', 'cv'],
        'max_upload_mb': 5,
      },
      'catalogs': {
        'document_types': {'dni': 'Documento de identidad', 'cv': 'Currículum (CV)', 'proof_of_address': 'Recibo de servicios / domicilio'},
      },
    });

Future<void> _pumpApp(WidgetTester tester, Widget home, {List overrides = const [], ThemeData? theme}) async {
  SharedPreferences.setMockInitialValues({});
  final prefs = await SharedPreferences.getInstance();
  await tester.pumpWidget(ProviderScope(
    overrides: [sharedPreferencesProvider.overrideWithValue(prefs), ...overrides.cast()],
    child: MaterialApp(
      theme: theme ?? AppTheme.light(),
      builder: (context, child) => SystemBarsInset(child: child!),
      home: home,
    ),
  ));
  await tester.pumpAndSettle();
}

void main() {
  setUpAll(() => initializeDateFormatting('es'));

  testWidgets('ninguna pantalla queda debajo de la barra de navegación del teléfono', (tester) async {
    _phone(tester);
    await _pumpApp(
      tester,
      Scaffold(
        appBar: AppBar(title: const Text('Notificaciones')),
        body: ListView(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
          children: [for (var i = 0; i < 30; i++) ListTile(key: Key('row-$i'), title: Text('Fila $i'))],
        ),
      ),
    );

    // Al final del scroll, la última fila termina por encima de la barra del sistema
    await tester.dragUntilVisible(find.byKey(const Key('row-29')), find.byType(ListView), const Offset(0, -300));
    await tester.drag(find.byType(ListView), const Offset(0, -2000));
    await tester.pumpAndSettle();
    expect(tester.getRect(find.byKey(const Key('row-29'))).bottom, lessThanOrEqualTo(780 - 48));
    expect(tester.getRect(find.byType(Scaffold)).bottom, 780 - 48);
  });

  for (final theme in [AppTheme.light(), AppTheme.dark()]) {
    testWidgets('Mis dispositivos: las tarjetas tienen aire entre sí (${theme.brightness.name})', (tester) async {
      _phone(tester);
      await _pumpApp(
        tester,
        Scaffold(
          body: DevicesList(
            sessions: [
              DeviceSession.fromJson({'id': 1, 'label': 'Teléfono Android', 'current': true}),
              DeviceSession.fromJson({'id': 2, 'label': 'Teléfono Android', 'current': false, 'last_used_at': '2026-10-11T19:46:00-05:00'}),
              DeviceSession.fromJson({'id': 3, 'label': 'iPhone', 'current': false, 'last_used_at': '2026-10-11T00:57:00-05:00'}),
            ],
            onRevoke: (_) {},
            onLogoutOthers: () {},
            onRefresh: () async {},
          ),
        ),
        theme: theme,
      );

      // El borde visible de cada tarjeta (sin su margen)
      Rect card(int id) => tester.getRect(find.descendant(of: find.byKey(Key('session-$id')), matching: find.byType(Material)).first);
      final r1 = card(1);
      final r2 = card(2);
      final r3 = card(3);
      expect(r2.top - r1.bottom, greaterThanOrEqualTo(10));
      expect(r3.top - r2.bottom, greaterThanOrEqualTo(10));
      expect(tester.getRect(find.byKey(const Key('logout-others'))).top - r3.bottom, greaterThanOrEqualTo(10));
      expect(tester.takeException(), isNull);
    });
  }

  for (final width in [320.0, 360.0, 412.0]) {
    testWidgets('Mis documentos: solo el ícono de subida y el nombre en una línea (${width.toInt()} dp)', (tester) async {
      _phone(tester, width: width);
      await _pumpApp(
        tester,
        const DocumentsScreen(),
        overrides: [employeeFileProvider.overrideWith((ref) async => _file(withDni: width == 412))],
      );

      expect(find.text('Subir'), findsNothing);
      expect(find.text('Reemplazar'), findsNothing);
      expect(find.byType(FilledButton), findsNothing);
      expect(find.byKey(const Key('upload-document')), findsNWidgets(3));

      for (final label in ['Documento de identidad', 'Currículum (CV)', 'Recibo de servicios / domicilio']) {
        final text = find.text(label);
        expect(tester.widget<Text>(text).maxLines, 1);
        // Una línea de titleSmall (15 px) mide menos de 30 px de alto
        expect(tester.getSize(text).height, lessThan(30));
      }
      expect(tester.takeException(), isNull);
    });
  }

  testWidgets('Mi perfil muestra la versión instalada de la app', (tester) async {
    _phone(tester);
    await _pumpApp(
      tester,
      Scaffold(body: ProfileBody(file: _file())),
      overrides: [appVersionProvider.overrideWithValue('1.4.2')],
    );

    await tester.scrollUntilVisible(find.byKey(const Key('account-version')), 200, scrollable: find.byType(Scrollable).first);
    expect(find.text('Versión de la app'), findsOneWidget);
    expect(find.text('1.4.2'), findsOneWidget);
  });
}
