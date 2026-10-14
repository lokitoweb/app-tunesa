import '../../../core/network/api_client.dart';
import '../../../core/network/api_endpoints.dart';
import '../domain/models.dart';

class BenefitRepository {
  BenefitRepository(this._api);

  final ApiClient _api;

  Future<BenefitCatalog> catalog() async {
    final json = await _api.get(ApiEndpoints.benefits);
    final meta = Map<String, dynamic>.from((json['meta'] as Map?) ?? const {});
    return BenefitCatalog(
      items: ((json['data'] as List?) ?? const []).map((e) => Benefit.fromJson(Map<String, dynamic>.from(e as Map))).toList(),
      categories: ((meta['categories'] as List?) ?? const [])
          .map((e) => BenefitCategory.fromJson(Map<String, dynamic>.from(e as Map)))
          .toList(),
    );
  }

  Future<Benefit> detail(int id) async {
    final json = await _api.get(ApiEndpoints.benefit(id));
    return Benefit.fromJson(Map<String, dynamic>.from(json['data'] as Map));
  }
}
