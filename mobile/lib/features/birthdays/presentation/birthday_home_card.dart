import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../domain/models.dart';
import 'birthday_providers.dart';
import 'birthday_widgets.dart';

/// "Próximos cumpleaños" en Inicio: hasta 3 personas y "Ver todos".
/// No se muestra si la empresa no usa el módulo o no hay cumpleaños cerca.
class BirthdayHomeCard extends ConsumerWidget {
  const BirthdayHomeCard({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (!ref.watch(birthdaysEnabledProvider)) return const SizedBox.shrink();
    final upcoming = ref.watch(upcomingBirthdaysProvider).value;
    if (upcoming == null || upcoming.items.isEmpty) return const SizedBox.shrink();

    final theme = Theme.of(context);
    final shown = upcoming.items.take(3).toList();
    final rest = upcoming.total - shown.length;

    return Padding(
      padding: const EdgeInsets.only(bottom: 16),
      child: Card(
        key: const Key('home-birthdays'),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 14, 16, 16),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              Icon(Icons.card_giftcard_rounded, color: theme.colorScheme.primary),
              const SizedBox(width: 8),
              Expanded(child: Text('Próximos cumpleaños', style: theme.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w800))),
              TextButton(onPressed: () => context.push('/birthdays'), child: const Text('Ver todos')),
            ]),
            const SizedBox(height: 8),
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                for (final b in shown) Expanded(child: _Person(birthday: b)),
                Expanded(
                  child: InkWell(
                    borderRadius: BorderRadius.circular(16),
                    onTap: () => context.push('/birthdays'),
                    child: Column(children: [
                      CircleAvatar(
                        radius: 28,
                        backgroundColor: theme.colorScheme.secondaryContainer,
                        child: Text(rest > 0 ? '$rest+' : '•••',
                            style: TextStyle(color: theme.colorScheme.onSecondaryContainer, fontWeight: FontWeight.w800, fontSize: 16)),
                      ),
                      const SizedBox(height: 6),
                      Text('Ver todos', style: theme.textTheme.labelMedium?.copyWith(fontWeight: FontWeight.w700)),
                    ]),
                  ),
                ),
                for (var i = shown.length; i < 3; i++) const Expanded(child: SizedBox.shrink()),
              ],
            ),
          ]),
        ),
      ),
    );
  }
}

class _Person extends StatelessWidget {
  const _Person({required this.birthday});

  final Birthday birthday;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return InkWell(
      borderRadius: BorderRadius.circular(16),
      onTap: () => showBirthdayDetail(context, birthday),
      child: Column(children: [
        BirthdayAvatar(birthday: birthday),
        const SizedBox(height: 6),
        Text(birthday.firstName.toUpperCase(),
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: theme.textTheme.labelMedium?.copyWith(color: theme.colorScheme.onSurfaceVariant)),
        Text(birthday.isToday ? '¡Hoy! 🎂' : birthday.shortDate,
            style: theme.textTheme.labelLarge?.copyWith(fontWeight: FontWeight.w800, color: birthday.isToday ? theme.colorScheme.primary : null)),
      ]),
    );
  }
}
