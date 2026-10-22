import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/utils/attachment_picker.dart';
import '../../../core/utils/formatters.dart';
import '../../../core/utils/list_filters.dart';
import '../../../core/widgets/async_views.dart';
import '../../../core/theme/app_theme.dart';
import '../domain/models.dart';
import 'attendance_providers.dart';
import '../../onboarding/presentation/onboarding_gate_card.dart';
import '../../onboarding/presentation/onboarding_providers.dart';
import '../../../core/widgets/page_padding.dart';

/// Historial mensual de asistencia con opción de justificar tardanzas y faltas.
class HistoryScreen extends ConsumerStatefulWidget {
  const HistoryScreen({super.key});

  @override
  ConsumerState<HistoryScreen> createState() => _HistoryScreenState();
}

class _HistoryScreenState extends ConsumerState<HistoryScreen> {
  // El mes elegido se conserva al navegar o minimizar la app (MonthFilter)
  DateTime get _month => ref.read(monthFilterProvider('history'));

  (int, int) get _period => (_month.month, _month.year);

  void _shift(int delta) => ref.read(monthFilterProvider('history').notifier).shift(delta);

  @override
  Widget build(BuildContext context) {
    ref.watch(monthFilterProvider('history'));
    // Sin incorporación aprobada por RRHH todavía no hay asistencia que ver
    if (!ref.watch(canPunchProvider)) {
      return Scaffold(
        appBar: AppBar(title: const Text('Mis asistencias')),
        body: ListView(padding: pagePadding(context), children: const [OnboardingGateCard()]),
      );
    }
    final history = ref.watch(attendanceHistoryProvider(_period));
    final isCurrentMonth = _month.year == DateTime.now().year && _month.month == DateTime.now().month;
    final title = DateFormat('MMMM yyyy', 'es').format(_month);

    return Scaffold(
      appBar: AppBar(title: const Text('Mis asistencias'), actions: [
        IconButton(
          key: const Key('open-overtime'),
          tooltip: 'Mis horas extra',
          icon: const Icon(Icons.more_time_rounded),
          onPressed: () => context.push('/overtime'),
        ),
      ]),
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
          child: history.when(
            loading: () => const LoadingView(),
            error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(attendanceHistoryProvider(_period))),
            data: (data) => RefreshIndicator(
              onRefresh: () => ref.refresh(attendanceHistoryProvider(_period).future),
              child: ListView(
                padding: pagePadding(context),
                children: [
                  _SummaryRow(summary: data.summary),
                  const SizedBox(height: 12),
                  if (data.items.isEmpty)
                    const EmptyView(icon: Icons.event_busy, message: 'No hay registros este mes.')
                  else
                    for (final a in data.items) _AttendanceTile(attendance: a, onJustify: () => _justify(a)),
                ],
              ),
            ),
          ),
        ),
      ]),
    );
  }

  Future<void> _justify(Attendance attendance) async {
    final sent = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      builder: (_) => JustificationSheet(attendance: attendance),
    );
    if (sent == true) {
      ref.invalidate(attendanceHistoryProvider(_period));
      if (mounted) showSnack(context, 'Justificación enviada. RRHH la revisará.');
    }
  }
}

class _SummaryRow extends StatelessWidget {
  const _SummaryRow({required this.summary});

  final HistorySummary summary;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    Widget cell(String label, String value, Color color) => Expanded(
          child: Column(children: [
            Text(value, style: TextStyle(fontSize: 22, fontWeight: FontWeight.w700, color: color)),
            const SizedBox(height: 2),
            Text(label, style: theme.textTheme.labelMedium?.copyWith(color: theme.colorScheme.onSurfaceVariant)),
          ]),
        );
    const divider = SizedBox(height: 32, child: VerticalDivider(width: 1));

    return Card(
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 14),
        child: Row(children: [
          cell('Puntual', '${summary.present}', Colors.green.shade700),
          divider,
          cell('Tardanzas', '${summary.late}', Colors.orange.shade800),
          divider,
          cell('Faltas', '${summary.absent}', theme.colorScheme.error),
          divider,
          cell('Horas', summary.totalHours.toStringAsFixed(1), theme.colorScheme.primary),
        ]),
      ),
    );
  }
}

