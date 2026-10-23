@extends('layouts.app')

@section('title', 'Cruce con Planilla')

@section('content')
@php
    $months = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
    $payTone = ['Verificado' => 'success', 'Por verificar' => 'warning'];
@endphp

<div class="payroll-page-wrapper">
    {{-- =========================================================================
         1. CABECERA EJECUTIVA DEL MÓDULO
         ========================================================================= --}}
    <div class="payroll-header-card p-4 mb-4 shadow-sm">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3">
            <div class="d-flex align-items-center gap-3">
                <div class="payroll-header-icon shadow-sm">
                    <i class="bi bi-cash-stack"></i>
                </div>
                <div>
                    <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                        <h1 class="h4 fw-bold mb-0 text-body">Cruce con Planilla</h1>
                        <span id="payrollPeriodBadge" class="badge rounded-pill bg-success-subtle text-success border border-success-subtle px-2 py-1 small">
                            <i class="bi bi-calendar-check" style="margin-right: 6px !important;"></i>Periodo {{ $months[$month] }} {{ $year }}
                        </span>
                    </div>
                    <p class="text-body-secondary small mb-0">
                        Consolidado mensual de horas efectivas, sobretiempos, tardanzas, faltas y cuentas de abono bancario para nómina.
                    </p>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2 w-100 w-md-auto justify-content-md-end">
                <a id="payrollExportBtn" href="{{ route('payroll.export', array_filter(['year' => $year, 'month' => $month, 'branch_id' => $branchId])) }}" class="btn btn-primary px-3 py-2 fw-semibold d-inline-flex align-items-center shadow-sm">
                    <i class="bi bi-download" style="margin-right: 6px !important;"></i>Exportar para planilla (CSV)
                </a>
            </div>
        </div>
    </div>

    {{-- =========================================================================
         2. TARJETAS DE INDICADORES (KPIS EJECUTIVOS)
         ========================================================================= --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg">
            <div class="payroll-kpi-card p-3 shadow-sm h-100">
                <div class="d-flex align-items-center gap-3">
                    <div class="payroll-kpi-pill bg-primary-subtle text-primary">
                        <i class="bi bi-people-fill"></i>
                    </div>
                    <div>
                        <div class="text-body-secondary small fw-medium text-uppercase" style="letter-spacing: 0.05em; font-size: 0.72rem;">
                            Colaboradores
                        </div>
                        <div id="kpiEmployees" class="fs-4 fw-bold text-body lh-1 mt-1">{{ $totals['employees'] }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-lg">
            <div class="payroll-kpi-card p-3 shadow-sm h-100">
                <div class="d-flex align-items-center gap-3">
                    <div class="payroll-kpi-pill bg-info-subtle text-info">
                        <i class="bi bi-clock-history"></i>
                    </div>
                    <div>
                        <div class="text-body-secondary small fw-medium text-uppercase" style="letter-spacing: 0.05em; font-size: 0.72rem;">
                            Horas Trabajadas
                        </div>
                        <div id="kpiWorkedHours" class="fs-4 fw-bold text-body lh-1 mt-1">{{ number_format($totals['worked_hours'], 1) }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-lg">
            <div class="payroll-kpi-card p-3 shadow-sm h-100">
                <div class="d-flex align-items-center gap-3">
                    <div class="payroll-kpi-pill bg-warning-subtle text-warning-emphasis">
                        <i class="bi bi-alarm-fill"></i>
                    </div>
                    <div>
                        <div class="text-body-secondary small fw-medium text-uppercase" style="letter-spacing: 0.05em; font-size: 0.72rem;">
                            Min. Tardanza
                        </div>
                        <div id="kpiLateMinutes" class="fs-4 fw-bold text-body lh-1 mt-1">{{ $totals['late_minutes'] }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-lg">
            <div class="payroll-kpi-card p-3 shadow-sm h-100">
                <div class="d-flex align-items-center gap-3">
                    <div class="payroll-kpi-pill bg-danger-subtle text-danger">
                        <i class="bi bi-person-x-fill"></i>
                    </div>
                    <div>
                        <div class="text-body-secondary small fw-medium text-uppercase" style="letter-spacing: 0.05em; font-size: 0.72rem;">
                            Faltas Injustificadas
                        </div>
                        <div id="kpiAbsences" class="fs-4 fw-bold text-body lh-1 mt-1">{{ $totals['absences'] }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg">
            <div class="payroll-kpi-card p-3 shadow-sm h-100">
                <div class="d-flex align-items-center gap-3">
                    <div id="kpiBankPill" class="payroll-kpi-pill {{ $totals['missing_bank'] || $totals['not_verified'] ? 'bg-warning-subtle text-warning-emphasis' : 'bg-success-subtle text-success' }}">
                        <i class="bi bi-bank2"></i>
                    </div>
                    <div>
                        <div class="text-body-secondary small fw-medium text-uppercase" style="letter-spacing: 0.05em; font-size: 0.72rem;">
                            Sin cuenta / Sin verificar
                        </div>
                        <div id="kpiBankText" class="fs-4 fw-bold lh-1 mt-1 {{ $totals['missing_bank'] || $totals['not_verified'] ? 'text-warning-emphasis' : 'text-success' }}">
                            {{ $totals['missing_bank'] }} / {{ $totals['not_verified'] }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- =========================================================================
         3. SELECTOR DE PERIODO Y FILTRO 100% AJAX (EN UNA SOLA LÍNEA HORIZONTAL)
         ========================================================================= --}}
    <div class="payroll-filter-card p-3 mb-4 shadow-sm">
        <div class="d-flex flex-wrap flex-xl-nowrap align-items-center gap-2 gap-xl-3">
            {{-- Selector de Mes --}}
            <div class="modern-input-wrapper flex-shrink-0" style="width: 175px;">
                <span class="modern-input-icon"><i class="bi bi-calendar-month text-primary" style="margin-right: 6px !important;"></i></span>
                <select id="payrollMonthSelect" class="modern-input-control" aria-label="Seleccionar mes">
                    @for($m = 1; $m <= 12; $m++)
                        <option value="{{ $m }}" @selected($m === $month)>{{ $months[$m] }}</option>
                    @endfor
                </select>
            </div>

            {{-- Selector de Año --}}
            <div class="modern-input-wrapper flex-shrink-0" style="width: 120px;">
                <span class="modern-input-icon"><i class="bi bi-calendar3 text-primary" style="margin-right: 6px !important;"></i></span>
                <input type="number" id="payrollYearInput" class="modern-input-control" value="{{ $year }}" min="2020" max="2100" aria-label="Seleccionar año">
            </div>

            {{-- Selector de Sucursal --}}
            <div class="modern-input-wrapper flex-shrink-0" style="width: 220px; min-width: 180px;">
                <span class="modern-input-icon"><i class="bi bi-building text-primary" style="margin-right: 6px !important;"></i></span>
                <select id="payrollBranchSelect" class="modern-input-control" aria-label="Filtrar por sucursal">
                    <option value="">Todas las sucursales</option>
                    @foreach($branches as $b)
                        <option value="{{ $b->id }}" @selected($branchId === $b->id)>{{ $b->name }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Buscador en Tiempo Real --}}
            <div class="modern-input-wrapper flex-grow-1" style="min-width: 220px;">
                <span class="modern-input-icon"><i class="bi bi-search text-body-secondary" style="margin-right: 6px !important;"></i></span>
                <input
                    id="payrollSearchInput"
                    type="search"
                    class="modern-input-control"
                    placeholder="Buscar por colaborador, DNI o cuenta..."
                    aria-label="Buscar en la planilla"
                >
                <button
                    id="payrollSearchClearBtn"
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
                id="payrollResetBtn"
                type="button"
                class="btn btn-outline-secondary px-3 py-1 fw-medium d-inline-flex align-items-center flex-shrink-0 text-nowrap"
                style="border-radius: 8px; height: 38px;"
                title="Restablecer filtros al periodo actual"
            >
                <i class="bi bi-arrow-counterclockwise" style="margin-right: 6px !important;"></i>
                <span>Limpiar</span>
            </button>
        </div>
    </div>

    {{-- =========================================================================
         4. TABLA CON FILTRADO Y PAGINACIÓN 100% INTERNA (SIN RECARGA DE PÁGINA)
         ========================================================================= --}}
    <div class="payroll-table-card shadow-sm mb-4">
        <div class="table-responsive position-relative">
            {{-- Spinner Overlay sutil para carga AJAX --}}
            <div id="payrollLoadingOverlay" class="position-absolute top-0 start-0 w-100 h-100 d-none justify-content-center align-items-center" style="background: rgba(255, 255, 255, 0.65); z-index: 5; backdrop-filter: blur(1px);">
                <div class="spinner-border text-primary" role="status" style="width: 2.2rem; height: 2.2rem;">
                    <span class="visually-hidden">Cargando...</span>
                </div>
            </div>

            <table class="table table-hover align-middle mb-0 small" style="min-width: 1100px;">
                <thead class="bg-surface-elevated text-body-secondary small text-uppercase">
                    <tr>
                        <th class="ps-3 py-3">Colaborador</th>
                        <th class="py-3">Régimen</th>
                        <th class="py-3 text-end">Días</th>
                        <th class="py-3 text-end">Horas</th>
                        <th class="py-3 text-end" title="Horas extra aprobadas por jefatura">Extra</th>
                        <th class="py-3 text-end" title="Horas trabajadas base más extra aprobadas">A pagar</th>
                        <th class="py-3">Tardanzas</th>
                        <th class="py-3">Faltas</th>
                        <th class="py-3">Licencias</th>
                        <th class="py-3">Banco / Cuenta</th>
                        <th class="py-3">Previsión</th>
                        <th class="pe-3 py-3 text-center">Estado Pago</th>
                    </tr>
                </thead>
                <tbody id="payrollTableBody">
                    {{-- Inyectado dinámicamente con paginación interna --}}
                </tbody>
            </table>
        </div>

        {{-- Paginación Interna (Sin cambiar URL) --}}
        <div id="payrollPaginationContainer" class="d-flex flex-column flex-sm-row justify-content-between align-items-center gap-3 p-3 border-top">
            <div class="text-body-secondary small" id="payrollPaginationInfo"></div>
            <div class="d-flex align-items-center gap-1" id="payrollPaginationButtons"></div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    {{-- 1. Limpieza inmediata de URL en el navegador (para mantener /payroll limpio sin query params) --}}
    if (window.location.search) {
        window.history.replaceState({}, '', window.location.pathname);
    }

    const monthNames = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

    {{-- Valores iniciales --}}
    const initialMonth = {{ $month }};
    const initialYear = {{ $year }};
    const initialBranch = '{{ $branchId ?? '' }}';

    let allRows = @json($rows);
    let filteredRows = [...allRows];
    let currentPage = 1;
    const perPage = 15;
    let isFetching = false;

    {{-- Elementos del DOM --}}
    const monthSelect = document.getElementById('payrollMonthSelect');
    const yearInput = document.getElementById('payrollYearInput');
    const branchSelect = document.getElementById('payrollBranchSelect');
    const searchInput = document.getElementById('payrollSearchInput');
    const searchClearBtn = document.getElementById('payrollSearchClearBtn');
    const resetBtn = document.getElementById('payrollResetBtn');
    const tableBody = document.getElementById('payrollTableBody');
    const loadingOverlay = document.getElementById('payrollLoadingOverlay');
    const paginationInfo = document.getElementById('payrollPaginationInfo');
    const paginationButtons = document.getElementById('payrollPaginationButtons');

    {{-- 2. Consulta AJAX al cambiar Mes, Año o Sucursal --}}
    function fetchPayrollData() {
        if (isFetching) return;
        isFetching = true;

        const month = monthSelect.value;
        const year = yearInput.value;
        const branch = branchSelect.value;

        const params = new URLSearchParams({ month, year });
        if (branch) params.append('branch_id', branch);

        {{-- Silueta en KPIs y tabla mientras llega el periodo (reemplaza el spinner) --}}
        Panel.skeleton.on('#main-content');

        fetch(`/payroll?${params.toString()}`, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        })
        .then(res => {
            if (!res.ok) throw new Error('Error al consultar planilla');
            return res.json();
        })
        .then(data => {
            allRows = data.rows || [];

            {{-- Actualizar tarjetas de KPI --}}
            if (data.totals) {
                const empEl = document.getElementById('kpiEmployees');
                const hoursEl = document.getElementById('kpiWorkedHours');
                const lateEl = document.getElementById('kpiLateMinutes');
                const absEl = document.getElementById('kpiAbsences');
                const bankText = document.getElementById('kpiBankText');
                const bankPill = document.getElementById('kpiBankPill');

                if (empEl) empEl.textContent = data.totals.employees;
                if (hoursEl) hoursEl.textContent = Number(data.totals.worked_hours).toFixed(1);
                if (lateEl) lateEl.textContent = data.totals.late_minutes;
                if (absEl) absEl.textContent = data.totals.absences;

                if (bankText && bankPill) {
                    const hasIssues = (data.totals.missing_bank > 0 || data.totals.not_verified > 0);
                    bankText.textContent = `${data.totals.missing_bank} / ${data.totals.not_verified}`;
                    if (hasIssues) {
                        bankText.className = 'fs-4 fw-bold lh-1 mt-1 text-warning-emphasis';
                        bankPill.className = 'payroll-kpi-pill bg-warning-subtle text-warning-emphasis';
                    } else {
                        bankText.className = 'fs-4 fw-bold lh-1 mt-1 text-success';
                        bankPill.className = 'payroll-kpi-pill bg-success-subtle text-success';
                    }
                }
            }

            {{-- Actualizar Badge del Periodo en Cabecera --}}
            const badge = document.getElementById('payrollPeriodBadge');
            if (badge) {
                badge.innerHTML = `<i class="bi bi-calendar-check" style="margin-right: 6px !important;"></i>Periodo ${monthNames[data.month]} ${data.year}`;
            }

            {{-- Actualizar enlace de descarga CSV --}}
            const exportBtn = document.getElementById('payrollExportBtn');
            if (exportBtn) {
                const expParams = new URLSearchParams({ year: data.year, month: data.month });
                if (data.branchId) expParams.append('branch_id', data.branchId);
                exportBtn.href = `/payroll/export?${expParams.toString()}`;
            }

            {{-- Mantener la URL del navegador limpia sin ensuciar la barra de direcciones --}}
            window.history.replaceState({}, '', window.location.pathname);

            {{-- Re-filtrar con el texto actual y re-renderizar tabla --}}
            currentPage = 1;
            filterRows();
        })
        .catch(err => {
            console.error('Error cargando planilla por AJAX:', err);
        })
        .finally(() => {
            isFetching = false;
            Panel.skeleton.off('#main-content');
        });
    }

    {{-- 3. Restablecer Filtros --}}
    function resetPayrollFilters() {
        monthSelect.value = initialMonth;
        yearInput.value = initialYear;
        branchSelect.value = '';
        searchInput.value = '';
        if (searchClearBtn) searchClearBtn.classList.add('d-none');
        fetchPayrollData();
    }

    {{-- 4. Filtrado en tiempo real en memoria --}}
    function filterRows() {
        const query = searchInput.value.trim().toLowerCase();
        if (searchClearBtn) {
            searchClearBtn.classList.toggle('d-none', query.length === 0);
        }

        filteredRows = allRows.filter(r => {
            if (query) {
                const matchesName = (r.full_name || '').toLowerCase().includes(query);
                const matchesCode = (r.employee_code || '').toLowerCase().includes(query);
                const matchesDoc  = (r.document_number || '').toLowerCase().includes(query);
                const matchesBank = (r.bank_name || '').toLowerCase().includes(query);
                const matchesAcc  = (r.account_number || '').toLowerCase().includes(query);
                if (!matchesName && !matchesCode && !matchesDoc && !matchesBank && !matchesAcc) {
                    return false;
                }
            }
            return true;
        });

        currentPage = 1;
        renderTable();
    }

    {{-- 5. Renderizado de Filas y Paginación --}}
    function renderTable() {
        const total = filteredRows.length;
        const totalPages = Math.max(1, Math.ceil(total / perPage));

        if (currentPage > totalPages) currentPage = totalPages;

        const start = (currentPage - 1) * perPage;
        const pageItems = filteredRows.slice(start, start + perPage);

        if (pageItems.length === 0) {
            tableBody.innerHTML = `
                <tr>
                    <td colspan="12" class="p-5 text-center">
                        <div class="d-inline-flex align-items-center justify-content-center p-3 rounded-circle bg-primary-subtle text-primary mb-3" style="width: 56px; height: 56px;">
                            <i class="bi bi-file-earmark-person fs-3"></i>
                        </div>
                        <h6 class="fw-bold text-body mb-1">Sin colaboradores en este periodo</h6>
                        <span class="text-body-secondary small">No hay registros con los filtros aplicados.</span>
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
            const payStatusBadge = r.payment_status === 'Verificado'
                ? '<span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1"><i class="bi bi-check-circle-fill" style="margin-right: 5px !important;"></i>Verificado</span>'
                : '<span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-2 py-1"><i class="bi bi-clock-history" style="margin-right: 5px !important;"></i>Por verificar</span>';

            html += `
                <tr>
                    <td class="ps-3">
                        <div class="fw-bold text-body">${r.full_name}</div>
                        <div class="text-body-secondary small">${r.employee_code || '—'} • DNI: ${r.document_number || '—'}</div>
                    </td>
                    <td>${r.labor_regime || '—'}</td>
                    <td class="text-end fw-semibold">${r.days_worked}</td>
                    <td class="text-end">${Number(r.worked_hours).toFixed(2)}</td>
                    <td class="text-end">
                        ${Number(r.overtime_hours).toFixed(2)}
                        ${r.overtime_pending_hours > 0 ? `<a href="/approvals?tab=overtime" class="d-block text-warning fw-semibold text-decoration-none small">+${Number(r.overtime_pending_hours).toFixed(2)} por aprobar</a>` : ''}
                    </td>
                    <td class="text-end fw-bold text-primary">${Number(r.payable_hours).toFixed(2)}</td>
                    <td>${r.late_count} <span class="text-body-secondary">(${r.late_minutes} min)</span></td>
                    <td>${r.absences} ${r.excused ? `<span class="text-body-secondary">+${r.excused} just.</span>` : ''}</td>
                    <td class="text-body-secondary text-nowrap">
                        <span title="Vacaciones">V: ${r.vacation_days}</span> •
                        <span title="Descanso médico">M: ${r.medical_leave_days}</span> •
                        <span title="Permisos">P: ${r.permission_days}</span>
                    </td>
                    <td>
                        <div class="fw-medium text-body">${r.bank_name || '—'}</div>
                        <div class="font-monospace text-body-secondary" style="font-size: 0.72rem;">${r.account_number || 'Sin cuenta'}</div>
                    </td>
                    <td>
                        <div>${r.pension_system || '—'}</div>
                        <div class="text-body-secondary" style="font-size: 0.72rem;">${r.pension_fund || ''}</div>
                    </td>
                    <td class="pe-3 text-center text-nowrap">${payStatusBadge}</td>
                </tr>
            `;
        });
        tableBody.innerHTML = html;

        const endItem = Math.min(start + perPage, total);
        paginationInfo.innerHTML = `Mostrando <span class="fw-bold text-body">${start + 1}</span> - <span class="fw-bold text-body">${endItem}</span> de <span class="fw-bold text-body">${total}</span> colaboradores`;

        if (totalPages <= 1) {
            paginationButtons.innerHTML = '';
            return;
        }

        let btns = `
            <button type="button" class="btn btn-sm btn-outline-secondary px-2 py-1" style="border-radius: 6px;" ${currentPage === 1 ? 'disabled' : ''} onclick="changePayrollPage(${currentPage - 1})">
                <i class="bi bi-chevron-left" style="margin-right: 4px !important;"></i>Anterior
            </button>
        `;

        for (let p = 1; p <= totalPages; p++) {
            btns += `
                <button type="button" class="btn btn-sm ${p === currentPage ? 'btn-primary fw-bold' : 'btn-outline-secondary'}" style="width: 32px; height: 32px; border-radius: 6px; padding: 0;" onclick="changePayrollPage(${p})">
                    ${p}
                </button>
            `;
        }

        btns += `
            <button type="button" class="btn btn-sm btn-outline-secondary px-2 py-1" style="border-radius: 6px;" ${currentPage === totalPages ? 'disabled' : ''} onclick="changePayrollPage(${currentPage + 1})">
                Siguiente<i class="bi bi-chevron-right" style="margin-left: 4px !important;"></i>
            </button>
        `;

        paginationButtons.innerHTML = btns;
    }

    window.changePayrollPage = function(page) {
        currentPage = page;
        renderTable();
    };

    {{-- Event listeners --}}
    monthSelect.addEventListener('change', fetchPayrollData);
    yearInput.addEventListener('change', fetchPayrollData);
    yearInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            fetchPayrollData();
        }
    });
    branchSelect.addEventListener('change', fetchPayrollData);
    resetBtn.addEventListener('click', resetPayrollFilters);
    searchInput.addEventListener('input', filterRows);
    if (searchClearBtn) {
        searchClearBtn.addEventListener('click', () => {
            searchInput.value = '';
            filterRows();
            searchInput.focus();
        });
    }

    renderTable();
});
</script>
@endsection
