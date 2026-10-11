<?php

namespace App\Modules\Core\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Marca blanca extendida + configuración de la app móvil, una fila por empresa.
 *
 * La fila con tenant_id NULL es el valor por defecto de la plataforma (lo
 * edita el Super Admin en la vista global). No usa BelongsToTenant: la fila
 * global no tiene empresa y el scope automático la ocultaría o le pondría el
 * tenant_id de la request; las consultas filtran tenant_id de forma explícita.
 *
 * Valores efectivos: TenantSetting::forTenant($tenant) devuelve una instancia
 * en memoria (no se guarda) con fila de la empresa > fila global > DEFAULTS.
 */
class TenantSetting extends Model
{
    /** Valores de fábrica si ni la empresa ni la plataforma definieron nada */
    public const DEFAULTS = [
        'trade_name'              => 'Tunesa',
        'primary_color'           => '#1E40AF',
        'secondary_color'         => '#F59E0B',
        'accent_color'            => '#10B981',
        'navbar_color'            => '#0F172A',
        'api_base_url'            => null,
        'min_supported_version'   => '1.0.0',
        'latest_version'          => null,
        'force_update'            => false,
        'selfie_required'         => true,
        'face_detection_required' => false,
        'offline_mode_enabled'    => false,
        'offline_max_hours'       => 24,
        'max_gps_accuracy_meters' => 100,
        'default_grace_minutes'   => 10,
        'lunch_minutes'           => 60,
        'lunch_paid'              => false,
        'default_geofence_radius' => 50,
        'mock_location_policy'    => 'reject',
        'max_time_drift_seconds'  => 300,
        'geocoding_provider'      => 'nominatim',
        'fcm_enabled'             => false,
    ];

    public const MOCK_LOCATION_POLICIES = ['reject', 'flag'];

    public const GEOCODING_PROVIDERS = ['nominatim', 'google'];

    /** Columnas de configuración editables (NULL = heredar) */
    public const SETTING_FIELDS = [
        'trade_name', 'contact_email', 'contact_phone', 'website', 'address',
        'accent_color', 'navbar_color',
        'api_base_url', 'min_supported_version', 'latest_version', 'force_update',
        'selfie_required', 'face_detection_required', 'offline_mode_enabled', 'offline_max_hours', 'max_gps_accuracy_meters',
        'default_grace_minutes', 'lunch_minutes', 'lunch_paid', 'default_geofence_radius',
        'webhook_url', 'notification_endpoint',
        'mock_location_policy', 'max_time_drift_seconds', 'geocoding_provider', 'fcm_enabled',
    ];

    /** Imágenes guardadas en esta tabla (el logo claro de una empresa vive en tenants.logo_path) */
    public const IMAGE_FIELDS = ['logo' => 'logo_path', 'logo_dark' => 'logo_dark_path', 'favicon' => 'favicon_path', 'splash' => 'splash_path'];

    /**
     * Secretos cifrados. maps_api_key es la clave de Google Maps (en formularios
     * y API se llama google_maps_api_key); fcm_service_account_json es la cuenta
     * de servicio de Firebase (solo fila global).
     */
    public const SECRET_FIELDS = ['webhook_secret', 'maps_api_key', 'fcm_service_account_json'];

    /** Solo valen en la fila global: una empresa usa sus columnas de `tenants` */
    public const PLATFORM_ONLY = ['logo_path', 'primary_color', 'secondary_color', 'fcm_service_account_json'];

    /** Propios de cada empresa: no se heredan de la plataforma */
    public const NOT_INHERITED = ['trade_name', 'webhook_url', 'webhook_secret', 'notification_endpoint'];

    private const META_COLUMNS = ['id', 'tenant_id', 'updated_by', 'created_at', 'updated_at'];

    protected $table = 'tenant_settings';

