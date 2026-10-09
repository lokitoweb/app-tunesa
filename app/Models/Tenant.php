<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Modelo Tenant
 * Representa cada empresa contratante del SaaS.
 *
 * @property int    $id
 * @property string $slug
 * @property string $company_name
 * @property string $plan
 * @property string $status
 * @property string $timezone
 */
class Tenant extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'slug',
        'company_name',
        'ruc',
        'email',
        'phone',
        'logo_path',
        'address',
        'plan',
        'trial_ends_at',
        'subscription_ends_at',
        'status',
        'timezone',
        'currency',
    ];

    protected $casts = [
        'trial_ends_at'         => 'datetime',
        'subscription_ends_at'  => 'datetime',
    ];

    // =========================================================================
    // RELACIONES
    // =========================================================================

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
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
    // HELPERS
    // =========================================================================

    /**
     * Determina si el tenant tiene una suscripción activa.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Determina si el plan de prueba está vigente.
     */
    public function isOnTrial(): bool
    {
        return $this->plan === 'trial'
            && $this->trial_ends_at
            && $this->trial_ends_at->isFuture();
    }
}
