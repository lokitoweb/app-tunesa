import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../utils/semver.dart';
import 'app_config.dart';
import 'app_config_controller.dart';

/// Versión instalada (`PackageInfo.version`); se inyecta en main().
final appVersionProvider = Provider<String>((ref) => '0.0.0');

enum UpdateLevel {
  /// Versión al día.
  none,

  /// Hay una versión más nueva: aviso descartable.
  optional,

  /// Versión no soportada: pantalla bloqueante.
  required,
}

class VersionGate {
  const VersionGate._();

  /// - Menor que `min_supported_version` + `force_update` → bloquea.
  /// - Menor que `min_supported_version` sin forzar, o menor que
  ///   `latest_version` → aviso descartable.
  /// - Un 426 de cualquier API ([serverRejected]) → bloquea.
  static UpdateLevel evaluate(String current, MobileConfig mobile, {bool serverRejected = false}) {
    if (serverRejected) return UpdateLevel.required;
    final belowMin = Semver.isLower(current, mobile.minSupportedVersion);
    if (belowMin && mobile.forceUpdate) return UpdateLevel.required;
    if (belowMin || Semver.isLower(current, mobile.latestVersion)) return UpdateLevel.optional;
    return UpdateLevel.none;
  }
}

/// Requisito de versión informado por un 426 APP_UPDATE_REQUIRED.
class UpdateRequirement {
  const UpdateRequirement({this.minSupportedVersion, this.latestVersion});

  final String? minSupportedVersion;
  final String? latestVersion;
}

class ServerUpdateRequiredController extends Notifier<UpdateRequirement?> {
  @override
  UpdateRequirement? build() => null;

  void report(String? minVersion, String? latestVersion) =>
      state = UpdateRequirement(minSupportedVersion: minVersion, latestVersion: latestVersion);

  void clear() => state = null;
}

final serverUpdateRequiredProvider =
    NotifierProvider<ServerUpdateRequiredController, UpdateRequirement?>(ServerUpdateRequiredController.new);

final updateLevelProvider = Provider<UpdateLevel>((ref) {
  final mobile = ref.watch(appConfigProvider.select((s) => s.config.mobile));
  return VersionGate.evaluate(
    ref.watch(appVersionProvider),
    mobile,
    serverRejected: ref.watch(serverUpdateRequiredProvider) != null,
  );
});

/// El aviso de actualización opcional se muestra una vez por arranque.
class UpdateBannerDismissed extends Notifier<bool> {
  @override
  bool build() => false;

  void dismiss() => state = true;

  /// Un push de versión nueva vuelve a mostrar el aviso.
  void show() => state = false;
}

final updateBannerDismissedProvider = NotifierProvider<UpdateBannerDismissed, bool>(UpdateBannerDismissed.new);
