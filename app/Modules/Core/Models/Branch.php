<?php

namespace App\Modules\Core\Models;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\HR\Models\EmployeeShift;
use App\Modules\Core\Models\Concerns\BelongsToBranchScope;
use App\Modules\Core\Models\Concerns\BelongsToTenant;
use App\Modules\Core\Services\OrgScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Modelo Branch (Sucursal/Obra)
 * Contiene la geocerca (lat, lng, radio) para validación GPS.
 *
 * @property int    $id
 * @property int    $tenant_id
 * @property string $name
 * @property float  $latitude
 * @property float  $longitude
 * @property int    $allowed_radius_meters
 */
class Branch extends Model
{
    use BelongsToTenant, SoftDeletes, BelongsToBranchScope;

    /** Alcance por sede: [columna de sede, columna de colaborador] (BelongsToBranchScope) */
    public const BRANCH_SCOPE = ['id', null];

    protected $fillable = [
        'tenant_id',
        'zone_id',
        'name',
        'code',
        'document_series',
        'branch_type',
        'address',
        'city',
        'province',
        'district',
        'country',
        'latitude',
        'longitude',
        'allowed_radius_meters',
        'geofence_tolerance_meters',
        'max_gps_accuracy_meters',
        'geofence_policy',
        'timezone',
        'is_active',
        'notes',
        'supervisor_id',
    ];

    /** Margen máximo de la zona de borde si la sede no define el suyo (m) */
    public const DEFAULT_EDGE_TOLERANCE = 15;

    /** Tipos de sede (branches.branch_type) */
    public const TYPES = [
        'agency'    => 'Agencia (carga / boletería)',
        'terminal'  => 'Terminal',
        'warehouse' => 'Almacén',
        'workshop'  => 'Taller',
        'corporate' => 'Corporativo',
    ];

    protected $casts = [
        'latitude'               => 'decimal:8',
        'longitude'              => 'decimal:8',
        'allowed_radius_meters'  => 'integer',
        'geofence_tolerance_meters' => 'integer',
        'max_gps_accuracy_meters'   => 'integer',
        'is_active'              => 'boolean',
    ];

    // =========================================================================
    // RELACIONES
    // =========================================================================

    protected static function booted(): void
    {
        // Un usuario con alcance de zona o sede edita sus sedes, pero no cambia la estructura
        static::saving(function (Branch $branch) {
            if ((! $branch->exists || $branch->isDirty('zone_id')) && app(OrgScope::class)->restricted()) {
                throw new \DomainException('Solo un usuario con alcance de toda la empresa crea sedes o las cambia de zona.');
            }
        });

        // Compatibilidad: quien aún guarda un solo supervisor (supervisor_id) lo refleja en la lista
        static::saved(function (Branch $branch) {
            if ($branch->syncingSupervisors || ! $branch->wasChanged('supervisor_id') && ! $branch->wasRecentlyCreated) {
                return;
            }
            if ($old = $branch->getOriginal('supervisor_id')) {
                $branch->supervisors()->detach($old);
            }
            if ($branch->supervisor_id) {
                $branch->supervisors()->syncWithoutDetaching([$branch->supervisor_id]);
            }
        });
    }

    /** true mientras syncSupervisors() guarda: el evento saved no debe tocar la lista */
    private bool $syncingSupervisors = false;

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    /** Primer supervisor de la lista (compatibilidad: API antigua y textos que muestran un nombre) */
    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    /** Supervisores de la sede (uno o varios: mañana, tarde o a la vez). Sin ninguno, la ven todos los supervisores. */
    public function supervisors(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'branch_supervisors')->withTimestamps()->orderBy('users.first_name');
    }

    /** Reemplaza los supervisores de la sede; supervisor_id queda como el primero (o null) */
    public function syncSupervisors(array $userIds): void
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($userIds))));
        $this->supervisors()->sync($ids);
        $this->syncingSupervisors = true;
        try {
            $this->forceFill(['supervisor_id' => $ids[0] ?? null])->saveQuietly();
        } finally {
            $this->syncingSupervisors = false;
        }
        $this->unsetRelation('supervisors')->unsetRelation('supervisor');
    }

    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'employee_branch')
            ->using(EmployeeBranch::class)
            ->withPivot(['is_primary', 'assigned_from', 'assigned_until'])
            ->withTimestamps();
    }

    /** Plantilla de la sede: cargos y plazas previstas de cada uno */
    public function positions(): BelongsToMany
    {
        return $this->belongsToMany(Position::class, 'branch_positions')->withPivot(['planned_headcount', 'tenant_id'])->withTimestamps();
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function employeeShifts(): HasMany
    {
        return $this->hasMany(EmployeeShift::class);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Devuelve la zona horaria efectiva de la sucursal.
     * Si no tiene propia, usa la del tenant.
     */
    public function getEffectiveTimezone(): string
    {
        return $this->timezone ?? $this->tenant->timezone ?? 'UTC';
    }

    /** Margen máximo de la zona de borde de esta sede (m) */
    public function edgeToleranceMeters(): int
    {
        return $this->geofence_tolerance_meters ?? self::DEFAULT_EDGE_TOLERANCE;
    }

    /** Precisión GPS máxima en esta sede: la propia o la de la empresa */
    public function maxGpsAccuracy(TenantSetting $settings): int
    {
        return $this->max_gps_accuracy_meters ?: $settings->maxGpsAccuracy();
    }

    /** ¿Fuera del radio se registra como incidencia ("flag") en vez de rechazarse? Sede → empresa. */
    public function flagsOutOfGeofence(): bool
    {
        return $this->geofence_policy
            ? $this->geofence_policy === 'flag'
            : CompanyConfiguration::forTenantId($this->tenant_id)->flagsOutOfGeofence();
    }

    /**
     * Retorna las coordenadas como array [lat, lng].
     */
    public function getCoordinates(): array
    {
        return [
            'latitude'  => (float) $this->latitude,
            'longitude' => (float) $this->longitude,
        ];
    }

    /** Sucursales que puede ver $viewer: todas o solo las que supervisa */
    public function scopeVisibleTo($query, User $viewer)
    {
        // Con alcance de zona o sede, BranchScope ya dejó solo las sedes de su territorio
        return $viewer->seesAllAttendance() || $viewer->isOrgScoped() ? $query : $query->supervisedBy($viewer);
    }

    /**
     * Sedes a cargo de $supervisor: donde figura entre sus supervisores y, si es supervisor
     * (attendance.view_team), también las que no tienen ningún supervisor asignado. Regla única
     * para horarios, asistencia, aprobaciones, avisos y la app.
     */
    public function scopeSupervisedBy($query, User $supervisor)
    {
        $id = $this->qualifyColumn('id');
        $pivot = fn () => \Illuminate\Support\Facades\DB::table('branch_supervisors')->select('branch_id');

        return $query->where(fn ($q) => $q
            ->whereIn($id, $pivot()->where('user_id', $supervisor->id))
            ->when($supervisor->hasPermission('attendance.view_team'), fn ($q) => $q->orWhereNotIn($id, $pivot())));
    }
}
