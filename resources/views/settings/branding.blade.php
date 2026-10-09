@extends('layouts.app')

@section('title', 'Marca y app')

@section('content')
@php
    // Pestaña a abrir: la pedida, o la del primer campo con error
    $fieldTabs = [
        'app'           => ['api_base_url', 'min_supported_version', 'latest_version', 'force_update', 'selfie_required', 'face_detection_required', 'offline_mode_enabled', 'offline_max_hours', 'max_gps_accuracy_meters'],
        'reglas'        => ['default_grace_minutes', 'lunch_minutes', 'lunch_paid', 'default_geofence_radius', 'geofence_policy'],
        'integraciones' => ['webhook_url', 'webhook_secret', 'maps_api_key', 'notification_endpoint'],
    ];
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
        <p>Logo, colores, comportamiento de la app móvil y reglas de marcación de {{ $ownerName }}.</p>
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
                            <div class="form-check form-switch form-switch-lg d-flex justify-content-between align-items-center gap-3 mt-3 mb-0 ps-0">
                                <label class="form-check-label" for="force_update">
                                    <span class="d-block fw-semibold">Obligar a actualizar</span>
                                    <span class="d-block small text-body-secondary">Las apps con una versión menor a la mínima no podrán marcar hasta actualizar.</span>
                                </label>
                                <input class="form-check-input flex-shrink-0 ms-0" type="checkbox" role="switch" name="force_update" value="1" id="force_update" @checked(old('force_update', $effective->force_update))>
                            </div>
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
                                    <div class="form-check form-switch form-switch-lg d-flex justify-content-between align-items-center gap-3 mb-0 ps-0">
                                        <label class="form-check-label" for="{{ $flag }}">
                                            <span class="d-block fw-semibold">{{ $label }}</span>
                                            <span class="d-block small text-body-secondary">{{ $desc }}</span>
                                        </label>
                                        <input class="form-check-input flex-shrink-0 ms-0" type="checkbox" role="switch" name="{{ $flag }}" value="1" id="{{ $flag }}" @checked(old($flag, $effective->{$flag}))>
                                    </div>
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
                            <div class="form-check form-switch form-switch-lg d-flex justify-content-between align-items-center gap-3 mt-3 mb-0 ps-0">
                                <label class="form-check-label" for="lunch_paid">
                                    <span class="d-block fw-semibold">Refrigerio pagado</span>
                                    <span class="d-block small text-body-secondary">El tiempo de refrigerio cuenta como hora trabajada en los reportes.</span>
                                </label>
                                <input class="form-check-input flex-shrink-0 ms-0" type="checkbox" role="switch" name="lunch_paid" value="1" id="lunch_paid" @checked(old('lunch_paid', $effective->lunch_paid))>
                            </div>
                            <p class="small text-body-secondary mt-3 mb-0">Campos vacíos: se usa el valor de {{ $isGlobal ? 'fábrica' : 'la plataforma' }} (indicado en gris).</p>
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
            </div>
        </div>

        {{-- ============================ INTEGRACIONES ============================ --}}
        <div class="tab-pane fade {{ $tab === 'integraciones' ? 'show active' : '' }}" id="pane-integraciones" role="tabpanel" aria-labelledby="tab-integraciones" tabindex="0">
            <div class="card border-0">
                <div class="card-header border-0 pt-3">
                    <h2 class="h6 fw-semibold mb-0">Integraciones</h2>
                    <small class="text-body-secondary">Las claves se guardan cifradas y nunca se muestran completas ni se envían a la app.</small>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="webhook_url" class="form-label">URL del webhook</label>
                            <input type="url" id="webhook_url" name="webhook_url" maxlength="255" value="{{ $val('webhook_url') }}" placeholder="https://erp.empresa.com/webhooks/asistencia" class="form-control @error('webhook_url') is-invalid @enderror">
                            <div class="form-text">Recibe un aviso por cada marcación (para ERP o planilla).</div>
                        </div>
                        <div class="col-md-6">
                            <label for="webhook_secret" class="form-label">Secreto del webhook</label>
                            <input type="password" id="webhook_secret" name="webhook_secret" maxlength="255" autocomplete="new-password" value=""
                                   placeholder="{{ $values['webhook_secret'] ?? 'Sin secreto' }}" class="form-control font-monospace @error('webhook_secret') is-invalid @enderror">
                            <div class="form-text">Dejar vacío para no cambiar.</div>
                            @if($values['has_webhook_secret'])
                                <div class="form-check mt-1">
                                    <input class="form-check-input" type="checkbox" name="clear_webhook_secret" value="1" id="clear_webhook_secret">
                                    <label class="form-check-label small" for="clear_webhook_secret">Borrar el secreto guardado</label>
                                </div>
                            @endif
                        </div>
                        <div class="col-md-6">
                            <label for="maps_api_key" class="form-label">Clave de mapas</label>
                            <input type="password" id="maps_api_key" name="maps_api_key" maxlength="255" autocomplete="new-password" value=""
                                   placeholder="{{ $values['maps_api_key'] ?? 'Sin clave' }}" class="form-control font-monospace @error('maps_api_key') is-invalid @enderror">
                            <div class="form-text">Dejar vacío para no cambiar.{{ $isGlobal ? '' : ' Sin clave propia se usa la de la plataforma.' }}</div>
                            @if($values['has_maps_api_key'])
                                <div class="form-check mt-1">
                                    <input class="form-check-input" type="checkbox" name="clear_maps_api_key" value="1" id="clear_maps_api_key">
                                    <label class="form-check-label small" for="clear_maps_api_key">Borrar la clave guardada</label>
                                </div>
                            @endif
                        </div>
                        <div class="col-md-6">
                            <label for="notification_endpoint" class="form-label">Endpoint de notificaciones</label>
                            <input type="url" id="notification_endpoint" name="notification_endpoint" maxlength="255" value="{{ $val('notification_endpoint') }}" placeholder="https://notificaciones.empresa.com/push" class="form-control @error('notification_endpoint') is-invalid @enderror">
                            <div class="form-text">Servicio externo de avisos (opcional).</div>
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
    const syncOffline = () => hoursRow.classList.toggle('opacity-50', !offline.checked);
    offline.addEventListener('change', syncOffline);
    syncOffline();

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
