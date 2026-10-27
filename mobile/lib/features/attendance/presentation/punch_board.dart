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
    if (status.isNotScheduled) return status.notScheduledMessage!;
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
    final a = status.attendance;
    final started = a?.clockIn != null;
    final done = ClockAction.values.where((x) => timeOf(x, a) != null).length;

    // Completa: verde · en curso: color de la marca · sin marcar: neutro
    final (label, color, icon) = status.isNotScheduled
        ? (TodayStatus.notScheduledFallback, scheme.onSurfaceVariant, Icons.event_busy_rounded)
        : status.isDayComplete
        ? ('Marcación completa', dark ? AppTheme.successGlow : AppTheme.success, Icons.task_alt_rounded)
        : started
            ? ('Marcación incompleta', scheme.primary, Icons.timelapse_rounded)
            : ('Sin marcaciones', scheme.onSurfaceVariant, Icons.schedule_rounded);

    return Container(
      decoration: BoxDecoration(
        color: dark ? scheme.surfaceContainerHigh : Colors.white,
        borderRadius: BorderRadius.circular(AppTheme.radius),
        border: Border.all(color: scheme.outlineVariant.withValues(alpha: dark ? 1 : 0.35)),
        boxShadow: dark ? null : [BoxShadow(color: scheme.primary.withValues(alpha: 0.08), blurRadius: 18, offset: const Offset(0, 6))],
      ),
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 14),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Row(children: [
          Container(
            width: 44,
            height: 44,
            decoration: BoxDecoration(color: color.withValues(alpha: 0.12), borderRadius: BorderRadius.circular(8)),
            child: Icon(icon, color: color, size: 24),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text('Marcaciones de hoy', style: theme.textTheme.labelMedium?.copyWith(color: scheme.onSurfaceVariant)),
              Text(label, style: theme.textTheme.titleMedium?.copyWith(color: dark ? null : scheme.onSurface, fontWeight: FontWeight.w800)),
            ]),
          ),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
            decoration: BoxDecoration(color: color.withValues(alpha: 0.12), borderRadius: BorderRadius.circular(6)),
            child: Text('$done/4', style: TextStyle(color: color, fontWeight: FontWeight.w800)),
          ),
        ]),
        const SizedBox(height: 12),
        ClipRRect(
          borderRadius: BorderRadius.circular(6),
          child: LinearProgressIndicator(
            value: done / 4,
            minHeight: 6,
            backgroundColor: scheme.outlineVariant.withValues(alpha: 0.35),
            valueColor: AlwaysStoppedAnimation(dark ? AppTheme.successGlow : AppTheme.success),
          ),
        ),
        if (a != null && a.lateMinutes > 0)
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: Row(children: [
              Icon(Icons.warning_amber_rounded, size: 16, color: scheme.error),
              const SizedBox(width: 4),
              Text('${Fmt.minutes(a.lateMinutes)} de tardanza',
                  style: theme.textTheme.labelMedium?.copyWith(color: scheme.error, fontWeight: FontWeight.w600)),
            ]),
          ),
        if (status.isNotScheduled)
          Container(
            key: const Key('punch-not-scheduled'),
            margin: const EdgeInsets.only(top: 12),
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: scheme.primary.withValues(alpha: dark ? 0.16 : 0.06),
              borderRadius: BorderRadius.circular(8),
            ),
            child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Icon(Icons.info_outline_rounded, size: 18, color: scheme.primary),
              const SizedBox(width: 8),
              Expanded(child: Text(status.notScheduledMessage!, style: theme.textTheme.bodySmall?.copyWith(height: 1.35))),
            ]),
          ),
        const SizedBox(height: 16),
        Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          for (final action in ClockAction.values) ...[
            if (action != ClockAction.clockIn) const SizedBox(width: 10),
            Expanded(
              child: _PunchTile(
                key: Key('punch-${action.apiName}'),
                label: _labels[action]!,
                icon: _icons[action]!,
                time: timeOf(action, a),
                available: !status.isNotScheduled && status.actions.contains(action),
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
    // Hecha: verde · siguiente: color de la marca, relleno · bloqueada: gris suave
    final background = done
        ? AppTheme.success.withValues(alpha: 0.12)
        : available
            ? scheme.primary
            : scheme.onSurface.withValues(alpha: dark ? 0.08 : 0.04);
    final foreground = done
        ? (dark ? AppTheme.successGlow : AppTheme.successDeep)
        : available
            ? scheme.onPrimary
            : scheme.onSurfaceVariant.withValues(alpha: 0.45);
    final timeColor = done ? (dark ? AppTheme.successGlow : AppTheme.successDeep) : scheme.onSurfaceVariant.withValues(alpha: 0.6);

    return Semantics(
      button: true,
      enabled: available,
      label: done ? '$label, $time' : label,
      child: Column(children: [
        AspectRatio(
          aspectRatio: 1,
          child: DecoratedBox(
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(8),
              color: background,
              // Oscuro: verde translúcido con filo menta para que el check resalte sin encandilar
              border: done && dark ? Border.all(color: AppTheme.successGlow.withValues(alpha: 0.28)) : null,
              boxShadow: available && !done
                  ? [BoxShadow(color: scheme.primary.withValues(alpha: 0.3), blurRadius: 10, offset: const Offset(0, 4))]
                  : null,
            ),
            child: Material(
              type: MaterialType.transparency,
              child: InkWell(
                borderRadius: BorderRadius.circular(8),
                onTap: onTap,
                child: Icon(done ? Icons.check_circle_rounded : icon, color: foreground, size: 28),
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
