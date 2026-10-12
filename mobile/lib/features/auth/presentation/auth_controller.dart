import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/config/app_config_controller.dart';
import '../../../core/config/env.dart';
import '../../../core/network/api_exception.dart';
import '../../../core/providers.dart';
import '../data/auth_repository.dart';
import '../domain/models.dart';

final authRepositoryProvider = Provider<AuthRepository>(
  (ref) => AuthRepository(ref.watch(apiClientProvider), ref.watch(sessionStorageProvider)),
);

/// needsTenant: la app aún no conoce la empresa; la sabrá al iniciar sesión.
enum AuthStatus { needsTenant, unauthenticated, authenticated }

class AuthState {
  const AuthState({required this.status, this.branding, this.user, this.notice});

  final AuthStatus status;
  final Branding? branding;
  final AppUser? user;

  /// Mensaje para mostrar en el login (ej: "Tu sesión expiró").
  final String? notice;
}

/// Estado de sesión de la app. El router redirige según [AuthState.status].
class AuthController extends Notifier<AuthState> {
  @override
  AuthState build() {
    final storage = ref.read(sessionStorageProvider);
    final brandingJson = storage.branding;
    final branding = brandingJson == null ? null : Branding.fromJson(brandingJson);
    final userJson = storage.user;

    if (storage.tenantSlug == null || branding == null) {
      return const AuthState(status: AuthStatus.needsTenant);
    }
    if (userJson == null) {
      return AuthState(status: AuthStatus.unauthenticated, branding: branding);
    }
    // El token se valida en segundo plano; si caducó, el 401 llama a sessionExpired().
    Future.microtask(_refreshUser);
    return AuthState(status: AuthStatus.authenticated, branding: branding, user: AppUser.fromJson(userJson));
  }

  AuthRepository get _repo => ref.read(authRepositoryProvider);

  /// Empresa fija por build (DEFAULT_TENANT): se resuelve sin pantalla de código.
  bool get hasFixedTenant => Env.defaultTenant.isNotEmpty;

  /// Solo correo y contraseña. El servidor reconoce la empresa del usuario;
  /// si es otra que la recordada, se guarda su código y se aplica su marca.
  Future<void> login(String email, String password) async {
    final result = await _repo.login(email: email, password: password);
    var branding = state.branding;

    if (ref.read(sessionStorageProvider).tenantSlug != result.tenantSlug || branding == null) {
      try {
        final config = await ref.read(appConfigProvider.notifier).loadForTenant(result.tenantSlug);
        branding = Branding.fromConfig(result.tenantSlug, config);
        await _repo.saveTenant(result.tenantSlug, branding);
      } catch (_) {
        // Sin la marca no hay sesión a medias: se vuelve a pedir el ingreso
        await ref.read(sessionStorageProvider).clearSession();
        rethrow;
      }
    }

    state = AuthState(status: AuthStatus.authenticated, branding: branding, user: result.user);
  }

  /// Cambia la contraseña (obligatorio si RRHH entregó una temporal).
  Future<void> changePassword(String current, String next) async {
    await _repo.changePassword(current: current, next: next);
    final user = state.user?.copyWith(mustChangePassword: false);
    if (user != null) await ref.read(sessionStorageProvider).saveUser(user.toJson());
    state = AuthState(status: AuthStatus.authenticated, branding: state.branding, user: user);
  }

  Future<void> updateAvatar(List<int> bytes) async {
    final user = await _repo.uploadAvatar(bytes);
    state = AuthState(status: AuthStatus.authenticated, branding: state.branding, user: user);
  }

  Future<void> removeAvatar() async {
    final user = await _repo.removeAvatar();
    state = AuthState(status: AuthStatus.authenticated, branding: state.branding, user: user);
  }

  Future<void> logout() async {
    await _repo.logout();
    state = AuthState(status: AuthStatus.unauthenticated, branding: state.branding);
  }

  /// Cambiar de empresa (solo apps multiempresa).
  Future<void> changeTenant() async {
    await _repo.logout();
    await ref.read(sessionStorageProvider).clearTenant();
    state = const AuthState(status: AuthStatus.needsTenant);
    await ref.read(appConfigProvider.notifier).resetToPlatform();
  }

  /// Llamado por ApiClient ante un 401.
  void sessionExpired() {
    if (state.status != AuthStatus.authenticated) return;
    ref.read(sessionStorageProvider).clearSession();
    state = AuthState(
      status: AuthStatus.unauthenticated,
      branding: state.branding,
      notice: 'Tu sesión expiró. Vuelve a iniciar sesión.',
    );
  }

  Future<void> _refreshUser() async {
    try {
      final user = await _repo.me();
      if (state.status == AuthStatus.authenticated) {
        state = AuthState(status: AuthStatus.authenticated, branding: state.branding, user: user);
      }
    } on ApiException {
      // Sin red: se mantiene la sesión guardada. 401: ya lo manejó sessionExpired().
    }
  }
}

final authControllerProvider = NotifierProvider<AuthController, AuthState>(AuthController.new);
