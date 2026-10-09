import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'core/router/app_router.dart';
import 'core/theme/app_theme.dart';
import 'core/theme/theme_mode_controller.dart';
import 'features/auth/presentation/auth_controller.dart';

class TunesaApp extends ConsumerWidget {
  const TunesaApp({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final router = ref.watch(routerProvider);
    final branding = ref.watch(authControllerProvider.select((s) => s.branding));
    final primary = AppTheme.parseHex(branding?.primaryColor);
    final secondary = AppTheme.parseHex(branding?.secondaryColor);

    return MaterialApp.router(
      title: branding?.companyName ?? 'TuneSA',
      debugShowCheckedModeBanner: false,
      theme: AppTheme.light(primary: primary, secondary: secondary),
      darkTheme: AppTheme.dark(primary: primary, secondary: secondary),
      themeMode: ref.watch(themeModeProvider),
      routerConfig: router,
      locale: const Locale('es'),
      supportedLocales: const [Locale('es'), Locale('en')],
      localizationsDelegates: GlobalMaterialLocalizations.delegates,
    );
  }
}