/// Tarjeta de un día: fecha, ENTRADA y SALIDA con su hora (24 h), estado de
/// la jornada, horas trabajadas y avisos (tardanza, horas extra, justificación).
class _AttendanceTile extends StatelessWidget {
  const _AttendanceTile({required this.attendance, required this.onJustify});

  final Attendance attendance;
  final VoidCallback onJustify;

  /// Etiqueta corta de la jornada, como en el reloj: "Completo" cuando hay
  /// entrada y salida a tiempo, "En curso" si falta la salida de hoy.
  static (String, Color) dayState(Attendance a, ColorScheme scheme) {
    final today = DateUtils.isSameDay(a.workDate, DateTime.now());
    if (a.status == 'absent') return ('Falta', scheme.error);
    if (a.status == 'excused') return ('Justificado', Colors.blue.shade700);
    if (a.clockIn != null && a.clockOut == null) {
      return today ? ('En curso', AppTheme.skyDeep) : ('Sin salida', Colors.orange.shade800);
    }
    if (a.status == 'late') return ('Tardanza', Colors.orange.shade800);
    if (a.status == 'incomplete' || a.status == 'half_day') return (a.statusLabel, Colors.orange.shade800);
    if (a.clockIn != null && a.clockOut != null) return ('Completo', Colors.green.shade700);
    return (a.statusLabel, scheme.onSurfaceVariant);
  }

  @override
  Widget build(BuildContext context) {
    final a = attendance;
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final (stateLabel, stateColor) = dayState(a, scheme);
    final weekday = DateFormat('EEE', 'es').format(a.workDate).replaceAll('.', '').toUpperCase();
    final justification = switch (a.justificationStatus) {
      'pending' => 'Justificación en revisión',
      'approved' => 'Justificación aprobada',
      'rejected' => 'Justificación rechazada',
      _ => null,
    };
    final notes = [
      if (a.lateMinutes > 0) '${a.lateMinutes} min tarde',
      ?a.overtimeLabel,
      ?justification,
    ];

    return Card(
      margin: const EdgeInsets.only(bottom: 10),
      clipBehavior: Clip.antiAlias,
      child: IntrinsicHeight(
        child: Row(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Container(width: 5, color: stateColor),
          Expanded(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(12, 12, 12, 12),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Row(children: [
                  Container(
                    width: 44,
                    padding: const EdgeInsets.symmetric(vertical: 4),
                    decoration: BoxDecoration(
                      color: scheme.primary.withValues(alpha: 0.08),
                      borderRadius: BorderRadius.circular(AppTheme.radius),
                    ),
                    child: Column(children: [
                      Text(weekday,
                          style: theme.textTheme.labelSmall
                              ?.copyWith(color: scheme.primary, fontWeight: FontWeight.w700, letterSpacing: 0.5)),
                      Text('${a.workDate.day}',
                          style: theme.textTheme.titleLarge
                              ?.copyWith(color: scheme.primary, fontWeight: FontWeight.w800, height: 1.1)),
                    ]),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(Fmt.dayLong(a.workDate),
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
                      Text(
                        [
                          if (a.workedHours > 0) '${a.regularHours.toStringAsFixed(1)} h de jornada',
                          ?a.shiftName,
                        ].join(' · ').ifEmpty('Sin horas registradas'),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: theme.textTheme.bodySmall?.copyWith(color: scheme.onSurfaceVariant),
                      ),
                    ]),
                  ),
                  const SizedBox(width: 8),
                  Container(
                    key: ValueKey('day-state-${a.id}'),
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                    decoration: BoxDecoration(
                      color: stateColor.withValues(alpha: 0.12),
                      borderRadius: BorderRadius.circular(20),
                    ),
                    child: Text(stateLabel,
                        style: TextStyle(color: stateColor, fontSize: 12, fontWeight: FontWeight.w700)),
                  ),
                ]),
                const SizedBox(height: 12),
                Row(children: [
                  Expanded(child: _PunchCell(label: 'ENTRADA', time: a.clockIn, icon: Icons.login_rounded, color: Colors.green.shade700)),
                  const SizedBox(width: 10),
                  Expanded(child: _PunchCell(label: 'SALIDA', time: a.clockOut, icon: Icons.logout_rounded, color: AppTheme.skyDeep)),
                ]),
                if (notes.isNotEmpty || (a.canJustify && a.justificationStatus != 'pending')) ...[
                  const SizedBox(height: 8),
                  Row(children: [
                    Expanded(
                      child: Text(notes.join(' · '),
                          style: theme.textTheme.bodySmall?.copyWith(color: scheme.onSurfaceVariant)),
                    ),
                    if (a.canJustify && a.justificationStatus != 'pending')
                      TextButton.icon(
                        onPressed: onJustify,
                        icon: const Icon(Icons.edit_note, size: 18),
                        label: const Text('Justificar'),
                        style: TextButton.styleFrom(visualDensity: VisualDensity.compact),
                      ),
                  ]),
                ],
              ]),
            ),
          ),
        ]),
      ),
    );
  }
}

