import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../domain/models.dart';
import 'birthday_providers.dart';
import 'birthday_widgets.dart';

/// "Próximos cumpleaños" en Inicio (entre las marcaciones y los accesos
/// rápidos): carrusel horizontal con hasta 5 personas de lo que queda del mes,
/// solo de la empresa y sede de quien usa la app.
/// No se muestra si la empresa no usa el módulo o si la consulta falla.
class BirthdayHomeCard extends ConsumerWidget {
  const BirthdayHomeCard({super.key});

  static const _months = [
    'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
    'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre',
  ];

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (!ref.watch(birthdaysEnabledProvider)) return const SizedBox.shrink();
    final upcoming = ref.watch(upcomingBirthdaysProvider).value;
    if (upcoming == null) return const SizedBox.shrink();

    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final shown = upcoming.items.take(homeBirthdaysLimit).toList();
    final rest = upcoming.total - shown.length;
    final month = _months[DateTime.now().month - 1];

    return Padding(
      padding: const EdgeInsets.only(bottom: 16),
      child: Card(
        key: const Key('home-birthdays'),
        clipBehavior: Clip.antiAlias,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 14, 8, 14),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              Container(
                width: 36,
                height: 36,
                decoration: BoxDecoration(color: scheme.primary.withValues(alpha: 0.10), borderRadius: BorderRadius.circular(10)),
                child: Icon(Icons.cake_rounded, color: scheme.primary, size: 20),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text('Próximos cumpleaños', style: theme.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w800)),
                  Text('En tu sede · resto de $month',
                      style: theme.textTheme.bodySmall?.copyWith(color: scheme.onSurfaceVariant)),
                ]),
              ),
              TextButton(onPressed: () => context.push('/birthdays'), child: const Text('Ver todos')),
            ]),
            const SizedBox(height: 10),
            if (shown.isEmpty)
              Padding(
                key: const Key('home-birthdays-empty'),
                padding: const EdgeInsets.only(right: 8, bottom: 2),
                child: Text('Nadie de tu sede cumple años en lo que queda de $month.',
                    style: theme.textTheme.bodyMedium?.copyWith(color: scheme.onSurfaceVariant)),
              )
            else
              SizedBox(
                height: 134,
                child: ListView.separated(
                  key: const Key('home-birthdays-carousel'),
                  scrollDirection: Axis.horizontal,
                  padding: const EdgeInsets.only(right: 8),
                  itemCount: shown.length + (rest > 0 ? 1 : 0),
                  separatorBuilder: (_, _) => const SizedBox(width: 6),
                  itemBuilder: (context, i) => i < shown.length
                      ? _Person(birthday: shown[i])
                      : _More(count: rest, onTap: () => context.push('/birthdays')),
                ),
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
    final scheme = theme.colorScheme;
    final today = birthday.isToday;

    return SizedBox(
      width: 84,
      child: InkWell(
        borderRadius: BorderRadius.circular(16),
        onTap: () => showBirthdayDetail(context, birthday),
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: 4),
          child: Column(children: [
            BirthdayAvatar(birthday: birthday, radius: 26),
            const SizedBox(height: 6),
            Text(birthday.firstName.toUpperCase(),
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: theme.textTheme.labelMedium?.copyWith(color: scheme.onSurfaceVariant)),
            const SizedBox(height: 2),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
              decoration: BoxDecoration(
                color: today ? scheme.primary : scheme.surfaceContainerHighest,
                borderRadius: BorderRadius.circular(20),
              ),
              child: Text(today ? '¡Hoy! 🎂' : birthday.shortDate,
                  style: theme.textTheme.labelSmall?.copyWith(
                    fontWeight: FontWeight.w800,
                    color: today ? scheme.onPrimary : scheme.onSurface,
                  )),
            ),
          ]),
        ),
      ),
    );
  }
}

/// "N+" al final del carrusel: lleva a la pantalla de cumpleaños.
class _More extends StatelessWidget {
  const _More({required this.count, required this.onTap});

  final int count;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;

    return SizedBox(
      width: 84,
      child: InkWell(
        borderRadius: BorderRadius.circular(16),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: 4),
          child: Column(children: [
            CircleAvatar(
              radius: 26,
              backgroundColor: scheme.secondaryContainer,
              child: Text('$count+', style: TextStyle(color: scheme.onSecondaryContainer, fontWeight: FontWeight.w800, fontSize: 15)),
            ),
            const SizedBox(height: 6),
            Text('Ver más', style: theme.textTheme.labelMedium?.copyWith(fontWeight: FontWeight.w700)),
          ]),
        ),
      ),
    );
  }
}
