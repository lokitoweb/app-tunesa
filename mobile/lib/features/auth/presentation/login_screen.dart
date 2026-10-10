import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/config/app_config_controller.dart';
import '../../../core/network/api_exception.dart';
import '../../../core/widgets/brand_logo.dart';
import '../../server/server_connection_dialog.dart';
import '../../../core/widgets/async_views.dart';
import 'auth_controller.dart';

/// Paso 2: correo y contraseña dentro de la empresa elegida.
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

  Future<void> _forgotPassword() async {
    final email = _email.text.trim();
    if (email.isEmpty || !email.contains('@')) {
      showSnack(context, 'Escribe tu correo y vuelve a tocar "Olvidé mi contraseña".');
      return;
    }
    try {
      await ref.read(authRepositoryProvider).forgotPassword(email);
      if (!mounted) return;
      await showDialog<void>(
        context: context,
        builder: (c) => AlertDialog(
          icon: const Icon(Icons.mark_email_read_outlined),
          title: const Text('Revisa tu correo'),
          content: Text(
            'Si $email está registrado, te enviamos un enlace para restablecer tu contraseña.\n\n'
            '1. Abre el enlace del correo (se abre una página web).\n'
            '2. Crea ahí tu nueva contraseña.\n'
            '3. Vuelve a la app e ingresa con tu correo y la nueva contraseña.\n\n'
            'Si no lo ves, revisa la carpeta de spam. El enlace vence en una hora.',
          ),
          actions: [FilledButton(onPressed: () => Navigator.pop(c), child: const Text('Entendido'))],
        ),
      );
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message, error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final auth = ref.watch(authControllerProvider);
    final controller = ref.read(authControllerProvider.notifier);
    final config = ref.watch(appConfigProvider.select((s) => s.config));
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
                  // Mantener presionado: conexión del servidor (testers)
                  BrandLogo(height: 72, onLongPress: () => showServerConnectionDialog(context)),
                  const SizedBox(height: 16),
                  Text(config.tenant?.displayName ?? auth.branding?.companyName ?? config.displayName,
                      textAlign: TextAlign.center, style: theme.textTheme.headlineSmall),
                  const SizedBox(height: 4),
                  Text('Inicia sesión con la cuenta que te entregó RRHH',
                      textAlign: TextAlign.center, style: TextStyle(color: theme.colorScheme.onSurfaceVariant)),
                  const SizedBox(height: 24),
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
                    child: TextButton(onPressed: _forgotPassword, child: const Text('Olvidé mi contraseña')),
                  ),
                  const SizedBox(height: 8),
                  FilledButton(
                    onPressed: _loading ? null : _submit,
                    child: _loading
                        ? const SizedBox.square(dimension: 22, child: CircularProgressIndicator(strokeWidth: 2.5))
                        : const Text('Ingresar'),
                  ),
                  // Solo si la app ya recuerda una empresa (otra persona quiere entrar con la suya)
                  if (!controller.hasFixedTenant && auth.status == AuthStatus.unauthenticated) ...[
                    const SizedBox(height: 16),
                    TextButton.icon(
                      onPressed: _loading ? null : controller.changeTenant,
                      icon: const Icon(Icons.swap_horiz),
                      label: const Text('Entrar con otra empresa'),
                    ),
                  ],
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _Banner extends StatelessWidget {
  const _Banner({required this.text, required this.color});

  final String text;
  final Color color;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(color: color, borderRadius: BorderRadius.circular(8)),
        child: Text(text),
      );
}
