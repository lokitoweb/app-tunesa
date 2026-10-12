import 'dart:typed_data';

import '../../../core/network/api_client.dart';
import '../../../core/network/api_endpoints.dart';
import '../domain/models.dart';

class TrainingRepository {
  TrainingRepository(this._api);

  final ApiClient _api;

  Future<List<TrainingSummary>> list() async {
    final json = await _api.get(ApiEndpoints.trainings);
    return ((json['data'] as List?) ?? const [])
        .map((e) => TrainingSummary.fromJson(Map<String, dynamic>.from(e as Map)))
        .toList();
  }

  Future<TrainingDetail> detail(int id) async {
    final json = await _api.get(ApiEndpoints.training(id));
    return TrainingDetail.fromJson(Map<String, dynamic>.from(json['data'] as Map));
  }

  Future<void> completeLesson(int course, int lesson) => _api.post(ApiEndpoints.trainingLessonComplete(course, lesson));

  /// [answers]: id de pregunta → índice de la opción elegida.
  Future<QuizResult> submitQuiz(int course, Map<int, int> answers) async {
    final json = await _api.post(
      ApiEndpoints.trainingQuiz(course),
      data: {'answers': answers.map((k, v) => MapEntry(k.toString(), v))},
    );
    return QuizResult.fromJson(json);
  }

  /// PDF de una lección o la constancia, en memoria (no se guarda en el teléfono).
  Future<Uint8List> lessonPdf(int course, int lesson) async =>
      Uint8List.fromList(await _api.download(ApiEndpoints.trainingLessonFile(course, lesson)));

  Future<Uint8List> certificate(int course) async =>
      Uint8List.fromList(await _api.download(ApiEndpoints.trainingCertificate(course)));
}
