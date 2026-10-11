import 'package:flutter/material.dart';

import '../../../core/theme/app_theme.dart';
import '../../../core/utils/formatters.dart';
import '../../../core/widgets/async_views.dart';
import '../domain/models.dart';

/// Tablero del día: las cuatro marcaciones (entrada, inicio y fin de break,
/// salida) con la hora exacta de cada una debajo. Solo se puede tocar la que
/// el servidor ofrece en `next_action`; las demás explican qué falta.
class PunchBoard extends StatelessWidget {
  const PunchBoard({super.key, required this.status, required this.onPunch});

  final TodayStatus status;
  final ValueChanged<ClockAction> onPunch;

  /// Hora registrada de [action] (HH:mm) o null si aún no se marca.
  static String? timeOf(ClockAction action, Attendance? a) => switch (action) {
        ClockAction.clockIn => a?.clockIn == null ? null : Fmt.time(a!.clockIn),
        ClockAction.breakStart => a?.breakStart,
        ClockAction.breakEnd => a?.breakEnd,
        ClockAction.clockOut => a?.clockOut == null ? null : Fmt.time(a!.clockOut),
      };

  /// Aviso al tocar una marcación que no corresponde todavía.
  static String lockedReason(ClockAction action, TodayStatus status) {
    final a = status.attendance;
    if (status.isDayComplete) return 'Ya completaste tus marcaciones de hoy.';
    if (a?.clockIn == null) return 'Primero marca tu hora de entrada.';
    if (action == ClockAction.breakEnd && a?.breakStart == null) return 'Primero marca el inicio de tu break.';
    if (a!.isOnBreak) return 'Marca el fin de tu break para continuar.';
    if (action == ClockAction.breakStart) return 'Ya usaste tu break de hoy.';
    return 'Esta marcación no está disponible en este momento.';
  }

  static const _labels = {
    ClockAction.clockIn: 'Entrada',
    ClockAction.breakStart: 'Inicio break',
    ClockAction.breakEnd: 'Fin break',
    ClockAction.clockOut: 'Salida',
  };

  static const _icons = {
    ClockAction.clockIn: Icons.login_rounded,
    ClockAction.breakStart: Icons.free_breakfast_outlined,
    ClockAction.breakEnd: Icons.restaurant_rounded,
    ClockAction.clockOut: Icons.logout_rounded,
  };

  void _tap(BuildContext context, ClockAction action) {
    final time = timeOf(action, status.attendance);
    if (time != null) {
      showSnack(context, 'Ya marcaste ${_labels[action]!.toLowerCase()} a las $time.');
    } else if (status.actions.contains(action)) {
      onPunch(action);
    } else {
      showSnack(context, lockedReason(action, status), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final started = status.attendance?.clockIn != null;

    // Completa: color primario · incompleta: secundario · sin marcar: neutro
    final (label, color, icon) = status.isDayComplete
        ? ('Marcación completa', scheme.primary, Icons.task_alt_rounded)
        : started
            ? ('Marcación incompleta', scheme.secondary, Icons.timelapse_rounded)
            : ('Sin marcaciones', scheme.outline, Icons.radio_button_unchecked_rounded);

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Row(children: [
            Container(
              width: 46,
              height: 46,
              decoration: BoxDecoration(shape: BoxShape.circle, border: Border.all(color: color, width: 4)),
              child: Icon(icon, color: color, size: 22),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text('Marcaciones de hoy', style: theme.textTheme.labelMedium?.copyWith(color: scheme.onSurfaceVariant)),
                Text(label, style: theme.textTheme.titleMedium?.copyWith(color: color, fontWeight: FontWeight.w700)),
              ]),
            ),
            if (status.attendance != null && status.attendance!.lateMinutes > 0)
              Text('${status.attendance!.lateMinutes} min tarde',
                  style: theme.textTheme.labelMedium?.copyWith(color: scheme.error, fontWeight: FontWeight.w600)),
          ]),
          const SizedBox(height: 16),
          Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            for (final action in ClockAction.values) ...[
              if (action != ClockAction.clockIn) const SizedBox(width: 8),
              Expanded(
                child: _PunchTile(
                  key: Key('punch-${action.apiName}'),
                  label: _labels[action]!,
                  icon: _icons[action]!,
                  time: timeOf(action, status.attendance),
                  available: status.actions.contains(action),
                  onTap: () => _tap(context, action),
                ),
              ),
            ],
          ]),
        ]),
      ),
    );
  }
}

class _PunchTile extends StatelessWidget {
  const _PunchTile({
    super.key,
    required this.label,
    required this.icon,
    required this.time,
    required this.available,
    required this.onTap,
  });

  final String label;
  final IconData icon;
  final String? time;
  final bool available;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final done = time != null;
    // Hecha: relleno de marca · disponible: borde de marca · bloqueada: gris
    final background = done
        ? scheme.primary
        : available
            ? scheme.primary.withValues(alpha: 0.08)
            : scheme.surfaceContainerHighest.withValues(alpha: 0.5);
    final foreground = done
        ? scheme.onPrimary
        : available
            ? scheme.primary
            : scheme.onSurfaceVariant.withValues(alpha: 0.55);

    return Semantics(
      button: true,
      enabled: available,
      label: done ? '$label, $time' : label,
      child: Column(children: [
        Material(
          color: background,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(AppTheme.radius),
            side: available && !done ? BorderSide(color: scheme.primary, width: 1.5) : BorderSide.none,
          ),
          clipBehavior: Clip.antiAlias,
          child: InkWell(
            onTap: onTap,
            child: AspectRatio(aspectRatio: 1, child: Icon(done ? Icons.check_rounded : icon, color: foreground, size: 28)),
          ),
        ),
        const SizedBox(height: 6),
        // Alto fijo de dos líneas: las horas quedan alineadas aunque una etiqueta se parta
        SizedBox(
          height: 28,
          child: Center(
            child: Text(label,
                textAlign: TextAlign.center,
                maxLines: 2,
                style: TextStyle(fontSize: 11.5, height: 1.15, fontWeight: FontWeight.w600, color: scheme.onSurface)),
          ),
        ),
        const SizedBox(height: 2),
        Text(time ?? '--:--',
            style: TextStyle(
              fontSize: 13,
              fontWeight: FontWeight.w700,
              color: done ? scheme.primary : scheme.onSurfaceVariant.withValues(alpha: 0.6),
            )),
      ]),
    );
  }
}
