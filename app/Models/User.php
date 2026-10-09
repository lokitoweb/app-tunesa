<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
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
    use BelongsToTenant, HasApiTokens, HasFactory, Notifiable, SoftDeletes;

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
        'must_change_password',
    ];

    /**
     * Jerarquía de rangos: nadie puede crear, editar ni asignar un rol de
     * rango superior al propio (ver canAssignRole / canManage).
     */
    public const ROLE_RANKS = [
        'super_admin'   => 100,
        'company_admin' => 80,
        'hr_manager'    => 60,
        'supervisor'    => 40,
        'employee'      => 20,
    ];

    /** Caché en memoria (no persistida) para consultas derivadas del rol */
    protected array $permissionCache = [];

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
        'locked_until'         => 'datetime',
        'last_login_at'        => 'datetime',
        'must_change_password' => 'boolean',
        'failed_login_attempts' => 'integer',
    ];

    /**
     * Al desactivar o suspender una cuenta se revocan todas sus sesiones
     * (tokens de la app), para que el acceso se corte en el acto.
     */
    protected static function booted(): void
    {
        static::updated(function (User $user) {
            if ($user->wasChanged('status') && $user->status !== 'active') {
                $user->tokens()->delete();
            }
        });
    }

    // =========================================================================
    // RELACIONES
    // =========================================================================

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'employee_branch')
            ->withPivot(['is_primary', 'assigned_from', 'assigned_until'])
            ->withTimestamps();
    }

    /** Sucursal principal del empleado (pivot is_primary) */
    public function primaryBranch(): BelongsToMany
    {
        return $this->branches()->wherePivot('is_primary', true);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    /** Rol del sistema que corresponde a users.role */
    public function roleModel(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role', 'slug')->whereNull('roles.tenant_id');
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

    public function profile(): HasOne
    {
        return $this->hasOne(EmployeeProfile::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
    }

    public function uniformSize(): HasOne
    {
        return $this->hasOne(UniformSize::class);
    }

    public function uniformDeliveries(): HasMany
    {
        return $this->hasMany(UniformDelivery::class);
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
     * Slugs de permisos del rol del usuario (memoizados por request).
     *
     * @return array<int, string>
     */
    public function permissionSlugs(): array
    {
        if (! array_key_exists('permission_slugs', $this->permissionCache)) {
            $this->permissionCache['permission_slugs'] = Permission::query()
                ->whereHas('roles', fn ($q) => $q->whereNull('tenant_id')->where('slug', $this->role))
                ->pluck('slug')
                ->all();
        }

        return $this->permissionCache['permission_slugs'];
    }

    /**
     * Verifica si el usuario tiene un permiso. El super admin tiene todos.
     */
    public function hasPermission(string $permission): bool
    {
        return $this->isSuperAdmin() || in_array($permission, $this->permissionSlugs(), true);
    }

    /**
     * Verifica si el usuario puede administrar recursos del tenant.
     */
    public function canManageTenant(): bool
    {
        return in_array($this->role, ['company_admin', 'hr_manager']);
    }

    public function roleRank(): int
    {
        return self::ROLE_RANKS[$this->role] ?? 0;
    }

    /**
     * Puede dar este rol a otra persona: el administrador hasta su mismo
     * rango, el resto solo rangos inferiores (RRHH no crea administradores).
     */
    public function canAssignRole(string $role): bool
    {
        $target = self::ROLE_RANKS[$role] ?? PHP_INT_MAX;

        if ($role === 'super_admin') {
            return $this->isSuperAdmin();
        }

        return $this->roleRank() >= self::ROLE_RANKS['company_admin']
            ? $target <= $this->roleRank()
            : $target < $this->roleRank();
    }

    /** Puede editar, desactivar o ver datos sensibles de este usuario */
    public function canManage(User $other): bool
    {
        return $this->id === $other->id || $this->canAssignRole($other->role);
    }

    /** Reglas de la nueva contraseña al restablecerla (API y panel web) */
    public static function resetPasswordRule(): PasswordRule
    {
        return PasswordRule::min(8)->letters()->numbers();
    }

    /**
     * Cierra el flujo de "olvidé mi contraseña": guarda la nueva, quita la
     * contraseña temporal y el bloqueo, y cierra la sesión en todos los dispositivos.
     */
    public function completePasswordReset(string $password): void
    {
        $this->forceFill([
            'password'              => $password,
            'remember_token'        => Str::random(60),
            'must_change_password'  => false,
            'failed_login_attempts' => 0,
            'locked_until'          => null,
        ])->save();

        $this->tokens()->delete();

        event(new PasswordReset($this));
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
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
    /** Ve la asistencia de toda la empresa (RRHH, admin) o solo la de su equipo (supervisor) */
    public function seesAllAttendance(): bool
    {
        return $this->hasPermission('attendance.view_all');
    }

    /**
     * Equipo de un supervisor: colaboradores asignados a las sucursales donde
     * él es supervisor (branches.supervisor_id). Misma regla que la API y Aprobaciones.
     */
    public function scopeSupervisedBy($query, User $supervisor)
    {
        return $query->where('users.id', '!=', $supervisor->id)
            ->whereHas('branches', fn ($q) => $q->where('branches.supervisor_id', $supervisor->id));
    }

    /** Usuarios cuya asistencia puede ver $viewer: todos o solo su equipo */
    public function scopeVisibleTo($query, User $viewer)
    {
        return $viewer->seesAllAttendance() ? $query : $query->supervisedBy($viewer);
    }

    /** Roles que marcan asistencia desde la app (colaboradores y supervisores) */
    public const CLOCKING_ROLES = ['employee', 'supervisor'];

    /**
     * Equipo de $viewer para la app: personal activo que marca (colaboradores y
     * supervisores) visible para él, sin incluirse a sí mismo. Lo usan
     * GET /api/supervisor/team y /api/supervisor/attendance/live.
     */
    public function scopeTeamOf($query, User $viewer, bool $activeOnly = true)
    {
        return $query->visibleTo($viewer)
            ->where('users.id', '!=', $viewer->id)
            ->whereIn('users.role', self::CLOCKING_ROLES)
            ->when($activeOnly, fn ($q) => $q->where('users.status', 'active'));
    }

    public function scopeActiveEmployees($query)
    {
        return $query->where('role', 'employee')
            ->where('status', 'active');
    }
}
