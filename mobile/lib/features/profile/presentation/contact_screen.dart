import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/widgets/async_views.dart';
import 'profile_providers.dart';
import '../../../core/widgets/page_padding.dart';

/// Dirección y contacto de emergencia.
class ContactScreen extends ConsumerStatefulWidget {
  const ContactScreen({super.key});

  @override
  ConsumerState<ContactScreen> createState() => _ContactScreenState();
}

class _ContactScreenState extends ConsumerState<ContactScreen> {
  final _formKey = GlobalKey<FormState>();
  final _address = TextEditingController();
  final _name = TextEditingController();
  final _phone = TextEditingController();
  bool _loaded = false;
  bool _saving = false;

  @override
  void dispose() {
    _address.dispose();
    _name.dispose();
    _phone.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() => _saving = true);
    try {
      await ref.read(profileRepositoryProvider).updateContact({
        'address': _address.text.trim(),
        'emergency_contact_name': _name.text.trim(),
        'emergency_contact_phone': _phone.text.trim(),
      });
      ref.invalidate(employeeFileProvider);
      if (mounted) {
        showSnack(context, 'Contacto actualizado.');
        Navigator.of(context).maybePop();
      }
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message, error: true);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final file = ref.watch(employeeFileProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Datos de contacto')),
      body: file.when(
        loading: () => const LoadingView(),
        error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(employeeFileProvider)),
        data: (f) {
          if (!_loaded) {
            _address.text = f.address ?? '';
            _name.text = f.emergencyContactName ?? '';
            _phone.text = f.emergencyContactPhone ?? '';
            _loaded = true;
          }
          return Form(
            key: _formKey,
            child: ListView(padding: pagePadding(context), children: [
              TextFormField(
                controller: _address,
                decoration: const InputDecoration(labelText: 'Dirección de domicilio', prefixIcon: Icon(Icons.home_outlined)),
              ),
              const SizedBox(height: 12),
              TextFormField(
                controller: _name,
                decoration: const InputDecoration(labelText: 'Contacto de emergencia', prefixIcon: Icon(Icons.person_outline)),
              ),
              const SizedBox(height: 12),
              TextFormField(
                controller: _phone,
                keyboardType: TextInputType.phone,
                decoration: const InputDecoration(labelText: 'Teléfono de emergencia', prefixIcon: Icon(Icons.phone_outlined)),
                validator: (v) => (v == null || v.isEmpty || RegExp(r'^[0-9+\- ()]{6,30}$').hasMatch(v)) ? null : 'Teléfono no válido',
              ),
              const SizedBox(height: 24),
              FilledButton(
                onPressed: _saving ? null : _save,
                child: _saving ? const SizedBox.square(dimension: 22, child: CircularProgressIndicator(strokeWidth: 2.5)) : const Text('Guardar'),
              ),
            ]),
          );
        },
      ),
    );
  }
}
