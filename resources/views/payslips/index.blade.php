@extends('layouts.app')

@section('title', 'Boletas de pago')

@php
    $monthNames = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
        5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
        9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
    ];
    $currentMonthName = $monthNames[$currentMonth] ?? 'Mes ' . $currentMonth;
@endphp

@section('content')
<div class="payslips-page-wrapper">

    {{-- =========================================================================
         1. CABECERA EJECUTIVA DEL MÓDULO
         ========================================================================= --}}
    <div class="payslips-header-card shadow-sm p-3 p-md-4 mb-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center">
                <div class="payslips-header-icon shadow-sm" style="margin-right: 16px !important;">
                    <i class="bi bi-receipt-cutoff"></i>
                </div>
                <div>
                    <div class="d-flex align-items-center flex-wrap mb-1" style="gap: 10px !important;">
                        <h1 class="h4 fw-bold mb-0 text-body">Boletas de Pago</h1>
                        <span class="badge rounded-2 bg-primary-subtle text-primary border border-primary-subtle px-2.5 py-1 small fw-semibold">
                            <i class="bi bi-cash-stack" style="margin-right: 6px !important;"></i>Nómina Digital
                        </span>
                        <span class="badge rounded-2 bg-info-subtle text-info border border-info-subtle px-2.5 py-1 small fw-semibold" id="headerPeriodBadge">
                            <i class="bi bi-calendar3" style="margin-right: 6px !important;"></i><span id="headerPeriodText">{{ $currentMonthName }} {{ $currentYear }}</span>
                        </span>
                        <span class="badge rounded-2 bg-warning-subtle text-warning-emphasis border border-warning-subtle px-2.5 py-1 small fw-semibold {{ ($summary['drafts'] ?? 0) > 0 ? '' : 'd-none' }}" id="headerDraftsBadge">
                            <i class="bi bi-clock-history" style="margin-right: 6px !important;"></i><span id="headerDraftsText">{{ $summary['drafts'] ?? 0 }}</span> pendiente(s) de publicar
                        </span>
                    </div>
                    <p class="text-secondary small mb-0">
                        Carga y distribución segura de boletas en PDF con cálculo de haberes, firma digital y auditoría de lectura móvil en tiempo real.
                    </p>
                </div>
            </div>

            <div class="d-flex align-items-center flex-wrap" style="gap: 10px;">
                <button type="button" 
                        class="btn btn-outline-primary d-inline-flex align-items-center shadow-sm"
                        style="height: 38px; border-radius: 6px; font-weight: 600; font-size: 0.85rem; padding: 0 1.15rem;"
                        data-bs-toggle="modal" 
                        data-bs-target="#bulkUploadPayslipModal">
                    <i class="bi bi-files" style="margin-right: 8px !important;"></i>
                    <span>Carga masiva</span>
                </button>
                <button type="button" 
                        class="btn btn-primary d-inline-flex align-items-center shadow-sm"
                        style="height: 38px; border-radius: 6px; font-weight: 600; font-size: 0.85rem; padding: 0 1.25rem;"
                        data-bs-toggle="modal" 
                        data-bs-target="#uploadSinglePayslipModal">
                    <i class="bi bi-cloud-arrow-up-fill" style="margin-right: 8px !important;"></i>
                    <span>Subir boleta</span>
                </button>
            </div>
        </div>
    </div>

    {{-- =========================================================================
         2. TARJETAS DE MÉTRICAS KPI (RESUMEN EJECUTIVO CON IDs REACTIVOS)
         ========================================================================= --}}
    @php
        $totalEmitted = $summary['total_emitted'] ?? 0;
        $publishedCount = $summary['published'] ?? 0;
        $readCount = $summary['read_count'] ?? 0;
        $totalPayroll = (float) ($summary['total_payroll'] ?? 0);
        $pubPercent = $totalEmitted > 0 ? round(($publishedCount / $totalEmitted) * 100) : 0;
        $readPercent = $publishedCount > 0 ? round(($readCount / $publishedCount) * 100) : 0;
    @endphp

    <div class="row g-3 mb-4">
        {{-- Total Emitidas --}}
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="payslips-kpi-card shadow-sm p-3 h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-muted fw-semibold small text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.05em;">Emitidas en Periodo</span>
                    <div class="payslips-kpi-pill bg-primary-subtle text-primary">
                        <i class="bi bi-file-earmark-text-fill"></i>
                    </div>
                </div>
                <div class="d-flex align-items-baseline gap-2">
                    <span class="h3 fw-bold mb-0 text-body" id="kpi_total_emitted">{{ $totalEmitted }}</span>
                    <span class="text-muted small">boletas</span>
                </div>
                <div class="text-muted small mt-1" style="font-size: 0.75rem;" id="kpi_period_hint">
                    Periodo {{ sprintf('%02d', $currentMonth) }}/{{ $currentYear }}
                </div>
            </div>
        </div>

        {{-- Publicadas en App --}}
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="payslips-kpi-card shadow-sm p-3 h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-muted fw-semibold small text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.05em;">Publicadas en App</span>
                    <div class="payslips-kpi-pill bg-emerald-subtle text-emerald">
                        <i class="bi bi-phone-fill"></i>
                    </div>
                </div>
                <div class="d-flex align-items-baseline gap-2">
                    <span class="h3 fw-bold mb-0 text-body" id="kpi_published">{{ $publishedCount }}</span>
                    <span class="badge {{ $publishedCount > 0 ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary' }}" id="kpi_pub_badge">
                        {{ $pubPercent }}% emitidas
                    </span>
                </div>
                <div class="text-muted small mt-1" style="font-size: 0.75rem;">
                    Disponibles en el móvil del colaborador
                </div>
            </div>
        </div>

        {{-- Leídas / Confirmadas --}}
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="payslips-kpi-card shadow-sm p-3 h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-muted fw-semibold small text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.05em;">Leídas por Empleado</span>
                    <div class="payslips-kpi-pill bg-info-subtle text-info">
                        <i class="bi bi-check2-all"></i>
                    </div>
                </div>
                <div class="d-flex align-items-baseline gap-2">
                    <span class="h3 fw-bold mb-0 text-body" id="kpi_read_count">{{ $readCount }}</span>
                    <span class="text-muted small" id="kpi_read_percent">({{ $readPercent }}% de publicadas)</span>
                </div>
                <div class="text-muted small mt-1" style="font-size: 0.75rem;">
                    Confirmación de recepción y apertura
                </div>
            </div>
        </div>

        {{-- Neto Total --}}
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="payslips-kpi-card shadow-sm p-3 h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-muted fw-semibold small text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.05em;">Planilla Neta Total</span>
                    <div class="payslips-kpi-pill bg-amber-subtle text-amber">
                        <i class="bi bi-cash-stack"></i>
                    </div>
                </div>
                <div class="d-flex align-items-baseline gap-2">
                    <span class="h3 fw-bold mb-0 text-body font-monospace" id="kpi_total_payroll">S/ {{ number_format($totalPayroll, 2) }}</span>
                </div>
                <div class="text-muted small mt-1" style="font-size: 0.75rem;">
                    Total neto a desembolsar en el mes
                </div>
            </div>
        </div>
    </div>

    {{-- =========================================================================
         3. BARRA DE FILTROS & CALENDARIO MODERNO DE PERIODO (AJAX INTERNO)
         ========================================================================= --}}
    <div class="payslips-filter-card shadow-sm p-3 mb-4">
        <div class="d-flex flex-wrap align-items-end justify-content-between gap-3">
            <form id="payslipsFilterForm" onsubmit="event.preventDefault(); applyPayslipFilters();" class="d-flex flex-wrap align-items-end flex-grow-1" style="gap: 12px;">
                {{-- Campos ocultos para Año y Mes sincronizados por el calendario --}}
                <input type="hidden" name="year" id="filter_year" value="{{ $currentYear }}">
                <input type="hidden" name="month" id="filter_month" value="{{ $currentMonth }}">

                {{-- Búsqueda predictiva AJAX --}}
                <div class="filter-field-box" style="flex: 1 1 210px; min-width: 180px;">
                    <label for="filter_search" class="filter-label">
                        <i class="bi bi-search" style="margin-right: 6px !important;"></i>Buscar
                    </label>
                    <input type="text" 
                           id="filter_search" 
                           name="search" 
                           value="{{ request('search') }}" 
                           class="form-control payslip-input"
                           placeholder="Nombre, DNI o boleta..."
                           autocomplete="off">
                </div>

                {{-- CALENDARIO MODERNO DE PERIODO (Reemplazo elegante de los selects separados) --}}
                <div class="filter-field-box" style="min-width: 190px;">
                    <label class="filter-label">
                        <i class="bi bi-calendar3" style="margin-right: 6px !important;"></i>Periodo de Pago
                    </label>
                    <div class="dropdown" id="payslipPeriodDropdown">
                        <button type="button" 
                                class="btn btn-outline-secondary d-inline-flex align-items-center justify-content-between payslip-calendar-trigger-btn w-100" 
                                data-bs-toggle="dropdown" 
                                data-bs-auto-close="outside" 
                                id="periodPickerBtn" 
                                aria-expanded="false">
                            <span class="d-inline-flex align-items-center">
                                <i class="bi bi-calendar-event text-primary" style="margin-right: 8px !important;"></i>
                                <span class="fw-bold text-body" id="selectedPeriodDisplay">{{ $currentMonthName }} {{ $currentYear }}</span>
                            </span>
                            <i class="bi bi-chevron-down text-muted small" style="margin-left: 8px !important;"></i>
                        </button>

                        <div class="dropdown-menu calendar-pro-popover shadow-lg p-0" id="calendarPeriodPopover" aria-labelledby="periodPickerBtn">
                            <div class="calendar-pro-grid">
                                {{-- Columna Lateral: Periodos Rápidos --}}
                                <div class="calendar-sidebar-presets">
                                    <div class="calendar-presets-heading">ACCESOS RÁPIDOS</div>
                                    <button type="button" class="calendar-preset-btn" data-period-year="{{ date('Y') }}" data-period-month="{{ date('n') }}">
                                        <span>Mes actual</span>
                                        <i class="bi bi-check-lg ms-auto {{ ($currentYear == date('Y') && $currentMonth == date('n')) ? '' : 'd-none' }}"></i>
                                    </button>
                                    @php
                                        $prevM = date('n') == 1 ? 12 : date('n') - 1;
                                        $prevY = date('n') == 1 ? date('Y') - 1 : date('Y');
                                    @endphp
                                    <button type="button" class="calendar-preset-btn" data-period-year="{{ $prevY }}" data-period-month="{{ $prevM }}">
                                        <span>Mes anterior</span>
                                    </button>
                                    <button type="button" class="calendar-preset-btn" data-period-year="{{ date('Y') }}" data-period-month="10">
                                        <span>Octubre {{ date('Y') }}</span>
                                    </button>
                                    <button type="button" class="calendar-preset-btn" data-period-year="{{ date('Y') }}" data-period-month="9">
                                        <span>Septiembre {{ date('Y') }}</span>
                                    </button>
                                    <button type="button" class="calendar-preset-btn" data-period-year="{{ date('Y') }}" data-period-month="8">
                                        <span>Agosto {{ date('Y') }}</span>
                                    </button>
                                </div>

                                {{-- Columna Principal: Navegador de Año y Matriz de 12 Meses --}}
                                <div class="calendar-content-pane">
                                    <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
                                        <button type="button" class="calendar-nav-btn" id="btnCalPrevYear" title="Año anterior">
                                            <i class="bi bi-chevron-left"></i>
                                        </button>
                                        <span class="calendar-month-title fs-6 fw-bold text-body" id="calActiveYearDisplay">{{ $currentYear }}</span>
                                        <button type="button" class="calendar-nav-btn" id="btnCalNextYear" title="Año siguiente">
                                            <i class="bi bi-chevron-right"></i>
                                        </button>
                                    </div>

                                    <div class="calendar-months-grid mb-2">
                                        @foreach($monthNames as $mNum => $mLabel)
                                            <button type="button" 
                                                    class="calendar-month-cell {{ $currentMonth == $mNum ? 'is-selected' : '' }}" 
                                                    data-month="{{ $mNum }}">
                                                {{ substr($mLabel, 0, 3) }}
                                            </button>
                                        @endforeach
                                    </div>

                                    <div class="text-muted small mt-auto pt-2 border-top d-flex justify-content-between align-items-center" style="font-size: 0.725rem;">
                                        <span>Selecciona mes y año</span>
                                        <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none fw-semibold" id="btnApplyCurrentMonth">Mes actual</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Colaborador --}}
                <div class="filter-field-box" style="flex: 1 1 200px; min-width: 180px;">
                    <label for="filter_user_id" class="filter-label">
                        <i class="bi bi-person" style="margin-right: 6px !important;"></i>Colaborador
                    </label>
                    <select id="filter_user_id" name="user_id" class="form-select payslip-input">
                        <option value="">Todos los colaboradores</option>
                        @foreach($employees as $e)
                            <option value="{{ $e->id }}" @selected(request('user_id') == $e->id)>
                                {{ $e->first_name }} {{ $e->last_name }} ({{ $e->document_number }})
                            </option>
                        @endforeach
                    </select>
                </div>

                {{-- Estado --}}
                <div class="filter-field-box" style="width: 130px;">
                    <label for="filter_status" class="filter-label">
                        <i class="bi bi-funnel" style="margin-right: 6px !important;"></i>Estado
                    </label>
                    <select id="filter_status" name="status" class="form-select payslip-input">
                        <option value="">Todos</option>
                        <option value="draft" @selected(request('status') === 'draft')>Borrador</option>
                        <option value="published" @selected(request('status') === 'published')>Publicada</option>
                        <option value="archived" @selected(request('status') === 'archived')>Anulada</option>
                    </select>
                </div>

                {{-- Botones de Filtrar y Limpiar --}}
                <div class="d-flex align-items-center" style="gap: 8px;">
                    <button type="button" onclick="applyPayslipFilters();" class="btn btn-primary d-inline-flex align-items-center btn-payslip-filter shadow-sm">
                        <i class="bi bi-funnel-fill" style="margin-right: 8px !important;"></i>
                        <span>Filtrar</span>
                    </button>
                    <button type="button" onclick="resetPayslipFilters();" class="btn btn-outline-secondary d-inline-flex align-items-center btn-payslip-reset" title="Restablecer filtros">
                        <i class="bi bi-arrow-counterclockwise" style="margin-right: 8px !important;"></i>
                        <span>Limpiar</span>
                    </button>
                </div>
            </form>

            {{-- Botón Publicar Todas con Modal Dialog --}}
            <div class="d-flex align-items-center">
                @php $draftsCount = $summary['drafts'] ?? 0; @endphp
                <button type="button" 
                        class="btn btn-success d-inline-flex align-items-center shadow-sm btn-publish-all"
                        id="btnPublishAllTrigger"
                        @if($draftsCount > 0)
                            data-bs-toggle="modal" 
                            data-bs-target="#confirmPublishAllModal"
                        @else
                            disabled 
                            title="No hay boletas en borrador para publicar"
                        @endif>
                    <i class="bi bi-send-check" style="margin-right: 8px !important;"></i>
                    <span>Publicar todas</span>
                    <span class="badge bg-white text-success ms-2 fw-bold {{ $draftsCount > 0 ? '' : 'd-none' }}" id="publishAllCountBadge" style="border-radius: 6px;">{{ $draftsCount }}</span>
                </button>
            </div>
        </div>
    </div>

    {{-- =========================================================================
         4. CONTENEDOR DE LA TABLA (ACTUALIZADO POR AJAX)
         ========================================================================= --}}
    <div id="payslipsTableWrapper" style="position: relative; min-height: 200px;">
        <div id="tableLoadingOverlay" class="table-loading-overlay d-none">
            <div class="spinner-border text-primary" role="status" style="width: 2.5rem; height: 2.5rem;">
                <span class="visually-hidden">Cargando datos...</span>
            </div>
        </div>
        @include('payslips.partials.table', [
            'payslips'     => $payslips,
            'currentYear'  => $currentYear,
            'currentMonth' => $currentMonth,
            'summary'      => $summary
        ])
    </div>

