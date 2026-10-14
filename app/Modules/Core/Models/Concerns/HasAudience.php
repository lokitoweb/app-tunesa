<?php

namespace App\Modules\Core\Models\Concerns;

use App\Modules\Core\Models\AudienceTarget;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\Audience;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Contenido con público (beneficios, documentos de la empresa): a quién se le
 * muestra según sus reglas (Support\Audience). Sin reglas no lo ve nadie.
 */
trait HasAudience
{
    public function audienceTargets(): MorphMany
    {
        return $this->morphMany(AudienceTarget::class, 'targetable');
    }

    /** Solo lo que le corresponde a esta persona */
    public function scopeVisibleToAudience(Builder $query, User $user): Builder
    {
        return $query->whereHas('audienceTargets', fn (Builder $q) => Audience::matching($q, $user));
    }

    public function isVisibleTo(User $user): bool
    {
        return Audience::matching($this->audienceTargets()->getQuery(), $user)->exists();
    }

    /** Reemplaza las reglas: [['type' => 'branch', 'id' => 3, 'value' => null], ...] */
    public function syncAudience(array $targets): void
    {
        $this->audienceTargets()->delete();

        $rows = Audience::normalize($targets);
        foreach ($rows as $row) {
            $this->audienceTargets()->create($row + ['tenant_id' => $this->tenant_id]);
        }
        $this->unsetRelation('audienceTargets');
    }

    /** Personas activas a las que llega (para avisos push) */
    public function audienceUsers(): Builder
    {
        return Audience::users($this->audienceTargets()->get(), (int) $this->tenant_id);
    }
}
