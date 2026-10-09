import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:tunesa_mobile/core/network/api_client.dart';
import 'package:tunesa_mobile/core/network/api_exception.dart';
import 'package:tunesa_mobile/core/storage/session_storage.dart';
import 'package:tunesa_mobile/core/utils/paged.dart';
import 'package:tunesa_mobile/core/widgets/async_views.dart';
import 'package:tunesa_mobile/features/auth/domain/models.dart';
import 'package:tunesa_mobile/features/notifications/data/notification_repository.dart';
import 'package:tunesa_mobile/features/notifications/domain/models.dart';
import 'package:tunesa_mobile/features/requests/data/leave_repository.dart';
import 'package:tunesa_mobile/features/requests/domain/models.dart';
import 'package:tunesa_mobile/features/requests/presentation/requests_screen.dart';
import 'package:tunesa_mobile/features/team/domain/models.dart';

Map<String, dynamic> _leave(int id) => {
      'id': id,
      'type': 'vacation',
      'type_label': 'Vacaciones',
      'start_date': '2026-11-02',
      'end_date': '2026-11-06',
      'days': 5,
      'reason': 'Viaje familiar',
      'status': 'pending',
      'has_attachment': false,
    };

/// Repositorio falso: 3 páginas de 2; la página [failPage] falla una vez.
class _FakeLeaveRepository extends LeaveRepository {
  _FakeLeaveRepository(super.api, {this.failPage});

  int? failPage;
  final requested = <int>[];

  @override
  Future<Paged<LeaveRequest>> list({int page = 1}) async {
    requested.add(page);
    if (page == failPage) {
      failPage = null;
      throw const ApiException('Sin conexión con el servidor.', code: 'NETWORK');
    }
    return Paged.fromJson({
      'data': [_leave(page * 10 + 1), _leave(page * 10 + 2)],
      'meta': {'total': 6, 'current_page': page},
    }, LeaveRequest.fromJson, perPage: 2);
  }
}

