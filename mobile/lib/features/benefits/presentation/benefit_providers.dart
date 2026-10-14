import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/config/app_config_controller.dart';
import '../../../core/providers.dart';
import '../data/benefit_repository.dart';
import '../domain/models.dart';

final benefitRepositoryProvider = Provider<BenefitRepository>((ref) => BenefitRepository(ref.watch(apiClientProvider)));

/// ¿La empresa usa el módulo de beneficios?
final benefitsEnabledProvider = Provider<bool>((ref) => ref.watch(appConfigProvider.select((s) => s.config.modules.benefits)));

final benefitCatalogProvider = FutureProvider.autoDispose<BenefitCatalog>((ref) => ref.watch(benefitRepositoryProvider).catalog());

final benefitDetailProvider = FutureProvider.autoDispose.family<Benefit, int>((ref, id) => ref.watch(benefitRepositoryProvider).detail(id));