</div>

{{-- =========================================================================
     5. MODAL DIALOGS (Bootstrap 5, Elegantes, Administrables y Espaciados)
     ========================================================================= --}}

{{-- Modal 1: Subir Boleta Individual --}}
<div class="modal fade" id="uploadSinglePayslipModal" tabindex="-1" aria-labelledby="uploadSinglePayslipLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 12px; overflow: hidden;">
            <div class="modal-header border-bottom py-3 px-4" style="background: var(--bg-surface-elevated, #f8fafc);">
                <div class="d-flex align-items-center">
                    <div class="modal-header-icon bg-primary-subtle text-primary shadow-xs" style="margin-right: 14px !important;">
                        <i class="bi bi-cloud-arrow-up-fill"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold text-body mb-0" id="uploadSinglePayslipLabel">Subir Boleta de Pago</h5>
                        <p class="text-secondary small mb-0">Carga individual de boleta en formato PDF para un colaborador.</p>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            
            <form method="POST" action="{{ route('payslips.store') }}" enctype="multipart/form-data" id="formSingleUpload">
                @csrf
                <div class="modal-body p-4" style="max-height: calc(85vh - 130px); overflow-y: auto;">
                    <div class="row g-3">
                        {{-- Colaborador --}}
                        <div class="col-12">
                            <label for="modal_user_id" class="form-label fw-semibold text-body small">
                                Colaborador <span class="text-danger">*</span>
                            </label>
                            <select id="modal_user_id" name="user_id" required class="form-select payslip-input">
                                <option value="">Selecciona un colaborador...</option>
                                @foreach($employees as $e)
                                    <option value="{{ $e->id }}">
                                        {{ $e->first_name }} {{ $e->last_name }} (DNI {{ $e->document_number }} · {{ $e->position ?? 'Operativo' }})
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        {{-- Título --}}
                        <div class="col-12">
                            <label for="modal_title" class="form-label fw-semibold text-body small">
                                Título de la boleta <span class="text-danger">*</span>
                            </label>
                            <input type="text" 
                                   id="modal_title" 
                                   name="title" 
                                   value="Boleta de pago {{ $currentMonthName }} {{ $currentYear }}" 
                                   required 
                                   class="form-control payslip-input">
                        </div>

                        {{-- Mes y Año --}}
                        <div class="col-12 col-sm-6">
                            <label for="modal_period_month" class="form-label fw-semibold text-body small">
                                Mes del periodo <span class="text-danger">*</span>
                            </label>
                            <select id="modal_period_month" name="period_month" required class="form-select payslip-input">
                                @foreach($monthNames as $mNum => $mLabel)
                                    <option value="{{ $mNum }}" @selected($currentMonth == $mNum)>{{ $mLabel }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-12 col-sm-6">
                            <label for="modal_period_year" class="form-label fw-semibold text-body small">
                                Año del periodo <span class="text-danger">*</span>
                            </label>
                            <input type="number" 
                                   id="modal_period_year" 
                                   name="period_year" 
                                   value="{{ $currentYear }}" 
                                   min="2020" 
                                   max="2035" 
                                   required 
                                   class="form-control payslip-input">
                        </div>

                        {{-- Montos --}}
                        <div class="col-12">
                            <div class="p-3 rounded-2 border" style="background: var(--bg-surface-elevated, #f8fafc);">
                                <div class="fw-bold small text-body mb-2">Desglose de Remuneración (Soles)</div>
                                <div class="row g-2">
                                    <div class="col-12 col-md-4">
                                        <label for="modal_grossSalary" class="form-label small text-secondary">Sueldo Bruto <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <span class="input-group-text small">S/</span>
                                            <input type="number" step="0.01" min="0" id="modal_grossSalary" name="gross_salary" value="0.00" required class="form-control payslip-input font-monospace">
                                        </div>
                                    </div>
                                    <div class="col-12 col-md-4">
                                        <label for="modal_deductions" class="form-label small text-secondary">Descuentos <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <span class="input-group-text small">S/</span>
                                            <input type="number" step="0.01" min="0" id="modal_deductions" name="deductions" value="0.00" required class="form-control payslip-input font-monospace text-danger">
                                        </div>
                                    </div>
                                    <div class="col-12 col-md-4">
                                        <label for="modal_netSalary" class="form-label small text-secondary">Neto a Pagar <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <span class="input-group-text small">S/</span>
                                            <input type="number" step="0.01" min="0" id="modal_netSalary" name="net_salary" value="0.00" required class="form-control payslip-input font-monospace fw-bold text-success">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Archivo PDF --}}
                        <div class="col-12">
                            <label for="modal_pdf_file" class="form-label fw-semibold text-body small">
                                Archivo Boleta PDF <span class="text-danger">*</span>
                            </label>
                            <input type="file" id="modal_pdf_file" name="pdf_file" accept=".pdf,application/pdf" required class="form-control payslip-input">
                            <div class="d-flex align-items-center justify-content-between p-2 rounded bg-light-subtle border mt-2" style="font-size: 0.775rem;">
                                <span class="text-secondary"><i class="bi bi-file-earmark-pdf me-1"></i>Documento PDF oficial firmado</span>
                                <span class="badge bg-secondary-subtle text-secondary">Máximo 10 MB</span>
                            </div>
                        </div>

                        {{-- Interruptor Ejecutivo Moderno --}}
                        <div class="col-12">
                            <div class="payslip-executive-switch-card p-3 rounded-2 border d-flex align-items-center justify-content-between" id="singlePublishSwitchBox">
                                <div class="d-flex align-items-center">
                                    <i class="bi bi-phone-vibrate-fill text-primary fs-4" style="margin-right: 14px !important; flex-shrink: 0;"></i>
                                    <div>
                                        <div class="fw-bold small text-body mb-0.5">Publicación Inmediata en App Móvil</div>
                                        <div class="text-secondary small" style="font-size: 0.75rem;">El colaborador recibirá notificación push y podrá ver su boleta al instante.</div>
                                    </div>
                                </div>
                                <div class="form-check form-switch m-0 p-0 d-flex align-items-center">
                                    <input class="form-check-input custom-executive-switch m-0" type="checkbox" role="switch" id="modal_publish_now" name="publish_now" value="1" checked>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top py-2.5 px-4" style="background: var(--bg-surface-elevated, #f8fafc);">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" style="height: 38px; border-radius: 6px; font-weight: 600; font-size: 0.85rem;">
                        Cancelar
                    </button>
                    <button type="submit" class="btn btn-primary d-inline-flex align-items-center" style="height: 38px; border-radius: 6px; font-weight: 600; font-size: 0.85rem; padding: 0 1.25rem;">
                        <i class="bi bi-cloud-arrow-up-fill" style="margin-right: 8px !important;"></i>
                        <span>Cargar boleta</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Modal 2: Carga Masiva de Boletas --}}
