import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/utils/formatters.dart';
import '../../../core/widgets/async_views.dart';
import '../domain/models.dart';
import 'account_providers.dart';
import '../../../core/widgets/skeleton.dart';

/// "Mis dispositivos": dónde está abierta la cuenta, con opción de cerrar cada
/// sesión o todas menos esta.
class DevicesScreen extends ConsumerStatefulWidget {
  const DevicesScreen({super.key});

  @override
  ConsumerState<DevicesScreen> createState() => _DevicesScreenState();
}

class _DevicesScreenState extends ConsumerState<DevicesScreen> {
  bool _busy = false;

  Future<void> _run(Future<String> Function() action) async {
    final messenger = ScaffoldMessenger.of(context);
    setState(() => _busy = true);
    try {
      final msg = await action();
      ref.invalidate(deviceSessionsProvider);
      messenger.showSnackBar(SnackBar(content: Text(msg)));
    } catch (e) {
      messenger.showSnackBar(SnackBar(content: Text(e.toString())));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<bool> _confirm(String title, String body) async =>
      await showDialog<bool>(
        context: context,
        builder: (c) => AlertDialog(
          title: Text(title),
          content: Text(body),
          actions: [
            TextButton(onPressed: () => Navigator.pop(c, false), child: const Text('Cancelar')),
            FilledButton(onPressed: () => Navigator.pop(c, true), child: const Text('Cerrar sesión')),
          ],
        ),
      ) ==
      true;

  Future<void> _revoke(DeviceSession s) async {
    if (!await _confirm('¿Cerrar esta sesión?', '${s.label} tendrá que volver a ingresar con tu contraseña.')) return;
    await _run(() async {
      await ref.read(accountRepositoryProvider).revoke(s.id);
      return 'Sesión cerrada en ${s.label}.';
    });
  }

  Future<void> _logoutOthers() async {
    if (!await _confirm('¿Cerrar las demás sesiones?', 'Solo quedará abierta la sesión de este teléfono.')) return;
    await _run(() async {
      final n = await ref.read(accountRepositoryProvider).logoutOthers();
      return n == 0 ? 'No había otras sesiones abiertas.' : 'Se cerró la sesión en $n dispositivo(s).';
    });
  }

  @override
  Widget build(BuildContext context) {
    final sessions = ref.watch(deviceSessionsProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Mis dispositivos')),
      body: sessions.whenSkeleton(
        loading: () => const SkeletonList(leading: SkeletonLeading.square),
        error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(deviceSessionsProvider)),
        data: (list) => DevicesList(
          sessions: list,
          busy: _busy,
          onRevoke: _revoke,
          onLogoutOthers: _logoutOthers,
          onRefresh: () => ref.refresh(deviceSessionsProvider.future),
        ),
      ),
    );
  }
}

/// Lista de sesiones (separada para probarla con datos fijos).
class DevicesList extends StatelessWidget {
  const DevicesList({
    super.key,
    required this.sessions,
    required this.onRevoke,
    required this.onLogoutOthers,
    required this.onRefresh,
    this.busy = false,
  });

  final List<DeviceSession> sessions;
  final bool busy;
  final void Function(DeviceSession) onRevoke;
  final VoidCallback onLogoutOthers;
  final Future<void> Function() onRefresh;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final others = sessions.where((s) => !s.current).length;

    return RefreshIndicator(
      onRefresh: onRefresh,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(4, 4, 4, 12),
            child: Text(
              'Aquí ves dónde está abierta tu cuenta. Si no reconoces un dispositivo, ciérralo y cambia tu contraseña.',
              style: TextStyle(color: scheme.onSurfaceVariant),
            ),
          ),
          for (final s in sessions)
            Card(
              key: Key('session-${s.id}'),
              margin: const EdgeInsets.only(bottom: 14),
              child: ListTile(
                contentPadding: const EdgeInsets.fromLTRB(18, 10, 10, 10),
                minVerticalPadding: 10,
                leading: Icon(s.label.contains('iPhone') ? Icons.phone_iphone_rounded : Icons.phone_android_rounded),
                title: Text(s.label, maxLines: 1, overflow: TextOverflow.ellipsis),
                subtitle: Text(s.current
                    ? 'Este dispositivo'
                    : 'Último uso: ${s.lastUsedAt == null ? 'sin uso' : '${Fmt.date(s.lastUsedAt)} ${Fmt.time(s.lastUsedAt)}'}'),
                trailing: s.current
                    ? Icon(Icons.check_circle_rounded, color: scheme.primary)
                    : IconButton(
                        tooltip: 'Cerrar sesión',
                        icon: Icon(Icons.logout_rounded, color: scheme.error),
                        onPressed: busy ? null : () => onRevoke(s),
                      ),
              ),
            ),
          const SizedBox(height: 12),
          if (others > 0)
            OutlinedButton.icon(
              key: const Key('logout-others'),
              onPressed: busy ? null : onLogoutOthers,
              icon: const Icon(Icons.devices_other_rounded),
              label: Text('Cerrar sesión en los otros $others'),
            ),
        ],
      ),
    );
  }
}
