import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:tunesa_mobile/core/network/api_client.dart';
import 'package:tunesa_mobile/core/providers.dart';
import 'package:tunesa_mobile/core/storage/session_storage.dart';
import 'package:tunesa_mobile/core/utils/paged.dart';
import 'package:tunesa_mobile/features/notifications/data/notification_repository.dart';
import 'package:tunesa_mobile/features/notifications/domain/models.dart';
import 'package:tunesa_mobile/features/notifications/presentation/notifications_providers.dart';
import 'package:tunesa_mobile/features/notifications/presentation/notifications_screen.dart';
import 'package:tunesa_mobile/features/team/data/team_repository.dart';
import 'package:tunesa_mobile/features/team/domain/models.dart';
import 'package:tunesa_mobile/features/team/presentation/approvals_screen.dart';
import 'package:tunesa_mobile/features/team/presentation/team_live_screen.dart';
import 'package:tunesa_mobile/features/team/presentation/team_providers.dart';

class _FakeTeamRepository extends TeamRepository {
  _FakeTeamRepository(super.api);

  final approved = <int>[];
  final rejected = <int, String>{};

  @override
  Future<Paged<ApprovalRequest>> pendingApprovals({int page = 1}) async => Paged.fromJson({
        'data': [
          {
            'id': 9,
            'type': 'permission',
            'type_label': 'Permiso',
            'start_date': '2026-10-10',
            'end_date': '2026-10-10',
            'days': 1,
            'reason': 'Cita médica',
            'status': 'pending',
            'employee': {'id': 4, 'name': 'Luis Rojas', 'employee_code': 'EMP-004'},
          },
        ],
        'meta': {'total': 1, 'current_page': 1},
      }, ApprovalRequest.fromJson);

  @override
  Future<void> approve(int id, {String? notes}) async => approved.add(id);

  @override
  Future<void> reject(int id, {required String notes}) async => rejected[id] = notes;
}

class _FakeNotificationRepository extends NotificationRepository {
  _FakeNotificationRepository(super.api);

  final read = <String>[];

  @override
  Future<Paged<AppNotification>> list({int page = 1}) async => Paged.fromJson({
        'data': [
          {
            'id': 'a1',
            'type': 'system.announcement',
            'title': 'Bienvenido',
            'body': 'Ya puedes usar la app.',
            'data': <String, dynamic>{},
            'read_at': null,
            'created_at': '2026-10-09T08:00:00-05:00',
          },
        ],
        'meta': {'current_page': 1, 'last_page': 1, 'total': 1, 'unread_count': 1},
      }, AppNotification.fromJson);

  @override
  Future<void> markRead(String id) async => read.add(id);

  @override
  Future<void> markAllRead() async {}
}

Widget _app(Widget home, List overrides) => ProviderScope(
      overrides: overrides.cast(),
      child: MaterialApp(
        locale: const Locale('es'),
        supportedLocales: const [Locale('es')],
        localizationsDelegates: GlobalMaterialLocalizations.delegates,
        home: home,
      ),
    );

void main() {
  late ApiClient api;
  late SharedPreferences prefs;

  setUpAll(() => initializeDateFormatting('es'));

  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    prefs = await SharedPreferences.getInstance();
    api = ApiClient(SessionStorage(prefs));
  });

  testWidgets('aprobaciones: rechazar pide el motivo y quita la solicitud', (tester) async {
    final repo = _FakeTeamRepository(api);
    await tester.pumpWidget(_app(const ApprovalsScreen(), [teamRepositoryProvider.overrideWithValue(repo)]));
    await tester.pumpAndSettle();

    expect(find.text('Luis Rojas · EMP-004'), findsOneWidget);
    await tester.tap(find.text('Rechazar'));
    await tester.pumpAndSettle();

    // Sin motivo no deja rechazar
    await tester.tap(find.widgetWithText(FilledButton, 'Rechazar'));
    await tester.pumpAndSettle();
    expect(find.text('Escribe el motivo'), findsOneWidget);

    await tester.enterText(find.byType(TextFormField), 'Sin cobertura ese día');
    await tester.tap(find.widgetWithText(FilledButton, 'Rechazar'));
    await tester.pumpAndSettle();

    expect(repo.rejected, {9: 'Sin cobertura ese día'});
    expect(find.text('No hay solicitudes pendientes de aprobación.'), findsOneWidget);
  });

  testWidgets('equipo en vivo: totales y alertas de tardanza y geocerca', (tester) async {
    final overview = TeamOverview.fromJson({
      'summary': {'team_size': 2, 'present': 1, 'late': 1, 'absent': 1},
      'attendances': [
        {
          'id': 1,
          'work_date': '2026-10-09',
          'status': 'late',
          'status_label': 'Tardanza',
          'clock_in': {'timestamp': '2026-10-09T08:20:00-05:00', 'within_geofence': false},
          'summary': {'late_minutes': 20},
          'branch': {'id': 1, 'name': 'Sede Central'},
        },
      ],
    }, {
      'data': [
        {'id': 4, 'full_name': 'Luis Rojas'},
        {'id': 5, 'full_name': 'María Soto'},
      ],
    });
    await tester.pumpWidget(_app(const TeamLiveScreen(), [teamOverviewProvider.overrideWith((ref) async => overview)]));
    await tester.pumpAndSettle();

    expect(find.text('Tardanza 20 min'), findsOneWidget);
    expect(find.text('Fuera de geocerca'), findsOneWidget);
    expect(find.text('María Soto'), findsOneWidget);
  });

  testWidgets('notificaciones: tocar marca como leída', (tester) async {
    final repo = _FakeNotificationRepository(api);
    await tester.pumpWidget(_app(const NotificationsScreen(), [
      notificationRepositoryProvider.overrideWithValue(repo),
      // La campana lee la sesión (sin sesión: contador en 0)
      sharedPreferencesProvider.overrideWithValue(prefs),
    ]));
    await tester.pumpAndSettle();

    expect(find.text('Bienvenido'), findsOneWidget);
    await tester.tap(find.text('Bienvenido'));
    await tester.pumpAndSettle();
    expect(repo.read, ['a1']);
  });
}
