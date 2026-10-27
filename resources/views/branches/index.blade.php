@extends('layouts.app')

@section('title', 'Sucursales y geocercas GPS')

@section('content')
<div class="branches-page-wrapper">
    {{-- =========================================================================
         1. CABECERA EJECUTIVA DEL MÓDULO
         ========================================================================= --}}
    <div class="branches-header-card shadow-sm p-3 p-md-4 mb-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center" style="gap: 16px !important;">
                <div class="branches-header-icon shadow-sm">
                    <i class="bi bi-pin-map-fill"></i>
                </div>
                <div>
                    <div class="d-flex align-items-center flex-wrap mb-1" style="gap: 10px !important;">
                        <h1 class="h4 fw-bold mb-0 text-body">Sucursales y Geocercas GPS</h1>
                        <span class="badge rounded-2 bg-primary-subtle text-primary border border-primary-subtle px-2.5 py-1 small fw-semibold">
                            <i class="bi bi-shield-check me-1"></i>Validación Oficial
                        </span>
                        @if(!empty($companies))
                            <span class="badge rounded-2 bg-info-subtle text-info border border-info-subtle px-2.5 py-1 small fw-semibold">
                                <i class="bi bi-globe2 me-1"></i>Vista Global
                            </span>
                        @endif
                    </div>
                    <p class="text-secondary small mb-0">
                        Ubicación física de sedes operativas, cálculo de cobertura perimetral y validación de asistencia vía coordenadas GPS.
                    </p>
                </div>
            </div>

            <div class="d-flex align-items-center gap-2 flex-wrap">
                <button type="button" 
                        class="btn btn-primary d-inline-flex align-items-center shadow-sm"
                        style="height: 38px; border-radius: 8px; font-weight: 600; font-size: 0.85rem; padding: 0 1.25rem; gap: 8px;"
                        onclick="openCreateBranchModal()">
                    <i class="bi bi-plus-lg"></i>
                    <span>Nueva sucursal</span>
                </button>
            </div>
        </div>
    </div>

    {{-- =========================================================================
         2. TARJETAS DE MÉTRICAS KPI (RESUMEN EJECUTIVO)
         ========================================================================= --}}
    @php
        $totalBranches = $branches->count();
        $activeBranches = $branches->where('is_active', true)->count();
        $totalEmployees = $branches->sum('employees_count');
        $avgRadius = $totalBranches > 0 ? round($branches->avg('allowed_radius_meters')) : $defaultRadius;
        $activeRate = $totalBranches > 0 ? round(($activeBranches / $totalBranches) * 100) : 100;
    @endphp

    <div class="row g-3 mb-4">
        {{-- Total Sedes --}}
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="branches-kpi-card shadow-sm p-3 h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-muted fw-semibold small text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.05em;">Sedes Registradas</span>
                    <div class="branches-kpi-pill bg-primary-subtle text-primary">
                        <i class="bi bi-buildings-fill"></i>
                    </div>
                </div>
                <div class="d-flex align-items-baseline gap-2">
                    <span class="h3 fw-bold mb-0 text-body">{{ $totalBranches }}</span>
                    <span class="badge {{ $activeBranches === $totalBranches ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning-emphasis' }}">
                        {{ $activeBranches }} activas
                    </span>
                </div>
                <div class="text-muted small mt-1" style="font-size: 0.75rem;">
                    Distribuidas en {{ $zones->count() }} zonas territoriales
                </div>
            </div>
        </div>

        {{-- Sedes Operativas --}}
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="branches-kpi-card shadow-sm p-3 h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-muted fw-semibold small text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.05em;">Sedes Operativas</span>
                    <div class="branches-kpi-pill bg-emerald-subtle text-emerald">
                        <i class="bi bi-check-circle-fill"></i>
                    </div>
                </div>
                <div class="d-flex align-items-baseline gap-2">
                    <span class="h3 fw-bold mb-0 text-body">{{ $activeRate }}%</span>
                    <span class="text-muted small">cobertura activa</span>
                </div>
                <div class="text-muted small mt-1" style="font-size: 0.75rem;">
                    Habilitadas para marcación móvil y app
                </div>
            </div>
        </div>

        {{-- Colaboradores Asignados --}}
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="branches-kpi-card shadow-sm p-3 h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-muted fw-semibold small text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.05em;">Dotación Asignada</span>
                    <div class="branches-kpi-pill bg-info-subtle text-info">
                        <i class="bi bi-people-fill"></i>
                    </div>
                </div>
                <div class="d-flex align-items-baseline gap-2">
                    <span class="h3 fw-bold mb-0 text-body">{{ $totalEmployees }}</span>
                    <span class="text-muted small">colaboradores</span>
                </div>
                <div class="text-muted small mt-1" style="font-size: 0.75rem;">
                    Personal con sucursal física de trabajo
                </div>
            </div>
        </div>

        {{-- Geocerca Promedio --}}
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="branches-kpi-card shadow-sm p-3 h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-muted fw-semibold small text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.05em;">Radio Promedio</span>
                    <div class="branches-kpi-pill bg-amber-subtle text-amber">
                        <i class="bi bi-broadcast-pin"></i>
                    </div>
                </div>
                <div class="d-flex align-items-baseline gap-2">
                    <span class="h3 fw-bold mb-0 text-body">{{ $avgRadius }}</span>
                    <span class="text-muted small">metros</span>
                </div>
                <div class="text-muted small mt-1" style="font-size: 0.75rem;">
                    Margen de tolerancia GPS configurado
                </div>
            </div>
        </div>
    </div>

    {{-- =========================================================================
         3. BARRA DE FILTROS Y BÚSQUEDA REACTIVA EN TIEMPO REAL
         ========================================================================= --}}
    <div class="branches-filter-card shadow-sm p-3 mb-4">
        <div class="row g-3 align-items-center">
            {{-- Buscador reactivo por nombre, ciudad, código o supervisor --}}
            <div class="col-12 col-md-5 col-xl-4">
                <div class="position-relative">
                    <i class="bi bi-search text-muted" style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%); pointer-events: none; z-index: 5; font-size: 0.85rem;"></i>
                    <input type="text" 
                           id="branchesSearchInput" 
                           class="form-control form-control-sm" 
                           placeholder="Buscar por sede, ciudad, dirección o supervisor..." 
                           style="height: 38px; border-radius: 6px; font-size: 0.85rem; padding-left: 42px !important;"
                           oninput="filterBranchesGrid()">
                </div>
            </div>

            {{-- Filtro por Estado (Todas, Activas, Inactivas) --}}
            <div class="col-12 col-sm-6 col-md-3 col-xl-2">
                <div class="pretty-select pretty-select-sm">
                    <i class="bi bi-toggle-on pretty-select-icon" aria-hidden="true"></i>
                    <select id="branchesStatusFilter" class="form-select" aria-label="Estado de la sede" onchange="filterBranchesGrid()">
                        <option value="">Todos los estados</option>
                        <option value="active">Solo Activas</option>
                        <option value="inactive">Inactivas</option>
                    </select>
                </div>
            </div>

            {{-- Filtro por Tipo de Sede --}}
            <div class="col-12 col-sm-6 col-md-4 col-xl-3">
                <div class="pretty-select pretty-select-sm">
                    <i class="bi bi-building pretty-select-icon" aria-hidden="true"></i>
                    <select id="branchesTypeFilter" class="form-select" aria-label="Tipo de sede" onchange="filterBranchesGrid()">
                        <option value="">Todos los tipos de sede</option>
                        @foreach($branchTypes as $typeKey => $typeLabel)
                            <option value="{{ $typeKey }}">{{ $typeLabel }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            {{-- Filtro por Zona Territorial (si existen) --}}
            @if($zones->isNotEmpty())
                <div class="col-12 col-sm-6 col-md-4 col-xl-2">
                    <div class="pretty-select pretty-select-sm">
                    <i class="bi bi-diagram-3 pretty-select-icon" aria-hidden="true"></i>
                    <select id="branchesZoneFilter" class="form-select" aria-label="Zona" onchange="filterBranchesGrid()">
                            <option value="">Todas las zonas</option>
                            @foreach($zones as $z)
                                <option value="{{ $z->id }}">{{ $z->name }}</option>
                            @endforeach
                            <option value="none">Sin zona asignada</option>
                        </select>
                    </div>
                </div>
            @endif

            {{-- Botón Reset & Contador Presentado en Tarjeta / Chip con Esquinas Menos Redondas --}}
            <div class="col-12 col-xl text-xl-end d-flex align-items-center justify-content-between justify-content-xl-end ms-auto" style="gap: 10px !important;">
                <button type="button" 
                        class="btn-branch-reset"
                        title="Restablecer todos los filtros de búsqueda"
                        onclick="resetBranchesFilters()">
                    <i class="bi bi-arrow-counterclockwise"></i>
                    <span>Limpiar</span>
                </button>
                <div class="branches-counter-card" title="Total de sedes visibles según filtros">
                    <i class="bi bi-buildings-fill counter-icon"></i>
                    <span id="branchesCounter" class="counter-text">
                        Mostrando {{ $totalBranches }} de {{ $totalBranches }}
                    </span>
                </div>
            </div>
        </div>
    </div>

    {{-- =========================================================================
         4. GRID DE TARJETAS DE SUCURSALES (DISEÑO ESPACIOSO Y ELEGANTE)
         ========================================================================= --}}
    <div class="row g-4" id="branchesCardsContainer">
        @forelse($branches as $b)
            <div class="col-12 col-md-6 col-xxl-4 branch-card-wrapper"
                 data-name="{{ strtolower($b->name) }}"
                 data-city="{{ strtolower($b->city ?? '') }}"
                 data-address="{{ strtolower($b->address) }}"
                 data-code="{{ strtolower($b->code ?? '') }}"
                 data-supervisor="{{ strtolower($b->supervisor ? $b->supervisor->first_name . ' ' . $b->supervisor->last_name : '') }}"
                 data-status="{{ $b->is_active ? 'active' : 'inactive' }}"
                 data-type="{{ $b->branch_type ?? 'agency' }}"
                 data-zone="{{ $b->zone_id ? $b->zone_id : 'none' }}">
                <div class="branch-card shadow-sm h-100 d-flex flex-column">
                    {{-- Cabecera de la Tarjeta con Espaciado Generoso --}}
                    <div class="branch-card-header border-bottom">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="d-flex align-items-start min-w-0">
                                @php
                                    $iconMeta = match($b->branch_type ?? 'agency') {
                                        'terminal'  => ['icon' => 'bi-bus-front-fill', 'cls' => 'branch-type-terminal'],
                                        'warehouse' => ['icon' => 'bi-box-seam-fill', 'cls' => 'branch-type-warehouse'],
                                        'workshop'  => ['icon' => 'bi-tools', 'cls' => 'branch-type-workshop'],
                                        'corporate' => ['icon' => 'bi-building-fill-gear', 'cls' => 'branch-type-corporate'],
                                        default     => ['icon' => 'bi-buildings-fill', 'cls' => 'branch-type-agency'],
                                    };
                                @endphp
                                <div class="branch-type-avatar {{ $iconMeta['cls'] }}">
                                    <i class="bi {{ $iconMeta['icon'] }}"></i>
                                </div>
                                <div class="min-w-0">
                                    <h2 class="branch-title text-truncate" title="{{ $b->name }}">
                                        {{ $b->name }}
                                    </h2>
                                    <div class="d-flex align-items-center flex-wrap" style="gap: 8px !important;">
                                        @if($b->code)
                                            <span class="badge bg-body-secondary text-secondary font-monospace" style="font-size: 0.72rem; padding: 4px 8px; border-radius: 4px;">
                                                {{ $b->code }}
                                            </span>
                                        @endif
                                        <span class="badge bg-body-secondary text-secondary" style="font-size: 0.72rem; padding: 4px 8px; border-radius: 4px;">
                                            {{ $branchTypes[$b->branch_type] ?? 'Agencia' }}
                                        </span>
                                        @include('partials.tenant-badge', ['tenantId' => $b->tenant_id])
                                    </div>
                                </div>
                            </div>
                            
                            {{-- Badge de Estado con Indicador Pulsante --}}
                            <div class="flex-shrink-0 ms-2">
                                @if($b->is_active)
                                    <span class="branch-status-badge branch-status-active">
                                        <span class="branch-status-dot active"></span>
                                        <span>Activa</span>
                                    </span>
                                @else
                                    <span class="branch-status-badge branch-status-inactive">
                                        <span class="branch-status-dot inactive"></span>
                                        <span>Inactiva</span>
                                    </span>
                                @endif
                            </div>
                        </div>
                    </div>

                    {{-- Cuerpo de la Tarjeta con Espacios Limpios y Sin Amontonamiento --}}
                    <div class="branch-card-body flex-grow-1">
                        {{-- Bloque de Dirección Física --}}
                        <div class="branch-address-box">
                            <div class="branch-address-icon">
                                <i class="bi bi-geo-alt-fill"></i>
                            </div>
                            <div class="min-w-0 flex-grow-1">
                                <span class="text-body fw-medium d-block text-break" style="font-size: 0.85rem; line-height: 1.45;">
                                    {{ $b->address }}
                                </span>
                                @if($b->city)
                                    <span class="text-secondary small d-block mt-1" style="font-size: 0.775rem;">
                                        {{ $b->city }}, {{ $b->country ?? 'Perú' }}
                                    </span>
                                @endif
                            </div>
                        </div>

                        {{-- Cuadrícula de Métricas Operativas (Radio GPS & Colaboradores) --}}
                        <div class="branch-stats-grid">
                            <div class="branch-stat-mini-card">
                                <div class="branch-stat-header">
                                    <i class="bi bi-broadcast text-success"></i>
                                    <span class="branch-stat-label">Radio GPS</span>
                                </div>
                                <div class="branch-stat-val text-success">
                                    {{ $b->allowed_radius_meters }} <span class="unit">metros</span>
                                </div>
                            </div>
                            <div class="branch-stat-mini-card">
                                <div class="branch-stat-header">
                                    <i class="bi bi-people-fill text-primary"></i>
                                    <span class="branch-stat-label">Dotación</span>
                                </div>
                                <div class="branch-stat-val text-primary">
                                    {{ $b->employees_count }} <span class="unit">colaboradores</span>
                                </div>
                            </div>
                        </div>

                        {{-- Filas Estructuradas con Espacios Respetados (Supervisor & Zona) --}}
                        <div class="branch-info-list">
                            {{-- Fila de Supervisor --}}
                            <div class="branch-info-box">
                                <div class="d-flex align-items-center min-w-0">
                                    <div class="branch-icon-pill bg-info-subtle text-info">
                                        <i class="bi bi-person-badge"></i>
                                    </div>
                                    <span class="branch-info-label">Supervisor:</span>
                                </div>
                                <span class="branch-info-val text-body" title="{{ $b->supervisor ? $b->supervisor->first_name . ' ' . $b->supervisor->last_name : 'Sin asignar' }}">
                                    {{ $b->supervisor ? $b->supervisor->first_name . ' ' . $b->supervisor->last_name : 'Sin asignar' }}
                                </span>
                            </div>

                            {{-- Fila de Zona Territorial --}}
                            <div class="branch-info-box">
                                <div class="d-flex align-items-center min-w-0">
                                    <div class="branch-icon-pill bg-emerald-subtle text-emerald">
                                        <i class="bi bi-diagram-3"></i>
                                    </div>
                                    <span class="branch-info-label">Zona:</span>
                                </div>
                                <span class="branch-info-val text-secondary" title="{{ $b->zone ? $b->zone->name : 'Sin zona territorial asignada' }}">
                                    {{ $b->zone ? $b->zone->name : 'Sin zona territorial' }}
                                </span>
                            </div>
                        </div>
                    </div>

                    {{-- Pie de la Tarjeta: Coordenadas + Acciones con Espacios Respetados y SOLO Iconos --}}
                    <div class="branch-card-footer">
                        <div class="branch-coords-pill" title="Coordenadas GPS de la sede">
                            <i class="bi bi-geo-alt-fill text-danger"></i>
                            <span>{{ number_format((float) $b->latitude, 4) }}, {{ number_format((float) $b->longitude, 4) }}</span>
                        </div>

                        {{-- BOTONES DE ACCIÓN: SOLO ICONOS, SIN DUPLICADOS Y CON ESPACIADO ELEGANTE --}}
                        <div class="d-flex align-items-center" style="gap: 10px !important;">
                            {{-- Botón Editar: Abre Dialog Modal Profesional Directamente en la Misma Vista --}}
                            <button type="button" 
                                    class="branch-action-btn branch-action-btn-edit"
                                    title="Editar datos y geocerca de {{ $b->name }}"
                                    aria-label="Editar {{ $b->name }}"
                                    onclick='openEditBranchModal(@json($b))'>
                                <i class="bi bi-pencil-square"></i>
                            </button>

                            {{-- Eliminar Sede --}}
                            <button type="button" 
                                    class="branch-action-btn branch-action-btn-delete"
                                    data-bs-toggle="modal" 
                                    data-bs-target="#deleteBranchModal"
                                    data-action="{{ route('branches.destroy', $b->id) }}" 
                                    data-name="{{ $b->name }}" 
                                    data-employees="{{ $b->employees_count }}" 
                                    title="Eliminar sede"
                                    aria-label="Eliminar {{ $b->name }}">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-12" id="branchesEmptyState">
                <div class="branch-card p-5 text-center text-muted">
                    <i class="bi bi-pin-map fs-1 opacity-50 mb-3 d-block text-primary"></i>
                    <h3 class="h5 fw-bold text-body">No hay sucursales registradas</h3>
                    <p class="small text-muted mb-3">Crea la primera sucursal para definir su geocerca y validar la presencia de tu personal.</p>
                    <button type="button" class="btn btn-primary btn-sm px-3" onclick="openCreateBranchModal()">
                        <i class="bi bi-plus-lg me-1"></i>Crear primera sucursal
                    </button>
                </div>
            </div>
        @endforelse
    </div>

    {{-- Estado sin resultados de búsqueda --}}
    <div class="col-12 d-none mt-4" id="branchesNoResultsState">
        <div class="branch-card p-5 text-center text-muted">
            <i class="bi bi-search fs-1 opacity-50 mb-3 d-block text-primary"></i>
            <h3 class="h5 fw-bold text-body">No se encontraron sucursales</h3>
            <p class="small text-muted mb-3">Ninguna sede coincide con los criterios de búsqueda o filtros seleccionados.</p>
            <button type="button" class="btn btn-outline-primary btn-sm px-3" onclick="resetBranchesFilters()">
                <i class="bi bi-arrow-counterclockwise me-1"></i>Restablecer filtros
            </button>
        </div>
    </div>
</div>

{{-- =========================================================================
     5. MODAL DIALOG PROFESIONAL: CREAR / EDITAR SUCURSAL Y GEOCERCA
     ========================================================================= --}}
<div class="modal fade" id="branchFormModal" tabindex="-1" aria-labelledby="branchFormModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable modal-fullscreen-sm-down modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 12px; overflow: hidden;">
            {{-- Cabecera del Modal --}}
            <div class="modal-header py-3 px-4 border-bottom">
                <div class="d-flex align-items-center" style="gap: 14px !important;">
                    <div class="branches-header-icon shadow-sm" style="width: 42px; height: 42px; font-size: 1.25rem;">
                        <i class="bi bi-buildings-fill"></i>
                    </div>
                    <div>
                        <h2 class="h6 fw-bold mb-0 text-body" id="branchModalTitle">Editar Sede Operativa</h2>
                        <span class="small text-muted" style="font-size: 0.775rem;">
                            Define los datos corporativos, geocerca GPS y responsable de la sede.
                        </span>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>

            {{-- Formulario --}}
            <form id="branchModalForm" method="POST" action="">
                @csrf
                <input type="hidden" name="_method" id="branchFormMethod" value="POST">

                <div class="modal-body p-4" style="max-height: calc(85vh - 120px); overflow-y: auto;">
                    <div class="row g-3">
                        {{-- Selector de Empresa (Si es Vista Global) --}}
                        @if(!empty($companies))
                            <div class="col-12" id="branchCompanyFieldWrapper">
                                <label for="m_tenant_id" class="form-label fw-semibold small text-body mb-1">
                                    Empresa <span class="text-danger">*</span>
                                </label>
                                <div class="pretty-select">
                                    <i class="bi bi-buildings pretty-select-icon" aria-hidden="true"></i>
                                    <select id="m_tenant_id" name="tenant_id" class="form-select" required>
                                    <option value="">Selecciona la empresa...</option>
                                    @foreach($companies as $cId => $cName)
                                        <option value="{{ $cId }}">{{ $cName }}</option>
                                    @endforeach
                                </select>
                                </div>
                            </div>
                        @endif

                        {{-- Nombre de la Sede --}}
                        <div class="col-12 col-md-8">
                            <label for="m_name" class="form-label fw-semibold small text-body mb-1">
                                Nombre de la Sede u Obra <span class="text-danger">*</span>
                            </label>
                            <input type="text" 
                                   id="m_name" 
                                   name="name" 
                                   class="form-control" 
                                   placeholder="Ej.: Sede Principal - Trujillo, Terminal Terrestre..."
                                   style="height: 40px; border-radius: 8px; font-size: 0.85rem;" 
                                   required>
                        </div>

                        {{-- Código Interno --}}
                        <div class="col-12 col-md-4">
                            <label for="m_code" class="form-label fw-semibold small text-body mb-1">
                                Código Interno <span class="text-muted fw-normal">(Opcional)</span>
                            </label>
                            <input type="text" 
                                   id="m_code" 
                                   name="code" 
                                   class="form-control" 
                                   placeholder="Ej.: SED-01, TRU-AG"
                                   style="height: 40px; border-radius: 8px; font-size: 0.85rem;">
                        </div>

                        {{-- Serie de documentos (RQ/OC/OS) --}}
                        <div class="col-12 col-md-4">
                            <label for="m_document_series" class="form-label fw-semibold small text-body mb-1">
                                Serie de documentos <span class="text-muted fw-normal">(RQ/OC/OS)</span>
                            </label>
                            <input type="text" id="m_document_series" name="document_series" class="form-control text-uppercase" maxlength="20"
                                   placeholder="Ej.: 001 (vacío = código interno)"
                                   style="height: 40px; border-radius: 8px; font-size: 0.85rem;">
                        </div>

                        {{-- Tipo de Sede --}}
                        <div class="col-12 col-md-4">
                            <label for="m_branch_type" class="form-label fw-semibold small text-body mb-1">
                                Tipo de Sede <span class="text-danger">*</span>
                            </label>
                            <select id="m_branch_type" name="branch_type" class="form-select" style="height: 40px; border-radius: 8px; font-size: 0.85rem;" required>
                                @foreach($branchTypes as $typeKey => $typeLabel)
                                    <option value="{{ $typeKey }}">{{ $typeLabel }}</option>
                                @endforeach
                            </select>
                        </div>

                        {{-- Zona Territorial --}}
                        <div class="col-12 col-md-4">
                            <label for="m_zone_id" class="form-label fw-semibold small text-body mb-1">
                                Zona Territorial <span class="text-muted fw-normal">(Opcional)</span>
                            </label>
                            <select id="m_zone_id" name="zone_id" class="form-select" style="height: 40px; border-radius: 8px; font-size: 0.85rem;">
                                <option value="">Sin zona territorial</option>
                                @foreach($zones as $z)
                                    <option value="{{ $z->id }}">{{ $z->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        {{-- Supervisor / Responsable --}}
                        <div class="col-12 col-md-4">
                            <label for="m_supervisor_id" class="form-label fw-semibold small text-body mb-1">
                                Supervisor / Encargado <span class="text-muted fw-normal">(Opcional)</span>
                            </label>
                            <select id="m_supervisor_id" name="supervisor_id" class="form-select" style="height: 40px; border-radius: 8px; font-size: 0.85rem;">
                                <option value="">Sin supervisor asignado</option>
                                @foreach($supervisors as $s)
                                    <option value="{{ $s->id }}" data-tenant="{{ $s->tenant_id }}">
                                        {{ $s->first_name }} {{ $s->last_name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        {{-- Ciudad --}}
                        <div class="col-12 col-md-4">
                            <label for="m_city" class="form-label fw-semibold small text-body mb-1">
                                Ciudad / Región
                            </label>
                            <input type="text" 
                                   id="m_city" 
                                   name="city" 
                                   class="form-control" 
                                   placeholder="Ej.: Trujillo, Lima, Arequipa..."
                                   style="height: 40px; border-radius: 8px; font-size: 0.85rem;">
                        </div>

                        {{-- Dirección Completa --}}
                        <div class="col-12 col-md-8">
                            <label for="m_address" class="form-label fw-semibold small text-body mb-1">
                                Dirección Física <span class="text-danger">*</span>
                            </label>
                            <input type="text" 
                                   id="m_address" 
                                   name="address" 
                                   class="form-control" 
                                   placeholder="Ej.: Av. España 1234, Oficina 201"
                                   style="height: 40px; border-radius: 8px; font-size: 0.85rem;" 
                                   required>
                        </div>

                        {{-- SECCIÓN: GEOCERCA GPS INTERACTIVA --}}
                        <div class="col-12">
                            <div class="branch-modal-section-card">
                                <div class="row g-3">
                                    {{-- Cabecera de la sección --}}
                                    <div class="col-12">
                                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 pb-2 border-bottom">
                                            <div class="d-flex align-items-center" style="gap: 12px !important;">
                                                <div class="d-flex align-items-center justify-content-center bg-danger-subtle text-danger rounded-circle" style="width: 34px; height: 34px; flex-shrink: 0;">
                                                    <i class="bi bi-geo-alt-fill fs-6"></i>
                                                </div>
                                                <div>
                                                    <span class="fw-bold text-body small d-block">Geocerca GPS y Marcación de Asistencia</span>
                                                    <span class="text-muted" style="font-size: 0.735rem;">Coordenadas y radio para validar la presencia del personal</span>
                                                </div>
                                            </div>
                                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                                <div class="btn-group btn-group-sm" role="group" aria-label="Capa de mapa">
                                                    <button type="button" class="btn btn-sm btn-primary" id="btnMapLayerStreets" onclick="switchModalMapLayer('streets')" title="Vista mapa de calles">
                                                        <i class="bi bi-map me-1"></i>Mapa
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnMapLayerSatellite" onclick="switchModalMapLayer('satellite')" title="Vista satelital">
                                                        <i class="bi bi-globe-americas me-1"></i>Satélite
                                                    </button>
                                                </div>
                                                <button type="button" 
                                                        id="btnModalCurrentLocation"
                                                        class="btn btn-sm btn-outline-primary d-inline-flex align-items-center"
                                                        style="border-radius: 6px; font-size: 0.785rem; padding: 6px 14px; gap: 6px !important; font-weight: 500;"
                                                        onclick="detectModalGPSLocation()">
                                                    <i class="bi bi-crosshair"></i>
                                                    <span id="btnModalGpsText">Mi ubicación</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    {{-- Mini Mapa Leaflet Interactivo en el Modal --}}
                                    <div class="col-12">
                                        <div class="branch-map-container position-relative">
                                            <div id="branchModalLeafletMap" style="height: 220px; width: 100%; border-radius: 8px; z-index: 1;"></div>
                                            <div class="branch-map-overlay-hint small text-muted">
                                                <i class="bi bi-info-circle me-1"></i>Haz clic en el mapa o arrastra el marcador para ajustar la posición.
                                            </div>
                                        </div>
                                    </div>

                                    {{-- Inputs de Coordenadas y Radio --}}
                                    <div class="col-12 col-md-4">
                                        <label for="m_latitude" class="form-label small fw-semibold text-secondary mb-1">
                                            Latitud GPS <span class="text-danger">*</span>
                                        </label>
                                        <input type="number" 
                                               id="m_latitude" 
                                               name="latitude" 
                                               step="0.000001" 
                                               class="form-control form-control-sm font-monospace" 
                                               style="height: 38px; border-radius: 6px; font-size: 0.85rem;" 
                                               required
                                               onchange="syncModalMapFromInputs()">
                                    </div>

                                    <div class="col-12 col-md-4">
                                        <label for="m_longitude" class="form-label small fw-semibold text-secondary mb-1">
                                            Longitud GPS <span class="text-danger">*</span>
                                        </label>
                                        <input type="number" 
                                               id="m_longitude" 
                                               name="longitude" 
                                               step="0.000001" 
                                               class="form-control form-control-sm font-monospace" 
                                               style="height: 38px; border-radius: 6px; font-size: 0.85rem;" 
                                               required
                                               onchange="syncModalMapFromInputs()">
                                    </div>

                                    <div class="col-12 col-md-4">
                                        <label for="m_allowed_radius_meters" class="form-label small fw-semibold text-secondary mb-1">
                                            Radio Permitido (metros) <span class="text-danger">*</span>
                                        </label>
                                        <input type="number" 
                                               id="m_allowed_radius_meters" 
                                               name="allowed_radius_meters" 
                                               min="10" 
                                               max="5000" 
                                               class="form-control form-control-sm fw-bold" 
                                               style="height: 38px; border-radius: 6px; font-size: 0.85rem;" 
                                               required
                                               oninput="syncModalRadiusFromInput()">
                                    </div>

                                    {{-- Botones de Preset de Distancia --}}
                                    <div class="col-12">
                                        <div class="pt-1">
                                            <div class="d-flex align-items-center justify-content-between mb-2">
                                                <span class="small fw-semibold text-secondary" style="font-size: 0.785rem;">
                                                    Distancias habituales recomendadas:
                                                </span>
                                                <span id="m_radiusBadge" class="badge bg-body-secondary text-secondary border fw-medium" style="font-size: 0.725rem;">
                                                    Radio: 100 m
                                                </span>
                                            </div>
                                            <div class="d-flex align-items-center flex-wrap" style="gap: 8px !important;">
                                                @foreach([50, 100, 200, 500, 1000] as $rPreset)
                                                    <button type="button" 
                                                            class="btn btn-sm btn-outline-secondary branch-preset-btn"
                                                            data-radius="{{ $rPreset }}"
                                                            style="border-radius: 6px !important; font-size: 0.8rem; font-weight: 600; padding: 6px 14px !important;"
                                                            onclick="setModalRadiusPreset({{ $rPreset }})">
                                                        {{ $rPreset }} metros
                                                    </button>
                                                @endforeach
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Switch Sede Activa --}}
                        <div class="col-12">
                            <div class="branch-modal-section-card d-flex align-items-center justify-content-between">
                                <div class="d-flex align-items-center" style="gap: 12px !important;">
                                    <div class="d-flex align-items-center justify-content-center bg-success-subtle text-success rounded-circle" style="width: 32px; height: 32px; flex-shrink: 0;">
                                        <i class="bi bi-power fs-6"></i>
                                    </div>
                                    <div>
                                        <div class="fw-semibold text-body small">Sede Activa</div>
                                        <div class="text-muted" style="font-size: 0.75rem;">Las sedes inactivas no permiten marcación ni aparecen en turnos laborales.</div>
                                    </div>
                                </div>
                                <div class="form-check form-switch m-0 ps-3">
                                    <input type="checkbox" 
                                           id="m_is_active" 
                                           name="is_active" 
                                           value="1" 
                                           class="form-check-input" 
                                           role="switch" 
                                           style="cursor: pointer; width: 42px; height: 22px;" 
                                           checked>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Pie del Modal --}}
                <div class="modal-footer py-3 px-4 border-top d-flex justify-content-end" style="gap: 10px !important;">
                    <button type="button" class="btn btn-outline-secondary btn-sm px-3" style="height: 38px; border-radius: 6px; font-weight: 600;" data-bs-dismiss="modal">
                        Cancelar
                    </button>
                    <button type="submit" 
                            id="btnSubmitBranchModal"
                            class="btn btn-primary btn-sm d-inline-flex align-items-center justify-content-center px-4" 
                            style="height: 38px; border-radius: 6px; font-weight: 600; min-width: 140px; gap: 8px !important;">
                        <i class="bi bi-check2 fs-6"></i>
                        <span id="btnSubmitBranchText">Guardar cambios</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- =========================================================================
     6. MODAL DIALOG DE CONFIRMACIÓN DE BORRADO
     ========================================================================= --}}