void main() {
  group('PagedController', () {
    late ProviderContainer container;
    late _FakeLeaveRepository repo;

    setUp(() async {
      SharedPreferences.setMockInitialValues({});
      final prefs = await SharedPreferences.getInstance();
      repo = _FakeLeaveRepository(ApiClient(SessionStorage(prefs)), failPage: 2);
      container = ProviderContainer(overrides: [leaveRepositoryProvider.overrideWithValue(repo)]);
      addTearDown(container.dispose);
    });

    test('carga más páginas, guarda el error y reintenta', () async {
      final sub = container.listen(leaveRequestsProvider, (_, _) {});
      addTearDown(sub.close);

      final first = await container.read(leaveRequestsProvider.future);
      expect(first.items, hasLength(2));
      expect(first.hasMore, isTrue);

      final controller = container.read(leaveRequestsProvider.notifier);
      await controller.loadMore();
      var state = container.read(leaveRequestsProvider).value!;
      expect(state.items, hasLength(2));
      expect(state.loadMoreError, isA<ApiException>());

      // Tras un error el scroll no reintenta solo; el botón sí
      await controller.loadMore();
      expect(repo.requested, [1, 2]);
      await controller.loadMore(retry: true);
      await controller.loadMore();
      state = container.read(leaveRequestsProvider).value!;
      expect(state.items.map((r) => r.id), [11, 12, 21, 22, 31, 32]);
      expect(state.hasMore, isFalse);
      expect(state.loadMoreError, isNull);

      await controller.loadMore();
      expect(repo.requested, [1, 2, 2, 3]);
    });
  });

  group('Paged.fromJson', () {
    test('sin last_page lo calcula con total y 20 por página (LeaveRequestController)', () {
      final page = Paged.fromJson({
        'data': [for (var i = 1; i <= 20; i++) _leave(i)],
        'meta': {'total': 45, 'current_page': 1},
      }, LeaveRequest.fromJson);
      expect(page.items, hasLength(20));
      expect(page.lastPage, 3);
      expect(page.hasMore, isTrue);
    });

    test('con last_page lo respeta y append acumula', () {
      final first = Paged.fromJson({
        'data': [_leave(1)],
        'meta': {'total': 2, 'current_page': 1, 'last_page': 2},
      }, LeaveRequest.fromJson, perPage: 1);
      final second = Paged.fromJson({
        'data': [_leave(2)],
        'meta': {'total': 2, 'current_page': 2, 'last_page': 2},
      }, LeaveRequest.fromJson, perPage: 1);
      final all = first.append(second);
      expect(all.items.map((r) => r.id), [1, 2]);
      expect(all.page, 2);
      expect(all.hasMore, isFalse);
    });

    test('una página vacía corta la paginación', () {
      final page = Paged.fromJson({
        'data': <Object>[],
        'meta': {'total': 40, 'current_page': 3},
      }, LeaveRequest.fromJson);
      expect(page.hasMore, isFalse);
    });

    test('sin meta: una sola página', () {
      final page = Paged.fromJson({'data': [_leave(1)]}, LeaveRequest.fromJson);
      expect(page.page, 1);
      expect(page.total, 1);
      expect(page.hasMore, isFalse);
    });
  });

  group('AppUser permisos', () {
    Map<String, dynamic> user(String role, [Object? permissions]) => {
          'id': 1,
          'full_name': 'Ana Pérez',
          'first_name': 'Ana',
          'email': 'ana@demo.pe',
          'role': role,
          'permissions': ?permissions,
        };

    test('lee los flags can_* de UserResource', () {
      final u = AppUser.fromJson(user('supervisor', {'can_approve_requests': true, 'can_view_dashboard': true}));
      expect(u.canApproveRequests, isTrue);
      expect(u.canViewTeam, isTrue);
      expect(u.hasTeamArea, isTrue);
    });

    test('un colaborador no ve el área de equipo', () {
      final u = AppUser.fromJson(user('employee', {'can_approve_requests': false}));
      expect(u.canApproveRequests, isFalse);
      expect(u.canViewTeam, isFalse);
      expect(u.hasTeamArea, isFalse);
    });

    test('acepta permisos como lista de slugs', () {
      final u = AppUser.fromJson(user('custom', ['leave.approve_team']));
      expect(u.canApproveRequests, isTrue);
      expect(u.canViewTeam, isFalse);
    });

    test('sesión guardada sin permisos: decide por rol y sobrevive a toJson', () {
      final legacy = AppUser.fromJson(user('hr_manager'));
      expect(legacy.canApproveRequests, isTrue);
      final roundTrip = AppUser.fromJson(
        AppUser.fromJson(user('employee', {'can_approve_requests': true})).toJson(),
      );
      expect(roundTrip.canApproveRequests, isTrue);
      expect(roundTrip.copyWith(mustChangePassword: true).canApproveRequests, isTrue);
    });
  });

  group('Aprobaciones y equipo', () {
    test('ApprovalRequest lee la solicitud y el colaborador', () {
      final a = ApprovalRequest.fromJson({
        ..._leave(9),
        'created_at': '2026-10-01T10:00:00-05:00',
        'employee': {'id': 4, 'name': 'Luis Rojas', 'employee_code': 'EMP-004'},
      });
      expect(a.id, 9);
      expect(a.employeeName, 'Luis Rojas');
      expect(a.employeeCode, 'EMP-004');
      expect(a.request.typeLabel, 'Vacaciones');
      expect(a.createdAt, isNotNull);
    });

    test('TeamOverview lee summary, attendances y el equipo', () {
      final overview = TeamOverview.fromJson({
        'success': true,
        'server_time': '2026-10-09T09:15:00-05:00',
        'summary': {'team_size': 3, 'present': 2, 'late': 1, 'on_break': 0, 'clocked_out': 0, 'absent': 1},
        'attendances': [
          {
            'id': 1,
            'work_date': '2026-10-09',
            'status': 'late',
            'status_label': 'Tardanza',
            'is_on_break': false,
            'clock_in': {'time': '08:20:00', 'timestamp': '2026-10-09T08:20:00-05:00', 'within_geofence': false},
            'break': {'start_time': null, 'end_time': null},
            'summary': {'late_minutes': 20},
            'branch': {'id': 1, 'name': 'Sede Central'},
          },
          {
            'id': 2,
            'work_date': '2026-10-09',
            'status': 'present',
            'clock_in': {'time': '07:58:00', 'timestamp': '2026-10-09T07:58:00-05:00', 'within_geofence': true},
            'summary': {'late_minutes': 0},
          },
        ],
      }, {
        'data': [
          {'id': 4, 'full_name': 'Luis Rojas', 'employee_code': 'EMP-004'},
        ],
      });

      expect(overview.summary.teamSize, 3);
      expect(overview.summary.absent, 1);
      expect(overview.attendances, hasLength(2));
      expect(overview.attendances.first.isLate, isTrue);
      expect(overview.attendances.first.outOfGeofence, isTrue);
      expect(overview.attendances.last.outOfGeofence, isFalse);
      expect(overview.members.single.fullName, 'Luis Rojas');
      // AttendanceResource no trae al colaborador: no se cruza con el equipo
      expect(overview.canMatchMembers, isFalse);
    });

    test('si el backend envía user, se cruza con el equipo', () {
      final overview = TeamOverview.fromJson({
        'summary': {'team_size': 1},
        'attendances': [
          {
            'id': 1,
            'work_date': '2026-10-09',
            'status': 'present',
            'user': {'id': 4, 'full_name': 'Luis Rojas'},
          },
        ],
      }, {
        'data': [
          {'id': 4, 'full_name': 'Luis Rojas'},
        ],
      });
      expect(overview.canMatchMembers, isTrue);
      expect(overview.attendanceOf(4)?.userName, 'Luis Rojas');
      expect(overview.attendanceOf(5), isNull);
    });
  });

  group('Notificaciones', () {
    final json = {
      'success': true,
      'data': [
        {
          'id': '9b1c2d3e-0000-4000-8000-000000000001',
          'type': 'leave_request.approved',
          'title': 'Solicitud aprobada',
          'body': 'Tus vacaciones del 02/11 fueron aprobadas.',
          'data': {'leave_request_id': 12},
          'read_at': null,
          'created_at': '2026-10-09T08:00:00-05:00',
        },
        {
          'id': '9b1c2d3e-0000-4000-8000-000000000002',
          'type': 'payslip.published',
          'title': 'Nueva boleta',
          'body': 'Ya está tu boleta de setiembre.',
          'data': {'payslip_id': 3},
          'read_at': '2026-10-08T10:00:00-05:00',
          'created_at': '2026-10-08T09:00:00-05:00',
        },
      ],
      'meta': {'current_page': 1, 'last_page': 3, 'total': 45, 'unread_count': 4},
    };

    test('lee la página y el contador de no leídas', () {
      final page = Paged.fromJson(json, AppNotification.fromJson);
      expect(page.items, hasLength(2));
      expect(page.items.first.isRead, isFalse);
      expect(page.items.last.isRead, isTrue);
      expect(page.items.first.data['leave_request_id'], 12);
      expect(page.hasMore, isTrue);
      expect(NotificationRepository.unreadOf(page), 4);
    });

    test('sin unread_count cuenta las no leídas de la página', () {
      final page = Paged.fromJson({'data': json['data']}, AppNotification.fromJson);
      expect(NotificationRepository.unreadOf(page), 1);
    });

    test('markedRead conserva los datos y marca la fecha', () {
      final n = AppNotification.fromJson(Map<String, dynamic>.from((json['data'] as List).first as Map));
      final read = n.markedRead();
      expect(read.isRead, isTrue);
      expect(read.id, n.id);
      expect(read.title, n.title);
    });

    test('navega según el tipo', () {
      expect(AppNotification.routeFor('payslip.published'), '/payslips');
      expect(AppNotification.routeFor('leave_request.approved'), '/requests');
      expect(AppNotification.routeFor('leave_request.rejected'), '/requests');
      expect(AppNotification.routeFor('leave_request.submitted'), '/approvals');
      expect(AppNotification.routeFor('document.rejected'), '/profile/documents');
      expect(AppNotification.routeFor('system.announcement'), isNull);
      expect(AppNotification.routeFor('', {'type': 'payslip'}), '/payslips');
    });
  });

  group('ErrorView', () {
    testWidgets('un 403 muestra "No tienes permiso" sin reintentar', (tester) async {
      await tester.pumpWidget(MaterialApp(
        home: Scaffold(
          body: ErrorView(
            error: const ApiException('No tienes permisos suficientes para esta acción.',
                statusCode: 403, code: 'INSUFFICIENT_PERMISSION'),
            onRetry: () {},
          ),
        ),
      ));
      expect(find.text(ErrorView.forbiddenMessage), findsOneWidget);
      expect(find.text('Reintentar'), findsNothing);
    });

    testWidgets('sin conexión mantiene el mensaje y el reintento', (tester) async {
      await tester.pumpWidget(MaterialApp(
        home: Scaffold(
          body: ErrorView(error: const ApiException('Sin conexión con el servidor.', code: 'NETWORK'), onRetry: () {}),
        ),
      ));
      expect(find.text('Sin conexión con el servidor.'), findsOneWidget);
      expect(find.text('Reintentar'), findsOneWidget);
    });
  });
}
