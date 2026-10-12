import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/push/push_controller.dart';
import '../../../core/push/push_options.dart';

/// Permiso de notificaciones de este teléfono (se vuelve a leer al regresar a la app).
final pushPermissionProvider = FutureProvider.autoDispose<PushPermission>((ref) async {
  final service = ref.watch(pushServiceProvider);
  if (service == null || !await service.start()) return PushPermission.unknown;
  return service.permission();
});

/// Abre los ajustes de notificaciones de la app (Android: canal nativo; iOS: Ajustes).
Future<void> openNotificationSettings() async {
  try {
    if (defaultTargetPlatform == TargetPlatform.android) {
      await const MethodChannel('tunesa/notifications').invokeMethod<bool>('openSettings');
    } else if (defaultTargetPlatform == TargetPlatform.iOS) {
      await launchUrl(Uri.parse('app-settings:'));
    }
  } catch (_) {
    // sin ajustes que abrir: el texto de la tarjeta explica qué hacer
  }
}

/// Estado de los avisos en este teléfono, arriba de Perfil > Notificaciones:
/// si el sistema los bloquea, un botón para permitirlos; si no, una prueba.
class PushStatusCard extends ConsumerStatefulWidget {
  const PushStatusCard({super.key});

  @override
  ConsumerState<PushStatusCard> createState() => _PushStatusCardState();
}

class _PushStatusCardState extends ConsumerState<PushStatusCard> {
  late final AppLifecycleListener _lifecycle;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    // Vuelve de los ajustes del sistema: el permiso pudo cambiar
    _lifecycle = AppLifecycleListener(onResume: () => ref.invalidate(pushPermissionProvider));
  }

  @override
  void dispose() {
    _lifecycle.dispose();
    super.dispose();
  }

  Future<void> _allow() async {
    setState(() => _busy = true);
    final result = await ref.read(pushServiceProvider)?.requestPermission() ?? PushPermission.unknown;
    if (result != PushPermission.granted) await openNotificationSettings();
    if (!mounted) return;
    ref.invalidate(pushPermissionProvider);
    setState(() => _busy = false);
  }

  Future<void> _test() async {
    final messenger = ScaffoldMessenger.of(context);
    setState(() => _busy = true);
    try {
      final message = await ref.read(pushControllerProvider.notifier).sendTestToThisPhone();
      messenger.showSnackBar(SnackBar(content: Text(message)));
    } on ApiException catch (e) {
      messenger.showSnackBar(SnackBar(content: Text(e.message)));
    } catch (e) {
      messenger.showSnackBar(SnackBar(content: Text(e is StateError ? e.message : 'No se pudo enviar el aviso de prueba.')));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final status = ref.watch(pushControllerProvider);
    final permission = ref.watch(pushPermissionProvider).value;

    return PushStatusView(
      status: status,
      permission: permission,
      busy: _busy,
      onAllow: _allow,
      onTest: _test,
    );
  }
}

/// Vista pura de la tarjeta (para pruebas).
class PushStatusView extends StatelessWidget {
  const PushStatusView({
    super.key,
    required this.status,
    required this.permission,
    required this.onAllow,
    required this.onTest,
    this.busy = false,
  });

  final PushStatus status;

  /// null = consultando.
  final PushPermission? permission;
  final VoidCallback onAllow;
  final VoidCallback onTest;
  final bool busy;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    final (IconData icon, Color color, String title, String body, Widget? action) = switch (status) {
      PushStatus.unavailable => (
          Icons.notifications_off_outlined,
          scheme.onSurfaceVariant,
          'Avisos no disponibles',
          'Esta versión de la app no tiene las notificaciones configuradas. Los avisos siguen llegando a tu bandeja.',
          null,
        ),
      PushStatus.disabledByCompany => (
          Icons.notifications_paused_outlined,
          scheme.onSurfaceVariant,
          'Avisos desactivados por tu empresa',
          'Los avisos siguen llegando a tu bandeja de notificaciones.',
          null,
        ),
      _ when permission == PushPermission.denied || permission == PushPermission.notDetermined => (
          Icons.notifications_off_outlined,
          scheme.error,
          'Tu teléfono bloquea los avisos',
          'Permite las notificaciones de la app para recibir avisos aunque no la tengas abierta.',
          FilledButton.icon(
            key: const Key('push-allow'),
            onPressed: busy ? null : onAllow,
            icon: const Icon(Icons.notifications_active_outlined),
            label: const Text('Permitir notificaciones'),
          ),
        ),
      _ => (
          Icons.notifications_active_outlined,
          scheme.primary,
          'Este teléfono recibe avisos',
          '¿No te llega nada? Envía una prueba: debería aparecer en unos segundos.',
          OutlinedButton.icon(
            key: const Key('push-test'),
            onPressed: busy || permission == null ? null : onTest,
            icon: const Icon(Icons.send_outlined),
            label: const Text('Enviar aviso de prueba'),
          ),
        ),
    };

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Icon(icon, color: color),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(title, style: Theme.of(context).textTheme.titleSmall),
                      const SizedBox(height: 2),
                      Text(body, style: TextStyle(color: scheme.onSurfaceVariant)),
                    ],
                  ),
                ),
              ],
            ),
            if (action != null) ...[const SizedBox(height: 12), action],
          ],
        ),
      ),
    );
  }
}
