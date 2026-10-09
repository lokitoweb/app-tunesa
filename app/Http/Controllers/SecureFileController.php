<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\EmployeeDocument;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Entrega archivos privados a través de URLs firmadas y temporales
 * (middleware 'signed'). Ver App\Services\SecureFileUrl.
 *
 * GET /secure-files/{kind}/{id}?expires=...&signature=...
 */
class SecureFileController extends Controller
{
    public function show(string $kind, int $id): Response
    {
        [$disk, $path, $name] = match ($kind) {
            'document' => $this->document($id),
            'selfie-in', 'selfie-out' => $this->selfie($id, $kind === 'selfie-in' ? 'in' : 'out'),
            default => abort(404),
        };

        abort_if(empty($path), 404);

        // Selfies anteriores a la Fase 3 quedaron en el disco público
        if (! Storage::disk($disk)->exists($path) && str_starts_with($kind, 'selfie') && Storage::disk('public')->exists($path)) {
            $disk = 'public';
        }

        abort_unless(Storage::disk($disk)->exists($path), 404);

        return Storage::disk($disk)->response($path, $name, [
            'Cache-Control'          => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
        ], 'inline');
    }

    private function document(int $id): array
    {
        $doc = EmployeeDocument::withoutGlobalScopes()->whereNull('deleted_at')->findOrFail($id);

        return [$doc->disk, $doc->path, $doc->original_name];
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
