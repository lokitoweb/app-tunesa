import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../features/auth/presentation/auth_controller.dart';
import 'location/location_service.dart';
import 'network/api_client.dart';
import 'storage/session_storage.dart';

/// Se inicializa en main() y se inyecta con overrideWithValue.
final sharedPreferencesProvider = Provider<SharedPreferences>(
  (ref) => throw UnimplementedError('sharedPreferencesProvider debe sobrescribirse en main()'),
);

final sessionStorageProvider = Provider<SessionStorage>(
  (ref) => SessionStorage(ref.watch(sharedPreferencesProvider)),
);

final apiClientProvider = Provider<ApiClient>((ref) {
  return ApiClient(
    ref.watch(sessionStorageProvider),
    onUnauthorized: () => ref.read(authControllerProvider.notifier).sessionExpired(),
  );
});

final locationServiceProvider = Provider<LocationService>((ref) => LocationService());

/// true mientras el teléfono tenga alguna red (wifi, datos, ethernet...).
final connectivityProvider = StreamProvider<bool>((ref) async* {
  final connectivity = Connectivity();
  bool online(List<ConnectivityResult> r) => r.any((c) => c != ConnectivityResult.none);

  yield online(await connectivity.checkConnectivity());
  yield* connectivity.onConnectivityChanged.map(online);
});
