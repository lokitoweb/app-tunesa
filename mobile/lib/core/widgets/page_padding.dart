import 'package:flutter/widgets.dart';

/// Margen de las pantallas con scroll: 16 a los costados y aire al final que
/// suma la barra del sistema (gestos o botones). Con un `padding` explícito el
/// ListView ya no agrega el borde seguro de abajo, y el último elemento
/// quedaba pegado o tapado por la barra de navegación del teléfono.
EdgeInsets pagePadding(BuildContext context, {double top = 16, double horizontal = 16, double bottom = 32}) =>
    EdgeInsets.fromLTRB(horizontal, top, horizontal, bottom + MediaQuery.paddingOf(context).bottom);
