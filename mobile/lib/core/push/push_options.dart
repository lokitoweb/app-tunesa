import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';

import '../config/env.dart';
import '../../features/notifications/domain/models.dart';

/// Valores de Firebase recibidos por `--dart-define` (sin google-services.json
/// ni GoogleService-Info.plist).
class FirebaseDefines {
  const FirebaseDefines({
    this.apiKey = '',
    this.androidAppId = '',
    this.messagingSenderId = '',
    this.projectId = '',
    this.iosAppId = '',
    this.iosBundleId = '',
  });

  /// Los compilados en la app (`env/<entorno>.json` o el workflow).
  static const compiled = FirebaseDefines(
    apiKey: Env.firebaseApiKey,
    androidAppId: Env.firebaseAppId,
    messagingSenderId: Env.firebaseMessagingSenderId,
    projectId: Env.firebaseProjectId,
    iosAppId: Env.firebaseIosAppId,
    iosBundleId: Env.firebaseIosBundleId,
  );

  final String apiKey;
  final String androidAppId;
  final String messagingSenderId;
  final String projectId;
  final String iosAppId;
  final String iosBundleId;
}

/// Opciones de Firebase para [platform], o null si falta algún valor
/// obligatorio (el push queda desactivado sin romper la app).
FirebaseOptions? firebaseOptionsFor(TargetPlatform platform, FirebaseDefines d) {
  bool filled(Iterable<String> values) => values.every((v) => v.trim().isNotEmpty);
  final common = [d.apiKey, d.messagingSenderId, d.projectId];

  switch (platform) {
    case TargetPlatform.android:
      if (!filled([...common, d.androidAppId])) return null;
      return FirebaseOptions(
        apiKey: d.apiKey.trim(),
        appId: d.androidAppId.trim(),
        messagingSenderId: d.messagingSenderId.trim(),
        projectId: d.projectId.trim(),
      );
    case TargetPlatform.iOS:
      if (!filled([...common, d.iosAppId, d.iosBundleId])) return null;
      return FirebaseOptions(
        apiKey: d.apiKey.trim(),
        appId: d.iosAppId.trim(),
        messagingSenderId: d.messagingSenderId.trim(),
        projectId: d.projectId.trim(),
        iosBundleId: d.iosBundleId.trim(),
      );
    default:
      return null;
  }
}

/// Push de "Nueva versión de la app" (`app.update_available`).
bool isAppUpdatePush(Map<String, dynamic> data) =>
    data['type']?.toString() == 'app.update_available' || data['screen']?.toString() == 'app_update';

/// Pantalla a la que lleva un push según `data.screen` (o, si no viene, el
/// `type`, igual que la bandeja in-app). Sin destino conocido: la bandeja.
String pushRouteFor(Map<String, dynamic> data) {
  final screen = data['screen']?.toString().trim().toLowerCase() ?? '';
  final byScreen = switch (screen) {
    'leave_requests' || 'leave_request' || 'requests' => '/requests',
    'approvals' || 'leave_approvals' => '/approvals',
    'payslips' || 'payslip' => '/payslips',
    'documents' || 'document' => '/profile/documents',
    'attendance' || 'history' => '/history',
    'notifications' => '/notifications',
    'home' || 'app_update' => '/home',
    'profile' => '/profile',
    'trainings' || 'training' => '/trainings',
    'announcements' || 'announcement' => _announcementRoute(data),
    'benefits' || 'benefit' => '/benefits',
    'birthdays' || 'birthday' => '/birthdays',
    'company_documents' || 'company_document' => '/company-documents',
    'devices' => '/profile/devices',
    _ => null,
  };
  if (byScreen != null) return byScreen;
  final type = data['type']?.toString() ?? '';
  return AppNotification.routeFor(type, data) ?? '/notifications';
}

/// Si el teléfono deja mostrar los avisos de la app.
enum PushPermission {
  granted,
  denied,

  /// Todavía no se preguntó (o se cerró el diálogo sin elegir).
  notDetermined,

  /// Push apagado o no se pudo consultar.
  unknown,
}

PushPermission pushPermissionFrom(AuthorizationStatus status) => switch (status) {
      AuthorizationStatus.authorized || AuthorizationStatus.provisional => PushPermission.granted,
      AuthorizationStatus.denied || AuthorizationStatus.deniedPermanently => PushPermission.denied,
      AuthorizationStatus.notDetermined => PushPermission.notDetermined,
    };

/// Con id, directo al comunicado; si no, a la lista.
String _announcementRoute(Map<String, dynamic> data) {
  final id = int.tryParse(data['announcement_id']?.toString() ?? '');
  return id == null ? '/announcements' : '/announcements/$id';
}
