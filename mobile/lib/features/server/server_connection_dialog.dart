import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/config/app_config_controller.dart';
import '../../core/config/env.dart';
import '../../core/network/server_url.dart';

/// "Conexión del servidor": diálogo oculto (mantener presionado el logo en
/// la pantalla de empresa o de login) para que los testers apunten la app a
/// otro backend, por ejemplo un túnel https://xxxx.trycloudflare.com.
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

  Future<void> _save() async {
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
      title: const Text('Conexión del servidor'),
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
              labelText: 'URL del servidor',
              hintText: Env.allowsHttp ? 'https://… o http://…' : 'https://xxxx.trycloudflare.com',
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
