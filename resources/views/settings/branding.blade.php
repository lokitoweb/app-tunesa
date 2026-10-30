@extends('layouts.app')

@section('title', 'Marca y app')

@section('content')
@php
    // Pestaña a abrir: la pedida, o la del primer campo con error
    $fieldTabs = [
        'app'           => ['api_base_url', 'min_supported_version', 'latest_version', 'force_update', 'update_url', 'selfie_required', 'face_detection_required', 'offline_mode_enabled', 'offline_max_hours', 'max_gps_accuracy_meters', 'self_password_change'],
        'reglas'        => ['default_grace_minutes', 'lunch_minutes', 'lunch_paid', 'vacation_days_per_year', 'punch_requires_schedule', 'default_geofence_radius', 'geofence_policy', 'mock_location_policy', 'max_time_drift_seconds'],
    ];
    // Campos que dependen de columnas nuevas de tenant_settings: solo se muestran si existen
    $has = fn (string $column) => in_array($column, $columns, true);
    $stored = fn (string $key) => old($key, $row->{$key});
    if ($errors->any()) {
        $first = array_key_first($errors->messages());
        $tab = collect($fieldTabs)->search(fn ($fields) => in_array($first, $fields, true)) ?: 'marca';
    }
    $val = fn (string $key) => old($key, $values[$key] ?? null);
    $inh = fn (string $key) => $inherited->{$key};
    $ownerName = $isGlobal ? 'la plataforma' : $tenant->company_name;
    $images = [
        'logo'      => ['Logo (fondo claro)', $values['logo_url'], 'image/png,image/jpeg,image/webp', 'PNG, JPG o WebP. Máx. 2 MB. Se usa en el panel, el login y la app.'],
        'logo_dark' => ['Logo (fondo oscuro)', $values['logo_dark_url'], 'image/png,image/jpeg,image/webp', 'Versión clara para el tema oscuro. Si no lo subes se usa el logo normal.'],
        'favicon'   => ['Favicon', $values['favicon_url'], 'image/png,image/x-icon,.ico', 'PNG o ICO cuadrado (32×32 o 64×64). Máx. 2 MB.'],
        'splash'    => ['Pantalla de inicio de la app', $values['splash_url'], 'image/png,image/jpeg,image/webp', 'Imagen vertical que la app muestra al abrirse (recomendado 1080×1920).'],
    ];
    $colors = [
        'primary_color'   => ['Primario', 'Botones, enlaces y elementos activos.'],
        'secondary_color' => ['Secundario', 'Detalles y acentos de la marca.'],
        'accent_color'    => ['Acento', 'Estados positivos y resaltados en la app.'],
        'navbar_color'    => ['Barra de navegación', 'Barra superior de la app y del panel.'],
    ];
    $previewColor = fn (string $key) => match ($key) {
        'primary_color'   => $effective->primaryColor(),
        'secondary_color' => $effective->secondaryColor(),
        'accent_color'    => $effective->accentColor(),
        default           => $effective->navbarColor(),
    };
@endphp

@include('settings.partials.header', [
    'title' => 'Marca y personalización',
    'subtitle' => 'Logotipos, colores y reglas operativas de ' . rtrim($ownerName, '.') . '.',
    'pill' => $isGlobal ? ['brand', 'Valores de toda la plataforma'] : ['blue', $tenant->company_name],
    'active' => 'branding',
])

@if($isGlobal)
    {{-- Banner Ejecutivo de Modo Global (Valores por Defecto) --}}
    <div class="settings-global-notice mb-4" role="status">
        <div class="d-flex align-items-start gap-3">
            <div class="settings-notice-icon">
                <i class="bi bi-globe-americas"></i>
            </div>
            <div class="flex-grow-1 min-w-0">
                <div class="d-flex align-items-center gap-2 mb-1">
                    <strong class="settings-notice-title">Valores por defecto de la plataforma</strong>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-0.5 font-monospace fw-bold" style="font-size: 0.68rem;">HERENCIA ACTIVA</span>
                </div>
                <div class="settings-notice-text">
                    Los parámetros configurados aquí se aplican automáticamente a todas las empresas que no hayan definido sus propios valores. Para personalizar una empresa específica, elígela en el conmutador superior.
                </div>
            </div>
        </div>
    </div>
@endif

