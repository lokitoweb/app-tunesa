import 'package:flutter/material.dart';

/// Logo oficial de Grupo Negreiros, incluido en la app: se ve igual en el
/// login, el arranque y el encabezado de Inicio, con o sin sesión y sin red.
/// Mantiene su proporción (BoxFit.contain) en cualquier pantalla.
class BrandLogo extends StatelessWidget {
  const BrandLogo({super.key, this.height = 72});

  static const asset = 'assets/branding/logo-grupo-negreiros.png';

  final double height;

  @override
  Widget build(BuildContext context) => Semantics(
        label: 'Grupo Negreiros',
        image: true,
        child: Center(
          child: Image.asset(
            asset,
            height: height,
            fit: BoxFit.contain,
            filterQuality: FilterQuality.medium,
            // Si faltara el archivo, el nombre en texto (nunca un hueco)
            errorBuilder: (context, _, _) => Text(
              'GRUPO NEGREIROS',
              style: TextStyle(fontSize: height * 0.3, fontWeight: FontWeight.w800, color: Theme.of(context).colorScheme.primary),
            ),
          ),
        ),
      );
}
