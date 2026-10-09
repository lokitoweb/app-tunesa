import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/config/env.dart';
import '../../../core/network/api_exception.dart';
import '../../../core/providers.dart';
import '../data/auth_repository.dart';
import '../domain/models.dart';

final authRepositoryProvider = Provider<AuthRepository>(
  (ref) => AuthRepository(ref.watch(apiClientProvider), ref.watch(sessionStorageProvider)),
);

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

  Future<void> selectTenant(String slug) async {
    final branding = await _repo.selectTenant(slug);
    state = AuthState(status: AuthStatus.unauthenticated, branding: branding);
  }

  Future<void> login(String email, String password) async {
    final user = await _repo.login(email: email, password: password);
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
