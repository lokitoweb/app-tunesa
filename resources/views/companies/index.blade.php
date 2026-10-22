@extends('layouts.app')

@section('title', 'Empresas')

@section('content')
@php
    use App\Modules\Core\Controllers\Web\CompanyWebController as C;
    $timezonesList = $timezones ?? \DateTimeZone::listIdentifiers();
    $optionalModulesList = $optionalModules ?? \App\Modules\ModuleRegistry::optional();
    $moduleLabelsList = $moduleLabels ?? \App\Modules\ModuleRegistry::LABELS;
    $plansList = $plans ?? C::PLANS;
    $statusesList = $statuses ?? C::STATUSES;
@endphp

<!-- Header con barra unificada de filtros y acción principal -->
<div class="company-header-bar">
    <div>
        <h1 class="h2 mb-1 fw-bold text-dark">Empresas</h1>
        <p class="text-secondary mb-0" style="font-size: 0.875rem;">Gestión centralizada de empresas cliente, suscripciones, marca blanca y accesos.</p>
    </div>
    
    <!-- Filtros integrados al lado del botón Nueva empresa -->
    <div class="company-actions-bar">
        <form method="GET" action="{{ route('companies.index') }}" class="d-flex align-items-center gap-2 m-0 p-0">
            <!-- Buscador con icono integrado (sin bordes rotos) -->
            <div class="company-search-wrapper">
                <i class="bi bi-search search-icon"></i>
                <input type="search" id="search" name="search" value="{{ request('search') }}" class="company-search-input" placeholder="Buscar empresa, RUC, subdominio...">
            </div>

            <!-- Filtro por Estado -->
            <select id="status_filter" name="status" class="form-select company-status-select" data-autosubmit>
                <option value="">Todos los estados</option>
                @foreach($statusesList + ['cancelled' => 'Dada de baja'] as $value => $label)
                    <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                @endforeach
            </select>

            @if(request()->hasAny(['search', 'status']))
                <a href="{{ route('companies.index') }}" class="btn btn-outline-secondary d-inline-flex align-items-center justify-content-center" style="height: 38px; width: 38px; border-radius: 8px;" title="Limpiar filtros">
                    <i class="bi bi-x-lg"></i>
                </a>
            @endif
        </form>

        <!-- Botón Nueva empresa -->
        <button type="button" class="btn btn-primary d-inline-flex align-items-center gap-2 shadow-sm text-nowrap" style="height: 38px; border-radius: 8px; font-weight: 600; padding: 0 1.15rem;" onclick="openCreateCompanyModal()">
            <i class="bi bi-plus-lg"></i>
            <span>Nueva empresa</span>
        </button>
        <a href="{{ route('companies.create') }}" class="visually-hidden">Nueva empresa</a>
    </div>
</div>

<!-- KPI Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="company-kpi-card">
            <div class="company-kpi-icon" style="background: rgba(2, 132, 199, 0.1); color: #0284c7;">
                <i class="bi bi-buildings"></i>
            </div>
            <div>
                <div class="company-kpi-val">{{ number_format($stats['total'] ?? $companies->total()) }}</div>
                <div class="company-kpi-label">Total Empresas</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="company-kpi-card">
            <div class="company-kpi-icon" style="background: rgba(16, 185, 129, 0.1); color: #10b981;">
                <i class="bi bi-check-circle"></i>
            </div>
            <div>
                <div class="company-kpi-val">{{ number_format($stats['active'] ?? $companies->where('status', 'active')->count()) }}</div>
                <div class="company-kpi-label">Empresas Activas</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="company-kpi-card">
            <div class="company-kpi-icon" style="background: rgba(99, 102, 241, 0.1); color: #6366f1;">
                <i class="bi bi-people"></i>
            </div>
            <div>
                <div class="company-kpi-val">{{ number_format($stats['total_users'] ?? 0) }}</div>
                <div class="company-kpi-label">Usuarios Plataforma</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="company-kpi-card">
            <div class="company-kpi-icon" style="background: rgba(245, 158, 11, 0.1); color: #d97706;">
                <i class="bi bi-globe"></i>
            </div>
            <div>
                <div class="company-kpi-val">{{ number_format($stats['custom_domains'] ?? 0) }}</div>
                <div class="company-kpi-label">Dominios Propios</div>
            </div>
        </div>
    </div>
</div>

