import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import 'announcement_providers.dart';
import 'announcements_screen.dart';

/// En Inicio: los comunicados importantes o urgentes que aún no se leyeron
/// (máximo 2). Desaparece al leerlos.
class AnnouncementHomeCard extends ConsumerWidget {
  const AnnouncementHomeCard({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (!ref.watch(announcementsEnabledProvider)) return const SizedBox.shrink();
    final feed = ref.watch(announcementFeedProvider).value;
    final items = feed?.highlighted ?? const [];
    if (items.isEmpty) return const SizedBox.shrink();

    return Padding(
      padding: const EdgeInsets.only(bottom: 16),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        for (final a in items.take(2)) ...[AnnouncementTile(announcement: a), const SizedBox(height: 8)],
        if (feed!.unread > 2)
          Align(
            alignment: Alignment.centerRight,
            child: TextButton(onPressed: () => context.push('/announcements'), child: Text('Ver los ${feed.unread} sin leer')),
          ),
      ]),
    );
  }
}
