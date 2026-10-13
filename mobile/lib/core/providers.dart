import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../features/auth/presentation/auth_controller.dart';
import 'config/version_gate.dart';
import 'location/location_service.dart';
import 'network/api_client.dart';
import 'network/api_exception.dart';
import 'network/server_url.dart';
import 'security/device_integrity.dart';
import 'storage/session_storage.dart';

/// Se inicializa en main() y se inyecta con overrideWithValue.
final sharedPreferencesProvider = Provider<SharedPreferences>(
  (ref) => throw UnimplementedError('sharedPreferencesProvider debe sobrescribirse en main()'),
);

final sessionStorageProvider = Provider<SessionStorage>(
  (ref) => SessionStorage(ref.watch(sharedPreferencesProvider)),
);

final apiClientProvider = Provider<ApiClient>((ref) {
  final client = ApiClient(
    ref.watch(sessionStorageProvider),
    baseUrl: ref.read(baseUrlProvider),
    appVersion: ref.watch(appVersionProvider),
    onUnauthorized: () => ref.read(authControllerProvider.notifier).sessionExpired(),
    onUpdateRequired: (min, latest) => ref.read(serverUpdateRequiredProvider.notifier).report(min, latest),
    onNetworkResult: (ok) => ref.read(baseUrlProvider.notifier).reportNetworkResult(ok: ok),
  );
  // Cambio de dominio (tester o servidor) sin recrear repositorios ni reiniciar.
  ref.listen(baseUrlProvider, (_, next) => client.baseUrl = next);
  ref.read(deviceIntegrityProvider).flags().then((f) => client.deviceFlags = f);
  return client;
});

final deviceIntegrityProvider = Provider<DeviceIntegrity>((ref) => DeviceIntegrity());

final locationServiceProvider = Provider<LocationService>((ref) => LocationService());

/// true mientras el teléfono tenga alguna red (wifi, datos, ethernet...).
final connectivityProvider = StreamProvider<bool>((ref) async* {
  final connectivity = Connectivity();
  bool online(List<ConnectivityResult> r) => r.any((c) => c != ConnectivityResult.none);

  yield online(await connectivity.checkConnectivity());
  yield* connectivity.onConnectivityChanged.map(online);
});

/// Reintento automático de providers (Riverpod 3 reintenta por defecto hasta 10
/// veces): un error del servidor (404, 403, 422…) no se reintenta, para que la
/// pantalla muestre el mensaje al instante; un corte de red, solo una vez.
Duration? providerRetry(int retryCount, Object error) {
  if (error is ApiException && !error.isNetwork) return null;
  if (retryCount >= 1) return null;
  return const Duration(seconds: 2);
}
