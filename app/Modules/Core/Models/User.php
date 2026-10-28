<?php

namespace App\Modules\Core\Models;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\HR\Models\EmployeeDocument;
use App\Modules\HR\Models\EmployeeProfile;
use App\Modules\HR\Models\EmployeeShift;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\HR\Models\UniformDelivery;
use App\Modules\HR\Models\UniformSize;
use App\Modules\Core\Models\Concerns\BelongsToBranchScope;
use App\Modules\Core\Models\Concerns\BelongsToTenant;
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
    use BelongsToTenant, HasApiTokens, HasFactory, Notifiable, SoftDeletes, BelongsToBranchScope;

    /** Alcance por sede: [columna de sede, columna de colaborador] (BelongsToBranchScope) */
    public const BRANCH_SCOPE = [null, 'id'];

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
        'bonus',
        'position',
        'department',
        'fcm_token',
        'fcm_token_updated_at',
        'notification_preferences',
        'must_change_password',
        'temp_password_expires_at',
        'department_id',
        'position_id',
        'scope_type',
        'scope_zone_id',
        'scope_branch_id',
        'roaming_attendance_enabled',
    ];

    /** Alcance territorial (users.scope_type). Ver App\Modules\Core\Services\OrgScope */
    public const SCOPE_GLOBAL = 'global';
    public const SCOPE_ZONE   = 'zone';
    public const SCOPE_BRANCH = 'branch';
    public const SCOPES = [
        self::SCOPE_GLOBAL => 'Toda la empresa',
        self::SCOPE_ZONE   => 'Una zona',
        self::SCOPE_BRANCH => 'Una sede',
    ];

    /**
     * Jerarquía de rangos: nadie puede crear, editar ni asignar un rol de
     * rango superior al propio (ver canAssignRole / canManage).
     */
    public const ROLE_RANKS = [
        'super_admin'   => 100,
        'company_admin' => 80,
        'hr_manager'    => 60,
        'manager'       => 60, // Gerencia: mismo nivel que RRHH (uno no crea al otro)
        'accountant'    => 50,
        'logistics'     => 50,
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
        'bonus'             => 'decimal:2',
        'password'          => 'hashed',
        'locked_until'         => 'datetime',
        'last_login_at'        => 'datetime',
        'fcm_token_updated_at' => 'datetime',
        'must_change_password' => 'boolean',
        'temp_password_expires_at' => 'datetime',
        'roaming_attendance_enabled' => 'boolean',
        'failed_login_attempts' => 'integer',
        'notification_preferences' => 'array',
    ];

    /**
     * Al desactivar o suspender una cuenta se revocan todas sus sesiones
     * (tokens de la app), para que el acceso se corte en el acto.
     */
    protected static function booted(): void
    {
        // Cargo del catálogo: users.position guarda la copia del nombre (reportes, app)
        // y el área se toma del cargo si el colaborador no tiene una.
        static::saving(function (User $user) {
            if (! $user->isDirty('position_id')) {
                return;
            }
            $position = $user->position_id ? Position::withoutGlobalScopes()->find($user->position_id) : null;
            if ($position) {
                $user->position = $position->name;
                $user->department_id ??= $position->department_id;
            } elseif (! $user->isDirty('position')) {
                $user->position = null;
            }
        });

        // Mes y día del cumpleaños (MMDD) para buscar por fecha sin funciones
        // de fecha propias de cada motor de base de datos (índice tenant_id, birth_md)
        static::saving(function (User $user) {
            if ($user->isDirty('birth_date')) {
                $user->birth_md = $user->birth_date ? (int) $user->birth_date->format('nd') : null;
            }
        });

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
            ->using(EmployeeBranch::class)
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

    /**
     * Régimen de pago: en planilla o por recibo por honorarios (4.ª categoría). Honorarios = contrato
     * "contractor" (locación de servicios). Las relaciones de personal para inspección salen solo
     * con planilla, salvo que se pida otra cosa.
     */
    public const PAYROLL_REGIMES = ['planilla' => 'En planilla', 'honorarios' => 'Recibo por honorarios'];

    public function isFeeEarner(): bool
    {
        return $this->contract_type === 'contractor';
    }

    /** planilla | honorarios | null (todos) */
    public function scopeRegime($query, ?string $regime)
    {
        return match ($regime) {
            'planilla'   => $query->where(fn ($q) => $q->where('contract_type', '!=', 'contractor')->orWhereNull('contract_type')),
            'honorarios' => $query->where('contract_type', 'contractor'),
            default      => $query,
        };
    }

    public function isEmployee(): bool
    {
        return $this->role === 'employee';
    }

    /**
     * Cierra sus sesiones del panel (sesiones en base de datos), salvo $exceptId: al cambiar la
     * contraseña o restablecer el acceso, una cookie robada deja de servir. Con otro driver de
     * sesión no hay tabla que limpiar y no hace nada.
     */
    public function endWebSessions(?string $exceptId = null): int
    {
        if (config('session.driver') !== 'database') {
            return 0;
        }

        return \Illuminate\Support\Facades\DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', $this->getKey())
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->delete();
    }

    /**
     * Slugs de permisos del rol del usuario (memoizados por request).
     *
     * @return array<int, string>
     */
    public function permissionSlugs(): array
    {
        if (! array_key_exists('permission_slugs', $this->permissionCache)) {
            $slugs = Permission::query()
                ->whereHas('roles', fn ($q) => $q->whereNull('tenant_id')->where('slug', $this->role))
                ->pluck('slug')
                ->all();

            // Lo que la empresa cambió en Configuración › Roles y permisos: estándar + concedidos − quitados
            if ($this->tenant_id && self::overridesTableReady()) {
                $overrides = RolePermissionOverride::withoutGlobalScopes()->where('tenant_id', $this->tenant_id)->where('role', $this->role)
                    ->join('permissions', 'permissions.id', '=', 'role_permission_overrides.permission_id')
                    ->pluck('role_permission_overrides.granted', 'permissions.slug');
                $slugs = array_values(array_unique(array_merge(
                    array_diff($slugs, $overrides->filter(fn ($granted) => ! $granted)->keys()->all()),
                    $overrides->filter()->keys()->all(),
                )));
            }

            $this->permissionCache['permission_slugs'] = $slugs;
        }

        return $this->permissionCache['permission_slugs'];
    }

    /** En un despliegue el código nuevo responde antes de migrar: sin la tabla, rige el estándar */
    private static ?bool $overridesReady = null;

    private static function overridesTableReady(): bool
    {
        return self::$overridesReady ??= \Illuminate\Support\Facades\Schema::hasTable('role_permission_overrides');
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
            'temp_password_expires_at' => null,
            'failed_login_attempts' => 0,
            'locked_until'          => null,
        ])->save();

        $this->tokens()->delete();

        event(new PasswordReset($this));
    }

    /** La contraseña temporal venció sin cambiarse: solo RRHH puede restablecer el acceso */
    public function temporaryPasswordExpired(): bool
    {
        return $this->must_change_password
            && $this->temp_password_expires_at !== null
            && $this->temp_password_expires_at->isPast();
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    /**
     * Nombre completo del usuario.
     */
    /**
     * Guarda el token de push de este celular. Un teléfono tiene un solo token:
     * si antes lo usaba otra cuenta (otro colaborador inició sesión en el mismo
     * equipo), se le quita para que sus avisos no lleguen a quien no son.
     */
    public function registerFcmToken(string $token): void
    {
        static::withoutGlobalScopes()
            ->where('fcm_token', $token)
            ->whereKeyNot($this->getKey())
            ->update(['fcm_token' => null, 'fcm_token_updated_at' => null]);

        $this->forceFill(['fcm_token' => $token, 'fcm_token_updated_at' => now()])->save();
    }

    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    /** Filtra empleados activos del tenant actual */
    /** Limitado a una zona o a una sede (supervisor zonal, administrador de agencia) */
    public function isOrgScoped(): bool
    {
        return in_array($this->scope_type, [self::SCOPE_ZONE, self::SCOPE_BRANCH], true);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function jobPosition(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'position_id');
    }

    public function assignedZone(): BelongsTo
    {
        return $this->belongsTo(Zone::class, 'scope_zone_id');
    }

    public function assignedBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'scope_branch_id');
    }

    /** "Zona Norte", "Sede Laredo" o "Toda la empresa" */
    public function scopeLabel(): string
    {
        return match ($this->scope_type) {
            self::SCOPE_ZONE   => 'Zona: ' . ($this->assignedZone?->name ?? 'sin asignar'),
            self::SCOPE_BRANCH => 'Sede: ' . (Branch::withoutGlobalScopes()->find($this->scope_branch_id)?->name ?? 'sin asignar'),
            default            => self::SCOPES[self::SCOPE_GLOBAL],
        };
    }

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
        // Con alcance de zona o sede, BranchScope ya dejó solo a los colaboradores de su territorio
        return $viewer->seesAllAttendance() || $viewer->isOrgScoped() ? $query : $query->supervisedBy($viewer);
    }

    /**
     * Roles que marcan asistencia desde la app con GPS: colaboradores, supervisores y
     * Contabilidad (política de la empresa). Fuente única para turnos, calendario,
     * faltas, reportes de horas y públicos de comunicados/capacitaciones.
     */
    public const CLOCKING_ROLES = ['employee', 'supervisor', 'accountant'];

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
