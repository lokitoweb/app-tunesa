import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/utils/formatters.dart';
import '../../../core/widgets/async_views.dart';
import '../domain/models.dart';
import 'training_providers.dart';
import '../../../core/widgets/skeleton.dart';

/// Lista de capacitaciones asignadas: pendientes primero, luego completadas.
class TrainingsScreen extends ConsumerWidget {
  const TrainingsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final trainings = ref.watch(trainingsProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Capacitaciones')),
      body: trainings.whenSkeleton(
        loading: () => const LoadingView(),
        error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(trainingsProvider)),
        data: (list) {
          final pending = list.where((t) => !t.isCompleted).toList();
          final done = list.where((t) => t.isCompleted).toList();
          return RefreshIndicator(
            onRefresh: () => ref.refresh(trainingsProvider.future),
            child: list.isEmpty
                ? ListView(children: const [
                    SizedBox(height: 80),
                    EmptyView(icon: Icons.school_outlined, message: 'No tienes capacitaciones asignadas por ahora.'),
                  ])
                : ListView(
                    padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
                    children: [
                      if (pending.isNotEmpty) ...[
                        _SectionTitle('Por completar (${pending.length})'),
                        for (final t in pending) TrainingCard(training: t),
                      ],
                      if (done.isNotEmpty) ...[
                        _SectionTitle('Completadas (${done.length})'),
                        for (final t in done) TrainingCard(training: t),
                      ],
                    ],
                  ),
          );
        },
      ),
    );
  }
}

class _SectionTitle extends StatelessWidget {
  const _SectionTitle(this.text);

  final String text;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.fromLTRB(4, 16, 4, 8),
        child: Text(text, style: Theme.of(context).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
      );
}

/// Tarjeta de un curso con avance y fecha límite.
class TrainingCard extends StatelessWidget {
  const TrainingCard({super.key, required this.training});

  final TrainingSummary training;

  @override
  Widget build(BuildContext context) {
    final t = training;
    final scheme = Theme.of(context).colorScheme;
    final days = t.daysLeft();
    final (Color tone, IconData icon) = switch (t.status) {
      TrainingStatus.completed => (Colors.green.shade700, Icons.verified_rounded),
      TrainingStatus.overdue => (scheme.error, Icons.alarm_rounded),
      TrainingStatus.locked => (scheme.error, Icons.lock_rounded),
      TrainingStatus.inProgress => (scheme.primary, Icons.play_circle_rounded),
      TrainingStatus.pending => (scheme.primary, Icons.school_rounded),
    };
    final dueText = t.isCompleted
        ? 'Completada el ${Fmt.date(t.completedAt)}${t.score != null ? ' · Nota ${t.score}' : ''}'
        : days == null
            ? 'Sin fecha límite'
            : days < 0
                ? 'Venció el ${Fmt.date(t.dueDate)}'
                : days == 0
                    ? 'Vence hoy'
                    : 'Vence en $days día${days == 1 ? '' : 's'}';

    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: () => context.push('/trainings/${t.id}'),
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Container(
                padding: const EdgeInsets.all(10),
                decoration: BoxDecoration(color: tone.withValues(alpha: 0.12), borderRadius: BorderRadius.circular(12)),
                child: Icon(icon, color: tone),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(t.title, style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700)),
                  const SizedBox(height: 2),
                  Text(
                    [t.categoryLabel, if (t.estimatedMinutes != null) Fmt.minutes(t.estimatedMinutes), if (t.isMandatory) 'Obligatoria'].join(' · '),
                    style: TextStyle(color: scheme.onSurfaceVariant, fontSize: 13),
                  ),
                ]),
              ),
            ]),
            const SizedBox(height: 14),
            ClipRRect(
              borderRadius: BorderRadius.circular(8),
              child: LinearProgressIndicator(
                value: t.progress / 100,
                minHeight: 8,
                color: t.isCompleted ? Colors.green.shade600 : null,
                semanticsLabel: 'Avance',
                semanticsValue: '${t.progress}%',
              ),
            ),
            const SizedBox(height: 8),
            Row(children: [
              Expanded(
                child: Text(dueText,
                    style: TextStyle(fontSize: 13, color: t.status == TrainingStatus.overdue ? scheme.error : scheme.onSurfaceVariant)),
              ),
              Text('${t.progress}%', style: const TextStyle(fontWeight: FontWeight.w700)),
            ]),
          ]),
        ),
      ),
    );
  }
}
