import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../features/attendance/domain/models.dart';
import '../../features/attendance/presentation/clock_screen.dart';
import '../../features/attendance/presentation/history_screen.dart';
import '../../features/auth/presentation/auth_controller.dart';
import '../../features/auth/presentation/change_password_screen.dart';
import '../../features/auth/presentation/login_screen.dart';
import '../../features/auth/presentation/tenant_screen.dart';
import '../../features/home/presentation/home_screen.dart';
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
import '../providers.dart';
import '../widgets/async_views.dart';

final _rootKey = GlobalKey<NavigatorState>();

/// Rutas de la app. Redirige según el estado de sesión:
/// sin empresa → /tenant, sin sesión → /login, con sesión → /home.
final routerProvider = Provider<GoRouter>((ref) {
  final refresh = ValueNotifier<int>(0);
  ref.listen(authControllerProvider, (_, _) => refresh.value++);
  ref.onDispose(refresh.dispose);

  return GoRouter(
    navigatorKey: _rootKey,
    initialLocation: '/home',
    refreshListenable: refresh,
    redirect: (context, state) {
      final auth = ref.read(authControllerProvider);
      final loc = state.matchedLocation;

      // Contraseña temporal entregada por RRHH: primero debe cambiarla
      if (auth.status == AuthStatus.authenticated && (auth.user?.mustChangePassword ?? false)) {
        return loc == '/change-password' ? null : '/change-password';
      }

      return switch (auth.status) {
        AuthStatus.needsTenant => loc == '/tenant' ? null : '/tenant',
        AuthStatus.unauthenticated => loc == '/login' ? null : '/login',
        AuthStatus.authenticated => (loc == '/login' || loc == '/tenant') ? '/home' : null,
      };
    },
    routes: [
      GoRoute(path: '/tenant', builder: (_, _) => const TenantScreen()),
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
      GoRoute(
        parentNavigatorKey: _rootKey,
        path: '/clock/:action',
        redirect: (_, state) => ClockAction.fromApi(state.pathParameters['action']) == null ? '/home' : null,
        builder: (_, state) => ClockScreen(action: ClockAction.fromApi(state.pathParameters['action'])!),
      ),
      GoRoute(
        parentNavigatorKey: _rootKey,
        path: '/requests/new',
        builder: (_, _) => const NewRequestScreen(),
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
    final config = ref.watch(companyConfigProvider).value ?? const CompanyConfig();

    // Índices de rama visibles, en orden
    final branches = [
      for (var i = 0; i < _destinations.length; i++)
        if (i != _payslipsBranch || config.payslips) i,
    ];
    final selected = branches.indexOf(shell.currentIndex);

    return Scaffold(
      body: Column(children: [
        if (!online) const OfflineBanner(),
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