    protected $fillable = [
        'tenant_id', 'updated_by',
        'trade_name', 'logo_path', 'logo_dark_path', 'favicon_path', 'splash_path',
        'contact_email', 'contact_phone', 'website', 'address',
        'primary_color', 'secondary_color', 'accent_color', 'navbar_color',
        'api_base_url', 'min_supported_version', 'latest_version', 'force_update',
        'selfie_required', 'face_detection_required', 'offline_mode_enabled', 'offline_max_hours', 'max_gps_accuracy_meters',
        'default_grace_minutes', 'lunch_minutes', 'lunch_paid', 'default_geofence_radius',
        'webhook_url', 'webhook_secret', 'maps_api_key', 'notification_endpoint',
        'mock_location_policy', 'max_time_drift_seconds', 'geocoding_provider', 'fcm_service_account_json', 'fcm_enabled',
    ];

    protected $hidden = ['webhook_secret', 'maps_api_key', 'fcm_service_account_json'];

    protected $casts = [
        'force_update'            => 'boolean',
        'selfie_required'         => 'boolean',
        'face_detection_required' => 'boolean',
        'offline_mode_enabled'    => 'boolean',
        'lunch_paid'              => 'boolean',
        'offline_max_hours'       => 'integer',
        'max_gps_accuracy_meters' => 'integer',
        'default_grace_minutes'   => 'integer',
        'lunch_minutes'           => 'integer',
        'default_geofence_radius' => 'integer',
        'webhook_secret'          => 'encrypted',
        'maps_api_key'            => 'encrypted',
        'fcm_service_account_json' => 'encrypted',
        'fcm_enabled'             => 'boolean',
        'max_time_drift_seconds'  => 'integer',
    ];

    /** Instancia resuelta por forTenant(): solo lectura */
    public bool $isEffective = false;

    public ?Tenant $resolvedTenant = null;

    public ?TenantSetting $tenantRow = null;

    public ?TenantSetting $globalRow = null;

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    // =========================================================================
    // FILAS (consultas explícitas por tenant_id)
    // =========================================================================

    public function scopeOfTenant(Builder $query, ?int $tenantId): Builder
    {
        return $tenantId === null ? $query->whereNull('tenant_id') : $query->where('tenant_id', $tenantId);
    }

    /** Fila guardada de la empresa (null = la plataforma), si existe */
    public static function rowFor(?Tenant $tenant): ?self
    {
        // orderBy: si alguna vez quedaran dos filas globales, siempre se lee la misma
        return static::query()->ofTenant($tenant?->id)->orderBy('id')->first();
    }

    /**
     * Fila global de la plataforma (tenant_id NULL), creándola si no existe.
     *
     * El índice único de tenant_id no impide varias filas NULL, así que la
     * creación va en una transacción con bloqueo: es el único camino de
     * código que crea la fila global.
     */
    public static function platform(): self
    {
        return DB::transaction(function () {
            $row = static::query()->whereNull('tenant_id')->orderBy('id')->lockForUpdate()->first();

            if (! $row) {
                $row = new static();
                $row->tenant_id = null;
                $row->save();
            }

            return $row;
        });
    }

    /** Prefijo de los secretos de webhook generados por la plataforma */
    public const WEBHOOK_SECRET_PREFIX = 'whsec_';

    /** Secreto nuevo y aleatorio para firmar los webhooks (HMAC-SHA256) */
    public static function generateWebhookSecret(): string
    {
        return self::WEBHOOK_SECRET_PREFIX . Str::random(32);
    }

