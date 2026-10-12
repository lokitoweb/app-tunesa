/// Capacitaciones del colaborador (`GET /api/trainings`).
library;

enum TrainingStatus {
  pending('Sin empezar'),
  inProgress('En curso'),
  completed('Completado'),
  overdue('Vencido');

  const TrainingStatus(this.label);

  final String label;

  static TrainingStatus fromApi(Object? raw) => switch (raw?.toString()) {
        'in_progress' => TrainingStatus.inProgress,
        'completed' => TrainingStatus.completed,
        'overdue' => TrainingStatus.overdue,
        _ => TrainingStatus.pending,
      };
}

int _int(Object? v, [int fallback = 0]) => v is num ? v.toInt() : int.tryParse(v?.toString() ?? '') ?? fallback;

int? _intOrNull(Object? v) => v is num ? v.toInt() : int.tryParse(v?.toString() ?? '');

String? _str(Object? v) {
  final s = v?.toString().trim();
  return s == null || s.isEmpty ? null : s;
}

class TrainingSummary {
  const TrainingSummary({
    required this.id,
    required this.title,
    required this.categoryLabel,
    required this.status,
    required this.progress,
    this.estimatedMinutes,
    this.isMandatory = true,
    this.passingScore = 70,
    this.lessonsCount = 0,
    this.hasQuiz = false,
    this.score,
    this.attempts = 0,
    this.dueDate,
    this.completedAt,
  });

  final int id;
  final String title;
  final String categoryLabel;
  final TrainingStatus status;
  final int progress;
  final int? estimatedMinutes;
  final bool isMandatory;
  final int passingScore;
  final int lessonsCount;
  final bool hasQuiz;
  final int? score;
  final int attempts;
  final DateTime? dueDate;
  final DateTime? completedAt;

  bool get isCompleted => status == TrainingStatus.completed;

  /// Días que faltan para la fecha límite (negativo = vencido); null sin plazo.
  int? daysLeft([DateTime? now]) {
    final due = dueDate;
    if (due == null) return null;
    final today = now ?? DateTime.now();
    return DateTime(due.year, due.month, due.day).difference(DateTime(today.year, today.month, today.day)).inDays;
  }

  factory TrainingSummary.fromJson(Map<String, dynamic> json) => TrainingSummary(
        id: _int(json['id']),
        title: _str(json['title']) ?? 'Capacitación',
        categoryLabel: _str(json['category_label']) ?? 'General',
        status: TrainingStatus.fromApi(json['status']),
        progress: _int(json['progress']).clamp(0, 100),
        estimatedMinutes: _intOrNull(json['estimated_minutes']),
        isMandatory: json['is_mandatory'] != false,
        passingScore: _int(json['passing_score'], 70),
        lessonsCount: _int(json['lessons_count']),
        hasQuiz: json['has_quiz'] == true,
        score: _intOrNull(json['score']),
        attempts: _int(json['attempts']),
        dueDate: DateTime.tryParse(json['due_date']?.toString() ?? ''),
        completedAt: DateTime.tryParse(json['completed_at']?.toString() ?? ''),
      );
}

enum LessonType { text, video, pdf, link }

class TrainingLesson {
  const TrainingLesson({
    required this.id,
    required this.title,
    required this.type,
    this.content,
    this.url,
    this.hasFile = false,
    this.durationMinutes,
    this.completed = false,
  });

  final int id;
  final String title;
  final LessonType type;
  final String? content;
  final String? url;
  final bool hasFile;
  final int? durationMinutes;
  final bool completed;

  factory TrainingLesson.fromJson(Map<String, dynamic> json) => TrainingLesson(
        id: _int(json['id']),
        title: _str(json['title']) ?? 'Lección',
        type: LessonType.values.firstWhere((t) => t.name == json['type'], orElse: () => LessonType.text),
        content: _str(json['content']),
        url: _str(json['url']),
        hasFile: json['has_file'] == true,
        durationMinutes: _intOrNull(json['duration_minutes']),
        completed: json['completed'] == true,
      );
}

class TrainingQuestion {
  const TrainingQuestion({required this.id, required this.question, required this.options});

  final int id;
  final String question;
  final List<String> options;

  factory TrainingQuestion.fromJson(Map<String, dynamic> json) => TrainingQuestion(
        id: _int(json['id']),
        question: _str(json['question']) ?? '',
        options: ((json['options'] as List?) ?? const []).map((o) => o.toString()).toList(),
      );
}

class TrainingDetail {
  const TrainingDetail({required this.summary, this.description, this.lessons = const [], this.questions = const []});

  final TrainingSummary summary;
  final String? description;
  final List<TrainingLesson> lessons;
  final List<TrainingQuestion> questions;

  bool get allLessonsDone => lessons.every((l) => l.completed);

  /// Primera lección sin completar (para "Continuar").
  TrainingLesson? get nextLesson => lessons.where((l) => !l.completed).firstOrNull;

  factory TrainingDetail.fromJson(Map<String, dynamic> json) => TrainingDetail(
        summary: TrainingSummary.fromJson(json),
        description: _str(json['description']),
        lessons: ((json['lessons'] as List?) ?? const [])
            .map((e) => TrainingLesson.fromJson(Map<String, dynamic>.from(e as Map)))
            .toList(),
        questions: ((json['questions'] as List?) ?? const [])
            .map((e) => TrainingQuestion.fromJson(Map<String, dynamic>.from(e as Map)))
            .toList(),
      );
}

class QuizResult {
  const QuizResult({
    required this.score,
    required this.passed,
    required this.correct,
    required this.total,
    required this.passingScore,
    required this.message,
  });

  final int score;
  final bool passed;
  final int correct;
  final int total;
  final int passingScore;
  final String message;

  factory QuizResult.fromJson(Map<String, dynamic> json) {
    final data = json['data'] is Map ? Map<String, dynamic>.from(json['data'] as Map) : const <String, dynamic>{};
    return QuizResult(
      score: _int(data['score']),
      passed: data['passed'] == true,
      correct: _int(data['correct']),
      total: _int(data['total']),
      passingScore: _int(data['passing_score'], 70),
      message: _str(json['message']) ?? '',
    );
  }
}
