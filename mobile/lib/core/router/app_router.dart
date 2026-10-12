import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../features/account/presentation/devices_screen.dart';
import '../../features/account/presentation/notification_settings_screen.dart';
import '../../features/account/presentation/privacy_screen.dart';
import '../../features/app_update/presentation/update_screen.dart';
import '../../features/training/presentation/certificate_screen.dart';
import '../../features/training/presentation/lesson_screen.dart';
import '../../features/training/presentation/quiz_screen.dart';
import '../../features/training/presentation/training_detail_screen.dart';
import '../../features/training/presentation/trainings_screen.dart';
import '../../features/attendance/domain/models.dart';
import '../../features/attendance/presentation/clock_screen.dart';
import '../../features/attendance/presentation/history_screen.dart';
import '../../features/attendance/presentation/offline_sync.dart';
import '../../features/attendance/presentation/rejected_punches_screen.dart';
import '../../features/auth/presentation/auth_controller.dart';
import '../../features/auth/presentation/change_password_screen.dart';
import '../../features/auth/presentation/login_screen.dart';
import '../../features/home/presentation/home_screen.dart';
import '../../features/notifications/presentation/notifications_screen.dart';
import '../../features/ethics/domain/models.dart';
import '../../features/ethics/presentation/ethics_case_screen.dart';
import '../../features/ethics/presentation/ethics_home_screen.dart';
import '../../features/ethics/presentation/ethics_report_screen.dart';
import '../../features/onboarding/presentation/onboarding_screen.dart';
import '../../features/payslips/presentation/payslip_viewer_screen.dart';
import '../../features/profile/domain/models.dart';
import '../../features/profile/presentation/contact_screen.dart';
import '../../features/profile/presentation/document_viewer_screen.dart';
import '../../features/profile/presentation/documents_screen.dart';
import '../../features/profile/presentation/payment_info_screen.dart';
import '../../features/profile/presentation/profile_providers.dart';
import '../../features/profile/presentation/profile_screen.dart';
import '../../features/profile/presentation/uniforms_screen.dart';
import '../../features/payslips/presentation/payslips_screen.dart';
import '../../features/requests/presentation/new_request_screen.dart';
import '../../features/requests/presentation/requests_screen.dart';
import '../../features/team/presentation/approvals_screen.dart';
import '../../features/splash/splash_screen.dart';
import '../../features/team/presentation/team_live_screen.dart';
import '../config/app_config_controller.dart';
import '../config/version_gate.dart';
import '../providers.dart';
import '../push/push_controller.dart';
import '../widgets/async_views.dart';

final _rootKey = GlobalKey<NavigatorState>();