<form method="POST" action="{{ route('settings.branding.update') }}" enctype="multipart/form-data" id="brandingForm" novalidate>
    @csrf @method('PUT')
    <input type="hidden" name="tab" id="activeTab" value="{{ $tab }}">

    {{-- Barra de Pestañas Ejecutiva Segmentada --}}
    <div class="d-flex align-items-center mb-4">
        <div class="nav branding-nav-tabs" role="tablist">
            @foreach(['marca' => ['palette', 'Marca y apariencia'], 'app' => ['phone', 'App móvil'], 'reglas' => ['sliders', 'Reglas operativas']] as $key => [$icon, $label])
                <button class="nav-link {{ $tab === $key ? 'active' : '' }}" id="tab-{{ $key }}" data-bs-toggle="tab" data-bs-target="#pane-{{ $key }}" data-tab="{{ $key }}" type="button" role="tab" aria-controls="pane-{{ $key }}" aria-selected="{{ $tab === $key ? 'true' : 'false' }}">
                    <i class="bi bi-{{ $icon }}"></i>
                    <span>{{ $label }}</span>
                </button>
            @endforeach
        </div>
    </div>

    <div class="tab-content">
        {{-- ============================ PESTAÑA 1: MARCA ============================ --}}
        <div class="tab-pane fade {{ $tab === 'marca' ? 'show active' : '' }}" id="pane-marca" role="tabpanel" aria-labelledby="tab-marca" tabindex="0">
            <div class="row g-4">
                <div class="col-12 col-xl-7 d-flex flex-column gap-4">
                    {{-- Identidad --}}
                    <div class="card border-0 shadow-sm branding-card">
                        <div class="card-header border-0 bg-transparent pt-3 pb-2 px-3">
                            <h2 class="h6 fw-bold mb-0 text-primary-emphasis">Identidad comercial</h2>
                        </div>
                        <div class="card-body p-3 pt-1">
                            <label for="trade_name" class="form-label small fw-semibold text-body mb-1">Nombre comercial</label>
                            <div class="modern-input-wrapper mb-1">
                                <span class="modern-input-icon"><i class="bi bi-building"></i></span>
                                <input type="text" id="trade_name" name="trade_name" maxlength="200" value="{{ $val('trade_name') }}"
                                       class="modern-input-control @error('trade_name') is-invalid @enderror"
                                       placeholder="{{ $isGlobal ? config('app.name') : $tenant->company_name }}">
                            </div>
                            <small class="settings-helper-text d-block">Visible en la barra superior del panel, inicio de sesión y encabezado de la app móvil.</small>
                        </div>
                    </div>

                    {{-- Imágenes Corporativas --}}
                    <div class="card border-0 shadow-sm branding-card">
                        <div class="card-header border-0 bg-transparent pt-3 pb-2 px-3">
                            <h2 class="h6 fw-bold mb-0 text-primary-emphasis">Imágenes y logos corporativos</h2>
                        </div>
                        <div class="card-body p-3 pt-1">
                            <div class="row g-3">
                                @foreach($images as $input => [$label, $url, $accept, $help])
                                    <div class="col-12 col-md-6">
                                        <div class="image-upload-card h-100 d-flex flex-column justify-content-between">
                                            <div>
                                                <label for="{{ $input }}" class="form-label small fw-semibold text-body mb-2">{{ $label }}</label>
                                                <div class="image-preview-zone mb-2 {{ $input === 'logo_dark' ? 'bg-dark' : '' }}">
                                                    <img src="{{ $url }}" alt="Vista previa: {{ $label }}" data-preview="{{ $input }}" class="{{ $url ? '' : 'd-none' }}" style="max-height: 72px; max-width: 90%; object-fit: contain;">
                                                    <span class="small text-secondary {{ $url ? 'd-none' : '' }}" data-empty="{{ $input }}">
                                                        <i class="bi bi-image me-1"></i> Sin imagen
                                                    </span>
                                                </div>
                                            </div>
                                            <div>
                                                <input type="file" id="{{ $input }}" name="{{ $input }}" accept="{{ $accept }}" data-preview-input="{{ $input }}"
                                                       class="form-control form-control-sm @error($input) is-invalid @enderror">
                                                <small class="settings-helper-text d-block mt-1" style="font-size: 0.725rem;">{{ $help }}</small>
                                                @if($url)
                                                    <div class="form-check mt-2 pt-1 border-top">
                                                        <input class="form-check-input" type="checkbox" name="remove_{{ $input }}" value="1" id="remove_{{ $input }}">
                                                        <label class="form-check-label small text-danger" for="remove_{{ $input }}">
                                                            <i class="bi bi-trash3 me-1"></i> Quitar imagen
                                                        </label>
                                                    </div>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    {{-- Datos de Contacto --}}
                    <div class="card border-0 shadow-sm branding-card">
                        <div class="card-header border-0 bg-transparent pt-3 pb-2 px-3">
                            <h2 class="h6 fw-bold mb-0 text-primary-emphasis">Datos de contacto</h2>
                            <small class="settings-helper-text">Visibles para el colaborador en la sección de "Ayuda y soporte".</small>
                        </div>
                        <div class="card-body p-3 pt-1">
                            <div class="row g-3">
                                <div class="col-sm-6">
                                    <label for="contact_email" class="form-label small fw-semibold text-body mb-1">Correo electrónico</label>
                                    <div class="modern-input-wrapper">
                                        <span class="modern-input-icon"><i class="bi bi-envelope"></i></span>
                                        <input type="email" id="contact_email" name="contact_email" maxlength="255" value="{{ $val('contact_email') }}" placeholder="{{ $tenant?->email }}" class="modern-input-control @error('contact_email') is-invalid @enderror">
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <label for="contact_phone" class="form-label small fw-semibold text-body mb-1">Teléfono</label>
                                    <div class="modern-input-wrapper">
                                        <span class="modern-input-icon"><i class="bi bi-telephone"></i></span>
                                        <input type="text" id="contact_phone" name="contact_phone" maxlength="30" value="{{ $val('contact_phone') }}" placeholder="{{ $tenant?->phone }}" class="modern-input-control @error('contact_phone') is-invalid @enderror">
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <label for="website" class="form-label small fw-semibold text-body mb-1">Sitio web</label>
                                    <div class="modern-input-wrapper">
                                        <span class="modern-input-icon"><i class="bi bi-globe"></i></span>
                                        <input type="url" id="website" name="website" maxlength="255" value="{{ $val('website') }}" placeholder="https://www.empresa.com" class="modern-input-control @error('website') is-invalid @enderror">
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <label for="address" class="form-label small fw-semibold text-body mb-1">Dirección fiscal / sede central</label>
                                    <div class="modern-input-wrapper">
                                        <span class="modern-input-icon"><i class="bi bi-geo-alt"></i></span>
                                        <input type="text" id="address" name="address" maxlength="500" value="{{ $val('address') }}" placeholder="{{ $tenant?->address }}" class="modern-input-control @error('address') is-invalid @enderror">
                                    </div>
                                </div>
                                <div class="col-12">
                                    <label for="login_email_domain" class="form-label small fw-semibold text-body mb-1">Dominio del correo de acceso (Alta rápida)</label>
                                    <div class="modern-input-wrapper">
                                        <span class="modern-input-icon text-primary font-monospace fw-bold" style="font-size: 0.85rem; width: auto; left: 12px;">DNI@</span>
                                        <input type="text" id="login_email_domain" name="login_email_domain" maxlength="150" value="{{ $val('login_email_domain') }}" placeholder="{{ \App\Modules\Core\Models\TenantSetting::forTenant(null)->loginEmailDomain() }}" class="modern-input-control @error('login_email_domain') is-invalid @enderror" style="padding-left: 56px;" aria-describedby="login_email_domain_help">
                                    </div>
                                    <small id="login_email_domain_help" class="settings-helper-text d-block mt-1">Al dar de alta a un colaborador se compone su usuario con su número de documento y este sufijo de dominio.</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-xl-5 d-flex flex-column gap-4">
                    {{-- Paleta de Colores --}}
                    <div class="card border-0 shadow-sm branding-card">
                        <div class="card-header border-0 bg-transparent pt-3 pb-2 px-3">
                            <h2 class="h6 fw-bold mb-0 text-primary-emphasis">Paleta de colores</h2>
                        </div>
                        <div class="card-body p-3 pt-1">
                            @foreach($colors as $key => [$label, $help])
                                @php
                                    $required = ! $isGlobal && in_array($key, ['primary_color', 'secondary_color'], true);
                                    $current  = $val($key);
                                @endphp
                                <div class="mb-3">
                                    <label for="{{ $key }}" class="form-label small fw-semibold text-body mb-1">{{ $label }}</label>
                                    <div class="d-flex align-items-center gap-2">
                                        <div style="position: relative; width: 40px; height: 40px; flex-shrink: 0; border-radius: 10px; overflow: hidden; border: 2px solid var(--border-color); box-shadow: 0 2px 4px rgba(0,0,0,0.06);">
                                            <input type="color" style="position: absolute; top: -6px; left: -6px; width: 54px; height: 54px; border: none; cursor: pointer; padding: 0;" value="{{ $previewColor($key) }}" data-color-picker="{{ $key }}" aria-label="Elegir color {{ strtolower($label) }}">
                                        </div>
                                        <div class="modern-input-wrapper flex-grow-1">
                                            <span class="modern-input-icon fw-bold font-monospace text-secondary" style="font-size: 0.95rem;">#</span>
                                            <input type="text" id="{{ $key }}" name="{{ $key }}" value="{{ $current }}" maxlength="7" pattern="#[0-9A-Fa-f]{6}" data-color-text="{{ $key }}"
                                                   class="modern-input-control font-monospace text-uppercase ps-4 @error($key) is-invalid @enderror"
                                                   placeholder="{{ $previewColor($key) }}" {{ $required ? 'required' : '' }}>
                                        </div>
                                    </div>
                                    <div class="settings-helper-text mt-1 d-flex flex-wrap align-items-center gap-1">
                                        <span>{{ $help }}</span>
                                        @unless($required)
                                            <span class="ms-1">Vacío: hereda</span>
                                            <span class="color-inherit-chip">{{ $previewColor($key) }}</span>
                                        @endunless
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    {{-- Vista Previa de Marca --}}
                    <div class="card border-0 shadow-sm branding-card">
                        <div class="card-header border-0 bg-transparent pt-3 pb-2 px-3">
                            <h2 class="h6 fw-bold mb-0 text-primary-emphasis">Vista previa interactiva</h2>
                        </div>
                        <div class="card-body p-3 pt-1">
                            <div class="brand-preview border rounded-3 overflow-hidden shadow-sm" id="brandPreview" aria-hidden="true"
                                 style="--p-primary: {{ $effective->primaryColor() }}; --p-secondary: {{ $effective->secondaryColor() }}; --p-accent: {{ $effective->accentColor() }}; --p-navbar: {{ $effective->navbarColor() }};">
                                <div class="d-flex align-items-center gap-2 px-3 py-2 brand-preview-bar">
                                    <img src="{{ $values['logo_url'] }}" alt="" data-preview="logo" class="{{ $values['logo_url'] ? '' : 'd-none' }}" style="height: 22px; max-width: 80px; object-fit: contain;">
                                    <span class="small fw-semibold" data-preview-name>{{ $effective->tradeName() }}</span>
                                </div>
                                <div class="d-flex" style="min-height: 150px;">
                                    <div class="bg-body-tertiary border-end p-2 small" style="width: 42%;">
                                        <div class="brand-preview-active rounded-2 px-2 py-1 mb-1"><i class="bi bi-speedometer2 me-1"></i> Dashboard</div>
                                        <div class="px-2 py-1 text-body-secondary"><i class="bi bi-people me-1"></i> Personal</div>
                                        <div class="px-2 py-1 text-body-secondary"><i class="bi bi-clock-history me-1"></i> Marcaciones</div>
                                    </div>
                                    <div class="p-3 flex-grow-1 d-flex flex-column gap-2 align-items-start">
                                        <span class="badge rounded-pill brand-preview-accent">Puntual</span>
                                        <span class="badge rounded-pill brand-preview-secondary">Turno mañana</span>
                                        <button type="button" class="btn btn-sm brand-preview-btn mt-auto" tabindex="-1">Marcar entrada</button>
                                    </div>
                                </div>
                            </div>
                            <small class="text-secondary d-block mt-2">El contraste de texto sobre cada color se calcula automáticamente para garantizar legibilidad.</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ============================ PESTAÑA 2: APP MÓVIL ============================ --}}
        <div class="tab-pane fade {{ $tab === 'app' ? 'show active' : '' }}" id="pane-app" role="tabpanel" aria-labelledby="tab-app" tabindex="0">
            <div class="row g-4">
                <div class="col-12 col-xl-6 d-flex flex-column gap-4">
                    {{-- Servidor API --}}
                    <div class="card border-0 shadow-sm branding-card">
                        <div class="card-header border-0 bg-transparent pt-3 pb-2 px-3">
                            <h2 class="h6 fw-bold mb-0 text-primary-emphasis">Conexión de servidor (API)</h2>
                        </div>
                        <div class="card-body p-3 pt-1">
                            @php
                                // Dominio actual: el de esta página (https) o APP_URL. Vacío = automático.
                                $requestOrigin = request()->getSchemeAndHttpHost();
                                $detectedApi = str_starts_with($requestOrigin, 'https://') ? $requestOrigin : \App\Modules\Core\Support\PlatformUrl::detected();
                                $storedApi = $stored('api_base_url');
                                $apiValue = old('api_base_url', $storedApi ?: ($isGlobal ? $detectedApi : ($inh('api_base_url') ?: $detectedApi)));
                            @endphp
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-1">
                                <label for="api_base_url" class="form-label small fw-semibold text-secondary mb-0">URL base de la API</label>
                                <span id="apiAutoBadge" class="badge rounded-pill bg-success-subtle text-success-emphasis border border-success-subtle {{ $apiValue && $detectedApi && rtrim($apiValue, '/') === $detectedApi ? '' : 'd-none' }}">
                                    <i class="bi bi-magic me-1"></i>Dominio detectado automáticamente
                                </span>
                                <span id="apiCustomBadge" class="badge rounded-pill bg-warning-subtle text-warning-emphasis border border-warning-subtle {{ $apiValue && $detectedApi && rtrim($apiValue, '/') !== $detectedApi ? '' : 'd-none' }}">
                                    <i class="bi bi-pencil me-1"></i>Personalizada (túnel u otro dominio)
                                </span>
                            </div>
                            <div class="d-flex gap-2 mb-1">
                                <div class="modern-input-wrapper flex-grow-1">
                                    <span class="modern-input-icon"><i class="bi bi-link-45deg"></i></span>
                                    <input type="url" id="api_base_url" name="api_base_url" maxlength="255" value="{{ $apiValue }}"
                                           data-detected="{{ $detectedApi }}"
                                           class="modern-input-control font-monospace @error('api_base_url') is-invalid @enderror"
                                           placeholder="{{ $detectedApi ?: 'https://xxxx.trycloudflare.com' }}">
                                </div>
                                @if($detectedApi)
                                    <button type="button" class="btn btn-outline-secondary flex-shrink-0" id="apiResetBtn" title="Usar el dominio de este servidor">
                                        <i class="bi bi-arrow-counterclockwise me-1"></i>Restablecer a dominio actual
                                    </button>
                                @endif
                            </div>
                            @error('api_base_url')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                            <small class="text-secondary d-block">Se completa sola con el dominio de este servidor y la app lo usa automáticamente. Cámbiala solo si la app debe conectarse por otra dirección (por ejemplo un Cloudflare Tunnel de pruebas). <code>https://</code>, sin <code>/api</code> al final.</small>
                            <script>
                                (function () {
                                    var input = document.getElementById('api_base_url');
                                    var reset = document.getElementById('apiResetBtn');
                                    var auto = document.getElementById('apiAutoBadge');
                                    var custom = document.getElementById('apiCustomBadge');
                                    if (!input) return;
                                    function paint() {
                                        var detected = input.dataset.detected;
                                        var value = input.value.trim().replace(/\/+$/, '');
                                        auto.classList.toggle('d-none', !detected || value !== detected);
                                        custom.classList.toggle('d-none', !detected || !value || value === detected);
                                    }
                                    input.addEventListener('input', paint);
                                    if (reset) reset.addEventListener('click', function () { input.value = input.dataset.detected; paint(); input.focus(); });
                                })();
                            </script>
                        </div>
                    </div>

                    {{-- Versiones y Actualizaciones --}}
                    <div class="card border-0 shadow-sm branding-card">
                        <div class="card-header border-0 bg-transparent pt-3 pb-2 px-3">
                            <h2 class="h6 fw-bold mb-0 text-primary-emphasis">Control de versiones</h2>
                        </div>
                        <div class="card-body p-3 pt-1">
                            <div class="row g-3">
                                <div class="col-sm-6">
                                    <label for="min_supported_version" class="form-label small fw-semibold text-secondary mb-1">Versión mínima soportada</label>
                                    <div class="modern-input-wrapper">
                                        <span class="modern-input-icon"><i class="bi bi-shield-check"></i></span>
                                        <input type="text" id="min_supported_version" name="min_supported_version" maxlength="40" value="{{ $val('min_supported_version') }}" placeholder="{{ $inh('min_supported_version') }}" class="modern-input-control font-monospace @error('min_supported_version') is-invalid @enderror">
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <label for="latest_version" class="form-label small fw-semibold text-secondary mb-1">Última versión publicada</label>
                                    <div class="modern-input-wrapper">
                                        <span class="modern-input-icon"><i class="bi bi-stars"></i></span>
                                        <input type="text" id="latest_version" name="latest_version" maxlength="40" value="{{ $val('latest_version') }}" placeholder="{{ $inh('latest_version') ?: '1.4.0' }}" class="modern-input-control font-monospace @error('latest_version') is-invalid @enderror">
                                    </div>
                                </div>
                                <div class="col-12">
                                    <label for="update_url" class="form-label small fw-semibold text-secondary mb-1">Enlace de descarga o Play Store</label>
                                    <div class="modern-input-wrapper">
                                        <span class="modern-input-icon"><i class="bi bi-box-arrow-up-right"></i></span>
                                        <input type="url" id="update_url" name="update_url" maxlength="500" value="{{ $val('update_url') }}" placeholder="{{ $inh('update_url') ?: 'https://play.google.com/store/apps/details?id=…' }}" class="modern-input-control @error('update_url') is-invalid @enderror">
                                    </div>
                                    <small class="text-secondary d-block mt-1">El colaborador verá el botón "Actualizar" que abrirá este enlace directamente.</small>
                                </div>
                            </div>
                            <div class="mt-3 pt-3 border-top">
                                @include('settings.partials.bool-setting', ['flag' => 'force_update',
                                    'label' => 'Obligar a actualizar',
                                    'desc'  => 'Las versiones de app inferiores a la mínima no podrán registrar marcaciones hasta que se actualicen.'])
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Políticas de Marcación Móvil --}}
                <div class="col-12 col-xl-6">
                    <div class="card border-0 shadow-sm branding-card">
                        <div class="card-header border-0 bg-transparent pt-3 pb-2 px-3">
                            <h2 class="h6 fw-bold mb-0 text-primary-emphasis">Seguridad y marcación móvil</h2>
                        </div>
                        <ul class="list-group list-group-flush">
                            @foreach([
                                'selfie_required'         => ['Selfie obligatoria', 'Solicita una fotografía facial al marcar entrada y salida.'],
                                'face_detection_required' => ['Detección de rostro biométrica', 'La app verifica que exista un rostro visible en la selfie antes de enviar la marcación.'],
                                'offline_mode_enabled'    => ['Marcación sin conexión (Offline)', 'Si no hay red, la app almacena temporalmente la marcación y la transmite al recuperar señal.'],
                                'self_password_change'    => ['Cambio autónomo de contraseña', 'El colaborador puede cambiar su contraseña desde Mi perfil cuando lo desee.'],
                            ] as $flag => [$label, $desc])
                                <li class="list-group-item py-3 px-3 bg-transparent">
                                    @include('settings.partials.bool-setting', ['flag' => $flag, 'label' => $label, 'desc' => $desc])
                                </li>
                            @endforeach
                            <li class="list-group-item py-3 px-3 bg-transparent" id="offlineHoursRow">
                                <label for="offline_max_hours" class="form-label small fw-semibold text-secondary mb-1">Antigüedad máxima permitida offline</label>
                                <div class="modern-input-wrapper" style="max-width: 220px;">
                                    <span class="modern-input-icon"><i class="bi bi-clock-history"></i></span>
                                    <input type="number" id="offline_max_hours" name="offline_max_hours" min="1" max="72" value="{{ $val('offline_max_hours') }}" placeholder="{{ $inh('offline_max_hours') }}" class="modern-input-control @error('offline_max_hours') is-invalid @enderror">
                                    <span class="modern-input-suffix">horas</span>
                                </div>
                                <small class="text-secondary d-block mt-1">Entre 1 y 72 horas. Si supera este umbral, requerirá regularización por RRHH.</small>
                            </li>
                            <li class="list-group-item py-3 px-3 bg-transparent">
                                <label for="max_gps_accuracy_meters" class="form-label small fw-semibold text-secondary mb-1">Precisión GPS mínima requerida</label>
                                <div class="modern-input-wrapper" style="max-width: 220px;">
                                    <span class="modern-input-icon"><i class="bi bi-crosshair"></i></span>
                                    <input type="number" id="max_gps_accuracy_meters" name="max_gps_accuracy_meters" min="10" max="1000" value="{{ $val('max_gps_accuracy_meters') }}" placeholder="{{ $inherited->maxGpsAccuracy() }}" class="modern-input-control @error('max_gps_accuracy_meters') is-invalid @enderror">
                                    <span class="modern-input-suffix">m</span>
                                </div>
                                <small class="text-secondary d-block mt-1">Si la dispersión del sensor excede este valor, solo se aceptará si todo el radio de error cae dentro de la geocerca.</small>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        {{-- ============================ PESTAÑA 3: REGLAS OPERATIVAS ============================ --}}
        <div class="tab-pane fade {{ $tab === 'reglas' ? 'show active' : '' }}" id="pane-reglas" role="tabpanel" aria-labelledby="tab-reglas" tabindex="0">
            <div class="row g-4">
                <div class="col-12 col-xl-6">
                    <div class="card border-0 shadow-sm branding-card h-100">
                        <div class="card-header border-0 bg-transparent pt-3 pb-2 px-3">
                            <h2 class="h6 fw-bold mb-0 text-primary-emphasis">Parámetros operativos predeterminados</h2>
                            <small class="text-secondary">Valores base propuestos al crear nuevos turnos y sucursales.</small>
                        </div>
                        <div class="card-body p-3 pt-1">
                            <div class="row g-3">
                                <div class="col-sm-6">
                                    <label for="default_grace_minutes" class="form-label small fw-semibold text-secondary mb-1">Tolerancia de tardanza</label>
                                    <div class="modern-input-wrapper">
                                        <span class="modern-input-icon"><i class="bi bi-hourglass-split"></i></span>
                                        <input type="number" id="default_grace_minutes" name="default_grace_minutes" min="0" max="240" value="{{ $val('default_grace_minutes') }}" placeholder="{{ $inh('default_grace_minutes') }}" class="modern-input-control @error('default_grace_minutes') is-invalid @enderror">
                                        <span class="modern-input-suffix">min</span>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <label for="lunch_minutes" class="form-label small fw-semibold text-secondary mb-1">Tiempo de refrigerio</label>
                                    <div class="modern-input-wrapper">
                                        <span class="modern-input-icon"><i class="bi bi-cup-hot"></i></span>
                                        <input type="number" id="lunch_minutes" name="lunch_minutes" min="0" max="480" value="{{ $val('lunch_minutes') }}" placeholder="{{ $inh('lunch_minutes') }}" class="modern-input-control @error('lunch_minutes') is-invalid @enderror">
                                        <span class="modern-input-suffix">min</span>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <label for="vacation_days_per_year" class="form-label small fw-semibold text-secondary mb-1">Vacaciones por año de servicio</label>
                                    <div class="modern-input-wrapper">
                                        <span class="modern-input-icon"><i class="bi bi-sun"></i></span>
                                        <input type="number" id="vacation_days_per_year" name="vacation_days_per_year" min="0" max="60" value="{{ $val('vacation_days_per_year') }}" placeholder="{{ $inh('vacation_days_per_year') }}" class="modern-input-control @error('vacation_days_per_year') is-invalid @enderror">
                                        <span class="modern-input-suffix">días</span>
                                    </div>
                                    <div class="form-text small">Días calendario que se ganan por cada año cumplido (régimen general: 30). El saldo descuenta las vacaciones aprobadas.</div>
                                </div>
                                <div class="col-12">
                                    <label for="default_geofence_radius" class="form-label small fw-semibold text-secondary mb-1">Radio de geocerca por defecto</label>
                                    <div class="modern-input-wrapper" style="max-width: 240px;">
                                        <span class="modern-input-icon"><i class="bi bi-geo"></i></span>
                                        <input type="number" id="default_geofence_radius" name="default_geofence_radius" min="10" max="5000" value="{{ $val('default_geofence_radius') }}" placeholder="{{ $inh('default_geofence_radius') }}" class="modern-input-control @error('default_geofence_radius') is-invalid @enderror">
                                        <span class="modern-input-suffix">m</span>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-3 pt-3 border-top">
                                @include('settings.partials.bool-setting', ['flag' => 'lunch_paid',
                                    'label' => 'Refrigerio remunerado / pagado',
                                    'desc'  => 'El descanso registrado no se descuenta del total de horas efectivas laboradas.'])
                            </div>
                            <div class="mt-3 pt-3 border-top">
                                @include('settings.partials.bool-setting', ['flag' => 'punch_requires_schedule',
                                    'label' => 'Marcar solo con turno programado',
                                    'desc'  => 'En días de descanso o sin programación la app no deja marcar entrada. RR. HH. o Gerencia pueden autorizar un día puntual en Asistencia › Marcación fuera de turno.'])
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-xl-6">
                    <div class="card border-0 shadow-sm branding-card h-100">
                        <div class="card-header border-0 bg-transparent pt-3 pb-2 px-3">
                            <h2 class="h6 fw-bold mb-0 text-primary-emphasis">Marcación fuera de geocerca</h2>
                            <small class="text-secondary">Comportamiento del sistema ante intentos de registro fuera de radio de sucursal.</small>
                        </div>
                        <div class="card-body p-3 pt-1">
                            @if($isGlobal)
                                <p class="small text-secondary mb-0">Esta política es específica por empresa (por defecto: rechazar la marcación). Elige una empresa en el selector superior para personalizarla.</p>
                            @else
                                <div class="d-flex flex-column gap-2">
                                    @foreach($policies as $policy => [$label, $desc])
                                        <label class="doc-tile-card {{ old('geofence_policy', $values['geofence_policy']) === $policy ? 'active' : '' }}" for="geo_{{ $policy }}">
                                            <div class="form-check mb-0">
                                                <input class="form-check-input" type="radio" name="geofence_policy" value="{{ $policy }}" id="geo_{{ $policy }}" @checked(old('geofence_policy', $values['geofence_policy']) === $policy) onchange="document.querySelectorAll('.doc-tile-card').forEach(c => c.classList.remove('active')); this.closest('.doc-tile-card').classList.add('active');">
                                            </div>
                                            <div>
                                                <span class="d-block fw-semibold small">{{ $label }}</span>
                                                <span class="d-block text-secondary" style="font-size: 0.775rem;">{{ $desc }}</span>
                                            </div>
                                        </label>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>
                </div>

                @if($has('mock_location_policy') || $has('max_time_drift_seconds'))
                <div class="col-12">
                    <div class="card border-0 shadow-sm branding-card">
                        <div class="card-header border-0 bg-transparent pt-3 pb-2 px-3">
                            <h2 class="h6 fw-bold mb-0 text-primary-emphasis">Seguridad antifraude de marcaciones</h2>
                            <small class="text-secondary">Detección de simuladores GPS (Fake GPS) y desincronización de hora del dispositivo.</small>
                        </div>
                        <div class="card-body p-3 pt-1">
                            <div class="row g-4">
                                @if($has('mock_location_policy'))
                                    @php
                                        $mockLabels = ['reject' => ['Bloquear marcación', 'La marcación con GPS simulado se rechaza de inmediato.'],
                                                       'flag'   => ['Marcar como sospechosa', 'Se registra pero queda con aviso de auditoría para revisión.']];
                                        $mockInherited = $inh('mock_location_policy') ?: 'reject';
                                        $mockCurrent = (string) ($stored('mock_location_policy') ?? ($isGlobal ? $mockInherited : ''));
                                    @endphp
                                    <fieldset class="col-md-7">
                                        <legend class="form-label small fw-semibold text-secondary mb-2">Ubicación simulada (Fake GPS)</legend>
                                        <div class="d-flex flex-column gap-2">
                                            @unless($isGlobal)
                                                <label class="doc-tile-card {{ $mockCurrent === '' ? 'active' : '' }}" for="mock_inherit">
                                                    <div class="form-check mb-0">
                                                        <input class="form-check-input" type="radio" name="mock_location_policy" value="" id="mock_inherit" @checked($mockCurrent === '') onchange="document.querySelectorAll('[name=mock_location_policy]').forEach(r => r.closest('.doc-tile-card')?.classList.remove('active')); this.closest('.doc-tile-card').classList.add('active');">
                                                    </div>
                                                    <div>
                                                        <span class="d-block fw-semibold small">Heredar de la plataforma</span>
                                                        <span class="d-block text-secondary" style="font-size: 0.775rem;">Actual: {{ $mockLabels[$mockInherited][0] ?? $mockInherited }}.</span>
                                                    </div>
                                                </label>
                                            @endunless
                                            @foreach($mockLabels as $policy => [$label, $desc])
                                                <label class="doc-tile-card {{ $mockCurrent === $policy ? 'active' : '' }}" for="mock_{{ $policy }}">
                                                    <div class="form-check mb-0">
                                                        <input class="form-check-input @error('mock_location_policy') is-invalid @enderror" type="radio" name="mock_location_policy" value="{{ $policy }}" id="mock_{{ $policy }}" @checked($mockCurrent === $policy) onchange="document.querySelectorAll('[name=mock_location_policy]').forEach(r => r.closest('.doc-tile-card')?.classList.remove('active')); this.closest('.doc-tile-card').classList.add('active');">
                                                    </div>
                                                    <div>
                                                        <span class="d-block fw-semibold small">{{ $label }}</span>
                                                        <span class="d-block text-secondary" style="font-size: 0.775rem;">{{ $desc }}</span>
                                                    </div>
                                                </label>
                                            @endforeach
                                        </div>
                                        @error('mock_location_policy')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                                    </fieldset>
                                @endif
                                @if($has('max_time_drift_seconds'))
                                    <div class="col-md-5">
                                        <label for="max_time_drift_seconds" class="form-label small fw-semibold text-secondary mb-1">Desfase máximo horario del móvil</label>
                                        <div class="modern-input-wrapper" style="max-width: 220px;">
                                            <span class="modern-input-icon"><i class="bi bi-stopwatch"></i></span>
                                            <input type="number" id="max_time_drift_seconds" name="max_time_drift_seconds" min="0" max="86400" step="1"
                                                   value="{{ $stored('max_time_drift_seconds') }}" placeholder="{{ $inh('max_time_drift_seconds') }}"
                                                   class="modern-input-control @error('max_time_drift_seconds') is-invalid @enderror">
                                            <span class="modern-input-suffix">s</span>
                                        </div>
                                        @error('max_time_drift_seconds')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                                        <small class="text-secondary d-block mt-1">Diferencia máxima en segundos tolerada respecto al reloj del servidor.</small>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Barra Inferior de Acción y Guardado --}}
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mt-4 settings-action-bar">
        <span class="small settings-helper-text">
            @if($row->updated_at)
                <i class="bi bi-clock-history me-1 text-primary"></i> <strong>Último cambio:</strong> {{ $row->updated_at->setTimezone(tenant_timezone())->format('d/m/Y h:i a') }}
            @endif
        </span>
        <button type="submit" class="btn btn-primary d-inline-flex align-items-center px-4 py-2.5 fw-bold shadow-sm" style="border-radius: 9px; box-shadow: 0 4px 14px rgba(var(--brand-rgb), 0.28);">
            <i class="bi bi-floppy2-fill me-2"></i> Guardar configuración
        </button>
    </div>
