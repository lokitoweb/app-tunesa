import '../../../core/network/api_client.dart';
import '../../../core/network/api_endpoints.dart';
import '../domain/models.dart';
import '../../../core/utils/json_num.dart';

class AnnouncementRepository {
  AnnouncementRepository(this._api);

  final ApiClient _api;

  Future<AnnouncementFeed> feed() async {
    final json = await _api.get(ApiEndpoints.announcements);
    final meta = Map<String, dynamic>.from((json['meta'] as Map?) ?? const {});
    return AnnouncementFeed(
      items: ((json['data'] as List?) ?? const []).map((e) => Announcement.fromJson(Map<String, dynamic>.from(e as Map))).toList(),
      unread: asInt(meta['unread']) ?? 0,
    );
  }

  /// Abrirlo lo marca como leído en el servidor.
  Future<Announcement> detail(int id) async {
    final json = await _api.get(ApiEndpoints.announcement(id));
    return Announcement.fromJson(Map<String, dynamic>.from(json['data'] as Map));
  }
}
