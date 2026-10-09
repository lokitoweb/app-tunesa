import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/providers.dart';
import '../../auth/presentation/auth_controller.dart';
import '../data/profile_repository.dart';
import '../domain/models.dart';

final profileRepositoryProvider = Provider<ProfileRepository>(
  (ref) => ProfileRepository(ref.watch(apiClientProvider)),
);

/// Perfil completo del colaborador (legajo, pago, tallas, flags).
final employeeFileProvider = FutureProvider.autoDispose<EmployeeFile>(
  (ref) => ref.watch(profileRepositoryProvider).profile(),
);

/// Flags de la empresa para el renderizado condicional (pestaña Boletas,
/// secciones de Mi perfil). Mientras carga o si falla, todo visible: el
/// backend igual rechaza lo que la empresa haya desactivado.
final companyConfigProvider = FutureProvider<CompanyConfig>((ref) async {
  // Se vuelve a pedir al cambiar de usuario (login / logout)
  ref.watch(authControllerProvider.select((s) => s.user?.id));
  try {
    return await ref.watch(profileRepositoryProvider).config();
  } catch (_) {
    return const CompanyConfig();
  }
});
