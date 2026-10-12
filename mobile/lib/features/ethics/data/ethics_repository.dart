import 'package:dio/dio.dart';

import '../../../core/network/api_client.dart';
import '../../../core/network/api_endpoints.dart';
import '../domain/models.dart';

/// Llamadas del canal ético. Van sin sesión ([ApiClient.anonymous]): el
/// servidor no puede saber qué usuario las hizo. La única excepción es la
/// denuncia identificada, que se envía con sesión solo si la persona lo eligió.
class EthicsRepository {
  EthicsRepository(this._api);

  final ApiClient _api;

  Future<List<EthicsCategory>> categories() async {
    final json = await _api.anonymous('GET', ApiEndpoints.ethicsCategories);
    return ((json['data'] as List?) ?? const [])
        .map((e) => EthicsCategory.fromJson(Map<String, dynamic>.from(e as Map)))
        .toList();
  }

  /// Registra la denuncia. Devuelve código y clave (la clave no se vuelve a mostrar).
  Future<({String code, String token})> report({
    required String category,
    required String subject,
    required String body,
    String? channel,
    List<List<int>> photos = const [],
    bool identified = false,
  }) async {
    final form = FormData.fromMap({
      'category': category,
      'subject': subject,
      'body': body,
      'push_channel': ?channel,
      for (final (i, bytes) in photos.indexed) 'photos[$i]': MultipartFile.fromBytes(bytes, filename: 'foto$i.jpg'),
    });
    final json = identified
        ? await _api.post(ApiEndpoints.ethicsCasesIdentified, data: form)
        : await _api.anonymous('POST', ApiEndpoints.ethicsCases, data: form);
    final data = Map<String, dynamic>.from(json['data'] as Map);
    return (code: data['code'].toString(), token: data['token'].toString());
  }

  Future<EthicsCaseView> lookup(String code, String token) async {
    final json = await _api.anonymous('GET', ApiEndpoints.ethicsCase(code.trim().toUpperCase()), headers: {'X-Ethics-Token': token});
    return EthicsCaseView.fromJson(Map<String, dynamic>.from(json['data'] as Map));
  }

  Future<EthicsCaseView> send(String code, String token, String body, {List<List<int>> photos = const []}) async {
    final form = FormData.fromMap({
      'body': body,
      for (final (i, bytes) in photos.indexed) 'photos[$i]': MultipartFile.fromBytes(bytes, filename: 'foto$i.jpg'),
    });
    final json = await _api.anonymous('POST', ApiEndpoints.ethicsMessages(code), data: form, headers: {'X-Ethics-Token': token});
    return EthicsCaseView.fromJson(Map<String, dynamic>.from(json['data'] as Map));
  }
}
