import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/config/app_config_controller.dart';
import '../../../core/providers.dart';
import '../../../core/storage/secure_key_store.dart';
import '../data/ethics_repository.dart';
import '../data/ethics_vault.dart';
import '../domain/models.dart';

final ethicsEnabledProvider = Provider<bool>((ref) => ref.watch(appConfigProvider.select((s) => s.config.modules.ethics)));

final ethicsRepositoryProvider = Provider<EthicsRepository>((ref) => EthicsRepository(ref.watch(apiClientProvider)));

final ethicsVaultProvider = Provider<EthicsVault>((ref) => EthicsVault(const FlutterSecureKeyValueStore()));

final ethicsTopicsProvider = Provider<EthicsTopics>((ref) => const EthicsTopics());

final ethicsCategoriesProvider =
    FutureProvider.autoDispose<List<EthicsCategory>>((ref) => ref.watch(ethicsRepositoryProvider).categories());

final savedEthicsCasesProvider =
    FutureProvider.autoDispose<List<SavedEthicsCase>>((ref) => ref.watch(ethicsVaultProvider).list());

/// Caso consultado con código + token.
final ethicsCaseProvider = FutureProvider.autoDispose.family<EthicsCaseView, ({String code, String token})>(
  (ref, key) => ref.watch(ethicsRepositoryProvider).lookup(key.code, key.token),
);
