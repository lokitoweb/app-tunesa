import 'package:flutter/material.dart';

import '../../../core/theme/app_theme.dart';
import '../domain/models.dart';
import 'birthday_gifts.dart';

/// Foto de la persona (o sus iniciales). Si cumple hoy, lleva un aro de color.
class BirthdayAvatar extends StatelessWidget {
  const BirthdayAvatar({super.key, required this.birthday, this.radius = 28});

  final Birthday birthday;
  final double radius;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final avatar = CircleAvatar(
      radius: radius,
      backgroundColor: scheme.primaryContainer,
      foregroundImage: birthday.avatarUrl == null ? null : NetworkImage(birthday.avatarUrl!),
      onForegroundImageError: birthday.avatarUrl == null ? null : (_, _) {},
      child: Text(
        birthday.initials,
        style: TextStyle(color: scheme.onPrimaryContainer, fontWeight: FontWeight.w700, fontSize: radius * 0.6),
      ),
    );
    if (!birthday.isToday) return avatar;

    // Cumple hoy: aro de color y un regalo en la esquina (se le pueden enviar regalos)
    return Stack(clipBehavior: Clip.none, children: [
      Container(
        padding: const EdgeInsets.all(3),
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          gradient: LinearGradient(colors: [scheme.primary, scheme.tertiary, scheme.secondary]),
        ),
        child: Container(
          padding: const EdgeInsets.all(2),
          decoration: BoxDecoration(shape: BoxShape.circle, color: scheme.surface),
          child: avatar,
        ),
      ),
      Positioned(
        right: -2,
        bottom: -2,
        child: Container(
          key: const Key('birthday-gift-badge'),
          padding: EdgeInsets.all(radius * 0.09),
          decoration: BoxDecoration(shape: BoxShape.circle, color: scheme.surface, border: Border.all(color: scheme.primary, width: 1.5)),
          child: Text('🎁', style: TextStyle(fontSize: radius * 0.42, height: 1)),
        ),
      ),
    ]);
  }
}

/// Tarjeta de la lista de cumpleaños (mes): foto, fecha, nombre y cargo.
class BirthdayTile extends StatelessWidget {
  const BirthdayTile({super.key, required this.birthday, this.muted = false});

  final Birthday birthday;

  /// Cumpleaños pasados: tono más suave.
  final bool muted;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final today = birthday.isToday;

    return Card(
      margin: const EdgeInsets.only(bottom: 10),
      color: today ? scheme.primaryContainer : null,
      child: InkWell(
        borderRadius: BorderRadius.circular(AppTheme.radius),
        onTap: () => showBirthdayDetail(context, birthday),
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Row(children: [
            Opacity(opacity: muted ? 0.75 : 1, child: BirthdayAvatar(birthday: birthday)),
            const SizedBox(width: 14),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Row(children: [
                  Flexible(
                    child: Text(birthday.dateLabel,
                        style: theme.textTheme.titleSmall?.copyWith(color: muted ? scheme.onSurfaceVariant : null)),
                  ),
                  if (today) ...[
                    const SizedBox(width: 8),
                    _Pill(text: '¡Hoy! 🎂', color: scheme.primary),
                  ] else if (!muted && birthday.daysUntil <= 7) ...[
                    const SizedBox(width: 8),
                    _Pill(text: birthday.whenLabel, color: scheme.secondary),
                  ],
                ]),
                const SizedBox(height: 2),
                Text(birthday.name.toUpperCase(),
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: theme.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w800, letterSpacing: 0.2)),
                if (birthday.position != null)
                  Text(birthday.position!.toUpperCase(),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.bodySmall?.copyWith(color: scheme.onSurfaceVariant)),
              ]),
            ),
            Icon(Icons.chevron_right_rounded, color: scheme.onSurfaceVariant),
          ]),
        ),
      ),
    );
  }
}

class _Pill extends StatelessWidget {
  const _Pill({required this.text, required this.color});

  final String text;
  final Color color;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
        decoration: BoxDecoration(color: color.withValues(alpha: 0.14), borderRadius: BorderRadius.circular(20)),
        child: Text(text, style: TextStyle(color: color, fontSize: 12, fontWeight: FontWeight.w700)),
      );
}

/// Ficha de la persona al tocarla (hoja inferior).
Future<void> showBirthdayDetail(BuildContext context, Birthday b) => showModalBottomSheet<void>(
      context: context,
      showDragHandle: true,
      isScrollControlled: true,
      builder: (context) {
        final theme = Theme.of(context);
        final scheme = theme.colorScheme;
        Widget row(IconData icon, String label, String? value) => value == null || value.isEmpty
            ? const SizedBox.shrink()
            : ListTile(
                dense: true,
                leading: Icon(icon, color: scheme.primary),
                title: Text(label, style: theme.textTheme.bodySmall?.copyWith(color: scheme.onSurfaceVariant)),
                subtitle: Text(value, style: theme.textTheme.bodyLarge),
              );

        return SafeArea(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              BirthdayAvatar(birthday: b, radius: 44),
              const SizedBox(height: 12),
              Text(b.name, textAlign: TextAlign.center, style: theme.textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w800)),
              const SizedBox(height: 6),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 6),
                decoration: BoxDecoration(color: scheme.primaryContainer, borderRadius: BorderRadius.circular(24)),
                child: Text(
                  b.isToday ? '🎂 ¡Hoy es su cumpleaños!' : '🎈 ${b.dateLabel} · ${b.whenLabel}',
                  style: TextStyle(color: scheme.onPrimaryContainer, fontWeight: FontWeight.w700),
                ),
              ),
              const SizedBox(height: 12),
              row(Icons.badge_outlined, 'Cargo', b.position),
              row(Icons.apartment_outlined, 'Área', b.department),
              row(Icons.place_outlined, 'Sede', b.branch),
              const SizedBox(height: 12),
              // Hoy: galería para enviarle un regalo; otro día: los regalos que recibió
              SizedBox(
                width: double.infinity,
                child: b.isToday
                    ? FilledButton.icon(
                        key: const Key('birthday-send-gift'),
                        onPressed: () => showGiftsSheet(context, userId: b.id, firstName: b.firstName),
                        icon: const Text('🎁', style: TextStyle(fontSize: 18)),
                        label: const Text('Regalos de cumpleaños'),
                      )
                    : OutlinedButton.icon(
                        key: const Key('birthday-gift-history'),
                        onPressed: () => showGiftsSheet(context, userId: b.id, firstName: b.firstName),
                        icon: const Icon(Icons.card_giftcard_rounded),
                        label: const Text('Regalos recibidos'),
                      ),
              ),
            ]),
          ),
        );
      },
    );
