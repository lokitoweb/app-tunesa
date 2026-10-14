// Recorrido de la app en un emulador Android (o simulador iOS) contra el
// backend real sembrado: inicia sesión y abre cada menú y pantalla.
//
// Falla si una pantalla muestra error, si algo se desborda (texto o botones
// que no caben) o si una excepción llega a Flutter.
//
//   php artisan migrate:fresh --seed && php artisan serve --host 0.0.0.0
//   flutter test integration_test --dart-define-from-file=env/dev.json
//
// En CI lo corre el workflow "App ↔ API: pruebas de punta a punta".
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:integration_test/integration_test.dart';
import 'package:tunesa_mobile/core/widgets/async_views.dart';
import 'package:tunesa_mobile/features/notifications/presentation/notifications_screen.dart';
import 'package:tunesa_mobile/main.dart' as app;

/// Bombea cuadros hasta que [finder] aparece (las pantallas cargan por red y
/// tienen animaciones infinitas, así que pumpAndSettle no sirve).
Future<void> waitFor(WidgetTester tester, Finder finder, {Duration timeout = const Duration(seconds: 30)}) async {
  final end = DateTime.now().add(timeout);
  while (DateTime.now().isBefore(end)) {
    await tester.pump(const Duration(milliseconds: 200));
    if (finder.evaluate().isNotEmpty) return;
  }
  throw TestFailure('No apareció: $finder');
}

/// Deja que la pantalla termine de cargar y comprueba que no muestra un error.
Future<void> settleScreen(WidgetTester tester, String name) async {
  for (var i = 0; i < 15; i++) {
    await tester.pump(const Duration(milliseconds: 200));
    if (find.byType(LoadingView).evaluate().isEmpty && find.byType(CircularProgressIndicator).evaluate().isEmpty) break;
  }
  await tester.pump(const Duration(milliseconds: 500));
  expect(find.byType(ErrorView), findsNothing, reason: '$name muestra un error');
}

Future<void> tapText(WidgetTester tester, String text) async {
  final finder = find.text(text).first;
  await tester.ensureVisible(finder);
  await tester.pump();
  await tester.tap(finder);
}

/// Abre una pantalla desde la actual, la revisa y vuelve atrás.
Future<void> visit(WidgetTester tester, String tile, String title) async {
  await tapText(tester, tile);
  await waitFor(tester, find.widgetWithText(AppBar, title));
  await settleScreen(tester, title);
  await tester.pageBack();
  await tester.pump(const Duration(milliseconds: 600));
}

Future<void> login(WidgetTester tester, String email, String password) async {
  await waitFor(tester, find.widgetWithText(TextFormField, 'Correo'), timeout: const Duration(seconds: 60));
  await tester.enterText(find.widgetWithText(TextFormField, 'Correo'), email);
  await tester.enterText(find.widgetWithText(TextFormField, 'Contraseña'), password);
  // "Listo" en el teclado ya envía el formulario (onFieldSubmitted)
  await tester.testTextInput.receiveAction(TextInputAction.done);
  await waitFor(tester, find.widgetWithText(AppBar, 'Inicio'));
  await settleScreen(tester, 'Inicio');
}

Future<void> logout(WidgetTester tester) async {
  await tapText(tester, 'Mi perfil');
  await waitFor(tester, find.widgetWithText(AppBar, 'Mi perfil'));
  await tapText(tester, 'Cerrar sesión');
  await waitFor(tester, find.byType(AlertDialog));
  await tester.tap(find.descendant(of: find.byType(AlertDialog), matching: find.text('Cerrar sesión')));
  await waitFor(tester, find.widgetWithText(TextFormField, 'Correo'));
}

void main() {
  IntegrationTestWidgetsFlutterBinding.ensureInitialized();

  testWidgets('colaborador y supervisor recorren todos los menús', (tester) async {
    await app.main();

    // --- Colaborador ---------------------------------------------------------
    await login(tester, 'ana.torres@empresa-demo.com', 'Employee2024!');

    // La campana dice "Notificaciones (N sin leer)" si hay avisos pendientes
    await tester.tap(find.byType(NotificationBell).first);
    await waitFor(tester, find.widgetWithText(AppBar, 'Notificaciones'));
    await settleScreen(tester, 'Notificaciones (bandeja)');
    await tester.pageBack();
    await tester.pump(const Duration(milliseconds: 600));

    await tapText(tester, 'Asistencia');
    await waitFor(tester, find.widgetWithText(AppBar, 'Mis asistencias'));
    await settleScreen(tester, 'Mi asistencia');

    await tapText(tester, 'Solicitudes');
    await waitFor(tester, find.widgetWithText(AppBar, 'Mis solicitudes'));
    await settleScreen(tester, 'Mis solicitudes');
    await visit(tester, 'Nueva', 'Nueva solicitud');

    await tapText(tester, 'Boletas');
    await waitFor(tester, find.widgetWithText(AppBar, 'Mis boletas'));
    await settleScreen(tester, 'Mis boletas');

    await tapText(tester, 'Mi perfil');
    await waitFor(tester, find.widgetWithText(AppBar, 'Mi perfil'));
    await settleScreen(tester, 'Mi perfil');
    await visit(tester, 'Mis documentos', 'Mis documentos');
    await visit(tester, 'Información de pagos', 'Información de pagos');
    await visit(tester, 'Uniforme y tallas', 'Uniforme y tallas');
    await visit(tester, 'Datos de contacto', 'Datos de contacto');
    await visit(tester, 'Capacitaciones', 'Capacitaciones');
    await visit(tester, 'Mis dispositivos', 'Mis dispositivos');
    await visit(tester, 'Notificaciones', 'Notificaciones');
    await visit(tester, 'Privacidad y datos', 'Privacidad y datos');

    await logout(tester);

    // --- Supervisor ----------------------------------------------------------
    await login(tester, 'supervisor@empresa-demo.com', 'Supervisor2024!');
    await visit(tester, 'Aprobaciones', 'Aprobaciones');
    await visit(tester, 'Equipo en vivo', 'Mi equipo hoy');
    await logout(tester);
  });
}
