import 'dart:async';

import 'package:firebase_core/firebase_core.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../features/auth/presentation/auth_controller.dart';
import '../../features/notifications/presentation/notifications_providers.dart';
import '../config/app_config_controller.dart';
import '../network/api_endpoints.dart';
import '../providers.dart';
import 'push_options.dart';
import 'push_service.dart';

/// Estado del push en este teléfono.
enum PushStatus {
  /// Build sin opciones de Firebase (FIREBASE_* vacíos) o plataforma sin soporte.
  unavailable,

  /// La empresa apagó el push (`mobile.fcm_enabled = false`).
  disabledByCompany,

  /// Sin sesión: no se registra token.
  signedOut,

  /// Se registra el token con `POST /auth/refresh-fcm`.
  enabled,
}

/// Decide si se activa el push (pura, para pruebas).
PushStatus pushAvailability({required bool hasOptions, required bool fcmEnabled, required bool authenticated}) {
  if (!hasOptions) return PushStatus.unavailable;
  if (!fcmEnabled) return PushStatus.disabledByCompany;
  if (!authenticated) return PushStatus.signedOut;
  return PushStatus.enabled;
}

/// Opciones compiladas; null = push apagado sin errores.
final firebaseOptionsProvider = Provider<FirebaseOptions?>(
  (ref) => kIsWeb ? null : firebaseOptionsFor(defaultTargetPlatform, FirebaseDefines.compiled),
);

final pushServiceProvider = Provider<PushService?>((ref) {
  final options = ref.watch(firebaseOptionsProvider);
  if (options == null) return null;
  final service = PushService(options);
  ref.onDispose(service.dispose);
  return service;
});

/// Registra el token FCM tras iniciar sesión y cada vez que rota; lo borra al
/// cerrar sesión.
class PushController extends Notifier<PushStatus> {
  StreamSubscription<String>? _tokenSub;
  StreamSubscription<void>? _receivedSub;
  String? _lastSentToken;

  @override
  PushStatus build() {
    ref.onDispose(() {
      _tokenSub?.cancel();
      _receivedSub?.cancel();
    });
    ref.listen<int?>(
      authControllerProvider.select((s) => s.status == AuthStatus.authenticated ? s.user?.id : null),
      (previous, next) {
        if (previous != null && next == null) {
          _lastSentToken = null;
          unawaited(ref.read(pushServiceProvider)?.deleteToken());
        }
        Future.microtask(_evaluate);
      },
      fireImmediately: true,
    );
    ref.listen(appConfigProvider.select((s) => s.config.mobile.fcmEnabled), (_, _) => Future.microtask(_evaluate));
    return ref.read(pushServiceProvider) == null ? PushStatus.unavailable : PushStatus.signedOut;
  }

  Future<void> _evaluate() async {
    if (!ref.mounted) return;
    final service = ref.read(pushServiceProvider);
    final auth = ref.read(authControllerProvider);
    final status = pushAvailability(
      hasOptions: service != null,
      fcmEnabled: ref.read(appConfigProvider).config.mobile.fcmEnabled,
      authenticated: auth.status == AuthStatus.authenticated,
    );
    state = status;
    if (status != PushStatus.enabled || service == null) return;

    if (!await service.start() || !ref.mounted) return;
    _tokenSub ??= service.tokenRefresh.listen(_register);
    _receivedSub ??= service.received.listen((_) {
      if (ref.mounted) ref.invalidate(unreadCountProvider);
    });
    final token = await service.token();
    if (token != null) await _register(token);
  }

  Future<void> _register(String token) async {
    if (!ref.mounted || token == _lastSentToken) return;
    if (ref.read(authControllerProvider).status != AuthStatus.authenticated) return;
    if (!ref.read(appConfigProvider).config.mobile.fcmEnabled) return;
    try {
      await ref.read(apiClientProvider).post(ApiEndpoints.refreshFcm, data: {'fcm_token': token});
      _lastSentToken = token;
    } catch (_) {
      // Sin red: se reintenta en el próximo arranque o rotación del token.
    }
  }
}

final pushControllerProvider = NotifierProvider<PushController, PushStatus>(PushController.new);

/// Activa el push dentro del shell autenticado y navega al tocar un aviso
/// (misma lógica que la bandeja: pestañas con `go`, el resto con `push`).
class PushListener extends ConsumerStatefulWidget {
  const PushListener({super.key});

  @override
  ConsumerState<PushListener> createState() => _PushListenerState();
}

class _PushListenerState extends ConsumerState<PushListener> {
  static const _tabRoutes = {'/home', '/history', '/requests', '/payslips', '/profile'};

  StreamSubscription<String>? _routesSub;

  @override
  void initState() {
    super.initState();
    final service = ref.read(pushServiceProvider);
    if (service == null) return;
    _routesSub = service.routes.listen((_) => _drain());
    WidgetsBinding.instance.addPostFrameCallback((_) => _drain());
  }

  void _drain() {
    final route = ref.read(pushServiceProvider)?.takePendingRoute();
    if (route == null || !mounted) return;
    final router = GoRouter.of(context);
    if (_tabRoutes.contains(route)) {
      router.go(route);
    } else {
      router.push(route);
    }
  }

  @override
  void dispose() {
    _routesSub?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    ref.watch(pushControllerProvider);
    return const SizedBox.shrink();
  }
}
