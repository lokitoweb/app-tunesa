import '../../../core/network/api_client.dart';
import '../../../core/network/api_endpoints.dart';
import '../domain/models.dart';
import '../../../core/utils/json_num.dart';

class BirthdayRepository {
  BirthdayRepository(this._api);

  final ApiClient _api;

  /// [thisMonth]: lo que queda del mes en curso (ignora [days]).
  /// [scope] 'team': solo la sede de quien consulta, sin incluirlo.
  Future<UpcomingBirthdays> upcoming({int days = 30, int limit = 10, String scope = 'company', bool thisMonth = false}) async {
    final json = await _api.get(ApiEndpoints.birthdaysUpcoming, query: {
      if (thisMonth) 'period': 'month' else 'days': days,
      'limit': limit,
      'scope': scope,
    });
    final items = ((json['data'] as List?) ?? const [])
        .map((e) => Birthday.fromJson(Map<String, dynamic>.from(e as Map)))
        .toList();
    final meta = Map<String, dynamic>.from((json['meta'] as Map?) ?? const {});
    return UpcomingBirthdays(
      items: items,
      total: asInt(meta['total']) ?? items.length,
      greeting: BirthdayGreeting.fromJson(meta['greeting']),
    );
  }

  /// Regalos del cumpleaños de [userId], catálogo y si hoy se puede enviar.
  Future<GiftBoard> gifts(int userId) async {
    final json = await _api.get(ApiEndpoints.birthdayGifts(userId));
    return GiftBoard.fromJson(Map<String, dynamic>.from(json['data'] as Map));
  }

  /// Envía un regalo (solo el día del cumpleaños; 409 si ya lo envió).
  Future<BirthdayGift> sendGift(int userId, String type) async {
    final json = await _api.post(ApiEndpoints.birthdayGifts(userId), data: {'type': type});
    return BirthdayGift.fromJson(Map<String, dynamic>.from(json['data'] as Map));
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