<div class="modal fade" id="deleteBranchModal" tabindex="-1" aria-labelledby="deleteBranchTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="" class="modal-content border-0 shadow-lg" id="deleteBranchForm" style="border-radius: 12px; overflow: hidden;">
            @csrf 
            @method('DELETE')
            <div class="modal-header py-3 px-4 border-bottom">
                <div class="d-flex align-items-center" style="gap: 12px !important;">
                    <div class="d-flex align-items-center justify-content-center bg-danger-subtle text-danger rounded-circle" style="width: 36px; height: 36px; flex-shrink: 0;">
                        <i class="bi bi-trash fs-6"></i>
                    </div>
                    <h2 class="modal-title fs-6 fw-bold mb-0 text-body" id="deleteBranchTitle">Eliminar sucursal</h2>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body p-4">
                <p class="mb-3 text-body" style="font-size: 0.9rem;">
                    ¿Estás seguro de eliminar permanentemente la sucursal <strong id="deleteBranchName"></strong>? Su geocerca GPS dejará de estar disponible para la validación de presencia.
                </p>
                <div class="alert alert-warning small mb-0 d-none d-flex align-items-start gap-2 border-0" id="deleteBranchWarning" style="border-radius: 8px;">
                    <i class="bi bi-exclamation-triangle-fill fs-6 flex-shrink-0 mt-0.5"></i>
                    <div>
                        <strong>Atención:</strong> Esta sede tiene colaboradores activos asignados. Debes reasignarlos a otra sede antes de poder eliminarla.
                    </div>
                </div>
            </div>
            <div class="modal-footer py-3 px-4 border-top d-flex justify-content-end" style="gap: 10px !important;">
                <button type="button" class="btn btn-outline-secondary btn-sm px-3" style="height: 38px; border-radius: 6px; font-weight: 600;" data-bs-dismiss="modal">
                    Cancelar
                </button>
                <button type="submit" id="btnConfirmDeleteBranch" class="btn btn-danger btn-sm d-inline-flex align-items-center justify-content-center px-4" style="height: 38px; border-radius: 6px; font-weight: 600; gap: 8px;">
                    <i class="bi bi-trash"></i>
                    <span>Sí, eliminar</span>
                </button>
            </div>
        </form>
    </div>
