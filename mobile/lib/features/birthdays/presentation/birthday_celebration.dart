import 'package:flutter/material.dart';
import 'package:lottie/lottie.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../domain/models.dart';
import 'birthday_gifts.dart';

/// Modal de celebración a pantalla completa (estilo historia): se abre solo en
/// Inicio, el día del cumpleaños de quien usa la app (lo decide el servidor con
/// la zona horaria de la empresa) y una sola vez ese día en este teléfono.
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
      barrierColor: Colors.black,
      transitionDuration: const Duration(milliseconds: 380),
      pageBuilder: (_, _, _) => BirthdayCelebrationDialog(greeting: greeting),
      transitionBuilder: (_, animation, _, child) => FadeTransition(
        opacity: animation,
        child: SlideTransition(
          position: Tween(begin: const Offset(0, 0.06), end: Offset.zero).animate(CurvedAnimation(parent: animation, curve: Curves.easeOutCubic)),
          child: child,
        ),
      ),
    );
  }
}

/// Paleta de la tarjeta según el género registrado.
class CelebrationPalette {
  const CelebrationPalette({required this.background, required this.accent, required this.onAccent, required this.muted});

  final List<Color> background;
  final Color accent;
  final Color onAccent;
  final Color muted;

  /// Hombre: azul medianoche y carbón con dorado.
  static const male = CelebrationPalette(
    background: [Color(0xFF0B1220), Color(0xFF111827), Color(0xFF1F2937)],
    accent: Color(0xFFD4AF37),
    onAccent: Color(0xFF1A1405),
    muted: Color(0xFFCBD5E1),
  );

  /// Mujer: rose gold y champagne en degradado suave.
  static const female = CelebrationPalette(
    background: [Color(0xFF4A1F33), Color(0xFF8E4A5E), Color(0xFFC08A7E)],
    accent: Color(0xFFF7E7CE),
    onAccent: Color(0xFF5A2638),
    muted: Color(0xFFF8E1E7),
  );

  /// Sin dato: carbón con champagne (neutro y elegante).
  static const neutral = CelebrationPalette(
    background: [Color(0xFF111827), Color(0xFF1E293B), Color(0xFF334155)],
    accent: Color(0xFFE8C39E),
    onAccent: Color(0xFF2A1D10),
    muted: Color(0xFFE2E8F0),
  );

  static CelebrationPalette forGender(String? gender) => switch (gender) {
        'male' => male,
        'female' => female,
        _ => neutral,
      };
}

class BirthdayCelebrationDialog extends StatelessWidget {
  const BirthdayCelebrationDialog({super.key, required this.greeting});

  final BirthdayGreeting greeting;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final palette = CelebrationPalette.forGender(greeting.gender);
    // "Reducir movimiento" del sistema: confeti quieto
    final animate = !MediaQuery.of(context).disableAnimations;
    final userId = greeting.userId;

    return Material(
      key: const Key('birthday-celebration'),
      color: Colors.transparent,
      child: DecoratedBox(
        decoration: BoxDecoration(
          gradient: LinearGradient(begin: Alignment.topCenter, end: Alignment.bottomCenter, colors: palette.background),
        ),
        child: Stack(children: [
          Positioned.fill(
            child: IgnorePointer(
              child: Opacity(
                opacity: 0.85,
                child: Lottie.asset('assets/gifts/celebration.json',
                    fit: BoxFit.cover, animate: animate, repeat: true, frameRate: FrameRate.max, errorBuilder: (_, _, _) => const SizedBox.shrink()),
              ),
            ),
          ),
          SafeArea(
            child: Center(
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 440),
                child: SingleChildScrollView(
                  padding: const EdgeInsets.fromLTRB(28, 24, 28, 24),
                  child: Column(mainAxisSize: MainAxisSize.min, children: [
                    // Medallón con aro del color de acento
                    Container(
                      width: 112,
                      height: 112,
                      decoration: BoxDecoration(
                        shape: BoxShape.circle,
                        border: Border.all(color: palette.accent, width: 2),
                        color: Colors.white.withValues(alpha: 0.06),
                      ),
                      alignment: Alignment.center,
                      child: const Text('🎂', style: TextStyle(fontSize: 54)),
                    ),
                    const SizedBox(height: 22),
                    Text('FELIZ CUMPLEAÑOS',
                        style: TextStyle(color: palette.accent, fontSize: 13, letterSpacing: 4, fontWeight: FontWeight.w700)),
                    const SizedBox(height: 8),
                    Text(
                      '¡Feliz Cumpleaños, ${greeting.firstName}!',
                      textAlign: TextAlign.center,
                      style: theme.textTheme.headlineMedium?.copyWith(color: Colors.white, fontWeight: FontWeight.w800, height: 1.15),
                    ),
                    const SizedBox(height: 16),
                    Container(width: 56, height: 2, color: palette.accent),
                    const SizedBox(height: 16),
                    if (greeting.body.isNotEmpty)
                      Text(greeting.body,
                          textAlign: TextAlign.center, style: theme.textTheme.bodyLarge?.copyWith(color: Colors.white, height: 1.45)),
                    const SizedBox(height: 10),
                    Text(
                      'Gracias por tu compromiso y por todo lo que aportas cada día. '
                      'Te deseamos un año lleno de salud, logros y momentos felices.',
                      textAlign: TextAlign.center,
                      style: theme.textTheme.bodyMedium?.copyWith(color: palette.muted, height: 1.45),
                    ),
                    if (greeting.company.isNotEmpty) ...[
                      const SizedBox(height: 16),
                      Text('Con cariño, el equipo de ${greeting.company}',
                          textAlign: TextAlign.center,
                          style: theme.textTheme.labelLarge?.copyWith(color: palette.accent, fontWeight: FontWeight.w700)),
                    ],
                    const SizedBox(height: 28),
                    if (userId != null) ...[
                      SizedBox(
                        width: double.infinity,
                        child: OutlinedButton.icon(
                          key: const Key('birthday-celebration-gifts'),
                          style: OutlinedButton.styleFrom(
                            foregroundColor: palette.accent,
                            side: BorderSide(color: palette.accent.withValues(alpha: 0.7)),
                            minimumSize: const Size.fromHeight(50),
                          ),
                          onPressed: () {
                            final nav = Navigator.of(context);
                            nav.pop();
                            showGiftsSheet(nav.context, userId: userId, firstName: greeting.firstName);
                          },
                          icon: const Text('🎁', style: TextStyle(fontSize: 18)),
                          label: const Text('Ver mis regalos'),
                        ),
                      ),
                      const SizedBox(height: 10),
                    ],
                    SizedBox(
                      width: double.infinity,
                      child: FilledButton.icon(
                        key: const Key('birthday-celebration-close'),
                        style: FilledButton.styleFrom(
                          backgroundColor: palette.accent,
                          foregroundColor: palette.onAccent,
                          minimumSize: const Size.fromHeight(52),
                        ),
                        onPressed: () => Navigator.of(context).pop(),
                        icon: const Icon(Icons.favorite_rounded),
                        label: const Text('¡Gracias!'),
                      ),
                    ),
                  ]),
                ),
              ),
            ),
          ),
        ]),
      ),
    );
  }
}
