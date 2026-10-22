import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../core/network/api_exception.dart';
import '../../../core/widgets/async_views.dart';
import '../domain/models.dart';
import 'training_providers.dart';
import '../../../core/widgets/skeleton.dart';

/// Cuestionario final. Las respuestas correctas solo las conoce el servidor.
class QuizScreen extends ConsumerStatefulWidget {
  const QuizScreen({super.key, required this.courseId});

  final int courseId;

  @override
  ConsumerState<QuizScreen> createState() => _QuizScreenState();
}

class _QuizScreenState extends ConsumerState<QuizScreen> {
  final _answers = <int, int>{};
  bool _sending = false;
  QuizResult? _result;

  Future<void> _submit() async {
    setState(() => _sending = true);
    try {
      final result = await ref.read(trainingRepositoryProvider).submitQuiz(widget.courseId, _answers);
      refreshTraining(ref, widget.courseId);
      if (mounted) setState(() => _result = result);
    } on ApiException catch (e) {
      // 423: agotó los intentos (otro dispositivo o pantalla vieja)
      if (e.code == 'TRAINING_LOCKED') refreshTraining(ref, widget.courseId);
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    } catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.toString())));
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final result = _result;
    if (result != null) {
      return QuizResultView(
        result: result,
        courseId: widget.courseId,
        onRetry: () => setState(() {
          _result = null;
          _answers.clear();
        }),
      );
    }

    final detail = ref.watch(trainingDetailProvider(widget.courseId));
    return Scaffold(
      appBar: AppBar(title: const Text('Cuestionario')),
      body: detail.whenSkeleton(
        loading: () => const LoadingView(),
        error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(trainingDetailProvider(widget.courseId))),
        data: (d) => d.summary.locked
            ? const QuizLockedView()
            : ListView(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
          children: [
            Text('Apruebas con ${d.summary.passingScore} de 100. Responde todas las preguntas.',
                style: TextStyle(color: Theme.of(context).colorScheme.onSurfaceVariant)),
            const SizedBox(height: 4),
            Text(
              d.summary.attemptsLeft == 1
                  ? 'Es tu último intento de ${d.summary.maxAttempts}.'
                  : 'Te quedan ${d.summary.attemptsLeft} de ${d.summary.maxAttempts} intentos.',
              key: const Key('quiz-attempts-left'),
              style: TextStyle(
                fontWeight: FontWeight.w600,
                color: d.summary.attemptsLeft == 1 ? Theme.of(context).colorScheme.error : null,
              ),
            ),
            const SizedBox(height: 12),
            for (final (i, q) in d.questions.indexed)
              Card(
                margin: const EdgeInsets.only(bottom: 12),
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(16, 16, 16, 8),
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text('${i + 1}. ${q.question}', style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
                    const SizedBox(height: 8),
                    RadioGroup<int>(
                      groupValue: _answers[q.id],
                      onChanged: (v) => setState(() => _answers[q.id] = v!),
                      child: Column(children: [
                        for (final (j, option) in q.options.indexed)
                          RadioListTile<int>(
                            key: Key('q${q.id}-o$j'),
                            value: j,
                            title: Text(option),
                            contentPadding: EdgeInsets.zero,
                            dense: true,
                          ),
                      ]),
                    ),
                  ]),
                ),
              ),
          ],
        ),
      ),
      bottomNavigationBar: detail.whenOrNull(
        data: (d) => d.summary.locked ? null : SafeArea(
          minimum: const EdgeInsets.fromLTRB(16, 8, 16, 16),
          child: SizedBox(
            height: 52,
            child: FilledButton(
              key: const Key('quiz-submit'),
              onPressed: _sending || _answers.length < d.questions.length ? null : _submit,
              child: _sending
                  ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2))
                  : Text('Enviar respuestas (${_answers.length}/${d.questions.length})'),
            ),
          ),
        ),
      ),
    );
  }
}

/// Resultado del cuestionario: aprobado (constancia) o repasar e intentar otra vez.
class QuizResultView extends StatelessWidget {
  const QuizResultView({super.key, required this.result, required this.courseId, required this.onRetry});

  final QuizResult result;
  final int courseId;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    final color = result.passed ? Colors.green.shade600 : Theme.of(context).colorScheme.error;
    return Scaffold(
      appBar: AppBar(title: const Text('Resultado')),
      body: Center(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            Icon(result.passed ? Icons.emoji_events_rounded : (result.locked ? Icons.lock_rounded : Icons.replay_circle_filled_rounded), size: 80, color: color),
            const SizedBox(height: 16),
            Text('${result.score}', style: Theme.of(context).textTheme.displayMedium?.copyWith(fontWeight: FontWeight.w800, color: color)),
            Text('${result.correct} de ${result.total} respuestas correctas'),
            const SizedBox(height: 16),
            Text(result.message, textAlign: TextAlign.center, style: const TextStyle(fontSize: 16)),
            const SizedBox(height: 24),
            if (result.passed)
              FilledButton.icon(
                onPressed: () => context.pushReplacement('/trainings/$courseId/certificate'),
                icon: const Icon(Icons.workspace_premium_rounded),
                label: const Text('Ver mi constancia'),
              )
            else if (result.locked)
              FilledButton.icon(
                onPressed: () => context.pop(),
                icon: const Icon(Icons.arrow_back),
                label: const Text('Volver a la capacitación'),
              )
            else ...[
              FilledButton.icon(onPressed: onRetry, icon: const Icon(Icons.refresh), label: const Text('Intentar otra vez')),
              TextButton(onPressed: () => context.pop(), child: const Text('Repasar las lecciones')),
            ],
          ]),
        ),
      ),
    );
  }
}

/// Sin intentos: el cuestionario queda cerrado hasta que RRHH lo desbloquee.
class QuizLockedView extends StatelessWidget {
  const QuizLockedView({super.key});

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          Icon(Icons.lock_rounded, size: 72, color: Theme.of(context).colorScheme.error),
          const SizedBox(height: 16),
          const Text(
            'Agotaste los intentos de este cuestionario. RRHH ya fue avisado y puede darte intentos extra; te llegará un aviso.',
            textAlign: TextAlign.center,
            style: TextStyle(fontSize: 16),
          ),
        ]),
      ),
    );
  }
}
