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
        'branch_type',
        'address',
        'city',
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
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
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
        return $viewer->seesAllAttendance() || $viewer->isOrgScoped() ? $query : $query->where($this->qualifyColumn('supervisor_id'), $viewer->id);
    }
}
