import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/config/app_config_controller.dart';
import '../../../core/providers.dart';
import '../data/announcement_repository.dart';
import '../domain/models.dart';

final announcementRepositoryProvider = Provider<AnnouncementRepository>((ref) => AnnouncementRepository(ref.watch(apiClientProvider)));

/// ¿La empresa usa el módulo de comunicados?
final announcementsEnabledProvider =
    Provider<bool>((ref) => ref.watch(appConfigProvider.select((s) => s.config.modules.announcements)));

final announcementFeedProvider = FutureProvider.autoDispose<AnnouncementFeed>((ref) => ref.watch(announcementRepositoryProvider).feed());

final announcementDetailProvider = FutureProvider.autoDispose.family<Announcement, int>((ref, id) async {
  final detail = await ref.watch(announcementRepositoryProvider).detail(id);
  // Quedó leído: se actualizan la lista y el destacado de Inicio
  ref.invalidate(announcementFeedProvider);
  return detail;
});
