import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/config/app_config_controller.dart';
import '../../../core/utils/formatters.dart';
import '../../../core/widgets/brand_logo.dart';
import '../../../core/widgets/async_views.dart';
import '../../../core/widgets/status_chip.dart';
import '../../attendance/domain/models.dart';
import '../../attendance/presentation/attendance_providers.dart';
import '../../attendance/presentation/offline_sync.dart';
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
    final appName = ref.watch(appConfigProvider.select((s) => s.config.tenant?.displayName)) ??
        auth.branding?.companyName ??
        'TuneSA';

    return Scaffold(
      appBar: AppBar(
        title: Row(children: [
          const BrandLogo(height: 32),
          const SizedBox(width: 10),
          Flexible(child: Text(appName, overflow: TextOverflow.ellipsis)),
        ]),
        actions: [
          const NotificationBell(),
          IconButton(
            tooltip: 'Mi perfil',
            icon: const Icon(Icons.account_circle_outlined),
            onPressed: () => context.go('/profile'),
          ),
        ],
      ),
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
                  _AttendanceCard(attendance: status.attendance),
                  const SizedBox(height: 16),
                  ..._actionButtons(context, status),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  List<Widget> _actionButtons(BuildContext context, TodayStatus status) {
    if (status.isDayComplete) {
      return const [
        Card(
          child: ListTile(
            leading: Icon(Icons.task_alt, color: Colors.green),
            title: Text('Jornada completa'),
            subtitle: Text('Ya registraste tu salida de hoy.'),
          ),
        ),
      ];
    }
    return [
      for (final action in status.actions) ...[
        _ClockButton(action: action, onTap: () => context.push('/clock/${action.apiName}')),
        const SizedBox(height: 12),
      ],
    ];
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
        borderRadius: BorderRadius.circular(20),
        gradient: LinearGradient(
          colors: [scheme.primary, Color.lerp(scheme.primary, scheme.tertiary, 0.55)!],
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

class _ClockButton extends StatelessWidget {
  const _ClockButton({required this.action, required this.onTap});

  final ClockAction action;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final icon = switch (action) {
      ClockAction.clockIn => Icons.login,
      ClockAction.breakStart => Icons.free_breakfast_outlined,
      ClockAction.breakEnd => Icons.play_arrow_rounded,
      ClockAction.clockOut => Icons.logout,
    };
    final secondary = action == ClockAction.breakStart || action == ClockAction.breakEnd;

    return secondary
        ? OutlinedButton.icon(
            style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(52)),
            onPressed: onTap,
            icon: Icon(icon),
            label: Text(action.label),
          )
        : FilledButton.icon(onPressed: onTap, icon: Icon(icon), label: Text(action.label));
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

class _AttendanceCard extends StatelessWidget {
  const _AttendanceCard({required this.attendance});

  final Attendance? attendance;

  @override
  Widget build(BuildContext context) {
    final a = attendance;
    if (a == null) {
      return const Card(
        child: ListTile(
          leading: Icon(Icons.hourglass_empty, size: 32),
          title: Text('Aún no marcas tu entrada'),
        ),
      );
    }

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Text('Tu jornada', style: Theme.of(context).textTheme.titleMedium),
            const Spacer(),
            StatusChip(status: a.status, label: a.isOnBreak ? 'En descanso' : a.statusLabel),
          ]),
          const SizedBox(height: 12),
          Row(children: [
            _TimeCell(label: 'Entrada', value: Fmt.time(a.clockIn)),
            _TimeCell(label: 'Descanso', value: a.breakStart == null ? '--:--' : '${a.breakStart}–${a.breakEnd ?? '…'}'),
            _TimeCell(label: 'Salida', value: Fmt.time(a.clockOut)),
          ]),
          if (a.lateMinutes > 0) ...[
            const SizedBox(height: 8),
            Text('Tardanza: ${a.lateMinutes} min', style: TextStyle(color: Theme.of(context).colorScheme.error)),
          ],
        ]),
      ),
    );
  }
}

class _TimeCell extends StatelessWidget {
  const _TimeCell({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) => Expanded(
        child: Column(children: [
          Text(label, style: Theme.of(context).textTheme.labelMedium),
          const SizedBox(height: 4),
          Text(value, style: Theme.of(context).textTheme.titleMedium),
        ]),
      );
}
