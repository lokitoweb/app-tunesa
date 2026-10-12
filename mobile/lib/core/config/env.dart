/// Configuración de entorno centralizada.
///
/// Los valores llegan en tiempo de compilación desde `env/<entorno>.json`:
///
///   flutter run   --dart-define-from-file=env/dev.json
///   flutter build apk --release --dart-define-from-file=env/prod.json
///
/// Para apuntar la app a otro dominio basta con editar `API_BASE_URL` en el
/// JSON del entorno (o pasar `--dart-define=API_BASE_URL=https://...`).
class Env {
  const Env._();

  /// Nombre del entorno: dev | staging | prod.
  static const String name = String.fromEnvironment('ENV_NAME', defaultValue: 'dev');

  /// Dominio del backend Laravel, sin `/api` (ej: https://api.tunesa.app).
  /// Por defecto, el backend local visto desde el emulador de Android.
  static const String apiBaseUrl = String.fromEnvironment('API_BASE_URL', defaultValue: 'http://10.0.2.2:8000');

  /// Empresa preseleccionada (app con marca blanca de un solo cliente).
  /// Vacío = el colaborador escribe el código de su empresa al ingresar.
  static const String defaultTenant = String.fromEnvironment('DEFAULT_TENANT');

  /// Precisión GPS máxima aceptada (metros) antes de enviar una marcación.
  /// El backend aplica su propio límite (ATTENDANCE_MAX_GPS_ACCURACY).
  static const int maxGpsAccuracyMeters = int.fromEnvironment('MAX_GPS_ACCURACY', defaultValue: 100);

  // Firebase (push). Sin google-services.json: las opciones llegan por
  // --dart-define. Si falta alguna obligatoria, el push queda desactivado.
  static const String firebaseApiKey = String.fromEnvironment('FIREBASE_API_KEY');
  static const String firebaseAppId = String.fromEnvironment('FIREBASE_APP_ID');
  static const String firebaseMessagingSenderId = String.fromEnvironment('FIREBASE_MESSAGING_SENDER_ID');
  static const String firebaseProjectId = String.fromEnvironment('FIREBASE_PROJECT_ID');
  static const String firebaseIosAppId = String.fromEnvironment('FIREBASE_IOS_APP_ID');
  static const String firebaseIosBundleId = String.fromEnvironment('FIREBASE_IOS_BUNDLE_ID');

  /// Huella SHA-256 del certificado con que se firma el APK (varias separadas por
  /// coma, p. ej. la llave propia y la de Google Play). Vacía = no se revisa.
  static const String appCertSha256 = String.fromEnvironment('APP_CERT_SHA256');

  static const Duration connectTimeout = Duration(seconds: 15);
  static const Duration receiveTimeout = Duration(seconds: 30);
  static const Duration gpsTimeout = Duration(seconds: 20);

  static bool get isProduction => name == 'prod';

  /// URL base de la API REST: `<API_BASE_URL>/api`.
  /// Acepta el dominio con o sin `/api` al final (https://x.trycloudflare.com
  /// o https://x.trycloudflare.com/api terminan en la misma URL).
  static String get apiUrl => apiUrlFor(apiBaseUrl);

  /// Dominio sin barras finales ni `/api` (https://x.app/api/ → https://x.app).
  static String normalizeBaseUrl(String base) =>
      base.trim().replaceAll(RegExp(r'/+$'), '').replaceAll(RegExp(r'/api$'), '');

  /// URL de la API REST para un dominio cualquiera (override en tiempo de ejecución).
  static String apiUrlFor(String base) => '${normalizeBaseUrl(base)}/api';

  /// `http://` solo se acepta en builds de desarrollo.
  static bool get allowsHttp => name == 'dev';
}
