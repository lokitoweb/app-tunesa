import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/providers.dart';
import '../../auth/presentation/auth_controller.dart';
import '../data/onboarding_repository.dart';
import '../domain/models.dart';

final onboardingRepositoryProvider = Provider<OnboardingRepository>((ref) => OnboardingRepository(ref.watch(apiClientProvider)));

/// Estado de la incorporación según el servidor. Un servidor sin el módulo
/// (404) equivale a aprobada.
final onboardingProvider = FutureProvider.autoDispose<OnboardingSummary>((ref) async {
  try {
    return await ref.watch(onboardingRepositoryProvider).summary();
  } on ApiException catch (e) {
    if (e.statusCode == 404) return OnboardingSummary.approved;
    rethrow;
  }
});

/// ¿Su rol marca asistencia? (permiso «Marcar asistencia»; Gerencia no, por defecto)
final marksAttendanceProvider = Provider.autoDispose<bool>(
  (ref) => ref.watch(authControllerProvider.select((s) => s.user?.marksAttendance ?? true)),
);

/// ¿Se muestran los botones de marcar? Solo si su rol marca y su incorporación
/// está aprobada. Sin respuesta del servidor (cargando o sin conexión) se usa el
/// estado guardado en la sesión. El servidor bloquea igual aunque la app se equivoque.
final canPunchProvider = Provider.autoDispose<bool>((ref) {
  if (!ref.watch(marksAttendanceProvider)) return false;
  final remote = ref.watch(onboardingProvider);
  if (remote.hasValue) return remote.requireValue.canPunch;
  final status = ref.watch(authControllerProvider.select((s) => s.user?.onboardingStatus ?? 'approved'));
  return status == 'approved';
});
