import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/utils/formatters.dart';
import '../../../core/theme/app_theme.dart';
import '../../../core/widgets/async_views.dart';
import '../../attendance/domain/models.dart';
import '../../attendance/presentation/attendance_providers.dart';
import '../../attendance/presentation/offline_sync.dart';
import '../../attendance/presentation/punch_board.dart';
import '../../auth/presentation/auth_controller.dart';
import '../../notifications/presentation/notifications_providers.dart';
import '../../notifications/presentation/notifications_screen.dart';
import '../../profile/presentation/profile_providers.dart';
import '../../team/presentation/team_providers.dart';

/// Inicio del colaborador: turno de hoy, estado de la jornada y botones de marcación.
/// Para supervisores / RRHH agrega la sección "Mi equipo".
class HomeScreen extends ConsumerStatefulWidget {
  const HomeScreen({super.key});

  @override
  ConsumerState<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends ConsumerState<HomeScreen> {
  late final AppLifecycleListener _lifecycle;

  @override
  void initState() {
    super.initState();
    // Al volver a la app se actualizan los avisos (sin servicio en segundo plano)
    _lifecycle = AppLifecycleListener(onResume: _refreshBadges);
  }

  @override
  void dispose() {
    _lifecycle.dispose();
    super.dispose();
  }

  void _refreshBadges() {
    ref.invalidate(unreadCountProvider);
    ref.invalidate(pendingApprovalsCountProvider);
  }

  @override
  Widget build(BuildContext context) {
    final auth = ref.watch(authControllerProvider);
    final today = ref.watch(todayStatusProvider);
    final user = auth.user;

    return Scaffold(
      // La empresa y la sede se ven en "Mi perfil": aquí solo el título y los avisos
      appBar: AppBar(title: const Text('Inicio'), actions: const [NotificationBell(), SizedBox(width: 4)]),
      body: RefreshIndicator(
        onRefresh: () async {
          _refreshBadges();
          // Envía marcaciones guardadas sin conexión antes de recargar el día
          await ref.read(offlineSyncProvider.notifier).sync(force: true);
          return ref.refresh(todayStatusProvider.future);
        },
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            _Greeting(name: auth.user?.firstName ?? ''),
            const SizedBox(height: 16),
            const _OnboardingBanner(),
            if (user != null && user.hasTeamArea) ...[
              _TeamSection(canApprove: user.canApproveRequests, canViewTeam: user.canViewTeam),
              const SizedBox(height: 16),
            ],
            today.when(
              loading: () => const Padding(padding: EdgeInsets.all(48), child: LoadingView()),
              error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(todayStatusProvider)),
              data: (status) => Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  _ShiftCard(schedule: status.schedule),
                  const SizedBox(height: 12),
                  PunchBoard(status: status, onPunch: (action) => context.push('/clock/${action.apiName}')),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _Greeting extends StatelessWidget {
  const _Greeting({required this.name});

  final String name;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final hour = DateTime.now().hour;
    final greeting = hour < 12 ? 'Buenos días' : (hour < 19 ? 'Buenas tardes' : 'Buenas noches');

    return Container(
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(AppTheme.radius),
        gradient: LinearGradient(
          colors: [scheme.primary, Color.lerp(scheme.primary, scheme.secondary, 0.7)!],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text('$greeting, $name', style: TextStyle(color: scheme.onPrimary, fontSize: 22, fontWeight: FontWeight.w700)),
        const SizedBox(height: 4),
        Text(Fmt.dayLong(DateTime.now()), style: TextStyle(color: scheme.onPrimary.withValues(alpha: 0.85))),
      ]),
    );
  }
}

/// Accesos del supervisor / RRHH: bandeja de aprobaciones y equipo en vivo.
class _TeamSection extends ConsumerWidget {
  const _TeamSection({required this.canApprove, required this.canViewTeam});

  final bool canApprove;
  final bool canViewTeam;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final pending = canApprove ? (ref.watch(pendingApprovalsCountProvider).value ?? 0) : 0;

    return Card(
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
          child: Text('Mi equipo', style: Theme.of(context).textTheme.titleMedium),
        ),
        if (canApprove)
          ListTile(
            leading: const Icon(Icons.fact_check_outlined),
            title: const Text('Aprobaciones'),
            subtitle: Text(pending > 0 ? '$pending solicitud(es) por revisar' : 'Sin solicitudes pendientes'),
            trailing: Row(mainAxisSize: MainAxisSize.min, children: [
              if (pending > 0) Badge(label: Text(pending > 99 ? '99+' : '$pending')),
              const Icon(Icons.chevron_right_rounded),
            ]),
            onTap: () async {
              await context.push('/approvals');
              if (context.mounted) ref.invalidate(pendingApprovalsCountProvider);
            },
          ),
        if (canViewTeam)
          ListTile(
            leading: const Icon(Icons.groups_outlined),
            title: const Text('Equipo en vivo'),
            subtitle: const Text('Quién marcó, tardanzas y fuera de geocerca'),
            trailing: const Icon(Icons.chevron_right_rounded),
            onTap: () => context.push('/team'),
          ),
      ]),
    );
  }
}

/// Recordatorio para completar la ficha de ingreso (legajo, cuenta, tallas).
class _OnboardingBanner extends ConsumerWidget {
  const _OnboardingBanner();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final file = ref.watch(employeeFileProvider).value;
    if (file == null || !file.config.hasEmployeeFile || file.progress >= 100) return const SizedBox.shrink();

    return Padding(
      padding: const EdgeInsets.only(bottom: 16),
      child: Card(
        color: Theme.of(context).colorScheme.secondaryContainer,
        child: ListTile(
          leading: const Icon(Icons.assignment_ind_outlined),
          title: Text('Completa tu ficha (${file.progress}%)'),
          subtitle: const Text('Sube tus documentos, tu cuenta de pago y tus tallas.'),
          trailing: const Icon(Icons.chevron_right_rounded),
          onTap: () => context.go('/profile'),
        ),
      ),
    );
  }
}

class _ShiftCard extends StatelessWidget {
  const _ShiftCard({required this.schedule});

  final DaySchedule? schedule;

  @override
  Widget build(BuildContext context) {
    final s = schedule;
    final works = s != null && s.isWorkday;

    return Card(
      child: ListTile(
        leading: Icon(works ? Icons.schedule : Icons.weekend_outlined, size: 32),
        title: Text(works ? '${s.shiftName ?? 'Turno'} · ${s.start}–${s.end}' : 'Hoy no tienes turno programado'),
        subtitle: Text(works ? (s.branch ?? 'Sucursal asignada') : 'Día libre según tu horario.'),
      ),
    );
  }
}