<!-- Table Card -->
<div class="card border-0 shadow-sm" style="border-radius: 14px; overflow: hidden; border: 1px solid #e2e8f0;">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead style="background: #f8fafc; border-bottom: 1px solid #e2e8f0;">
                <tr>
                    <th class="ps-4 py-3 text-secondary text-uppercase fw-semibold" style="font-size: 0.725rem; letter-spacing: 0.05em;">Empresa</th>
                    <th class="py-3 text-secondary text-uppercase fw-semibold" style="font-size: 0.725rem; letter-spacing: 0.05em;">Subdominio / Dominio</th>
                    <th class="py-3 text-secondary text-uppercase fw-semibold" style="font-size: 0.725rem; letter-spacing: 0.05em;">Plan</th>
                    <th class="py-3 text-secondary text-uppercase fw-semibold text-end" style="font-size: 0.725rem; letter-spacing: 0.05em;">Usuarios</th>
                    <th class="py-3 text-secondary text-uppercase fw-semibold" style="font-size: 0.725rem; letter-spacing: 0.05em;">Estado</th>
                    <th class="py-3 text-secondary text-uppercase fw-semibold" style="font-size: 0.725rem; letter-spacing: 0.05em;">Fecha Alta</th>
                    <th class="pe-4 py-3 text-secondary text-uppercase fw-semibold text-end" style="font-size: 0.725rem; letter-spacing: 0.05em;">Acciones</th>
                </tr>
            </thead>
            <tbody>
                @forelse($companies as $c)
                    <tr style="border-bottom: 1px solid #f1f5f9;">
                        <td class="ps-4 py-3">
                            <div class="d-flex align-items-center gap-3">
                                @if($c->logo_path)
                                    <span class="company-avatar-box">
                                        <img src="{{ asset('storage/' . $c->logo_path) }}" alt="{{ $c->company_name }}">
                                    </span>
                                @else
                                    <span class="company-avatar-box" style="background: {{ preg_match('/^#[0-9a-fA-F]{6}$/', (string) $c->primary_color) ? $c->primary_color : '#0284c7' }};">
                                        <i class="bi bi-buildings"></i>
                                    </span>
                                @endif
                                <div>
                                    <div class="fw-bold text-dark" style="font-size: 0.925rem;">{{ $c->company_name }}</div>
                                    <div class="small text-secondary d-flex align-items-center gap-2" style="font-size: 0.78rem;">
                                        @if($c->ruc)
                                            <span>RUC: <strong class="text-dark">{{ $c->ruc }}</strong></span>
                                            <span class="text-muted">·</span>
                                        @endif
                                        <span>{{ $c->email }}</span>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="d-inline-flex flex-column gap-1">
                                <span class="badge bg-light text-dark border font-monospace text-lowercase fw-normal px-2 py-1" style="font-size: 0.775rem;">
                                    {{ $c->slug }}.{{ config('app.domain', 'tunesa.app') }}
                                </span>
                                @if($c->custom_domain)
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle font-monospace text-lowercase fw-normal px-2 py-1" style="font-size: 0.75rem;">
                                        <i class="bi bi-link-45deg me-1"></i>{{ $c->custom_domain }}
                                    </span>
                                @endif
                            </div>
                        </td>
                        <td>
                            <span class="badge bg-secondary-subtle text-secondary border fw-medium px-2 py-1" style="font-size: 0.75rem;">
                                {{ label('tenant_plan', $c->plan) }}
                            </span>
                        </td>
                        <td class="text-end">
                            <span class="fw-bold text-dark me-1">{{ $c->users_count }}</span>
                            <i class="bi bi-people text-secondary small"></i>
                        </td>
                        <td>
                            <x-status-badge type="tenant_status" :value="$c->status ?? 'cancelled'" />
                        </td>
                        <td class="small text-secondary text-nowrap">
                            <i class="bi bi-calendar3 me-1 text-muted"></i>{{ $c->created_at?->format('d/m/Y') }}
                        </td>
                        <td class="pe-4 text-end">
                            <div class="d-inline-flex align-items-center gap-1">
                                <button type="button" class="btn-table-action btn-action-edit" title="Editar empresa" onclick="openEditCompanyModal({{ $c->id }})">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <a href="{{ route('companies.edit', $c->id) }}" class="visually-hidden">Editar</a>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center py-5">
                            <div class="empty-state p-4">
                                <i class="bi bi-buildings text-muted fs-1 d-block mb-2"></i>
                                <div class="fw-semibold text-secondary">No hay empresas registradas</div>
                                <div class="text-muted small">
                                    {{ request()->hasAny(['search', 'status']) ? 'No se encontraron resultados para los filtros seleccionados.' : 'Empieza agregando tu primera empresa.' }}
                                </div>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($companies->hasPages())
        <div class="card-footer bg-white border-top py-3 px-4">
            {{ $companies->links() }}
        </div>
    @endif