</form>
@endsection

@section('styles')
<style>
    .brand-preview-bar { background: var(--p-navbar); color: var(--p-navbar-text, #fff); }
    .brand-preview-active { background: color-mix(in srgb, var(--p-primary) 14%, transparent); color: var(--p-primary); font-weight: 600; }
    [data-bs-theme="dark"] .brand-preview-active { color: color-mix(in srgb, var(--p-primary) 55%, #fff); }
    .brand-preview-btn { background: var(--p-primary); color: var(--p-primary-text, #fff); border: 0; }
    .brand-preview-accent { background: var(--p-accent); color: var(--p-accent-text, #fff); }
    .brand-preview-secondary { background: var(--p-secondary); color: var(--p-secondary-text, #fff); }
</style>
@endsection

@section('scripts')
<script>
(function () {
    const preview = document.getElementById('brandPreview');
    const HEX = /^#[0-9A-Fa-f]{6}$/;

    function contrastText(hex) {
        const c = [1, 3, 5].map((i) => parseInt(hex.substr(i, 2), 16) / 255)
            .map((v) => v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4));
        const l = 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
        return 1.05 / (l + 0.05) >= (l + 0.05) / 0.0589 ? '#FFFFFF' : '#0F172A';
    }

    const vars = { primary_color: 'primary', secondary_color: 'secondary', accent_color: 'accent', navbar_color: 'navbar' };
    function paint(key, hex) {
        if (!HEX.test(hex)) return;
        preview.style.setProperty('--p-' + vars[key], hex);
        preview.style.setProperty('--p-' + vars[key] + '-text', contrastText(hex));
    }

    {{-- Selector de color ↔ texto hexadecimal --}}
    document.querySelectorAll('[data-color-picker]').forEach((picker) => {
        const key = picker.dataset.colorPicker;
        const text = document.querySelector('[data-color-text="' + key + '"]');
        paint(key, picker.value);
        picker.addEventListener('input', () => { text.value = picker.value.toUpperCase(); paint(key, picker.value); });
        text.addEventListener('input', () => {
            const v = text.value.trim();
            if (HEX.test(v)) { picker.value = v.toLowerCase(); paint(key, v); }
            if (v === '') { paint(key, text.placeholder); picker.value = text.placeholder.toLowerCase(); }
        });
    });

    {{-- Vista previa de imágenes antes de subirlas --}}
    document.querySelectorAll('[data-preview-input]').forEach((input) => {
        const key = input.dataset.previewInput;
        input.addEventListener('change', () => {
            const file = input.files && input.files[0];
            if (!file || !file.type.startsWith('image/')) return;
            const reader = new FileReader();
            reader.onload = (e) => {
                document.querySelectorAll('[data-preview="' + key + '"]').forEach((img) => { img.src = e.target.result; img.classList.remove('d-none'); });
                document.querySelectorAll('[data-empty="' + key + '"]').forEach((el) => el.classList.add('d-none'));
            };
            reader.readAsDataURL(file);
        });
    });

    const tradeName = document.getElementById('trade_name');
    if (tradeName) {
        tradeName.addEventListener('input', () => {
            document.querySelector('[data-preview-name]').textContent = tradeName.value.trim() || tradeName.placeholder;
        });
    }

    {{-- Las horas sin conexión solo aplican si la marcación sin conexión está activa --}}
    const offline = document.getElementById('offline_mode_enabled');
    const hoursRow = document.getElementById('offlineHoursRow');
    if (offline && hoursRow) {
        const offlineOn = () => offline.type === 'checkbox'
            ? offline.checked
            : (offline.value === '' ? offline.dataset.inherited === '1' : offline.value === '1');
        const syncOffline = () => hoursRow.classList.toggle('opacity-50', !offlineOn());
        offline.addEventListener('change', syncOffline);
        syncOffline();
    }

    {{-- Recordar pestaña activa internamente sin mutar la URL en la barra del navegador --}}
    document.querySelectorAll('[data-bs-toggle="tab"]').forEach((btn) => {
        btn.addEventListener('shown.bs.tab', () => {
            const activeTabInput = document.getElementById('activeTab');
            if (activeTabInput) activeTabInput.value = btn.dataset.tab;
        });
    });
})();
</script>
@endsection