/// Rutas de la app. Redirige según el estado de sesión:
/// sin configuración → /splash, versión no soportada → /update,
/// sin sesión (con o sin empresa conocida) → /login, con sesión → /home.
final routerProvider = Provider<GoRouter>((ref) {
  final refresh = ValueNotifier<int>(0);
  ref.listen(authControllerProvider, (_, _) => refresh.value++);
  ref.listen(appConfigProvider.select((s) => s.ready), (_, _) => refresh.value++);
  ref.listen(updateLevelProvider, (_, _) => refresh.value++);
  ref.onDispose(refresh.dispose);

  return GoRouter(
    navigatorKey: _rootKey,
    initialLocation: '/home',
    refreshListenable: refresh,
    redirect: (context, state) {
      final auth = ref.read(authControllerProvider);
      final loc = state.matchedLocation;

      // Primer arranque sin configuración en caché: splash hasta tenerla
      if (!ref.read(appConfigProvider).ready) return loc == '/splash' ? null : '/splash';

      // Versión no soportada (force_update o 426): pantalla bloqueante
      if (ref.read(updateLevelProvider) == UpdateLevel.required) return loc == '/update' ? null : '/update';

      // Contraseña temporal entregada por RRHH: primero debe cambiarla
      if (auth.status == AuthStatus.authenticated && (auth.user?.mustChangePassword ?? false)) {
        return loc == '/change-password' ? null : '/change-password';
      }
      // Primer ingreso: quien aún no está aprobado por RRHH va a su incorporación
      // (documentos, datos y cursos), no al inicio de marcación
      final landing = (auth.user?.onboardingStatus ?? 'approved') == 'approved' ? '/home' : '/onboarding';

      // Fuera de ese caso la app no cambia contraseñas: lo hace RRHH desde el panel.
      // Recién cambiada la temporal, sigue el primer ingreso.
      if (loc == '/change-password') return auth.status == AuthStatus.authenticated ? landing : '/login';

      return switch (auth.status) {
        // Sin empresa conocida también va al login: el servidor la reconoce por el correo
        AuthStatus.needsTenant || AuthStatus.unauthenticated => loc == '/login' ? null : '/login',
        AuthStatus.authenticated => const {'/login', '/splash', '/update'}.contains(loc) ? landing : null,
      };
    },
    routes: [
      GoRoute(path: '/splash', builder: (_, _) => const SplashScreen()),
      GoRoute(path: '/update', builder: (_, _) => const UpdateScreen()),
      GoRoute(path: '/login', builder: (_, _) => const LoginScreen()),
      StatefulShellRoute.indexedStack(
        builder: (_, _, shell) => _AppShell(shell: shell),
        branches: [
          StatefulShellBranch(routes: [GoRoute(path: '/home', builder: (_, _) => const HomeScreen())]),
          StatefulShellBranch(routes: [GoRoute(path: '/history', builder: (_, _) => const HistoryScreen())]),
          StatefulShellBranch(routes: [GoRoute(path: '/requests', builder: (_, _) => const RequestsScreen())]),
          StatefulShellBranch(routes: [GoRoute(path: '/payslips', builder: (_, _) => const PayslipsScreen())]),
          StatefulShellBranch(routes: [GoRoute(path: '/profile', builder: (_, _) => const ProfileScreen())]),
        ],
      ),
      GoRoute(
        parentNavigatorKey: _rootKey,
        path: '/change-password',
        builder: (_, _) => const ChangePasswordScreen(),
      ),
      GoRoute(parentNavigatorKey: _rootKey, path: '/profile/documents', builder: (_, _) => const DocumentsScreen()),
      GoRoute(
        parentNavigatorKey: _rootKey,
        path: '/profile/documents/:id',
        builder: (_, state) => DocumentViewerScreen(
          id: int.parse(state.pathParameters['id']!),
          document: state.extra as EmployeeDocument?,
        ),
      ),
      GoRoute(parentNavigatorKey: _rootKey, path: '/profile/payment', builder: (_, _) => const PaymentInfoScreen()),
      GoRoute(parentNavigatorKey: _rootKey, path: '/profile/uniforms', builder: (_, _) => const UniformsScreen()),
      GoRoute(parentNavigatorKey: _rootKey, path: '/profile/contact', builder: (_, _) => const ContactScreen()),
      GoRoute(parentNavigatorKey: _rootKey, path: '/profile/devices', builder: (_, _) => const DevicesScreen()),
      GoRoute(parentNavigatorKey: _rootKey, path: '/profile/notifications', builder: (_, _) => const NotificationSettingsScreen()),
      GoRoute(parentNavigatorKey: _rootKey, path: '/profile/privacy', builder: (_, _) => const PrivacyScreen()),
      GoRoute(
        parentNavigatorKey: _rootKey,
        path: '/clock/:action',
        redirect: (_, state) => ClockAction.fromApi(state.pathParameters['action']) == null ? '/home' : null,
        builder: (_, state) => ClockScreen(action: ClockAction.fromApi(state.pathParameters['action'])!),
      ),
      GoRoute(
        parentNavigatorKey: _rootKey,
        path: '/attendance/rejected',
        builder: (_, _) => const RejectedPunchesScreen(),
      ),
      GoRoute(
        parentNavigatorKey: _rootKey,
        path: '/requests/new',
        builder: (_, _) => const NewRequestScreen(),
      ),
      GoRoute(parentNavigatorKey: _rootKey, path: '/notifications', builder: (_, _) => const NotificationsScreen()),
      GoRoute(parentNavigatorKey: _rootKey, path: '/onboarding', builder: (_, _) => const OnboardingScreen()),
      GoRoute(parentNavigatorKey: _rootKey, path: '/ethics', builder: (_, _) => const EthicsHomeScreen()),
      GoRoute(parentNavigatorKey: _rootKey, path: '/ethics/new', builder: (_, _) => const EthicsReportScreen()),
      GoRoute(
        parentNavigatorKey: _rootKey,
        path: '/ethics/created',
        redirect: (_, state) => state.extra is ({SavedEthicsCase saved, bool kept}) ? null : '/ethics',
        builder: (_, state) {
          final args = state.extra! as ({SavedEthicsCase saved, bool kept});
          return EthicsCreatedScreen(saved: args.saved, kept: args.kept);
        },
      ),
      GoRoute(
        parentNavigatorKey: _rootKey,
        path: '/ethics/case',
        redirect: (_, state) => state.extra is SavedEthicsCase ? null : '/ethics',
        builder: (_, state) => EthicsCaseScreen(saved: state.extra! as SavedEthicsCase),
      ),
      // Área de supervisor / RRHH: solo con permiso (el backend igual responde 403)
      GoRoute(
        parentNavigatorKey: _rootKey,
        path: '/approvals',
        redirect: (_, _) => (ref.read(authControllerProvider).user?.canApproveRequests ?? false) ? null : '/home',
        builder: (_, _) => const ApprovalsScreen(),
      ),
      GoRoute(
        parentNavigatorKey: _rootKey,
        path: '/team',
        redirect: (_, _) => (ref.read(authControllerProvider).user?.canViewTeam ?? false) ? null : '/home',
        builder: (_, _) => const TeamLiveScreen(),
      ),
      // Capacitaciones
      GoRoute(parentNavigatorKey: _rootKey, path: '/trainings', builder: (_, _) => const TrainingsScreen()),
      GoRoute(
        parentNavigatorKey: _rootKey,
        path: '/trainings/:id',
        builder: (_, state) => TrainingDetailScreen(id: int.parse(state.pathParameters['id']!)),
      ),
      GoRoute(
        parentNavigatorKey: _rootKey,
        path: '/trainings/:id/lessons/:lesson',
        builder: (_, state) => LessonScreen(
          courseId: int.parse(state.pathParameters['id']!),
          lessonId: int.parse(state.pathParameters['lesson']!),
        ),
      ),
      GoRoute(
        parentNavigatorKey: _rootKey,
        path: '/trainings/:id/quiz',
        builder: (_, state) => QuizScreen(courseId: int.parse(state.pathParameters['id']!)),
      ),
      GoRoute(
        parentNavigatorKey: _rootKey,
        path: '/trainings/:id/certificate',
        builder: (_, state) => CertificateScreen(courseId: int.parse(state.pathParameters['id']!)),
      ),
      GoRoute(
        parentNavigatorKey: _rootKey,
        path: '/payslips/:id',
        builder: (_, state) => PayslipViewerScreen(
          id: int.parse(state.pathParameters['id']!),
          title: state.extra as String?,
        ),
      ),
    ],
  );
});

