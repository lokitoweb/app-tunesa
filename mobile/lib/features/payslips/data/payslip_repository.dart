import 'dart:typed_data';

import '../../../core/network/api_client.dart';
import '../../../core/network/api_endpoints.dart';
import '../../../core/network/api_exception.dart';
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
  /// El backend la marca como leída. Si llega algo que no es un PDF (archivo
  /// dañado o vacío) se avisa con un mensaje claro en lugar de un visor roto.
  Future<Uint8List> pdf(int id) async {
    final bytes = Uint8List.fromList(await _api.download(ApiEndpoints.payslipDownload(id)));
    if (!isPdf(bytes)) {
      throw const ApiException(
        'La boleta no se pudo abrir porque el archivo está dañado o vacío. Pide a RR. HH. que la vuelva a publicar.',
        code: 'INVALID_PDF',
      );
    }
    return bytes;
  }

  /// Un PDF empieza con "%PDF"
  static bool isPdf(List<int> bytes) =>
      bytes.length > 4 && bytes[0] == 0x25 && bytes[1] == 0x50 && bytes[2] == 0x44 && bytes[3] == 0x46;
}
