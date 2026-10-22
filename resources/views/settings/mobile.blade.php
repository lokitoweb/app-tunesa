@extends('layouts.app')

@section('title', 'Configuración de la app móvil')

@section('content')
@php
    use App\Modules\Core\Models\CompanyConfiguration;
    use App\Modules\HR\Models\EmployeeDocument;

    $children = [
        'mobile_documents_upload'  => 'mobile_documents_enabled',
        'mobile_payment_info_edit' => 'mobile_payment_info_enabled',
        'mobile_uniforms_edit'     => 'mobile_uniforms_enabled'
    ];

    $flagIcons = [
        'mobile_payslips_enabled'     => ['icon' => 'bi-cash-coin', 'color' => '#0284c7'],
        'mobile_documents_enabled'    => ['icon' => 'bi-folder2-open', 'color' => '#0ea5e9'],
        'mobile_documents_upload'     => ['icon' => 'bi-cloud-arrow-up', 'color' => '#0284c7'],
        'mobile_payment_info_enabled' => ['icon' => 'bi-bank', 'color' => '#10b981'],
        'mobile_payment_info_edit'    => ['icon' => 'bi-pencil-square', 'color' => '#059669'],
        'mobile_uniforms_enabled'     => ['icon' => 'bi-person-badge', 'color' => '#8b5cf6'],
        'mobile_uniforms_edit'        => ['icon' => 'bi-rulers', 'color' => '#7c3aed'],
        'lock_verified_payment_info'  => ['icon' => 'bi-shield-lock-fill', 'color' => '#f59e0b'],
    ];

    $docIcons = [
        'dni'              => 'bi-person-vcard',
        'cv'               => 'bi-file-earmark-person',
        'contract'         => 'bi-file-earmark-text',
        'proof_of_address' => 'bi-house-check',
        'certificate'      => 'bi-award',
        'other'            => 'bi-file-earmark',
    ];
@endphp

<!-- Header Ejecutivo Moderno con Hub de Navegación -->
<div class="settings-header-card">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div class="d-flex align-items-center gap-3">
            <div class="settings-header-icon" style="background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);">
                <i class="bi bi-phone-fill"></i>
            </div>
            <div>
                <div class="d-flex align-items-center gap-2">
                    <h1 class="settings-header-title mb-0">Configuración de la app móvil</h1>
                    @if($config)
                        <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-0.5 rounded-pill font-monospace fw-semibold" style="font-size: 0.7rem;">EMPRESA ACTIVA</span>
                    @else
                        <span class="badge bg-warning-subtle text-warning border border-warning-subtle px-2 py-0.5 rounded-pill font-monospace fw-semibold" style="font-size: 0.7rem;">SELECCIONA EMPRESA</span>
                    @endif
                </div>
                <p class="settings-header-subtitle mb-0 mt-0.5">Controla las secciones visibles, permisos de edición y legajo obligatorio para los colaboradores desde su smartphone.</p>
            </div>
        </div>
        <div class="settings-hub-nav">
            <a href="{{ route('settings.branding') }}" class="settings-hub-btn" title="Configurar identidad y colores">
                <i class="bi bi-palette"></i>
                <span>Marca y apariencia</span>
            </a>
            <span class="settings-hub-btn is-active" aria-current="page">
                <i class="bi bi-phone-fill text-primary"></i>
                <span>App móvil</span>
            </span>
            <a href="{{ route('settings.integrations') }}" class="settings-hub-btn" title="Configurar integraciones y webhooks">
                <i class="bi bi-plug"></i>
                <span>Integraciones</span>
            </a>
        </div>
    </div>
</div>

@if(! $config)
    <div class="card border-0 shadow-sm mobile-settings-card mb-4">
        <div class="card-body empty-state py-5 px-4 text-center">
            <div class="d-inline-flex align-items-center justify-content-center p-3 rounded-circle bg-primary-subtle text-primary mb-3" style="width: 68px; height: 68px;">
                <i class="bi bi-phone fs-2"></i>
            </div>
            <h5 class="fw-bold text-body mb-2">Configuración Específica por Empresa</h5>
            <p class="settings-helper-text mb-4 max-w-md mx-auto" style="font-size: 0.875rem;">
                Las funciones y permisos de la aplicación móvil se definen por cada razón social. Para configurar o ajustar los parámetros, selecciona una empresa en el conmutador de la barra superior.
            </p>
            <div class="d-inline-flex align-items-center gap-2 px-3 py-2 rounded-3 bg-body-tertiary border text-secondary small">
                <i class="bi bi-arrow-up-circle-fill text-primary"></i>
                <span>Usa el selector <strong>"Todas las empresas"</strong> arriba a la izquierda para elegir una empresa</span>
            </div>
        </div>
    </div>
@else

