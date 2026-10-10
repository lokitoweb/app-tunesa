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
        'custom_domain',
        'company_name',
        'ruc',
        'email',
        'phone',
        'logo_path',
        'primary_color',
        'secondary_color',
        'address',
        'plan',
        'trial_ends_at',
        'subscription_ends_at',
        'status',
        'timezone',
        'currency',
        'settings',
    ];

    protected $casts = [
        'trial_ends_at'         => 'datetime',
        'subscription_ends_at'  => 'datetime',
        'settings'              => 'array',
    ];

    /**
     * ResolveTenant cachea el tenant 5 minutos por slug y dominio:
     * al cambiar estado, marca o dominio se invalida para que aplique ya.
     */
    protected static function booted(): void
    {
        $forget = function (Tenant $tenant) {
            foreach (array_filter([
                $tenant->slug, $tenant->getOriginal('slug'),
            ]) as $slug) {
                cache()->forget("tenant:slug:{$slug}");
            }
            foreach (array_filter([
                $tenant->custom_domain, $tenant->getOriginal('custom_domain'),
            ]) as $domain) {
                cache()->forget("tenant:domain:{$domain}");
            }
        };

        static::saved($forget);
        static::deleted($forget);

        // Toda empresa nace con su secreto de webhook: las integraciones siempre firman
        static::created(fn (Tenant $tenant) => TenantSetting::ensureWebhookSecret($tenant));
    }

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

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
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
     * Datos de marca blanca para el panel y la app móvil.
     */
    public function branding(): array
    {
        return [
            'company_name'    => $this->company_name,
            'slug'            => $this->slug,
            'logo_url'        => $this->logo_path ? asset('storage/' . $this->logo_path) : null,
            'primary_color'   => $this->primary_color,
            'secondary_color' => $this->secondary_color,
            'custom_domain'   => $this->custom_domain,
            'timezone'        => $this->timezone,
            'currency'        => $this->currency,
        ];
    }

    /**
     * Lee una preferencia de la empresa (settings JSON).
     */
    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }

    /**
     * Empresa del subdominio (empresa.tunesa.app) o dominio propio del host,
     * si aplica (marca blanca del login y de la recuperación de contraseña).
     */
    public static function fromHost(string $host): ?self
    {
        $appDomain = config('app.domain', 'tunesa.app');

        if (str_ends_with($host, ".{$appDomain}")) {
            $slug = substr($host, 0, -strlen(".{$appDomain}"));

            return in_array($slug, ['www', 'api', 'admin', 'app', 'mail', 'cdn'], true)
                ? null
                : self::where('slug', $slug)->first();
        }

        if ($host === $appDomain || in_array($host, ['localhost', '127.0.0.1'], true)) {
            return null;
        }

        return self::where('custom_domain', strtolower($host))->first();
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
