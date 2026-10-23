<?php

namespace App\Modules\Core\Controllers;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\AttendancePunch;
use App\Modules\Attendance\Services\Attendance\SelfieStorage;
use App\Modules\Core\Support\PrivateFile;
use App\Modules\HR\Models\EmployeeDocument;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Entrega archivos privados a través de URLs firmadas y temporales
 * (middleware 'signed'). Ver App\Modules\Core\Services\SecureFileUrl.
 *
 * GET /secure-files/{kind}/{id}?expires=...&signature=...
 */
class SecureFileController extends Controller
{
    public function show(string $kind, int $id): Response
    {
        // Miniatura de selfie: la de 160 px si existe; las fotos anteriores no la tienen
        $thumb = str_ends_with($kind, '-thumb');
        if ($thumb) {
            $kind = substr($kind, 0, -6);
            abort_unless(in_array($kind, ['selfie-in', 'selfie-out', 'punch-photo'], true), 404);
        }

        [$disk, $path, $name] = match ($kind) {
            'document' => $this->document($id),
            'selfie-in', 'selfie-out' => $this->selfie($id, $kind === 'selfie-in' ? 'in' : 'out'),
            'punch-photo' => $this->punchPhoto($id),
            default => abort(404),
        };

        abort_if(empty($path), 404);

        if ($thumb && Storage::disk($disk)->exists($small = SelfieStorage::thumbPathFor($path))) {
            $path = $small;
        }

        // Selfies anteriores a la Fase 3 quedaron en el disco público
        if (! Storage::disk($disk)->exists($path) && str_starts_with($kind, 'selfie') && Storage::disk('public')->exists($path)) {
            $disk = 'public';
        }

        return PrivateFile::response($disk, $path, $name);
    }

    private function document(int $id): array
    {
        $doc = EmployeeDocument::withoutGlobalScopes()->whereNull('deleted_at')->findOrFail($id);

        return [$doc->disk, $doc->path, $doc->original_name];
    }

    /** Foto de un evento de la bitácora (incluye las de descanso) */
    private function punchPhoto(int $id): array
    {
        $punch = AttendancePunch::withoutGlobalScopes()->findOrFail($id);
        $path  = $punch->photo_purged_at ? null : $punch->photo_path;

        return [config('filesystems.attendance_disk', 'private'), $path,
            "punch_{$punch->type}_{$id}." . ($path ? (pathinfo($path, PATHINFO_EXTENSION) ?: 'jpg') : 'jpg')];
    }

    private function selfie(int $id, string $moment): array
    {
        $attendance = Attendance::withoutGlobalScopes()->findOrFail($id);
        $path       = $moment === 'in' ? $attendance->clock_in_photo : $attendance->clock_out_photo;

        // Las selfies nuevas son .webp (SelfieStorage); las anteriores, .jpg
        $extension = $path ? (pathinfo($path, PATHINFO_EXTENSION) ?: 'jpg') : 'jpg';

        return [config('filesystems.attendance_disk', 'private'), $path, "selfie_{$moment}_{$id}.{$extension}"];
    }
}