/// Recuadro de una marcación (ENTRADA o SALIDA) con su hora en 24 h.
class _PunchCell extends StatelessWidget {
  const _PunchCell({required this.label, required this.time, required this.icon, required this.color});

  final String label;
  final DateTime? time;
  final IconData icon;
  final Color color;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final missing = time == null;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
      decoration: BoxDecoration(
        color: (missing ? theme.colorScheme.onSurfaceVariant : color).withValues(alpha: 0.07),
        borderRadius: BorderRadius.circular(AppTheme.radius),
      ),
      child: Row(children: [
        Icon(icon, size: 20, color: missing ? theme.colorScheme.onSurfaceVariant : color),
        const SizedBox(width: 8),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(label,
                style: theme.textTheme.labelSmall?.copyWith(
                    color: theme.colorScheme.onSurfaceVariant, fontWeight: FontWeight.w700, letterSpacing: 0.8)),
            Text(Fmt.time(time),
                style: theme.textTheme.titleMedium?.copyWith(
                    fontWeight: FontWeight.w800,
                    fontFeatures: const [FontFeature.tabularFigures()],
                    color: missing ? theme.colorScheme.onSurfaceVariant : null)),
          ]),
        ),
      ]),
    );
  }
}

extension on String {
  String ifEmpty(String fallback) => isEmpty ? fallback : this;
}

/// Formulario de justificación: motivo + sustento opcional (PDF/imagen).
class JustificationSheet extends ConsumerStatefulWidget {
  const JustificationSheet({super.key, required this.attendance});

  final Attendance attendance;

  @override
  ConsumerState<JustificationSheet> createState() => _JustificationSheetState();
}

class _JustificationSheetState extends ConsumerState<JustificationSheet> {
  final _notes = TextEditingController();
  PlatformAttachment? _file;
  bool _sending = false;
  String? _error;

  @override
  void dispose() {
    _notes.dispose();
    super.dispose();
  }

  Future<void> _pick() async {
    try {
      final file = await PlatformAttachment.pick();
      if (file != null) setState(() => _file = file);
    } on AttachmentTooLarge catch (e) {
      setState(() => _error = e.toString());
    }
  }

  Future<void> _send() async {
    if (_notes.text.trim().length < 5) {
      setState(() => _error = 'Explica el motivo (mínimo 5 caracteres).');
      return;
    }
    setState(() {
      _sending = true;
      _error = null;
    });
    try {
      await ref.read(attendanceRepositoryProvider).submitJustification(widget.attendance.id, _notes.text.trim(), file: _file);
      if (mounted) Navigator.of(context).pop(true);
    } on ApiException catch (e) {
      setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.fromLTRB(16, 16, 16, MediaQuery.of(context).viewInsets.bottom + 16),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text('Justificar ${widget.attendance.statusLabel.toLowerCase()} del ${Fmt.date(widget.attendance.workDate)}',
              style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(height: 12),
          TextField(
            controller: _notes,
            maxLines: 4,
            maxLength: 1000,
            decoration: const InputDecoration(labelText: 'Motivo'),
          ),
          OutlinedButton.icon(
            onPressed: _pick,
            icon: const Icon(Icons.attach_file),
            label: Text(_file?.name ?? 'Adjuntar sustento (opcional)'),
          ),
          if (_error != null) ...[
            const SizedBox(height: 8),
            Text(_error!, style: TextStyle(color: Theme.of(context).colorScheme.error)),
          ],
          const SizedBox(height: 12),
          FilledButton(
            onPressed: _sending ? null : _send,
            child: _sending
                ? const SizedBox.square(dimension: 22, child: CircularProgressIndicator(strokeWidth: 2.5))
                : const Text('Enviar justificación'),
          ),
        ],
      ),
    );
  }
}
