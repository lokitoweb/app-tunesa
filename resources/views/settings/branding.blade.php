@extends('layouts.app')

@section('title', 'Marca y app')

@section('content')
@php
    // Pestaña a abrir: la pedida, o la del primer campo con error
    $fieldTabs = [
        'app'           => ['api_base_url', 'min_supported_version', 'latest_version', 'force_update', 'selfie_required', 'face_detection_required', 'offline_mode_enabled', 'offline_max_hours', 'max_gps_accuracy_meters'],
        'reglas'        => ['default_grace_minutes', 'lunch_minutes', 'lunch_paid', 'default_geofence_radius', 'geofence_policy', 'mock_location_policy', 'max_time_drift_seconds'],
        'integraciones' => ['webhook_url', 'webhook_secret', 'maps_api_key', 'notification_endpoint', 'geocoding_provider', 'fcm_enabled', 'fcm_service_account_json'],
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

<div class="page-header">
    <div>
        <h1>Marca y app</h1>
        <p>Logo, colores, comportamiento de la app móvil y reglas de marcación de {{ rtrim($ownerName, '.') }}.</p>
    </div>
    <a href="{{ route('settings.mobile') }}" class="btn btn-outline-secondary"><i class="bi bi-phone me-1"></i> Módulos visibles en la app</a>
</div>

@if($isGlobal)
    <div class="alert alert-info d-flex align-items-start gap-2" role="status">
        <i class="bi bi-globe2"></i>
        <div>
            <strong>Valores por defecto de la plataforma.</strong>
            Lo que guardes aquí lo heredan todas las empresas que no definieron su propio valor (y la app cuando aún no eligió empresa).
            Para editar una empresa en particular, elígela en el selector superior.
        </div>
    </div>
@endif

<form method="POST" action="{{ route('settings.branding.update') }}" enctype="multipart/form-data" id="brandingForm" novalidate>
    @csrf @method('PUT')
    <input type="hidden" name="tab" id="activeTab" value="{{ $tab }}">

    <ul class="nav nav-tabs mb-3" role="tablist">
        @foreach(['marca' => ['palette', 'Marca y apariencia'], 'app' => ['phone', 'App móvil'], 'reglas' => ['sliders', 'Reglas operativas'], 'integraciones' => ['plug', 'Integraciones']] as $key => [$icon, $label])
            <li class="nav-item" role="presentation">
                <button class="nav-link {{ $tab === $key ? 'active' : '' }}" id="tab-{{ $key }}" data-bs-toggle="tab" data-bs-target="#pane-{{ $key }}" data-tab="{{ $key }}" type="button" role="tab" aria-controls="pane-{{ $key }}" aria-selected="{{ $tab === $key ? 'true' : 'false' }}">
                    <i class="bi bi-{{ $icon }} me-1"></i>{{ $label }}
                </button>
            </li>
        @endforeach
    </ul>

    <div class="tab-content">
        {{-- ============================ MARCA ============================ --}}
        <div class="tab-pane fade {{ $tab === 'marca' ? 'show active' : '' }}" id="pane-marca" role="tabpanel" aria-labelledby="tab-marca" tabindex="0">
            <div class="row g-3">
                <div class="col-12 col-xl-7 d-flex flex-column gap-3">
                    <div class="card border-0">
                        <div class="card-header border-0 pt-3"><h2 class="h6 fw-semibold mb-0">Identidad</h2></div>
                        <div class="card-body">
                            <label for="trade_name" class="form-label">Nombre comercial</label>
                            <input type="text" id="trade_name" name="trade_name" maxlength="200" value="{{ $val('trade_name') }}"
                                   class="form-control @error('trade_name') is-invalid @enderror"
                                   placeholder="{{ $isGlobal ? 'TuneSA' : $tenant->company_name }}">
                            <div class="form-text">Se muestra en el título del panel, el login y la app. Vacío: {{ $isGlobal ? 'TuneSA' : 'la razón social de la empresa' }}.</div>
                        </div>
                    </div>

                    <div class="card border-0">
                        <div class="card-header border-0 pt-3"><h2 class="h6 fw-semibold mb-0">Imágenes</h2></div>
                        <div class="card-body">
                            <div class="row g-3">
                                @foreach($images as $input => [$label, $url, $accept, $help])
                                    <div class="col-12 col-md-6">
                                        <label for="{{ $input }}" class="form-label">{{ $label }}</label>
                                        <div class="border rounded-3 bg-body-tertiary d-flex align-items-center justify-content-center mb-2 {{ $input === 'logo_dark' ? 'bg-dark' : '' }}" style="height: 88px;">
                                            <img src="{{ $url }}" alt="Vista previa: {{ $label }}" data-preview="{{ $input }}" class="{{ $url ? '' : 'd-none' }}" style="max-height: 72px; max-width: 90%; object-fit: contain;">
                                            <span class="small text-body-secondary {{ $url ? 'd-none' : '' }}" data-empty="{{ $input }}">Sin imagen</span>
                                        </div>
                                        <input type="file" id="{{ $input }}" name="{{ $input }}" accept="{{ $accept }}" data-preview-input="{{ $input }}"
                                               class="form-control form-control-sm @error($input) is-invalid @enderror">
                                        <div class="form-text">{{ $help }}</div>
                                        @if($url)
                                            <div class="form-check mt-1">
                                                <input class="form-check-input" type="checkbox" name="remove_{{ $input }}" value="1" id="remove_{{ $input }}">
                                                <label class="form-check-label small" for="remove_{{ $input }}">Quitar</label>
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    <div class="card border-0">
                        <div class="card-header border-0 pt-3">
                            <h2 class="h6 fw-semibold mb-0">Datos de contacto</h2>
                            <small class="text-body-secondary">La app los muestra en "Ayuda". Vacío: {{ $isGlobal ? 'sin dato' : 'los datos registrados de la empresa' }}.</small>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-sm-6">
                                    <label for="contact_email" class="form-label">Correo</label>
                                    <input type="email" id="contact_email" name="contact_email" maxlength="255" value="{{ $val('contact_email') }}" placeholder="{{ $tenant?->email }}" class="form-control @error('contact_email') is-invalid @enderror">
                                </div>
                                <div class="col-sm-6">
                                    <label for="contact_phone" class="form-label">Teléfono</label>
                                    <input type="text" id="contact_phone" name="contact_phone" maxlength="30" value="{{ $val('contact_phone') }}" placeholder="{{ $tenant?->phone }}" class="form-control @error('contact_phone') is-invalid @enderror">
                                </div>
                                <div class="col-sm-6">
                                    <label for="website" class="form-label">Sitio web</label>
                                    <input type="url" id="website" name="website" maxlength="255" value="{{ $val('website') }}" placeholder="https://www.empresa.com" class="form-control @error('website') is-invalid @enderror">
                                </div>
                                <div class="col-sm-6">
                                    <label for="address" class="form-label">Dirección</label>
                                    <input type="text" id="address" name="address" maxlength="500" value="{{ $val('address') }}" placeholder="{{ $tenant?->address }}" class="form-control @error('address') is-invalid @enderror">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-xl-5 d-flex flex-column gap-3">
                    <div class="card border-0">
                        <div class="card-header border-0 pt-3"><h2 class="h6 fw-semibold mb-0">Colores</h2></div>
                        <div class="card-body">
                            @foreach($colors as $key => [$label, $help])
                                @php
                                    $required = ! $isGlobal && in_array($key, ['primary_color', 'secondary_color'], true);
                                    $current  = $val($key);
                                @endphp
                                <div class="mb-3">
                                    <label for="{{ $key }}" class="form-label mb-1">{{ $label }}</label>
                                    <div class="input-group">
                                        <input type="color" class="form-control form-control-color flex-grow-0" style="width: 3.25rem;" value="{{ $previewColor($key) }}" data-color-picker="{{ $key }}" aria-label="Elegir color {{ strtolower($label) }}">
                                        <input type="text" id="{{ $key }}" name="{{ $key }}" value="{{ $current }}" maxlength="7" pattern="#[0-9A-Fa-f]{6}" data-color-text="{{ $key }}"
                                               class="form-control font-monospace text-uppercase @error($key) is-invalid @enderror"
                                               placeholder="{{ $previewColor($key) }}" {{ $required ? 'required' : '' }}>
                                    </div>
                                    <div class="form-text">{{ $help }}@unless($required) Vacío: hereda {{ $previewColor($key) }}.@endunless</div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="card border-0">
                        <div class="card-header border-0 pt-3"><h2 class="h6 fw-semibold mb-0">Vista previa</h2></div>
                        <div class="card-body">
                            <div class="brand-preview border rounded-3 overflow-hidden" id="brandPreview" aria-hidden="true"
                                 style="--p-primary: {{ $effective->primaryColor() }}; --p-secondary: {{ $effective->secondaryColor() }}; --p-accent: {{ $effective->accentColor() }}; --p-navbar: {{ $effective->navbarColor() }};">
                                <div class="d-flex align-items-center gap-2 px-3 py-2 brand-preview-bar">
                                    <img src="{{ $values['logo_url'] }}" alt="" data-preview="logo" class="{{ $values['logo_url'] ? '' : 'd-none' }}" style="height: 22px; max-width: 80px; object-fit: contain;">
                                    <span class="small fw-semibold" data-preview-name>{{ $effective->tradeName() }}</span>
                                </div>
                                <div class="d-flex" style="min-height: 150px;">
                                    <div class="bg-body-tertiary border-end p-2 small" style="width: 42%;">
                                        <div class="brand-preview-active rounded-2 px-2 py-1 mb-1"><i class="bi bi-speedometer2 me-1"></i>Dashboard</div>
                                        <div class="px-2 py-1 text-body-secondary"><i class="bi bi-people me-1"></i>Personal</div>
                                        <div class="px-2 py-1 text-body-secondary"><i class="bi bi-clock-history me-1"></i>Marcaciones</div>
                                    </div>
                                    <div class="p-3 flex-grow-1 d-flex flex-column gap-2 align-items-start">
                                        <span class="badge rounded-pill brand-preview-accent">Puntual</span>
                                        <span class="badge rounded-pill brand-preview-secondary">Turno mañana</span>
                                        <button type="button" class="btn btn-sm brand-preview-btn mt-auto" tabindex="-1">Marcar entrada</button>
                                    </div>
                                </div>
                            </div>
                            <p class="small text-body-secondary mt-2 mb-0">El color del texto sobre cada fondo se elige solo (blanco u oscuro) para que siempre se lea.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ============================ APP MÓVIL ============================ --}}
        <div class="tab-pane fade {{ $tab === 'app' ? 'show active' : '' }}" id="pane-app" role="tabpanel" aria-labelledby="tab-app" tabindex="0">
            <div class="row g-3">
                <div class="col-12 col-xl-6 d-flex flex-column gap-3">
                    <div class="card border-0">
                        <div class="card-header border-0 pt-3"><h2 class="h6 fw-semibold mb-0">Conexión</h2></div>
                        <div class="card-body">
                            <label for="api_base_url" class="form-label">URL del servidor (API)</label>
                            <input type="url" id="api_base_url" name="api_base_url" maxlength="255" value="{{ $val('api_base_url') }}"
                                   class="form-control font-monospace @error('api_base_url') is-invalid @enderror"
                                   placeholder="{{ $inh('api_base_url') ?: 'https://xxxx.trycloudflare.com' }}">
                            <div class="form-text">
                                Solo <code>https://</code> y sin <code>/api</code> al final: la app lo agrega. Si publicas el servidor con Cloudflare Tunnel
                                usa la URL que te da, por ejemplo <code>https://xxxx.trycloudflare.com</code>. Vacío: {{ $isGlobal ? 'la URL incluida en la app' : 'la URL de la plataforma' }}.
                            </div>
                        </div>
                    </div>

                    <div class="card border-0">
                        <div class="card-header border-0 pt-3"><h2 class="h6 fw-semibold mb-0">Versiones</h2></div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-sm-6">
                                    <label for="min_supported_version" class="form-label">Versión mínima</label>
                                    <input type="text" id="min_supported_version" name="min_supported_version" maxlength="40" value="{{ $val('min_supported_version') }}" placeholder="{{ $inh('min_supported_version') }}" class="form-control font-monospace @error('min_supported_version') is-invalid @enderror">
                                </div>
                                <div class="col-sm-6">
                                    <label for="latest_version" class="form-label">Última versión publicada</label>
                                    <input type="text" id="latest_version" name="latest_version" maxlength="40" value="{{ $val('latest_version') }}" placeholder="{{ $inh('latest_version') ?: '1.4.0' }}" class="form-control font-monospace @error('latest_version') is-invalid @enderror">
                                </div>
                            </div>
                            @include('settings.partials.bool-setting', ['flag' => 'force_update', 'class' => 'mt-3',
                                'label' => 'Obligar a actualizar',
                                'desc'  => 'Las apps con una versión menor a la mínima no podrán marcar hasta actualizar.'])
                        </div>
                    </div>
                </div>

                <div class="col-12 col-xl-6">
                    <div class="card border-0">
                        <div class="card-header border-0 pt-3"><h2 class="h6 fw-semibold mb-0">Marcación desde la app</h2></div>
                        <ul class="list-group list-group-flush">
                            @foreach([
                                'selfie_required'         => ['Selfie obligatoria', 'Pide una foto al marcar entrada y salida.'],
                                'face_detection_required' => ['Detección de rostro', 'La app verifica que haya un rostro en la selfie antes de enviarla.'],
                                'offline_mode_enabled'    => ['Marcación sin conexión', 'Sin internet la app guarda la marcación y la envía al recuperar la señal.'],
                            ] as $flag => [$label, $desc])
                                <li class="list-group-item py-3">
                                    @include('settings.partials.bool-setting', ['flag' => $flag, 'label' => $label, 'desc' => $desc])
                                </li>
                            @endforeach
                            <li class="list-group-item py-3" id="offlineHoursRow">
                                <label for="offline_max_hours" class="form-label">Antigüedad máxima de una marcación sin conexión</label>
                                <div class="input-group" style="max-width: 200px;">
                                    <input type="number" id="offline_max_hours" name="offline_max_hours" min="1" max="72" value="{{ $val('offline_max_hours') }}" placeholder="{{ $inh('offline_max_hours') }}" class="form-control @error('offline_max_hours') is-invalid @enderror">
                                    <span class="input-group-text">horas</span>
                                </div>
                                <div class="form-text">Entre 1 y 72. Pasado ese plazo RRHH debe registrarla a mano.</div>
                            </li>
                            <li class="list-group-item py-3">
                                <label for="max_gps_accuracy_meters" class="form-label">Precisión GPS mínima aceptada</label>
                                <div class="input-group" style="max-width: 200px;">
                                    <input type="number" id="max_gps_accuracy_meters" name="max_gps_accuracy_meters" min="10" max="1000" value="{{ $val('max_gps_accuracy_meters') }}" placeholder="{{ $inherited->maxGpsAccuracy() }}" class="form-control @error('max_gps_accuracy_meters') is-invalid @enderror">
                                    <span class="input-group-text">m</span>
                                </div>
                                <div class="form-text">Si el GPS del celular tiene un error mayor, la marcación se rechaza.</div>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        {{-- ============================ REGLAS ============================ --}}
        <div class="tab-pane fade {{ $tab === 'reglas' ? 'show active' : '' }}" id="pane-reglas" role="tabpanel" aria-labelledby="tab-reglas" tabindex="0">
            <div class="row g-3">
                <div class="col-12 col-xl-6">
                    <div class="card border-0 h-100">
                        <div class="card-header border-0 pt-3">
                            <h2 class="h6 fw-semibold mb-0">Valores por defecto</h2>
                            <small class="text-body-secondary">Se proponen al crear turnos y sucursales; cada uno puede cambiarlos.</small>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-sm-6">
                                    <label for="default_grace_minutes" class="form-label">Tolerancia de tardanza</label>
                                    <div class="input-group">
                                        <input type="number" id="default_grace_minutes" name="default_grace_minutes" min="0" max="240" value="{{ $val('default_grace_minutes') }}" placeholder="{{ $inh('default_grace_minutes') }}" class="form-control @error('default_grace_minutes') is-invalid @enderror">
                                        <span class="input-group-text">min</span>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <label for="lunch_minutes" class="form-label">Refrigerio</label>
                                    <div class="input-group">
                                        <input type="number" id="lunch_minutes" name="lunch_minutes" min="0" max="480" value="{{ $val('lunch_minutes') }}" placeholder="{{ $inh('lunch_minutes') }}" class="form-control @error('lunch_minutes') is-invalid @enderror">
                                        <span class="input-group-text">min</span>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <label for="default_geofence_radius" class="form-label">Radio de geocerca</label>
                                    <div class="input-group">
                                        <input type="number" id="default_geofence_radius" name="default_geofence_radius" min="10" max="5000" value="{{ $val('default_geofence_radius') }}" placeholder="{{ $inh('default_geofence_radius') }}" class="form-control @error('default_geofence_radius') is-invalid @enderror">
                                        <span class="input-group-text">m</span>
                                    </div>
                                </div>
                            </div>
                            @include('settings.partials.bool-setting', ['flag' => 'lunch_paid', 'class' => 'mt-3',
                                'label' => 'Refrigerio pagado',
                                'desc'  => 'Si está activo, el descanso registrado no se descuenta de las horas trabajadas al marcar la salida.'])
                            <p class="small text-body-secondary mt-3 mb-0">Campos vacíos: se usa el valor de {{ $isGlobal ? 'fábrica' : 'la plataforma' }} (indicado en gris).@unless($isGlobal) Las opciones Sí/No en "Heredar" siguen a la plataforma aunque cambie después.@endunless</p>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-xl-6">
                    <div class="card border-0 h-100">
                        <div class="card-header border-0 pt-3">
                            <h2 class="h6 fw-semibold mb-0">Marcación fuera de geocerca</h2>
                            <small class="text-body-secondary">Qué pasa si alguien marca lejos de su sucursal (radio configurado en Sucursales).</small>
                        </div>
                        <div class="card-body">
                            @if($isGlobal)
                                <p class="small text-body-secondary mb-0">Esta política es propia de cada empresa (por defecto: rechazar la marcación). Elige una empresa en el selector superior para cambiarla.</p>
                            @else
                                @foreach($policies as $policy => [$label, $desc])
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="radio" name="geofence_policy" value="{{ $policy }}" id="geo_{{ $policy }}" @checked(old('geofence_policy', $values['geofence_policy']) === $policy)>
                                        <label class="form-check-label" for="geo_{{ $policy }}">
                                            <span class="d-block fw-semibold">{{ $label }}</span>
                                            <span class="d-block small text-body-secondary">{{ $desc }}</span>
                                        </label>
                                    </div>
                                @endforeach
                                <p class="small text-body-secondary mb-0 mt-2">"Rechazar" es el modo estricto que recibe la app.</p>
                            @endif
                        </div>
                    </div>
                </div>

                @if($has('mock_location_policy') || $has('max_time_drift_seconds'))
                <div class="col-12">
                    <div class="card border-0">
                        <div class="card-header border-0 pt-3">
                            <h2 class="h6 fw-semibold mb-0">Antifraude en la marcación</h2>
                            <small class="text-body-secondary">Ubicaciones simuladas (apps de GPS falso) y celulares con la hora cambiada.</small>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                @if($has('mock_location_policy'))
                                    @php
                                        $mockLabels = ['reject' => ['Bloquear', 'La marcación con ubicación simulada se rechaza y el colaborador ve el motivo.'],
                                                       'flag'   => ['Marcar como sospechosa', 'Se guarda, pero queda marcada para que RRHH la revise.']];
                                        $mockInherited = $inh('mock_location_policy') ?: 'reject';
                                        $mockCurrent = (string) ($stored('mock_location_policy') ?? ($isGlobal ? $mockInherited : ''));
                                    @endphp
                                    <fieldset class="col-md-7">
                                        <legend class="form-label fs-6 mb-2">Ubicación simulada detectada</legend>
                                        @unless($isGlobal)
                                            <div class="form-check mb-2">
                                                <input class="form-check-input" type="radio" name="mock_location_policy" value="" id="mock_inherit" @checked($mockCurrent === '')>
                                                <label class="form-check-label" for="mock_inherit">
                                                    <span class="d-block fw-semibold">Heredar de la plataforma</span>
                                                    <span class="d-block small text-body-secondary">Actual: {{ $mockLabels[$mockInherited][0] ?? $mockInherited }}.</span>
                                                </label>
                                            </div>
                                        @endunless
                                        @foreach($mockLabels as $policy => [$label, $desc])
                                            <div class="form-check mb-2">
                                                <input class="form-check-input @error('mock_location_policy') is-invalid @enderror" type="radio" name="mock_location_policy" value="{{ $policy }}" id="mock_{{ $policy }}" @checked($mockCurrent === $policy)>
                                                <label class="form-check-label" for="mock_{{ $policy }}">
                                                    <span class="d-block fw-semibold">{{ $label }}</span>
                                                    <span class="d-block small text-body-secondary">{{ $desc }}</span>
                                                </label>
                                            </div>
                                        @endforeach
                                        @error('mock_location_policy')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                                    </fieldset>
                                @endif
                                @if($has('max_time_drift_seconds'))
                                    <div class="col-md-5">
                                        <label for="max_time_drift_seconds" class="form-label">Diferencia máxima de hora del celular</label>
                                        <div class="input-group" style="max-width: 220px;">
                                            <input type="number" id="max_time_drift_seconds" name="max_time_drift_seconds" min="0" max="86400" step="1"
                                                   value="{{ $stored('max_time_drift_seconds') }}" placeholder="{{ $inh('max_time_drift_seconds') }}"
                                                   class="form-control @error('max_time_drift_seconds') is-invalid @enderror">
                                            <span class="input-group-text">s</span>
                                        </div>
                                        @error('max_time_drift_seconds')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                                        <div class="form-text">Si la hora del teléfono difiere de la del servidor en más de estos segundos, se considera que la hora del celular fue cambiada. Vacío: {{ $isGlobal ? 'el valor de fábrica' : 'el de la plataforma' }}.</div>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
                @endif
            </div>
        </div>

        {{-- ============================ INTEGRACIONES ============================ --}}
        <div class="tab-pane fade {{ $tab === 'integraciones' ? 'show active' : '' }}" id="pane-integraciones" role="tabpanel" aria-labelledby="tab-integraciones" tabindex="0">
            <div class="row g-3">
                {{-- Webhook y endpoint de notificaciones --}}
                <div class="col-12">
                    <div class="card border-0">
                        <div class="card-header border-0 pt-3">
                            <h2 class="h6 fw-semibold mb-0">Webhook y notificaciones</h2>
                            <small class="text-body-secondary">Se guardan cifradas. El secreto solo lo ven quienes administran {{ $isGlobal ? 'la plataforma' : 'la empresa' }} y nunca se envía a la app.</small>
                            @if($isGlobal)
                                <small class="d-block text-body-secondary">El webhook y el endpoint de notificaciones son propios de cada empresa: en la vista global solo sirven para probar la conexión, no se heredan ni reciben eventos.</small>
                            @endif
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="webhook_url" class="form-label">URL del webhook</label>
                                    @php $webhookUrl = $val('webhook_url') ?: $receiverUrls['webhook_url']; @endphp
                                    <input type="url" id="webhook_url" name="webhook_url" maxlength="255" value="{{ $webhookUrl }}" placeholder="https://erp.empresa.com/webhooks/asistencia" class="form-control @error('webhook_url') is-invalid @enderror">
                                    @error('webhook_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    <div class="form-text">Recibe un POST JSON por cada entrada, inicio y fin de descanso y salida, y por cada cambio de estado de un permiso (para ERP o planilla).</div>
                                    @if($webhookUrl === $receiverUrls['webhook_url'])
                                        <div class="form-text text-info-emphasis"><i class="bi bi-info-circle me-1"></i>Precargada con el receptor de la plataforma, que verifica la firma. Reemplázala por la URL de tu sistema cuando la tengas.</div>
                                    @endif
                                    <button type="button" class="btn btn-sm btn-outline-primary mt-2" data-integration-test="{{ route('settings.branding.test-webhook') }}" data-url-input="webhook_url" data-result="webhookResult">
                                        <i class="bi bi-send me-1"></i>Probar Webhook Ahora
                                    </button>
                                    <div id="webhookResult" class="mt-2" aria-live="polite"></div>
                                </div>
                                <div class="col-md-6">
                                    <label for="webhook_secret" class="form-label">Secreto del webhook</label>
                                    <div class="input-group">
                                        <input type="password" id="webhook_secret" name="webhook_secret" maxlength="255" minlength="16" autocomplete="off" spellcheck="false" required
                                               value="{{ old('webhook_secret', $webhookSecret) }}" class="form-control font-monospace @error('webhook_secret') is-invalid @enderror">
                                        <button type="button" class="btn btn-outline-secondary" id="webhookSecretToggle" aria-pressed="false" title="Mostrar el secreto" aria-label="Mostrar el secreto">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        <button type="button" class="btn btn-outline-secondary" id="webhookSecretCopy" title="Copiar el secreto" aria-label="Copiar el secreto">
                                            <i class="bi bi-clipboard"></i>
                                        </button>
                                        @error('webhook_secret')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                    <div class="form-text">Firma cada envío en la cabecera <code>X-Tunesa-Signature: sha256=…</code> (HMAC-SHA256 del cuerpo). Configura este mismo valor en tu sistema para validar la firma.</div>
                                    <button type="button" class="btn btn-sm btn-outline-warning mt-2" id="webhookSecretRegenerate" data-url="{{ route('settings.branding.regenerate-webhook-secret') }}">
                                        <i class="bi bi-arrow-repeat me-1"></i>Regenerar secreto
                                    </button>
                                    <div id="webhookSecretResult" class="mt-2 small" aria-live="polite"></div>
                                </div>
                                <div class="col-md-6">
                                    <label for="notification_endpoint" class="form-label">Endpoint de notificaciones</label>
                                    @php $notificationUrl = $val('notification_endpoint') ?: $receiverUrls['notification_endpoint']; @endphp
                                    <input type="url" id="notification_endpoint" name="notification_endpoint" maxlength="255" value="{{ $notificationUrl }}" placeholder="https://notificaciones.empresa.com/push" class="form-control @error('notification_endpoint') is-invalid @enderror">
                                    @error('notification_endpoint')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    <div class="form-text">Recibe un POST JSON por cada aviso in-app creado para un usuario de la empresa, con la misma firma que el webhook.</div>
                                    @if($notificationUrl === $receiverUrls['notification_endpoint'])
                                        <div class="form-text text-info-emphasis"><i class="bi bi-info-circle me-1"></i>Precargada con el receptor de la plataforma. Reemplázala por la URL de tu sistema cuando la tengas.</div>
                                    @endif
                                    <button type="button" class="btn btn-sm btn-outline-primary mt-2" data-integration-test="{{ route('settings.branding.test-notification') }}" data-url-input="notification_endpoint" data-result="notificationResult">
                                        <i class="bi bi-bell me-1"></i>Enviar Notificación de Prueba
                                    </button>
                                    <div id="notificationResult" class="mt-2" aria-live="polite"></div>
                                </div>
                                <div class="col-md-6">
                                    <p class="small text-body-secondary mb-0 mt-md-4">
                                        Las pruebas se envían al instante a la URL escrita arriba (aunque no la hayas guardado) con el evento
                                        <code>ping</code> o <code>notification.test</code>, firmadas con el secreto del webhook.
                                        Los eventos reales se envían en segundo plano y se reintentan hasta 5 veces si fallan.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Geocodificación --}}
                <div class="col-12 col-xl-6">
                    <div class="card border-0 h-100">
                        <div class="card-header border-0 pt-3">
                            <h2 class="h6 fw-semibold mb-0">Geocodificación</h2>
                            <small class="text-body-secondary">Convierte coordenadas en direcciones (y al revés) en el formulario de sucursales.</small>
                        </div>
                        <div class="card-body">
                            @if($has('geocoding_provider'))
                                @php
                                    $providers = ['nominatim' => 'OpenStreetMap / Nominatim — gratis', 'google' => 'Google Maps Geocoding'];
                                    $providerInherited = $inh('geocoding_provider') ?: 'nominatim';
                                    $providerCurrent = (string) ($stored('geocoding_provider') ?? ($isGlobal ? $providerInherited : ''));
                                @endphp
                                <div class="mb-3">
                                    <label for="geocoding_provider" class="form-label">Proveedor</label>
                                    <select id="geocoding_provider" name="geocoding_provider" class="form-select @error('geocoding_provider') is-invalid @enderror" data-inherited="{{ $providerInherited }}">
                                        @unless($isGlobal)
                                            <option value="" @selected($providerCurrent === '')>Heredar de la plataforma (actual: {{ $providers[$providerInherited] ?? $providerInherited }})</option>
                                        @endunless
                                        @foreach($providers as $key => $label)
                                            <option value="{{ $key }}" @selected($providerCurrent === $key)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    @error('geocoding_provider')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                            @endif
                            <div class="mb-3">
                                <label for="maps_api_key" class="form-label">Clave de API de Google Maps</label>
                                <input type="password" id="maps_api_key" name="maps_api_key" maxlength="255" autocomplete="new-password" value=""
                                       placeholder="{{ $values['maps_api_key'] ?? ($isGlobal ? 'Sin clave' : ($hasMapsKey ? 'Usa la clave de la plataforma' : 'Sin clave')) }}"
                                       class="form-control font-monospace @error('maps_api_key') is-invalid @enderror" data-has-key="{{ $hasMapsKey ? '1' : '0' }}">
                                <div class="form-text">Necesaria solo con Google (API "Geocoding" habilitada). Dejar vacío para no cambiar.</div>
                                <div class="form-text text-warning-emphasis d-none" id="mapsKeyFallback">
                                    <i class="bi bi-info-circle me-1"></i>Sin clave de Google se usará OpenStreetMap automáticamente.
                                </div>
                                @if($values['has_maps_api_key'])
                                    <div class="form-check mt-1">
                                        <input class="form-check-input" type="checkbox" name="clear_maps_api_key" value="1" id="clear_maps_api_key">
                                        <label class="form-check-label small" for="clear_maps_api_key">Borrar la clave guardada</label>
                                    </div>
                                @endif
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary" id="geocodeTest"
                                    data-url="{{ url('/geocode/reverse') }}" data-lat="{{ $geoProbe['lat'] }}" data-lng="{{ $geoProbe['lng'] }}">
                                <i class="bi bi-geo-alt me-1"></i>Probar geocodificación
                            </button>
                            <span class="small text-body-secondary ms-1">con {{ $geoProbe['label'] }} ({{ number_format($geoProbe['lat'], 5) }}, {{ number_format($geoProbe['lng'], 5) }})</span>
                            <div id="geocodeResult" class="mt-2" aria-live="polite"></div>
                            <p class="small text-body-secondary mt-2 mb-0">La prueba usa la configuración guardada: guarda antes si cambiaste el proveedor o la clave.</p>
                        </div>
                    </div>
                </div>

                {{-- Notificaciones push (Firebase Cloud Messaging) --}}
                @if($has('fcm_enabled') || $has('fcm_service_account_json'))
                <div class="col-12 col-xl-6">
                    <div class="card border-0 h-100">
                        <div class="card-header border-0 pt-3">
                            <h2 class="h6 fw-semibold mb-0">Notificaciones push</h2>
                            <small class="text-body-secondary">Avisos al celular del colaborador con Firebase Cloud Messaging.</small>
                        </div>
                        <div class="card-body">
                            @if($has('fcm_enabled'))
                                @include('settings.partials.bool-setting', ['flag' => 'fcm_enabled', 'class' => 'mb-3',
                                    'label' => 'Enviar notificaciones push',
                                    'desc'  => 'Además del aviso dentro de la app, se envía una notificación al teléfono.'])
                            @endif

                            @if($has('fcm_service_account_json'))
                                <div class="mb-3">
                                    <span class="form-label d-block">Cuenta de servicio de Firebase</span>
                                    @if($fcm['configured'])
                                        <span class="badge text-bg-success"><i class="bi bi-check-circle me-1"></i>Configurado{{ $isGlobal ? '' : ' por la plataforma' }}</span>
                                        @if($isGlobal && $fcm['project_id'])
                                            <span class="small text-body-secondary ms-1">Proyecto <code>{{ $fcm['project_id'] }}</code> · {{ $fcm['client_email'] }}</span>
                                        @endif
                                    @else
                                        <span class="badge text-bg-secondary">No configurado</span>
                                    @endif
                                    @if($isGlobal)
                                        <textarea id="fcm_service_account_json" name="fcm_service_account_json" rows="4" spellcheck="false" autocomplete="off"
                                                  class="form-control font-monospace small mt-2 @error('fcm_service_account_json') is-invalid @enderror"
                                                  placeholder='{"type": "service_account", "project_id": "…", "private_key": "…", "client_email": "…"}'></textarea>
                                        @error('fcm_service_account_json')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                        <input type="file" id="fcm_service_account_file" name="fcm_service_account_file" accept="application/json,.json" class="form-control form-control-sm mt-2" aria-label="Subir el archivo JSON de la cuenta de servicio">
                                        <div class="form-text">Pega el JSON o sube el archivo que descargas en Firebase › Configuración del proyecto › Cuentas de servicio. Debe incluir <code>project_id</code>, <code>client_email</code> y <code>private_key</code>. Se guarda cifrado; dejar vacío para no cambiar.</div>
                                        @if($fcm['configured'])
                                            <div class="form-check mt-1">
                                                <input class="form-check-input" type="checkbox" name="clear_fcm_service_account_json" value="1" id="clear_fcm_service_account_json">
                                                <label class="form-check-label small" for="clear_fcm_service_account_json">Borrar la cuenta de servicio guardada</label>
                                            </div>
                                        @endif
                                    @else
                                        <div class="form-text">La cuenta de Firebase la administra la plataforma para todas las empresas.</div>
                                    @endif
                                </div>
                            @endif

                            <label for="push_email" class="form-label">Probar push</label>
                            <input type="email" id="push_email" maxlength="255" placeholder="{{ auth()->user()->email }}" class="form-control mb-1">
                            <div class="form-text mb-2">Correo de un usuario que ya inició sesión en la app (vacío: tu propio celular). Se envía aunque el push esté desactivado.</div>
                            <button type="button" class="btn btn-sm btn-outline-primary" data-integration-test="{{ route('settings.branding.test-push') }}" data-email-input="push_email" data-result="pushResult">
                                <i class="bi bi-phone-vibrate me-1"></i>Enviar push de prueba
                            </button>
                            <div id="pushResult" class="mt-2" aria-live="polite"></div>
                        </div>
                    </div>
                </div>
                @endif

                {{-- Historial de envíos --}}
                <div class="col-12">
                    <div class="card border-0">
                        <div class="card-header border-0 pt-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <div>
                                <h2 class="h6 fw-semibold mb-0">Últimos 20 envíos</h2>
                                <small class="text-body-secondary">{{ $isGlobal ? 'Pruebas hechas desde la vista global.' : 'Webhook y endpoint de notificaciones de ' . rtrim($tenant->company_name, '.') . '. Se guardan 30 días.' }}</small>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" role="switch" id="deliveriesAuto">
                                    <label class="form-check-label small" for="deliveriesAuto">Auto (10 s)</label>
                                </div>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="deliveriesRefresh" data-url="{{ route('settings.branding.deliveries') }}">
                                    <i class="bi bi-arrow-clockwise me-1"></i>Actualizar
                                </button>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0 small" id="deliveriesTable">
                                <thead>
                                    <tr>
                                        <th>Evento</th><th>Destino</th><th class="text-center">Intento</th><th class="text-center">HTTP</th>
                                        <th class="text-end">Tiempo</th><th>Estado</th><th>Fecha</th><th class="text-end"><span class="visually-hidden">Detalle</span></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr><td colspan="8" class="text-center text-body-secondary py-3">Cargando…</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
        <span class="small text-body-secondary">
            @if($row->updated_at)
                Último cambio: {{ $row->updated_at->setTimezone(tenant_timezone())->format('d/m/Y H:i') }}
            @endif
        </span>
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Guardar cambios</button>
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
    .integration-pre { max-height: 240px; overflow: auto; white-space: pre-wrap; word-break: break-word; }
    /* El detalle de un envío ocupa toda la fila: en pantallas angostas queda fijo al borde
       visible de la tabla con scroll, para leerlo sin desplazarse de lado */
    .delivery-detail { position: sticky; left: .5rem; max-width: calc(100vw - 4rem); }