<div class="modal fade" id="bulkUploadPayslipModal" tabindex="-1" aria-labelledby="bulkUploadPayslipLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 12px; overflow: hidden;">
            <div class="modal-header border-bottom py-3 px-4" style="background: var(--bg-surface-elevated, #f8fafc);">
                <div class="d-flex align-items-center">
                    <div class="modal-header-icon bg-info-subtle text-info shadow-xs" style="margin-right: 14px !important;">
                        <i class="bi bi-files"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold text-body mb-0" id="bulkUploadPayslipLabel">Carga Masiva de Boletas</h5>
                        <p class="text-secondary small mb-0">Sube varios PDFs a la vez asignados automáticamente por DNI o código.</p>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            
            <form method="POST" action="{{ route('payslips.bulk.store') }}" enctype="multipart/form-data" id="formBulkUpload">
                @csrf
                <div class="modal-body p-4" style="max-height: calc(85vh - 130px); overflow-y: auto;">
                    {{-- Instrucciones de nombrado con icono con margen garantizado --}}
                    <div class="alert alert-info border-0 d-flex align-items-start mb-3 p-3 rounded-2" style="background: rgba(2, 132, 199, 0.08); color: var(--accent-cyan, #0284c7);">
                        <i class="bi bi-info-circle-fill fs-5" style="margin-right: 12px !important; flex-shrink: 0; margin-top: 1px;"></i>
                        <div class="small">
                            <strong>Regla de Asignación Automática:</strong> El sistema identifica al colaborador si el nombre del archivo contiene su <strong>DNI</strong> (ej. <code>boleta_45871236.pdf</code>) o su <strong>Código</strong> (ej. <code>EMP-003.pdf</code>). Si ya existía un borrador para ese periodo, se reemplaza.
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-12 col-sm-6">
                            <label for="bulk_modal_period_month" class="form-label fw-semibold text-body small">Mes de emisión <span class="text-danger">*</span></label>
                            <select id="bulk_modal_period_month" name="period_month" required class="form-select payslip-input">
                                @foreach($monthNames as $mNum => $mLabel)
                                    <option value="{{ $mNum }}" @selected($currentMonth == $mNum)>{{ $mLabel }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-12 col-sm-6">
                            <label for="bulk_modal_period_year" class="form-label fw-semibold text-body small">Año de emisión <span class="text-danger">*</span></label>
                            <input type="number" 
                                   id="bulk_modal_period_year" 
                                   name="period_year" 
                                   value="{{ $currentYear }}" 
                                   min="2020" 
                                   max="2100" 
                                   required 
                                   class="form-control payslip-input">
                        </div>

                        <div class="col-12">
                            <label for="bulkFilesInput" class="form-label fw-semibold text-body small">Archivos PDF <span class="text-danger">*</span></label>
                            <input type="file" 
                                   name="files[]" 
                                   accept="application/pdf,.pdf" 
                                   multiple 
                                   required 
                                   class="form-control payslip-input" 
                                   id="bulkFilesInput">
                            <div class="d-flex align-items-center justify-content-between p-2 rounded bg-light-subtle border mt-2" style="font-size: 0.775rem;">
                                <span class="text-secondary" id="bulkFilesCountNote"><i class="bi bi-paperclip me-1"></i>Ningún archivo seleccionado.</span>
                                <span class="badge bg-secondary-subtle text-secondary">Máx. 500 archivos por lote (10MB c/u)</span>
                            </div>
                        </div>

                        {{-- Interruptor Ejecutivo Moderno Carga Masiva --}}
                        <div class="col-12">
                            <div class="payslip-executive-switch-card p-3 rounded-2 border d-flex align-items-center justify-content-between" id="bulkPublishSwitchBox">
                                <div class="d-flex align-items-center">
                                    <i class="bi bi-send-check-fill text-primary fs-4" style="margin-right: 14px !important; flex-shrink: 0;"></i>
                                    <div>
                                        <div class="fw-bold small text-body mb-0.5">Publicar de inmediato todas las boletas cargadas</div>
                                        <div class="text-secondary small" style="font-size: 0.75rem;">Se emitirá notificación push masiva a todos los colaboradores con boleta cargada.</div>
                                    </div>
                                </div>
                                <div class="form-check form-switch m-0 p-0 d-flex align-items-center">
                                    <input class="form-check-input custom-executive-switch m-0" type="checkbox" role="switch" id="bulk_publish_now" name="publish_now" value="1">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer border-top py-2.5 px-4 d-flex justify-content-between align-items-center" style="background: var(--bg-surface-elevated, #f8fafc);">
                    <a href="{{ route('payslips.bulk') }}" class="small text-secondary text-decoration-none d-inline-flex align-items-center">
                        <i class="bi bi-box-arrow-up-right" style="margin-right: 6px !important;"></i>Ver historial de cargas
                    </a>
                    <div class="d-flex" style="gap: 8px;">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" style="height: 38px; border-radius: 6px; font-weight: 600; font-size: 0.85rem;">
                            Cancelar
                        </button>
                        <button type="submit" class="btn btn-primary d-inline-flex align-items-center" style="height: 38px; border-radius: 6px; font-weight: 600; font-size: 0.85rem; padding: 0 1.25rem;">
                            <i class="bi bi-upload" style="margin-right: 8px !important;"></i>
                            <span>Procesar carga masiva</span>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Modal 3: Confirmar Publicación Masiva --}}
