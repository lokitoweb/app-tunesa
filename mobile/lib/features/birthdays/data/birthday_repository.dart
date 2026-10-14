import '../../../core/network/api_client.dart';
import '../../../core/network/api_endpoints.dart';
import '../domain/models.dart';

class BirthdayRepository {
  BirthdayRepository(this._api);

  final ApiClient _api;

  Future<UpcomingBirthdays> upcoming({int days = 30, int limit = 10}) async {
    final json = await _api.get(ApiEndpoints.birthdaysUpcoming, query: {'days': days, 'limit': limit});
    final items = ((json['data'] as List?) ?? const [])
        .map((e) => Birthday.fromJson(Map<String, dynamic>.from(e as Map)))
        .toList();
    final meta = Map<String, dynamic>.from((json['meta'] as Map?) ?? const {});
    return UpcomingBirthdays(items: items, total: (meta['total'] as num?)?.toInt() ?? items.length);
  }

  Future<BirthdayMonth> month(int month, {String scope = 'company', String search = ''}) async {
    final json = await _api.get(ApiEndpoints.birthdays, query: {
      'month': month,
      'scope': scope,
      if (search.trim().isNotEmpty) 'search': search.trim(),
    });
    return BirthdayMonth.fromJson(Map<String, dynamic>.from(json['data'] as Map));
  }
}
