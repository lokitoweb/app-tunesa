import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';

import '../../../core/utils/formatters.dart';
import '../../../core/utils/list_filters.dart';
import '../../../core/widgets/async_views.dart';
import '../../../core/widgets/page_padding.dart';
import '../domain/models.dart';
import 'attendance_providers.dart';
import '../../onboarding/presentation/onboarding_gate_card.dart';
import '../../onboarding/presentation/onboarding_providers.dart';
import '../../../core/widgets/skeleton.dart';

/// Mis horas extra del mes: cada día con horas extra y su estado de
/// aprobación (por aprobar, aprobadas o no aprobadas por la jefatura).
/// Solo las aprobadas se pagan en la planilla.
class OvertimeScreen extends ConsumerStatefulWidget {
  const OvertimeScreen({super.key});

  @override
  ConsumerState<OvertimeScreen> createState() => _OvertimeScreenState();
}

class _OvertimeScreenState extends ConsumerState<OvertimeScreen> {
  // El mes elegido se conserva al navegar o minimizar la app (MonthFilter)
  DateTime get _month => ref.read(monthFilterProvider('overtime'));

  (int, int) get _period => (_month.month, _month.year);

  void _shift(int delta) => ref.read(monthFilterProvider('overtime').notifier).shift(delta);

  static String hours(double v) => '${v.toStringAsFixed(2).replaceFirst(RegExp(r'\.?0+$'), '')} h';

  @override
  Widget build(BuildContext context) {
    ref.watch(monthFilterProvider('overtime'));
    if (!ref.watch(canPunchProvider)) {
      return Scaffold(
        appBar: AppBar(title: const Text('Mis horas extra')),
        body: ListView(padding: pagePadding(context), children: const [OnboardingGateCard()]),
      );
    }
    final history = ref.watch(attendanceHistoryProvider(_period));
    final now = DateTime.now();
    final isCurrentMonth = _month.year == now.year && _month.month == now.month;
    final title = DateFormat('MMMM yyyy', 'es').format(_month);

    return Scaffold(
      appBar: AppBar(title: const Text('Mis horas extra')),
      body: Column(children: [
        Material(
          color: Theme.of(context).colorScheme.surface,
          child: Row(children: [
            IconButton(onPressed: () => _shift(-1), icon: const Icon(Icons.chevron_left)),
            Expanded(
              child: Text(title[0].toUpperCase() + title.substring(1),
                  textAlign: TextAlign.center, style: Theme.of(context).textTheme.titleMedium),
            ),
            IconButton(onPressed: isCurrentMonth ? null : () => _shift(1), icon: const Icon(Icons.chevron_right)),
          ]),
        ),
        Expanded(
          child: history.whenSkeleton(
            loading: () => const SkeletonList(header: true, trailing: true),
            error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(attendanceHistoryProvider(_period))),
            data: (data) {
              final days = data.items.where((a) => a.overtimeStatus != null).toList();
              double sum(String status) => days
                  .where((a) => a.overtimeStatus == status)
                  .fold(0.0, (t, a) => t + (status == 'approved' ? (a.overtimeApprovedHours ?? a.overtimeHours) : a.overtimeHours));

              return RefreshIndicator(
                onRefresh: () => ref.refresh(attendanceHistoryProvider(_period).future),
                child: ListView(
                  padding: pagePadding(context),
                  children: [
                    _OvertimeSummary(approved: sum('approved'), pending: sum('pending'), rejected: sum('rejected')),
                    const SizedBox(height: 8),
                    Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 4),
                      child: Text(
                        'Tu jefatura revisa las horas extra; solo las aprobadas se pagan en tu boleta.',
                        style: Theme.of(context).textTheme.bodySmall?.copyWith(color: Theme.of(context).colorScheme.onSurfaceVariant),
                      ),
                    ),
                    const SizedBox(height: 12),
                    if (days.isEmpty)
                      const EmptyView(icon: Icons.more_time_rounded, message: 'No tienes horas extra este mes.')
                    else
                      for (final a in days) _OvertimeTile(attendance: a),
                  ],
                ),
              );
            },
          ),
        ),
      ]),
    );
  }
}

class _OvertimeSummary extends StatelessWidget {
  const _OvertimeSummary({required this.approved, required this.pending, required this.rejected});

  final double approved;
  final double pending;
  final double rejected;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    Widget cell(String label, double value, Color color) => Expanded(
          child: Column(children: [
            Text(_OvertimeScreenState.hours(value), style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800, color: color)),
            const SizedBox(height: 2),
            Text(label, style: theme.textTheme.labelMedium?.copyWith(color: theme.colorScheme.onSurfaceVariant)),
          ]),
        );
    const divider = SizedBox(height: 32, child: VerticalDivider(width: 1));

    return Card(
      key: const Key('overtime-summary'),
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 14),
        child: Row(children: [
          cell('Aprobadas', approved, Colors.green.shade700),
          divider,
          cell('Por aprobar', pending, Colors.orange.shade800),
          divider,
          cell('No aprobadas', rejected, theme.colorScheme.error),
        ]),
      ),
    );
  }
}

class _OvertimeTile extends StatelessWidget {
  const _OvertimeTile({required this.attendance});

  final Attendance attendance;

  @override
  Widget build(BuildContext context) {
    final a = attendance;
    final (label, color, icon) = switch (a.overtimeStatus) {
      'approved' => ('Aprobadas', Colors.green.shade700, Icons.check_circle_rounded),
      'rejected' => ('No aprobadas', Theme.of(context).colorScheme.error, Icons.cancel_rounded),
      _ => ('Por aprobar', Colors.orange.shade800, Icons.schedule_rounded),
    };
    final shown = a.overtimeStatus == 'approved' ? (a.overtimeApprovedHours ?? a.overtimeHours) : a.overtimeHours;

    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: ListTile(
        leading: Icon(icon, color: color),
        title: Text(Fmt.dayLong(a.workDate)),
        subtitle: Text('${Fmt.time(a.clockIn)} – ${Fmt.time(a.clockOut)} · ${a.regularHours.toStringAsFixed(1)} h de jornada'),
        trailing: Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.end, children: [
          Text(_OvertimeScreenState.hours(shown), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
          Text(label, style: TextStyle(color: color, fontSize: 12, fontWeight: FontWeight.w600)),
        ]),
      ),
    );
  }
}
