import '../../../core/network/api_client.dart';
import '../../../core/network/api_endpoints.dart';
import '../../../core/utils/paged.dart';
import '../domain/models.dart';
import '../../../core/utils/json_num.dart';

class NotificationRepository {
  NotificationRepository(this._api);

  final ApiClient _api;

  /// Página de notificaciones; `meta.unread_count` trae las no leídas.
  Future<Paged<AppNotification>> list({int page = 1}) async {
    final json = await _api.get(ApiEndpoints.notifications, query: {'page': page});
    return Paged.fromJson(json, AppNotification.fromJson);
  }

  /// Solo el contador de no leídas (para la campana de Inicio).
  Future<int> unreadCount() async => unreadOf(await list());

  Future<void> markRead(String id) => _api.post(ApiEndpoints.notificationRead(id));

  Future<void> markAllRead() => _api.post(ApiEndpoints.notificationsReadAll);

  static int unreadOf(Paged<AppNotification> page) =>
      asInt(page.meta['unread_count']) ?? page.items.where((n) => !n.isRead).length;
}
