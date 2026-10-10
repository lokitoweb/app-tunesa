import 'dart:async';
import 'dart:convert';

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';

import 'push_options.dart';

/// Notificaciones push con Firebase Cloud Messaging.
///
/// - Firebase se inicializa con opciones de `--dart-define` (sin archivos de
///   Google). Cualquier fallo deja el push apagado sin afectar la app.
/// - En primer plano: Android muestra una notificación local (el sistema no
///   lo hace solo); iOS usa las opciones de presentación de FCM.
/// - Al tocar un push se emite la ruta en [routes] (o queda en
///   [takePendingRoute] si la app aún no tiene la navegación lista).
class PushService {
  PushService(this.options);

  final FirebaseOptions options;

  static const _channelId = 'tunesa_default';
  static const _channelName = 'Avisos de TuneSA';

  bool _started = false;
  Future<bool>? _starting;
  FlutterLocalNotificationsPlugin? _local;
  final _subscriptions = <StreamSubscription<Object?>>[];

  final _routes = StreamController<String>.broadcast();
  final _tokens = StreamController<String>.broadcast();
  final _received = StreamController<void>.broadcast();
  String? _pendingRoute;

  /// Ruta a abrir tras tocar un push.
  Stream<String> get routes => _routes.stream;

  /// Token nuevo de FCM (rotación).
  Stream<String> get tokenRefresh => _tokens.stream;

  /// Llegó un push con la app abierta (para refrescar la campana).
  Stream<void> get received => _received.stream;

  bool get isStarted => _started;

  /// Ruta de un toque que aún no se atendió (arranque en frío).
  String? takePendingRoute() {
    final route = _pendingRoute;
    _pendingRoute = null;
    return route;
  }

  Future<bool> start() => _starting ??= _start().then((ok) {
        if (!ok) _starting = null;
        return ok;
      });

  Future<bool> _start() async {
    try {
      if (Firebase.apps.isEmpty) await Firebase.initializeApp(options: options);
      final messaging = FirebaseMessaging.instance;
      // Android 13+ (POST_NOTIFICATIONS) e iOS piden permiso aquí.
      await messaging.requestPermission(alert: true, badge: true, sound: true);

      if (defaultTargetPlatform == TargetPlatform.iOS) {
        await messaging.setForegroundNotificationPresentationOptions(alert: true, badge: true, sound: true);
      } else if (defaultTargetPlatform == TargetPlatform.android) {
        await _initLocalNotifications();
      }

      _subscriptions
        ..add(FirebaseMessaging.onMessage.listen(_onForegroundMessage))
        ..add(FirebaseMessaging.onMessageOpenedApp.listen((m) => _open(m.data)))
        ..add(messaging.onTokenRefresh.listen(_tokens.add));

      final initial = await messaging.getInitialMessage();
      if (initial != null) _open(initial.data);
      _started = true;
      return true;
    } catch (e) {
      debugPrint('Push desactivado: $e');
      return false;
    }
  }

  /// Token actual (null si FCM no lo entrega, p. ej. iOS sin APNs).
  Future<String?> token() async {
    if (!_started) return null;
    try {
      if (defaultTargetPlatform == TargetPlatform.iOS) {
        final apns = await FirebaseMessaging.instance.getAPNSToken();
        if (apns == null) return null;
      }
      return await FirebaseMessaging.instance.getToken();
    } catch (e) {
      debugPrint('Sin token FCM: $e');
      return null;
    }
  }

  /// Al cerrar sesión: invalida el token para que no lleguen avisos de esa cuenta.
  Future<void> deleteToken() async {
    if (!_started) return;
    try {
      await FirebaseMessaging.instance.deleteToken();
    } catch (_) {
      // sin red: el backend lo reemplaza en el próximo login
    }
  }

  Future<void> _initLocalNotifications() async {
    final local = FlutterLocalNotificationsPlugin();
    await local.initialize(
      settings: const InitializationSettings(android: AndroidInitializationSettings('@mipmap/ic_launcher')),
      onDidReceiveNotificationResponse: (response) => _openPayload(response.payload),
    );
    await local
        .resolvePlatformSpecificImplementation<AndroidFlutterLocalNotificationsPlugin>()
        ?.createNotificationChannel(const AndroidNotificationChannel(
          _channelId,
          _channelName,
          importance: Importance.high,
        ));
    final launch = await local.getNotificationAppLaunchDetails();
    if (launch?.didNotificationLaunchApp ?? false) _openPayload(launch?.notificationResponse?.payload);
    _local = local;
  }

  Future<void> _onForegroundMessage(RemoteMessage message) async {
    _received.add(null);
    final notification = message.notification;
    final local = _local;
    if (notification == null || local == null) return;
    try {
      await local.show(
        id: message.hashCode & 0x7fffffff,
        title: notification.title,
        body: notification.body,
        notificationDetails: const NotificationDetails(
          android: AndroidNotificationDetails(
            _channelId,
            _channelName,
            importance: Importance.high,
            priority: Priority.high,
          ),
        ),
        payload: jsonEncode(message.data),
      );
    } catch (_) {
      // mostrar el aviso es opcional
    }
  }

  void _openPayload(String? payload) {
    if (payload == null || payload.isEmpty) return _open(const {});
    try {
      final data = jsonDecode(payload);
      _open(data is Map ? Map<String, dynamic>.from(data) : const {});
    } catch (_) {
      _open(const {});
    }
  }

  void _open(Map<String, dynamic> data) {
    final route = pushRouteFor(data);
    _pendingRoute = route;
    if (_routes.hasListener) _routes.add(route);
  }

  Future<void> dispose() async {
    for (final s in _subscriptions) {
      await s.cancel();
    }
    await _routes.close();
    await _tokens.close();
    await _received.close();
  }
}
