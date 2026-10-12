import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../domain/models.dart';
import 'training_providers.dart';

/// Aviso en Inicio cuando hay capacitaciones por completar (se oculta si no hay
/// o si la empresa no usa el módulo).
class TrainingHomeCard extends ConsumerWidget {
  const TrainingHomeCard({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (!ref.watch(trainingsEnabledProvider)) return const SizedBox.shrink();
    final pending = ref.watch(trainingsProvider).value?.where((t) => !t.isCompleted).toList() ?? const <TrainingSummary>[];
    if (pending.isEmpty) return const SizedBox.shrink();

    final overdue = pending.where((t) => t.status == TrainingStatus.overdue).length;
    final scheme = Theme.of(context).colorScheme;
    final tone = overdue > 0 ? scheme.error : scheme.primary;

    return Padding(
      padding: const EdgeInsets.only(bottom: 16),
      child: Card(
        child: ListTile(
          key: const Key('home-trainings'),
          leading: CircleAvatar(backgroundColor: tone.withValues(alpha: 0.12), foregroundColor: tone, child: const Icon(Icons.school_rounded)),
          title: Text(pending.length == 1 ? 'Tienes 1 capacitación pendiente' : 'Tienes ${pending.length} capacitaciones pendientes',
              style: const TextStyle(fontWeight: FontWeight.w700)),
          subtitle: Text(overdue > 0 ? '$overdue vencida${overdue == 1 ? '' : 's'}: complétala${overdue == 1 ? '' : 's'} hoy' : pending.first.title,
              maxLines: 1, overflow: TextOverflow.ellipsis),
          trailing: const Icon(Icons.chevron_right),
          onTap: () => context.push('/trainings'),
        ),
      ),
    );
  }
}
