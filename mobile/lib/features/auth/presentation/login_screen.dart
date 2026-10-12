import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/widgets/brand_logo.dart';
import 'auth_controller.dart';

/// Acceso: solo correo y contraseña; la empresa y la sede las reconoce el servidor.
class LoginScreen extends ConsumerStatefulWidget {
  const LoginScreen({super.key});

  @override
  ConsumerState<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends ConsumerState<LoginScreen> {
  final _formKey = GlobalKey<FormState>();
  final _email = TextEditingController();
  final _password = TextEditingController();
  bool _loading = false;
  bool _obscure = true;
  ApiException? _error;

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    FocusScope.of(context).unfocus();
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      await ref.read(authControllerProvider.notifier).login(_email.text, _password.text);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  /// La app no restablece contraseñas (política de tiendas y de la empresa): lo hace RRHH.
  Future<void> _forgotPassword() => showDialog<void>(
        context: context,
        builder: (c) => AlertDialog(
          icon: const Icon(Icons.support_agent_rounded),
          title: const Text('¿Olvidaste tu contraseña?'),
          content: const Text(
            'Pide a RRHH que restablezca tu acceso. Te darán una contraseña temporal '
            'que deberás cambiar al ingresar.',
          ),
          actions: [FilledButton(onPressed: () => Navigator.pop(c), child: const Text('Entendido'))],
        ),
      );


  @override
  Widget build(BuildContext context) {
    final auth = ref.watch(authControllerProvider);
    final theme = Theme.of(context);
    final fieldFill = theme.brightness == Brightness.dark ? null : Colors.white;

    // Fondo cielo (blanco → celeste), igual que el acceso del panel: el logo conserva sus colores
    const sky = BoxDecoration(
      gradient: LinearGradient(
        begin: Alignment.topCenter,
        end: Alignment.bottomCenter,
        colors: [Color(0xFFFFFFFF), Color(0xFFEAF6FF), Color(0xFFC6E6FB)],
        stops: [0, 0.35, 1],
      ),
    );

    return Scaffold(
      body: DecoratedBox(
        decoration: theme.brightness == Brightness.dark ? const BoxDecoration() : sky,
        child: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: Form(
              key: _formKey,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  const BrandLogo(height: 104),
                  const SizedBox(height: 28),
                  Text('Iniciar sesión',
                      textAlign: TextAlign.center,
                      style: theme.textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w700)),
                  const SizedBox(height: 20),
                  if (auth.notice != null && _error == null) ...[
                    _Banner(text: auth.notice!, color: theme.colorScheme.tertiaryContainer),
                    const SizedBox(height: 16),
                  ],
                  if (_error != null && _error!.fieldErrors.isEmpty) ...[
                    _Banner(text: _error!.message, color: theme.colorScheme.errorContainer),
                    const SizedBox(height: 16),
                  ],
                  TextFormField(
                    controller: _email,
                    keyboardType: TextInputType.emailAddress,
                    autocorrect: false,
                    autofillHints: const [AutofillHints.username],
                    textInputAction: TextInputAction.next,
                    decoration: InputDecoration(
                      fillColor: fieldFill,
                      labelText: 'Correo',
                      prefixIcon: const Icon(Icons.mail_outline),
                      errorText: _error?.fieldError('email'),
                    ),
                    validator: (v) => (v == null || !v.contains('@')) ? 'Ingresa un correo válido' : null,
                  ),
                  const SizedBox(height: 16),
                  TextFormField(
                    controller: _password,
                    obscureText: _obscure,
                    autofillHints: const [AutofillHints.password],
                    textInputAction: TextInputAction.done,
                    onFieldSubmitted: (_) => _submit(),
                    decoration: InputDecoration(
                      fillColor: fieldFill,
                      labelText: 'Contraseña',
                      prefixIcon: const Icon(Icons.lock_outline),
                      errorText: _error?.fieldError('password'),
                      suffixIcon: IconButton(
                        icon: Icon(_obscure ? Icons.visibility : Icons.visibility_off),
                        onPressed: () => setState(() => _obscure = !_obscure),
                      ),
                    ),
                    validator: (v) => (v == null || v.length < 6) ? 'Mínimo 6 caracteres' : null,
                  ),
                  Align(
                    alignment: Alignment.centerRight,
                    child: TextButton(onPressed: _forgotPassword, child: const Text('¿Olvidaste tu contraseña?')),
                  ),
                  const SizedBox(height: 8),
                  FilledButton(
                    onPressed: _loading ? null : _submit,
                    child: _loading
                        ? const SizedBox.square(dimension: 22, child: CircularProgressIndicator(strokeWidth: 2.5))
                        : const Text('Ingresar'),
                  ),
                ],
              ),
            ),
          ),
        ),
      )),
    );
  }
}

class _Banner extends StatelessWidget {
  const _Banner({required this.text, required this.color});

  final String text;
  final Color color;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(color: color, borderRadius: BorderRadius.circular(10)),
        child: Text(text),
      );
}
