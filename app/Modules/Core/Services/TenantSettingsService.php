<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\CompanyConfiguration;
use App\Modules\Core\Models\Tenant;
use App\Modules\Core\Models\TenantSetting;
use App\Modules\Core\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Validación y guardado de "Marca y app" (panel web y PUT /api/admin/app-config).
 *
 * La empresa a editar SIEMPRE la decide quien llama (TenantContext, o null =
 * fila global de la plataforma para el Super Admin): nunca sale del input.
 */
class TenantSettingsService
{
    private const HEX     = 'regex:/^#[0-9A-Fa-f]{6}$/';
    private const SEMVER  = 'regex:/^v?\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/';
    public const BOOLEANS = ['force_update', 'selfie_required', 'face_detection_required', 'offline_mode_enabled', 'lunch_paid', 'fcm_enabled'];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Valor de un selector Heredar/Sí/No: null o "" = heredar (NULL en la fila).
     * Lo que no sea un booleano reconocible se devuelve tal cual para que la validación lo rechace.
     */
    public static function triState(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $value;
    }

    /** Columnas de tenant_settings (los campos nuevos solo se usan si existen) */
    public static function columns(): array
    {
        static $columns = null;

        return $columns ??= Schema::getColumnListing('tenant_settings');
    }

    /**
     * Guardado desde un formulario del panel ("Marca y app", "Integraciones").
     *
     * $booleans = opciones Sí/No que ESE formulario contiene. En la plataforma
     * son interruptores (uno desmarcado no se envía: ausente = false); en una
     * empresa, selectores Heredar ("" → NULL) / Sí / No (ausente = no cambiar).
     */
    public function updateFromPanel(?Tenant $tenant, Request $request, ?User $actor, ?array $booleans = null): TenantSetting
    {
        $booleans ??= self::BOOLEANS;
        if (! in_array('fcm_enabled', self::columns(), true)) {
            $booleans = array_diff($booleans, ['fcm_enabled']);
        }

        $request->merge($tenant === null
            ? collect($booleans)->mapWithKeys(fn ($f) => [$f => $request->boolean($f)])->all()
            : collect($booleans)->filter(fn ($f) => $request->exists($f))
                ->mapWithKeys(fn ($f) => [$f => self::triState($request->input($f))])->all());

        $fcmJson   = $this->fcmServiceAccountInput($request, $tenant);
        $validated = $request->validate($this->rules(global: $tenant === null), $this->messages());

        $row = $this->update($tenant, $validated, $request, $actor);
        $this->saveFcmServiceAccount($request, $tenant, $fcmJson, $actor);

        return $row;
    }

