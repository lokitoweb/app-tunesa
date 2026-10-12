import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/utils/attachment_picker.dart';
import '../../../core/utils/formatters.dart';
import '../../../core/widgets/async_views.dart';
import '../../../core/widgets/status_chip.dart';
import '../domain/models.dart';
import 'attendance_providers.dart';
import '../../../core/widgets/page_padding.dart';

/// Historial mensual de asistencia con opción de justificar tardanzas y faltas.
class HistoryScreen extends ConsumerStatefulWidget {
  const HistoryScreen({super.key});

  @override
  ConsumerState<HistoryScreen> createState() => _HistoryScreenState();
}

class _HistoryScreenState extends ConsumerState<HistoryScreen> {
  DateTime _month = DateTime(DateTime.now().year, DateTime.now().month);

  (int, int) get _period => (_month.month, _month.year);

  void _shift(int delta) => setState(() => _month = DateTime(_month.year, _month.month + delta));

  @override
  Widget build(BuildContext context) {
    final history = ref.watch(attendanceHistoryProvider(_period));
    final isCurrentMonth = _month.year == DateTime.now().year && _month.month == DateTime.now().month;
    final title = DateFormat('MMMM yyyy', 'es').format(_month);

    return Scaffold(
      appBar: AppBar(title: const Text('Mi asistencia')),
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

class _AttendanceTile extends StatelessWidget {
  const _AttendanceTile({required this.attendance, required this.onJustify});

  final Attendance attendance;
  final VoidCallback onJustify;

  @override
  Widget build(BuildContext context) {
    final a = attendance;
    final justification = switch (a.justificationStatus) {
      'pending' => 'Justificación en revisión',
      'approved' => 'Justificación aprobada',
      'rejected' => 'Justificación rechazada',
      _ => null,
    };

    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: ListTile(
        title: Text(Fmt.date(a.workDate)),
        subtitle: Text([
          '${Fmt.time(a.clockIn)} – ${Fmt.time(a.clockOut)}',
          if (a.lateMinutes > 0) '${a.lateMinutes} min tarde',
          ?justification,
        ].join(' · ')),
        trailing: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          crossAxisAlignment: CrossAxisAlignment.end,
          children: [
            StatusChip(status: a.status, label: a.statusLabel),
            if (a.canJustify && a.justificationStatus != 'pending')
              InkWell(
                onTap: onJustify,
                child: const Padding(
                  padding: EdgeInsets.only(top: 4),
                  child: Text('Justificar', style: TextStyle(decoration: TextDecoration.underline)),
                ),
              ),
          ],
        ),
      ),
    );
  }
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
