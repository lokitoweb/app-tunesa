<?php

namespace App\Modules\CompanyDocs\Services;

use App\Modules\CompanyDocs\Models\CompanyDocument;
use App\Modules\CompanyDocs\Models\CompanyDocumentRead;
use App\Modules\CompanyDocs\Notifications\CompanyDocumentPublished;
use App\Modules\Core\Jobs\NotifyAudience;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Documentos de la empresa: guardado del PDF (disco privado), público,
 * aviso al publicar y registro de lectura / confirmación.
 */
class CompanyDocumentService
{
    public function disk(): string
    {
        return config('filesystems.documents_disk', 'private');
    }

    public function save(CompanyDocument $document, array $data, User $actor, ?UploadedFile $file = null): CompanyDocument
    {
        $wasPublished = $document->exists && $document->published_at !== null;
        $oldFile = $document->exists ? [$document->file_disk, $document->file_path] : null;

        DB::transaction(function () use ($document, $data, $actor, $file) {
            $document->fill(collect($data)->except(['targets', 'file', 'notify'])->all());
            $document->created_by ??= $actor->id;
            if (! $document->requires_acknowledgement) {
                $document->acknowledge_by = null;
            }
            if ($document->isPublished() && ! $document->published_at) {
                $document->published_at = now();
            }

            if ($file) {
                $tenant = $document->tenant_id ?? current_tenant_id();
                $document->file_disk = $this->disk();
                $document->file_path = $file->storeAs("company-documents/{$tenant}", Str::uuid() . '.pdf', $this->disk());
                $document->file_name = mb_substr($file->getClientOriginalName(), 0, 200);
                $document->file_size = (int) $file->getSize();
            }

            $document->save();
            $document->syncAudience($data['targets'] ?? []);
        });

        // El PDF anterior se borra solo después de guardar el nuevo
        if ($file && $oldFile && $oldFile[1]) {
            Storage::disk($oldFile[0])->delete($oldFile[1]);
        }

        if ($document->isPublished() && ! $wasPublished && ($data['notify'] ?? true)) {
            NotifyAudience::dispatch(CompanyDocument::class, $document->id, CompanyDocumentPublished::class);
        }

        return $document;
    }

    public function setStatus(CompanyDocument $document, string $status, bool $notify = true): void
    {
        $firstPublish = $status === 'published' && $document->published_at === null;
        $document->status = $status;
        if ($firstPublish) {
            $document->published_at = now();
        }
        $document->save();

        if ($firstPublish && $notify) {
            NotifyAudience::dispatch(CompanyDocument::class, $document->id, CompanyDocumentPublished::class);
        }
    }

    /** La persona abrió el PDF */
    public function markRead(CompanyDocument $document, User $user): CompanyDocumentRead
    {
        $read = CompanyDocumentRead::firstOrNew(['document_id' => $document->id, 'user_id' => $user->id]);
        $read->tenant_id = $document->tenant_id;
        $read->first_read_at ??= now();
        $read->last_read_at = now();
        $read->save();

        return $read;
    }

    /** "Leído y conforme": queda la fecha y la IP (no se puede deshacer) */
    public function acknowledge(CompanyDocument $document, User $user, ?string $ip): CompanyDocumentRead
    {
        $read = $this->markRead($document, $user);
        if (! $read->acknowledged_at) {
            $read->forceFill(['acknowledged_at' => now(), 'acknowledged_ip' => $ip])->save();
        }

        return $read;
    }

    /** Público del documento con su lectura (para el reporte del panel) */
    public function audienceWithReads(CompanyDocument $document): Builder
    {
        return $document->audienceUsers()
            ->leftJoin('company_document_reads as r', fn ($j) => $j->on('r.user_id', '=', 'users.id')->where('r.document_id', $document->id))
            ->select('users.id', 'users.first_name', 'users.last_name', 'users.employee_code', 'users.document_number', 'users.position', 'users.department_id',
                'r.first_read_at', 'r.acknowledged_at');
    }
}
