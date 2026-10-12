import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/utils/formatters.dart';
import '../../../core/widgets/async_views.dart';
import '../../../core/widgets/status_chip.dart';
import '../domain/models.dart';
import 'team_providers.dart';
import '../../../core/widgets/page_padding.dart';

/// Mi equipo hoy: quién marcó, tardanzas, descansos y marcaciones fuera de
/// la geocerca. Se actualiza deslizando hacia abajo.
class TeamLiveScreen extends ConsumerWidget {
  const TeamLiveScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final overview = ref.watch(teamOverviewProvider);

    return Scaffold(
      appBar: AppBar(
        title: const Text('Mi equipo hoy'),
        actions: [
          IconButton(
            tooltip: 'Actualizar',
            icon: const Icon(Icons.refresh_rounded),
            onPressed: () => ref.invalidate(teamOverviewProvider),
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: () => ref.refresh(teamOverviewProvider.future),
        child: overview.when(
          loading: () => const LoadingView(),
          error: (e, _) => ListView(children: [
            const SizedBox(height: 80),
            ErrorView(error: e, onRetry: () => ref.invalidate(teamOverviewProvider)),
          ]),
          data: (data) => _TeamBody(data: data),
        ),
      ),
    );
  }
}

class _TeamBody extends StatelessWidget {
  const _TeamBody({required this.data});

  final TeamOverview data;

  @override
  Widget build(BuildContext context) {
    final s = data.summary;
    final theme = Theme.of(context);

    if (s.teamSize == 0 && data.members.isEmpty) {
      return ListView(children: const [
        SizedBox(height: 80),
        EmptyView(
          icon: Icons.groups_outlined,
          message: 'No tienes colaboradores a cargo.\nEl equipo se arma con las sucursales que supervisas.',
        ),
      ]);
    }

    final matched = data.canMatchMembers;

    return ListView(
      padding: pagePadding(context),
      children: [
        if (data.serverTime != null)
          Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: Text('Actualizado a las ${Fmt.time(data.serverTime)}', style: theme.textTheme.bodySmall),
          ),
        Wrap(spacing: 8, runSpacing: 8, children: [
          _Stat(label: 'Equipo', value: s.teamSize, icon: Icons.groups_outlined),
          _Stat(label: 'Marcaron', value: s.present, icon: Icons.login_rounded, color: Colors.green),
          _Stat(label: 'Tardanzas', value: s.late, icon: Icons.schedule_rounded, color: Colors.orange),
          _Stat(label: 'En descanso', value: s.onBreak, icon: Icons.free_breakfast_outlined, color: Colors.blue),
          _Stat(label: 'Salieron', value: s.clockedOut, icon: Icons.logout_rounded),
          _Stat(label: 'Sin marcar', value: s.absent, icon: Icons.person_off_outlined, color: Colors.red),
        ]),
        const SizedBox(height: 20),
        if (matched) ...[
          Text('Colaboradores', style: theme.textTheme.titleMedium),
          const SizedBox(height: 8),
          for (final m in data.members) _MemberTile(member: m, attendance: data.attendanceOf(m.id)),
        ] else ...[
          Text('Marcaciones de hoy', style: theme.textTheme.titleMedium),
          const SizedBox(height: 8),
          if (data.attendances.isEmpty)
            const Card(child: ListTile(leading: Icon(Icons.hourglass_empty), title: Text('Nadie ha marcado todavía.'))),
          for (final a in data.attendances) _AttendanceTile(item: a),
          if (data.members.isNotEmpty) ...[
            const SizedBox(height: 20),
            Text('Mi equipo (${data.members.length})', style: theme.textTheme.titleMedium),
            const SizedBox(height: 8),
            for (final m in data.members)
              Card(
                child: ListTile(
                  leading: const Icon(Icons.person_outline_rounded),
                  title: Text(m.fullName),
                  subtitle: Text([m.employeeCode, m.position].whereType<String>().join(' · ')),
                ),
              ),
          ],
        ],
      ],
    );
  }
}

class _Stat extends StatelessWidget {
  const _Stat({required this.label, required this.value, required this.icon, this.color});

  final String label;
  final int value;
  final IconData icon;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final c = color ?? theme.colorScheme.primary;
    final width = (MediaQuery.sizeOf(context).width - 32 - 16) / 3;

    return SizedBox(
      width: width,
      child: Card(
        margin: EdgeInsets.zero,
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 8),
          child: Column(children: [
            Icon(icon, color: c, size: 22),
            const SizedBox(height: 4),
            Text('$value', style: theme.textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w700)),
            Text(label, style: theme.textTheme.labelSmall, textAlign: TextAlign.center, maxLines: 1, overflow: TextOverflow.ellipsis),
          ]),
        ),
      ),
    );
  }
}

/// Integrante con su estado de hoy (cuando el backend identifica al colaborador).
class _MemberTile extends StatelessWidget {
  const _MemberTile({required this.member, required this.attendance});

  final TeamMember member;
  final TeamAttendance? attendance;

  @override
  Widget build(BuildContext context) {
    final a = attendance;
    if (a == null) {
      return Card(
        child: ListTile(
          leading: const Icon(Icons.person_off_outlined),
          title: Text(member.fullName),
          subtitle: const Text('Sin marcación hoy'),
        ),
      );
    }
    return _AttendanceTile(item: a, name: member.fullName);
  }
}

class _AttendanceTile extends StatelessWidget {
  const _AttendanceTile({required this.item, this.name});

  final TeamAttendance item;
  final String? name;

  @override
  Widget build(BuildContext context) {
    final a = item.attendance;
    final theme = Theme.of(context);
    final title = name ?? item.userName ?? [a.branchName, a.shiftName].whereType<String>().join(' · ');
    final times = 'Entrada ${Fmt.time(a.clockIn)}${a.clockOut != null ? ' · Salida ${Fmt.time(a.clockOut)}' : ''}';

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Expanded(child: Text(title.isEmpty ? 'Colaborador' : title, style: theme.textTheme.titleSmall)),
            StatusChip(status: a.status, label: a.isOnBreak ? 'En descanso' : a.statusLabel),
          ]),
          const SizedBox(height: 4),
          Text(times),
          if ((name != null || item.userName != null) && a.branchName != null)
            Text(a.branchName!, style: theme.textTheme.bodySmall),
          if (item.isLate || item.outOfGeofence) ...[
            const SizedBox(height: 6),
            Wrap(spacing: 8, runSpacing: 4, children: [
              if (item.isLate)
                _Flag(icon: Icons.schedule_rounded, text: a.lateMinutes > 0 ? 'Tardanza ${a.lateMinutes} min' : 'Tardanza', color: Colors.orange),
              if (item.outOfGeofence)
                _Flag(icon: Icons.wrong_location_outlined, text: 'Fuera de geocerca', color: theme.colorScheme.error),
            ]),
          ],
        ]),
      ),
    );
  }
}

class _Flag extends StatelessWidget {
  const _Flag({required this.icon, required this.text, required this.color});

  final IconData icon;
  final String text;
  final Color color;

  @override
  Widget build(BuildContext context) => Row(mainAxisSize: MainAxisSize.min, children: [
        Icon(icon, size: 16, color: color),
        const SizedBox(width: 4),
        Text(text, style: TextStyle(color: color, fontSize: 12, fontWeight: FontWeight.w600)),
      ]);
}
