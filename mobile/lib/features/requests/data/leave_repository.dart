import 'package:dio/dio.dart';

import '../../../core/network/api_client.dart';
import '../../../core/network/api_endpoints.dart';
import '../../../core/utils/attachment_picker.dart';
import '../../../core/utils/formatters.dart';
import '../domain/models.dart';

class LeaveRepository {
  LeaveRepository(this._api);

  final ApiClient _api;

  Future<List<LeaveRequest>> list() async {
    final json = await _api.get(ApiEndpoints.leaveRequests);
    return ((json['data'] as List?) ?? const [])
        .map((e) => LeaveRequest.fromJson(Map<String, dynamic>.from(e as Map)))
        .toList();
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
