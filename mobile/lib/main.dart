import 'package:flutter/foundation.dart' show kReleaseMode;
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:package_info_plus/package_info_plus.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'app.dart';
import 'core/config/env.dart';
import 'core/config/version_gate.dart';
import 'core/providers.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  Env.ensureReleaseConfig(release: kReleaseMode);
  await SystemChrome.setPreferredOrientations([DeviceOrientation.portraitUp]);
  await initializeDateFormatting('es');
  final prefs = await SharedPreferences.getInstance();
  final info = await PackageInfo.fromPlatform();

  runApp(ProviderScope(
    retry: providerRetry,
    overrides: [
      sharedPreferencesProvider.overrideWithValue(prefs),
      appVersionProvider.overrideWithValue(info.version),
    ],
    child: const TunesaApp(),
  ));
}
