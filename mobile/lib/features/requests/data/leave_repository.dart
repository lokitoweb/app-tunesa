import 'package:dio/dio.dart';

import '../../../core/network/api_client.dart';
import '../../../core/network/api_endpoints.dart';
import '../../../core/utils/attachment_picker.dart';
import '../../../core/utils/formatters.dart';
import '../../../core/utils/paged.dart';
import '../domain/models.dart';

class LeaveRepository {
  LeaveRepository(this._api);

  final ApiClient _api;

  /// Mis solicitudes, paginadas de 20 en 20 por el backend.
  Future<Paged<LeaveRequest>> list({int page = 1}) async {
    final json = await _api.get(ApiEndpoints.leaveRequests, query: {'page': page});
    return Paged.fromJson(json, LeaveRequest.fromJson);
  }

  Future<void> create({
    required LeaveType type,
    required DateTime start,
    required DateTime end,
    required String reason,
    String? startTime,
    String? endTime,
    PlatformAttachment? attachment,
  }) async {
    final form = FormData.fromMap({
      'type': type.apiName,
      'start_date': Fmt.apiDate(start),
      'end_date': Fmt.apiDate(end),
      'reason': reason,
      'start_time': ?startTime,
      'end_time': ?endTime,
      if (attachment != null) 'attachment': attachment.toMultipart(),
    });
    await _api.postMultipart(ApiEndpoints.leaveRequests, form);
  }

  Future<void> cancel(int id) => _api.post(ApiEndpoints.cancelLeaveRequest(id));
}
