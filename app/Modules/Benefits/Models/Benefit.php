<?php

namespace App\Modules\Benefits\Models;

use App\Modules\Core\Models\Concerns\BelongsToTenant;
use App\Modules\Core\Models\Concerns\HasAudience;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * Beneficio para el personal. En la app se ve si está publicado, vigente
 * (valid_from / valid_until) y la persona está en su público.
 */
class Benefit extends Model
{
    use BelongsToTenant, HasAudience, SoftDeletes;

    public const STATUSES = ['draft' => 'Borrador', 'published' => 'Publicado', 'archived' => 'Archivado'];

    public const IMAGE_DISK = 'public';

    protected $fillable = [
        'tenant_id', 'category_id', 'title', 'summary', 'description', 'partner', 'highlight', 'promo_code',
        'how_to_use', 'terms', 'url', 'contact', 'location', 'image_path', 'valid_from', 'valid_until',
        'is_featured', 'status', 'published_at', 'created_by',
    ];

    protected $casts = [
        'valid_from'   => 'date',
        'valid_until'  => 'date',
        'is_featured'  => 'boolean',
        'published_at' => 'datetime',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(BenefitCategory::class, 'category_id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }

    /** Publicado y dentro de su vigencia (fechas de la empresa) */
    public function scopeAvailable(Builder $query): Builder
    {
        $today = now(tenant_timezone())->toDateString();

        return $query->published()
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhereDate('valid_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $today));
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    public function isExpired(): bool
    {
        return $this->valid_until !== null && $this->valid_until->lt(now(tenant_timezone())->startOfDay());
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? Storage::disk(self::IMAGE_DISK)->url($this->image_path) : null;
    }
}
