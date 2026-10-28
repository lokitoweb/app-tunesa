<?php

namespace App\Modules\Attendance\Models;

use App\Modules\Core\Casts\UtcDateTime;
use App\Modules\Core\Models\Concerns\BelongsToTenant;
use App\Modules\Core\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Plan de contingencia de marcación (caída de la app, sin señal, falla del sistema). Lo activa
 * quien tiene attendance.contingency, para toda la empresa, sedes, áreas o personas, con motivo
 * y hora de fin: se apaga solo al vencer. Mientras rige, las personas cubiertas marcan desde
 * Mi espacio con la hora del servidor (AttendanceService::punch con contingencyId).
 */
class AttendanceContingency extends Model
{
    use BelongsToTenant;

    public const SCOPES = ['all' => 'Toda la empresa', 'branch' => 'Sedes', 'department' => 'Áreas', 'users' => 'Personas'];

    /** Vigencia máxima de una activación (h): una contingencia no es la forma normal de marcar */
    public const MAX_HOURS = 72;

    protected $fillable = ['tenant_id', 'scope', 'scope_ids', 'reason', 'starts_at', 'ends_at', 'ended_at', 'created_by', 'ended_by'];

    protected $casts = ['scope_ids' => 'array', 'starts_at' => UtcDateTime::class, 'ends_at' => UtcDateTime::class, 'ended_at' => UtcDateTime::class];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function ender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by');
    }

    /** Vigentes ahora: empezaron, no vencieron y nadie las desactivó */
    public function scopeActive(Builder $query): Builder
    {
        $now = CarbonImmutable::now('UTC');

        return $query->whereNull('ended_at')->where('starts_at', '<=', $now)->where('ends_at', '>', $now);
    }

    public function isActive(): bool
    {
        $now = CarbonImmutable::now('UTC');

        return ! $this->ended_at && $this->starts_at->lte($now) && $this->ends_at->gt($now);
    }

    /** ¿La persona está dentro del alcance? (sede asignada vigente, su área o la lista de personas) */
    public function covers(User $user): bool
    {
        $ids = array_map('intval', $this->scope_ids ?? []);

        return match ($this->scope) {
            'all'        => true,
            'users'      => in_array($user->id, $ids, true),
            'department' => in_array((int) $user->department_id, $ids, true),
            'branch'     => $user->branches()->whereIn('branches.id', $ids)->exists(),
            default      => false,
        };
    }

    /** El plan vigente que cubre a la persona ahora, si hay uno */
    public static function activeFor(User $user): ?self
    {
        return static::withoutGlobalScopes()->where('tenant_id', $user->tenant_id)->active()->latest('id')->get()
            ->first(fn (self $c) => $c->covers($user));
    }
}
