import 'dart:async';

import 'package:flutter/widgets.dart';
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
///
/// Inicio queda abierto en memoria: sin esto, al pasar la medianoche seguía
/// mostrando las marcaciones del día anterior. Se recarga solo al cambiar de
/// día (a las 00:00 o al volver a la app) y si pasaron más de 5 min fuera.
final todayStatusProvider = FutureProvider.autoDispose<TodayStatus>((ref) {
  final loadedAt = DateTime.now();
  final midnight = DateTime(loadedAt.year, loadedAt.month, loadedAt.day + 1);
  final timer = Timer(midnight.difference(loadedAt) + const Duration(seconds: 2), ref.invalidateSelf);
  final lifecycle = AppLifecycleListener(onResume: () {
    final now = DateTime.now();
    final otherDay = now.day != loadedAt.day || now.month != loadedAt.month || now.year != loadedAt.year;
    if (otherDay || now.difference(loadedAt) > const Duration(minutes: 5)) ref.invalidateSelf();
  });
  ref.onDispose(() {
    timer.cancel();
    lifecycle.dispose();
  });
  return ref.watch(attendanceRepositoryProvider).today();
});

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
