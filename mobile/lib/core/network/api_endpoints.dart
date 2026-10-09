/// Rutas de la API (routes/api.php del backend). Relativas a [Env.apiUrl].
class ApiEndpoints {
  const ApiEndpoints._();

  // Empresa / marca blanca (público, requiere X-Tenant-Slug)
  static const branding = '/tenant/branding';

  // Autenticación
  static const login = '/auth/login';
  static const logout = '/auth/logout';
  static const me = '/auth/me';
  static const forgotPassword = '/auth/forgot-password';
  static const changePassword = '/auth/change-password';
  static const logoutAll = '/auth/logout-all';

  // Asistencia
  static const attendanceToday = '/attendance/today';
  static const attendanceHistory = '/attendance/history';
  static const clockIn = '/attendance/clock-in';
  static const breakStart = '/attendance/break-start';
  static const breakEnd = '/attendance/break-end';
  static const clockOut = '/attendance/clock-out';
  static String justification(int attendanceId) => '/attendance/$attendanceId/justification';

  // Turnos y sucursales
  static const schedule = '/schedule';
  static const myShifts = '/my-shifts';
  static const myBranches = '/my-branches';

  // Solicitudes (vacaciones, permisos, licencias)
  static const leaveRequests = '/leave-requests';
  static String cancelLeaveRequest(int id) => '/leave-requests/$id/cancel';

  // Boletas
  static const payslips = '/payslips';
  static String payslipDownload(int id) => '/payslips/$id/download';

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
