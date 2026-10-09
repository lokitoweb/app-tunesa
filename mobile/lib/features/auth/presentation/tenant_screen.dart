import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/config/env.dart';
import '../../../core/network/api_exception.dart';
import 'auth_controller.dart';

/// Paso 1 del login por empresa: el colaborador ingresa el código de su
/// empresa (slug). Se valida contra /tenant/branding y se aplica su marca.
class TenantScreen extends ConsumerStatefulWidget {
  const TenantScreen({super.key});

  @override
  ConsumerState<TenantScreen> createState() => _TenantScreenState();
}

class _TenantScreenState extends ConsumerState<TenantScreen> {
  final _formKey = GlobalKey<FormState>();
  final _slug = TextEditingController();
  bool _loading = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    // App de marca blanca: la empresa viene fija en el build (DEFAULT_TENANT).
    if (Env.defaultTenant.isNotEmpty) {
      _slug.text = Env.defaultTenant;
      WidgetsBinding.instance.addPostFrameCallback((_) => _submit());
    }
  }

  @override
  void dispose() {
    _slug.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      await ref.read(authControllerProvider.notifier).selectTenant(_slug.text);
    } on ApiException catch (e) {
      setState(() => _error = e.statusCode == 404 ? 'No encontramos una empresa con ese código.' : e.message);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: Form(
              key: _formKey,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Icon(Icons.apartment_rounded, size: 64, color: theme.colorScheme.primary),
                  const SizedBox(height: 16),
                  Text('Bienvenido a TuneSA', textAlign: TextAlign.center, style: theme.textTheme.headlineSmall),
                  const SizedBox(height: 8),
                  const Text('Ingresa el código de tu empresa. Te lo da RRHH.', textAlign: TextAlign.center),
                  const SizedBox(height: 32),
                  TextFormField(
                    controller: _slug,
                    autocorrect: false,
                    textInputAction: TextInputAction.go,
                    onFieldSubmitted: (_) => _submit(),
                    decoration: InputDecoration(
                      labelText: 'Código de empresa',
                      hintText: 'ej: empresa-demo',
                      prefixIcon: const Icon(Icons.business),
                      errorText: _error,
                    ),
                    validator: (v) => (v == null || v.trim().isEmpty) ? 'Ingresa el código de tu empresa' : null,
                  ),
                  const SizedBox(height: 24),
                  FilledButton(
                    onPressed: _loading ? null : _submit,
                    child: _loading
                        ? const SizedBox.square(dimension: 22, child: CircularProgressIndicator(strokeWidth: 2.5))
                        : const Text('Continuar'),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
