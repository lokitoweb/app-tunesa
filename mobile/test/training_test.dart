import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:tunesa_mobile/core/config/app_config.dart';
import 'package:tunesa_mobile/core/theme/app_theme.dart';
import 'package:tunesa_mobile/features/notifications/domain/models.dart';
import 'package:tunesa_mobile/features/training/domain/models.dart';
import 'package:tunesa_mobile/features/training/presentation/quiz_screen.dart';
import 'package:tunesa_mobile/features/training/presentation/training_detail_screen.dart';
import 'package:tunesa_mobile/features/training/presentation/training_home_card.dart';
import 'package:tunesa_mobile/features/training/presentation/training_providers.dart';

Map<String, dynamic> _detailJson({String status = 'in_progress', bool lessonsDone = false}) => {
      'id': 7,
      'title': 'Manejo defensivo',
      'category_label': 'Seguridad y salud en el trabajo',
      'estimated_minutes': 30,
      'is_mandatory': true,
      'passing_score': 70,
      'lessons_count': 2,
      'has_quiz': true,
      'status': status,
      'progress': lessonsDone ? 66 : 33,
      'attempts': 0,
      'due_date': '2026-10-20',
      'description': 'Para choferes',
      'lessons': [
        {'id': 1, 'title': 'Introducción', 'type': 'text', 'content': 'Hola', 'completed': true},
        {'id': 2, 'title': 'Manual', 'type': 'pdf', 'has_file': true, 'completed': lessonsDone},
      ],
      'questions': [
        {'id': 10, 'question': '¿Cinturón?', 'options': ['Sí', 'No']},
      ],
    };

Future<void> _pump(WidgetTester tester, Widget child, {List overrides = const []}) async {
  await tester.pumpWidget(ProviderScope(
    overrides: [...overrides],
    child: MaterialApp(theme: AppTheme.light(), home: Scaffold(body: child)),
  ));
  await tester.pump();
}

void main() {
  setUpAll(() => initializeDateFormatting('es'));

  group('Modelos de capacitaciones', () {
    test('lee el detalle, el estado y la siguiente lección', () {
      final d = TrainingDetail.fromJson(_detailJson());
      expect(d.summary.status, TrainingStatus.inProgress);
      expect(d.summary.hasQuiz, isTrue);
      expect(d.lessons.map((l) => l.type), [LessonType.text, LessonType.pdf]);
      expect(d.allLessonsDone, isFalse);
      expect(d.nextLesson?.id, 2);
      expect(d.questions.single.options, ['Sí', 'No']);
      expect(d.summary.daysLeft(DateTime(2026, 10, 18)), 2);
    });

    test('valores faltantes no rompen el parseo', () {
      final s = TrainingSummary.fromJson({'id': '3', 'status': 'raro', 'progress': 150});
      expect(s.status, TrainingStatus.pending);
      expect(s.progress, 100);
      expect(s.daysLeft(), isNull);
      expect(const ModulesConfig().trainings, isTrue);
      expect(ModulesConfig.fromJson({'trainings': false}).trainings, isFalse);
    });

    test('el resultado del cuestionario', () {
      final r = QuizResult.fromJson({
        'message': '¡Aprobaste!',
        'data': {'score': 100, 'passed': true, 'correct': 2, 'total': 2, 'passing_score': 70},
      });
      expect(r.passed, isTrue);
      expect(r.score, 100);
    });

    test('los avisos de capacitación abren Capacitaciones', () {
      expect(AppNotification.routeFor('training.assigned'), '/trainings');
      expect(AppNotification.routeFor('training.due_soon'), '/trainings');
    });
  });

  group('Pantallas de capacitaciones', () {
    testWidgets('el detalle muestra lecciones vistas y bloquea el cuestionario', (tester) async {
      await _pump(tester, TrainingDetailBody(detail: TrainingDetail.fromJson(_detailJson())));
      expect(find.text('Manejo defensivo'), findsOneWidget);
      expect(find.text('Introducción'), findsOneWidget);
      expect(find.byIcon(Icons.check_rounded), findsOneWidget);
      expect(find.text('Se habilita al terminar todas las lecciones'), findsOneWidget);
    });

    testWidgets('el resultado aprobado ofrece la constancia', (tester) async {
      const r = QuizResult(score: 100, passed: true, correct: 1, total: 1, passingScore: 70, message: '¡Aprobaste!');
      await tester.pumpWidget(MaterialApp(home: QuizResultView(result: r, courseId: 7, onRetry: () {})));
      expect(find.text('Ver mi constancia'), findsOneWidget);
      expect(find.text('Intentar otra vez'), findsNothing);
    });

    testWidgets('el desaprobado permite reintentar', (tester) async {
      var retried = false;
      const r = QuizResult(score: 50, passed: false, correct: 1, total: 2, passingScore: 70, message: 'Repasa');
      await tester.pumpWidget(MaterialApp(home: QuizResultView(result: r, courseId: 7, onRetry: () => retried = true)));
      await tester.tap(find.text('Intentar otra vez'));
      expect(retried, isTrue);
    });

    testWidgets('Inicio avisa las pendientes y se oculta sin ellas', (tester) async {
      final pending = [TrainingSummary.fromJson({'id': 1, 'title': 'Inducción', 'status': 'overdue', 'due_date': '2026-01-01'})];
      await _pump(tester, const TrainingHomeCard(), overrides: [
        trainingsEnabledProvider.overrideWithValue(true),
        trainingsProvider.overrideWith((ref) async => pending),
      ]);
      await tester.pump();
      expect(find.text('Tienes 1 capacitación pendiente'), findsOneWidget);
      expect(find.textContaining('vencida'), findsOneWidget);

      await _pump(tester, const TrainingHomeCard(), overrides: [
        trainingsEnabledProvider.overrideWithValue(false),
        trainingsProvider.overrideWith((ref) async => pending),
      ]);
      await tester.pump();
      expect(find.byKey(const Key('home-trainings')), findsNothing);
    });
  });
}
