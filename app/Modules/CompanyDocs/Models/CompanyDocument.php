<?php

namespace App\Modules\CompanyDocs\Models;

use App\Modules\Core\Models\Concerns\BelongsToTenant;
use App\Modules\Core\Models\Concerns\HasAudience;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Documento de la empresa (PDF) que el personal lee en la app. Si pide
 * confirmación, cada persona marca "Leído y conforme" (queda la fecha y la IP).
 */
class CompanyDocument extends Model
{
    use BelongsToTenant, HasAudience, SoftDeletes;

    public const STATUSES = ['draft' => 'Borrador', 'published' => 'Publicado', 'archived' => 'Archivado'];

    protected $fillable = [
        'tenant_id', 'folder_id', 'title', 'description', 'version', 'file_disk', 'file_path', 'file_name', 'file_size',
        'requires_acknowledgement', 'acknowledge_by', 'status', 'published_at', 'created_by',
    ];

    protected $casts = [
        'requires_acknowledgement' => 'boolean',
        'acknowledge_by'           => 'date',
        'published_at'             => 'datetime',
        'file_size'                => 'integer',
    ];

    public function folder(): BelongsTo
    {
        return $this->belongsTo(CompanyDocumentFolder::class, 'folder_id');
    }

    public function reads(): HasMany
    {
        return $this->hasMany(CompanyDocumentRead::class, 'document_id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }
}
