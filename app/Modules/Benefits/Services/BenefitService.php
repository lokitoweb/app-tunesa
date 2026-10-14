<?php

namespace App\Modules\Benefits\Services;

use App\Modules\Benefits\Models\Benefit;
use App\Modules\Benefits\Notifications\BenefitPublished;
use App\Modules\Core\Jobs\NotifyAudience;
use App\Modules\Core\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Alta y edición de beneficios (panel): datos, imagen, público y aviso a
 * quienes les corresponde cuando se publica por primera vez.
 */
class BenefitService
{
    public function save(Benefit $benefit, array $data, User $actor, ?UploadedFile $image = null, bool $removeImage = false): Benefit
    {
        $wasPublished = $benefit->exists && $benefit->published_at !== null;

        DB::transaction(function () use ($benefit, $data, $actor, $image, $removeImage) {
            $benefit->fill(collect($data)->except(['targets', 'image', 'remove_image', 'notify'])->all());
            $benefit->created_by ??= $actor->id;
            if ($benefit->isPublished() && ! $benefit->published_at) {
                $benefit->published_at = now();
            }

            if ($image || $removeImage) {
                $this->deleteImage($benefit);
                $benefit->image_path = $image
                    ? $image->storeAs('benefits/' . ($benefit->tenant_id ?? current_tenant_id()), Str::uuid() . '.' . $image->extension(), Benefit::IMAGE_DISK)
                    : null;
            }

            $benefit->save();
            $benefit->syncAudience($data['targets'] ?? []);
        });

        if ($benefit->isPublished() && ! $wasPublished && ($data['notify'] ?? true)) {
            NotifyAudience::dispatch(Benefit::class, $benefit->id, BenefitPublished::class);
        }

        return $benefit;
    }

    /** Publicar / archivar / volver a borrador */
    public function setStatus(Benefit $benefit, string $status): void
    {
        $firstPublish = $status === 'published' && $benefit->published_at === null;
        $benefit->status = $status;
        if ($firstPublish) {
            $benefit->published_at = now();
        }
        $benefit->save();

        if ($firstPublish) {
            NotifyAudience::dispatch(Benefit::class, $benefit->id, BenefitPublished::class);
        }
    }

    public function deleteImage(Benefit $benefit): void
    {
        if ($benefit->image_path) {
            Storage::disk(Benefit::IMAGE_DISK)->delete($benefit->image_path);
        }
    }
}
