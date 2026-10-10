<?php

namespace App\Modules\HR\Services;

use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Models\CompanyConfiguration;
use App\Modules\HR\Models\EmployeeDocument;
use App\Modules\Core\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Alta y revisión de documentos del legajo digital.
 *
 * - Guarda en el disco privado con nombre aleatorio (no adivinable).
 * - Calcula SHA-256 para integridad y para rechazar el mismo archivo dos veces.
 * - Un documento pendiente del mismo tipo se reemplaza; uno aprobado se
 *   mantiene hasta que RRHH apruebe el nuevo (entonces queda "superseded").
 */
class DocumentStorageService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function disk(): string
    {
        return config('filesystems.documents_disk', 'private');
    }

    /** Reglas de validación del archivo según la configuración de la empresa */
    public static function fileRules(?CompanyConfiguration $config = null): array
    {
        $maxKb = ($config?->max_upload_mb ?? 5) * 1024;

        return ['required', 'file', 'mimes:' . implode(',', EmployeeDocument::ALLOWED_MIMES), "max:{$maxKb}"];
    }

    /** Tipos MIME reales aceptados (se leen del contenido, no de la extensión) */
    public const REAL_MIME_TYPES = ['application/pdf', 'image/jpeg', 'image/png'];

    public function store(User $owner, UploadedFile $file, string $type, User $uploader, array $extra = []): EmployeeDocument
    {
        // La regla 'mimes' confía en el tipo que reporta el archivo; aquí se
        // inspecciona el contenido (un .php renombrado a .jpg no pasa).
        $realMime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());

        if (! in_array($realMime, self::REAL_MIME_TYPES, true)) {
            throw ValidationException::withMessages([
                'file' => ['El contenido del archivo no es un PDF, JPG o PNG válido.'],
            ]);
        }

        $sha256 = hash_file('sha256', $file->getRealPath());

        $duplicate = EmployeeDocument::where('user_id', $owner->id)
            ->current()
            ->where('sha256', $sha256)
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'file' => [$uploader->is($owner)
                    ? 'Este archivo ya está en tu legajo.'
                    : 'Este archivo ya está en el legajo del colaborador.'],
            ]);
        }

        $extension = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'][$realMime];
        $directory = "documents/{$owner->tenant_id}/{$owner->id}/{$type}";
        $path      = $file->storeAs($directory, Str::uuid() . '.' . $extension, $this->disk());

        return DB::transaction(function () use ($owner, $file, $type, $uploader, $extra, $sha256, $path, $realMime) {
            // Un pendiente del mismo tipo se reemplaza por el nuevo
            EmployeeDocument::where('user_id', $owner->id)
                ->where('type', $type)
                ->where('status', 'pending')
                ->get()
                ->each(fn (EmployeeDocument $old) => $this->purge($old));

            $document = EmployeeDocument::create([
                'tenant_id'     => $owner->tenant_id,
                'user_id'       => $owner->id,
                'uploaded_by'   => $uploader->id,
                'type'          => $type,
                'title'         => $extra['title'] ?? null,
                'expires_at'    => $extra['expires_at'] ?? null,
                'disk'          => $this->disk(),
                'path'          => $path,
                'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
                'mime_type'     => $realMime,
                'size_bytes'    => $file->getSize(),
                'sha256'        => $sha256,
                // Lo que sube RRHH ya nace aprobado
                'status'        => $uploader->hasPermission('documents.review') && $uploader->id !== $owner->id ? 'approved' : 'pending',
                'reviewed_by'   => $uploader->hasPermission('documents.review') && $uploader->id !== $owner->id ? $uploader->id : null,
                'reviewed_at'   => $uploader->hasPermission('documents.review') && $uploader->id !== $owner->id ? now() : null,
            ]);

            if ($document->status === 'approved') {
                $this->supersedePrevious($document);
            }

            $this->audit->log('document.uploaded', $document, ['type' => $type, 'sha256' => $sha256]);

            return $document;
        });
    }

    public function approve(EmployeeDocument $document, User $reviewer, ?string $expiresAt = null): EmployeeDocument
    {
        DB::transaction(function () use ($document, $reviewer, $expiresAt) {
            $document->update([
                'status'           => 'approved',
                'rejection_reason' => null,
                'reviewed_by'      => $reviewer->id,
                'reviewed_at'      => now(),
                'expires_at'       => $expiresAt ?? $document->expires_at,
            ]);
            $this->supersedePrevious($document);
        });

        $this->audit->log('document.approved', $document);

        return $document->fresh();
    }

    public function reject(EmployeeDocument $document, User $reviewer, string $reason): EmployeeDocument
    {
        $document->update([
            'status'           => 'rejected',
            'rejection_reason' => $reason,
            'reviewed_by'      => $reviewer->id,
            'reviewed_at'      => now(),
        ]);

        $this->audit->log('document.rejected', $document, ['reason' => $reason]);

        return $document->fresh();
    }

    /** Borra archivo y registro (solo pendientes o rechazados) */
    public function purge(EmployeeDocument $document): void
    {
        Storage::disk($document->disk)->delete($document->path);
        $document->forceDelete();
    }

    /** Verifica que el archivo en disco no cambió desde que se subió */
    public function verifyIntegrity(EmployeeDocument $document): bool
    {
        $disk = Storage::disk($document->disk);

        return $disk->exists($document->path)
            && hash('sha256', $disk->get($document->path)) === $document->sha256;
    }

    private function supersedePrevious(EmployeeDocument $document): void
    {
        EmployeeDocument::where('user_id', $document->user_id)
            ->where('type', $document->type)
            ->where('status', 'approved')
            ->where('id', '!=', $document->id)
            ->whereNotIn('type', ['certificate', 'other'])
            ->update(['status' => 'superseded']);
    }
}
