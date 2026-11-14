<?php

namespace App\Modules\Core\Services;

use App\Modules\Benefits\Models\Benefit;
use App\Modules\CompanyDocs\Models\CompanyDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
        $footprint = app(UserPurger::class)->footprint($ids);
        $plan = [
            'Colaboradores inactivos y usuario de prueba' => count($ids),
            '  · su asistencia (jornadas)' => $footprint['asistencia'],
            '  · sus marcaciones' => $footprint['marcaciones'],
            '  · sus documentos del legajo' => $footprint['documentos'],
            '  · sus solicitudes' => $footprint['solicitudes'],
            '  · sus boletas' => $footprint['boletas'],
        ];
        foreach (self::TABLES as $label => $tables) {
            $plan[$label] = collect($tables)->filter(fn ($t) => Schema::hasTable($t))->sum(fn ($t) => DB::table($t)->count());
        }

        return $plan;
    }

    /** Borra todo lo indicado. Devuelve lo borrado (mismas etiquetas que plan()). */
    public function run(): array
    {
        $plan   = $this->plan();
        $ids    = $this->userIds();
        $purger = app(UserPurger::class);
        $files  = array_merge($this->sectionFiles(), $purger->filesFor($ids));

        DB::transaction(function () use ($ids, $purger) {
            foreach (self::TABLES as $tables) {
                foreach ($tables as $table) {
                    if (Schema::hasTable($table)) {
                        DB::table($table)->delete();
                    }
                }
            }
            // Destinatarios de beneficios y documentos borrados (relación polimórfica, sin llave foránea)
            DB::table('audience_targets')->whereIn('targetable_type', [Benefit::class, CompanyDocument::class])->delete();

            // Las personas: misma regla que «Eliminar colaborador» (UserPurger)
            $purger->deleteRows($ids);
        });

        $purger->deleteFiles($files);

        return $plan;
    }

    /**
     * Archivos de las secciones que se vacían: [disco|null, ruta, ¿selfie?] (los de las personas los da UserPurger).
     *
     * @return list<array{0: ?string, 1: string, 2: bool}>
     */
    private function sectionFiles(): array
    {
        $files = [];
        foreach (DB::table('ethics_case_attachments')->pluck('path')->merge(DB::table('benefits')->pluck('image_path'))->filter() as $path) {
            $files[] = [null, (string) $path, false];
        }
        foreach (['training_lessons', 'company_documents'] as $table) {
            foreach (DB::table($table)->whereNotNull('file_path')->get(['file_disk', 'file_path']) as $row) {
                $files[] = [$row->file_disk ?: null, (string) $row->file_path, false];
            }
        }

        return $files;
    }

    public static function normalize(string $name): string
    {
        return Str::upper(trim(preg_replace('/\s+/', ' ', Str::ascii($name))));
    }
}