</div>

<!-- ========================================================================= -->
<!-- MODAL EXPANDIDO: CREAR / EDITAR EMPRESA                                   -->
<!-- ========================================================================= -->
<div class="modal fade" id="companyModal" tabindex="-1" aria-labelledby="companyModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-company-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            
            <!-- Modal Form wraps header, body and footer -->
            <form id="companyForm" method="POST" action="" enctype="multipart/form-data">
                @csrf
                <input type="hidden" name="_method" id="companyFormMethod" value="">
                <input type="hidden" name="modules_submitted" value="1">

                <!-- Modal Header -->
                <div class="modal-header border-bottom px-4 py-3 bg-white flex-shrink-0">
                    <div class="d-flex align-items-center gap-3">
                        <div id="companyModalHeaderIcon" style="width: 42px; height: 42px; border-radius: 8px; background: rgba(2, 132, 199, 0.1); color: #0284c7; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
                            <i class="bi bi-buildings" id="companyModalHeaderIconEl"></i>
                        </div>
                        <div>
                            <h5 class="modal-title fw-bold text-dark mb-0" id="companyModalLabel">Editar empresa</h5>
                            <small class="text-muted" id="companyModalSublabel" style="font-size: 0.8rem;">Configuración corporativa, marca blanca y accesos de plataforma.</small>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <!-- Segmented Control Navigation (Modern Pill Tabs) -->
                <div class="modal-segmented-nav flex-shrink-0" id="companyModalTabs" role="tablist">
                    <div class="nav-item" role="presentation">
                        <button class="nav-link active" id="tab-general-btn" data-bs-toggle="tab" data-bs-target="#tab-general" type="button" role="tab" aria-controls="tab-general" aria-selected="true">
                            <i class="bi bi-building"></i>
                            <span>Datos Generales</span>
                        </button>
                    </div>
                    <div class="nav-item" role="presentation">
                        <button class="nav-link" id="tab-branding-btn" data-bs-toggle="tab" data-bs-target="#tab-branding" type="button" role="tab" aria-controls="tab-branding" aria-selected="false">
                            <i class="bi bi-palette"></i>
                            <span>Marca y Diseño</span>
                        </button>
                    </div>
                    <div class="nav-item" role="presentation">
                        <button class="nav-link" id="tab-modules-btn" data-bs-toggle="tab" data-bs-target="#tab-modules" type="button" role="tab" aria-controls="tab-modules" aria-selected="false">
                            <i class="bi bi-grid-3x3-gap"></i>
                            <span>Módulos</span>
                        </button>
                    </div>
                    <div class="nav-item" role="presentation">
                        <button class="nav-link" id="tab-admin-btn" data-bs-toggle="tab" data-bs-target="#tab-admin" type="button" role="tab" aria-controls="tab-admin" aria-selected="false">
                            <i class="bi bi-shield-lock"></i>
                            <span>Administrador</span>
                        </button>
                    </div>
                </div>

                <div class="modal-body px-4 py-3 flex-grow-1 overflow-auto" style="background: #ffffff;">
                    <!-- Loading State -->
                    <div id="companyModalLoading" class="text-center py-5 d-none">
                        <div class="spinner-border text-primary mb-3" role="status" style="width: 2.5rem; height: 2.5rem;">
                            <span class="visually-hidden">Cargando...</span>
                        </div>
                        <div class="text-secondary fw-semibold">Cargando datos de la empresa...</div>
                    </div>

                    <div id="companyModalContent" class="tab-content">
                        <!-- TAB 1: DATOS GENERALES -->
                        <div class="tab-pane fade show active" id="tab-general" role="tabpanel" aria-labelledby="tab-general-btn">
                            <!-- Sección: Identificación -->
                            <div class="modal-section-card">
                                <div class="modal-section-title">
                                    <i class="bi bi-building-check"></i>
                                    <span>Identificación Corporativa</span>
                                </div>
                                <div class="row g-3">
                                    <div class="col-12 col-md-8">
                                        <label for="company_name" class="form-label small fw-semibold text-secondary mb-1">
                                            Nombre Comercial <span class="text-danger">*</span>
                                        </label>
                                        <input type="text" id="company_name" name="company_name" required maxlength="200" class="form-control" placeholder="Ej. Negreiros Soluciones S.A.C.">
                                    </div>
                                    <div class="col-12 col-md-4">
                                        <label for="ruc" class="form-label small fw-semibold text-secondary mb-1">RUC</label>
                                        <input type="text" id="ruc" name="ruc" maxlength="20" class="form-control font-monospace" placeholder="20123456789">
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <label for="slug" class="form-label small fw-semibold text-secondary mb-1">
                                            Subdominio asignado <span class="text-danger">*</span>
                                        </label>
                                        <div class="input-domain-wrapper">
                                            <input type="text" id="slug" name="slug" required maxlength="63" pattern="[a-z0-9]+(-[a-z0-9]+)*" class="font-monospace text-lowercase" placeholder="empresa-demo">
                                            <span class="domain-suffix">.{{ config('app.domain', 'tunesa.app') }}</span>
                                        </div>
                                        <div class="form-text small text-muted mt-1">Identificador para inicio de sesión en plataforma y móvil.</div>
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <label for="custom_domain" class="form-label small fw-semibold text-secondary mb-1">Dominio Propio (CNAME)</label>
                                        <div class="input-domain-wrapper">
                                            <input type="text" id="custom_domain" name="custom_domain" maxlength="255" class="font-monospace text-lowercase" placeholder="asistencia.miempresa.com">
                                        </div>
                                        <div class="form-text small text-muted mt-1">Opcional para marca blanca y acceso personalizado.</div>
                                    </div>
                                </div>
                            </div>

                            <!-- Sección: Contacto & Ubicación -->
                            <div class="modal-section-card">
                                <div class="modal-section-title">
                                    <i class="bi bi-geo-alt"></i>
                                    <span>Contacto y Ubicación</span>
                                </div>
                                <div class="row g-3">
                                    <div class="col-12 col-md-6">
                                        <label for="email" class="form-label small fw-semibold text-secondary mb-1">
                                            Correo Corporativo <span class="text-danger">*</span>
                                        </label>
                                        <input type="email" id="email" name="email" required class="form-control" placeholder="contacto@empresa.com">
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <label for="phone" class="form-label small fw-semibold text-secondary mb-1">Teléfono</label>
                                        <input type="text" id="phone" name="phone" maxlength="20" class="form-control" placeholder="+51 987 654 321">
                                    </div>
                                    <div class="col-12">
                                        <label for="address" class="form-label small fw-semibold text-secondary mb-1">Dirección Fiscal / Sede Central</label>
                                        <input type="text" id="address" name="address" maxlength="500" class="form-control" placeholder="Av. Principal 123, Trujillo, La Libertad">
                                    </div>
                                </div>
                            </div>

                            <!-- Sección: Suscripción & Parámetros -->
                            <div class="modal-section-card mb-0">
                                <div class="modal-section-title">
                                    <i class="bi bi-sliders"></i>
                                    <span>Suscripción y Estado</span>
                                </div>
                                <div class="row g-3">
                                    <div class="col-12 col-md-4">
                                        <label for="plan" class="form-label small fw-semibold text-secondary mb-1">Plan de Servicio</label>
                                        <select id="plan" name="plan" class="form-select">
                                            @foreach($plansList as $value => $label)
                                                <option value="{{ $value }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-12 col-md-4">
                                        <label for="status" class="form-label small fw-semibold text-secondary mb-1">Estado en Plataforma</label>
                                        <select id="status" name="status" class="form-select">
                                            @foreach($statusesList as $value => $label)
                                                <option value="{{ $value }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        <div class="form-text small text-muted mt-1">Suspendida: bloquea todo acceso al panel.</div>
                                    </div>
                                    <div class="col-12 col-md-4">
                                        <label for="timezone" class="form-label small fw-semibold text-secondary mb-1">Zona Horaria</label>
                                        <select id="timezone" name="timezone" class="form-select">
                                            @foreach($timezonesList as $tz)
                                                <option value="{{ $tz }}">{{ $tz }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- TAB 2: MARCA BLANCA Y DISEÑO -->
                        <div class="tab-pane fade" id="tab-branding" role="tabpanel" aria-labelledby="tab-branding-btn">
                            <div class="row g-3">
                                <!-- Logo -->
                                <div class="col-12 col-md-6">
                                    <div class="modal-section-card h-100 mb-0">
                                        <div class="modal-section-title">
                                            <i class="bi bi-image"></i>
                                            <span>Logo Corporativo</span>
                                        </div>
                                        <div class="d-flex align-items-center gap-3 mb-3">
                                            <div id="logoPreviewContainer" class="d-flex align-items-center justify-content-center border rounded-3 bg-white shadow-sm p-2" style="width: 140px; height: 68px;">
                                                <img id="logoPreviewImg" src="" alt="Logo actual" class="d-none" style="max-height: 52px; max-width: 120px; object-fit: contain;">
                                                <span id="logoPreviewPlaceholder" class="text-muted small text-center">
                                                    <i class="bi bi-image fs-3 d-block text-secondary"></i>
                                                    Sin logo
                                                </span>
                                            </div>
                                            <div class="flex-grow-1">
                                                <label for="logo" class="form-label small fw-semibold text-secondary mb-1">Cargar Logotipo</label>
                                                <input type="file" id="logo" name="logo" accept="image/png,image/jpeg,image/webp" class="form-control form-control-sm">
                                                <div class="form-text small text-muted mt-1">Formatos PNG, JPG, WebP (máx. 2 MB).</div>
                                            </div>
                                        </div>
                                        <div id="removeLogoWrapper" class="form-check d-none pt-2 border-top">
                                            <input class="form-check-input" type="checkbox" name="remove_logo" value="1" id="remove_logo">
                                            <label class="form-check-label small text-secondary" for="remove_logo">
                                                Eliminar logo actual y utilizar monograma
                                            </label>
                                        </div>
                                    </div>
                                </div>

                                <!-- Colores -->
                                <div class="col-12 col-md-6">
                                    <div class="modal-section-card h-100 mb-0">
                                        <div class="modal-section-title">
                                            <i class="bi bi-palette"></i>
                                            <span>Paleta Institucional</span>
                                        </div>
                                        <div class="row g-3">
                                            <div class="col-6">
                                                <label for="primary_color" class="form-label small fw-semibold text-secondary mb-1">Color Primario</label>
                                                <div class="d-flex align-items-center gap-2">
                                                    <input type="color" id="primary_color" name="primary_color" value="#0284c7" class="form-control form-control-color border-0 p-0" style="width: 40px; height: 38px; border-radius: 8px;">
                                                    <input type="text" id="primary_color_text" class="form-control font-monospace form-control-sm text-uppercase" value="#0284C7" readonly>
                                                </div>
                                                <div class="form-text small text-muted mt-1">Botones principales y navegación.</div>
                                            </div>
                                            <div class="col-6">
                                                <label for="secondary_color" class="form-label small fw-semibold text-secondary mb-1">Color Secundario</label>
                                                <div class="d-flex align-items-center gap-2">
                                                    <input type="color" id="secondary_color" name="secondary_color" value="#F59E0B" class="form-control form-control-color border-0 p-0" style="width: 40px; height: 38px; border-radius: 8px;">
                                                    <input type="text" id="secondary_color_text" class="form-control font-monospace form-control-sm text-uppercase" value="#F59E0B" readonly>
                                                </div>
                                                <div class="form-text small text-muted mt-1">Acentos, etiquetas y estados.</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- TAB 3: MÓDULOS DEL SISTEMA -->
                        <div class="tab-pane fade" id="tab-modules" role="tabpanel" aria-labelledby="tab-modules-btn">
                            <div class="alert alert-light border small text-secondary mb-3 d-flex align-items-center gap-2 rounded-3">
                                <i class="bi bi-info-circle text-primary fs-5"></i>
                                <span>Los módulos desmarcados quedan inactivos para esta empresa: se ocultan de su navegación y sus endpoints quedan restringidos.</span>
                            </div>
                            <div class="row g-3" id="modulesCheckboxesContainer">
                                @foreach($optionalModulesList as $mod)
                                    <div class="col-12 col-md-6">
                                        <div class="module-toggle-card d-flex align-items-center justify-content-between">
                                            <div>
                                                <label class="form-check-label fw-bold text-dark d-block cursor-pointer mb-0" for="module_{{ $mod }}" style="font-size: 0.875rem;">
                                                    {{ $moduleLabelsList[$mod] ?? ucfirst($mod) }}
                                                </label>
                                                <small class="text-secondary" style="font-size: 0.775rem;">Habilita el módulo de {{ strtolower($moduleLabelsList[$mod] ?? $mod) }} para los colaboradores.</small>
                                            </div>
                                            <div class="form-check form-switch mb-0 fs-5">
                                                <input class="form-check-input module-checkbox" type="checkbox" name="modules[]" value="{{ $mod }}" id="module_{{ $mod }}" checked>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        <!-- TAB 4: ADMINISTRADOR -->
                        <div class="tab-pane fade" id="tab-admin" role="tabpanel" aria-labelledby="tab-admin-btn">
                            <!-- Modo Crear: Alta del primer administrador -->
                            <div id="adminCreateFields">
                                <div class="modal-section-card mb-0">
                                    <div class="modal-section-title">
                                        <i class="bi bi-person-badge"></i>
                                        <span>Credenciales del Administrador Principal</span>
                                    </div>
                                    <div class="alert alert-light border small text-secondary mb-3 d-flex align-items-center gap-2 rounded-3">
                                        <i class="bi bi-shield-check text-primary fs-5"></i>
                                        <span>Se creará la cuenta del Administrador de la Empresa. Ingresará con la contraseña temporal y deberá actualizarla al iniciar sesión.</span>
                                    </div>
                                    <div class="row g-3">
                                        <div class="col-12 col-md-6">
                                            <label for="admin_first_name" class="form-label small fw-semibold text-secondary mb-1">
                                                Nombres <span class="text-danger">*</span>
                                            </label>
                                            <input type="text" id="admin_first_name" name="admin_first_name" maxlength="100" class="form-control" placeholder="Ej. Carlos">
                                        </div>
                                        <div class="col-12 col-md-6">
                                            <label for="admin_last_name" class="form-label small fw-semibold text-secondary mb-1">
                                                Apellidos <span class="text-danger">*</span>
                                            </label>
                                            <input type="text" id="admin_last_name" name="admin_last_name" maxlength="100" class="form-control" placeholder="Ej. Negreiros">
                                        </div>
                                        <div class="col-12 col-md-6">
                                            <label for="admin_email" class="form-label small fw-semibold text-secondary mb-1">
                                                Correo Electrónico de Acceso <span class="text-danger">*</span>
                                            </label>
                                            <input type="email" id="admin_email" name="admin_email" class="form-control" placeholder="admin@empresa.com">
                                        </div>
                                        <div class="col-12 col-md-6">
                                            <label for="admin_password" class="form-label small fw-semibold text-secondary mb-1">
                                                Contraseña Temporal <span class="text-danger">*</span>
                                            </label>
                                            <div class="input-group">
                                                <input type="text" id="admin_password" name="admin_password" minlength="8" autocomplete="off" class="form-control font-monospace" placeholder="Temporal123">
                                                <button class="btn btn-outline-secondary" type="button" onclick="generateRandomPassword()" title="Generar clave aleatoria">
                                                    <i class="bi bi-arrow-repeat"></i>
                                                </button>
                                            </div>
                                            <div class="form-text small text-muted mt-1">Mínimo 8 caracteres.</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Modo Editar: Lista de Administradores Actuales -->
                            <div id="adminEditList" class="d-none">
                                <div class="modal-section-card mb-0">
                                    <div class="modal-section-title">
                                        <i class="bi bi-people"></i>
                                        <span>Administradores Asociados a esta Empresa</span>
                                    </div>
                                    <div id="adminsListContent" class="list-group list-group-flush border rounded-3 overflow-hidden">
                                        <!-- Injected via JavaScript -->
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Modal Footer Siempre Visible con Alineación Limpia -->
                <div class="modal-company-footer">
                    <button type="button" class="btn btn-light border px-4" data-bs-dismiss="modal" style="border-radius: 8px; font-weight: 500;">
                        Cancelar
                    </button>
                    <button type="submit" id="companySubmitBtn" class="btn btn-primary px-4 d-inline-flex align-items-center gap-2 shadow-sm" style="border-radius: 8px; font-weight: 600;">
                        <i class="bi bi-check2" id="companySubmitBtnIcon"></i>
                        <span id="companySubmitBtnText">Guardar cambios</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

@push('scripts')
<script>
    let companyModalInstance = null;

    function getCompanyModalInstance() {
        const modalEl = document.getElementById('companyModal');
        if (!modalEl) return null;
        if (window.bootstrap && window.bootstrap.Modal) {
            return bootstrap.Modal.getOrCreateInstance(modalEl);
        }
        return null;
    }

    document.addEventListener('DOMContentLoaded', () => {
        getCompanyModalInstance();

        // Live color text syncer
        const pColor = document.getElementById('primary_color');
        const pColorText = document.getElementById('primary_color_text');
        if (pColor && pColorText) {
            pColor.addEventListener('input', (e) => {
                pColorText.value = e.target.value.toUpperCase();
            });
        }

        const sColor = document.getElementById('secondary_color');
        const sColorText = document.getElementById('secondary_color_text');
        if (sColor && sColorText) {
            sColor.addEventListener('input', (e) => {
                sColorText.value = e.target.value.toUpperCase();
            });
        }

        // Handle auto-open if validation errors occurred on submit
        @if($errors->any())
            const isEditing = "{{ old('_method') }}" === 'PUT';
            if (isEditing) {
                const modal = getCompanyModalInstance();
                modal && modal.show();
            } else {
                openCreateCompanyModal();
            }
        @endif
    });

    function resetModalTabs() {
        const tabBtn = document.getElementById('tab-general-btn');
        if (tabBtn && window.bootstrap) {
            const trigger = new bootstrap.Tab(tabBtn);
            trigger.show();
        }
    }

    function generateRandomPassword() {
        const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#$%';
        let pass = '';
        for (let i = 0; i < 12; i++) {
            pass += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        const input = document.getElementById('admin_password');
        if (input) input.value = pass;
    }

    function openCreateCompanyModal() {
        resetModalTabs();
        const form = document.getElementById('companyForm');
        form.reset();
        form.action = "{{ route('companies.store') }}";
        document.getElementById('companyFormMethod').value = '';

        document.getElementById('companyModalLabel').textContent = 'Nueva empresa';
        document.getElementById('companyModalSublabel').textContent = 'Crea la empresa y su primer administrador para la plataforma.';
        document.getElementById('companySubmitBtnText').textContent = 'Crear empresa';
        document.getElementById('companyModalHeaderIconEl').className = 'bi bi-plus-circle';

        // Admin fields (Create mode)
        document.getElementById('adminCreateFields').classList.remove('d-none');
        document.getElementById('adminEditList').classList.add('d-none');
        document.getElementById('admin_first_name').required = true;
        document.getElementById('admin_last_name').required = true;
        document.getElementById('admin_email').required = true;
        document.getElementById('admin_password').required = true;

        // Branding
        document.getElementById('logoPreviewImg').classList.add('d-none');
        document.getElementById('logoPreviewPlaceholder').classList.remove('d-none');
        document.getElementById('removeLogoWrapper').classList.add('d-none');
        document.getElementById('primary_color').value = '#0284c7';
        document.getElementById('primary_color_text').value = '#0284C7';
        document.getElementById('secondary_color').value = '#F59E0B';
        document.getElementById('secondary_color_text').value = '#F59E0B';

        // Select defaults
        document.getElementById('plan').value = 'trial';
        document.getElementById('status').value = 'active';
        document.getElementById('timezone').value = 'America/Lima';

        // Check all modules by default for new company
        document.querySelectorAll('.module-checkbox').forEach(cb => {
            cb.checked = true;
        });

        // Show content
        document.getElementById('companyModalLoading').classList.add('d-none');
        document.getElementById('companyModalContent').classList.remove('d-none');

        const modal = getCompanyModalInstance();
        modal && modal.show();
    }

    async function openEditCompanyModal(id) {
        resetModalTabs();
        const form = document.getElementById('companyForm');
        form.reset();
        form.action = `/companies/${id}`;
        document.getElementById('companyFormMethod').value = 'PUT';

        document.getElementById('companyModalLabel').textContent = 'Editar empresa';
        document.getElementById('companyModalSublabel').textContent = 'Actualiza la información general, marca blanca y módulos de la empresa.';
        document.getElementById('companySubmitBtnText').textContent = 'Guardar cambios';
        document.getElementById('companyModalHeaderIconEl').className = 'bi bi-pencil-square';

        // Admin fields (Edit mode: list instead of create fields)
        document.getElementById('adminCreateFields').classList.add('d-none');
        document.getElementById('adminEditList').classList.remove('d-none');
        document.getElementById('admin_first_name').required = false;
        document.getElementById('admin_last_name').required = false;
        document.getElementById('admin_email').required = false;
        document.getElementById('admin_password').required = false;

        // Show loading state inside modal
        document.getElementById('companyModalLoading').classList.remove('d-none');
        document.getElementById('companyModalContent').classList.add('d-none');
        const modal = getCompanyModalInstance();
        modal && modal.show();

        try {
            const res = await fetch(`/companies/${id}/edit`, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (!res.ok) throw new Error('Error al obtener datos');
            const data = await res.json();
            const c = data.company;

            document.getElementById('companyModalLabel').textContent = `Editar empresa: ${c.company_name}`;
            document.getElementById('company_name').value = c.company_name || '';
            document.getElementById('ruc').value = c.ruc || '';
            document.getElementById('slug').value = c.slug || '';
            document.getElementById('custom_domain').value = c.custom_domain || '';
            document.getElementById('email').value = c.email || '';
            document.getElementById('phone').value = c.phone || '';
            document.getElementById('address').value = c.address || '';
            document.getElementById('plan').value = c.plan || 'trial';
            document.getElementById('status').value = c.status || 'active';
            document.getElementById('timezone').value = c.timezone || 'America/Lima';

            // Branding
            const pColor = c.primary_color || '#0284c7';
            const sColor = c.secondary_color || '#F59E0B';
            document.getElementById('primary_color').value = pColor;
            document.getElementById('primary_color_text').value = pColor.toUpperCase();
            document.getElementById('secondary_color').value = sColor;
            document.getElementById('secondary_color_text').value = sColor.toUpperCase();

            const previewImg = document.getElementById('logoPreviewImg');
            const placeholder = document.getElementById('logoPreviewPlaceholder');
            const removeWrapper = document.getElementById('removeLogoWrapper');

            if (data.logo_url) {
                previewImg.src = data.logo_url;
                previewImg.classList.remove('d-none');
                placeholder.classList.add('d-none');
                removeWrapper.classList.remove('d-none');
            } else {
                previewImg.src = '';
                previewImg.classList.add('d-none');
                placeholder.classList.remove('d-none');
                removeWrapper.classList.add('d-none');
            }

            // Modules
            const enabledMods = data.enabled_modules || [];
            document.querySelectorAll('.module-checkbox').forEach(cb => {
                cb.checked = enabledMods.includes(cb.value);
            });

            // Admins list
            const adminListEl = document.getElementById('adminsListContent');
            adminListEl.innerHTML = '';
            if (data.admins && data.admins.length > 0) {
                data.admins.forEach(a => {
                    const item = document.createElement('div');
                    item.className = 'list-group-item d-flex justify-content-between align-items-center py-2 px-3';
                    item.innerHTML = `
                        <div class="d-flex align-items-center gap-2">
                            <div class="avatar-sm bg-light text-primary rounded-circle d-flex align-items-center justify-content-center fw-bold">
                                ${Panel.esc((a.name || '?').charAt(0).toUpperCase())}
                            </div>
                            <div>
                                <div class="fw-semibold text-dark small">${Panel.esc(a.name)}</div>
                                <div class="text-secondary" style="font-size: 0.775rem;">${Panel.esc(a.email)}</div>
                            </div>
                        </div>
                        <span class="badge bg-success-subtle text-success border border-success-subtle fw-medium px-2 py-1" style="font-size: 0.75rem;">
                            ${a.status === 'active' ? 'Activo' : Panel.esc(a.status)}
                        </span>
                    `;
                    adminListEl.appendChild(item);
                });
            } else {
                adminListEl.innerHTML = '<div class="p-3 text-center text-muted small">No hay administradores registrados para esta empresa.</div>';
            }

            // Hide loading, show content
            document.getElementById('companyModalLoading').classList.add('d-none');
            document.getElementById('companyModalContent').classList.remove('d-none');

        } catch (err) {
            console.error(err);
            Panel.toast('No se pudo cargar la información de la empresa.', 'error');
            const modal = getCompanyModalInstance();
            modal && modal.hide();
        }
    }
</script>
@endpush
@endsection
