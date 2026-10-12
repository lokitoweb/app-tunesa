/// Rutas de la API (routes/api.php del backend). Relativas a [Env.apiUrl].
class ApiEndpoints {
  const ApiEndpoints._();

  // Configuración pública de arranque (marca blanca, reglas, versión mínima)
  static const appConfig = '/v1/app/config';

  // Empresa / marca blanca (legado: backend sin /v1/app/config; requiere X-Tenant-Slug)
  static const branding = '/tenant/branding';

  // Autenticación
  static const login = '/auth/login';

  /// Sin código de empresa: el servidor la reconoce por el correo.
  static const loginByEmail = '/v1/auth/login';
  static const forgotPasswordByEmail = '/v1/auth/forgot-password';
  static const logout = '/auth/logout';
  static const me = '/auth/me';
  static const profile = '/auth/profile';
  static const forgotPassword = '/auth/forgot-password';
  static const changePassword = '/auth/change-password';
  static const logoutAll = '/auth/logout-all';
  static const logoutOthers = '/auth/logout-others';
  static const sessions = '/auth/sessions';
  static String session(int id) => '/auth/sessions/$id';
  static const notificationPreferences = '/auth/notification-preferences';
  static const refreshFcm = '/auth/refresh-fcm';

  /// Pedido de eliminación de la cuenta y sus datos.
  static const accountDeletion = '/auth/account-deletion';

  // Asistencia
  static const attendanceToday = '/attendance/today';
  static const attendanceHistory = '/attendance/history';
  static const clockIn = '/attendance/clock-in';
  static const breakStart = '/attendance/break-start';
  static const breakEnd = '/attendance/break-end';
  static const clockOut = '/attendance/clock-out';

  /// Envío en lote de la cola sin conexión (máx. 50; la app manda 20).
  static const syncBatch = '/v1/attendances/sync-batch';

  // Dirección legible de una coordenada (con token)
  static const geocodeReverse = '/geocode/reverse';
  static String justification(int attendanceId) => '/attendance/$attendanceId/justification';

  // Turnos y sucursales
  static const schedule = '/schedule';
  static const myShifts = '/my-shifts';
  static const myBranches = '/my-branches';

  // Solicitudes (vacaciones, permisos, licencias)
  static const leaveRequests = '/leave-requests';
  static String cancelLeaveRequest(int id) => '/leave-requests/$id/cancel';

  // Aprobación de solicitudes (supervisor: su equipo; RRHH/Admin: todas)
  static const approvals = '/approvals/leave-requests';
  static String approveLeaveRequest(int id) => '/approvals/leave-requests/$id/approve';
  static String rejectLeaveRequest(int id) => '/approvals/leave-requests/$id/reject';

  // Supervisor: mi equipo y su asistencia de hoy
  static const supervisorTeam = '/supervisor/team';
  static const supervisorLive = '/supervisor/attendance/live';

  // Notificaciones in-app
  static const notifications = '/notifications';
  static String notificationRead(String id) => '/notifications/$id/read';
  static const notificationsReadAll = '/notifications/read-all';

  // Boletas
  static const payslips = '/payslips';
  static String payslipDownload(int id) => '/payslips/$id/download';

  // Capacitaciones
  static const trainings = '/trainings';
  static String training(int id) => '/trainings/$id';
  static String trainingLessonComplete(int course, int lesson) => '/trainings/$course/lessons/$lesson/complete';
  static String trainingLessonFile(int course, int lesson) => '/trainings/$course/lessons/$lesson/file';
  static String trainingQuiz(int course) => '/trainings/$course/quiz';
  static String trainingCertificate(int course) => '/trainings/$course/certificate';

  // Autoservicio: legajo, datos de pago y dotación
  static const employeeConfig = '/employee/config';
  static const employeeProfile = '/employee/profile';
  static const paymentInfo = '/employee/payment-info';
  static const contactInfo = '/employee/contact';
  static const documents = '/employee/documents';
  static String documentUrl(int id) => '/employee/documents/$id/url';
  static String document(int id) => '/employee/documents/$id';
  static const uniformSizes = '/employee/uniform-sizes';
}
