<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Borrado definitivo de personas: una sola regla para la limpieza de datos (DataCleanup) y para
 * «Eliminar colaborador» (EmployeeDeletion). La base borra en cascada lo que cuelga de la persona
 * (asistencia, marcaciones, legajo, solicitudes, boletas, etc.); aquí se borra lo que no tiene llave
 * foránea (avisos, tokens de la app, sesiones, destinatarios) y sus archivos.
 *
 * Uso: $files = filesFor($ids) → dentro de una transacción deleteRows($ids) → después deleteFiles($files)
 * (los archivos se borran al final: si la base falla, no se pierde ninguno).
 */
final class UserPurger
{
    /** Lo que cuelga de las personas, para mostrarlo antes de borrar */
    public function footprint(array $ids): array
    {
        $count = fn (string $table) => $ids ? DB::table($table)->whereIn('user_id', $ids)->count() : 0;

        return [
            'asistencia'  => $count('attendances'),
            'marcaciones' => $count('attendance_punches'),
            'documentos'  => $count('employee_documents'),
            'solicitudes' => $count('leave_requests'),
            'boletas'     => $count('payslips'),
        ];
    }

    /**
     * Archivos de las personas: [disco|null, ruta, ¿selfie con miniatura?]. disco null = se busca en los
     * discos privado y público (rutas generadas por el sistema, únicas).
     *
     * @return list<array{0: ?string, 1: string, 2: bool}>
     */
    public function filesFor(array $ids): array
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

        foreach (array_chunk($ids, 500) as $chunk) {
            $add(DB::table('users')->whereIn('id', $chunk)->pluck('avatar_path'), 'public');
            foreach (DB::table('attendances')->whereIn('user_id', $chunk)->get(['clock_in_photo', 'clock_out_photo', 'justification_file']) as $a) {
                $add([$a->clock_in_photo, $a->clock_out_photo], $selfieDisk, true);
                $add([$a->justification_file]);
            }
            $add(DB::table('attendance_punches')->whereIn('user_id', $chunk)->pluck('photo_path'), $selfieDisk, true);
            foreach (DB::table('employee_documents')->whereIn('user_id', $chunk)->get(['disk', 'path']) as $d) {
                $add([$d->path], $d->disk ?: null);
            }
            $add(DB::table('leave_requests')->whereIn('user_id', $chunk)->pluck('attachment_path'));
            $add(DB::table('payslips')->whereIn('user_id', $chunk)->pluck('file_path'));
        }

        return $files;
    }

    /** Borra las personas y lo que las apunta sin llave foránea. Llamar dentro de una transacción. */
    public function deleteRows(array $ids): void
    {
        if (! $ids) {
            return;
        }
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

    /** Un archivo que no se pudo borrar queda en el registro: nunca deshace el borrado de la base */
    public function deleteFiles(array $files): void
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
                Log::warning("Borrado: no se pudo borrar el archivo {$path}: " . $e->getMessage());
            }
        }
    }
}
