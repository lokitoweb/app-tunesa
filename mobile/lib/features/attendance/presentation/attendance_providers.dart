import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/config/version_gate.dart';
import '../../../core/providers.dart';
import '../data/attempt_reporter.dart';
import '../data/attendance_repository.dart';
import '../data/reverse_geocoder.dart';
import '../domain/models.dart';

final attendanceRepositoryProvider = Provider<AttendanceRepository>(
  (ref) => AttendanceRepository(
    ref.watch(apiClientProvider),
    ref.watch(sessionStorageProvider),
    appVersion: ref.watch(appVersionProvider),
  ),
);

/// Bloqueos de la app reportados al servidor (con cola local si no hay señal).
final attemptReporterProvider = Provider<AttemptReporter>(
  (ref) => AttemptReporter(
    ref.watch(apiClientProvider),
    ref.watch(sharedPreferencesProvider),
    ref.watch(sessionStorageProvider),
    appVersion: ref.watch(appVersionProvider),
  ),
);

/// Estado del día (registro, acciones disponibles y turno programado).
final todayStatusProvider = FutureProvider.autoDispose<TodayStatus>(
  (ref) => ref.watch(attendanceRepositoryProvider).today(),
);

/// Historial por mes: (mes, año).
final attendanceHistoryProvider = FutureProvider.autoDispose.family<AttendanceHistory, (int, int)>(
  (ref, period) => ref.watch(attendanceRepositoryProvider).history(month: period.$1, year: period.$2),
);

/// Turnos de los próximos 7 días.
final weekScheduleProvider = FutureProvider.autoDispose<List<DaySchedule>>((ref) {
  final today = DateTime.now();
  return ref.watch(attendanceRepositoryProvider).schedule(today, today.add(const Duration(days: 6)));
});

/// Sucursales asignadas (geocercas) para la validación previa en el teléfono.
final myBranchesProvider = FutureProvider.autoDispose<List<BranchGeofence>>(
  (ref) => ref.watch(attendanceRepositoryProvider).myBranches(),
);

/// Direcciones de la pantalla de marcación (caché por ~50 m durante la sesión).
final reverseGeocoderProvider = Provider<ReverseGeocoder>((ref) {
  final repo = ref.watch(attendanceRepositoryProvider);
  return ReverseGeocoder(repo.reverseGeocode);
});
