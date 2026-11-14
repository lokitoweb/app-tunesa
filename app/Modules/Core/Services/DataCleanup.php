<?php

namespace App\Modules\Core\Services;

use App\Modules\Benefits\Models\Benefit;
use App\Modules\CompanyDocs\Models\CompanyDocument;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Limpieza pedida por el cliente (todas las empresas), una sola vez:
 *  - Colaboradores inactivos y el usuario de prueba «Juan Pérez Quispe» (supervisor), con todo lo suyo:
 *    la base borra en cascada asistencia, marcaciones, documentos, solicitudes, boletas, etc.
 *  - Horarios (turnos, asignaciones semanales y programación), feriados, canal ético, marcaciones
 *    fuera de turno, capacitaciones, beneficios y documentos de la empresa.
 * Las tablas quedan, vacías. No toca a los colaboradores activos ni su asistencia.
 *
 * plan() solo cuenta; run() borra (la base en una transacción; los archivos después, sin frenar nada).
 */
class DataCleanup
{
    /** Usuario de prueba a borrar: nombre completo normalizado (sin tildes, mayúsculas) y rol */
    public const TEST_USER_NAME = 'JUAN PEREZ QUISPE';

    public const TEST_USER_ROLE = 'supervisor';

    /** Tablas que se vacían por completo (hijas antes que padres), agrupadas por lo que pidió el cliente */
    public const TABLES = [
        'Horarios'                  => ['employee_schedule_days', 'employee_shifts', 'shifts'],
        'Feriados'                  => ['holidays'],
        'Canal ético'               => ['ethics_case_attachments', 'ethics_case_messages', 'ethics_cases'],
        'Marcación fuera de turno'  => ['attendance_offshift_authorizations'],
        'Capacitaciones'            => ['training_quiz_attempts', 'training_enrollments', 'training_questions', 'training_lessons', 'training_targets', 'training_courses'],
        // Las categorías de beneficios y las carpetas de documentos se conservan: son la organización
        // de cada sección (el sistema además recrea las de por defecto), no datos cargados
        'Beneficios'                => ['benefits'],
        'Documentos de la empresa'  => ['company_document_reads', 'company_documents'],
    ];

    /** ids de los usuarios a borrar: inactivos de una empresa (nunca el Super Admin) y el de prueba */
    public function userIds(): array
    {
        $inactive = DB::table('users')->whereNotNull('tenant_id')->where('status', 'inactive')
            ->where('role', '!=', 'super_admin')->pluck('id');

        $test = DB::table('users')->where('role', self::TEST_USER_ROLE)->get(['id', 'first_name', 'last_name'])
            ->filter(fn ($u) => self::normalize("{$u->first_name} {$u->last_name}") === self::TEST_USER_NAME)
            ->pluck('id');

        return $inactive->merge($test)->unique()->values()->all();
    }

    /** Cuántos registros se borrarían (no borra nada) */
    public function plan(): array
    {
        $ids = $this->userIds();
        $plan = [
            'Colaboradores inactivos y usuario de prueba' => count($ids),
            '  · su asistencia (jornadas)' => $ids ? DB::table('attendances')->whereIn('user_id', $ids)->count() : 0,
            '  · sus marcaciones' => $ids ? DB::table('attendance_punches')->whereIn('user_id', $ids)->count() : 0,
            '  · sus documentos del legajo' => $ids ? DB::table('employee_documents')->whereIn('user_id', $ids)->count() : 0,
            '  · sus solicitudes' => $ids ? DB::table('leave_requests')->whereIn('user_id', $ids)->count() : 0,
            '  · sus boletas' => $ids ? DB::table('payslips')->whereIn('user_id', $ids)->count() : 0,
        ];
        foreach (self::TABLES as $label => $tables) {
            $plan[$label] = collect($tables)->filter(fn ($t) => Schema::hasTable($t))->sum(fn ($t) => DB::table($t)->count());
        }

        return $plan;
    }

