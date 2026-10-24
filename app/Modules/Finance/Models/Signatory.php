<?php

namespace App\Modules\Finance\Models;

use App\Modules\Core\Models\Concerns\BelongsToTenant;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Apoderado / gerente de la empresa con su firma escaneada (archivo privado). Su firma se
 * estampa en la OC/OS que aprueba y en la OP que autoriza; can_authorize lo hace "la persona
 * designada" para autorizar pagos (desde el panel o la app).
 */
class Signatory extends Model
{
    use BelongsToTenant;

    public const DISK = 'private';

    protected $fillable = ['tenant_id', 'user_id', 'position', 'signature_path', 'can_authorize', 'is_active'];

    protected $casts = ['can_authorize' => 'boolean', 'is_active' => 'boolean'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Apoderado activo del usuario en la empresa actual */
    public static function activeFor(?int $userId): ?self
    {
        return $userId ? static::where('user_id', $userId)->where('is_active', true)->first() : null;
    }

    /** ¿Puede autorizar órdenes de pago? */
    public static function canAuthorize(User $user): bool
    {
        return (bool) static::activeFor($user->id)?->can_authorize;
    }

    /** Firma como data URI para estamparla en un PDF (Dompdf no lee el disco privado) */
    public function signatureDataUri(): ?string
    {
        $disk = \Illuminate\Support\Facades\Storage::disk(self::DISK);
        if (! $this->signature_path || ! $disk->exists($this->signature_path)) {
            return null;
        }

        return 'data:' . $disk->mimeType($this->signature_path) . ';base64,' . base64_encode($disk->get($this->signature_path));
    }
}
