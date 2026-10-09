import 'package:flutter/material.dart';

import '../../core/widgets/brand_logo.dart';

/// Primer arranque sin configuración en caché: se espera a `/v1/app/config`
/// (máximo AppConfigController.splashTimeout) para mostrar la marca correcta.
/// Con caché no aparece: la app abre al instante y refresca en segundo plano.
class SplashScreen extends StatelessWidget {
  const SplashScreen({super.key});

  @override
  Widget build(BuildContext context) => const Scaffold(
        body: Center(
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            BrandLogo(height: 96),
            SizedBox(height: 32),
            SizedBox.square(dimension: 28, child: CircularProgressIndicator(strokeWidth: 3)),
          ]),
        ),
      );
}