/// Navegación inferior + aviso de sin conexión. La pestaña Boletas se oculta
/// si la empresa la desactivó (flags de /api/employee/config).
class _AppShell extends ConsumerWidget {
  const _AppShell({required this.shell});

  final StatefulNavigationShell shell;

  static const _payslipsBranch = 3;

  static const _destinations = [
    NavigationDestination(icon: Icon(Icons.home_outlined), selectedIcon: Icon(Icons.home_rounded), label: 'Inicio'),
    NavigationDestination(icon: Icon(Icons.history_rounded), label: 'Asistencia'),
    NavigationDestination(icon: Icon(Icons.event_note_outlined), selectedIcon: Icon(Icons.event_note_rounded), label: 'Solicitudes'),
    NavigationDestination(icon: Icon(Icons.receipt_long_outlined), selectedIcon: Icon(Icons.receipt_long_rounded), label: 'Boletas'),
    NavigationDestination(icon: Icon(Icons.person_outline_rounded), selectedIcon: Icon(Icons.person_rounded), label: 'Mi perfil'),
  ];

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final online = ref.watch(connectivityProvider).value ?? true;
    final offlineClocking = ref.watch(appConfigProvider.select((s) => s.config.mobile.offlineModeEnabled));
    final config = ref.watch(companyConfigProvider).value ?? const CompanyConfig();

    // Índices de rama visibles, en orden
    final branches = [
      for (var i = 0; i < _destinations.length; i++)
        if (i != _payslipsBranch || config.payslips) i,
    ];
    final selected = branches.indexOf(shell.currentIndex);

    return Scaffold(
      body: Column(children: [
        if (!online) OfflineBanner(offlineClocking: offlineClocking),
        const UpdateBanner(),
        const OfflineSyncListener(),
        const PushListener(),
        Expanded(child: shell),
      ]),
      bottomNavigationBar: NavigationBar(
        selectedIndex: selected < 0 ? 0 : selected,
        onDestinationSelected: (i) {
          final branch = branches[i];
          shell.goBranch(branch, initialLocation: branch == shell.currentIndex);
        },
        destinations: [for (final b in branches) _destinations[b]],
      ),
    );
  }
}
