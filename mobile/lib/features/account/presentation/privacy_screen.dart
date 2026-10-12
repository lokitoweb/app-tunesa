import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../core/config/app_config_controller.dart';
import '../../../core/network/server_url.dart';
import 'account_providers.dart';

/// Mi perfil › Privacidad y datos: qué datos usa la app, la política de
/// privacidad y el pedido de eliminación de la cuenta (exigidos por Google Play).
class PrivacyScreen extends ConsumerWidget {
  const PrivacyScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final legal = ref.watch(appConfigProvider).config.legal;
    final base = ref.watch(baseUrlProvider);
    final privacyUrl = legal.privacyPolicyUrl ?? '$base/privacidad';
    final scheme = Theme.of(context).colorScheme;

    return Scaffold(
      appBar: AppBar(title: const Text('Privacidad y datos')),
      body: SafeArea(
        top: false,
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            const Card(
              child: Padding(
                padding: EdgeInsets.all(16),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  _DataLine(icon: Icons.location_on_outlined, text: 'Tu ubicación, solo mientras marcas. Nunca en segundo plano.'),
                  _DataLine(icon: Icons.photo_camera_front_outlined, text: 'La selfie de cada marcación y las fotos que adjuntas.'),
                  _DataLine(icon: Icons.badge_outlined, text: 'Tus datos laborales, documentos, pagos y tallas.'),
                  _DataLine(icon: Icons.phone_android_rounded, text: 'Datos del teléfono para proteger tu cuenta y enviarte avisos.'),
                ]),
              ),
            ),
            const SizedBox(height: 12),
            Card(
              child: Column(children: [
                ListTile(
                  key: const Key('privacy-policy'),
                  leading: const Icon(Icons.policy_outlined),
                  title: const Text('Política de privacidad'),
                  trailing: const Icon(Icons.open_in_new_rounded),
                  onTap: () => _open(context, privacyUrl),
                ),
                const Divider(),
                ListTile(
                  key: const Key('privacy-delete-account'),
                  leading: Icon(Icons.person_remove_outlined, color: scheme.error),
                  title: Text('Eliminar mi cuenta', style: TextStyle(color: scheme.error)),
                  subtitle: const Text('RRHH borra tu cuenta y tus datos en máximo 30 días'),
                  onTap: () => _requestDeletion(context, ref),
                ),
              ]),
            ),
          ],
        ),
      ),
    );
  }

  static Future<void> _open(BuildContext context, String url) async {
    final messenger = ScaffoldMessenger.of(context);
    var opened = false;
    try {
      opened = await launchUrl(Uri.parse(url), mode: LaunchMode.externalApplication);
    } catch (_) {}
    if (!opened) messenger.showSnackBar(SnackBar(content: Text('No se pudo abrir $url')));
  }

  static Future<void> _requestDeletion(BuildContext context, WidgetRef ref) async {
    final messenger = ScaffoldMessenger.of(context);
    final reason = await showDialog<String>(context: context, builder: (_) => const _DeletionDialog());
    if (reason == null) return;
    try {
      final message = await ref.read(accountRepositoryProvider).requestDeletion(reason: reason);
      messenger.showSnackBar(SnackBar(content: Text(message), duration: const Duration(seconds: 6)));
    } catch (e) {
      messenger.showSnackBar(SnackBar(content: Text(e.toString())));
    }
  }
}

class _DataLine extends StatelessWidget {
  const _DataLine({required this.icon, required this.text});

  final IconData icon;
  final String text;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 6),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Icon(icon, size: 20, color: Theme.of(context).colorScheme.primary),
          const SizedBox(width: 12),
          Expanded(child: Text(text)),
        ]),
      );
}

/// Confirma el pedido; devuelve el motivo (puede ser vacío) o null si se cancela.
class _DeletionDialog extends StatefulWidget {
  const _DeletionDialog();

  @override
  State<_DeletionDialog> createState() => _DeletionDialogState();
}

class _DeletionDialogState extends State<_DeletionDialog> {
  final _reason = TextEditingController();

  @override
  void dispose() {
    _reason.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return AlertDialog(
      title: const Text('¿Eliminar tu cuenta?'),
      content: SingleChildScrollView(
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
          const Text(
            'RRHH de tu empresa eliminará tu cuenta, tus fotos y tus documentos. Por ley se conservan '
            'las planillas, boletas y marcaciones el tiempo que exige la normativa laboral.',
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _reason,
            maxLength: 1000,
            maxLines: 3,
            minLines: 1,
            decoration: const InputDecoration(labelText: 'Motivo (opcional)'),
          ),
        ]),
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context), child: const Text('Cancelar')),
        FilledButton(
          key: const Key('confirm-delete-account'),
          style: FilledButton.styleFrom(backgroundColor: scheme.error, foregroundColor: scheme.onError),
          onPressed: () => Navigator.pop(context, _reason.text),
          child: const Text('Pedir eliminación'),
        ),
      ],
    );
  }
}
