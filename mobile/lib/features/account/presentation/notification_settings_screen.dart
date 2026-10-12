import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/widgets/async_views.dart';
import '../domain/models.dart';
import 'account_providers.dart';
import 'push_status_card.dart';

/// Qué avisos llegan como notificación al teléfono. Apagar uno solo quita el
/// push: el aviso sigue apareciendo en la bandeja.
class NotificationSettingsScreen extends ConsumerStatefulWidget {
  const NotificationSettingsScreen({super.key});

  @override
  ConsumerState<NotificationSettingsScreen> createState() => _NotificationSettingsScreenState();
}

class _NotificationSettingsScreenState extends ConsumerState<NotificationSettingsScreen> {
  /// Cambios en curso (se muestran al instante y se revierten si falla).
  final Map<String, bool> _pending = {};

  Future<void> _toggle(NotificationPreference p, bool value) async {
    final messenger = ScaffoldMessenger.of(context);
    setState(() => _pending[p.key] = value);
    try {
      await ref.read(accountRepositoryProvider).setPreference(p.key, value);
      ref.invalidate(notificationPreferencesProvider);
      await ref.read(notificationPreferencesProvider.future);
    } catch (e) {
      messenger.showSnackBar(SnackBar(content: Text(e.toString())));
    } finally {
      if (mounted) setState(() => _pending.remove(p.key));
    }
  }

  @override
  Widget build(BuildContext context) {
    final prefs = ref.watch(notificationPreferencesProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Notificaciones')),
      body: prefs.when(
        loading: () => const LoadingView(),
        error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(notificationPreferencesProvider)),
        data: (list) => NotificationPreferencesList(
          preferences: [for (final p in list) _pending.containsKey(p.key) ? p.copyWith(enabled: _pending[p.key]) : p],
          onChanged: _toggle,
          header: const PushStatusCard(),
        ),
      ),
    );
  }
}

class NotificationPreferencesList extends StatelessWidget {
  const NotificationPreferencesList({super.key, required this.preferences, required this.onChanged, this.header});

  final List<NotificationPreference> preferences;
  final void Function(NotificationPreference, bool) onChanged;

  /// Estado de los avisos en este teléfono (permiso y prueba).
  final Widget? header;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
      children: [
        if (header != null) ...[header!, const SizedBox(height: 12)],
        Padding(
          padding: const EdgeInsets.fromLTRB(4, 4, 4, 12),
          child: Text(
            'Elige qué avisos te llegan al teléfono. Lo que apagues seguirá en tu bandeja de notificaciones.',
            style: TextStyle(color: scheme.onSurfaceVariant),
          ),
        ),
        Card(
          child: Column(children: [
            for (final p in preferences)
              SwitchListTile(
                key: Key('pref-${p.key}'),
                title: Text(p.title),
                subtitle: Text(p.description),
                value: p.enabled,
                onChanged: (v) => onChanged(p, v),
              ),
          ]),
        ),
        const SizedBox(height: 12),
        ListTile(
          leading: Icon(Icons.shield_outlined, color: scheme.primary),
          title: const Text('Alertas de seguridad'),
          subtitle: const Text('Inicios de sesión nuevos y cambios de contraseña. Siempre activas para proteger tu cuenta.'),
        ),
      ],
    );
  }
}
