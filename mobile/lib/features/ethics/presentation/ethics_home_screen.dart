import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/widgets/page_padding.dart';
import '../domain/models.dart';
import 'ethics_providers.dart';
import '../../../core/widgets/skeleton.dart';

/// Inicio del canal ético: cómo funciona, nueva denuncia y las guardadas en este celular.
class EthicsHomeScreen extends ConsumerWidget {
  const EthicsHomeScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final scheme = Theme.of(context).colorScheme;
    final saved = ref.watch(savedEthicsCasesProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Canal ético')),
      body: ListView(
        padding: pagePadding(context),
        children: [
          Card(
            color: scheme.secondaryContainer,
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Row(children: [
                  Icon(Icons.shield_outlined, color: scheme.onSecondaryContainer),
                  const SizedBox(width: 8),
                  Text('Tu denuncia es anónima',
                      style: TextStyle(fontWeight: FontWeight.w700, fontSize: 16, color: scheme.onSecondaryContainer)),
                ]),
                const SizedBox(height: 8),
                Text(
                  'No enviamos tu nombre, tu cuenta, tu ubicación ni datos de tu celular. '
                  'Recibirás un código y un token: con ellos sigues el caso y conversas con quien lo investiga.',
                  style: TextStyle(color: scheme.onSecondaryContainer, height: 1.35),
                ),
              ]),
            ),
          ),
          const SizedBox(height: 16),
          SizedBox(
            height: 52,
            child: FilledButton.icon(
              key: const Key('ethics-new'),
              onPressed: () async {
                await context.push('/ethics/new');
                ref.invalidate(savedEthicsCasesProvider);
              },
              icon: const Icon(Icons.campaign_outlined),
              label: const Text('Hacer una denuncia'),
            ),
          ),
          const SizedBox(height: 8),
          OutlinedButton.icon(
            key: const Key('ethics-lookup'),
            onPressed: () => _lookup(context),
            icon: const Icon(Icons.key_outlined),
            label: const Text('Consultar con código y clave'),
          ),
          const SizedBox(height: 24),
          Text('Guardadas en este celular', style: Theme.of(context).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
          const SizedBox(height: 8),
          ...switch (saved.value) {
            null => const [Shimmer(child: SkeletonTile(leading: SkeletonLeading.square))],
            [] => [
                Text('Ninguna. Si guardas una denuncia, aparecerá aquí para consultarla sin escribir la clave.',
                    style: TextStyle(color: scheme.onSurfaceVariant)),
              ],
            final list => [
                for (final c in list)
                  Card(
                    child: ListTile(
                      key: Key('ethics-saved-${c.code}'),
                      leading: const Icon(Icons.folder_shared_outlined),
                      title: Text(c.subject.isEmpty ? c.code : c.subject, maxLines: 1, overflow: TextOverflow.ellipsis),
                      subtitle: Text(c.code),
                      trailing: IconButton(
                        tooltip: 'Quitar de este celular',
                        icon: const Icon(Icons.delete_outline),
                        onPressed: () => _remove(context, ref, c),
                      ),
                      onTap: () => context.push('/ethics/case', extra: c),
                    ),
                  ),
              ],
          },
        ],
      ),
    );
  }

  Future<void> _lookup(BuildContext context) async {
    final code = TextEditingController();
    final token = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => AlertDialog(
        title: const Text('Consultar denuncia'),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          TextField(
            key: const Key('lookup-code'),
            controller: code,
            textCapitalization: TextCapitalization.characters,
            decoration: const InputDecoration(labelText: 'Código', hintText: '123456'),
          ),
          const SizedBox(height: 12),
          TextField(
            key: const Key('lookup-token'),
            controller: token,
            textCapitalization: TextCapitalization.characters,
            decoration: const InputDecoration(labelText: 'Clave', hintText: 'XXXX-XXXX'),
          ),
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.pop(c, false), child: const Text('Cancelar')),
          FilledButton(onPressed: () => Navigator.pop(c, true), child: const Text('Consultar')),
        ],
      ),
    );
    if (ok == true && code.text.trim().isNotEmpty && token.text.trim().isNotEmpty && context.mounted) {
      await context.push('/ethics/case', extra: SavedEthicsCase(code: code.text.trim().toUpperCase(), token: token.text.trim(), subject: ''));
    }
  }

  Future<void> _remove(BuildContext context, WidgetRef ref, SavedEthicsCase c) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (d) => AlertDialog(
        title: const Text('¿Quitar de este celular?'),
        content: Text('La denuncia sigue abierta. Para volver a verla necesitarás el código ${c.code} y su clave.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(d, false), child: const Text('Cancelar')),
          FilledButton(onPressed: () => Navigator.pop(d, true), child: const Text('Quitar')),
        ],
      ),
    );
    if (ok != true) return;
    await ref.read(ethicsVaultProvider).remove(c.code);
    if (c.channel != null) await ref.read(ethicsTopicsProvider).unsubscribe(c.channel!);
    ref.invalidate(savedEthicsCasesProvider);
  }
}
