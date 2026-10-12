import '../../../core/network/api_client.dart';
import '../../../core/network/api_endpoints.dart';
import '../domain/models.dart';

class OnboardingRepository {
  OnboardingRepository(this._api);

  final ApiClient _api;

  Future<OnboardingSummary> summary() async {
    final json = await _api.get(ApiEndpoints.onboarding);
    return OnboardingSummary.fromJson(Map<String, dynamic>.from(json['data'] as Map));
  }

  /// Envía a RRHH. Devuelve el resumen actualizado y el mensaje del servidor.
  Future<(OnboardingSummary, String)> submit() async {
    final json = await _api.post(ApiEndpoints.onboardingSubmit);
    return (
      OnboardingSummary.fromJson(Map<String, dynamic>.from(json['data'] as Map)),
      json['message']?.toString() ?? 'Enviamos tu incorporación a RRHH.',
    );
  }
}
