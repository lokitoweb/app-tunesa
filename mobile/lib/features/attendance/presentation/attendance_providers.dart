import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/providers.dart';
import '../data/attendance_repository.dart';
import '../domain/models.dart';

final attendanceRepositoryProvider = Provider<AttendanceRepository>(
  (ref) => AttendanceRepository(ref.watch(apiClientProvider), ref.watch(sessionStorageProvider)),
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
