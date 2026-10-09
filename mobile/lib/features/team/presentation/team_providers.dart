import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/providers.dart';
import '../../../core/utils/paged.dart';
import '../../auth/presentation/auth_controller.dart';
import '../data/team_repository.dart';
import '../domain/models.dart';

final teamRepositoryProvider = Provider<TeamRepository>((ref) => TeamRepository(ref.watch(apiClientProvider)));

/// Bandeja de solicitudes pendientes de aprobación (scroll infinito).
class ApprovalsController extends PagedController<ApprovalRequest> {
  @override
  Future<Paged<ApprovalRequest>> fetchPage(int page) => ref.read(teamRepositoryProvider).pendingApprovals(page: page);

  /// Aprueba o rechaza y quita la solicitud de la bandeja. Lanza
  /// [ApiException] (ej: SELF_APPROVAL, NOT_PENDING) para que la UI lo muestre.
  Future<void> review(int id, {required bool approve, String? notes}) async {
    final repo = ref.read(teamRepositoryProvider);
    try {
      if (approve) {
        await repo.approve(id, notes: notes);
      } else {
        await repo.reject(id, notes: notes ?? '');
      }
    } on ApiException catch (e) {
      // Otro aprobador ya la revisó: se quita igual de la bandeja
      if (e.code == 'NOT_PENDING' && ref.mounted) _remove(id);
      rethrow;
    }
    if (!ref.mounted) return;
    _remove(id);
    ref.invalidate(pendingApprovalsCountProvider);
  }

  void _remove(int id) => updateItems((items) => items.where((a) => a.id != id).toList(), totalDelta: -1);
}

final approvalsProvider = AsyncNotifierProvider.autoDispose<ApprovalsController, Paged<ApprovalRequest>>(
  ApprovalsController.new,
);

/// Cantidad de solicitudes pendientes (contador en Inicio). Sin permiso o sin
/// red devuelve 0: es solo un aviso.
final pendingApprovalsCountProvider = FutureProvider.autoDispose<int>((ref) async {
  final user = ref.watch(authControllerProvider.select((s) => s.user));
  if (user == null || !user.canApproveRequests) return 0;
  try {
    final page = await ref.watch(teamRepositoryProvider).pendingApprovals();
    return page.total;
  } on ApiException {
    return 0;
  }
});

/// Asistencia de hoy del equipo (en vivo, se actualiza con pull-to-refresh).
final teamOverviewProvider = FutureProvider.autoDispose<TeamOverview>(
  (ref) => ref.watch(teamRepositoryProvider).overview(),
);