    /**
     * Secreto del webhook en claro; null si no hay o no se puede descifrar
     * (APP_KEY cambiada: se trata como vacío y se genera otro).
     */
    public function readableWebhookSecret(): ?string
    {
        try {
            return filled($this->webhook_secret) ? $this->webhook_secret : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Fila de la empresa (o de la plataforma) con secreto de webhook garantizado:
     * si está vacío se genera y se guarda. Las integraciones nunca quedan sin firma.
     */
    public static function ensureWebhookSecret(?Tenant $tenant): self
    {
        return DB::transaction(function () use ($tenant) {
            $row = static::editableRowFor($tenant);

            if ($row->readableWebhookSecret() === null) {
                $row->webhook_secret = static::generateWebhookSecret();
                $row->save();
            }

            return $row;
        });
    }

    /** Cambia el secreto por uno nuevo y lo devuelve */
    public static function regenerateWebhookSecret(?Tenant $tenant): string
    {
        return DB::transaction(function () use ($tenant) {
            $row = static::editableRowFor($tenant);
            $row->webhook_secret = $secret = static::generateWebhookSecret();
            $row->save();

            return $secret;
        });
    }

    /** Fila a editar: la existente o una nueva con el tenant_id correcto */
    public static function editableRowFor(?Tenant $tenant): self
    {
        if (! $tenant) {
            return static::platform();
        }

        $row = static::rowFor($tenant) ?? new static();
        $row->tenant_id = $tenant->id;

        return $row;
    }

    /**
     * Configuración efectiva: fila de la empresa sobre la fila global sobre
     * los valores de fábrica. Sin empresa = valores de la plataforma.
     */
    public static function forTenant(?Tenant $tenant): self
    {
        $global    = static::rowFor(null);
        $tenantRow = $tenant ? static::rowFor($tenant) : null;

        $values = self::DEFAULTS;
        foreach (self::overrides($global) as $key => $value) {
            $values[$key] = $value;
        }

        if ($tenant) {
            foreach (self::NOT_INHERITED as $key) {
                $values[$key] = null;
            }
            foreach (self::overrides($tenantRow) as $key => $value) {
                if (! in_array($key, self::PLATFORM_ONLY, true)) {
                    $values[$key] = $value;
                }
            }
        }

        $effective = new static();
        $effective->setRawAttributes(['tenant_id' => $tenant?->id] + $values, true);
        $effective->exists         = false;
        $effective->isEffective    = true;
        $effective->resolvedTenant = $tenant;
        $effective->tenantRow      = $tenantRow;
        $effective->globalRow      = $global;

        return $effective;
    }

    /** Columnas no nulas de una fila (valores crudos: los secretos siguen cifrados) */
    private static function overrides(?self $row): array
    {
        if (! $row) {
            return [];
        }

        return array_filter(
            array_diff_key($row->getAttributes(), array_flip(self::META_COLUMNS)),
            fn ($v) => $v !== null && $v !== '',
        );
    }

    public function save(array $options = []): bool
    {
        if ($this->isEffective) {
            throw new LogicException('La configuración efectiva es de solo lectura; edita la fila con editableRowFor().');
        }

        return parent::save($options);
    }

    // =========================================================================
    // VALORES EFECTIVOS
    // =========================================================================

    public function tradeName(): string
    {
        return $this->trade_name ?: ($this->resolvedTenant?->company_name ?: self::DEFAULTS['trade_name']);
    }

    public function primaryColor(): string
    {
        return self::hexOr($this->resolvedTenant?->primary_color, self::hexOr($this->primary_color, self::DEFAULTS['primary_color']));
    }

    public function secondaryColor(): string
    {
        return self::hexOr($this->resolvedTenant?->secondary_color, self::hexOr($this->secondary_color, self::DEFAULTS['secondary_color']));
    }

    public function accentColor(): string
    {
        return self::hexOr($this->accent_color, self::DEFAULTS['accent_color']);
    }

    public function navbarColor(): string
    {
        return self::hexOr($this->navbar_color, self::DEFAULTS['navbar_color']);
    }

    /** true si la empresa o la plataforma eligió un color de barra (el panel solo lo aplica entonces) */
    public function hasCustomNavbar(): bool
    {
        return self::isHex($this->navbar_color);
    }

    /** Logo claro: el de la empresa (tenants.logo_path) o el de la plataforma */
    public function logoPath(): ?string
    {
        return $this->resolvedTenant?->logo_path ?: $this->logo_path;
    }

    /**
     * Logo para fondo oscuro. Si la empresa tiene logo propio pero no versión
     * oscura se usa su logo claro (nunca el de la plataforma junto a su marca).
     */
    public function logoDarkPath(): ?string
    {
        if ($this->resolvedTenant?->logo_path && ! $this->tenantRow?->logo_dark_path) {
            return $this->resolvedTenant->logo_path;
        }

        return $this->logo_dark_path ?: $this->logoPath();
    }

    public function logoUrl(): ?string
    {
        return self::publicUrl($this->logoPath());
    }

    /** Logo incluido con la plataforma: se usa mientras nadie suba el suyo en "Marca y app" */
    public const DEFAULT_LOGO = 'img/tunesa-logo.webp';

    /** Logo a pintar en el panel: el subido (empresa o plataforma) o el de la plataforma por defecto */
    public function displayLogoUrl(): string
    {
        return $this->logoUrl() ?? asset(self::DEFAULT_LOGO);
    }

    /** ¿Se está mostrando el logo por defecto? (en tema oscuro va sobre una pastilla clara) */
    public function usesDefaultLogo(): bool
    {
        return $this->logoUrl() === null;
    }

    public function logoDarkUrl(): ?string
    {
        return self::publicUrl($this->logoDarkPath());
    }

    public function faviconUrl(): ?string
    {
        return self::publicUrl($this->favicon_path);
    }

    public function splashUrl(): ?string
    {
        return self::publicUrl($this->splash_path);
    }

    /**
     * Precisión GPS máxima: la de la empresa o la plataforma si la definieron;
     * si no, ATTENDANCE_MAX_GPS_ACCURACY (config/attendance.php).
     */
    public function maxGpsAccuracy(): int
    {
        $explicit = ($this->resolvedTenant ? $this->tenantRow?->max_gps_accuracy_meters : null)
            ?? $this->globalRow?->max_gps_accuracy_meters;

        return (int) ($explicit ?? config('attendance.max_gps_accuracy_meters', self::DEFAULTS['max_gps_accuracy_meters']));
    }

    /**
     * GPS simulado: "reject" (422 MOCK_LOCATION_DETECTED) o "flag" (se acepta
     * como sospechosa con motivo MOCK_LOCATION). Empresa → plataforma →
     * ATTENDANCE_REJECT_MOCK_LOCATION de .env (true = reject, false = flag).
     */
    public function mockLocationPolicy(): string
    {
        $explicit = ($this->resolvedTenant ? $this->tenantRow?->mock_location_policy : null)
            ?? $this->globalRow?->mock_location_policy;

        if (in_array($explicit, self::MOCK_LOCATION_POLICIES, true)) {
            return $explicit;
        }

        return config('attendance.reject_mock_location', true) ? 'reject' : 'flag';
    }

    /** Diferencia máxima (s) entre el reloj del teléfono y el del servidor antes de marcar TIME_DRIFT */
    public function maxTimeDriftSeconds(): int
    {
        return max(0, (int) ($this->max_time_drift_seconds ?? self::DEFAULTS['max_time_drift_seconds']));
    }

    /** Clave de Google Maps (columna maps_api_key, cifrada). Se hereda de la plataforma. */
    public function googleMapsApiKey(): ?string
    {
        return filled($this->maps_api_key) ? (string) $this->maps_api_key : null;
    }

    /** Proveedor elegido en Marca y app (nominatim | google) */
    public function configuredGeocodingProvider(): string
    {
        return in_array($this->geocoding_provider, self::GEOCODING_PROVIDERS, true)
            ? $this->geocoding_provider
            : self::DEFAULTS['geocoding_provider'];
    }

    /** Proveedor que realmente se usa: Google solo si está elegido y hay clave; si no, Nominatim */
    public function geocodingProvider(): string
    {
        return $this->configuredGeocodingProvider() === 'google' && $this->googleMapsApiKey() ? 'google' : 'nominatim';
    }

    /**
     * Cuenta de servicio de Firebase (fila global). null si no hay o si el JSON
     * no trae lo necesario para FCM HTTP v1.
     *
     * @return array{project_id: string, client_email: string, private_key: string, token_uri: string}|null
     */
    public function fcmServiceAccount(): ?array
    {
        return self::parseServiceAccount($this->globalRow?->fcm_service_account_json);
    }

    /** Valida y normaliza el JSON de una cuenta de servicio de Google */
    public static function parseServiceAccount(?string $json): ?array
    {
        if (blank($json)) {
            return null;
        }

        $data = json_decode($json, true);
        if (! is_array($data) || blank($data['project_id'] ?? null) || blank($data['client_email'] ?? null)
            || ! is_string($data['private_key'] ?? null) || ! str_contains($data['private_key'], 'PRIVATE KEY')) {
            return null;
        }

        return [
            'project_id'   => (string) $data['project_id'],
            'client_email' => (string) $data['client_email'],
            'private_key'  => $data['private_key'],
            'token_uri'    => (string) ($data['token_uri'] ?? 'https://oauth2.googleapis.com/token'),
        ];
    }

    /** Push activo: fcm_enabled efectivo (empresa → plataforma) y credenciales válidas en la plataforma */
    public function fcmEnabled(): bool
    {
        return (bool) $this->fcm_enabled && $this->fcmServiceAccount() !== null;
    }

    /** "Rechazar" = modo estricto; se guarda en company_configurations.geofence_policy */
    public function geofenceStrictMode(): bool
    {
        if (! $this->resolvedTenant) {
            return true;
        }

        return ! CompanyConfiguration::forTenantId($this->resolvedTenant->id)->flagsOutOfGeofence();
    }

    public function contact(): array
    {
        // El contacto de una empresa es suyo (fila propia o datos de `tenants`),
        // nunca el soporte de la plataforma.
        $tenant = $this->resolvedTenant;
        $row    = $tenant ? $this->tenantRow : $this->globalRow;

        return [
            'email'   => ($row?->contact_email ?: $tenant?->email) ?: null,
            'phone'   => ($row?->contact_phone ?: $tenant?->phone) ?: null,
            'website' => $row?->website ?: null,
            'address' => ($row?->address ?: $tenant?->address) ?: null,
        ];
    }

    public function branding(): array
    {
        return [
            'logo_url'        => $this->logoUrl(),
            'logo_dark_url'   => $this->logoDarkUrl(),
            'favicon_url'     => $this->faviconUrl(),
            'splash_url'      => $this->splashUrl(),
            'primary_color'   => $this->primaryColor(),
            'secondary_color' => $this->secondaryColor(),
            'accent_color'    => $this->accentColor(),
            // null si nadie eligió color de barra: la app usa su barra clara (igual que el panel)
            'navbar_color'    => $this->hasCustomNavbar() ? $this->navbarColor() : null,
        ];
    }

    public function mobile(): array
    {
        return [
            'api_base_url'            => $this->api_base_url ?: null,
            'min_supported_version'   => $this->min_supported_version ?: self::DEFAULTS['min_supported_version'],
            'latest_version'          => $this->latest_version ?: null,
            'force_update'            => (bool) $this->force_update,
            'selfie_required'         => (bool) $this->selfie_required,
            'face_detection_required' => (bool) $this->face_detection_required,
            'offline_mode_enabled'    => (bool) $this->offline_mode_enabled,
            'offline_max_hours'       => (int) $this->offline_max_hours,
            'max_gps_accuracy_meters' => $this->maxGpsAccuracy(),
            'geofence_strict_mode'    => $this->geofenceStrictMode(),
            'mock_location_policy'    => $this->mockLocationPolicy(),
            'max_time_drift_seconds'  => $this->maxTimeDriftSeconds(),
            'geocoding_provider'      => $this->geocodingProvider(),
            'fcm_enabled'             => $this->fcmEnabled(),
        ];
    }

    public function rules(): array
    {
        return [
            'grace_minutes'           => (int) $this->default_grace_minutes,
            'lunch_minutes'           => (int) $this->lunch_minutes,
            'lunch_paid'              => (bool) $this->lunch_paid,
            'default_geofence_radius' => (int) $this->default_geofence_radius,
        ];
    }

    /** Fecha del último cambio que afecta a la configuración (para que la app sepa si recargar) */
    public function configVersion(): string
    {
        $stamps = array_filter([
            $this->resolvedTenant?->updated_at,
            $this->tenantRow?->updated_at,
            $this->globalRow?->updated_at,
            $this->resolvedTenant ? CompanyConfiguration::forTenantId($this->resolvedTenant->id)->updated_at : null,
        ]);

        $latest = collect($stamps)->sortByDesc(fn (CarbonInterface $d) => $d->getTimestamp())->first();

        return ($latest ?? Carbon::createFromTimestampUTC(0))->copy()->utc()->toIso8601String();
    }

    /** Respuesta de GET /api/v1/app/config (sin secretos) */
    public function toAppConfig(): array
    {
        $tenant  = $this->resolvedTenant;
        $modules = ($tenant ? CompanyConfiguration::forTenantId($tenant->id) : new CompanyConfiguration())->toApi()['modules'];

        return [
            'tenant'   => $tenant ? [
                'slug'         => $tenant->slug,
                'company_name' => $tenant->company_name,
                'trade_name'   => $this->tradeName(),
            ] : null,
            'branding' => $this->branding(),
            'mobile'   => $this->mobile(),
            'rules'    => $this->rules(),
            'contact'  => $this->contact(),
            'modules'  => $modules,
        ];
    }

    // =========================================================================
    // VERSIONES DE LA APP
    // =========================================================================

    /** "v1.2.3+45" → "1.2.3" (sin prefijo ni número de build) */
    public static function normalizeVersion(string $version): string
    {
        return ltrim(explode('+', trim($version), 2)[0], 'vV');
    }

    /** true si la versión instalada es menor que la mínima soportada */
    public function isBelowMinimum(string $appVersion): bool
    {
        $min = $this->min_supported_version ?: self::DEFAULTS['min_supported_version'];

        return version_compare(self::normalizeVersion($appVersion), self::normalizeVersion($min), '<');
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    public static function isHex(?string $value): bool
    {
        return is_string($value) && preg_match('/^#[0-9A-Fa-f]{6}$/', $value) === 1;
    }

    public static function hexOr(?string $value, string $fallback): string
    {
        return self::isHex($value) ? strtoupper($value) : strtoupper($fallback);
    }

    /** Texto legible sobre un fondo: blanco u oscuro según la luminancia relativa (WCAG) */
    public static function contrastText(string $hex): string
    {
        [$r, $g, $b] = array_map(function ($c) {
            $c /= 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, sscanf(self::hexOr($hex, '#000000'), '#%02x%02x%02x'));

        $luminance = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;

        // Contraste con blanco vs con #0F172A (luminancia ≈ 0.0089)
        return (1.05 / ($luminance + 0.05)) >= (($luminance + 0.05) / 0.0589) ? '#FFFFFF' : '#0F172A';
    }

    /** "#1E40AF" → "30, 64, 175" para rgba(var(--x-rgb), a) */
    public static function rgbList(string $hex): string
    {
        return implode(', ', sscanf(self::hexOr($hex, '#000000'), '#%02x%02x%02x'));
    }

    public static function publicUrl(?string $path): ?string
    {
        return $path ? asset('storage/' . ltrim($path, '/')) : null;
    }
}
