<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Modelo User
 * Cubre todos los roles del sistema desde super_admin hasta employee.
 *
 * @property int    $id
 * @property int    $tenant_id
 * @property string $role
 * @property string $status
 * @property string $email
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'first_name',
        'last_name',
        'email',
        'password',
        'document_number',
        'document_type',
        'phone',
        'avatar_path',
        'birth_date',
        'gender',
        'role',
        'status',
        'employee_code',
        'hire_date',
        'termination_date',
        'contract_type',
        'base_salary',
        'position',
        'department',
        'fcm_token',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'birth_date'        => 'date',
        'hire_date'         => 'date',
        'termination_date'  => 'date',
        'base_salary'       => 'decimal:2',
        'password'          => 'hashed',
    ];

    // =========================================================================
    // RELACIONES
    // =========================================================================

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'employee_branch')
            ->withPivot(['is_primary', 'assigned_from', 'assigned_until'])
            ->withTimestamps();
    }

    /** Sucursal principal del empleado */
    public function primaryBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function employeeShifts(): HasMany
    {
        return $this->hasMany(EmployeeShift::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    // =========================================================================
    // HELPERS DE ROL
    // =========================================================================

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    public function isCompanyAdmin(): bool
    {
        return $this->role === 'company_admin';
    }

    public function isHrManager(): bool
    {
        return $this->role === 'hr_manager';
    }

    public function isSupervisor(): bool
    {
        return $this->role === 'supervisor';
    }

    public function isEmployee(): bool
    {
        return $this->role === 'employee';
    }

    /**
     * Verifica si el usuario puede administrar recursos del tenant.
     */
    public function canManageTenant(): bool
    {
        return in_array($this->role, ['company_admin', 'hr_manager']);
    }

    /**
     * Nombre completo del usuario.
     */
    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    /** Filtra empleados activos del tenant actual */
    public function scopeActiveEmployees($query)
    {
        return $query->where('role', 'employee')
            ->where('status', 'active');
    }
}