</div>
@endsection

@section('scripts')
<script>
    {{-- Variables de Leaflet para el Modal --}}
    let modalMap = null;
    let modalMarker = null;
    let modalCircle = null;
    let currentTileLayer = null;
    let currentTileMode = 'streets';
    let defaultRadiusVal = {{ $defaultRadius ?? 100 }};

    const streetTileUrl = 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';
    const streetTileAttrib = '© OpenStreetMap';
    const satelliteTileUrl = 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}';
    const satelliteTileAttrib = '© Esri, Maxar, Earthstar Geographics';

    window.switchModalMapLayer = function(type) {
        if (!modalMap) return;
        currentTileMode = type;
        if (currentTileLayer) {
            modalMap.removeLayer(currentTileLayer);
        }
        const btnStreets = document.getElementById('btnMapLayerStreets');
        const btnSat = document.getElementById('btnMapLayerSatellite');

        if (type === 'satellite') {
            currentTileLayer = L.tileLayer(satelliteTileUrl, { maxZoom: 19, attribution: satelliteTileAttrib }).addTo(modalMap);
            if (currentTileLayer.bringToBack) currentTileLayer.bringToBack();
            if (btnStreets) { btnStreets.classList.remove('btn-primary'); btnStreets.classList.add('btn-outline-secondary'); }
            if (btnSat) { btnSat.classList.remove('btn-outline-secondary'); btnSat.classList.add('btn-primary'); }
        } else {
            currentTileLayer = L.tileLayer(streetTileUrl, { maxZoom: 19, attribution: streetTileAttrib }).addTo(modalMap);
            if (currentTileLayer.bringToBack) currentTileLayer.bringToBack();
            if (btnSat) { btnSat.classList.remove('btn-primary'); btnSat.classList.add('btn-outline-secondary'); }
            if (btnStreets) { btnStreets.classList.remove('btn-outline-secondary'); btnStreets.classList.add('btn-primary'); }
        }
    };

    document.addEventListener('DOMContentLoaded', function () {
        {{-- Inicializar Leaflet al mostrar el modal --}}
        const branchModalEl = document.getElementById('branchFormModal');
        if (branchModalEl) {
            branchModalEl.addEventListener('shown.bs.modal', function () {
                initOrInvalidateModalMap();
            });
        }

        {{-- Modal de eliminación --}}
        const deleteModalEl = document.getElementById('deleteBranchModal');
        if (deleteModalEl) {
            deleteModalEl.addEventListener('show.bs.modal', function (event) {
                const btn = event.relatedTarget;
                if (!btn) return;
                const action = btn.getAttribute('data-action');
                const name = btn.getAttribute('data-name');
                const employees = parseInt(btn.getAttribute('data-employees') || '0', 10);

                document.getElementById('deleteBranchForm').action = action;
                document.getElementById('deleteBranchName').textContent = name;

                const warnEl = document.getElementById('deleteBranchWarning');
                const submitBtn = document.getElementById('btnConfirmDeleteBranch');
                if (employees > 0) {
                    warnEl.classList.remove('d-none');
                    submitBtn.disabled = true;
                } else {
                    warnEl.classList.add('d-none');
                    submitBtn.disabled = false;
                }
            });
        }
    });

    {{-- Abrir Modal en Modo Crear --}}
    window.openCreateBranchModal = function() {
        document.getElementById('branchModalTitle').textContent = 'Crear Nueva Sede Operativa';
        document.getElementById('btnSubmitBranchText').textContent = 'Crear Sede';
        document.getElementById('branchFormMethod').value = 'POST';
        document.getElementById('branchModalForm').action = "{{ route('branches.store') }}";

        {{-- Resetear campos --}}
        document.getElementById('m_name').value = '';
        document.getElementById('m_code').value = '';
        document.getElementById('m_document_series').value = '';
        document.getElementById('m_branch_type').value = 'agency';
        document.getElementById('m_zone_id').value = '';
        document.getElementById('m_supervisor_id').value = '';
        document.getElementById('m_city').value = '';
        document.getElementById('m_address').value = '';
        document.getElementById('m_latitude').value = '-12.046374';
        document.getElementById('m_longitude').value = '-77.042793';
        document.getElementById('m_allowed_radius_meters').value = defaultRadiusVal;
        document.getElementById('m_is_active').checked = true;

        const tenantSelect = document.getElementById('m_tenant_id');
        if (tenantSelect) tenantSelect.value = '';

        setModalRadiusPreset(defaultRadiusVal);

        const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('branchFormModal'));
        modal.show();
    };

    {{-- Abrir Modal en Modo Editar con los datos de la sucursal --}}
    window.openEditBranchModal = function(branch) {
        document.getElementById('branchModalTitle').textContent = `Editar Sede · ${branch.name}`;
        document.getElementById('btnSubmitBranchText').textContent = 'Guardar cambios';
        document.getElementById('branchFormMethod').value = 'PUT';
        document.getElementById('branchModalForm').action = `/branches/${branch.id}`;

        document.getElementById('m_name').value = branch.name || '';
        document.getElementById('m_code').value = branch.code || '';
        document.getElementById('m_document_series').value = branch.document_series || '';
        document.getElementById('m_branch_type').value = branch.branch_type || 'agency';
        document.getElementById('m_zone_id').value = branch.zone_id || '';
        document.getElementById('m_supervisor_id').value = branch.supervisor_id || '';
        document.getElementById('m_city').value = branch.city || '';
        document.getElementById('m_address').value = branch.address || '';
        document.getElementById('m_latitude').value = parseFloat(branch.latitude || -12.046374).toFixed(6);
        document.getElementById('m_longitude').value = parseFloat(branch.longitude || -77.042793).toFixed(6);
        
        const rad = parseInt(branch.allowed_radius_meters, 10) || defaultRadiusVal;
        document.getElementById('m_allowed_radius_meters').value = rad;
        document.getElementById('m_is_active').checked = Boolean(branch.is_active);

        const tenantSelect = document.getElementById('m_tenant_id');
        if (tenantSelect) tenantSelect.value = branch.tenant_id || '';

        setModalRadiusPreset(rad);

        const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('branchFormModal'));
        modal.show();
    };

    {{-- Inicializar o refrescar Leaflet dentro del Modal --}}
    function initOrInvalidateModalMap() {
        const latVal = parseFloat(document.getElementById('m_latitude').value) || -12.046374;
        const lngVal = parseFloat(document.getElementById('m_longitude').value) || -77.042793;
        const radVal = parseInt(document.getElementById('m_allowed_radius_meters').value, 10) || defaultRadiusVal;

        const mapContainer = document.getElementById('branchModalLeafletMap');
        if (!mapContainer) return;

        if (!modalMap) {
            modalMap = L.map('branchModalLeafletMap').setView([latVal, lngVal], 16);
            if (currentTileMode === 'satellite') {
                currentTileLayer = L.tileLayer(satelliteTileUrl, { maxZoom: 19, attribution: satelliteTileAttrib }).addTo(modalMap);
            } else {
                currentTileLayer = L.tileLayer(streetTileUrl, { maxZoom: 19, attribution: streetTileAttrib }).addTo(modalMap);
            }

            modalMarker = L.marker([latVal, lngVal], { draggable: true, title: 'Ubicación central' }).addTo(modalMap);
            modalCircle = L.circle([latVal, lngVal], {
                radius: radVal,
                color: '#0284c7',
                fillColor: '#0284c7',
                fillOpacity: 0.18,
                weight: 2
            }).addTo(modalMap);

            {{-- Al arrastrar el marcador --}}
            modalMarker.on('drag', function(e) {
                const pos = e.target.getLatLng();
                document.getElementById('m_latitude').value = pos.lat.toFixed(6);
                document.getElementById('m_longitude').value = pos.lng.toFixed(6);
                modalCircle.setLatLng(pos);
            });

            {{-- Al hacer clic en el mapa --}}
            modalMap.on('click', function(e) {
                const pos = e.latlng;
                document.getElementById('m_latitude').value = pos.lat.toFixed(6);
                document.getElementById('m_longitude').value = pos.lng.toFixed(6);
                modalMarker.setLatLng(pos);
                modalCircle.setLatLng(pos);
            });
        } else {
            modalMap.invalidateSize();
            modalMarker.setLatLng([latVal, lngVal]);
            modalCircle.setLatLng([latVal, lngVal]);
            modalCircle.setRadius(radVal);
            modalMap.setView([latVal, lngVal], 16);
        }
    }

    {{-- Sincronizar mapa cuando se escriben coordenadas manualmente --}}
    window.syncModalMapFromInputs = function() {
        if (!modalMap || !modalMarker || !modalCircle) return;
        const latVal = parseFloat(document.getElementById('m_latitude').value);
        const lngVal = parseFloat(document.getElementById('m_longitude').value);
        if (!isNaN(latVal) && !isNaN(lngVal)) {
            modalMarker.setLatLng([latVal, lngVal]);
            modalCircle.setLatLng([latVal, lngVal]);
            modalMap.panTo([latVal, lngVal]);
        }
    };

    {{-- Sincronizar radio cuando se escribe en el input --}}
    window.syncModalRadiusFromInput = function() {
        const rad = parseInt(document.getElementById('m_allowed_radius_meters').value, 10) || 50;
        document.getElementById('m_radiusBadge').textContent = `Radio: ${rad} m`;
        if (modalCircle) modalCircle.setRadius(rad);
        updatePresetButtons(rad);
    };

    {{-- Ajustar preset de radio --}}
    window.setModalRadiusPreset = function(meters) {
        document.getElementById('m_allowed_radius_meters').value = meters;
        document.getElementById('m_radiusBadge').textContent = `Radio: ${meters} m`;
        if (modalCircle) modalCircle.setRadius(meters);
        updatePresetButtons(meters);
    };

    function updatePresetButtons(currentVal) {
        document.querySelectorAll('.branch-preset-btn').forEach(btn => {
            const r = parseInt(btn.getAttribute('data-radius'), 10);
            if (r === currentVal) {
                btn.classList.add('btn-primary', 'shadow-sm');
                btn.classList.remove('btn-outline-secondary');
            } else {
                btn.classList.remove('btn-primary', 'shadow-sm');
                btn.classList.add('btn-outline-secondary');
            }
        });
    }

    {{-- Detectar GPS actual en el Modal (Feedback visual moderno) --}}
    window.detectModalGPSLocation = function() {
        const btn = document.getElementById('btnModalCurrentLocation');
        const text = document.getElementById('btnModalGpsText');
        if (!navigator.geolocation) {
            showToastFeedback('Tu navegador no soporta geolocalización GPS.', 'warning');
            return;
        }

        btn.disabled = true;
        text.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Obteniendo GPS...';

        navigator.geolocation.getCurrentPosition(
            function (pos) {
                btn.disabled = false;
                text.textContent = 'Usar mi ubicación actual';
                const lat = Number(pos.coords.latitude.toFixed(6));
                const lng = Number(pos.coords.longitude.toFixed(6));
                document.getElementById('m_latitude').value = lat;
                document.getElementById('m_longitude').value = lng;
                syncModalMapFromInputs();
                showToastFeedback('Coordenadas GPS obtenidas con éxito.', 'success');
            },
            function () {
                btn.disabled = false;
                text.textContent = 'Usar mi ubicación actual';
                showToastFeedback('No se pudo obtener la ubicación GPS. Verifica los permisos en el navegador.', 'warning');
            },
            { enableHighAccuracy: true, timeout: 10000 }
        );
    };

    {{-- Filtrado Instantáneo en Vivo en el Grid de Sedes --}}
    window.filterBranchesGrid = function() {
        const query = (document.getElementById('branchesSearchInput').value || '').toLowerCase().trim();
        const status = document.getElementById('branchesStatusFilter').value;
        const type = document.getElementById('branchesTypeFilter').value;
        const zoneFilter = document.getElementById('branchesZoneFilter');
        const zone = zoneFilter ? zoneFilter.value : '';

        const cards = document.querySelectorAll('.branch-card-wrapper');
        let visibleCount = 0;

        cards.forEach(card => {
            const cName = card.getAttribute('data-name') || '';
            const cCity = card.getAttribute('data-city') || '';
            const cAddress = card.getAttribute('data-address') || '';
            const cCode = card.getAttribute('data-code') || '';
            const cSupervisor = card.getAttribute('data-supervisor') || '';
            const cStatus = card.getAttribute('data-status') || '';
            const cType = card.getAttribute('data-type') || '';
            const cZone = card.getAttribute('data-zone') || '';

            const matchesQuery = !query || cName.includes(query) || cCity.includes(query) || cAddress.includes(query) || cCode.includes(query) || cSupervisor.includes(query);
            const matchesStatus = !status || cStatus === status;
            const matchesType = !type || cType === type;
            const matchesZone = !zone || cZone === zone;

            if (matchesQuery && matchesStatus && matchesType && matchesZone) {
                card.classList.remove('d-none');
                visibleCount++;
            } else {
                card.classList.add('d-none');
            }
        });

        {{-- Actualizar contador --}}
        const counterEl = document.getElementById('branchesCounter');
        if (counterEl) {
            counterEl.textContent = `Mostrando ${visibleCount} de ${cards.length}`;
        }

        {{-- Mostrar estado vacío si ningún resultado coincide --}}
        const noResults = document.getElementById('branchesNoResultsState');
        if (noResults) {
            if (visibleCount === 0 && cards.length > 0) {
                noResults.classList.remove('d-none');
            } else {
                noResults.classList.add('d-none');
            }
        }
    };

    {{-- Resetear Filtros --}}
    window.resetBranchesFilters = function() {
        document.getElementById('branchesSearchInput').value = '';
        document.getElementById('branchesStatusFilter').value = '';
        document.getElementById('branchesTypeFilter').value = '';
        const zoneFilter = document.getElementById('branchesZoneFilter');
        if (zoneFilter) zoneFilter.value = '';
        filterBranchesGrid();
    };

    {{-- Toast Feedback Moderno --}}
    function showToastFeedback(message, type) {
        if (window.Panel && typeof Panel.toast === 'function') {
            Panel.toast(message, type);
            return;
        }
        let stack = document.querySelector('.toast-stack');
        if (!stack) {
            stack = document.createElement('div');
            stack.className = 'toast-stack';
            document.body.appendChild(stack);
        }
        const alert = document.createElement('div');
        alert.className = `alert toast-item toast-${type} shadow-lg`;
        alert.innerHTML = `
            <div class="toast-icon-badge toast-badge-${type}">
                <i class="bi ${type === 'success' ? 'bi-check-lg' : 'bi-exclamation-triangle-fill'}"></i>
            </div>
            <div class="toast-content">
                <strong class="toast-title">${type === 'success' ? 'Completado' : 'Aviso'}</strong>
                <span class="toast-text">${Panel.esc(message)}</span>
            </div>
        `;
        stack.appendChild(alert);
        setTimeout(() => {
            alert.classList.add('is-leaving');
            setTimeout(() => alert.remove(), 250);
        }, 4000);
    }
