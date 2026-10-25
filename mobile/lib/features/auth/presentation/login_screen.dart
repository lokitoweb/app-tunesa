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

  /// La app no restablece contraseñas sola: avisa a RRHH, que da una temporal.
  Future<void> _forgotPassword() => showDialog<void>(
        context: context,
        builder: (_) => _ForgotPasswordDialog(
          initialEmail: _email.text.trim(),
          send: (email) => ref.read(authRepositoryProvider).requestPasswordHelp(email),
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
                  // Logo oficial (incluido en la app)
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

/// "Olvidé mi contraseña": con el correo, la app avisa a RRHH. Al terminar
/// muestra los siguientes pasos (contraseña temporal que se cambia al entrar).
class _ForgotPasswordDialog extends StatefulWidget {
  const _ForgotPasswordDialog({required this.initialEmail, required this.send});

  final String initialEmail;
  final Future<String> Function(String email) send;

  @override
  State<_ForgotPasswordDialog> createState() => _ForgotPasswordDialogState();
}

class _ForgotPasswordDialogState extends State<_ForgotPasswordDialog> {
  late final _email = TextEditingController(text: widget.initialEmail);
  bool _sending = false;
  String? _error;
  String? _done;

  @override
  void dispose() {
    _email.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_email.text.contains('@')) {
      setState(() => _error = 'Ingresa un correo válido');
      return;
    }
    setState(() {
      _sending = true;
      _error = null;
    });
    try {
      final message = await widget.send(_email.text);
      if (mounted) setState(() => _done = message);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.fieldError('email') ?? e.message);
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final done = _done;
    if (done != null) {
      return AlertDialog(
        key: const Key('password-help-done'),
        icon: const Icon(Icons.mark_email_read_outlined),
        title: const Text('Pedido enviado'),
        content: Text(done),
        actions: [FilledButton(onPressed: () => Navigator.pop(context), child: const Text('Entendido'))],
      );
    }
    return AlertDialog(
      icon: const Icon(Icons.support_agent_rounded),
      title: const Text('¿Olvidaste tu contraseña?'),
      content: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        const Text('Escribe tu correo y avisaremos a RR. HH. Te darán una contraseña temporal que cambiarás al ingresar.'),
        const SizedBox(height: 16),
        TextField(
          key: const Key('password-help-email'),
          controller: _email,
          keyboardType: TextInputType.emailAddress,
          autocorrect: false,
          decoration: InputDecoration(labelText: 'Correo', prefixIcon: const Icon(Icons.mail_outline), errorText: _error),
          onSubmitted: (_) => _submit(),
        ),
      ]),
      actions: [
        TextButton(onPressed: _sending ? null : () => Navigator.pop(context), child: const Text('Cancelar')),
        FilledButton(
          key: const Key('password-help-send'),
          onPressed: _sending ? null : _submit,
          child: _sending
              ? const SizedBox.square(dimension: 18, child: CircularProgressIndicator(strokeWidth: 2))
              : const Text('Avisar a RR. HH.'),
        ),
      ],
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
