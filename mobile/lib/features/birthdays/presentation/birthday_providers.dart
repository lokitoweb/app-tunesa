import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/config/app_config_controller.dart';
import '../../../core/providers.dart';
import '../data/birthday_repository.dart';
import '../domain/models.dart';

final birthdayRepositoryProvider = Provider<BirthdayRepository>((ref) => BirthdayRepository(ref.watch(apiClientProvider)));

/// ¿La empresa usa el módulo de cumpleaños?
final birthdaysEnabledProvider = Provider<bool>((ref) => ref.watch(appConfigProvider.select((s) => s.config.modules.birthdays)));

/// Carrusel de Inicio: hasta 5 cumpleaños de los próximos 7 días, con el mismo
/// filtro (toda la empresa) que la pantalla de cumpleaños: antes pedía solo la
/// sede y el resto del mes, y quedaba vacío aunque alguien cumpliera mañana.
/// Trae también el saludo el día del cumpleaños de quien usa la app.
const homeBirthdaysLimit = 5;
const homeBirthdaysDays = 7;

final upcomingBirthdaysProvider = FutureProvider.autoDispose<UpcomingBirthdays>((ref) async {
  if (!ref.watch(birthdaysEnabledProvider)) return UpcomingBirthdays.empty;
  return ref.watch(birthdayRepositoryProvider).upcoming(days: homeBirthdaysDays, limit: homeBirthdaysLimit);
});

/// Consulta de la pantalla de cumpleaños: mes y búsqueda.
typedef BirthdayQuery = ({int month, String search});

final birthdayMonthProvider = FutureProvider.autoDispose.family<BirthdayMonth, BirthdayQuery>(
  (ref, q) => ref.watch(birthdayRepositoryProvider).month(q.month, search: q.search),
);

/// Regalos del cumpleaños de una persona (se refresca al enviar uno).
final giftBoardProvider = FutureProvider.autoDispose.family<GiftBoard, int>(
  (ref, userId) => ref.watch(birthdayRepositoryProvider).gifts(userId),
);
