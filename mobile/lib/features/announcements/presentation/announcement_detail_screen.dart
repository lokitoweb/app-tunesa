import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../core/utils/formatters.dart';
import '../../../core/widgets/async_views.dart';
import '../../../core/widgets/page_padding.dart';
import '../domain/models.dart';
import 'announcement_providers.dart';
import 'announcement_widgets.dart';
import '../../../core/widgets/skeleton.dart';

/// Comunicado completo. Abrirlo lo marca como leído (RR. HH. ve quién lo leyó).
class AnnouncementDetailScreen extends ConsumerWidget {
  const AnnouncementDetailScreen({super.key, required this.id});

  final int id;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final detail = ref.watch(announcementDetailProvider(id));

    return Scaffold(
      appBar: AppBar(title: const Text('Comunicado')),
      body: detail.whenSkeleton(
        loading: () => const SkeletonDetail(image: true),
        error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(announcementDetailProvider(id))),
        data: (a) => _Body(announcement: a),
      ),
    );
  }
}

class _Body extends StatelessWidget {
  const _Body({required this.announcement});

  final Announcement announcement;

  Future<void> _open(BuildContext context, String url) async {
    final ok = await launchUrl(Uri.parse(url), mode: LaunchMode.externalApplication);
    if (!ok && context.mounted) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('No se pudo abrir el enlace.')));
    }
  }

  @override
  Widget build(BuildContext context) {
    final a = announcement;
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final style = announcementStyle(a, scheme);

    Widget info(IconData icon, String text) => Padding(
          padding: const EdgeInsets.only(top: 8),
          child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Icon(icon, size: 18, color: scheme.primary),
            const SizedBox(width: 8),
            Expanded(child: Text(text, style: theme.textTheme.bodyMedium)),
          ]),
        );

    return ListView(
      padding: pagePadding(context),
      children: [
        Row(children: [
          AnnouncementIcon(a, size: 48),
          const SizedBox(width: 12),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(a.categoryLabel.toUpperCase(), style: theme.textTheme.labelMedium?.copyWith(color: style.color, fontWeight: FontWeight.w800)),
              if (a.sentAt != null) Text('Enviado el ${Fmt.date(a.sentAt)}', style: theme.textTheme.bodySmall?.copyWith(color: scheme.onSurfaceVariant)),
            ]),
          ),
        ]),
        const SizedBox(height: 16),
        Text(a.title, style: theme.textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.w800)),
        if (a.eventAt != null) info(Icons.event_rounded, '${Fmt.dayLong(a.eventAt!.toLocal())} · ${Fmt.time(a.eventAt)}'),
        if (a.location != null) info(Icons.place_outlined, a.location!),
        const SizedBox(height: 16),
        SelectableText(a.body ?? a.excerpt, style: theme.textTheme.bodyLarge?.copyWith(height: 1.45)),
        if (a.url != null) ...[
          const SizedBox(height: 20),
          OutlinedButton.icon(onPressed: () => _open(context, a.url!), icon: const Icon(Icons.open_in_new_rounded), label: const Text('Abrir enlace')),
        ],
      ],
    );
  }
}
