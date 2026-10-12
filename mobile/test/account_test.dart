import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:tunesa_mobile/core/push/push_controller.dart';
import 'package:tunesa_mobile/core/push/push_options.dart';
import 'package:tunesa_mobile/core/theme/app_theme.dart';
import 'package:tunesa_mobile/features/account/domain/models.dart';
import 'package:tunesa_mobile/features/account/presentation/devices_screen.dart';
import 'package:tunesa_mobile/features/account/presentation/notification_settings_screen.dart';
import 'package:tunesa_mobile/features/account/presentation/push_status_card.dart';
import 'package:tunesa_mobile/features/notifications/domain/models.dart';

Future<void> _pump(WidgetTester tester, Widget child) =>
    tester.pumpWidget(MaterialApp(theme: AppTheme.light(), home: Scaffold(body: child)));

void main() {
  setUpAll(() => initializeDateFormatting('es'));

  test('las sesiones se leen con su nombre legible y la actual marcada', () {
    final s = DeviceSession.fromJson({'id': 3, 'device': 'app_android_x', 'label': 'Teléfono Android', 'current': true, 'last_used_at': '2026-10-10T08:00:00-05:00'});
    expect(s.label, 'Teléfono Android');
    expect(s.current, isTrue);
    expect(s.lastUsedAt, isNotNull);
    expect(DeviceSession.fromJson({'id': 4, 'device': 'pc'}).label, 'pc');
  });

  testWidgets('Mis dispositivos: la sesión actual no se cierra, las otras sí', (tester) async {
    DeviceSession? revoked;
    var others = false;
    await _pump(
      tester,
      DevicesList(
        sessions: [
          DeviceSession.fromJson({'id': 1, 'label': 'Teléfono Android', 'current': true}),
          DeviceSession.fromJson({'id': 2, 'label': 'iPhone', 'current': false}),
        ],
        onRevoke: (s) => revoked = s,
        onLogoutOthers: () => others = true,
        onRefresh: () async {},
      ),
    );

    expect(find.text('Este dispositivo'), findsOneWidget);
    expect(find.descendant(of: find.byKey(const Key('session-1')), matching: find.byIcon(Icons.logout_rounded)), findsNothing);

    await tester.tap(find.descendant(of: find.byKey(const Key('session-2')), matching: find.byIcon(Icons.logout_rounded)));
    expect(revoked?.id, 2);

    await tester.tap(find.byKey(const Key('logout-others')));
    expect(others, isTrue);
    expect(find.text('Cerrar sesión en los otros 1'), findsOneWidget);
  });

  testWidgets('sin otras sesiones no ofrece cerrarlas', (tester) async {
    await _pump(
      tester,
      DevicesList(
        sessions: [DeviceSession.fromJson({'id': 1, 'label': 'iPhone', 'current': true})],
        onRevoke: (_) {},
        onLogoutOthers: () {},
        onRefresh: () async {},
      ),
    );
    expect(find.byKey(const Key('logout-others')), findsNothing);
  });

  testWidgets('Notificaciones: cada categoría tiene su interruptor', (tester) async {
    final changes = <String, bool>{};
    await _pump(
      tester,
      NotificationPreferencesList(
        preferences: [
          NotificationPreference.fromJson({'key': 'payslips', 'title': 'Boletas de pago', 'description': 'x', 'enabled': true}),
          NotificationPreference.fromJson({'key': 'trainings', 'title': 'Capacitaciones', 'description': 'y', 'enabled': false}),
        ],
        onChanged: (p, v) => changes[p.key] = v,
      ),
    );

    expect(tester.widget<SwitchListTile>(find.byKey(const Key('pref-trainings'))).value, isFalse);
    await tester.tap(find.byKey(const Key('pref-payslips')));
    expect(changes, {'payslips': false});
    expect(find.text('Alertas de seguridad'), findsOneWidget);
  });

  test('los avisos de seguridad llevan a Mis dispositivos', () {
    expect(AppNotification.routeFor('security.new_device'), '/profile/devices');
    expect(pushRouteFor({'type': 'security.password_changed', 'screen': 'devices'}), '/profile/devices');
  });

  test('permiso de notificaciones: Android "denegado para siempre" también cuenta como bloqueado', () {
    expect(pushPermissionFrom(AuthorizationStatus.authorized), PushPermission.granted);
    expect(pushPermissionFrom(AuthorizationStatus.provisional), PushPermission.granted);
    expect(pushPermissionFrom(AuthorizationStatus.denied), PushPermission.denied);
    expect(pushPermissionFrom(AuthorizationStatus.deniedPermanently), PushPermission.denied);
    expect(pushPermissionFrom(AuthorizationStatus.notDetermined), PushPermission.notDetermined);
  });

  testWidgets('estado de avisos: bloqueados ofrece permitir, permitidos ofrece una prueba', (tester) async {
    var allowed = 0;
    var tested = 0;
    Widget card(PushStatus status, PushPermission? permission) => PushStatusView(
          status: status,
          permission: permission,
          onAllow: () => allowed++,
          onTest: () => tested++,
        );

    await _pump(tester, card(PushStatus.enabled, PushPermission.denied));
    expect(find.text('Tu teléfono bloquea los avisos'), findsOneWidget);
    await tester.tap(find.byKey(const Key('push-allow')));
    expect(allowed, 1);

    await _pump(tester, card(PushStatus.enabled, PushPermission.granted));
    expect(find.text('Este teléfono recibe avisos'), findsOneWidget);
    await tester.tap(find.byKey(const Key('push-test')));
    expect(tested, 1);

    // Consultando el permiso: el botón espera
    await _pump(tester, card(PushStatus.enabled, null));
    await tester.tap(find.byKey(const Key('push-test')));
    expect(tested, 1);

    await _pump(tester, card(PushStatus.disabledByCompany, PushPermission.granted));
    expect(find.text('Avisos desactivados por tu empresa'), findsOneWidget);
    expect(find.byType(OutlinedButton), findsNothing);

    await _pump(tester, card(PushStatus.unavailable, PushPermission.unknown));
    expect(find.text('Avisos no disponibles'), findsOneWidget);
  });

  testWidgets('Notificaciones muestra el estado del teléfono arriba de la lista', (tester) async {
    await _pump(
      tester,
      NotificationPreferencesList(
        preferences: const [],
        onChanged: (_, _) {},
        header: const Text('estado-del-telefono'),
      ),
    );
    expect(find.text('estado-del-telefono'), findsOneWidget);
  });
}