<!-- KPIs / Tarjetas Rápidas de Estado -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="mobile-kpi-card shadow-sm h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="mobile-setting-icon" style="background: rgba(2, 132, 199, 0.1); color: #0284c7;">
                    <i class="bi bi-grid-fill"></i>
                </div>
                <div class="min-w-0">
                    @php
                        $activeFlags = collect(CompanyConfiguration::FLAGS)->keys()->filter(fn($f) => !isset($children[$f]) && $config->{$f})->count();
                        $totalFlags = collect(CompanyConfiguration::FLAGS)->keys()->filter(fn($f) => !isset($children[$f]))->count();
                    @endphp
                    <div class="fs-4 fw-bold text-body lh-1">{{ $activeFlags }} <span class="fs-6 text-secondary fw-normal">/ {{ $totalFlags }}</span></div>
                    <div class="settings-helper-text mt-1 text-truncate">Módulos activos</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="mobile-kpi-card shadow-sm h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="mobile-setting-icon" style="background: rgba(16, 185, 129, 0.1); color: #10b981;">
                    <i class="bi bi-pencil-square"></i>
                </div>
                <div class="min-w-0">
                    @php
                        $activeEdits = collect($children)->keys()->filter(fn($f) => $config->{$f})->count();
                    @endphp
                    <div class="fs-4 fw-bold text-body lh-1">{{ $activeEdits }} <span class="fs-6 text-secondary fw-normal">/ {{ count($children) }}</span></div>
                    <div class="settings-helper-text mt-1 text-truncate">Ediciones permitidas</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="mobile-kpi-card shadow-sm h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="mobile-setting-icon" style="background: rgba(139, 92, 246, 0.1); color: #8b5cf6;">
                    <i class="bi bi-file-earmark-check-fill"></i>
                </div>
                <div class="min-w-0">
                    <div class="fs-4 fw-bold text-body lh-1">{{ count($config->requiredDocuments()) }}</div>
                    <div class="settings-helper-text mt-1 text-truncate">Docs. obligatorios</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="mobile-kpi-card shadow-sm h-100">
            <div class="d-flex align-items-center gap-3">
                <div class="mobile-setting-icon" style="background: rgba(245, 158, 11, 0.1); color: #f59e0b;">
                    <i class="bi bi-cloud-arrow-up-fill"></i>
                </div>
                <div class="min-w-0">
                    <div class="fs-4 fw-bold text-body lh-1">{{ $config->max_upload_mb }} <span class="fs-6 text-secondary fw-normal">MB</span></div>
                    <div class="settings-helper-text mt-1 text-truncate">Límite por archivo</div>
                </div>
            </div>
        </div>
    </div>
</div>

