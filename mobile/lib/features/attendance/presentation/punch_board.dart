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

  /// Hora registrada de [action] (12 h) o null si aún no se marca.
  static String? timeOf(ClockAction action, Attendance? a) => switch (action) {
        ClockAction.clockIn => a?.clockIn == null ? null : Fmt.time(a!.clockIn),
        ClockAction.breakStart => a?.breakStart == null ? null : Fmt.clock(a!.breakStart),
        ClockAction.breakEnd => a?.breakEnd == null ? null : Fmt.clock(a!.breakEnd),
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
    final dark = theme.brightness == Brightness.dark;
    final started = status.attendance?.clockIn != null;

    // Completa: celeste intenso · incompleta: celeste · sin marcar: neutro
    final (label, color, icon) = status.isDayComplete
        ? ('Marcación completa', dark ? AppTheme.sky : AppTheme.skyDeep, Icons.task_alt_rounded)
        : started
            ? ('Marcación incompleta', AppTheme.sky, Icons.timelapse_rounded)
            : ('Sin marcaciones', scheme.outline, Icons.radio_button_unchecked_rounded);

    // Tarjeta azul cielo con blanco
    return Container(
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(AppTheme.radius + 4),
        gradient: LinearGradient(
          begin: Alignment.topCenter,
          end: Alignment.bottomCenter,
          colors: dark
              ? [AppTheme.sky.withValues(alpha: 0.22), scheme.surfaceContainerHigh]
              : const [AppTheme.skySoft, Colors.white],
        ),
        border: Border.all(color: AppTheme.sky.withValues(alpha: dark ? 0.35 : 0.3)),
        boxShadow: dark
            ? null
            : [BoxShadow(color: AppTheme.sky.withValues(alpha: 0.14), blurRadius: 14, offset: const Offset(0, 6))],
      ),
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 14),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Row(children: [
          Container(
            width: 46,
            height: 46,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              color: dark ? scheme.surface : Colors.white,
              border: Border.all(color: color, width: 3),
            ),
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
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final dark = theme.brightness == Brightness.dark;
    final done = time != null;
    final white = dark ? scheme.surface : Colors.white;
    // Hecha: celeste relleno · disponible: blanco con borde celeste · bloqueada: blanco apagado
    final foreground = done
        ? Colors.white
        : available
            ? AppTheme.sky
            : scheme.onSurfaceVariant.withValues(alpha: 0.45);
    final timeColor = done ? (dark ? AppTheme.sky : AppTheme.skyDeep) : scheme.onSurfaceVariant.withValues(alpha: 0.6);

    return Semantics(
      button: true,
      enabled: available,
      label: done ? '$label, $time' : label,
      child: Column(children: [
        AspectRatio(
          aspectRatio: 1,
          child: DecoratedBox(
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(AppTheme.radius + 2),
              color: done ? null : (available ? white : white.withValues(alpha: dark ? 0.5 : 0.7)),
              gradient: done
                  ? const LinearGradient(
                      begin: Alignment.topLeft,
                      end: Alignment.bottomRight,
                      colors: [AppTheme.sky, AppTheme.skyDeep],
                    )
                  : null,
              border: done
                  ? null
                  : Border.all(
                      color: available ? AppTheme.sky : AppTheme.sky.withValues(alpha: 0.2),
                      width: available ? 2 : 1,
                    ),
              boxShadow: available || done
                  ? [BoxShadow(color: AppTheme.sky.withValues(alpha: 0.22), blurRadius: 8, offset: const Offset(0, 3))]
                  : null,
            ),
            child: Material(
              type: MaterialType.transparency,
              child: InkWell(
                borderRadius: BorderRadius.circular(AppTheme.radius + 2),
                onTap: onTap,
                child: Icon(done ? Icons.check_rounded : icon, color: foreground, size: 28),
              ),
            ),
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
        FittedBox(
          fit: BoxFit.scaleDown,
          child: Text(time ?? '--:--', maxLines: 1, style: TextStyle(fontSize: 13, fontWeight: FontWeight.w700, color: timeColor)),
        ),
      ]),
    );
  }
}
