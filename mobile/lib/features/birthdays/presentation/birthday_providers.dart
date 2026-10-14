import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/config/app_config_controller.dart';
import '../../../core/providers.dart';
import '../data/birthday_repository.dart';
import '../domain/models.dart';

final birthdayRepositoryProvider = Provider<BirthdayRepository>((ref) => BirthdayRepository(ref.watch(apiClientProvider)));

/// ¿La empresa usa el módulo de cumpleaños?
final birthdaysEnabledProvider = Provider<bool>((ref) => ref.watch(appConfigProvider.select((s) => s.config.modules.birthdays)));

/// Próximos 30 días para el carrusel de Inicio.
final upcomingBirthdaysProvider = FutureProvider.autoDispose<UpcomingBirthdays>(
  (ref) => ref.watch(birthdayRepositoryProvider).upcoming(days: 30, limit: 10),
);

/// Consulta de la pantalla de cumpleaños: mes y búsqueda.
typedef BirthdayQuery = ({int month, String search});

final birthdayMonthProvider = FutureProvider.autoDispose.family<BirthdayMonth, BirthdayQuery>(
  (ref, q) => ref.watch(birthdayRepositoryProvider).month(q.month, search: q.search),
);
