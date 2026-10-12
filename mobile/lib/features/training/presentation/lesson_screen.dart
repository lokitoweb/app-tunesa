import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../core/widgets/async_views.dart';
import '../../../core/widgets/pdf_bytes_view.dart';
import '../domain/models.dart';
import 'training_providers.dart';

final _lessonPdfProvider = FutureProvider.autoDispose.family<Uint8List, (int, int)>(
  (ref, ids) => ref.watch(trainingRepositoryProvider).lessonPdf(ids.$1, ids.$2),
);

/// Una lección: lectura, PDF, o video / enlace que se abre fuera de la app.
/// "Marcar como vista" registra el avance y pasa a la siguiente.
class LessonScreen extends ConsumerStatefulWidget {
  const LessonScreen({super.key, required this.courseId, required this.lessonId});

  final int courseId;
  final int lessonId;

  @override
  ConsumerState<LessonScreen> createState() => _LessonScreenState();
}

class _LessonScreenState extends ConsumerState<LessonScreen> {
  bool _saving = false;

  Future<void> _complete(TrainingDetail detail, TrainingLesson lesson) async {
    setState(() => _saving = true);
    final messenger = ScaffoldMessenger.of(context);
    final router = GoRouter.of(context);
    try {
      await ref.read(trainingRepositoryProvider).completeLesson(widget.courseId, lesson.id);
      refreshTraining(ref, widget.courseId);
      final idx = detail.lessons.indexWhere((l) => l.id == lesson.id);
      final next = idx >= 0 && idx + 1 < detail.lessons.length ? detail.lessons[idx + 1] : null;
      if (!mounted) return;
      if (next != null) {
        router.pushReplacement('/trainings/${widget.courseId}/lessons/${next.id}');
      } else {
        messenger.showSnackBar(SnackBar(
          content: Text(detail.summary.hasQuiz
              ? '¡Terminaste las lecciones! Ahora rinde el cuestionario.'
              : '¡Completaste la capacitación!'),
        ));
        router.pop();
      }
    } catch (e) {
      messenger.showSnackBar(SnackBar(content: Text(e.toString())));
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  Future<void> _open(String url) async {
    final messenger = ScaffoldMessenger.of(context);
    var ok = false;
    try {
      ok = await launchUrl(Uri.parse(url), mode: LaunchMode.externalApplication);
    } catch (_) {}
    if (!ok) messenger.showSnackBar(const SnackBar(content: Text('No se pudo abrir el enlace.')));
  }

  @override
  Widget build(BuildContext context) {
    final detail = ref.watch(trainingDetailProvider(widget.courseId));

    return detail.when(
      loading: () => const Scaffold(body: LoadingView()),
      error: (e, _) => Scaffold(
        appBar: AppBar(),
        body: ErrorView(error: e, onRetry: () => ref.invalidate(trainingDetailProvider(widget.courseId))),
      ),
      data: (d) {
        final lesson = d.lessons.where((l) => l.id == widget.lessonId).firstOrNull;
        if (lesson == null) {
          return Scaffold(appBar: AppBar(), body: const EmptyView(icon: Icons.search_off, message: 'Esta lección ya no existe.'));
        }
        final position = d.lessons.indexOf(lesson) + 1;

        return Scaffold(
          appBar: AppBar(title: Text('Lección $position de ${d.lessons.length}')),
          body: switch (lesson.type) {
            LessonType.pdf => Column(children: [
                _Title(lesson: lesson),
                Expanded(
                  child: ref.watch(_lessonPdfProvider((widget.courseId, lesson.id))).when(
                        loading: () => const LoadingView(message: 'Descargando documento…'),
                        error: (e, _) => ErrorView(
                            error: e, onRetry: () => ref.invalidate(_lessonPdfProvider((widget.courseId, lesson.id)))),
                        data: (bytes) => PdfBytesView(bytes: bytes),
                      ),
                ),
              ]),
            LessonType.text => ListView(
                padding: const EdgeInsets.fromLTRB(20, 0, 20, 24),
                children: [
                  _Title(lesson: lesson, padding: EdgeInsets.zero),
                  const SizedBox(height: 12),
                  SelectableText(lesson.content ?? '', style: const TextStyle(fontSize: 16, height: 1.55)),
                ],
              ),
            LessonType.video || LessonType.link => ListView(
                padding: const EdgeInsets.fromLTRB(20, 0, 20, 24),
                children: [
                  _Title(lesson: lesson, padding: EdgeInsets.zero),
                  const SizedBox(height: 24),
                  Icon(lesson.type == LessonType.video ? Icons.ondemand_video_rounded : Icons.public_rounded,
                      size: 72, color: Theme.of(context).colorScheme.primary),
                  const SizedBox(height: 16),
                  Text(
                    lesson.type == LessonType.video
                        ? 'El video se abre en tu reproductor. Cuando lo termines, vuelve y márcalo como visto.'
                        : 'El contenido se abre en tu navegador. Cuando lo revises, vuelve y márcalo como visto.',
                    textAlign: TextAlign.center,
                  ),
                  const SizedBox(height: 16),
                  if (lesson.url != null)
                    OutlinedButton.icon(
                      onPressed: () => _open(lesson.url!),
                      icon: const Icon(Icons.open_in_new_rounded),
                      label: Text(lesson.type == LessonType.video ? 'Ver video' : 'Abrir enlace'),
                    ),
                ],
              ),
          },
          bottomNavigationBar: SafeArea(
            minimum: const EdgeInsets.fromLTRB(16, 8, 16, 16),
            child: SizedBox(
              height: 52,
              child: lesson.completed
                  ? OutlinedButton.icon(
                      onPressed: _saving ? null : () => _complete(d, lesson),
                      icon: const Icon(Icons.check_rounded),
                      label: Text(position < d.lessons.length ? 'Vista · Siguiente lección' : 'Vista · Volver'),
                    )
                  : FilledButton.icon(
                      key: const Key('lesson-complete'),
                      onPressed: _saving ? null : () => _complete(d, lesson),
                      icon: _saving
                          ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))
                          : const Icon(Icons.check_circle_rounded),
                      label: const Text('Marcar como vista'),
                    ),
            ),
          ),
        );
      },
    );
  }
}

class _Title extends StatelessWidget {
  const _Title({required this.lesson, this.padding = const EdgeInsets.fromLTRB(20, 8, 20, 12)});

  final TrainingLesson lesson;
  final EdgeInsets padding;

  @override
  Widget build(BuildContext context) => Padding(
        padding: padding,
        child: Align(
          alignment: Alignment.centerLeft,
          child: Text(lesson.title, style: Theme.of(context).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w700)),
        ),
      );
}
