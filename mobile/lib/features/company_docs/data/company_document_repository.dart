import 'dart:typed_data';

import '../../../core/network/api_client.dart';
import '../../../core/network/api_endpoints.dart';
import '../domain/models.dart';

class CompanyDocumentRepository {
  CompanyDocumentRepository(this._api);

  final ApiClient _api;

  Future<DocumentLibrary> library() async {
    final json = await _api.get(ApiEndpoints.companyDocuments);
    final meta = Map<String, dynamic>.from((json['meta'] as Map?) ?? const {});
    return DocumentLibrary(
      documents: ((json['data'] as List?) ?? const []).map((e) => CompanyDocument.fromJson(Map<String, dynamic>.from(e as Map))).toList(),
      folders: ((meta['folders'] as List?) ?? const []).map((e) => DocumentFolder.fromJson(Map<String, dynamic>.from(e as Map))).toList(),
      pendingAcknowledgement: (meta['pending_acknowledgement'] as num?)?.toInt() ?? 0,
    );
  }

  Future<CompanyDocument> detail(int id) async {
    final json = await _api.get(ApiEndpoints.companyDocument(id));
    return CompanyDocument.fromJson(Map<String, dynamic>.from(json['data'] as Map));
  }

  /// PDF en memoria (no se guarda en el teléfono). El servidor lo marca como leído.
  Future<Uint8List> pdf(int id) async => Uint8List.fromList(await _api.download(ApiEndpoints.companyDocumentFile(id)));

  Future<CompanyDocument> acknowledge(int id) async {
    final json = await _api.post(ApiEndpoints.companyDocumentAcknowledge(id));
    return CompanyDocument.fromJson(Map<String, dynamic>.from(json['data'] as Map));
  }
}
