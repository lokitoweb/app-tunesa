import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/config/app_config_controller.dart';
import '../../../core/providers.dart';
import '../data/birthday_repository.dart';
import '../domain/models.dart';

final birthdayRepositoryProvider = Provider<BirthdayRepository>((ref) => BirthdayRepository(ref.watch(apiClientProvider)));

/// ¿La empresa usa el módulo de cumpleaños?
final birthdaysEnabledProvider = Provider<bool>((ref) => ref.watch(appConfigProvider.select((s) => s.config.modules.birthdays)));

/// Carrusel de Inicio: hasta 5 cumpleaños de lo que queda del mes, solo de la
/// empresa y sede de quien usa la app. Trae también su saludo el día de su cumpleaños.
const homeBirthdaysLimit = 5;

final upcomingBirthdaysProvider = FutureProvider.autoDispose<UpcomingBirthdays>((ref) async {
  if (!ref.watch(birthdaysEnabledProvider)) return UpcomingBirthdays.empty;
  return ref.watch(birthdayRepositoryProvider).upcoming(scope: 'team', thisMonth: true, limit: homeBirthdaysLimit);
});

/// Consulta de la pantalla de cumpleaños: mes y búsqueda.
typedef BirthdayQuery = ({int month, String search});

final birthdayMonthProvider = FutureProvider.autoDispose.family<BirthdayMonth, BirthdayQuery>(
  (ref, q) => ref.watch(birthdayRepositoryProvider).month(q.month, search: q.search),
);
