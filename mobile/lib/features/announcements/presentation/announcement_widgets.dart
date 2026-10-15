import 'package:flutter/material.dart';

import '../../../core/theme/app_theme.dart';
import '../domain/models.dart';

/// Ícono y color por tipo de comunicado.
({IconData icon, Color color}) announcementStyle(Announcement a, ColorScheme scheme) => switch (a.category) {
      'urgent' => (icon: Icons.warning_amber_rounded, color: scheme.error),
      'meeting' => (icon: Icons.groups_rounded, color: scheme.primary),
      'update' => (icon: Icons.system_update_alt_rounded, color: AppTheme.success),
      'reminder' => (icon: Icons.notifications_active_rounded, color: const Color(0xFFF59E0B)),
      _ => (icon: Icons.campaign_rounded, color: scheme.primary),
    };

class AnnouncementIcon extends StatelessWidget {
  const AnnouncementIcon(this.announcement, {super.key, this.size = 40});

  final Announcement announcement;
  final double size;

  @override
  Widget build(BuildContext context) {
    final style = announcementStyle(announcement, Theme.of(context).colorScheme);
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(color: style.color.withValues(alpha: 0.12), borderRadius: BorderRadius.circular(8)),
      child: Icon(style.icon, color: style.color, size: size * 0.55),
    );
  }
}
