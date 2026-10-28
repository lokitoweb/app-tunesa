<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Modelo Role
 * Roles del sistema (tenant_id NULL) identificados por slug = users.role.
 *
 * @property int    $id
 * @property ?int   $tenant_id
 * @property string $slug
 */
class Role extends Model
{
    public const SUPER_ADMIN   = 'super_admin';
    public const COMPANY_ADMIN = 'company_admin';
    public const HR_MANAGER    = 'hr_manager';
    public const MANAGER       = 'manager';
    public const ACCOUNTANT    = 'accountant';
    public const SUPERVISOR    = 'supervisor';
    public const LOGISTICS     = 'logistics';
    public const EMPLOYEE      = 'employee';

    public const ALL = [
        self::SUPER_ADMIN,
        self::COMPANY_ADMIN,
        self::HR_MANAGER,
        self::MANAGER,
        self::ACCOUNTANT,
        self::SUPERVISOR,
        self::LOGISTICS,
        self::EMPLOYEE,
    ];

    protected $fillable = [
        'tenant_id',
        'slug',
        'base_role',
        'name',
        'description',
        'is_system',
    ];

    protected $casts = [
        'is_system' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    /** Personas con este rol propio de empresa (users.custom_role_id) */
    public function customUsers(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(User::class, 'custom_role_id');
    }

    /** Roles del sistema (compartidos por todas las empresas) */
    public function scopeSystem(Builder $query): Builder
    {
        return $query->whereNull('tenant_id');
    }
}
