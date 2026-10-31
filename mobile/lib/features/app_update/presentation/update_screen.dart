import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../core/config/app_config_controller.dart';
import '../../../core/config/version_gate.dart';
import '../../../core/widgets/brand_logo.dart';

/// Abre el enlace de descarga (tienda o APK) fuera de la app.
Future<void> openUpdateLink(BuildContext context, String url) async {
  final messenger = ScaffoldMessenger.maybeOf(context);
  var opened = false;
  try {
    opened = await launchUrl(Uri.parse(url), mode: LaunchMode.externalApplication);
  } catch (_) {
    opened = false;
  }
  if (!opened) {
    messenger?.showSnackBar(const SnackBar(content: Text('No se pudo abrir el enlace de descarga. Pídeselo a RRHH.')));
  }
}

/// Pantalla bloqueante: la versión instalada ya no está soportada
/// (`force_update` o un 426 APP_UPDATE_REQUIRED). No hay forma de seguir.
class UpdateScreen extends ConsumerStatefulWidget {
  const UpdateScreen({super.key});

  @override
  ConsumerState<UpdateScreen> createState() => _UpdateScreenState();
}

class _UpdateScreenState extends ConsumerState<UpdateScreen> {
  bool _checking = false;

  /// Por si la empresa bajó la versión mínima: se vuelve a consultar.
  Future<void> _retry() async {
    setState(() => _checking = true);
    ref.read(serverUpdateRequiredProvider.notifier).clear();
    await ref.read(appConfigProvider.notifier).refresh();
    if (mounted) setState(() => _checking = false);
  }

  @override
  Widget build(BuildContext context) {
    final config = ref.watch(appConfigProvider.select((s) => s.config));
    final server = ref.watch(serverUpdateRequiredProvider);
    final current = ref.watch(appVersionProvider);
    final minVersion = server?.minSupportedVersion ?? config.mobile.minSupportedVersion;
    final latest = server?.latestVersion ?? config.mobile.latestVersion;
    final contact = config.contact;
    final theme = Theme.of(context);

    return PopScope(
      canPop: false,
      child: Scaffold(
        body: SafeArea(
          child: Center(
            child: SingleChildScrollView(
              padding: const EdgeInsets.all(24),
              child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                const BrandLogo(height: 72),
                const SizedBox(height: 24),
                Icon(Icons.system_update_rounded, size: 48, color: theme.colorScheme.primary),
                const SizedBox(height: 12),
                Text('Actualiza la app', textAlign: TextAlign.center, style: theme.textTheme.headlineSmall),
                const SizedBox(height: 8),
                Text(
                  'Tu versión ($current) ya no es compatible'
                  '${minVersion != null ? '; se necesita la $minVersion o superior' : ''}. '
                  'Instala la versión ${latest ?? 'más reciente'} desde la tienda o el enlace que te dio RRHH.',
                  textAlign: TextAlign.center,
                ),
                if (!contact.isEmpty) ...[
                  const SizedBox(height: 24),
                  Card(
                    child: Column(children: [
                      ListTile(title: Text('¿Necesitas ayuda? ${config.displayName}')),
                      if (contact.email != null)
                        ListTile(leading: const Icon(Icons.mail_outline), title: SelectableText(contact.email!)),
                      if (contact.phone != null)
                        ListTile(leading: const Icon(Icons.phone_outlined), title: SelectableText(contact.phone!)),
                      if (contact.website != null)
                        ListTile(leading: const Icon(Icons.language), title: SelectableText(contact.website!)),
                    ]),
                  ),
                ],
                if (config.mobile.updateUrl != null) ...[
                  const SizedBox(height: 24),
                  FilledButton.icon(
                    onPressed: () => openUpdateLink(context, config.mobile.updateUrl!),
                    icon: const Icon(Icons.download_rounded),
                    label: Text(latest == null ? 'Descargar la nueva versión' : 'Descargar la versión $latest'),
                  ),
                ],
                const SizedBox(height: 24),
                OutlinedButton.icon(
                  onPressed: _checking ? null : _retry,
                  icon: const Icon(Icons.refresh),
                  label: const Text('Ya actualicé, reintentar'),
                ),
              ]),
            ),
          ),
        ),
      ),
    );
  }
}

/// Aviso descartable (una vez por arranque) cuando hay una versión más nueva.
class UpdateBanner extends ConsumerWidget {
  const UpdateBanner({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final level = ref.watch(updateLevelProvider);
    final dismissed = ref.watch(updateBannerDismissedProvider);
    if (level != UpdateLevel.optional || dismissed) return const SizedBox.shrink();

    final latest = ref.watch(appConfigProvider.select((s) => s.config.mobile.latestVersion));
    final updateUrl = ref.watch(appConfigProvider.select((s) => s.config.mobile.updateUrl));
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final dark = theme.brightness == Brightness.dark;

    // Tarjeta flotante bajo la barra de estado: ícono, título, versión y botón
    return SafeArea(
      bottom: false,
      child: Container(
        key: const Key('update-banner'),
        margin: const EdgeInsets.fromLTRB(12, 8, 12, 4),
        padding: const EdgeInsets.fromLTRB(12, 10, 4, 10),
        decoration: BoxDecoration(
          color: dark ? scheme.surfaceContainerHigh : Colors.white,
          borderRadius: BorderRadius.circular(14),
          border: Border.all(color: scheme.primary.withValues(alpha: dark ? 0.35 : 0.18)),
          boxShadow: dark ? null : [BoxShadow(color: scheme.primary.withValues(alpha: 0.12), blurRadius: 16, offset: const Offset(0, 6))],
        ),
        child: Row(children: [
          Container(
            width: 40,
            height: 40,
            decoration: BoxDecoration(color: scheme.primary.withValues(alpha: 0.12), borderRadius: BorderRadius.circular(10)),
            child: Icon(Icons.rocket_launch_rounded, color: scheme.primary, size: 22),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
              Text('Nueva versión disponible', style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w800)),
              const SizedBox(height: 2),
              Text(
                latest == null ? 'Incluye mejoras y correcciones.' : 'Versión $latest con mejoras y correcciones.',
                style: theme.textTheme.bodySmall?.copyWith(color: scheme.onSurfaceVariant),
              ),
            ]),
          ),
          if (updateUrl != null) ...[
            const SizedBox(width: 8),
            FilledButton(
              style: FilledButton.styleFrom(
                visualDensity: VisualDensity.compact,
                padding: const EdgeInsets.symmetric(horizontal: 14),
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
              ),
              onPressed: () => openUpdateLink(context, updateUrl),
              child: const Text('Actualizar'),
            ),
          ],
          IconButton(
            tooltip: 'Ahora no',
            visualDensity: VisualDensity.compact,
            icon: Icon(Icons.close_rounded, color: scheme.onSurfaceVariant, size: 20),
            onPressed: () => ref.read(updateBannerDismissedProvider.notifier).dismiss(),
          ),
        ]),
      ),
    );
  }
}
