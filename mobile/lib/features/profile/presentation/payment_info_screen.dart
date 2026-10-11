import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/widgets/async_views.dart';
import '../domain/models.dart';
import 'profile_providers.dart';

/// "Datos de Pago y Previsión": cuenta de abono (banco, tipo, número, CCI /
/// CLABE / CBU) y fondo de retiro / seguro (AFP, ONP, seguro estatal).
class PaymentInfoScreen extends ConsumerWidget {
  const PaymentInfoScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final file = ref.watch(employeeFileProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Datos de Pago y Previsión')),
      body: file.when(
        loading: () => const LoadingView(),
        error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(employeeFileProvider)),
        data: (f) => f.config.paymentInfo
            ? PaymentInfoForm(file: f)
            : const EmptyView(icon: Icons.lock_outline, message: 'Tu empresa no habilitó esta sección.'),
      ),
    );
  }
}

class PaymentInfoForm extends ConsumerStatefulWidget {
  const PaymentInfoForm({super.key, required this.file});

  final EmployeeFile file;

  @override
  ConsumerState<PaymentInfoForm> createState() => _PaymentInfoFormState();
}

class _PaymentInfoFormState extends ConsumerState<PaymentInfoForm> {
  final _formKey = GlobalKey<FormState>();
  late final _bank = TextEditingController(text: widget.file.payment.bankName);
  final _account = TextEditingController();
  final _interbank = TextEditingController();
  final _pensionCode = TextEditingController();
  late String _accountType = widget.file.payment.accountType ?? 'payroll';
  late String _currency = widget.file.payment.currency ?? 'PEN';
  late String? _pensionSystem = widget.file.payment.pensionSystem;
  late String? _pensionFund = widget.file.payment.pensionFund;
  late String? _health = widget.file.payment.healthInsurance;
  bool _editing = false;
  bool _saving = false;
  ApiException? _error;

  PaymentInfo get _p => widget.file.payment;
  Catalogs get _cat => widget.file.catalogs;
  bool get _locked => _p.isVerified;
  bool get _canEdit => widget.file.config.editPaymentInfo && !_locked;

