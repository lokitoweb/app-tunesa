import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/utils/formatters.dart';
import '../../../core/widgets/async_views.dart';
import '../../../core/widgets/status_chip.dart';
import '../../attendance/domain/models.dart';
import '../../attendance/presentation/attendance_providers.dart';
import '../../auth/presentation/auth_controller.dart';

/// Inicio del colaborador: turno de hoy, estado de la jornada y botones de marcación.
class HomeScreen extends ConsumerWidget {
  const HomeScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final auth = ref.watch(authControllerProvider);
    final today = ref.watch(todayStatusProvider);

    return Scaffold(
      appBar: AppBar(
        title: Text(auth.branding?.companyName ?? 'TuneSA'),
        actions: [
          PopupMenuButton<String>(
            icon: const Icon(Icons.account_circle_outlined),
            onSelected: (value) async {
              if (value != 'logout') return;
              final ok = await showDialog<bool>(
                context: context,
                builder: (c) => AlertDialog(
                  title: const Text('Cerrar sesión'),
                  content: const Text('¿Seguro que quieres salir?'),
                  actions: [
                    TextButton(onPressed: () => Navigator.pop(c, false), child: const Text('Cancelar')),
                    FilledButton(onPressed: () => Navigator.pop(c, true), child: const Text('Salir')),
                  ],
                ),
              );
              if (ok == true) await ref.read(authControllerProvider.notifier).logout();
            },
            itemBuilder: (_) => [
              PopupMenuItem(
                enabled: false,
                child: Text('${auth.user?.fullName ?? ''}\n${auth.user?.email ?? ''}'),
              ),
              const PopupMenuItem(value: 'logout', child: Text('Cerrar sesión')),
            ],
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: () => ref.refresh(todayStatusProvider.future),
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            Text('Hola, ${auth.user?.firstName ?? ''}', style: Theme.of(context).textTheme.headlineSmall),
            Text(Fmt.dayLong(DateTime.now())),
            const SizedBox(height: 16),
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
