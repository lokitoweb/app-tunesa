import '../../../core/network/api_client.dart';
import '../../../core/network/api_endpoints.dart';
import '../domain/models.dart';

/// Firma APP: el apoderado designado autoriza las órdenes de pago programadas.
/// La regla (quién puede, OC/OS válida, programada) la valida el servidor.
class TreasuryRepository {
  TreasuryRepository(this._api);

  final ApiClient _api;

  Future<List<PaymentAuthorization>> pending() async {
    final json = await _api.get(ApiEndpoints.paymentAuthorizations);
    final data = json['data'];
    return data is List ? data.whereType<Map<String, dynamic>>().map(PaymentAuthorization.fromJson).toList() : const [];
  }

  Future<void> authorize(int id) => _api.post(ApiEndpoints.authorizePayment(id));
}
