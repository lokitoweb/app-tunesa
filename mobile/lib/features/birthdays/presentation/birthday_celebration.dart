import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../domain/models.dart';

/// Modal de celebración: se abre solo en Inicio, el día del cumpleaños de
/// quien usa la app (lo decide el servidor con la zona horaria de la empresa)
/// y una sola vez ese día en este teléfono.
class BirthdayCelebration {
  const BirthdayCelebration._();

  static String prefsKey(int userId) => 'birthday_celebration.$userId';

  /// ¿Ya se mostró hoy a este usuario?
  static bool alreadyShown(SharedPreferences prefs, int userId, BirthdayGreeting greeting) =>
      prefs.getString(prefsKey(userId)) == greeting.date;

  /// Muestra el modal si corresponde. Se marca antes de abrirlo: aunque la app
  /// se cierre con el modal abierto, no vuelve a salir ese día.
  static Future<void> maybeShow(BuildContext context, SharedPreferences prefs, int userId, BirthdayGreeting greeting) async {
    if (alreadyShown(prefs, userId, greeting)) return;
    await prefs.setString(prefsKey(userId), greeting.date);
    if (!context.mounted) return;
    await showGeneralDialog<void>(
      context: context,
      barrierDismissible: true,
      barrierLabel: 'Cerrar',
      barrierColor: Colors.black54,
      transitionDuration: const Duration(milliseconds: 320),
      pageBuilder: (_, _, _) => BirthdayCelebrationDialog(greeting: greeting),
      transitionBuilder: (_, animation, _, child) => FadeTransition(
        opacity: animation,
        child: ScaleTransition(scale: CurvedAnimation(parent: animation, curve: Curves.easeOutBack), child: child),
      ),
    );
  }
}

class BirthdayCelebrationDialog extends StatelessWidget {
  const BirthdayCelebrationDialog({super.key, required this.greeting});

  final BirthdayGreeting greeting;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;

    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 400),
          child: Material(
            key: const Key('birthday-celebration'),
            color: scheme.surface,
            borderRadius: BorderRadius.circular(24),
            clipBehavior: Clip.antiAlias,
            elevation: 12,
            child: SingleChildScrollView(
              child: Column(mainAxisSize: MainAxisSize.min, children: [
                // Cabecera festiva con los colores de la marca
                Container(
                  width: double.infinity,
                  padding: const EdgeInsets.fromLTRB(24, 28, 24, 22),
                  decoration: BoxDecoration(
                    gradient: LinearGradient(
                      begin: Alignment.topLeft,
                      end: Alignment.bottomRight,
                      colors: [scheme.primary, Color.lerp(scheme.primary, scheme.tertiary, 0.6)!],
                    ),
                  ),
                  child: Column(children: [
                    const Text('🎉   🎂   🎈', style: TextStyle(fontSize: 30, height: 1.2)),
                    const SizedBox(height: 14),
                    Text(
                      '¡Feliz Cumpleaños, ${greeting.firstName}!',
                      textAlign: TextAlign.center,
                      style: theme.textTheme.headlineSmall?.copyWith(color: Colors.white, fontWeight: FontWeight.w800),
                    ),
                  ]),
                ),
                Padding(
                  padding: const EdgeInsets.fromLTRB(24, 20, 24, 8),
                  child: Column(children: [
                    if (greeting.body.isNotEmpty)
                      Text(greeting.body, textAlign: TextAlign.center, style: theme.textTheme.bodyLarge?.copyWith(height: 1.45)),
                    const SizedBox(height: 12),
                    Text(
                      'Gracias por tu compromiso y por todo lo que aportas cada día. '
                      'Te deseamos un año lleno de salud, logros y momentos felices.',
                      textAlign: TextAlign.center,
                      style: theme.textTheme.bodyMedium?.copyWith(color: scheme.onSurfaceVariant, height: 1.45),
                    ),
                    if (greeting.company.isNotEmpty) ...[
                      const SizedBox(height: 14),
                      Text('Con cariño, el equipo de ${greeting.company}',
                          textAlign: TextAlign.center,
                          style: theme.textTheme.labelLarge?.copyWith(color: scheme.primary, fontWeight: FontWeight.w700)),
                    ],
                  ]),
                ),
                Padding(
                  padding: const EdgeInsets.fromLTRB(24, 12, 24, 22),
                  child: SizedBox(
                    width: double.infinity,
                    child: FilledButton.icon(
                      key: const Key('birthday-celebration-close'),
                      onPressed: () => Navigator.of(context).pop(),
                      icon: const Icon(Icons.favorite_rounded),
                      label: const Text('¡Gracias!'),
                    ),
                  ),
                ),
              ]),
            ),
          ),
        ),
      ),
    );
  }
}