    /**
     * Cuenta de servicio de Firebase (solo la fila de la plataforma): pegada en
     * el textarea o subida como archivo .json. Valida que sea un JSON con
     * project_id, client_email y private_key. Devuelve el JSON normalizado,
     * o null si no se envió nada.
     */
    private function fcmServiceAccountInput(Request $request, ?Tenant $tenant): ?string
    {
        if ($tenant !== null || ! in_array('fcm_service_account_json', self::columns(), true)) {
            // Una empresa nunca la cambia: se ignora lo que llegue
            $request->request->remove('fcm_service_account_json');
            $request->files->remove('fcm_service_account_file');

            return null;
        }

        $file = $request->file('fcm_service_account_file');
        $raw  = $file instanceof UploadedFile && $file->isValid() && $file->getSize() <= 64 * 1024
            ? (string) file_get_contents($file->getRealPath())
            : trim((string) $request->input('fcm_service_account_json'));

        $request->request->remove('fcm_service_account_json');

        if ($file instanceof UploadedFile && $raw === '') {
            throw ValidationException::withMessages(['fcm_service_account_json' => 'No se pudo leer el archivo (máximo 64 KB).']);
        }
        if ($raw === '') {
            return null;
        }

        $data    = json_decode($raw, true);
        $missing = is_array($data)
            ? array_values(array_filter(['project_id', 'client_email', 'private_key'], fn ($k) => blank($data[$k] ?? null)))
            : null;

        if ($missing === null || $missing) {
            throw ValidationException::withMessages(['fcm_service_account_json' => $missing === null
                ? 'La cuenta de servicio debe ser el archivo JSON que descargas de Firebase (Configuración del proyecto › Cuentas de servicio).'
                : 'Al JSON de la cuenta de servicio le falta: ' . implode(', ', $missing) . '.']);
        }
        if (! str_contains($data['private_key'], 'PRIVATE KEY')) {
            throw ValidationException::withMessages(['fcm_service_account_json' => 'El campo private_key no parece una clave privada válida.']);
        }

        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** Guarda (o borra) la cuenta de servicio en la fila de la plataforma */
    private function saveFcmServiceAccount(Request $request, ?Tenant $tenant, ?string $json, ?User $actor): void
    {
        if ($tenant !== null || ! in_array('fcm_service_account_json', self::columns(), true)) {
            return;
        }

        $clear = $request->boolean('clear_fcm_service_account_json');
        if ($json === null && ! $clear) {
            return;
        }

        $row = TenantSetting::platform();
        $row->fcm_service_account_json = $clear && $json === null ? null : $json;
        $row->updated_by = $actor?->id;
        $row->save();
    }

    /** Reglas comunes: todo es opcional (ausente = no cambiar; null = heredar) */
    public function rules(bool $global): array
    {
        $image = ['sometimes', 'nullable', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'];

        return [
            'trade_name'    => ['sometimes', 'nullable', 'string', 'max:200'],
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'website'       => ['sometimes', 'nullable', 'url:http,https', 'max:255'],
            'address'       => ['sometimes', 'nullable', 'string', 'max:500'],

            'primary_color'   => $global ? ['sometimes', 'nullable', self::HEX] : ['sometimes', 'required', self::HEX],
            'secondary_color' => $global ? ['sometimes', 'nullable', self::HEX] : ['sometimes', 'required', self::HEX],
            'accent_color'    => ['sometimes', 'nullable', self::HEX],
            'navbar_color'    => ['sometimes', 'nullable', self::HEX],

            'logo'      => $image,
            'logo_dark' => $image,
            'splash'    => $image,
            // SVG excluido (puede llevar scripts); el favicon admite ICO
            'favicon'   => ['sometimes', 'nullable', 'file', 'mimes:png,ico', 'extensions:png,ico', 'max:2048'],
            'remove_logo'      => ['sometimes', 'boolean'],
            'remove_logo_dark' => ['sometimes', 'boolean'],
            'remove_favicon'   => ['sometimes', 'boolean'],
            'remove_splash'    => ['sometimes', 'boolean'],

            'api_base_url'            => ['sometimes', 'nullable', 'url:https', 'max:255', 'not_regex:/\/api\/?$/i'],
            'min_supported_version'   => ['sometimes', 'nullable', 'string', 'max:40', self::SEMVER],
            'latest_version'          => ['sometimes', 'nullable', 'string', 'max:40', self::SEMVER],
            'force_update'            => ['sometimes', 'nullable', 'boolean'],
            'update_url'              => ['sometimes', 'nullable', 'url:https', 'max:500'],
            'selfie_required'         => ['sometimes', 'nullable', 'boolean'],
            'face_detection_required' => ['sometimes', 'nullable', 'boolean'],
            'offline_mode_enabled'    => ['sometimes', 'nullable', 'boolean'],
            'offline_max_hours'       => ['sometimes', 'nullable', 'integer', 'between:1,72'],
            'max_gps_accuracy_meters' => ['sometimes', 'nullable', 'integer', 'between:10,1000'],
            'mock_location_policy'    => ['sometimes', 'nullable', Rule::in(TenantSetting::MOCK_LOCATION_POLICIES)],
            'max_time_drift_seconds'  => ['sometimes', 'nullable', 'integer', 'between:30,86400'],

            'default_grace_minutes'   => ['sometimes', 'nullable', 'integer', 'between:0,240'],
            'lunch_minutes'           => ['sometimes', 'nullable', 'integer', 'between:0,480'],
            'lunch_paid'              => ['sometimes', 'nullable', 'boolean'],
            'default_geofence_radius' => ['sometimes', 'nullable', 'integer', 'between:10,5000'],
            'geofence_policy'         => ['sometimes', Rule::in(array_keys(CompanyConfiguration::GEOFENCE_POLICIES))],

            'webhook_url'           => ['sometimes', 'nullable', 'url:http,https', 'max:255'],
            'webhook_secret'        => ['sometimes', 'nullable', 'string', 'min:16', 'max:255'],
            'maps_api_key'          => ['sometimes', 'nullable', 'string', 'max:255'],
            'notification_endpoint' => ['sometimes', 'nullable', 'url:http,https', 'max:255'],
            'clear_webhook_secret'  => ['sometimes', 'boolean'],
            'clear_maps_api_key'    => ['sometimes', 'boolean'],

            // Geocodificación: google_maps_api_key se guarda en la columna cifrada maps_api_key
            'geocoding_provider'        => ['sometimes', 'nullable', Rule::in(TenantSetting::GEOCODING_PROVIDERS)],
            'google_maps_api_key'       => ['sometimes', 'nullable', 'string', 'max:255'],
            'clear_google_maps_api_key' => ['sometimes', 'boolean'],

            // Push (FCM HTTP v1). La cuenta de servicio es de la plataforma (única app móvil)
            'fcm_enabled'                    => ['sometimes', 'nullable', 'boolean'],
            'fcm_service_account_json'       => $global
                ? ['sometimes', 'nullable', 'string', 'max:20000', $this->serviceAccountRule()]
                : ['prohibited'],
            'fcm_service_account_file'       => $global
                ? ['sometimes', 'nullable', 'file', 'extensions:json', 'max:64']
                : ['prohibited'],
            'clear_fcm_service_account_json' => $global ? ['sometimes', 'boolean'] : ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'api_base_url.url'        => 'La URL de la API debe empezar con https://.',
            'webhook_secret.min'      => 'El secreto del webhook debe tener al menos 16 caracteres. Usa "Regenerar secreto" para crear uno seguro.',
            'api_base_url.not_regex'  => 'Escribe la URL sin /api al final (la app la agrega).',
            'min_supported_version.regex' => 'Usa el formato de versión 1.2.3.',
            'latest_version.regex'    => 'Usa el formato de versión 1.2.3.',
            'update_url.url'          => 'El enlace de descarga debe empezar con https://.',
            '*.regex'                 => 'El color debe ser hexadecimal, por ejemplo #1E40AF.',
            'favicon.mimes'           => 'El favicon debe ser PNG o ICO.',
            'favicon.extensions'      => 'El favicon debe ser PNG o ICO.',
            'fcm_service_account_json.prohibited' => 'La cuenta de servicio de Firebase solo se configura en la plataforma.',
            'fcm_service_account_file.prohibited' => 'La cuenta de servicio de Firebase solo se configura en la plataforma.',
            'fcm_service_account_file.extensions' => 'Sube el archivo .json de la cuenta de servicio de Firebase.',
            'max_time_drift_seconds.between'      => 'La diferencia de hora permitida debe estar entre 30 y 86400 segundos.',
        ];
    }

    /** El JSON debe ser una cuenta de servicio de Google con project_id, client_email y private_key */
    private function serviceAccountRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            if (filled($value) && TenantSetting::parseServiceAccount((string) $value) === null) {
                $fail('El JSON de la cuenta de servicio de Firebase no es válido (faltan project_id, client_email o private_key).');
            }
        };
    }

