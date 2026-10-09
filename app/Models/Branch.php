<?php

namespace App\Models;

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
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'name',
        'code',
        'address',
        'city',
        'country',
        'latitude',
        'longitude',
        'allowed_radius_meters',
        'timezone',
        'is_active',
        'notes',
        'supervisor_id',
    ];

    protected $casts = [
        'latitude'               => 'decimal:8',
        'longitude'              => 'decimal:8',
        'allowed_radius_meters'  => 'integer',
        'is_active'              => 'boolean',
    ];

    // =========================================================================
    // RELACIONES
    // =========================================================================

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'employee_branch')
            ->withPivot(['is_primary', 'assigned_from', 'assigned_until'])
            ->withTimestamps();
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
}
