import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/providers.dart';
import '../data/account_repository.dart';
import '../domain/models.dart';

final accountRepositoryProvider = Provider<AccountRepository>((ref) => AccountRepository(ref.watch(apiClientProvider)));

final deviceSessionsProvider = FutureProvider.autoDispose<List<DeviceSession>>(
  (ref) => ref.watch(accountRepositoryProvider).sessions(),
);

final notificationPreferencesProvider = FutureProvider.autoDispose<List<NotificationPreference>>(
  (ref) => ref.watch(accountRepositoryProvider).preferences(),
);