  @override
  void dispose() {
    for (final c in [_bank, _account, _interbank, _pensionCode]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _save() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() {
      _saving = true;
      _error = null;
    });
    try {
      final msg = await ref.read(profileRepositoryProvider).updatePaymentInfo({
        'bank_name': _bank.text.trim(),
        'bank_account_type': _accountType,
        'bank_account_currency': _currency,
        'bank_account_number': _account.text.trim(),
        if (_interbank.text.trim().isNotEmpty) 'interbank_code': _interbank.text.trim(),
        'pension_system': _pensionSystem,
        if (_pensionSystem == 'afp') 'pension_fund': _pensionFund,
        if (_pensionCode.text.trim().isNotEmpty) 'pension_code': _pensionCode.text.trim(),
        'health_insurance': ?_health,
      });
      ref.invalidate(employeeFileProvider);
      if (mounted) {
        showSnack(context, msg);
        setState(() => _editing = false);
      }
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        _StatusBanner(payment: _p),
        const SizedBox(height: 16),
        if (!_editing) ...[
          _ReadOnly(payment: _p, catalogs: _cat),
          const SizedBox(height: 16),
          if (_canEdit)
            FilledButton.icon(
              onPressed: () => setState(() => _editing = true),
              icon: const Icon(Icons.edit_outlined),
              label: Text(_p.isComplete ? 'Actualizar datos' : 'Registrar mis datos'),
            )
          else
            Text(
              _locked
                  ? 'RRHH ya verificó tus datos. Si necesitas cambiarlos, comunícate con RRHH.'
                  : 'Tu empresa gestiona estos datos desde RRHH.',
              textAlign: TextAlign.center,
              style: TextStyle(color: scheme.onSurfaceVariant),
            ),
        ] else
          Form(
            key: _formKey,
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              if (_error != null && _error!.fieldErrors.isEmpty) ...[
                Text(_error!.message, style: TextStyle(color: scheme.error)),
                const SizedBox(height: 12),
              ],
              _sectionTitle(context, 'Cuenta de abono'),
              Autocomplete<String>(
                initialValue: TextEditingValue(text: _bank.text),
                optionsBuilder: (v) => _cat.banks.where((b) => b.toLowerCase().contains(v.text.toLowerCase())),
                onSelected: (v) => _bank.text = v,
                fieldViewBuilder: (context, controller, focus, onSubmit) => TextFormField(
                  controller: controller,
                  focusNode: focus,
                  onChanged: (v) => _bank.text = v,
                  decoration: InputDecoration(labelText: 'Banco', prefixIcon: const Icon(Icons.account_balance_outlined), errorText: _error?.fieldError('bank_name')),
                  validator: (v) => (v == null || v.trim().isEmpty) ? 'Indica tu banco' : null,
                ),
              ),
              const SizedBox(height: 12),
              Row(children: [
                Expanded(
                  flex: 3,
                  child: DropdownButtonFormField<String>(
                    initialValue: _accountType,
                    decoration: const InputDecoration(labelText: 'Tipo de cuenta'),
                    items: (_cat.bankAccountTypes.isEmpty ? const {'payroll': 'Cuenta sueldo', 'savings': 'Ahorros', 'checking': 'Corriente'} : _cat.bankAccountTypes)
                        .entries
                        .map((e) => DropdownMenuItem(value: e.key, child: Text(e.value)))
                        .toList(),
                    onChanged: (v) => setState(() => _accountType = v ?? _accountType),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  flex: 2,
                  child: DropdownButtonFormField<String>(
                    initialValue: _currency,
                    decoration: const InputDecoration(labelText: 'Moneda'),
                    items: const ['PEN', 'USD', 'MXN', 'ARS', 'COP', 'CLP']
                        .map((c) => DropdownMenuItem(value: c, child: Text(c)))
                        .toList(),
                    onChanged: (v) => setState(() => _currency = v ?? _currency),
                  ),
                ),
              ]),
              const SizedBox(height: 12),
              TextFormField(
                controller: _account,
                keyboardType: TextInputType.number,
                inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9\- ]'))],
                decoration: InputDecoration(
                  labelText: 'Número de cuenta',
                  helperText: _p.accountNumberMasked != null ? 'Actual: ${_p.accountNumberMasked}' : null,
                  errorText: _error?.fieldError('bank_account_number'),
                ),
                validator: (v) => RegExp(r'^[0-9\- ]{6,30}$').hasMatch(v?.trim() ?? '') ? null : 'Solo dígitos (6 a 30)',
              ),
              const SizedBox(height: 12),
              TextFormField(
                controller: _interbank,
                keyboardType: TextInputType.number,
                inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9\- ]'))],
                decoration: InputDecoration(
                  labelText: 'CCI / CLABE / CBU (opcional)',
                  helperText: _p.interbankMasked != null ? 'Actual: ${_p.interbankMasked}' : 'Código interbancario de 18 a 22 dígitos',
                  errorText: _error?.fieldError('interbank_code'),
                ),
                validator: (v) => (v == null || v.trim().isEmpty || RegExp(r'^[0-9\- ]{10,30}$').hasMatch(v.trim())) ? null : 'Solo dígitos (10 a 30)',
              ),
              const SizedBox(height: 24),
              _sectionTitle(context, 'Fondo de retiro y seguro'),
              Wrap(spacing: 8, runSpacing: 8, children: [
                for (final e in (_cat.pensionSystems.isEmpty ? const {'afp': 'AFP (privado)', 'onp': 'ONP (estatal)'} : _cat.pensionSystems).entries)
                  ChoiceChip(
                    label: Text(e.value),
                    selected: _pensionSystem == e.key,
                    onSelected: (_) => setState(() => _pensionSystem = e.key),
                  ),
              ]),
              if (_error?.fieldError('pension_system') != null)
                Padding(padding: const EdgeInsets.only(top: 6), child: Text(_error!.fieldError('pension_system')!, style: TextStyle(color: scheme.error))),
              if (_pensionSystem == 'afp') ...[
                const SizedBox(height: 12),
                DropdownButtonFormField<String>(
                  initialValue: _cat.pensionFunds.contains(_pensionFund) ? _pensionFund : null,
                  decoration: InputDecoration(labelText: 'AFP', errorText: _error?.fieldError('pension_fund')),
                  items: _cat.pensionFunds.map((f) => DropdownMenuItem(value: f, child: Text(f))).toList(),
                  onChanged: (v) => setState(() => _pensionFund = v),
                  validator: (v) => v == null ? 'Elige tu AFP' : null,
                ),
                const SizedBox(height: 12),
                TextFormField(
                  controller: _pensionCode,
                  textCapitalization: TextCapitalization.characters,
                  decoration: InputDecoration(
                    labelText: 'CUSPP (opcional)',
                    helperText: _p.pensionCodeMasked != null ? 'Actual: ${_p.pensionCodeMasked}' : null,
                  ),
                ),
              ],
              const SizedBox(height: 12),
              DropdownButtonFormField<String>(
                initialValue: _health,
                decoration: const InputDecoration(labelText: 'Seguro de salud'),
                items: (_cat.healthInsurances.isEmpty ? const {'essalud': 'EsSalud', 'eps': 'EPS'} : _cat.healthInsurances)
                    .entries
                    .map((e) => DropdownMenuItem(value: e.key, child: Text(e.value)))
                    .toList(),
                onChanged: (v) => setState(() => _health = v),
              ),
              const SizedBox(height: 24),
              FilledButton(
                onPressed: _saving || _pensionSystem == null ? null : _save,
                child: _saving
                    ? const SizedBox.square(dimension: 22, child: CircularProgressIndicator(strokeWidth: 2.5))
                    : const Text('Guardar y enviar a RRHH'),
              ),
              TextButton(onPressed: _saving ? null : () => setState(() => _editing = false), child: const Text('Cancelar')),
              const SizedBox(height: 8),
              Text(
                'Tus números se guardan cifrados y solo RRHH puede verlos completos.',
                textAlign: TextAlign.center,
                style: Theme.of(context).textTheme.bodySmall,
              ),
            ]),
          ),
      ],
    );
  }

  Widget _sectionTitle(BuildContext context, String text) => Padding(
        padding: const EdgeInsets.only(bottom: 12),
        child: Text(text, style: Theme.of(context).textTheme.titleMedium),
      );
}

