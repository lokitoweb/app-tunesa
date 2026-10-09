import 'dart:typed_data';

import 'package:dio/dio.dart';

import '../../../core/network/api_client.dart';
import '../../../core/network/api_endpoints.dart';
import '../domain/models.dart';

/// Autoservicio del colaborador: perfil, legajo, datos de pago y tallas.
class ProfileRepository {
  ProfileRepository(this._api);

  final ApiClient _api;

  Future<EmployeeFile> profile() async {
    final json = await _api.get(ApiEndpoints.employeeProfile);
    return EmployeeFile.fromJson(Map<String, dynamic>.from(json['data'] as Map));
  }

  Future<CompanyConfig> config() async {
    final json = await _api.get(ApiEndpoints.employeeConfig);
    return CompanyConfig.fromJson(Map<String, dynamic>.from(json['data'] as Map));
  }

  Future<String> updatePaymentInfo(Map<String, dynamic> data) async {
    final json = await _api.put(ApiEndpoints.paymentInfo, data: data);
    return json['message']?.toString() ?? 'Datos guardados.';
  }

  Future<void> updateContact(Map<String, dynamic> data) => _api.put(ApiEndpoints.contactInfo, data: data);

  Future<void> updateUniformSizes(Map<String, dynamic> data) => _api.put(ApiEndpoints.uniformSizes, data: data);

  Future<String> uploadDocument({required String type, required String fileName, required List<int> bytes}) async {
    final form = FormData.fromMap({
      'type': type,
      'file': MultipartFile.fromBytes(bytes, filename: fileName),
    });
    final json = await _api.postMultipart(ApiEndpoints.documents, form);
    return json['message']?.toString() ?? 'Documento enviado.';
  }

  Future<void> deleteDocument(int id) => _api.delete(ApiEndpoints.document(id));

  /// Descarga el documento con una URL firmada que vence en minutos.
  /// Se muestra desde memoria, no se guarda en el teléfono.
  Future<Uint8List> documentBytes(int id) async {
    final json = await _api.get(ApiEndpoints.documentUrl(id));
    final url = (json['data'] as Map)['url'] as String;
    return Uint8List.fromList(await _api.downloadSigned(url));
  }
}
