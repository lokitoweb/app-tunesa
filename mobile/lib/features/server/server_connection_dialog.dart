import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/config/app_config_controller.dart';
import '../../core/config/env.dart';
import '../../core/network/server_url.dart';

/// "Conectar con tu empresa" (enlace en el login o mantener presionado el
/// logo): un mismo APK sirve a cualquier empresa. Se escribe el dominio del
/// servidor (erp.miempresa.com) o el código de la empresa; la app comprueba
/// que responda, guarda el servidor y aplica la marca de esa empresa.
Future<void> showServerConnectionDialog(BuildContext context) =>
    showDialog<void>(context: context, builder: (_) => const ServerConnectionDialog());

class ServerConnectionDialog extends ConsumerStatefulWidget {
  const ServerConnectionDialog({super.key});

  @override
  ConsumerState<ServerConnectionDialog> createState() => _ServerConnectionDialogState();
}

class _ServerConnectionDialogState extends ConsumerState<ServerConnectionDialog> {
  late final TextEditingController _url = TextEditingController(text: ref.read(baseUrlProvider));
  String? _error;
  bool? _healthy;
  bool _busy = false;

  @override
  void dispose() {
    _url.dispose();
    super.dispose();
  }

  /// Un código de empresa no tiene puntos ni esquema ("acme", "grupo-norte")
  static final _codePattern = RegExp(r'^[a-z0-9][a-z0-9_-]{1,49}$');

  bool get _isCompanyCode => _codePattern.hasMatch(_url.text.trim().toLowerCase());

  String? _validated() {
    final check = ServerUrl.validate(_url.text);
    setState(() {
      _error = check.error;
      _healthy = null;
    });
    return check.url;
  }

  Future<bool> _test(String url) async {
    setState(() => _busy = true);
    try {
      final ok = await ref.read(serverHealthCheckProvider)(url);
      if (mounted) setState(() => _healthy = ok);
      return ok;
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  /// Código de empresa: se busca en el servidor actual. Si la empresa tiene
  /// su propio servidor (`mobile.api_base_url`), la app se cambia sola.
  Future<void> _connectCompany() async {
    setState(() {
      _busy = true;
      _error = null;
      _healthy = null;
    });
    try {
      final config = await ref.read(appConfigProvider.notifier).loadForTenant(_url.text.trim().toLowerCase());
      if (!mounted) return;
      final messenger = ScaffoldMessenger.maybeOf(context);
      Navigator.of(context).pop();
      messenger?.showSnackBar(SnackBar(content: Text('Conectado con ${config.displayName}.')));
    } catch (_) {
      if (mounted) setState(() => _error = 'No encontramos una empresa activa con ese código.');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _save() async {
    if (_isCompanyCode) return _connectCompany();
    final url = _validated();
    if (url == null) return;
    if (!await _test(url)) return;
    await ref.read(baseUrlProvider.notifier).setOverride(url);
    await ref.read(appConfigProvider.notifier).refresh();
    if (mounted) Navigator.of(context).pop();
  }

  Future<void> _reset() async {
    await ref.read(baseUrlProvider.notifier).reset();
    await ref.read(appConfigProvider.notifier).refresh();
    if (mounted) Navigator.of(context).pop();
  }

  @override
  Widget build(BuildContext context) {
    final controller = ref.read(baseUrlProvider.notifier);
    final current = ref.watch(baseUrlProvider);
    final scheme = Theme.of(context).colorScheme;

    return AlertDialog(
      icon: const Icon(Icons.dns_outlined),
      title: const Text('Conectar con tu empresa'),
      content: SingleChildScrollView(
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text('En uso: $current${controller.hasOverride ? '' : ' (predeterminado)'}',
              style: Theme.of(context).textTheme.bodySmall),
          const SizedBox(height: 12),
          TextField(
            controller: _url,
            keyboardType: TextInputType.url,
            autocorrect: false,
            decoration: InputDecoration(
              labelText: 'Dominio del servidor o código de empresa',
              hintText: Env.allowsHttp ? 'erp.miempresa.com, http://… o acme' : 'erp.miempresa.com o acme',
              helperText: 'Te lo da RRHH de tu empresa.',
              errorText: _error,
            ),
            onChanged: (_) => setState(() => _healthy = null),
          ),
          if (_healthy != null) ...[
            const SizedBox(height: 12),
            Row(children: [
              Icon(_healthy! ? Icons.check_circle : Icons.error_outline,
                  color: _healthy! ? Colors.green : scheme.error, size: 20),
              const SizedBox(width: 8),
              Expanded(child: Text(_healthy! ? 'El servidor responde.' : 'El servidor no responde (se esperaba HTTP 200).')),
            ]),
          ],
        ]),
      ),
      actions: [
        if (controller.hasOverride)
          TextButton(onPressed: _busy ? null : _reset, child: const Text('Restablecer')),
        TextButton(
          onPressed: _busy
              ? null
              : () {
                  if (_isCompanyCode) {
                    _connectCompany();
                    return;
                  }
                  final url = _validated();
                  if (url != null) _test(url);
                },
          child: const Text('Probar'),
        ),
        FilledButton(
          onPressed: _busy ? null : _save,
          child: _busy
              ? const SizedBox.square(dimension: 18, child: CircularProgressIndicator(strokeWidth: 2))
              : const Text('Guardar'),
        ),
      ],
    );
  }
}
