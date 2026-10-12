import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'core/config/app_config_controller.dart';
import 'core/router/app_router.dart';
import 'core/theme/app_theme.dart';
import 'core/widgets/system_bars_inset.dart';
import 'core/theme/theme_mode_controller.dart';

class TunesaApp extends ConsumerWidget {
  const TunesaApp({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final router = ref.watch(routerProvider);
    // Única fuente de marca: AppConfig (caché + /v1/app/config)
    final config = ref.watch(appConfigProvider.select((s) => s.config));

    return MaterialApp.router(
      title: config.displayName,
      debugShowCheckedModeBanner: false,
      theme: AppTheme.fromBranding(config.branding, Brightness.light),
      darkTheme: AppTheme.fromBranding(config.branding, Brightness.dark),
      themeMode: ref.watch(themeModeProvider),
      routerConfig: router,
      builder: (context, child) => SystemBarsInset(child: child ?? const SizedBox.shrink()),
      locale: const Locale('es'),
      supportedLocales: const [Locale('es'), Locale('en')],
      localizationsDelegates: GlobalMaterialLocalizations.delegates,
    );
  }
}
