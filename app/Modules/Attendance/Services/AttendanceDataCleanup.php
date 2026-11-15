<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Core\Services\UserPurger;
use Illuminate\Support\Facades\DB;

/**
 * Limpieza pedida por el cliente el 2026-11-14 (todas las empresas), una sola vez: la asistencia de
 * prueba antes de que el personal real empiece a marcar. Vacía jornadas (con faltas, justificaciones y
 * horas extra), marcaciones con sus selfies, intentos, planes de contingencia, marcación fuera de turno,
 * solicitudes (vacaciones, permisos, licencias) y sus avisos. Las tablas quedan, vacías; no toca
 * personas, sedes, turnos, roles ni configuración. Las faltas sin registro se calculan del horario.
 *
 * plan() solo cuenta; run() borra (la base en una transacción; los archivos después, sin frenar nada).
 */
class AttendanceDataCleanup
{
    /** Tablas que se vacían (hijas antes que padres) */
    public const TABLES = [
        'Solicitudes'                => ['leave_requests'],
        'Marcación fuera de turno'   => ['attendance_offshift_authorizations'],
        'Intentos de marcación'      => ['attendance_punch_attempts'],
        'Marcaciones'                => ['attendance_punches'],
        'Asistencia (jornadas y faltas)' => ['attendances'],
        'Planes de contingencia'     => ['attendance_contingencies'],
    ];

    /** Avisos de la campana que hablan de lo borrado (notifications.type) */
    public const NOTIFICATION_TYPES = ['attendance.%', 'justification.%', 'overtime.%', 'leave_request.%', 'shift.offshift_authorized', 'report.monthly_ready'];

    public function plan(): array
    {
        $plan = [];
        foreach (self::TABLES as $label => $tables) {
            $plan[$label] = collect($tables)->sum(fn ($t) => DB::table($t)->count());
        }
        $plan['Avisos de asistencia y solicitudes'] = $this->notifications()->count();

        return $plan;
    }

    public function run(): array
    {
        $plan  = $this->plan();
        $files = $this->files();

        DB::transaction(function () {
            $this->notifications()->delete();
            foreach (self::TABLES as $tables) {
                foreach ($tables as $table) {
                    DB::table($table)->delete(); // DELETE, no TRUNCATE: los ids no se reutilizan
                }
            }
        });

        app(UserPurger::class)->deleteFiles($files);

        return $plan;
    }

    private function notifications()
    {
        return DB::table('notifications')->where(function ($q) {
            foreach (self::NOTIFICATION_TYPES as $type) {
                $q->orWhere('type', 'like', $type);
            }
        });
    }

    /** @return list<array{0: ?string, 1: string, 2: bool}> mismo formato que UserPurger::filesFor() */
    private function files(): array
    {
        $selfieDisk = config('filesystems.attendance_disk', 'private');
        $files = [];
        $add = function (iterable $paths, ?string $disk, bool $selfie) use (&$files) {
            foreach ($paths as $path) {
                if (filled($path)) {
                    $files[] = [$disk, (string) $path, $selfie];
                }
            }
        };

        foreach (DB::table('attendances')->get(['clock_in_photo', 'clock_out_photo', 'justification_file']) as $a) {
            $add([$a->clock_in_photo, $a->clock_out_photo], $selfieDisk, true);
            $add([$a->justification_file], null, false);
        }
        $add(DB::table('attendance_punches')->pluck('photo_path'), $selfieDisk, true);
        $add(DB::table('leave_requests')->pluck('attachment_path'), null, false);

        return $files;
    }
}
