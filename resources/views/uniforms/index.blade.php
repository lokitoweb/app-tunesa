@extends('layouts.app')

@section('title', 'Dotación y Tallas')

@section('content')
@php
    use App\Modules\HR\Models\UniformDelivery;
    $completeCount = max(0, $summary['total'] - $summary['missing']);
@endphp

<div class="uniforms-page-wrapper">
    {{-- =========================================================================
         1. CABECERA EJECUTIVA DEL MÓDULO
         ========================================================================= --}}
    <div class="uniforms-header-card p-4 mb-4 shadow-sm">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3">
            <div class="d-flex align-items-center gap-3">
                <div class="uniforms-header-icon shadow-sm">
                    <i class="bi bi-person-badge-fill"></i>
                </div>
                <div>
                    <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                        <h1 class="h4 fw-bold mb-0 text-body">Dotación y Tallas de Personal</h1>
                        <span class="badge rounded-pill bg-primary-subtle text-primary border border-primary-subtle px-2 py-1 small">
                            <i class="bi bi-rulers" style="margin-right: 6px !important;"></i>Logística & EPP
                        </span>
                    </div>
                    <p class="text-body-secondary small mb-0">
                        Consolidado de tallas registradas por los colaboradores en la app móvil y control de entregas de uniformes.
                    </p>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2 w-100 w-md-auto justify-content-md-end">
                <button
                    type="button"
                    class="btn btn-primary px-3 py-2 fw-semibold d-inline-flex align-items-center shadow-sm"
                    data-bs-toggle="modal"
                    data-bs-target="#deliveryModal"
                    style="height: 38px; border-radius: 8px; font-weight: 600; font-size: 0.85rem;"
                >
                    <i class="bi bi-box-seam-fill" style="margin-right: 8px !important;"></i>
                    <span>Registrar entrega</span>
                </button>
                <a
                    id="exportCsvBtn"
                    href="{{ route('uniforms.export', array_filter(['branch_id' => $branchId])) }}"
                    class="btn btn-outline-secondary px-3 py-2 fw-semibold d-inline-flex align-items-center"
                    style="height: 38px; border-radius: 8px; font-weight: 600; font-size: 0.85rem;"
                >
                    <i class="bi bi-download text-primary" style="margin-right: 8px !important;"></i>
                    <span>Exportar tallas (CSV)</span>
                </a>
            </div>
        </div>
    </div>

    {{-- =========================================================================
         2. TARJETAS DE INDICADORES (KPIS EJECUTIVOS)
         ========================================================================= --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="uniforms-kpi-card p-3 shadow-sm h-100">
                <div class="d-flex align-items-center gap-3">
                    <div class="uniforms-kpi-pill bg-primary-subtle text-primary">
                        <i class="bi bi-people-fill"></i>
                    </div>
                    <div>
                        <div class="text-body-secondary small fw-medium text-uppercase" style="letter-spacing: 0.05em; font-size: 0.72rem;">
                            Total Personal
                        </div>
                        <div class="fs-4 fw-bold text-body lh-1 mt-1">{{ $summary['total'] }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-lg-3">
            <div class="uniforms-kpi-card p-3 shadow-sm h-100">
                <div class="d-flex align-items-center gap-3">
                    <div class="uniforms-kpi-pill bg-success-subtle text-success">
                        <i class="bi bi-check-circle-fill"></i>
                    </div>
                    <div>
                        <div class="text-body-secondary small fw-medium text-uppercase" style="letter-spacing: 0.05em; font-size: 0.72rem;">
                            Tallas Completas
                        </div>
                        <div class="fs-4 fw-bold text-body lh-1 mt-1">{{ $completeCount }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-lg-3">
            <div class="uniforms-kpi-card p-3 shadow-sm h-100">
                <div class="d-flex align-items-center gap-3">
                    <div class="uniforms-kpi-pill bg-warning-subtle text-warning-emphasis">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                    </div>
                    <div>
                        <div class="text-body-secondary small fw-medium text-uppercase" style="letter-spacing: 0.05em; font-size: 0.72rem;">
                            Tallas Incompletas
                        </div>
                        <div class="fs-4 fw-bold lh-1 mt-1 {{ $summary['missing'] ? 'text-warning-emphasis' : 'text-body' }}">
                            {{ $summary['missing'] }}
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-lg-3">
            <div class="uniforms-kpi-card p-3 shadow-sm h-100">
                <div class="d-flex align-items-center gap-3">
                    <div class="uniforms-kpi-pill bg-info-subtle text-info">
                        <i class="bi bi-box-seam-fill"></i>
                    </div>
                    <div>
                        <div class="text-body-secondary small fw-medium text-uppercase" style="letter-spacing: 0.05em; font-size: 0.72rem;">
                            Entregas Registradas
                        </div>
                        <div class="fs-4 fw-bold text-body lh-1 mt-1">{{ count($recentDeliveries ?? $recent) }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- =========================================================================
         3. RESUMEN DE CANTIDADES POR PRENDA (ÓRDENES DE COMPRA LOGÍSTICA)
         ========================================================================= --}}
    <div class="row g-3 mb-4">
        @foreach([
            'shirt' => ['label' => 'Polo / Camisa', 'icon' => 'bi-person-badge', 'color' => '#0284c7'],
            'pants' => ['label' => 'Pantalón', 'icon' => 'bi-tag', 'color' => '#10b981'],
            'shoes' => ['label' => 'Calzado', 'icon' => 'bi-shield-check', 'color' => '#8b5cf6']
        ] as $key => $meta)
            <div class="col-12 col-md-4">
                <div class="uniforms-section-card p-3 shadow-sm h-100">
                    <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
                        <h2 class="h6 fw-bold mb-0 text-body d-flex align-items-center">
                            <span class="d-inline-flex align-items-center justify-content-center rounded-2 me-2" style="width: 28px; height: 28px; background: {{ $meta['color'] }}22; color: {{ $meta['color'] }};">
                                <i class="bi {{ $meta['icon'] }}"></i>
                            </span>
                            {{ $meta['label'] }}
                        </h2>
                        <span class="badge bg-secondary-subtle text-secondary small">
                            {{ count($summary[$key]) }} tallas requeridas
                        </span>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        @forelse($summary[$key] as $size => $qty)
                            <div class="p-2 px-3 rounded-3 text-center border bg-surface-elevated" style="min-width: 65px;">
                                <div class="fw-bold fs-6" style="color: {{ $meta['color'] }};">{{ $qty }}</div>
                                <div class="small text-body-secondary" style="font-size: 0.72rem;">Talla {{ $size }}</div>
                            </div>
                        @empty
                            <span class="small text-body-secondary py-2">Sin tallas registradas</span>
                        @endforelse
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- =========================================================================
         4. BANDEJA PRINCIPAL (TABLA AMPLIA, PROFESIONAL, CON FILTROS EN UNA SOLA LÍNEA)
         ========================================================================= --}}
    <div class="uniforms-table-card shadow-sm mb-4">
        {{-- Barra de Filtros Internos (100% Horizontal) --}}
        <div class="p-3 border-bottom">
            <div class="d-flex flex-wrap flex-xl-nowrap align-items-center justify-content-between gap-3">
                {{-- Segmented Control de Estados y Vistas --}}
                <div class="training-segmented-bar flex-shrink-0" role="tablist" id="uniformTabs">
                    <button
                        type="button"
                        class="training-segmented-item is-active"
                        data-tab="all"
                    >
                        <i class="bi bi-people-fill" style="margin-right: 6px !important;"></i>
                        <span>Todos</span>
                        <span class="training-segmented-pill">{{ count($rows) }}</span>
                    </button>
                    <button
                        type="button"
                        class="training-segmented-item"
                        data-tab="complete"
                    >
                        <i class="bi bi-check-circle-fill text-success" style="margin-right: 6px !important;"></i>
                        <span>Tallas completas</span>
                        <span class="training-segmented-pill">{{ $completeCount }}</span>
                    </button>
                    <button
                        type="button"
                        class="training-segmented-item"
                        data-tab="incomplete"
                    >
                        <i class="bi bi-exclamation-triangle-fill text-warning" style="margin-right: 6px !important;"></i>
                        <span>Tallas pendientes</span>
                        <span class="training-segmented-pill">{{ $summary['missing'] }}</span>
                    </button>
                    <button
                        type="button"
                        class="training-segmented-item"
                        data-tab="deliveries"
                    >
                        <i class="bi bi-clock-history text-info" style="margin-right: 6px !important;"></i>
                        <span>Historial entregas</span>
                        <span class="training-segmented-pill">{{ count($recentDeliveries ?? $recent) }}</span>
                    </button>
                </div>

                {{-- Filtros Complementarios: Sucursal, Buscador y Limpiar EN UNA SOLA LÍNEA HORIZONTAL --}}
                <div class="d-flex align-items-center flex-nowrap" style="gap: 12px; min-width: 0;">
                    {{-- Selector de Sucursal --}}
                    <div class="modern-input-wrapper flex-shrink-0" style="width: 210px; min-width: 175px;">
                        <span class="modern-input-icon"><i class="bi bi-building"></i></span>
                        <select id="uniformBranchSelect" class="modern-input-control" aria-label="Filtrar por sucursal">
                            <option value="">Todas las sucursales</option>
                            @foreach($branches as $b)
                                <option value="{{ $b->name }}" @selected($branchId === $b->id)>{{ $b->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Buscador Predictivo --}}
                    <div class="modern-input-wrapper flex-shrink-0" style="width: 280px; min-width: 210px;">
                        <span class="modern-input-icon"><i class="bi bi-search"></i></span>
                        <input
                            id="uniformSearchInput"
                            type="search"
                            class="modern-input-control"
                            placeholder="Buscar persona, DNI o cargo..."
                            aria-label="Buscar colaborador"
                        >
                        <button
                            id="uniformSearchClearBtn"
                            type="button"
                            class="btn btn-sm text-secondary p-0 me-2 border-0 d-none"
                            style="width: 22px; height: 22px;"
                            title="Limpiar búsqueda"
                        >
                            <i class="bi bi-x-circle-fill"></i>
                        </button>
                    </div>

                    {{-- Botón Limpiar Filtros --}}
                    <button
                        id="uniformResetBtn"
                        type="button"
                        class="btn btn-outline-secondary px-3 py-1 fw-medium d-inline-flex align-items-center flex-shrink-0 text-nowrap"
                        style="border-radius: 8px; height: 38px;"
                        title="Restablecer filtros"
                    >
                        <i class="bi bi-arrow-counterclockwise" style="margin-right: 6px !important;"></i>
                        <span>Limpiar</span>
                    </button>
                </div>
            </div>
        </div>

        {{-- Vista 1: Tabla de Personal y Tallas --}}
        <div id="personnelTableWrapper" class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="uniformsTable">
                <thead class="bg-surface-elevated text-body-secondary small text-uppercase">
                    <tr>
                        <th class="ps-3 py-3" style="min-width: 260px;">Colaborador</th>
                        <th class="py-3 text-center" style="width: 130px;">Polo / Camisa</th>
                        <th class="py-3 text-center" style="width: 130px;">Pantalón</th>
                        <th class="py-3 text-center" style="width: 160px;">Calzado</th>
                        <th class="py-3 text-center" style="width: 130px;">Estado</th>
                        <th class="py-3 text-center" style="width: 150px;">Última Entrega</th>
                        <th class="pe-3 py-3 text-end" style="width: 110px;">Acciones</th>
                    </tr>
                </thead>
                <tbody id="uniformsTableBody">
                    {{-- Inyectado y paginado dinámicamente por JS --}}
                </tbody>
            </table>
        </div>

        {{-- Vista 2: Tabla de Historial de Entregas --}}
        <div id="deliveriesTableWrapper" class="table-responsive d-none">
            <table class="table table-hover align-middle mb-0" id="deliveriesTable">
                <thead class="bg-surface-elevated text-body-secondary small text-uppercase">
                    <tr>
                        <th class="ps-3 py-3" style="min-width: 240px;">Colaborador</th>
                        <th class="py-3" style="width: 200px;">Artículo Entregado</th>
                        <th class="py-3 text-center" style="width: 110px;">Talla</th>
                        <th class="py-3 text-center" style="width: 110px;">Cantidad</th>
                        <th class="py-3 text-center" style="width: 150px;">Fecha Entrega</th>
                        <th class="pe-3 py-3" style="min-width: 220px;">Observaciones</th>
                    </tr>
                </thead>
                <tbody id="deliveriesTableBody">
                    {{-- Inyectado dinámicamente por JS --}}
                </tbody>
            </table>
        </div>

        {{-- Paginación Interna (100% Reactiva, Sin alterar URL) --}}
        <div id="uniformsPaginationContainer" class="d-flex flex-column flex-sm-row justify-content-between align-items-center gap-3 p-3 border-top">
            <div class="text-body-secondary small" id="uniformsPaginationInfo"></div>
            <div class="d-flex align-items-center gap-1" id="uniformsPaginationButtons"></div>
        </div>
    </div>
</div>

{{-- =========================================================================
     MODAL DIALOG: REGISTRAR ENTREGA DE UNIFORME (Elegante y Escalable)
     ========================================================================= --}}
<div class="modal fade" id="deliveryModal" tabindex="-1" aria-labelledby="deliveryModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="{{ route('uniforms.deliveries.store') }}" class="modal-content shadow-lg border-0" id="deliveryForm" style="border-radius: 14px; overflow: hidden;">
            @csrf
            {{-- Modal Header --}}
            <div class="modal-header border-bottom p-3 p-md-4" style="background: var(--bg-surface);">
                <div class="d-flex align-items-center" style="gap: 14px !important;">
                    <div style="width: 44px; height: 44px; border-radius: 12px; background: linear-gradient(135deg, var(--brand), #0369a1); display: flex; align-items: center; justify-content: center; color: #fff; font-size: 1.35rem; box-shadow: 0 4px 12px rgba(var(--brand-rgb), 0.25);">
                        <i class="bi bi-box-seam-fill"></i>
                    </div>
                    <div>
                        <h2 class="h5 fw-bold mb-0 text-body" id="deliveryModalTitle">Registrar Entrega de Uniforme</h2>
                        <span class="small text-secondary">Asigna prendas, dotación y EPP entregados al personal</span>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>

            {{-- Modal Body --}}
            <div class="modal-body p-3 p-md-4" style="background: var(--bg-surface);">
                <div class="mb-3">
                    <label for="delivery_user_id" class="form-label small fw-semibold text-body">
                        Colaborador beneficiario <span class="text-danger">*</span>
                    </label>
                    <div class="modern-input-wrapper">
                        <i class="bi bi-person-fill modern-input-icon"></i>
                        <select id="delivery_user_id" name="user_id" class="modern-input-control" required>
                            <option value="">Selecciona un colaborador...</option>
                            @foreach($rows as $r)
                                <option
                                    value="{{ $r['id'] }}"
                                    data-shirt="{{ $r['shirt_size'] ?? '' }}"
                                    data-pants="{{ $r['pants_size'] ?? '' }}"
                                    data-shoes="{{ $r['shoe_size'] ?? '' }}"
                                >
                                    {{ $r['full_name'] }} (Polo: {{ $r['shirt_size'] ?? '—' }} | Pantalón: {{ $r['pants_size'] ?? '—' }} | Calzado: {{ $r['shoe_size'] ?? '—' }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-12 col-md-6">
                        <label for="delivery_item" class="form-label small fw-semibold text-body">
                            Prenda / Artículo <span class="text-danger">*</span>
                        </label>
                        <div class="modern-input-wrapper">
                            <i class="bi bi-tag-fill modern-input-icon"></i>
                            <select id="delivery_item" name="item" class="modern-input-control" required>
                                @foreach(UniformDelivery::ITEMS as $k => $v)
                                    <option value="{{ $k }}">{{ $v }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="col-6 col-md-3">
                        <label for="delivery_size" class="form-label small fw-semibold text-body">Talla</label>
                        <div class="modern-input-wrapper">
                            <i class="bi bi-rulers modern-input-icon"></i>
                            <input id="delivery_size" name="size" type="text" class="modern-input-control" maxlength="10" placeholder="Ej.: M">
                        </div>
                    </div>

                    <div class="col-6 col-md-3">
                        <label for="delivery_quantity" class="form-label small fw-semibold text-body">
                            Cantidad <span class="text-danger">*</span>
                        </label>
                        <div class="modern-input-wrapper">
                            <i class="bi bi-123 modern-input-icon"></i>
                            <input type="number" id="delivery_quantity" name="quantity" class="modern-input-control" value="1" min="1" max="50" required>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-2">
                    <div class="col-12 col-md-6">
                        <label for="delivery_delivered_at" class="form-label small fw-semibold text-body">
                            Fecha de entrega <span class="text-danger">*</span>
                        </label>
                        <div class="modern-input-wrapper">
                            <i class="bi bi-calendar-event modern-input-icon"></i>
                            <input type="date" id="delivery_delivered_at" name="delivered_at" class="modern-input-control" value="{{ now()->toDateString() }}" max="{{ now()->toDateString() }}" required>
                        </div>
                    </div>

                    <div class="col-12 col-md-6">
                        <label for="delivery_notes" class="form-label small fw-semibold text-body">Observaciones</label>
                        <div class="modern-input-wrapper">
                            <i class="bi bi-card-text modern-input-icon"></i>
                            <input id="delivery_notes" name="notes" type="text" class="modern-input-control" maxlength="255" placeholder="Ej.: Dotación semestral 2026">
                        </div>
                    </div>
                </div>
            </div>

            {{-- Modal Footer --}}
            <div class="modal-footer border-top p-3" style="background: var(--bg-surface);">
                <button type="button" class="btn btn-outline-secondary px-3 py-1.5 fw-medium" data-bs-dismiss="modal">
                    Cancelar
                </button>
                <button type="submit" class="btn btn-primary px-4 py-1.5 fw-semibold shadow-sm">
                    <i class="bi bi-check-lg" style="margin-right: 6px !important;"></i>Registrar Entrega
                </button>
            </div>
        </form>
    </div>
</div>
@endsection

@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    {{-- Datos completos precargados --}}
    const allRows = @json($rows);
    const recentDeliveries = @json($recentDeliveries ?? []);

    let currentTab = 'all'; {{-- 'all' | 'complete' | 'incomplete' | 'deliveries' --}}
    let filteredRows = [...allRows];
    let filteredDeliveries = [...recentDeliveries];
    let currentPage = 1;
    const perPage = 10;

    const branchSelect = document.getElementById('uniformBranchSelect');
    const searchInput = document.getElementById('uniformSearchInput');
    const searchClearBtn = document.getElementById('uniformSearchClearBtn');
    const resetBtn = document.getElementById('uniformResetBtn');
    const tabs = document.querySelectorAll('#uniformTabs .training-segmented-item');

    const personnelWrapper = document.getElementById('personnelTableWrapper');
    const deliveriesWrapper = document.getElementById('deliveriesTableWrapper');
    const tableBody = document.getElementById('uniformsTableBody');
    const deliveriesBody = document.getElementById('deliveriesTableBody');
    const paginationInfo = document.getElementById('uniformsPaginationInfo');
    const paginationButtons = document.getElementById('uniformsPaginationButtons');

    function updateSearchClearVisibility() {
        if (searchInput.value.trim().length > 0) {
            searchClearBtn.classList.remove('d-none');
        } else {
            searchClearBtn.classList.add('d-none');
        }
    }

    function filterData() {
        const branchVal = branchSelect.value.trim().toLowerCase();
        const searchVal = searchInput.value.trim().toLowerCase();

        updateSearchClearVisibility();

        if (currentTab === 'deliveries') {
            filteredDeliveries = recentDeliveries.filter(d => {
                if (searchVal) {
                    const matchName = d.user_name?.toLowerCase().includes(searchVal);
                    const matchCode = d.employee_code?.toLowerCase().includes(searchVal);
                    const matchItem = d.item_label?.toLowerCase().includes(searchVal);
                    const matchNotes = d.notes?.toLowerCase().includes(searchVal);
                    if (!matchName && !matchCode && !matchItem && !matchNotes) return false;
                }
                return true;
            });
        } else {
            filteredRows = allRows.filter(r => {
                {{-- Filtro por Tab --}}
                if (currentTab === 'complete' && !r.complete) return false;
                if (currentTab === 'incomplete' && r.complete) return false;

                {{-- Filtro por Sucursal --}}
                if (branchVal && !r.branch?.toLowerCase().includes(branchVal)) {
                    return false;
                }

                {{-- Filtro por Búsqueda Predictiva --}}
                if (searchVal) {
                    const matchesName = r.full_name?.toLowerCase().includes(searchVal);
                    const matchesCode = r.employee_code?.toLowerCase().includes(searchVal);
                    const matchesPos = r.position?.toLowerCase().includes(searchVal);
                    const matchesBranch = r.branch?.toLowerCase().includes(searchVal);
                    if (!matchesName && !matchesCode && !matchesPos && !matchesBranch) {
                        return false;
                    }
                }
                return true;
            });
        }

        currentPage = 1;
        renderActiveView();
    }

    function renderActiveView() {
        if (currentTab === 'deliveries') {
            personnelWrapper.classList.add('d-none');
            deliveriesWrapper.classList.remove('d-none');
            renderDeliveriesTable();
        } else {
            deliveriesWrapper.classList.add('d-none');
            personnelWrapper.classList.remove('d-none');
            renderPersonnelTable();
        }
    }

    function renderPersonnelTable() {
        const total = filteredRows.length;
        const totalPages = Math.max(1, Math.ceil(total / perPage));

        if (currentPage > totalPages) currentPage = totalPages;

        const start = (currentPage - 1) * perPage;
        const pageItems = filteredRows.slice(start, start + perPage);

        if (pageItems.length === 0) {
            tableBody.innerHTML = `
                <tr>
                    <td colspan="7" class="p-5 text-center">
                        <div class="d-inline-flex align-items-center justify-content-center p-3 rounded-circle bg-primary-subtle text-primary mb-3" style="width: 56px; height: 56px;">
                            <i class="bi bi-person-x fs-3"></i>
                        </div>
                        <h6 class="fw-bold text-body mb-1">Sin coincidencias</h6>
                        <span class="text-body-secondary small">No hay colaboradores que coincidan con los filtros aplicados.</span>
                    </td>
                </tr>
            `;
            paginationInfo.innerHTML = `Mostrando 0 de 0 colaboradores`;
            paginationButtons.innerHTML = '';
            return;
        }

        let html = '';
        {{-- Escapa los textos de cada fila antes de armar el HTML (evita XSS con datos del colaborador) --}}
        const escRow = (o) => Object.fromEntries(Object.entries(o).map(([k, v]) => [k, typeof v === 'string' ? Panel.esc(v) : v]));
        pageItems.map(escRow).forEach(r => {
            const initials = (r.full_name || 'C').split(' ').slice(0, 2).map(n => n[0]).join('').toUpperCase();
            const safeName = (r.full_name || '').replace(/'/g, "\\'");

            html += `
                <tr>
                    <td class="ps-3">
                        <div class="d-flex align-items-center gap-2">
                            <span class="rounded-circle d-inline-flex align-items-center justify-content-center bg-primary text-white fw-bold shadow-sm flex-shrink-0" style="width: 34px; height: 34px; font-size: 0.75rem;">
                                ${initials}
                            </span>
                            <div class="min-w-0">
                                <a href="/employees/${r.id}/file" class="fw-bold text-body text-decoration-none d-block text-truncate" title="${r.full_name}">
                                    ${r.full_name}
                                </a>
                                <div class="small text-body-secondary d-flex align-items-center gap-2 flex-wrap" style="font-size: 0.725rem;">
                                    <span class="font-monospace">${r.employee_code || '—'}</span>
                                    <span>•</span>
                                    <span>${r.position || 'Colaborador'}</span>
                                    ${r.branch ? `<span class="badge bg-secondary-subtle text-secondary border px-1.5 py-0">${r.branch}</span>` : ''}
                                </div>
                            </div>
                        </div>
                    </td>
                    <td class="text-center">
                        ${r.shirt_size ? `<span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1 fw-bold">${r.shirt_size}</span>` : `<span class="text-body-secondary small">—</span>`}
                    </td>
                    <td class="text-center">
                        ${r.pants_size ? `<span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1 fw-bold">${r.pants_size}</span>` : `<span class="text-body-secondary small">—</span>`}
                    </td>
                    <td class="text-center">
                        ${r.shoe_size ? `
                            <div><span class="badge bg-purple-subtle text-purple border border-purple-subtle px-2 py-1 fw-bold" style="background: rgba(139, 92, 246, 0.12); color: #8b5cf6; border-color: rgba(139, 92, 246, 0.25);">${r.shoe_size}</span></div>
                            ${r.shoe_type ? `<div class="text-body-secondary" style="font-size: 0.68rem;">${r.shoe_type}</div>` : ''}
                        ` : `<span class="text-body-secondary small">—</span>`}
                    </td>
                    <td class="text-center">
                        ${r.complete ? `
                            <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1 small">
                                <i class="bi bi-check-circle-fill me-1"></i>Completo
                            </span>
                        ` : `
                            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-2 py-1 small">
                                <i class="bi bi-clock-history me-1"></i>Incompleto
                            </span>
                        `}
                    </td>
                    <td class="text-center text-nowrap small text-body-secondary">
                        ${r.last_delivery ? `
                            <span class="d-inline-flex align-items-center text-body">
                                <i class="bi bi-calendar3 me-1 text-primary"></i>${r.last_delivery}
                            </span>
                        ` : `<span class="badge bg-secondary-subtle text-secondary">Sin entregas</span>`}
                    </td>
                    <td class="pe-3 text-end text-nowrap">
                        <div class="d-inline-flex align-items-center gap-1">
                            <button
                                type="button"
                                class="btn-table-action btn-action-publish"
                                title="Registrar entrega para este colaborador"
                                onclick="openDeliveryModalFor(${r.id})"
                            >
                                <i class="bi bi-box-seam-fill text-primary"></i>
                            </button>
                            <a
                                href="/employees/${r.id}/file"
                                class="btn-table-action btn-action-view"
                                title="Ver legajo del colaborador"
                            >
                                <i class="bi bi-person-lines-fill"></i>
                            </a>
                        </div>
                    </td>
                </tr>
            `;
        });
        tableBody.innerHTML = html;

        {{-- Paginación info y botones --}}
        renderPaginationControls(start, total, totalPages, 'colaboradores');
    }

    function renderDeliveriesTable() {
        const total = filteredDeliveries.length;
        const totalPages = Math.max(1, Math.ceil(total / perPage));

        if (currentPage > totalPages) currentPage = totalPages;

        const start = (currentPage - 1) * perPage;
        const pageItems = filteredDeliveries.slice(start, start + perPage);

        if (pageItems.length === 0) {
            deliveriesBody.innerHTML = `
                <tr>
                    <td colspan="6" class="p-5 text-center">
                        <div class="d-inline-flex align-items-center justify-content-center p-3 rounded-circle bg-info-subtle text-info mb-3" style="width: 56px; height: 56px;">
                            <i class="bi bi-box-seam fs-3"></i>
                        </div>
                        <h6 class="fw-bold text-body mb-1">Sin entregas registradas</h6>
                        <span class="text-body-secondary small">No hay registros de entrega que coincidan con la búsqueda.</span>
                    </td>
                </tr>
            `;
            paginationInfo.innerHTML = `Mostrando 0 de 0 registros`;
            paginationButtons.innerHTML = '';
            return;
        }

        let html = '';
        {{-- Escapa los textos de cada fila antes de armar el HTML (evita XSS con datos del colaborador) --}}
        const escRow = (o) => Object.fromEntries(Object.entries(o).map(([k, v]) => [k, typeof v === 'string' ? Panel.esc(v) : v]));
        pageItems.map(escRow).forEach(d => {
            html += `
                <tr>
                    <td class="ps-3">
                        <div class="fw-bold text-body small">${d.user_name}</div>
                        <div class="text-body-secondary" style="font-size: 0.725rem;">
                            <span class="font-monospace">${d.employee_code}</span>
                        </div>
                    </td>
                    <td>
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1">
                            <i class="bi bi-tag-fill me-1"></i>${d.item_label}
                        </span>
                    </td>
                    <td class="text-center">
                        <span class="fw-bold text-body small">${d.size}</span>
                    </td>
                    <td class="text-center">
                        <span class="badge bg-secondary-subtle text-secondary px-2 py-1 fw-bold">${d.quantity} ud(s)</span>
                    </td>
                    <td class="text-center text-nowrap small text-body">
                        <i class="bi bi-calendar-check me-1 text-success"></i>${d.delivered_at}
                    </td>
                    <td class="pe-3 small text-body-secondary">
                        ${d.notes || '—'}
                    </td>
                </tr>
            `;
        });
        deliveriesBody.innerHTML = html;

        renderPaginationControls(start, total, totalPages, 'registros');
    }

    function renderPaginationControls(start, total, totalPages, unitLabel) {
        const endItem = Math.min(start + perPage, total);
        paginationInfo.innerHTML = `Mostrando <span class="fw-bold text-body">${start + 1}</span> - <span class="fw-bold text-body">${endItem}</span> de <span class="fw-bold text-body">${total}</span> ${unitLabel}`;

        if (totalPages <= 1) {
            paginationButtons.innerHTML = '';
            return;
        }

        let btns = `
            <button type="button" class="btn btn-sm btn-outline-secondary px-2 py-1" style="border-radius: 6px;" ${currentPage === 1 ? 'disabled' : ''} onclick="changeUniformPage(${currentPage - 1})">
                <i class="bi bi-chevron-left" style="margin-right: 4px !important;"></i>Anterior
            </button>
        `;

        for (let p = 1; p <= totalPages; p++) {
            btns += `
                <button type="button" class="btn btn-sm ${p === currentPage ? 'btn-primary fw-bold' : 'btn-outline-secondary'}" style="width: 32px; height: 32px; border-radius: 6px; padding: 0;" onclick="changeUniformPage(${p})">
                    ${p}
                </button>
            `;
        }

        btns += `
            <button type="button" class="btn btn-sm btn-outline-secondary px-2 py-1" style="border-radius: 6px;" ${currentPage === totalPages ? 'disabled' : ''} onclick="changeUniformPage(${currentPage + 1})">
                Siguiente<i class="bi bi-chevron-right" style="margin-left: 4px !important;"></i>
            </button>
        `;

        paginationButtons.innerHTML = btns;
    }

    window.changeUniformPage = function(page) {
        currentPage = page;
        renderActiveView();
    };

    {{-- Pre-seleccionar colaborador al abrir modal desde una fila --}}
    window.openDeliveryModalFor = function(userId) {
        const modalEl = document.getElementById('deliveryModal');
        const userSelect = document.getElementById('delivery_user_id');
        if (userSelect) {
            userSelect.value = String(userId);
            triggerSizeSuggestion();
        }
        if (modalEl) {
            const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
            modal.show();
        }
    };

    {{-- Auto-sugerir talla al cambiar colaborador o prenda en el modal --}}
    const modalUserSelect = document.getElementById('delivery_user_id');
    const modalItemSelect = document.getElementById('delivery_item');
    const modalSizeInput = document.getElementById('delivery_size');

    function triggerSizeSuggestion() {
        const selectedOpt = modalUserSelect.options[modalUserSelect.selectedIndex];
        if (!selectedOpt || !selectedOpt.value) return;

        const itemVal = modalItemSelect.value;
        if (itemVal === 'shirt') {
            modalSizeInput.value = selectedOpt.dataset.shirt || '';
        } else if (itemVal === 'pants') {
            modalSizeInput.value = selectedOpt.dataset.pants || '';
        } else if (itemVal === 'shoes') {
            modalSizeInput.value = selectedOpt.dataset.shoes || '';
        }
    }

    if (modalUserSelect && modalItemSelect) {
        modalUserSelect.addEventListener('change', triggerSizeSuggestion);
        modalItemSelect.addEventListener('change', triggerSizeSuggestion);
    }

    {{-- Tabs de filtrado --}}
    tabs.forEach(tab => {
        tab.addEventListener('click', () => {
            tabs.forEach(t => t.classList.remove('is-active'));
            tab.classList.add('is-active');
            currentTab = tab.dataset.tab;
            filterData();
        });
    });

    {{-- Filtros interactivos --}}
    branchSelect.addEventListener('change', filterData);
    searchInput.addEventListener('input', filterData);
    searchClearBtn.addEventListener('click', () => {
        searchInput.value = '';
        filterData();
    });

    {{-- Reset general --}}
    resetBtn.addEventListener('click', () => {
        tabs.forEach(t => t.classList.remove('is-active'));
        const allTab = document.querySelector('#uniformTabs [data-tab="all"]');
        if (allTab) allTab.classList.add('is-active');
        currentTab = 'all';

        branchSelect.value = '';
        searchInput.value = '';
        updateSearchClearVisibility();
        filterData();
    });

    {{-- Render inicial --}}
    filterData();
});
</script>
@endsection
