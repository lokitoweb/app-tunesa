import '../../../core/network/api_client.dart';
import '../../../core/network/api_endpoints.dart';
import '../../../core/utils/paged.dart';
import '../domain/models.dart';

/// Supervisor / RRHH: bandeja de aprobaciones y asistencia del equipo.
class TeamRepository {
  TeamRepository(this._api);

  final ApiClient _api;

  /// Solicitudes pendientes que puedo revisar (20 por página).
  Future<Paged<ApprovalRequest>> pendingApprovals({int page = 1}) async {
    final json = await _api.get(ApiEndpoints.approvals, query: {'page': page});
    return Paged.fromJson(json, ApprovalRequest.fromJson);
  }

  Future<void> approve(int id, {String? notes}) => _api.post(
        ApiEndpoints.approveLeaveRequest(id),
        data: {if (notes != null && notes.trim().isNotEmpty) 'notes': notes.trim()},
      );

  /// El backend exige el motivo del rechazo en `notes`.
  Future<void> reject(int id, {required String notes}) =>
      _api.post(ApiEndpoints.rejectLeaveRequest(id), data: {'notes': notes.trim()});

  /// Asistencia de hoy del equipo + integrantes.
  Future<TeamOverview> overview() async {
    final results = await Future.wait([
      _api.get(ApiEndpoints.supervisorLive),
      _api.get(ApiEndpoints.supervisorTeam),
    ]);
    return TeamOverview.fromJson(results[0], results[1]);
  }
}
