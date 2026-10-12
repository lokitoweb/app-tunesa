import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/config/app_config_controller.dart';
import '../../../core/providers.dart';
import '../data/training_repository.dart';
import '../domain/models.dart';

final trainingRepositoryProvider = Provider<TrainingRepository>((ref) => TrainingRepository(ref.watch(apiClientProvider)));

/// ¿La empresa tiene el módulo de capacitaciones?
final trainingsEnabledProvider = Provider<bool>((ref) => ref.watch(appConfigProvider.select((s) => s.config.modules.trainings)));

final trainingsProvider = FutureProvider.autoDispose<List<TrainingSummary>>((ref) => ref.watch(trainingRepositoryProvider).list());

final trainingDetailProvider = FutureProvider.autoDispose.family<TrainingDetail, int>(
  (ref, id) => ref.watch(trainingRepositoryProvider).detail(id),
);

/// Recarga la lista y el detalle tras un avance.
void refreshTraining(WidgetRef ref, int id) {
  ref.invalidate(trainingDetailProvider(id));
  ref.invalidate(trainingsProvider);
}
