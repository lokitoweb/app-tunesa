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

  static const Duration connectTimeout = Duration(seconds: 15);
  static const Duration receiveTimeout = Duration(seconds: 30);
  static const Duration gpsTimeout = Duration(seconds: 20);

  static bool get isProduction => name == 'prod';

  /// URL base de la API REST: `<API_BASE_URL>/api`.
  static String get apiUrl => '${apiBaseUrl.replaceAll(RegExp(r'/+$'), '')}/api';
}
