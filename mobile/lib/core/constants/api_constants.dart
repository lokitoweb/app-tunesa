/// Constantes de configuración de API y Endpoints de TuneSA
class ApiConstants {
  // URL Base del Backend Laravel
  // Para emulador Android: 'http://10.0.2.2:8000/api/v1'
  // Para simulador iOS / dispositivo físico: 'http://192.168.1.X:8000/api/v1' o URL de producción
  static const String baseUrl = 'http://10.0.2.2:8000/api/v1';

  // Autenticación
  static const String login = '/login';
  static const String logout = '/logout';
  static const String profile = '/profile';
  static const String fcmToken = '/fcm-token';

  // Ciclo de Marcación de Asistencias (4 Estados)
  static const String attendanceStatus = '/attendance/today-status';
  static const String clockIn = '/attendance/clock-in';
  static const String breakStart = '/attendance/break-start';
  static const String breakEnd = '/attendance/break-end';
  static const String clockOut = '/attendance/clock-out';
  static const String attendanceHistory = '/attendance/history';
  static const String submitJustification = '/attendance/justifications';

  // Sucursales asignadas al colaborador
  static const String employeeBranches = '/branches/my-branches';

  // Boletas de Pago
  static const String payslips = '/payslips';
  static const String payslipDownload = '/payslips'; // /{id}/download-url

  // SharedPreferences Keys
  static const String tokenKey = 'tunesa_jwt_token';
  static const String userKey = 'tunesa_user_data';
  static const String offlineQueueKey = 'tunesa_offline_queue';
  static const String tenantIdKey = 'tunesa_tenant_id';
}