<div class="modal fade" id="confirmPublishAllModal" tabindex="-1" aria-labelledby="confirmPublishAllLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 12px; overflow: hidden;">
            <div class="modal-header border-0 pb-0 pt-4 px-4 text-center d-flex flex-column align-items-center">
                <div class="modal-warning-icon bg-success-subtle text-success mb-3 shadow-xs">
                    <i class="bi bi-send-check-fill fs-3"></i>
                </div>
                <h5 class="modal-title fw-bold text-body" id="confirmPublishAllLabel">¿Publicar todas las boletas?</h5>
            </div>
            
            <form method="POST" action="{{ route('payslips.bulk-publish') }}">
                @csrf
                <input type="hidden" name="year" id="bulkPublishYearInput" value="{{ $currentYear }}">
                <input type="hidden" name="month" id="bulkPublishMonthInput" value="{{ $currentMonth }}">
                
                <div class="modal-body px-4 py-3 text-center">
                    <p class="text-secondary small mb-2">
                        Estás a punto de publicar <strong><span id="confirmPublishCountDisplay">{{ $summary['drafts'] ?? 0 }}</span> boleta(s) en borrador</strong> correspondientes al periodo <strong><span id="confirmPublishPeriodDisplay">{{ $currentMonthName }} {{ $currentYear }}</span></strong>.
                    </p>
                    <p class="text-muted small mb-0">
                        Una vez publicadas, estarán inmediatamente visibles y descargables en la app móvil de cada colaborador con notificación push.
                    </p>
                </div>
                
                <div class="modal-footer border-0 pt-0 pb-4 px-4 justify-content-center" style="gap: 10px;">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" style="height: 38px; border-radius: 6px; font-weight: 600; font-size: 0.85rem; min-width: 110px;">
                        Cancelar
                    </button>
                    <button type="submit" class="btn btn-success d-inline-flex align-items-center shadow-sm" style="height: 38px; border-radius: 6px; font-weight: 600; font-size: 0.85rem; min-width: 140px; justify-content: center;">
                        <i class="bi bi-send-check" style="margin-right: 8px !important;"></i>
                        <span>Sí, publicar todas</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Modal 4: Confirmar Publicar Individual --}}
<div class="modal fade" id="confirmPublishModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 12px; overflow: hidden;">
            <div class="modal-header border-0 pb-0 pt-4 px-4 text-center d-flex flex-column align-items-center">
                <div class="modal-warning-icon bg-success-subtle text-success mb-3 shadow-xs">
                    <i class="bi bi-send-check-fill fs-3"></i>
                </div>
                <h5 class="modal-title fw-bold text-body">Publicar Boleta de Pago</h5>
            </div>
            
            <div class="modal-body px-4 py-3 text-center">
                <p class="text-secondary small mb-2">
                    ¿Deseas publicar la boleta para <strong id="publishEmployeeName" class="text-body"></strong>?
                </p>
                <div class="p-2.5 rounded border bg-light-subtle small text-muted mx-auto" style="max-width: 380px;" id="publishPayslipTitle"></div>
            </div>
            
            <div class="modal-footer border-0 pt-0 pb-4 px-4 justify-content-center" style="gap: 10px;">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" style="height: 38px; border-radius: 6px; font-weight: 600; font-size: 0.85rem; min-width: 100px;">
                    Cancelar
                </button>
                <button type="button" class="btn btn-success d-inline-flex align-items-center shadow-sm" id="btnConfirmPublishSubmit" style="height: 38px; border-radius: 6px; font-weight: 600; font-size: 0.85rem; min-width: 120px; justify-content: center;">
                    <i class="bi bi-check-lg" style="margin-right: 6px !important;"></i>
                    <span>Publicar</span>
                </button>
            </div>
        </div>
    </div>
