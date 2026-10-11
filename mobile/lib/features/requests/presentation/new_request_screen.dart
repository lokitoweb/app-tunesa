import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/utils/attachment_picker.dart';
import '../../../core/utils/formatters.dart';
import '../../../core/widgets/async_views.dart';
import '../../../core/widgets/picker_field.dart';
import '../domain/models.dart';
import 'requests_screen.dart';

/// Nueva solicitud: tipo, fechas, horas (permisos por horas), motivo y sustento.
class NewRequestScreen extends ConsumerStatefulWidget {
  const NewRequestScreen({super.key});

  @override
  ConsumerState<NewRequestScreen> createState() => _NewRequestScreenState();
}

class _NewRequestScreenState extends ConsumerState<NewRequestScreen> {
  final _formKey = GlobalKey<FormState>();
  final _reason = TextEditingController();

  LeaveType _type = LeaveType.vacation;
  DateTimeRange? _range;
  bool _byHours = false;
  TimeOfDay? _startTime;
  TimeOfDay? _endTime;
  PlatformAttachment? _attachment;
  bool _sending = false;
  ApiException? _error;

  @override
  void dispose() {
    _reason.dispose();
    super.dispose();
  }

  Future<void> _pickRange() async {
    final now = DateTime.now();
    final range = await showDateRangePicker(
      context: context,
      firstDate: now.subtract(const Duration(days: 30)),
      lastDate: now.add(const Duration(days: 365)),
      initialDateRange: _range,
      helpText: 'Fechas de la solicitud',
    );
    if (range != null) setState(() => _range = range);
  }

  Future<void> _pickTime(bool start) async {
    final time = await showTimePicker(context: context, initialTime: (start ? _startTime : _endTime) ?? TimeOfDay.now());
    if (time == null) return;
    setState(() => start ? _startTime = time : _endTime = time);
  }

  Future<void> _pickAttachment() async {
    try {
      final file = await PlatformAttachment.pick();
      if (file != null) setState(() => _attachment = file);
    } on AttachmentTooLarge catch (e) {
      if (mounted) showSnack(context, e.toString(), error: true);
    }
  }

  String _hhmm(TimeOfDay t) => '${t.hour.toString().padLeft(2, '0')}:${t.minute.toString().padLeft(2, '0')}';

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    if (_range == null) {
      showSnack(context, 'Elige las fechas.', error: true);
      return;
    }
    if (_type.requiresAttachment && _attachment == null) {
      showSnack(context, 'Adjunta el certificado médico.', error: true);
      return;
    }
    final hours = _type == LeaveType.permission && _byHours;
    if (hours && (_startTime == null || _endTime == null)) {
      showSnack(context, 'Indica la hora de inicio y fin del permiso.', error: true);
      return;
    }

    setState(() {
      _sending = true;
      _error = null;
    });
    try {
      await ref.read(leaveRepositoryProvider).create(
            type: _type,
            start: _range!.start,
            end: _range!.end,
            reason: _reason.text.trim(),
            startTime: hours ? _hhmm(_startTime!) : null,
            endTime: hours ? _hhmm(_endTime!) : null,
            attachment: _attachment,
          );
      if (!mounted) return;
      showSnack(context, 'Solicitud enviada. Te avisaremos cuando sea revisada.');
      Navigator.of(context).pop(true);
    } on ApiException catch (e) {
      setState(() => _error = e);
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final range = _range;

    return Scaffold(
      appBar: AppBar(title: const Text('Nueva solicitud')),
      body: Form(
        key: _formKey,
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            DropdownButtonFormField<LeaveType>(
              initialValue: _type,
              decoration: const InputDecoration(labelText: 'Tipo de solicitud', prefixIcon: Icon(Icons.category_outlined)),
              items: [for (final t in LeaveType.values) DropdownMenuItem(value: t, child: Text(t.label))],
              onChanged: (t) => setState(() => _type = t ?? _type),
            ),
            const SizedBox(height: 12),
            PickerField(
              label: 'Fechas',
              icon: Icons.date_range_outlined,
              value: range == null ? null : '${Fmt.date(range.start)} al ${Fmt.date(range.end)}',
              placeholder: 'Elegir fechas',
              onTap: _pickRange,
            ),
            if (_error?.fieldError('start_date') != null || _error?.fieldError('end_date') != null)
              Text(_error!.fieldError('start_date') ?? _error!.fieldError('end_date')!,
                  style: TextStyle(color: Theme.of(context).colorScheme.error)),
            if (_type == LeaveType.permission) ...[
              SwitchListTile(
                contentPadding: EdgeInsets.zero,
                title: const Text('Permiso por horas'),
                value: _byHours,
                onChanged: (v) => setState(() => _byHours = v),
              ),
              if (_byHours)
                Row(children: [
                  Expanded(
                    child: PickerField(
                      label: 'Desde',
                      icon: Icons.schedule,
                      value: _startTime == null ? null : _hhmm(_startTime!),
                      placeholder: '--:--',
                      onTap: () => _pickTime(true),
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: PickerField(
                      label: 'Hasta',
                      icon: Icons.schedule,
                      value: _endTime == null ? null : _hhmm(_endTime!),
                      placeholder: '--:--',
                      onTap: () => _pickTime(false),
                    ),
                  ),
                ]),
            ],
            const SizedBox(height: 12),
            TextFormField(
              controller: _reason,
              maxLines: 4,
              maxLength: 1000,
              decoration: InputDecoration(
                labelText: 'Motivo',
                alignLabelWithHint: true,
                errorText: _error?.fieldError('reason'),
              ),
              validator: (v) => (v == null || v.trim().length < 5) ? 'Describe el motivo (mínimo 5 caracteres)' : null,
            ),
            PickerField(
              label: _type.requiresAttachment ? 'Certificado médico (obligatorio)' : 'Sustento (opcional)',
              icon: Icons.attach_file,
              value: _attachment?.name,
              placeholder: 'Adjuntar archivo',
              onTap: _pickAttachment,
            ),
            if (_error?.fieldError('attachment') != null)
              Text(_error!.fieldError('attachment')!, style: TextStyle(color: Theme.of(context).colorScheme.error)),
            if (_error != null && _error!.fieldErrors.isEmpty) ...[
              const SizedBox(height: 12),
              Text(_error!.message, style: TextStyle(color: Theme.of(context).colorScheme.error)),
            ],
            const SizedBox(height: 24),
            FilledButton(
              onPressed: _sending ? null : _submit,
              child: _sending
                  ? const SizedBox.square(dimension: 22, child: CircularProgressIndicator(strokeWidth: 2.5))
                  : const Text('Enviar solicitud'),
            ),
          ],
        ),
      ),
    );
  }
}
