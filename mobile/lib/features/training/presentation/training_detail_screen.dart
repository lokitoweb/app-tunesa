import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/widgets/async_views.dart';
import '../domain/models.dart';
import 'training_providers.dart';
import 'trainings_screen.dart';

/// Detalle de un curso: avance, lecciones, cuestionario y constancia.
class TrainingDetailScreen extends ConsumerWidget {
  const TrainingDetailScreen({super.key, required this.id});

  final int id;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final detail = ref.watch(trainingDetailProvider(id));

    return Scaffold(
      appBar: AppBar(title: const Text('Capacitación')),
      body: detail.when(
        loading: () => const LoadingView(),
        error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(trainingDetailProvider(id))),
        data: (d) => RefreshIndicator(
          onRefresh: () => ref.refresh(trainingDetailProvider(id).future),
          child: TrainingDetailBody(detail: d),
        ),
      ),
      bottomNavigationBar: detail.whenOrNull(data: (d) => _ActionBar(detail: d)),
    );
  }
}

/// Contenido del detalle (separado para probarlo con datos fijos).
class TrainingDetailBody extends StatelessWidget {
  const TrainingDetailBody({super.key, required this.detail});

  final TrainingDetail detail;

  @override
  Widget build(BuildContext context) {
    final s = detail.summary;
    final scheme = Theme.of(context).colorScheme;

    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
      children: [
        TrainingCard(training: s),
        if (detail.description != null)
          Padding(
            padding: const EdgeInsets.fromLTRB(4, 4, 4, 8),
            child: Text(detail.description!, style: TextStyle(color: scheme.onSurfaceVariant, height: 1.4)),
          ),
        Padding(
          padding: const EdgeInsets.fromLTRB(4, 12, 4, 8),
          child: Text('Lecciones', style: Theme.of(context).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
        ),
        Card(
          child: Column(children: [
            for (final (i, l) in detail.lessons.indexed) ...[
              if (i > 0) const Divider(height: 1),
              ListTile(
                key: Key('lesson-${l.id}'),
                leading: CircleAvatar(
                  backgroundColor: l.completed ? Colors.green.shade50 : scheme.primaryContainer,
                  foregroundColor: l.completed ? Colors.green.shade700 : scheme.onPrimaryContainer,
                  child: l.completed ? const Icon(Icons.check_rounded) : Text('${i + 1}'),
                ),
                title: Text(l.title),
                subtitle: Text([_typeLabel(l.type), if (l.durationMinutes != null) '${l.durationMinutes} min'].join(' · ')),
                trailing: Icon(_typeIcon(l.type), color: scheme.onSurfaceVariant),
                onTap: () => context.push('/trainings/${s.id}/lessons/${l.id}'),
              ),
            ],
          ]),
        ),
        if (s.hasQuiz) ...[
          Padding(
            padding: const EdgeInsets.fromLTRB(4, 16, 4, 8),
            child: Text('Evaluación', style: Theme.of(context).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
          ),
          Card(
            child: ListTile(
              leading: Icon(Icons.quiz_outlined, color: scheme.primary),
              title: Text('Cuestionario de ${detail.questions.length} preguntas'),
              subtitle: Text(s.isCompleted
                  ? 'Aprobado con ${s.score ?? 100}'
                  : detail.allLessonsDone
                      ? 'Apruebas con ${s.passingScore} de 100${s.attempts > 0 ? ' · Mejor nota: ${s.score ?? 0}' : ''}'
                      : 'Se habilita al terminar todas las lecciones'),
            ),
          ),
        ],
      ],
    );
  }
}

String _typeLabel(LessonType t) => switch (t) {
      LessonType.text => 'Lectura',
      LessonType.video => 'Video',
      LessonType.pdf => 'Documento PDF',
      LessonType.link => 'Enlace',
    };

IconData _typeIcon(LessonType t) => switch (t) {
      LessonType.text => Icons.article_outlined,
      LessonType.video => Icons.play_circle_outline,
      LessonType.pdf => Icons.picture_as_pdf_outlined,
      LessonType.link => Icons.open_in_new_rounded,
    };

class _ActionBar extends StatelessWidget {
  const _ActionBar({required this.detail});

  final TrainingDetail detail;

  @override
  Widget build(BuildContext context) {
    final s = detail.summary;
    final next = detail.nextLesson;

    final Widget button;
    if (s.isCompleted) {
      button = FilledButton.icon(
        onPressed: () => context.push('/trainings/${s.id}/certificate', extra: s.title),
        icon: const Icon(Icons.workspace_premium_rounded),
        label: const Text('Ver mi constancia'),
      );
    } else if (next != null) {
      button = FilledButton.icon(
        onPressed: () => context.push('/trainings/${s.id}/lessons/${next.id}'),
        icon: const Icon(Icons.play_arrow_rounded),
        label: Text(s.progress == 0 ? 'Empezar' : 'Continuar: ${next.title}', overflow: TextOverflow.ellipsis),
      );
    } else if (s.hasQuiz) {
      button = FilledButton.icon(
        onPressed: () => context.push('/trainings/${s.id}/quiz'),
        icon: const Icon(Icons.quiz_rounded),
        label: Text(s.attempts == 0 ? 'Rendir cuestionario' : 'Volver a intentar el cuestionario'),
      );
    } else {
      return const SizedBox.shrink();
    }

    return SafeArea(
      minimum: const EdgeInsets.fromLTRB(16, 8, 16, 16),
      child: SizedBox(height: 52, child: button),
    );
  }
}
