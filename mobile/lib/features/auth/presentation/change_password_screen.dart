import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/widgets/async_views.dart';
import 'auth_controller.dart';

/// Cambio de contraseña. Si la cuenta tiene una contraseña temporal
/// (creada por RRHH) es obligatorio y no se puede salir de esta pantalla.
class ChangePasswordScreen extends ConsumerStatefulWidget {
  const ChangePasswordScreen({super.key});

  @override
  ConsumerState<ChangePasswordScreen> createState() => _ChangePasswordScreenState();
}

class _ChangePasswordScreenState extends ConsumerState<ChangePasswordScreen> {
  final _formKey = GlobalKey<FormState>();
  final _current = TextEditingController();
  final _next = TextEditingController();
  final _confirm = TextEditingController();
  bool _saving = false;
  ApiException? _error;

  @override
  void dispose() {
    for (final c in [_current, _next, _confirm]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() {
      _saving = true;
      _error = null;
    });
    try {
      await ref.read(authControllerProvider.notifier).changePassword(_current.text, _next.text);
      if (mounted) {
        showSnack(context, 'Contraseña actualizada.');
        if (Navigator.of(context).canPop()) Navigator.of(context).pop();
      }
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final forced = ref.watch(authControllerProvider.select((s) => s.user?.mustChangePassword ?? false));

    return PopScope(
      canPop: !forced,
      child: Scaffold(
        appBar: AppBar(
          title: const Text('Cambiar contraseña'),
          automaticallyImplyLeading: !forced,
          actions: [
            if (forced)
              TextButton(
                onPressed: () => ref.read(authControllerProvider.notifier).logout(),
                child: const Text('Salir'),
              ),
          ],
        ),
        body: Form(
          key: _formKey,
          child: ListView(padding: const EdgeInsets.all(16), children: [
            if (forced)
              Card(
                color: Theme.of(context).colorScheme.primaryContainer,
                child: const ListTile(
                  leading: Icon(Icons.shield_outlined),
                  title: Text('Crea tu contraseña personal'),
                  subtitle: Text('RRHH te dio una contraseña temporal. Cámbiala para continuar.'),
                ),
              ),
            const SizedBox(height: 16),
            if (_error != null && _error!.fieldErrors.isEmpty)
              Padding(
                padding: const EdgeInsets.only(bottom: 12),
                child: Text(_error!.message, style: TextStyle(color: Theme.of(context).colorScheme.error)),
              ),
            TextFormField(
              controller: _current,
              obscureText: true,
              decoration: InputDecoration(labelText: forced ? 'Contraseña temporal' : 'Contraseña actual', errorText: _error?.fieldError('current_password')),
              validator: (v) => (v == null || v.isEmpty) ? 'Requerida' : null,
            ),
            const SizedBox(height: 12),
            TextFormField(
              controller: _next,
              obscureText: true,
              decoration: InputDecoration(
                labelText: 'Nueva contraseña',
                helperText: '8+ caracteres, mayúsculas, minúsculas y números',
                errorText: _error?.fieldError('new_password'),
              ),
              validator: ChangePasswordScreenValidators.strong,
            ),
            const SizedBox(height: 12),
            TextFormField(
              controller: _confirm,
              obscureText: true,
              decoration: const InputDecoration(labelText: 'Repite la nueva contraseña'),
              validator: (v) => v != _next.text ? 'No coincide' : null,
            ),
            const SizedBox(height: 24),
            FilledButton(
              onPressed: _saving ? null : _submit,
              child: _saving ? const SizedBox.square(dimension: 22, child: CircularProgressIndicator(strokeWidth: 2.5)) : const Text('Guardar contraseña'),
            ),
          ]),
        ),
      ),
    );
  }
}

/// Misma regla que el backend (Password::min(8)->letters()->mixedCase()->numbers()).
class ChangePasswordScreenValidators {
  const ChangePasswordScreenValidators._();

  static String? strong(String? v) {
    if (v == null || v.length < 8) return 'Mínimo 8 caracteres';
    if (!RegExp(r'[a-z]').hasMatch(v) || !RegExp(r'[A-Z]').hasMatch(v)) return 'Usa mayúsculas y minúsculas';
    if (!RegExp(r'\d').hasMatch(v)) return 'Incluye al menos un número';
    return null;
  }
}
