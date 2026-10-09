import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../features/attendance/domain/models.dart';
import '../../features/attendance/presentation/clock_screen.dart';
import '../../features/attendance/presentation/history_screen.dart';
import '../../features/auth/presentation/auth_controller.dart';
import '../../features/auth/presentation/login_screen.dart';
import '../../features/auth/presentation/tenant_screen.dart';
import '../../features/home/presentation/home_screen.dart';
import '../../features/payslips/presentation/payslip_viewer_screen.dart';
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
      final status = ref.read(authControllerProvider).status;
      final loc = state.matchedLocation;

      return switch (status) {
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
        ],
      ),
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

/// Navegación inferior + aviso de sin conexión.
class _AppShell extends ConsumerWidget {
  const _AppShell({required this.shell});

  final StatefulNavigationShell shell;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final online = ref.watch(connectivityProvider).value ?? true;

    return Scaffold(
      body: Column(children: [
        if (!online) const OfflineBanner(),
        Expanded(child: shell),
      ]),
      bottomNavigationBar: NavigationBar(
        selectedIndex: shell.currentIndex,
        onDestinationSelected: (i) => shell.goBranch(i, initialLocation: i == shell.currentIndex),
        destinations: const [
          NavigationDestination(icon: Icon(Icons.home_outlined), selectedIcon: Icon(Icons.home), label: 'Inicio'),
          NavigationDestination(icon: Icon(Icons.history), label: 'Asistencia'),
          NavigationDestination(icon: Icon(Icons.event_note_outlined), selectedIcon: Icon(Icons.event_note), label: 'Solicitudes'),
          NavigationDestination(icon: Icon(Icons.receipt_long_outlined), selectedIcon: Icon(Icons.receipt_long), label: 'Boletas'),
        ],
      ),
    );
  }
}
