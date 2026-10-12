import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

/// Reserva el espacio de la barra de navegación del teléfono (botones o
/// gestos) en todas las pantallas. Desde Android 15 la app se dibuja detrás de
/// esa barra: sin esto el final de las listas y los botones quedaban tapados y
/// los botones del sistema no se distinguían sobre el contenido.
///
/// Debajo de la app queda una franja del color de la barra inferior, con los
/// íconos del sistema en el contraste que le corresponde. También respeta los
/// costados (teléfono en horizontal con la barra a un lado).
class SystemBarsInset extends StatelessWidget {
  const SystemBarsInset({super.key, required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final band = theme.navigationBarTheme.backgroundColor ?? theme.colorScheme.surface;
    final darkBand = ThemeData.estimateBrightnessForColor(band) == Brightness.dark;

    return AnnotatedRegion<SystemUiOverlayStyle>(
      value: SystemUiOverlayStyle(
        systemNavigationBarColor: band,
        systemNavigationBarDividerColor: Colors.transparent,
        systemNavigationBarIconBrightness: darkBand ? Brightness.light : Brightness.dark,
        systemNavigationBarContrastEnforced: false,
      ),
      child: ColoredBox(
        color: band,
        child: SafeArea(top: false, child: child),
      ),
    );
  }
}