</div>

{{-- Modal 5: Confirmar Anular --}}
<div class="modal fade" id="confirmAnnulModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 12px; overflow: hidden;">
            <div class="modal-header border-0 pb-0 pt-4 px-4 text-center d-flex flex-column align-items-center">
                <div class="modal-warning-icon bg-warning-subtle text-warning-emphasis mb-3 shadow-xs">
                    <i class="bi bi-exclamation-triangle-fill fs-3"></i>
                </div>
                <h5 class="modal-title fw-bold text-body">¿Anular esta boleta publicada?</h5>
            </div>
            
            <div class="modal-body px-4 py-3 text-center">
                <p class="text-secondary small mb-2">
                    La boleta de <strong id="annulEmployeeName" class="text-body"></strong> dejará de verse en la app móvil del colaborador.
                </p>
                <p class="text-muted small mb-0">
                    El registro quedará archivado con fines de auditoría y cumplimiento laboral.
                </p>
            </div>
            
            <div class="modal-footer border-0 pt-0 pb-4 px-4 justify-content-center" style="gap: 10px;">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" style="height: 38px; border-radius: 6px; font-weight: 600; font-size: 0.85rem; min-width: 100px;">
                    Cancelar
                </button>
                <button type="button" class="btn btn-danger d-inline-flex align-items-center shadow-sm" id="btnConfirmAnnulSubmit" style="height: 38px; border-radius: 6px; font-weight: 600; font-size: 0.85rem; min-width: 120px; justify-content: center;">
                    <i class="bi bi-slash-circle" style="margin-right: 6px !important;"></i>
                    <span>Sí, anular</span>
                </button>
            </div>
        </div>
    </div>
</div>

{{-- Modal 6: Confirmar Eliminar Borrador --}}
<div class="modal fade" id="confirmDeleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 12px; overflow: hidden;">
            <div class="modal-header border-0 pb-0 pt-4 px-4 text-center d-flex flex-column align-items-center">
                <div class="modal-warning-icon bg-danger-subtle text-danger mb-3 shadow-xs">
                    <i class="bi bi-trash3-fill fs-3"></i>
                </div>
                <h5 class="modal-title fw-bold text-body">¿Eliminar boleta en borrador?</h5>
            </div>
            
            <div class="modal-body px-4 py-3 text-center">
                <p class="text-secondary small mb-2">
                    Se eliminará permanentemente la boleta y el archivo PDF de <strong id="deleteEmployeeName" class="text-body"></strong>.
                </p>
                <p class="text-muted small mb-0">
                    Podrás volver a subir la boleta correcta de este periodo una vez eliminada.
                </p>
            </div>
            
            <div class="modal-footer border-0 pt-0 pb-4 px-4 justify-content-center" style="gap: 10px;">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" style="height: 38px; border-radius: 6px; font-weight: 600; font-size: 0.85rem; min-width: 100px;">
                    Cancelar
                </button>
                <button type="button" class="btn btn-danger d-inline-flex align-items-center shadow-sm" id="btnConfirmDeleteSubmit" style="height: 38px; border-radius: 6px; font-weight: 600; font-size: 0.85rem; min-width: 120px; justify-content: center;">
                    <i class="bi bi-trash" style="margin-right: 6px !important;"></i>
                    <span>Eliminar</span>
                </button>
            </div>
        </div>
    </div>
</div>

{{-- Modal 7: Vista Previa PDF --}}
<div class="modal fade" id="previewPdfModal" tabindex="-1" aria-labelledby="previewPdfLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable modal-fullscreen-sm-down modal-dialog-centered modal-xl" style="max-width: 900px;">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 12px; overflow: hidden;">
            <div class="modal-header border-bottom py-2.5 px-4" style="background: var(--bg-surface-elevated, #f8fafc);">
                <div class="d-flex align-items-center">
                    <div class="modal-header-icon bg-danger-subtle text-danger" style="margin-right: 12px !important; width: 36px; height: 36px; font-size: 1.1rem;">
                        <i class="bi bi-file-earmark-pdf-fill"></i>
                    </div>
                    <div>
                        <h6 class="modal-title fw-bold text-body mb-0" id="previewPdfTitle">Vista Previa de Boleta</h6>
                        <span class="text-secondary small" id="previewPdfSubtitle">Documento PDF seguro</span>
                    </div>
                </div>
                <div class="d-flex align-items-center" style="gap: 8px;">
                    <a href="#" id="previewPdfNewTabBtn" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center" title="Abrir en pestaña nueva">
                        <i class="bi bi-box-arrow-up-right" style="margin-right: 6px !important;"></i>Pestaña nueva
                    </a>
                    <a href="#" id="previewPdfDownloadBtn" class="btn btn-sm btn-outline-primary d-inline-flex align-items-center" title="Descargar PDF">
                        <i class="bi bi-download" style="margin-right: 6px !important;"></i>Descargar
                    </a>
                    <button type="button" class="btn-close ms-2" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
            </div>
            <div class="modal-body p-0" style="background: #525659; min-height: 550px; height: 70vh;">
                <iframe id="pdfPreviewIframe" src="about:blank" style="width: 100%; height: 100%; border: none;"></iframe>
            </div>
        </div>
    </div>
</div>

@endsection

@section('styles')
<style>
{{-- =============================================================================
Estilos Profesionales del Módulo de Boletas de Pago
============================================================================= --}}
.payslips-page-wrapper {
    width: 100%;
}