<form method="POST" action="{{ route('settings.mobile.update') }}">
    @csrf @method('PUT')

    <div class="row g-4">
        <!-- Columna Izquierda: Módulos y Permisos -->
        <div class="col-12 col-xl-7">
            <div class="card border-0 shadow-sm mobile-settings-card">
                <div class="card-header border-0 bg-transparent pt-3 pb-2 px-3 d-flex align-items-center justify-content-between">
                    <div>
                        <h2 class="h6 fw-bold mb-0 text-primary-emphasis">
                            <i class="bi bi-toggles2 text-primary me-2"></i>Módulos y permisos del colaborador
                        </h2>
                        <small class="settings-helper-text">Activa o restringe las secciones visibles en la aplicación móvil.</small>
                    </div>
                </div>
                <div class="mobile-settings-list">
                    @foreach(CompanyConfiguration::FLAGS as $flag => [$label, $desc])
                        @php
                            $isChild = isset($children[$flag]);
                            $iconData = $flagIcons[$flag] ?? ['icon' => 'bi-sliders', 'color' => '#0284c7'];
                        @endphp
                        <div class="mobile-setting-item {{ $isChild ? 'mobile-setting-child' : '' }}" data-parent="{{ $children[$flag] ?? '' }}">
                            <div class="d-flex align-items-center gap-3 flex-grow-1">
                                <div class="mobile-setting-icon" style="background: {{ $iconData['color'] }}15; color: {{ $iconData['color'] }};">
                                    <i class="bi {{ $iconData['icon'] }}"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="d-flex align-items-center gap-2">
                                        <label class="form-check-label fw-bold text-body mb-0 cursor-pointer" for="flag_{{ $flag }}">
                                            {{ $label }}
                                        </label>
                                        @if($isChild)
                                            <span class="badge rounded-pill bg-primary-subtle text-primary border border-primary-subtle font-monospace" style="font-size: 0.68rem;">Edición</span>
                                        @endif
                                    </div>
                                    <div class="settings-helper-text mt-0.5">{{ $desc }}</div>
                                </div>
                            </div>
                            <div class="form-check form-switch form-switch-lg mb-0 ps-0 flex-shrink-0">
                                <input class="form-check-input ms-0" type="checkbox" role="switch" name="{{ $flag }}" value="1" id="flag_{{ $flag }}" @checked($config->{$flag}) style="cursor: pointer; width: 2.85em; height: 1.5em;">
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <!-- Columna Derecha: Legajo Obligatorio & Parámetros -->
        <div class="col-12 col-xl-5 d-flex flex-column gap-4">
            <!-- Legajo Obligatorio -->
            <div class="card border-0 shadow-sm mobile-settings-card">
                <div class="card-header border-0 bg-transparent pt-3 pb-2 px-3">
                    <h2 class="h6 fw-bold mb-0 text-primary-emphasis">
                        <i class="bi bi-file-earmark-lock-fill text-primary me-2"></i>Legajo obligatorio
                    </h2>
                    <small class="settings-helper-text">Documentos requeridos para el avance y validación del onboarding.</small>
                </div>
                <div class="card-body p-3">
                    <div class="d-flex flex-column gap-2 mb-3">
                        @foreach(EmployeeDocument::TYPES as $type => $label)
                            @php
                                $isChecked = in_array($type, $config->requiredDocuments(), true);
                                $docIcon = $docIcons[$type] ?? 'bi-file-earmark';
                            @endphp
                            <label class="doc-tile-card {{ $isChecked ? 'active' : '' }}" for="req_{{ $type }}">
                                <div class="form-check mb-0">
                                    <input class="form-check-input" type="checkbox" name="required_documents[]" value="{{ $type }}" id="req_{{ $type }}" @checked($isChecked) onchange="this.closest('.doc-tile-card').classList.toggle('active', this.checked)">
                                </div>
                                <div class="d-flex align-items-center gap-2 flex-grow-1">
                                    <i class="bi {{ $docIcon }} text-primary fs-5"></i>
                                    <span class="fw-semibold text-body small">{{ $label }}</span>
                                </div>
                            </label>
                        @endforeach
                    </div>

                    <div class="pt-2 border-top">
                        <label class="form-label small fw-semibold text-body mb-1" for="max_upload_mb">Tamaño máximo por archivo</label>
                        <div class="modern-input-wrapper" style="max-width: 220px;">
                            <span class="modern-input-icon"><i class="bi bi-cloud-arrow-up"></i></span>
                            <input type="number" id="max_upload_mb" name="max_upload_mb" class="modern-input-control" min="1" max="20" value="{{ $config->max_upload_mb }}" required>
                            <span class="modern-input-suffix">MB</span>
                        </div>
                        <small class="settings-helper-text d-block mt-1">Permitido entre 1 y 20 MB por documento.</small>
                    </div>
                </div>
            </div>

            <!-- Marcación fuera de geocerca -->
            <div class="card border-0 shadow-sm mobile-settings-card">
                <div class="card-header border-0 bg-transparent pt-3 pb-2 px-3">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-geo-alt-fill text-danger fs-5"></i>
                        <h2 class="h6 fw-bold mb-0 text-primary-emphasis">Marcación fuera de geocerca</h2>
                    </div>
                </div>
                <div class="card-body p-3 pt-1">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <span class="small settings-helper-text">Política actual:</span>
                        <span class="badge bg-secondary-subtle text-secondary-emphasis border px-2 py-1">
                            {{ CompanyConfiguration::GEOFENCE_POLICIES[$config->geofence_policy][0] ?? label('geofence_policy', $config->geofence_policy) }}
                        </span>
                    </div>
                    <p class="settings-helper-text mb-3">
                        Se gestiona en conjunto con la selfie, la marcación sin conexión y la precisión de geocerca.
                    </p>
                    <a href="{{ route('settings.branding', ['tab' => 'reglas']) }}" class="btn btn-sm btn-outline-primary d-inline-flex align-items-center" style="border-radius: 8px;">
                        <i class="bi bi-sliders me-2"></i> Ir a Reglas operativas
                    </a>
                </div>
            </div>

            <!-- Botón de Guardado Ejecutivo -->
            <div class="settings-action-bar">
                <button type="submit" class="btn btn-primary w-100 d-inline-flex align-items-center justify-content-center py-2.5 fw-bold shadow-sm" style="border-radius: 9px; box-shadow: 0 4px 14px rgba(2, 132, 199, 0.28);">
                    <i class="bi bi-floppy2-fill me-2"></i> Guardar configuración móvil
                </button>
                @if($config->updated_at)
                    <div class="text-center small settings-helper-text mt-2">
                        <i class="bi bi-clock-history me-1 text-primary"></i> <strong>Último cambio:</strong> {{ $config->updated_at->format('d/m/Y h:i a') }}
                    </div>
                @endif
            </div>
        </div>
    </div>
</form>
@endif
@endsection

@section('scripts')
<script>
    // Un permiso de edición se desactiva si su módulo principal está apagado
    document.querySelectorAll('[data-parent]').forEach((row) => {
        const parentId = row.dataset.parent;
        if (!parentId) return;
        const parent = document.getElementById('flag_' + parentId);
        const input = row.querySelector('input');
        const sync = () => {
            const isEnabled = parent && parent.checked;
            row.classList.toggle('opacity-50', !isEnabled);
            if (input) input.disabled = !isEnabled;
        };
        if (parent) {
            parent.addEventListener('change', sync);
            sync();
        }
    });
</script>
@endsection
