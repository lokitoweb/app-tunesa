import '../../../core/network/api_client.dart';
import '../../../core/network/api_endpoints.dart';
import '../domain/models.dart';

/// Control de la cuenta: sesiones abiertas y avisos push.
class AccountRepository {
  AccountRepository(this._api);

  final ApiClient _api;

  Future<List<DeviceSession>> sessions() async {
    final json = await _api.get(ApiEndpoints.sessions);
    return ((json['data'] as List?) ?? const [])
        .map((e) => DeviceSession.fromJson(Map<String, dynamic>.from(e as Map)))
        .toList();
  }

  Future<void> revoke(int id) => _api.delete(ApiEndpoints.session(id));

  /// Cierra la sesión en los demás dispositivos; devuelve cuántos se cerraron.
  Future<int> logoutOthers() async {
    final json = await _api.post(ApiEndpoints.logoutOthers);
    return ((json['data'] as Map?)?['closed'] as num?)?.toInt() ?? 0;
  }

  Future<List<NotificationPreference>> preferences() async => _parse(await _api.get(ApiEndpoints.notificationPreferences));

  Future<List<NotificationPreference>> setPreference(String key, bool enabled) async =>
      _parse(await _api.put(ApiEndpoints.notificationPreferences, data: {key: enabled}));

  List<NotificationPreference> _parse(Map<String, dynamic> json) => ((json['data'] as List?) ?? const [])
      .map((e) => NotificationPreference.fromJson(Map<String, dynamic>.from(e as Map)))
      .toList();
}