    /** Lee el .json subido de la cuenta de servicio (o null). Lanza ValidationException si no sirve. */
    private function uploadedServiceAccount(Request $request): ?string
    {
        $file = $request->file('fcm_service_account_file');
        if (! $file instanceof UploadedFile) {
            return null;
        }

        $json = (string) file_get_contents($file->getRealPath());
        if (TenantSetting::parseServiceAccount($json) === null) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'fcm_service_account_file' => 'El archivo no es una cuenta de servicio de Firebase válida.',
            ]);
        }

        return $json;
    }

    /**
     * Guarda lo validado en la fila de la empresa (o la global si $tenant es null).
     */
    public function update(?Tenant $tenant, array $data, Request $request, ?User $actor): TenantSetting
    {
        $row     = TenantSetting::editableRowFor($tenant);
        $changed = [];
        $secrets = [];
        $before  = AuditLogger::snapshot($row, $tenant?->only(['company_name', 'primary_color', 'secondary_color', 'logo_path']), ['geofence_policy' => $tenant ? CompanyConfiguration::forTenantId($tenant->id)->geofence_policy : null]);

        DB::transaction(function () use ($tenant, $data, $request, $actor, $row, &$changed, &$secrets) {
            foreach (TenantSetting::SETTING_FIELDS as $field) {
                if (array_key_exists($field, $data)) {
                    $row->{$field} = $data[$field] === '' ? null : $data[$field];
                }
            }
            if (isset($data['api_base_url'])) {
                $row->api_base_url = rtrim($data['api_base_url'], '/');
            }
            foreach (['accent_color', 'navbar_color'] as $c) {
                if (! empty($data[$c])) {
                    $row->{$c} = strtoupper($data[$c]);
                }
            }

            // Alias: la clave de Google Maps vive en maps_api_key
            if (! empty($data['google_maps_api_key'])) {
                $data['maps_api_key'] = $data['google_maps_api_key'];
            }
            if (! $tenant && ($uploaded = $this->uploadedServiceAccount($request))) {
                $data['fcm_service_account_json'] = $uploaded;
            }
            if (! empty($data['fcm_service_account_json'])) {
                $data['fcm_service_account_json'] = trim($data['fcm_service_account_json']);
            }

            // Secretos: vacío = no cambiar; "borrar" los elimina
            foreach (TenantSetting::SECRET_FIELDS as $secret) {
                if ($secret === 'fcm_service_account_json' && $tenant) {
                    continue; // solo la plataforma
                }
                $clear = $request->boolean("clear_{$secret}")
                    || ($secret === 'maps_api_key' && $request->boolean('clear_google_maps_api_key'));

                if ($clear && $secret === 'webhook_secret') {
                    // El webhook nunca queda sin firma: "borrar" genera uno nuevo
                    $row->webhook_secret = TenantSetting::generateWebhookSecret();
                    $secrets[] = $secret;
                } elseif ($clear) {
                    $row->{$secret} = null;
                    $secrets[] = $secret;
                } elseif ($secret === 'webhook_secret' && ! empty($data[$secret]) && $data[$secret] === $row->readableWebhookSecret()) {
                    continue; // el formulario reenvía el secreto precargado: no es un cambio
                } elseif (! empty($data[$secret])) {
                    $row->{$secret} = $data[$secret];
                    $secrets[] = $secret;
                }
            }

            // Colores primario/secundario: en `tenants` para una empresa, en la fila global para la plataforma
            $tenantChanges = [];
            // Solo lo envía PUT /api/admin/branding (no está en rules())
            if ($tenant && isset($data['company_name'])) {
                $tenantChanges['company_name'] = $data['company_name'];
            }
            foreach (['primary_color', 'secondary_color'] as $c) {
                if (array_key_exists($c, $data)) {
                    $value = $data[$c] ? strtoupper($data[$c]) : null;
                    if ($tenant) {
                        $tenantChanges[$c] = $value;
                    } else {
                        $row->{$c} = $value;
                    }
                }
            }

            // Imágenes
            $dir = $tenant ? "tenants/{$tenant->id}/branding" : 'platform/branding';
            foreach (TenantSetting::IMAGE_FIELDS as $input => $column) {
                $owner = ($input === 'logo' && $tenant) ? $tenant : $row;
                $file  = $request->file($input);

                if ($file instanceof UploadedFile) {
                    $this->deleteFile($owner->{$column});
                    $value = $file->store($dir, 'public');
                } elseif ($request->boolean("remove_{$input}") && $owner->{$column}) {
                    $this->deleteFile($owner->{$column});
                    $value = null;
                } else {
                    continue;
                }

                if ($owner === $tenant) {
                    $tenantChanges[$column] = $value;
                } else {
                    $row->{$column} = $value;
                }
            }

            if ($tenant && $tenantChanges) {
                $tenant->fill($tenantChanges);
                $changed = array_merge($changed, array_keys($tenant->getDirty()));
                $tenant->save();
            }

            // Política de geocerca: una sola fuente (company_configurations)
            if ($tenant && isset($data['geofence_policy'])) {
                $config = CompanyConfiguration::forTenantId($tenant->id);
                $config->geofence_policy = $data['geofence_policy'];
                if ($config->isDirty('geofence_policy') || ! $config->exists) {
                    $config->updated_by = $actor?->id;
                    $config->save();
                    $changed[] = 'geofence_policy';
                }
            }

            $changed = array_merge($changed, array_diff(array_keys($row->getDirty()), TenantSetting::SECRET_FIELDS));
            $row->updated_by = $actor?->id;
            $row->save();
        });

        $after = AuditLogger::snapshot($row, $tenant?->only(['company_name', 'primary_color', 'secondary_color', 'logo_path']), ['geofence_policy' => $tenant ? CompanyConfiguration::forTenantId($tenant->id)->geofence_policy : null]);
        $this->audit->logChanges('tenant_settings.updated', $row, $before, $after, ['scope' => $tenant ? 'tenant' : 'platform']);

        return $row;
    }

    /** Lo que ve el administrador al editar (secretos enmascarados) */
    public function editablePayload(?Tenant $tenant): array
    {
        $row = TenantSetting::rowFor($tenant) ?? new TenantSetting();

        $values = collect(TenantSetting::SETTING_FIELDS)->mapWithKeys(fn ($f) => [$f => $row->{$f}])->all();

        return $values + [
            'primary_color'   => $tenant ? $tenant->primary_color : $row->primary_color,
            'secondary_color' => $tenant ? $tenant->secondary_color : $row->secondary_color,
            'logo_url'        => TenantSetting::publicUrl($tenant ? $tenant->logo_path : $row->logo_path),
            'logo_dark_url'   => TenantSetting::publicUrl($row->logo_dark_path),
            'favicon_url'     => TenantSetting::publicUrl($row->favicon_path),
            'splash_url'      => TenantSetting::publicUrl($row->splash_path),
            'geofence_policy' => $tenant ? CompanyConfiguration::forTenantId($tenant->id)->geofence_policy : null,
            'webhook_secret'  => self::mask($row->webhook_secret),
            'maps_api_key'    => self::mask($row->maps_api_key),
            'has_webhook_secret' => filled($row->webhook_secret),
            'has_maps_api_key'   => filled($row->maps_api_key),
            'google_maps_api_key'     => self::mask($row->maps_api_key),
            'has_google_maps_api_key' => filled($row->maps_api_key),
            // La cuenta de servicio nunca se devuelve: solo datos para reconocerla
            'has_fcm_service_account' => ! $tenant && filled($row->fcm_service_account_json),
            'fcm_project_id'          => $tenant ? null : TenantSetting::parseServiceAccount($row->fcm_service_account_json)['project_id'] ?? null,
            'fcm_client_email'        => $tenant ? null : TenantSetting::parseServiceAccount($row->fcm_service_account_json)['client_email'] ?? null,
        ];
    }

    /** "sk_live_abcdef1234" → "••••••••1234" */
    public static function mask(?string $secret): ?string
    {
        if (blank($secret)) {
            return null;
        }

        return str_repeat('•', 8) . (mb_strlen($secret) > 8 ? mb_substr($secret, -4) : '');
    }

    private function deleteFile(?string $path): void
    {
        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }
}
