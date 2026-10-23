<?php

namespace App\Modules\Core\Models;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\HR\Models\Shift;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

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
        'code',
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

        // Abreviatura de los correlativos (RQ-TN-…): se propone sola, y única, al crear la empresa
        static::creating(fn (Tenant $t) => $t->code ??= static::uniqueCode((string) $t->company_name));

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

    /** Palabras que no aportan iniciales ("Transportes de la Costa S.A.C." -> TC) */
    private const CODE_STOPWORDS = ['DE', 'DEL', 'LA', 'LAS', 'LOS', 'EL', 'Y', 'E', 'EN', 'S', 'A', 'C', 'R', 'L', 'SA', 'SAC', 'SAA', 'SRL', 'EIRL'];

    /**
     * Abreviatura única de 2 caracteres a partir del nombre: iniciales ("Turismo Negreiros" -> TN);
     * si ya existe, otras letras del nombre (TR, TU…), luego inicial + dígito y, al final, cualquier par libre.
     */
    public static function uniqueCode(string $name, ?int $ignoreId = null): string
    {
        $taken = array_flip(static::withTrashed()->whereNotNull('code')->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->pluck('code')->all());
        $words = array_values(array_diff(preg_split('/[^A-Z0-9]+/', strtoupper(Str::ascii($name)), -1, PREG_SPLIT_NO_EMPTY), self::CODE_STOPWORDS));
        $first = $words[0] ?? 'X';

        $candidates = [];
        foreach (array_slice($words, 1) as $w) {
            $candidates[] = $first[0] . $w[0];
        }
        foreach ($words as $w) {
            for ($i = 1; $i < strlen($w); $i++) {
                $candidates[] = $w[0] . $w[$i];
            }
        }
        foreach (str_split('123456789') as $d) {
            $candidates[] = $first[0] . $d;
        }
        $alphabet = str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789');
        foreach ($alphabet as $a) {
            foreach ($alphabet as $b) {
                $candidates[] = $a . $b;
            }
        }

        foreach ($candidates as $code) {
            if (! isset($taken[$code])) {
                return $code;
            }
        }

        throw new \RuntimeException("No quedan abreviaturas libres para {$name}.");
    }
}