    /** Borra todo lo indicado. Devuelve lo borrado (mismas etiquetas que plan()). */
    public function run(): array
    {
        $plan  = $this->plan();
        $ids   = $this->userIds();
        $files = $this->filesToDelete($ids);

        DB::transaction(function () use ($ids) {
            foreach (self::TABLES as $tables) {
                foreach ($tables as $table) {
                    if (Schema::hasTable($table)) {
                        DB::table($table)->delete();
                    }
                }
            }
            // Destinatarios de beneficios y documentos borrados (relación polimórfica, sin llave foránea)
            DB::table('audience_targets')->whereIn('targetable_type', [Benefit::class, CompanyDocument::class])->delete();

            if ($ids) {
                // Lo que apunta a la persona sin llave foránea; el resto lo borra la base en cascada
                DB::table('notifications')->where('notifiable_type', User::class)->whereIn('notifiable_id', $ids)->delete();
                DB::table('personal_access_tokens')->where('tokenable_type', User::class)->whereIn('tokenable_id', $ids)->delete();
                DB::table('audience_targets')->where('target_type', 'user')->whereIn('target_id', $ids)->delete();
                if (Schema::hasTable('sessions')) {
                    DB::table('sessions')->whereIn('user_id', $ids)->delete();
                }
                foreach (array_chunk($ids, 500) as $chunk) {
                    DB::table('users')->whereIn('id', $chunk)->delete();
                }
            }
        });

        $this->deleteFiles($files);

        return $plan;
    }

    /**
     * Archivos de lo que se borra: [disco|null, ruta, ¿selfie con miniatura?]. disco null = se busca en
     * los discos privado y público (rutas generadas por el sistema, únicas).
     *
     * @return list<array{0: ?string, 1: string, 2: bool}>
     */
    private function filesToDelete(array $ids): array
    {
        $files = [];
        $add = function (iterable $paths, ?string $disk = null, bool $selfie = false) use (&$files) {
            foreach ($paths as $path) {
                if (filled($path)) {
                    $files[] = [$disk, (string) $path, $selfie];
                }
            }
        };
        $selfieDisk = config('filesystems.attendance_disk', 'private');

        $add(DB::table('ethics_case_attachments')->pluck('path'));
        $add(DB::table('benefits')->pluck('image_path'));
        foreach (['training_lessons', 'company_documents'] as $table) {
            foreach (DB::table($table)->whereNotNull('file_path')->get(['file_disk', 'file_path']) as $row) {
                $add([$row->file_path], $row->file_disk ?: null);
            }
        }

        foreach (array_chunk($ids, 500) as $chunk) {
            $add(DB::table('users')->whereIn('id', $chunk)->pluck('avatar_path'));
            foreach (DB::table('attendances')->whereIn('user_id', $chunk)->get(['clock_in_photo', 'clock_out_photo', 'justification_file']) as $a) {
                $add([$a->clock_in_photo, $a->clock_out_photo], $selfieDisk, true);
                $add([$a->justification_file]);
            }
            $add(DB::table('attendance_punches')->whereIn('user_id', $chunk)->pluck('photo_path'), $selfieDisk, true);
            $add(DB::table('employee_documents')->whereIn('user_id', $chunk)->pluck('path'));
            $add(DB::table('leave_requests')->whereIn('user_id', $chunk)->pluck('attachment_path'));
            $add(DB::table('payslips')->whereIn('user_id', $chunk)->pluck('file_path'));
        }

        return $files;
    }

    /** Un archivo que no se pudo borrar queda en el registro: nunca deshace la limpieza */
    private function deleteFiles(array $files): void
    {
        foreach ($files as [$disk, $path, $selfie]) {
            try {
                if ($selfie) {
                    app(\App\Modules\Attendance\Services\Attendance\SelfieStorage::class)->delete($path, $disk);

                    continue;
                }
                foreach ($disk ? [$disk] : ['private', 'public'] as $d) {
                    Storage::disk($d)->delete($path);
                }
            } catch (\Throwable $e) {
                Log::warning("Limpieza: no se pudo borrar el archivo {$path}: " . $e->getMessage());
            }
        }
    }

    public static function normalize(string $name): string
    {
        return Str::upper(trim(preg_replace('/\s+/', ' ', Str::ascii($name))));
    }
}
