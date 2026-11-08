<?php

namespace App\Modules\Core\Support;

use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\TenantSetting;
use App\Modules\Core\Models\User;

/**
 * Categorías de avisos push que cada persona puede apagar desde la app.
 * Apagar una categoría solo quita el push: el aviso sigue en la bandeja.
 * Los avisos de seguridad (security.*) no se pueden apagar.
 */
final class NotificationPreferences
{
    /** clave => [título, descripción, prefijos de tipo] */
    public const CATEGORIES = [
        'payslips'             => ['Boletas de pago', 'Cuando publican una boleta nueva.', ['payslip.']],
        'requests'             => ['Solicitudes y justificaciones', 'Cuando aprueban o rechazan tus solicitudes, justificaciones u horas extra, o llega una de tu equipo.', ['leave_request.', 'justification.', 'overtime.']],
        // La descripción real (con los minutos que configuró la empresa) la arma entryReminderText()
        'attendance_reminders' => ['Recordatorios de entrada y salida', 'Si no marcaste la entrada de tu turno y si a los 5 minutos de terminarlo no marcaste la salida.', ['attendance.entry_reminder', 'attendance.exit_reminder']],
        'attendance_alerts'    => ['Alertas de marcación', 'Marcaciones fuera de horario o de la sede.', ['attendance.outside_']],
        'shifts'               => ['Turnos y horarios', 'Cuando te asignan o cambian el turno.', ['shift.']],
        'trainings'            => ['Capacitaciones', 'Cursos nuevos y los que están por vencer.', ['training.']],
        'documents'            => ['Legajo', 'Cuando revisan un documento de tu legajo.', ['document.']],
        'announcements'        => ['Comunicados de RR. HH.', 'Avisos, reuniones y actualizaciones de la empresa. Los urgentes siempre llegan.', ['announcement.']],
        'company_documents'    => ['Documentos de la empresa', 'Políticas, reglamentos y otros documentos nuevos o por confirmar.', ['company_document.']],
        'benefits'             => ['Beneficios', 'Descuentos, convenios y promociones nuevos para ti.', ['benefit.']],
        'birthdays'            => ['Cumpleaños', 'Tu saludo de cumpleaños y los cumpleaños de tus compañeros.', ['birthday.']],
        'app_updates'          => ['Nuevas versiones de la app', 'Cuando hay una actualización disponible.', ['app.']],
        'reports'              => ['Reportes', 'Reportes mensuales listos para descargar.', ['report.']],
    ];

    /** Categoría de un tipo de aviso, o null si no se puede apagar */
    public static function categoryFor(string $type): ?string
    {
        // Seguridad y comunicados urgentes siempre llegan
        if (str_starts_with($type, 'security.') || $type === 'announcement.urgent') {
            return null;
        }
        foreach (self::CATEGORIES as $key => [, , $prefixes]) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($type, $prefix)) {
                    return $key;
                }
            }
        }

        return null;
    }

    public static function allowsPush(User $user, string $type): bool
    {
        $category = self::categoryFor($type);

        return $category === null || (($user->notification_preferences ?? [])[$category] ?? true) !== false;
    }

    /** "5 minutos después de la hora de entrada si no marcaste…", según lo que configuró la empresa */
    public static function entryReminderText(User $user): string
    {
        $offset = TenantSetting::forTenant($user->tenant_id ? Tenant::find($user->tenant_id) : null)->entryReminderOffsetMinutes();
        $minutes = abs($offset) === 1 ? '1 minuto' : abs($offset) . ' minutos';
        $when = match (true) {
            $offset < 0 => "{$minutes} antes de tu turno",
            $offset > 0 => "{$minutes} después de tu hora de entrada si no marcaste",
            default     => 'A tu hora de entrada si no marcaste',
        };

        return "{$when}, y si a los 5 minutos de terminar tu turno no marcaste la salida.";
    }

    /** @return list<array{key: string, title: string, description: string, enabled: bool}> */
    public static function forUser(User $user): array
    {
        $prefs = $user->notification_preferences ?? [];

        return collect(self::CATEGORIES)->map(fn ($c, $key) => [
            'key'         => $key,
            'title'       => $c[0],
            'description' => $key === 'attendance_reminders' ? self::entryReminderText($user) : $c[1],
            'enabled'     => ($prefs[$key] ?? true) !== false,
        ])->values()->all();
    }
}