class _StatusBanner extends StatelessWidget {
  const _StatusBanner({required this.payment});

  final PaymentInfo payment;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final (bg, fg, icon, text) = switch (payment.status) {
      'verified' => (Colors.green.withValues(alpha: 0.12), Colors.green.shade700, Icons.verified_user_outlined, 'Verificado por RRHH'),
      'pending' => (Colors.orange.withValues(alpha: 0.12), Colors.orange.shade800, Icons.hourglass_top_rounded, 'Enviado, RRHH lo está verificando'),
      'rejected' => (scheme.errorContainer, scheme.onErrorContainer, Icons.report_gmailerrorred_outlined, 'Observado: ${payment.rejectionReason ?? 'revisa tus datos'}'),
      _ => (scheme.surfaceContainerHighest, scheme.onSurfaceVariant, Icons.info_outline, 'Aún no registras tu cuenta de abono'),
    };

    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(10)),
      child: Row(children: [
        Icon(icon, color: fg),
        const SizedBox(width: 10),
        Expanded(child: Text(text, style: TextStyle(color: fg, fontWeight: FontWeight.w600))),
      ]),
    );
  }
}

class _ReadOnly extends StatelessWidget {
  const _ReadOnly({required this.payment, required this.catalogs});

  final PaymentInfo payment;
  final Catalogs catalogs;

  @override
  Widget build(BuildContext context) {
    Widget row(String label, String? value) => ListTile(
          dense: true,
          title: Text(label),
          trailing: Text(value ?? '—', style: const TextStyle(fontWeight: FontWeight.w600, fontFeatures: [FontFeature.tabularFigures()])),
        );

    return Card(
      child: Column(children: [
        row('Banco', payment.bankName),
        row('Tipo de cuenta', catalogs.bankAccountTypes[payment.accountType] ?? payment.accountType),
        row('Moneda', payment.currency),
        row('N° de cuenta', payment.accountNumberMasked),
        row('CCI / CLABE / CBU', payment.interbankMasked),
        const Divider(),
        row('Sistema previsional', catalogs.pensionSystems[payment.pensionSystem] ?? payment.pensionSystem),
        if (payment.pensionSystem == 'afp') row('AFP', payment.pensionFund),
        row('Seguro de salud', catalogs.healthInsurances[payment.healthInsurance] ?? payment.healthInsurance),
        const Divider(),
        row('Régimen laboral', payment.laborRegimeLabel),
        row('Depósito CTS', payment.ctsBankName),
      ]),
    );
  }
}