{{-- Cabecera Ejecutiva --}}
.payslips-header-card {
    background: var(--bg-surface, #ffffff);
    border: 1px solid var(--border-color, rgba(0, 0, 0, 0.08));
    border-radius: 12px;
}

.payslips-header-icon {
    width: 48px;
    height: 48px;
    background: linear-gradient(135deg, #0284c7 0%, #2563eb 100%);
    color: #ffffff;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.35rem;
    flex-shrink: 0;
}

{{-- Tarjetas KPI --}}
.payslips-kpi-card {
    background: var(--bg-surface, #ffffff);
    border: 1px solid var(--border-color, rgba(0, 0, 0, 0.07));
    border-radius: 10px;
    transition: transform 0.15s ease, box-shadow 0.15s ease;
}

.payslips-kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 14px rgba(0, 0, 0, 0.06) !important;
}

.payslips-kpi-pill {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
    flex-shrink: 0;
}

{{-- Colores pill auxiliares --}}
.bg-emerald-subtle {
    background: rgba(16, 185, 129, 0.12) !important;
}
.text-emerald {
    color: #10b981 !important;
}

.bg-amber-subtle {
    background: rgba(245, 158, 11, 0.12) !important;
}
.text-amber {
    color: #f59e0b !important;
}

{{-- Tarjeta de Filtros --}}
.payslips-filter-card {
    background: var(--bg-surface, #ffffff);
    border: 1px solid var(--border-color, rgba(0, 0, 0, 0.08));
    border-radius: 10px;
}

.filter-label {
    display: block;
    font-size: 0.725rem;
    font-weight: 600;
    color: var(--text-secondary, #64748b);
    margin-bottom: 4px;
    letter-spacing: 0.02em;
}

.payslip-input {
    height: 38px !important;
    border-radius: 6px !important;
    font-size: 0.85rem !important;
    border: 1px solid var(--border-color, #cbd5e1) !important;
    background-color: var(--bg-surface-elevated, #ffffff) !important;
}

.payslip-input:focus {
    border-color: #0284c7 !important;
    box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.15) !important;
}

{{-- Botón Trigger del Calendario --}}
.payslip-calendar-trigger-btn {
    height: 38px;
    border-radius: 6px !important;
    font-size: 0.85rem !important;
    border: 1px solid var(--border-color, #cbd5e1) !important;
    background-color: var(--bg-surface-elevated, #ffffff) !important;
    padding: 0 12px;
    font-weight: 500;
    transition: all 0.15s ease;
}

.payslip-calendar-trigger-btn:hover,
.payslip-calendar-trigger-btn:focus {
    border-color: #0284c7 !important;
    background-color: var(--bg-surface, #ffffff) !important;
    color: var(--text-primary) !important;
}

{{-- Matriz de Meses del Calendario --}}
.calendar-months-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 6px;
}

.calendar-month-cell {
    height: 34px;
    border-radius: 6px;
    border: 1px solid transparent;
    background: transparent;
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--text-primary, #1e293b);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.12s ease;
    text-transform: capitalize;
}

.calendar-month-cell:hover {
    background: var(--bg-surface-elevated, #f1f5f9);
    color: var(--accent-cyan, #0284c7);
}

.calendar-month-cell.is-selected {
    background: #0284c7 !important;
    color: #ffffff !important;
    box-shadow: 0 2px 6px rgba(2, 132, 199, 0.35);
}

{{-- Botones de Filtro --}}
.btn-payslip-filter {
    height: 38px;
    padding: 0 16px;
    border-radius: 6px !important;
    font-weight: 600;
    font-size: 0.85rem;
}

.btn-payslip-reset {
    height: 38px;
    padding: 0 14px;
    border-radius: 6px !important;
    border: 1px solid var(--border-color, #cbd5e1);
    background: var(--bg-surface, #ffffff);
    color: var(--text-secondary, #64748b);
    font-weight: 600;
    font-size: 0.85rem;
    transition: all 0.15s ease;
}

.btn-payslip-reset:hover {
    background: var(--bg-surface-elevated, #f1f5f9);
    color: var(--text-primary, #0f172a);
    border-color: #94a3b8;
}

.btn-publish-all {
    height: 38px;
    padding: 0 16px;
    border-radius: 6px !important;
    font-weight: 600;
    font-size: 0.85rem;
}

{{-- Tarjeta y Tabla --}}
.payslips-table-card {
    background: var(--bg-surface, #ffffff);
    border: 1px solid var(--border-color, rgba(0, 0, 0, 0.08));
    border-radius: 10px;
    overflow: hidden;
}

.payslips-table thead th {
    background: var(--bg-surface-elevated, #f8fafc);
    color: var(--text-secondary, #64748b);
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    padding: 0.85rem 1rem;
    border-bottom: 1px solid var(--border-color, #e2e8f0);
}

.payslips-table tbody td {
    padding: 0.85rem 1rem;
    border-bottom: 1px solid var(--border-color, #e2e8f0);
}

.payslip-avatar-circle {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    background: linear-gradient(135deg, #e0f2fe 0%, #dbeafe 100%);
    color: #0284c7;
    font-size: 0.8rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

{{-- Botones de Acción Icon Only --}}
.btn-action-btn {
    width: 34px;
    height: 34px;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 6px;
    font-size: 0.85rem;
    transition: all 0.15s ease;
    border: 1px solid transparent;
}

.btn-action-publish {
    background: rgba(16, 185, 129, 0.1);
    color: #10b981;
    border-color: rgba(16, 185, 129, 0.25);
}
.btn-action-publish:hover {
    background: #10b981;
    color: #ffffff;
}

.btn-action-view {
    background: var(--bg-surface-elevated, #f8fafc);
    color: var(--text-secondary, #475569);
    border-color: var(--border-color, #cbd5e1);
}
.btn-action-view:hover {
    background: #0284c7;
    color: #ffffff;
    border-color: #0284c7;
}

.btn-action-download {
    background: var(--bg-surface-elevated, #f8fafc);
    color: var(--text-secondary, #475569);
    border-color: var(--border-color, #cbd5e1);
}
.btn-action-download:hover {
    background: #2563eb;
    color: #ffffff;
    border-color: #2563eb;
}

.btn-action-annul {
    background: rgba(245, 158, 11, 0.1);
    color: #d97706;
    border-color: rgba(245, 158, 11, 0.25);
}
.btn-action-annul:hover {
    background: #d97706;
    color: #ffffff;
}

.btn-action-delete {
    background: rgba(239, 68, 68, 0.1);
    color: #dc2626;
    border-color: rgba(239, 68, 68, 0.25);
}
.btn-action-delete:hover {
    background: #dc2626;
    color: #ffffff;
}

{{-- Empty State --}}
.payslips-empty-icon {
    width: 64px;
    height: 64px;
    border-radius: 50%;
    background: var(--bg-surface-elevated, #f1f5f9);
    color: var(--text-muted, #94a3b8);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.8rem;
}

{{-- Modal Icons con Espaciado Garantizado --}}
.modal-header-icon {
    width: 42px;
    height: 42px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    flex-shrink: 0;
}

.modal-warning-icon {
    width: 56px;
    height: 56px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

{{-- Interruptor Ejecutivo Moderno --}}
.payslip-executive-switch-card {
    background: var(--bg-surface-elevated, #f8fafc);
    border-color: var(--border-color, #e2e8f0) !important;
    transition: all 0.2s ease;
}

.payslip-executive-switch-card.is-active {
    background: rgba(2, 132, 199, 0.05) !important;
    border-color: #0284c7 !important;
}

.switch-icon-circle {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.15rem;
    flex-shrink: 0;
}

.custom-executive-switch {
    width: 46px !important;
    height: 24px !important;
    cursor: pointer;
    background-color: #cbd5e1 !important;
    border: none !important;
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='-4 -4 8 8'%3e%3ccircle r='3' fill='%23fff'/%3e%3c/svg%3e") !important;
    transition: background-position 0.2s ease-in-out, background-color 0.2s ease-in-out !important;
}

.custom-executive-switch:checked {
    background-color: #0284c7 !important;
    box-shadow: 0 0 8px rgba(2, 132, 199, 0.4) !important;
}

{{-- Overlay de Carga AJAX --}}
.table-loading-overlay {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(255, 255, 255, 0.7);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10;
    border-radius: 10px;
    backdrop-filter: blur(2px);
}

{{-- =============================================================================
Modo Oscuro [data-bs-theme="dark"]
============================================================================= --}}
[data-bs-theme="dark"] .payslips-header-card,
[data-bs-theme="dark"] .payslips-kpi-card,
[data-bs-theme="dark"] .payslips-filter-card,
[data-bs-theme="dark"] .payslips-table-card {
    background: #111622 !important;
    border-color: #1e2638 !important;
}

[data-bs-theme="dark"] .payslips-table thead th {
    background: #161f30 !important;
    border-bottom-color: #243048 !important;
    color: #94a3b8 !important;
}

[data-bs-theme="dark"] .payslips-table tbody td {
    border-bottom-color: #1e2638 !important;
}

[data-bs-theme="dark"] .payslip-avatar-circle {
    background: #1a273d !important;
    color: #38bdf8 !important;
}

[data-bs-theme="dark"] .btn-action-view,
[data-bs-theme="dark"] .btn-action-download {
    background: #161f30 !important;
    border-color: #243048 !important;
    color: #94a3b8 !important;
}

[data-bs-theme="dark"] .btn-payslip-reset,
[data-bs-theme="dark"] .payslip-calendar-trigger-btn {
    background: #161f30 !important;
    border-color: #243048 !important;
    color: #94a3b8 !important;
}

[data-bs-theme="dark"] .calendar-month-cell {
    color: #f8fafc !important;
}

[data-bs-theme="dark"] .calendar-month-cell:hover {
    background: #1c273e !important;
    color: #38bdf8 !important;
}

[data-bs-theme="dark"] .calendar-month-cell.is-selected {
    background: #0284c7 !important;
    color: #ffffff !important;
}

[data-bs-theme="dark"] .payslips-empty-icon {
    background: #161f30 !important;
    color: #64748b !important;
}

[data-bs-theme="dark"] .payslip-executive-switch-card {
    background: #161f30 !important;
    border-color: #243048 !important;
}

[data-bs-theme="dark"] .table-loading-overlay {
    background: rgba(17, 22, 34, 0.75) !important;
}
</style>
@endsection

@section('scripts')
<script>
    {{-- ========================================================================= --}}
    {{-- 1. Constantes y Mapeos de Meses --}}
    {{-- ========================================================================= --}}
    const monthNamesMap = {
        1: 'Enero', 2: 'Febrero', 3: 'Marzo', 4: 'Abril',
        5: 'Mayo', 6: 'Junio', 7: 'Julio', 8: 'Agosto',
        9: 'Septiembre', 10: 'Octubre', 11: 'Noviembre', 12: 'Diciembre'
    };

    let calendarBrowsingYear = parseInt(document.getElementById('filter_year').value, 10) || new Date().getFullYear();

    {{-- ========================================================================= --}}
    {{-- 2. Motor de Filtrado AJAX Interno (Sin recargar URL) --}}
    {{-- ========================================================================= --}}
    let filterDebounceTimer = null;

    async function applyPayslipFilters(page = 1) {
        const tableWrapper = document.getElementById('payslipsTableWrapper');
        {{-- Silueta en KPIs y tabla mientras llega el filtro (reemplaza el spinner) --}}
        Panel.skeleton.on('#main-content');

        const year = document.getElementById('filter_year').value;
        const month = document.getElementById('filter_month').value;
        const search = document.getElementById('filter_search').value.trim();
        const userId = document.getElementById('filter_user_id').value;
        const status = document.getElementById('filter_status').value;

        const params = new URLSearchParams({
            page: page,
            year: year,
            month: month,
            search: search,
            user_id: userId,
            status: status
        });

        try {
            const response = await fetch(`{{ route('payslips.index') }}?${params.toString()}`, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            });

            if (!response.ok) throw new Error('Error de conexión al filtrar boletas');

            const data = await response.json();
            if (data.success) {
                {{-- 1. Reemplazar tabla HTML --}}
                if (tableWrapper) {
                    tableWrapper.innerHTML = `
                        <div id="tableLoadingOverlay" class="table-loading-overlay d-none">
                            <div class="spinner-border text-primary" role="status" style="width: 2.5rem; height: 2.5rem;">
                                <span class="visually-hidden">Cargando datos...</span>
                            </div>
                        </div>
                        ${data.html_table}
                    `;
                }

                {{-- 2. Actualizar Métricas KPI reactivas --}}
                const s = data.summary;
                if (s) {
                    document.getElementById('kpi_total_emitted').textContent = s.total_emitted;
                    document.getElementById('kpi_period_hint').textContent = `Periodo ${String(data.current_month).padStart(2, '0')}/${data.current_year}`;
                    document.getElementById('kpi_published').textContent = s.published;
                    document.getElementById('kpi_pub_badge').textContent = `${s.pub_percent}% emitidas`;
                    document.getElementById('kpi_read_count').textContent = s.read_count;
                    document.getElementById('kpi_read_percent').textContent = `(${s.read_percent}% de publicadas)`;
                    document.getElementById('kpi_total_payroll').textContent = s.total_payroll;

                    {{-- Header Badges --}}
                    const monthLabel = monthNamesMap[data.current_month] || `Mes ${data.current_month}`;
                    document.getElementById('headerPeriodText').textContent = `${monthLabel} ${data.current_year}`;
                    
                    const draftsBadge = document.getElementById('headerDraftsBadge');
                    const draftsText = document.getElementById('headerDraftsText');
                    if (draftsBadge && draftsText) {
                        draftsText.textContent = s.drafts;
                        if (s.drafts > 0) {
                            draftsBadge.classList.remove('d-none');
                        } else {
                            draftsBadge.classList.add('d-none');
                        }
                    }

                    {{-- Botón Publicar Todas --}}
                    const publishAllBtn = document.getElementById('btnPublishAllTrigger');
                    const publishBadge = document.getElementById('publishAllCountBadge');
                    const bulkYearInput = document.getElementById('bulkPublishYearInput');
                    const bulkMonthInput = document.getElementById('bulkPublishMonthInput');
                    const confirmCount = document.getElementById('confirmPublishCountDisplay');
                    const confirmPeriod = document.getElementById('confirmPublishPeriodDisplay');

                    if (bulkYearInput) bulkYearInput.value = data.current_year;
                    if (bulkMonthInput) bulkMonthInput.value = data.current_month;
                    if (confirmCount) confirmCount.textContent = s.drafts;
                    if (confirmPeriod) confirmPeriod.textContent = `${monthLabel} ${data.current_year}`;

                    if (publishAllBtn) {
                        if (s.drafts > 0) {
                            publishAllBtn.removeAttribute('disabled');
                            publishAllBtn.setAttribute('data-bs-toggle', 'modal');
                            publishAllBtn.setAttribute('data-bs-target', '#confirmPublishAllModal');
                            publishBadge?.classList.remove('d-none');
                            if (publishBadge) publishBadge.textContent = s.drafts;
                        } else {
                            publishAllBtn.setAttribute('disabled', 'disabled');
                            publishAllBtn.removeAttribute('data-bs-toggle');
                            publishAllBtn.removeAttribute('data-bs-target');
                            publishBadge?.classList.add('d-none');
                        }
                    }
                }
            }
        } catch (err) {
            console.error('Error in applyPayslipFilters:', err);
        } finally {
            Panel.skeleton.off('#main-content');
        }
    }

    {{-- Interceptar clics en la paginación dentro de la tabla --}}
    document.addEventListener('click', function (e) {
        const pageLink = e.target.closest('.payslip-ajax-pagination a.page-link');
        if (pageLink) {
            e.preventDefault();
            const href = pageLink.getAttribute('href');
            if (href) {
                const url = new URL(href, window.location.origin);
                const pageNum = url.searchParams.get('page') || 1;
                applyPayslipFilters(pageNum);
            }
        }
    });

    {{-- Auto-filtro al escribir en el buscador predictivo (debounced) --}}
    document.getElementById('filter_search')?.addEventListener('input', function () {
        clearTimeout(filterDebounceTimer);
        filterDebounceTimer = setTimeout(() => {
            applyPayslipFilters(1);
        }, 320);
    });

    {{-- Auto-filtro al cambiar selects --}}
    document.getElementById('filter_user_id')?.addEventListener('change', () => applyPayslipFilters(1));
    document.getElementById('filter_status')?.addEventListener('change', () => applyPayslipFilters(1));

    {{-- Resetear filtros --}}
    function resetPayslipFilters() {
        document.getElementById('filter_search').value = '';
        document.getElementById('filter_user_id').value = '';
        document.getElementById('filter_status').value = '';
        
        const now = new Date();
        const curY = now.getFullYear();
        const curM = now.getMonth() + 1;
        
        setCalendarPeriod(curY, curM);
        applyPayslipFilters(1);
    }

    {{-- ========================================================================= --}}
    {{-- 3. Lógica del Calendario de Periodos --}}
    {{-- ========================================================================= --}}
    function setCalendarPeriod(year, month) {
        document.getElementById('filter_year').value = year;
        document.getElementById('filter_month').value = month;
        calendarBrowsingYear = year;

        const mName = monthNamesMap[month] || `Mes ${month}`;
        document.getElementById('selectedPeriodDisplay').textContent = `${mName} ${year}`;
        document.getElementById('calActiveYearDisplay').textContent = year;

        {{-- Actualizar celda seleccionada --}}
        document.querySelectorAll('.calendar-month-cell').forEach(cell => {
            const m = parseInt(cell.dataset.month, 10);
            if (m === parseInt(month, 10) && calendarBrowsingYear === parseInt(year, 10)) {
                cell.classList.add('is-selected');
            } else {
                cell.classList.remove('is-selected');
            }
        });
    }

    {{-- Navegar años en el calendario --}}
    document.getElementById('btnCalPrevYear')?.addEventListener('click', function () {
        calendarBrowsingYear--;
        document.getElementById('calActiveYearDisplay').textContent = calendarBrowsingYear;
        highlightActiveCalendarMonth();
    });

    document.getElementById('btnCalNextYear')?.addEventListener('click', function () {
        calendarBrowsingYear++;
        document.getElementById('calActiveYearDisplay').textContent = calendarBrowsingYear;
        highlightActiveCalendarMonth();
    });

    function highlightActiveCalendarMonth() {
        const curY = parseInt(document.getElementById('filter_year').value, 10);
        const curM = parseInt(document.getElementById('filter_month').value, 10);
        document.querySelectorAll('.calendar-month-cell').forEach(cell => {
            const m = parseInt(cell.dataset.month, 10);
            if (m === curM && calendarBrowsingYear === curY) {
                cell.classList.add('is-selected');
            } else {
                cell.classList.remove('is-selected');
            }
        });
    }

    {{-- Clic en un mes del calendario --}}
    document.querySelectorAll('.calendar-month-cell').forEach(cell => {
        cell.addEventListener('click', function () {
            const m = parseInt(this.dataset.month, 10);
            setCalendarPeriod(calendarBrowsingYear, m);

            {{-- Cerrar el popover del calendario --}}
            const dropdownEl = document.getElementById('payslipPeriodDropdown');
            const dropdown = bootstrap.Dropdown.getInstance(document.getElementById('periodPickerBtn'));
            if (dropdown) dropdown.hide();

            {{-- Filtrar inmediatamente por AJAX --}}
            applyPayslipFilters(1);
        });
    });

    {{-- Clic en accesos rápidos del calendario --}}
    document.querySelectorAll('.calendar-preset-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            const y = parseInt(this.dataset.periodYear, 10);
            const m = parseInt(this.dataset.periodMonth, 10);
            if (y && m) {
                setCalendarPeriod(y, m);
                const dropdown = bootstrap.Dropdown.getInstance(document.getElementById('periodPickerBtn'));
                if (dropdown) dropdown.hide();
                applyPayslipFilters(1);
            }
        });
    });

    document.getElementById('btnApplyCurrentMonth')?.addEventListener('click', function () {
        const now = new Date();
        setCalendarPeriod(now.getFullYear(), now.getMonth() + 1);
        const dropdown = bootstrap.Dropdown.getInstance(document.getElementById('periodPickerBtn'));
        if (dropdown) dropdown.hide();
        applyPayslipFilters(1);
    });

    {{-- ========================================================================= --}}
    {{-- 4. Modales y Switches Reactivos --}}
    {{-- ========================================================================= --}}
    {{-- Cálculo reactivo en Modal de Subida Individual (Neto = Bruto - Descuentos) --}}
    const grossInput = document.getElementById('modal_grossSalary');
    const dedInput = document.getElementById('modal_deductions');
    const netInput = document.getElementById('modal_netSalary');

    const updateNetSalary = () => {
        if (!grossInput || !dedInput || !netInput) return;
        const g = parseFloat(grossInput.value) || 0;
        const d = parseFloat(dedInput.value) || 0;
        netInput.value = Math.max(0, g - d).toFixed(2);
    };

    grossInput?.addEventListener('input', updateNetSalary);
    dedInput?.addEventListener('input', updateNetSalary);

    {{-- Feedback visual en el switch ejecutivo --}}
    const singleSwitch = document.getElementById('modal_publish_now');
    const singleSwitchBox = document.getElementById('singlePublishSwitchBox');
    if (singleSwitch && singleSwitchBox) {
        singleSwitch.addEventListener('change', function () {
            if (this.checked) {
                singleSwitchBox.classList.add('is-active');
            } else {
                singleSwitchBox.classList.remove('is-active');
            }
        });
        if (singleSwitch.checked) singleSwitchBox.classList.add('is-active');
    }

    const bulkSwitch = document.getElementById('bulk_publish_now');
    const bulkSwitchBox = document.getElementById('bulkPublishSwitchBox');
    if (bulkSwitch && bulkSwitchBox) {
        bulkSwitch.addEventListener('change', function () {
            if (this.checked) {
                bulkSwitchBox.classList.add('is-active');
            } else {
                bulkSwitchBox.classList.remove('is-active');
            }
        });
    }

    {{-- Contador de archivos en Carga Masiva --}}
    const bulkFilesInput = document.getElementById('bulkFilesInput');
    const bulkFilesNote = document.getElementById('bulkFilesCountNote');
    if (bulkFilesInput && bulkFilesNote) {
        bulkFilesInput.addEventListener('change', function () {
            const count = this.files ? this.files.length : 0;
            if (count === 0) {
                bulkFilesNote.innerHTML = '<i class="bi bi-paperclip me-1"></i>Ningún archivo seleccionado.';
            } else if (count === 1) {
                bulkFilesNote.innerHTML = `<i class="bi bi-file-earmark-check text-success me-1"></i><strong>1 archivo</strong> listo para subir.`;
            } else {
                bulkFilesNote.innerHTML = `<i class="bi bi-files text-success me-1"></i><strong>${count} archivos</strong> listos para procesar.`;
            }
        });
    }

    {{-- ========================================================================= --}}
    {{-- 5. Modales de Confirmación y Visor PDF --}}
    {{-- ========================================================================= --}}
    let pendingPublishId = null;
    window.openConfirmPublishModal = function (id, employeeName, title) {
        pendingPublishId = id;
        document.getElementById('publishEmployeeName').textContent = employeeName;
        document.getElementById('publishPayslipTitle').textContent = title;
        const modal = new bootstrap.Modal(document.getElementById('confirmPublishModal'));
        modal.show();
    };

    document.getElementById('btnConfirmPublishSubmit')?.addEventListener('click', function () {
        if (!pendingPublishId) return;
        const form = document.getElementById(`form-publish-${pendingPublishId}`);
        if (form) form.submit();
    });

    let pendingAnnulId = null;
    window.openConfirmAnnulModal = function (id, employeeName) {
        pendingAnnulId = id;
        document.getElementById('annulEmployeeName').textContent = employeeName;
        const modal = new bootstrap.Modal(document.getElementById('confirmAnnulModal'));
        modal.show();
    };

    document.getElementById('btnConfirmAnnulSubmit')?.addEventListener('click', function () {
        if (!pendingAnnulId) return;
        const form = document.getElementById(`form-annul-${pendingAnnulId}`);
        if (form) form.submit();
    });

    let pendingDeleteId = null;
    window.openConfirmDeleteModal = function (id, employeeName) {
        pendingDeleteId = id;
        document.getElementById('deleteEmployeeName').textContent = employeeName;
        const modal = new bootstrap.Modal(document.getElementById('confirmDeleteModal'));
        modal.show();
    };

    document.getElementById('btnConfirmDeleteSubmit')?.addEventListener('click', function () {
        if (!pendingDeleteId) return;
        const form = document.getElementById(`form-delete-${pendingDeleteId}`);
        if (form) form.submit();
    });

    {{-- Visor Inline en Modal --}}
    window.openPdfPreviewModal = function (viewUrl, downloadUrl, title, employeeName) {
        document.getElementById('previewPdfTitle').textContent = title || 'Boleta de Pago';
        document.getElementById('previewPdfSubtitle').textContent = employeeName ? `Colaborador: ${employeeName}` : 'Documento oficial';
        document.getElementById('previewPdfDownloadBtn').setAttribute('href', downloadUrl);
        document.getElementById('previewPdfNewTabBtn').setAttribute('href', viewUrl);
        
        const iframe = document.getElementById('pdfPreviewIframe');
        iframe.src = viewUrl;

        const modalEl = document.getElementById('previewPdfModal');
        const modal = new bootstrap.Modal(modalEl);
        modal.show();

        modalEl.addEventListener('hidden.bs.modal', function onHidden() {
            iframe.src = 'about:blank';
            modalEl.removeEventListener('hidden.bs.modal', onHidden);
        });
    };
</script>
@endsection
