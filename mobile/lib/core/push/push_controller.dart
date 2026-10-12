import 'dart:async';

import 'package:firebase_core/firebase_core.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../features/auth/presentation/auth_controller.dart';
import '../../features/notifications/presentation/notifications_providers.dart';
import '../config/app_config_controller.dart';
import '../config/version_gate.dart';
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
  StreamSubscription<Map<String, dynamic>>? _receivedSub;
  String? _lastSentToken;

  @override
  PushStatus build() {
    ref.onDispose(() {
      _tokenSub?.cancel();
      _receivedSub?.cancel();
      _retry?.cancel();
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
    _receivedSub ??= service.received.listen((data) {
      if (!ref.mounted) return;
      ref.invalidate(unreadCountProvider);
      // Nueva versión publicada: recarga la configuración y vuelve a mostrar el aviso
      if (isAppUpdatePush(data)) {
        ref.read(updateBannerDismissedProvider.notifier).show();
        unawaited(ref.read(appConfigProvider.notifier).refresh());
      }
    });
    final token = await service.token();
    if (token != null) {
      await _register(token);
    } else {
      _retryLater();
    }
  }

  Timer? _retry;
  int _retries = 0;

  /// Google a veces no entrega el token al primer intento (sin red, Play
  /// Services ocupado): se reintenta unas veces antes de esperar al próximo arranque.
  void _retryLater() {
    if (_retries >= 3 || (_retry?.isActive ?? false)) return;
    _retries++;
    _retry = Timer(Duration(seconds: 15 * _retries), () {
      if (ref.mounted) unawaited(_evaluate());
    });
  }

  /// Al volver a la app: revisa si cambió el token o la configuración.
  Future<void> refresh() => _evaluate();

  Future<bool> _register(String token, {bool force = false}) async {
    if (!ref.mounted || (!force && token == _lastSentToken)) return token == _lastSentToken;
    if (ref.read(authControllerProvider).status != AuthStatus.authenticated) return false;
    if (!ref.read(appConfigProvider).config.mobile.fcmEnabled) return false;
    try {
      await ref.read(apiClientProvider).post(ApiEndpoints.refreshFcm, data: {'fcm_token': token});
      _lastSentToken = token;
      _retries = 0;
      return true;
    } catch (_) {
      // Sin red: se reintenta en el próximo arranque, al volver a la app o al rotar el token.
      _retryLater();
      return false;
    }
  }

  /// Manda un push de prueba a este mismo teléfono (con su token actual, así
  /// también corrige un registro viejo en el servidor). Devuelve el mensaje
  /// del servidor; lanza [ApiException] si falla.
  Future<String> sendTestToThisPhone() async {
    final service = ref.read(pushServiceProvider);
    if (service == null || !await service.start()) {
      throw StateError('Las notificaciones no están disponibles en esta versión de la app.');
    }
    final token = await service.token();
    final json = await ref.read(apiClientProvider).post(ApiEndpoints.testPush, data: {'fcm_token': ?token});
    if (token != null) _lastSentToken = token;
    return json['message']?.toString() ?? 'Aviso de prueba enviado.';
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
  AppLifecycleListener? _lifecycle;

  @override
  void initState() {
    super.initState();
    final service = ref.read(pushServiceProvider);
    if (service == null) return;
    _lifecycle = AppLifecycleListener(onResume: () => unawaited(ref.read(pushControllerProvider.notifier).refresh()));
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
    _lifecycle?.dispose();
    _routesSub?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    ref.watch(pushControllerProvider);
    return const SizedBox.shrink();
  }
}
