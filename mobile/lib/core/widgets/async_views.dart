import 'package:flutter/material.dart';

import '../network/api_exception.dart';
import 'skeleton.dart';

/// Mensaje de error con botón de reintento. Distingue falta de conexión y
/// falta de permisos (403). El 401 ya lo resuelve ApiClient cerrando sesión.
class ErrorView extends StatelessWidget {
  const ErrorView({super.key, required this.error, this.onRetry});

  final Object error;
  final VoidCallback? onRetry;

  static const forbiddenMessage = 'No tienes permiso para ver esta sección. Pídele a RR. HH. que revise los permisos de tu usuario.';
  static const moduleDisabledMessage = 'Esta sección no está activa para tu empresa. Pídele a RR. HH. que la active.';
  static const tenantInactiveMessage = 'La cuenta de tu empresa está suspendida. Comunícate con RR. HH.';

  /// Texto de un 403 según el motivo que envía el backend (`code`), para que
  /// la persona y RR. HH. sepan qué revisar.
  static String forbiddenText(ApiException api) => switch (api.code) {
        'MODULE_DISABLED' => moduleDisabledMessage,
        'TENANT_INACTIVE' => tenantInactiveMessage,
        // Contraseña temporal o incorporación pendiente: el backend explica el paso que falta
        'PASSWORD_CHANGE_REQUIRED' || 'ONBOARDING_PENDING' => api.message,
        _ => forbiddenMessage,
      };

  @override
  Widget build(BuildContext context) {
    final api = error is ApiException ? error as ApiException : null;
    final isNetwork = api?.isNetwork ?? false;
    final isForbidden = api?.isForbidden ?? false;
    final message = isForbidden ? forbiddenText(api!) : error.toString();

    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(
                isNetwork
                    ? Icons.wifi_off_rounded
                    : (isForbidden ? Icons.lock_outline_rounded : Icons.error_outline_rounded),
                size: 48,
                color: Theme.of(context).colorScheme.error),
            const SizedBox(height: 12),
            Text(message, textAlign: TextAlign.center),
            if (onRetry != null && !isForbidden) ...[
              const SizedBox(height: 16),
              OutlinedButton.icon(onPressed: onRetry, icon: const Icon(Icons.refresh), label: const Text('Reintentar')),
            ],
          ],
        ),
      ),
    );
  }
}

/// Carga genérica con skeleton: con [message] (descargar un PDF) dibuja una
/// hoja; sin él, una lista de tarjetas. Las pantallas principales usan su
/// propia silueta (ver skeleton.dart) para calcar su diseño.
class LoadingView extends StatelessWidget {
  const LoadingView({super.key, this.message});

  final String? message;

  @override
  Widget build(BuildContext context) =>
      message != null ? SkeletonDocument(message: message) : const SkeletonList();
}

class EmptyView extends StatelessWidget {
  const EmptyView({super.key, required this.icon, required this.message});

  final IconData icon;
  final String message;

  @override
  Widget build(BuildContext context) => Center(
        child: Padding(
          padding: const EdgeInsets.all(32),
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            Icon(icon, size: 48, color: Theme.of(context).colorScheme.outline),
            const SizedBox(height: 12),
            Text(message, textAlign: TextAlign.center),
          ]),
        ),
      );
}

/// Banner fijo cuando el teléfono no tiene red.
class OfflineBanner extends StatelessWidget {
  const OfflineBanner({super.key, this.offlineClocking = false});

  /// La empresa permite marcar sin conexión (se envía al recuperar señal).
  final bool offlineClocking;

  @override
  Widget build(BuildContext context) => Material(
        color: Theme.of(context).colorScheme.errorContainer,
        child: SafeArea(
          bottom: false,
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
            child: Row(children: [
              Icon(Icons.wifi_off_rounded, size: 18, color: Theme.of(context).colorScheme.onErrorContainer),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                    offlineClocking
                        ? 'Sin conexión. Tus marcaciones se guardarán y se enviarán al recuperar señal.'
                        : 'Sin conexión. Para marcar necesitas internet.',
                    style: TextStyle(color: Theme.of(context).colorScheme.onErrorContainer)),
              ),
            ]),
          ),
        ),
      );
}

void showSnack(BuildContext context, String message, {bool error = false}) {
  final scheme = Theme.of(context).colorScheme;
  ScaffoldMessenger.of(context)
    ..hideCurrentSnackBar()
    ..showSnackBar(SnackBar(
      content: Text(message),
      backgroundColor: error ? scheme.error : null,
      behavior: SnackBarBehavior.floating,
    ));
}

/// Pie de una lista paginada: indicador mientras carga la siguiente página,
/// error con reintento, o botón "Cargar más" si quedan páginas.
class LoadMoreFooter extends StatelessWidget {
  const LoadMoreFooter({
    super.key,
    required this.hasMore,
    required this.loading,
    required this.onLoadMore,
    this.error,
  });

  final bool hasMore;
  final bool loading;
  final Object? error;
  final VoidCallback onLoadMore;

  @override
  Widget build(BuildContext context) {
    if (loading) {
      // La siguiente página asoma como una tarjeta más, sin spinner
      return const Padding(
        padding: EdgeInsets.only(top: 10),
        child: Shimmer(child: SkeletonTile(seed: 3)),
      );
    }
    if (error != null) {
      return Padding(
        padding: const EdgeInsets.all(12),
        child: Column(children: [
          Text(error.toString(), textAlign: TextAlign.center, style: TextStyle(color: Theme.of(context).colorScheme.error)),
          TextButton.icon(onPressed: onLoadMore, icon: const Icon(Icons.refresh), label: const Text('Reintentar')),
        ]),
      );
    }
    if (!hasMore) return const SizedBox(height: 8);
    return Center(
      child: TextButton(onPressed: onLoadMore, child: const Text('Cargar más')),
    );
  }
}

/// Llama a [onLoadMore] cuando el usuario se acerca al final de la lista
/// (scroll infinito). El controlador evita cargas duplicadas.
bool loadMoreOnScroll(ScrollNotification n, VoidCallback onLoadMore) {
  if (n.metrics.axis == Axis.vertical && n.metrics.extentAfter < 300) onLoadMore();
  return false;
}