</script>
@endsection

@section('styles')
<style>
{{-- =============================================================================
   MÓDULO SUCURSALES Y GEOCERCAS — DISEÑO CORPORATIVO, ESPACIOSO Y ELEGANTE
   ============================================================================= --}}

.branches-page-wrapper {
    width: 100%;
}

{{-- Cabecera Ejecutiva --}}
.branches-header-card {
    background: var(--bg-surface, #ffffff);
    border: 1px solid var(--border-color, rgba(0, 0, 0, 0.08));
    border-radius: 14px;
}

.branches-header-icon {
    width: 48px;
    height: 48px;
    background: linear-gradient(135deg, #0284c7 0%, #2563eb 100%);
    color: #ffffff;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.35rem;
    flex-shrink: 0;
}

{{-- Tarjetas de Métricas KPI --}}
.branches-kpi-card {
    background: var(--bg-surface, #ffffff);
    border: 1px solid var(--border-color, rgba(0, 0, 0, 0.07));
    border-radius: 12px;
    transition: transform 0.15s ease, box-shadow 0.15s ease;
}

.branches-kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 14px rgba(0, 0, 0, 0.06) !important;
}

.branches-kpi-pill {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
}

{{-- Barra de Filtros --}}
.branches-filter-card {
    background: var(--bg-surface, #ffffff);
    border: 1px solid var(--border-color, rgba(0, 0, 0, 0.08));
    border-radius: 10px;
}

{{-- Botón Limpiar Filtros Mejorado --}}
.btn-branch-reset {
    height: 38px;
    padding: 0 14px;
    border-radius: 6px !important;
    border: 1px solid var(--border-color, #cbd5e1);
    background: var(--bg-surface, #ffffff);
    color: var(--text-secondary, #475569);
    font-size: 0.825rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.15s ease;
    cursor: pointer;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.03);
    text-decoration: none;
}

.btn-branch-reset:hover {
    background: var(--bg-surface-elevated, #f1f5f9);
    border-color: #94a3b8;
    color: var(--text-primary, #0f172a);
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.06);
}

.btn-branch-reset i {
    font-size: 0.85rem;
    color: var(--accent-cyan, #0284c7);
    transition: transform 0.25s ease;
}

.btn-branch-reset:hover i {
    transform: rotate(-180deg);
}

{{-- Tarjeta / Chip Contador de Resultados --}}
.branches-counter-card {
    height: 38px;
    padding: 0 14px;
    border-radius: 6px !important;
    border: 1px solid var(--border-color, #e2e8f0);
    background: var(--bg-surface-elevated, #f8fafc);
    color: var(--text-secondary, #475569);
    font-size: 0.825rem;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    white-space: nowrap;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.03);
}

.branches-counter-card .counter-icon {
    font-size: 0.9rem;
    color: var(--accent-cyan, #0284c7);
    flex-shrink: 0;
}

.branches-counter-card .counter-text {
    font-size: 0.825rem;
    font-weight: 600;
    color: var(--text-primary, #0f172a);
}

{{-- =============================================================================
   TARJETA INDIVIDUAL DE SUCURSAL (ESPACIOSA, SIN APILAMIENTO NI AMONTONAMIENTO)
   ============================================================================= --}}
.branch-card {
    background: var(--bg-surface, #ffffff);
    border: 1px solid var(--border-color, rgba(0, 0, 0, 0.08));
    border-radius: 14px;
    transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
    overflow: hidden;
}

.branch-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 10px 24px rgba(0, 0, 0, 0.08) !important;
    border-color: rgba(2, 132, 199, 0.4) !important;
}

.branch-card-header {
    background: var(--bg-surface, #ffffff);
}

{{-- Avatar del Tipo de Sede --}}
.branch-type-avatar {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    flex-shrink: 0;
}

.branch-type-agency    { background: rgba(2, 132, 199, 0.12); color: #0284c7; }
.branch-type-terminal  { background: rgba(14, 165, 233, 0.12); color: #0284c7; }
.branch-type-warehouse { background: rgba(245, 158, 11, 0.14); color: #d97706; }
.branch-type-workshop  { background: rgba(99, 102, 241, 0.14); color: #6366f1; }
.branch-type-corporate { background: rgba(147, 51, 234, 0.14); color: #9333ea; }

{{-- Status Badge con Píldora y Dot Pulsante --}}
.branch-status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
}

.branch-status-active {
    background: rgba(5, 150, 105, 0.12);
    color: #059669;
    border: 1px solid rgba(5, 150, 105, 0.28);
}

.branch-status-inactive {
    background: rgba(100, 116, 139, 0.12);
    color: #64748b;
    border: 1px solid rgba(100, 116, 139, 0.28);
}

.branch-status-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    display: inline-block;
}

.branch-status-dot.active {
    background-color: #059669;
    box-shadow: 0 0 0 2px rgba(5, 150, 105, 0.25);
}

.branch-status-dot.inactive {
    background-color: #64748b;
}

{{-- Bloque de Dirección Física --}}
.branch-address-box {
    background: var(--bg-surface-elevated, #f8fafc);
    border: 1px solid var(--border-color, #e2e8f0);
    border-radius: 10px;
    padding: 12px 14px;
}

.branch-address-icon {
    width: 28px;
    height: 28px;
    border-radius: 8px;
    background: rgba(239, 68, 68, 0.1);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.95rem;
}

{{-- Mini Cards de Estadísticas (Radio & Colaboradores) --}}
.branch-stat-mini-card {
    background: var(--bg-surface-elevated, #f8fafc);
    border: 1px solid var(--border-color, #e2e8f0);
    border-radius: 10px;
    padding: 10px 14px;
    height: 100%;
}

.branch-stat-label {
    font-size: 0.72rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--text-secondary, #64748b);
}

.branch-stat-val {
    font-size: 1.1rem;
    font-weight: 700;
    line-height: 1.2;
}

.branch-stat-val .unit {
    font-size: 0.75rem;
    font-weight: 500;
    opacity: 0.85;
}

{{-- Lista Estructurada de Información (Supervisor & Zona) --}}
.branch-info-list {
    display: flex;
    flex-direction: column;
}

.branch-info-row {
    font-size: 0.825rem;
}

.branch-icon-pill {
    width: 28px;
    height: 28px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.9rem;
    flex-shrink: 0;
}

.branch-info-label {
    font-size: 0.775rem;
    font-weight: 500;
    color: var(--text-secondary, #64748b);
}

.branch-info-val {
    font-size: 0.825rem;
}

{{-- Pie de la Tarjeta --}}
.branch-card-footer {
    background: var(--bg-surface-elevated, #f8fafc);
}

{{-- Tarjeta de Sección dentro de Modales --}}
.branch-modal-section-card {
    background: var(--bg-surface-elevated, #f8fafc);
    border: 1px solid var(--border-color, #e2e8f0);
    border-radius: 10px;
    padding: 1.25rem 1.5rem;
    transition: border-color 0.15s ease;
}

.branch-map-container {
    overflow: hidden;
    border: 1px solid var(--border-color, #e2e8f0);
    border-radius: 8px;
}

.branch-map-overlay-hint {
    background: rgba(var(--bs-body-bg-rgb, 255, 255, 255), 0.85);
    backdrop-filter: blur(4px);
    padding: 5px 12px;
    border-top: 1px solid var(--border-color, #e2e8f0);
    font-size: 0.735rem;
}

{{-- Colores auxiliares --}}
.text-indigo { color: #6366f1 !important; }
.bg-indigo-subtle { background-color: rgba(99, 102, 241, 0.12) !important; }
.text-emerald { color: #059669 !important; }
.bg-emerald-subtle { background-color: rgba(5, 150, 105, 0.12) !important; }
.text-amber { color: #d97706 !important; }
.bg-amber-subtle { background-color: rgba(245, 158, 11, 0.14) !important; }
.text-purple { color: #9333ea !important; }
.bg-purple-subtle { background-color: rgba(147, 51, 234, 0.12) !important; }

{{-- =============================================================================
   SOPORTE TOTAL PARA MODO OSCURO
   ============================================================================= --}}
[data-bs-theme="dark"] .branches-header-card,
[data-bs-theme="dark"] .branches-kpi-card,
[data-bs-theme="dark"] .branches-filter-card,
[data-bs-theme="dark"] .branch-card {
    background: var(--bg-surface, #111622) !important;
    border-color: var(--border-color, #1a2234) !important;
}

[data-bs-theme="dark"] .branch-card-header {
    background: var(--bg-surface, #111622) !important;
    border-bottom-color: #1a2234 !important;
}

[data-bs-theme="dark"] .branch-address-box,
[data-bs-theme="dark"] .branch-stat-mini-card {
    background: #161f30 !important;
    border-color: #243048 !important;
}

[data-bs-theme="dark"] .branch-info-row.border-bottom {
    border-bottom-color: #1a2234 !important;
}

[data-bs-theme="dark"] .branch-card-footer {
    background: #0d121c !important;
    border-top-color: #1a2234 !important;
}

[data-bs-theme="dark"] .branch-modal-section-card {
    background: #161f30 !important;
    border-color: #243048 !important;
}

[data-bs-theme="dark"] .branch-map-container {
    border-color: #243048 !important;
}

[data-bs-theme="dark"] .btn-branch-reset,
[data-bs-theme="dark"] .branches-counter-card {
    background: #161f30 !important;
    border-color: #243048 !important;
    color: #94a3b8 !important;
}

[data-bs-theme="dark"] .btn-branch-reset:hover {
    background: #1c273e !important;
    color: #f8fafc !important;
    border-color: #38bdf8 !important;
}

[data-bs-theme="dark"] .branches-counter-card .counter-text {
    color: #f8fafc !important;
}

[data-bs-theme="dark"] .branch-map-overlay-hint {
    background: rgba(17, 22, 34, 0.9) !important;
    border-color: #243048 !important;
    color: #94a3b8 !important;
}

[data-bs-theme="dark"] .modal-content {
    background-color: #111622 !important;
    border-color: #1a2234 !important;
    color: #f8fafc !important;
}

[data-bs-theme="dark"] .modal-header,
[data-bs-theme="dark"] .modal-footer {
    background-color: #161f30 !important;
    border-color: #1a2234 !important;
}

[data-bs-theme="dark"] .modal-body .form-control,
[data-bs-theme="dark"] .modal-body .form-select {
    background-color: #0d121c !important;
    border-color: #243048 !important;
    color: #f8fafc !important;
}
</style>
@endsection