</style>
@endsection

@section('scripts')
<script>
(function () {
    const preview = document.getElementById('brandPreview');
    const HEX = /^#[0-9A-Fa-f]{6}$/;

    // Texto blanco u oscuro según la luminancia del fondo (igual que el servidor)
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

    // Selector de color ↔ texto hexadecimal
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

    // Vista previa de imágenes antes de subirlas
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
    tradeName.addEventListener('input', () => {
        document.querySelector('[data-preview-name]').textContent = tradeName.value.trim() || tradeName.placeholder;
    });

    // Las horas sin conexión solo aplican si la marcación sin conexión está activa
    const offline = document.getElementById('offline_mode_enabled');
    const hoursRow = document.getElementById('offlineHoursRow');
    // Interruptor (plataforma) o selector Heredar/Sí/No (empresa)
    const offlineOn = () => offline.type === 'checkbox'
        ? offline.checked
        : (offline.value === '' ? offline.dataset.inherited === '1' : offline.value === '1');
    const syncOffline = () => hoursRow.classList.toggle('opacity-50', !offlineOn());
    offline.addEventListener('change', syncOffline);
    syncOffline();

    // ===================== Integraciones: pruebas en vivo e historial =====================
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const httpBadge = (status) => {
        if (!status) return '<span class="badge text-bg-secondary">Sin respuesta</span>';
        const cls = status < 300 ? 'success' : status < 400 ? 'info' : status < 500 ? 'warning' : 'danger';
        return '<span class="badge text-bg-' + cls + '">HTTP ' + esc(status) + '</span>';
    };
    const pretty = (text) => {
        try { return JSON.stringify(JSON.parse(text), null, 2); } catch (e) { return text; }
    };

    // Respuesta {ok, status, ms, body|error} → badge + tiempo + cuerpo
    function renderResult(box, data) {
        const text = data.ok ? data.body : (data.body || '');
        box.innerHTML =
            '<div class="d-flex flex-wrap align-items-center gap-2">' +
                (data.ok ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-x-circle-fill text-danger"></i>') +
                httpBadge(data.status) +
                (data.ms ? '<span class="small text-body-secondary">' + esc(data.ms) + ' ms</span>' : '') +
                (data.signed === false ? '<span class="small text-body-secondary">(sin firma: no hay secreto)</span>' : '') +
            '</div>' +
            (data.error ? '<div class="small text-danger mt-1">' + esc(data.error) + '</div>' : '') +
            (text ? '<pre class="small bg-body-tertiary rounded p-2 mt-1 mb-0 integration-pre">' + esc(pretty(text)) + '</pre>' : '');
    }

    // Secreto del webhook: mostrar/ocultar, copiar y regenerar
    const secretInput = document.getElementById('webhook_secret');
    const secretToggle = document.getElementById('webhookSecretToggle');
    const secretBox = document.getElementById('webhookSecretResult');
    secretToggle?.addEventListener('click', () => {
        const show = secretInput.type === 'password';
        secretInput.type = show ? 'text' : 'password';
        secretToggle.setAttribute('aria-pressed', show ? 'true' : 'false');
        secretToggle.title = show ? 'Ocultar el secreto' : 'Mostrar el secreto';
        secretToggle.setAttribute('aria-label', secretToggle.title);
        secretToggle.innerHTML = show ? '<i class="bi bi-eye-slash"></i>' : '<i class="bi bi-eye"></i>';
    });
    document.getElementById('webhookSecretCopy')?.addEventListener('click', async (e) => {
        const btn = e.currentTarget;
        try {
            await navigator.clipboard.writeText(secretInput.value);
        } catch (_) {
            // Sin permiso de portapapeles (http): copia con la selección
            const type = secretInput.type;
            secretInput.type = 'text';
            secretInput.select();
            document.execCommand('copy');
            secretInput.type = type;
        }
        btn.innerHTML = '<i class="bi bi-clipboard-check text-success"></i>';
        secretBox.innerHTML = '<span class="text-success"><i class="bi bi-check-circle-fill me-1"></i>Secreto copiado.</span>';
        setTimeout(() => { btn.innerHTML = '<i class="bi bi-clipboard"></i>'; }, 1500);
    });
    const regenBtn = document.getElementById('webhookSecretRegenerate');
    regenBtn?.addEventListener('click', async () => {
        if (!confirm('¿Generar un secreto nuevo? Las firmas con el actual dejarán de ser válidas en tu sistema.')) return;
        busy(regenBtn, true);
        try {
            const data = await requestJson(regenBtn.dataset.url, { method: 'POST', body: '{}' });
            secretInput.value = data.secret;
            secretBox.innerHTML = '<span class="text-success"><i class="bi bi-check-circle-fill me-1"></i>' + esc(data.message) + '</span>';
        } catch (e) {
            secretBox.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle-fill me-1"></i>' + esc(e.message) + '</span>';
        } finally {
            busy(regenBtn, false);
        }
    });

    async function requestJson(url, options) {
        const res = await fetch(url, Object.assign({ headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf, 'Content-Type': 'application/json' } }, options));
        let data = null;
        try { data = await res.json(); } catch (e) { /* respuesta no JSON */ }
        if (res.status === 429) throw new Error('Demasiadas pruebas seguidas. Espera un minuto.');
        if (res.status === 404 && !(data && 'ok' in data) && !(data && 'success' in data)) throw new Error('Esta función no está disponible en este servidor.');
        if (!data) throw new Error('Respuesta inesperada del servidor (HTTP ' + res.status + ').');
        if (res.status === 422 && data.errors) throw new Error(Object.values(data.errors).flat().join(' '));
        return data;
    }

    function busy(btn, on) {
        btn.disabled = on;
        if (on) { btn.dataset.html = btn.innerHTML; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Enviando…'; }
        else if (btn.dataset.html) { btn.innerHTML = btn.dataset.html; }
    }

    document.querySelectorAll('[data-integration-test]').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const box = document.getElementById(btn.dataset.result);
            const urlInput = btn.dataset.urlInput ? document.getElementById(btn.dataset.urlInput) : null;
            const secret = document.getElementById('webhook_secret');
            const payload = {};
            if (urlInput && urlInput.value.trim()) payload.url = urlInput.value.trim();
            if (secret && secret.value) payload.secret = secret.value;
            const emailInput = btn.dataset.emailInput ? document.getElementById(btn.dataset.emailInput) : null;
            if (emailInput && emailInput.value.trim()) payload.email = emailInput.value.trim();
            busy(btn, true);
            box.innerHTML = '';
            try {
                renderResult(box, await requestJson(btn.dataset.integrationTest, { method: 'POST', body: JSON.stringify(payload) }));
            } catch (e) {
                renderResult(box, { ok: false, status: null, ms: 0, error: e.message });
            } finally {
                busy(btn, false);
                loadDeliveries();
            }
        });
    });

    // Geocodificación: aviso de respaldo a OpenStreetMap si Google no tiene clave
    const provider = document.getElementById('geocoding_provider');
    const mapsKey = document.getElementById('maps_api_key');
    const fallback = document.getElementById('mapsKeyFallback');
    function syncProvider() {
        if (!provider || !fallback) return;
        const chosen = provider.value || provider.dataset.inherited;
        const cleared = document.getElementById('clear_maps_api_key')?.checked;
        const hasKey = mapsKey.value.trim() !== '' || (mapsKey.dataset.hasKey === '1' && !cleared);
        fallback.classList.toggle('d-none', !(chosen === 'google' && !hasKey));
    }
    provider?.addEventListener('change', syncProvider);
    mapsKey?.addEventListener('input', syncProvider);
    document.getElementById('clear_maps_api_key')?.addEventListener('change', syncProvider);
    syncProvider();

    const geoBtn = document.getElementById('geocodeTest');
    geoBtn?.addEventListener('click', async () => {
        const box = document.getElementById('geocodeResult');
        const url = new URL(geoBtn.dataset.url, window.location.origin);
        url.searchParams.set('lat', geoBtn.dataset.lat);
        url.searchParams.set('lng', geoBtn.dataset.lng);
        busy(geoBtn, true);
        const t0 = performance.now();
        try {
            const data = await requestJson(url, { method: 'GET' });
            const ms = Math.round(performance.now() - t0);
            if (data.success && data.data && data.data.address) {
                box.innerHTML = '<div class="d-flex flex-wrap align-items-center gap-2"><i class="bi bi-check-circle-fill text-success"></i>' +
                    '<span class="badge text-bg-primary">' + esc(data.data.provider === 'google' ? 'Google Maps' : 'OpenStreetMap') + '</span>' +
                    '<span class="small text-body-secondary">' + ms + ' ms</span></div>' +
                    '<div class="small mt-1">' + esc(data.data.address) + '</div>';
            } else {
                box.innerHTML = '<div class="small text-danger"><i class="bi bi-x-circle-fill me-1"></i>' + esc(data.message || 'No se encontró una dirección para esas coordenadas.') + '</div>';
            }
        } catch (e) {
            box.innerHTML = '<div class="small text-danger"><i class="bi bi-x-circle-fill me-1"></i>' + esc(e.message) + '</div>';
        } finally {
            busy(geoBtn, false);
        }
    });

    // Historial de envíos (GET /settings/branding/deliveries)
    const statusBadge = {
        success: '<span class="badge text-bg-success">Entregado</span>',
        pending: '<span class="badge text-bg-warning">Pendiente</span>',
        failed:  '<span class="badge text-bg-danger">Fallido</span>',
    };
    const refreshBtn = document.getElementById('deliveriesRefresh');
    const tbody = document.querySelector('#deliveriesTable tbody');
    const opened = new Set();
    let loading = false;

    async function loadDeliveries() {
        if (!refreshBtn || loading) return;
        loading = true;
        refreshBtn.disabled = true;
        try {
            const { data } = await requestJson(refreshBtn.dataset.url, { method: 'GET' });
            if (!data.length) {
                tbody.innerHTML = '<tr><td colspan="8" class="text-center text-body-secondary py-3">Todavía no hay envíos.</td></tr>';
                return;
            }
            tbody.innerHTML = data.map((d) => {
                const detail = '<div class="delivery-detail">' + (d.error ? '<div class="text-danger mb-1">' + esc(d.error) + '</div>' : '') +
                    (d.next_retry_at ? '<div class="text-body-secondary mb-1">Próximo reintento: ' + esc(d.next_retry_at) + '</div>' : '') +
                    '<div class="text-body-secondary mb-1 text-break">' + esc(d.url) + '</div>' +
                    (d.response_body ? '<pre class="bg-body-tertiary rounded p-2 mb-0 integration-pre">' + esc(pretty(d.response_body)) + '</pre>' : '<span class="text-body-secondary">Sin cuerpo de respuesta.</span>') + '</div>';
                const open = opened.has(d.id);
                return '<tr>' +
                    '<td><div class="fw-medium">' + esc(d.event_label || d.event) + '</div><code class="small">' + esc(d.event) + '</code></td>' +
                    '<td class="text-break">' + esc(d.host) + '</td>' +
                    '<td class="text-center">' + esc(d.attempt) + '</td>' +
                    '<td class="text-center">' + (d.http_status ? httpBadge(d.http_status) : '—') + '</td>' +
                    '<td class="text-end text-nowrap">' + (d.response_ms != null ? esc(d.response_ms) + ' ms' : '—') + '</td>' +
                    '<td>' + (statusBadge[d.status] || esc(d.status)) + '</td>' +
                    '<td class="text-nowrap">' + esc(d.created_at) + '</td>' +
                    '<td class="text-end"><button type="button" class="btn btn-link btn-sm p-0" data-delivery="' + d.id + '" aria-expanded="' + open + '">' +
                        '<i class="bi bi-chevron-' + (open ? 'up' : 'down') + '"></i><span class="visually-hidden">Ver respuesta</span></button></td>' +
                    '</tr>' +
                    '<tr class="' + (open ? '' : 'd-none') + '" data-delivery-detail="' + d.id + '"><td colspan="8" class="bg-body-tertiary-subtle">' + detail + '</td></tr>';
            }).join('');
        } catch (e) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-center text-danger py-3">' + esc(e.message) + '</td></tr>';
        } finally {
            loading = false;
            refreshBtn.disabled = false;
        }
    }

    tbody?.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-delivery]');
        if (!btn) return;
        const id = Number(btn.dataset.delivery);
        const row = tbody.querySelector('[data-delivery-detail="' + id + '"]');
        const open = row.classList.toggle('d-none') === false;
        open ? opened.add(id) : opened.delete(id);
        btn.setAttribute('aria-expanded', open);
        btn.querySelector('i').className = 'bi bi-chevron-' + (open ? 'up' : 'down');
    });
    refreshBtn?.addEventListener('click', loadDeliveries);

    let autoTimer = null;
    document.getElementById('deliveriesAuto')?.addEventListener('change', (e) => {
        clearInterval(autoTimer);
        autoTimer = e.target.checked ? setInterval(() => { if (!document.hidden) loadDeliveries(); }, 10000) : null;
    });

    // Se carga al abrir la pestaña Integraciones (o de inmediato si ya está abierta)
    let deliveriesLoaded = false;
    const integrationsTab = document.getElementById('tab-integraciones');
    const firstLoad = () => { if (!deliveriesLoaded) { deliveriesLoaded = true; loadDeliveries(); } };
    integrationsTab?.addEventListener('shown.bs.tab', firstLoad);
    if (integrationsTab?.classList.contains('active')) firstLoad();

    // Recordar la pestaña activa para volver a ella después de guardar
    document.querySelectorAll('[data-bs-toggle="tab"]').forEach((btn) => {
        btn.addEventListener('shown.bs.tab', () => {
            document.getElementById('activeTab').value = btn.dataset.tab;
            const url = new URL(window.location);
            url.searchParams.set('tab', btn.dataset.tab);
            history.replaceState(null, '', url);
        });
    });
})();
</script>
@endsection
