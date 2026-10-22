import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/theme/app_theme.dart';
import '../../../core/utils/formatters.dart';
import '../../../core/widgets/async_views.dart';
import '../../../core/widgets/page_padding.dart';
import '../domain/models.dart';
import 'announcement_providers.dart';
import 'announcement_widgets.dart';
import '../../../core/widgets/skeleton.dart';

/// Comunicados de RR. HH. que le llegaron a la persona (últimos 6 meses).
class AnnouncementsScreen extends ConsumerWidget {
  const AnnouncementsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final feed = ref.watch(announcementFeedProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Comunicados')),
      body: RefreshIndicator(
        onRefresh: () => ref.refresh(announcementFeedProvider.future),
        child: feed.whenSkeleton(
          loading: () => const SkeletonList(leading: SkeletonLeading.square, lines: 3),
          error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(announcementFeedProvider)),
          data: (f) => f.items.isEmpty
              ? ListView(children: const [
                  SizedBox(height: 120),
                  EmptyView(icon: Icons.campaign_outlined, message: 'Aún no tienes comunicados. Aquí verás avisos, reuniones y novedades de la empresa.'),
                ])
              : ListView.separated(
                  padding: pagePadding(context),
                  itemCount: f.items.length,
                  separatorBuilder: (_, _) => const SizedBox(height: 10),
                  itemBuilder: (_, i) => AnnouncementTile(announcement: f.items[i]),
                ),
        ),
      ),
    );
  }
}

class AnnouncementTile extends StatelessWidget {
  const AnnouncementTile({super.key, required this.announcement});

  final Announcement announcement;

  @override
  Widget build(BuildContext context) {
    final a = announcement;
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final style = announcementStyle(a, scheme);

    return Material(
      color: scheme.surface,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(AppTheme.radius),
        side: BorderSide(color: a.read ? scheme.outlineVariant : style.color.withValues(alpha: 0.5)),
      ),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: () => context.push('/announcements/${a.id}'),
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            AnnouncementIcon(a),
            const SizedBox(width: 12),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Row(children: [
                  Text(a.categoryLabel.toUpperCase(),
                      style: theme.textTheme.labelSmall?.copyWith(color: style.color, fontWeight: FontWeight.w800, letterSpacing: 0.4)),
                  const Spacer(),
                  if (a.sentAt != null) Text(Fmt.date(a.sentAt), style: theme.textTheme.labelSmall?.copyWith(color: scheme.onSurfaceVariant)),
                ]),
                const SizedBox(height: 4),
                Row(children: [
                  if (!a.read) ...[
                    Container(width: 8, height: 8, decoration: BoxDecoration(color: style.color, shape: BoxShape.circle)),
                    const SizedBox(width: 6),
                  ],
                  Expanded(
                    child: Text(a.title,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: theme.textTheme.titleSmall?.copyWith(fontWeight: a.read ? FontWeight.w600 : FontWeight.w800)),
                  ),
                ]),
                if (a.excerpt.isNotEmpty) ...[
                  const SizedBox(height: 4),
                  Text(a.excerpt, maxLines: 2, overflow: TextOverflow.ellipsis, style: theme.textTheme.bodySmall?.copyWith(color: scheme.onSurfaceVariant)),
                ],
                if (a.eventAt != null) ...[
                  const SizedBox(height: 6),
                  Row(children: [
                    Icon(Icons.event_rounded, size: 14, color: scheme.primary),
                    const SizedBox(width: 4),
                    Text('${Fmt.date(a.eventAt)} · ${Fmt.time(a.eventAt)}', style: theme.textTheme.labelMedium?.copyWith(color: scheme.primary)),
                  ]),
                ],
              ]),
            ),
          ]),
        ),
      ),
    );
  }
}
