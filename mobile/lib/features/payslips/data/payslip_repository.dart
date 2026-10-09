import 'dart:typed_data';

import '../../../core/network/api_client.dart';
import '../../../core/network/api_endpoints.dart';
import '../domain/models.dart';

class PayslipRepository {
  PayslipRepository(this._api);

  final ApiClient _api;

  Future<List<Payslip>> list() async {
    final json = await _api.get(ApiEndpoints.payslips);
    return ((json['data'] as List?) ?? const [])
        .map((e) => Payslip.fromJson(Map<String, dynamic>.from(e as Map)))
        .toList();
  }

  /// Descarga el PDF a memoria (no se escribe en el almacenamiento del teléfono).
  /// El backend la marca como leída.
  Future<Uint8List> pdf(int id) async => Uint8List.fromList(await _api.download(ApiEndpoints.payslipDownload(id)));
}
