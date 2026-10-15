import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Aviso destacado (política de datos de usuario de Google Play) antes de
/// pedir por primera vez la ubicación y la cámara para marcar: explica qué se
/// toma, cuándo y para qué. Se muestra una sola vez por instalación.
class PunchDisclosure {
  const PunchDisclosure._();

  static const prefsKey = 'punch_disclosure_accepted_v1';

  /// true si ya lo aceptó o lo acepta ahora; false si elige "Ahora no".
  static Future<bool> ensureAccepted(BuildContext context, SharedPreferences prefs, {required bool selfie}) async {
    if (prefs.getBool(prefsKey) == true) return true;
    final ok = await showDialog<bool>(
      context: context,
      barrierDismissible: false,
      builder: (_) => _PunchDisclosureDialog(selfie: selfie),
    );
    if (ok == true) await prefs.setBool(prefsKey, true);
    return ok == true;
  }
}

class _PunchDisclosureDialog extends StatelessWidget {
  const _PunchDisclosureDialog({required this.selfie});

  final bool selfie;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return AlertDialog(
      key: const Key('punch-disclosure'),
      icon: Icon(Icons.verified_user_outlined, color: scheme.primary, size: 32),
      title: const Text('Antes de marcar'),
      content: SingleChildScrollView(
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
          const _Point(
            icon: Icons.location_on_outlined,
            text: 'Grupo Negreiros usa tu ubicación precisa solo mientras estás en esta pantalla y al registrar la marcación, '
                'para comprobar que estás en tu sede. No la usa en segundo plano.',
          ),
          if (selfie)
            const _Point(
              icon: Icons.photo_camera_front_outlined,
              text: 'La cámara frontal toma la selfie de la marcación. La foto la ve solo tu empresa '
                  'y no se crean datos biométricos.',
            ),
          const _Point(
            icon: Icons.apartment_rounded,
            text: 'Tu empresa recibe la hora, la ubicación y la foto de cada marcación para controlar la asistencia.',
          ),
        ]),
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Ahora no')),
        FilledButton(
          key: const Key('punch-disclosure-accept'),
          onPressed: () => Navigator.pop(context, true),
          child: const Text('Entendido'),
        ),
      ],
    );
  }
}

class _Point extends StatelessWidget {
  const _Point({required this.icon, required this.text});

  final IconData icon;
  final String text;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 6),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Icon(icon, size: 20, color: Theme.of(context).colorScheme.primary),
          const SizedBox(width: 12),
          Expanded(child: Text(text)),
        ]),
      );
}
