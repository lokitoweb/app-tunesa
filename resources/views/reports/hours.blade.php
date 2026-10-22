@extends('layouts.app')

@section('title', 'Reporte de horas y faltas')

@section('content')
<div class="reports-page-wrapper">
    <!-- =========================================================================
         1. CABECERA EJECUTIVA DEL MÓDULO
         ========================================================================= -->
    <div class="reports-header-card p-4 mb-4 shadow-sm">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3">
            <div class="d-flex align-items-center gap-3">
                <div class="reports-header-icon shadow-sm" id="reportsHeaderIcon">
                    <i class="bi bi-hourglass-split"></i>
                </div>
                <div>
                    <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                        <h1 class="h4 fw-bold mb-0 text-body" id="reportsHeaderTitle">Reporte de horas y faltas</h1>
                        <span class="badge rounded-pill bg-info-subtle text-info border border-info-subtle px-2 py-1 small" id="reportsHeaderBadge">
                            <i class="bi bi-hourglass-split me-1"></i>Liquidación de Tiempo
                        </span>
                    </div>
                    <p class="text-body-secondary small mb-0" id="reportsHeaderSubtitle">
                        Horas efectivas, tardanzas, horas extra diurnas y nocturnas (22:00–06:00) y faltas por colaborador.
                    </p>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2 w-100 w-md-auto justify-content-md-end" id="reportsExportActions">
                @can('reports.export')
                    <a id="reportExportExcelBtn" href="{{ route('reports.hours.excel', $filters->toQuery()) }}" class="btn btn-outline-success px-3 py-2 fw-semibold d-inline-flex align-items-center">
                        <i class="bi bi-file-earmark-spreadsheet me-2"></i>Excel (.xlsx)
                    </a>
                    <a id="reportExportPdfBtn" href="{{ route('reports.hours.pdf', $filters->toQuery()) }}" target="_blank" rel="noopener" class="btn btn-outline-danger px-3 py-2 fw-semibold d-inline-flex align-items-center">
                        <i class="bi bi-file-earmark-pdf me-2"></i>Exportar PDF
                    </a>
                @endcan
            </div>
        </div>
    </div>

    <!-- =========================================================================
         2. TABS MODERNOS (AJAX SPA)
         ========================================================================= -->
    @include('reports.partials.tabs', ['activeTab' => 'hours'])

    <!-- =========================================================================
         3. CONTENIDO DINÁMICO DE PESTAÑAS
         ========================================================================= -->
    <div id="reportsTabContent" class="reports-tab-transition">
        @include('reports.partials.hours-content', ['rows' => $rows, 'totals' => $totals, 'filters' => $filters, 'branches' => $branches, 'employees' => $employees])
    </div>
</div>
@endsection

@section('scripts')
@include('reports.partials.scripts', ['initialTab' => 'hours'])
@endsection
