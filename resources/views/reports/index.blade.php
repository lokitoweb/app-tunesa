@extends('layouts.app')

@section('title', 'Reportes de Asistencia')

@section('content')
<div class="reports-page-wrapper">
    {{-- =========================================================================
         1. CABECERA EJECUTIVA DEL MÓDULO
         ========================================================================= --}}
    <div class="reports-header-card p-4 mb-4 shadow-sm">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3">
            <div class="d-flex align-items-center gap-3">
                <div class="reports-header-icon shadow-sm" id="reportsHeaderIcon">
                    <i class="bi bi-bar-chart-line-fill"></i>
                </div>
                <div>
                    <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                        <h1 class="h4 fw-bold mb-0 text-body" id="reportsHeaderTitle">Reportes de Asistencia y Jornadas</h1>
                        <span class="badge rounded-pill bg-primary-subtle text-primary border border-primary-subtle px-2 py-1 small" id="reportsHeaderBadge">
                            <i class="bi bi-graph-up-arrow me-1"></i>Analítica Operativa
                        </span>
                    </div>
                    <p class="text-body-secondary small mb-0" id="reportsHeaderSubtitle">
                        Cómputo de horas trabajadas, sobretiempos, tardanzas, tolerancias y cumplimiento de geocercas.
                    </p>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2 w-100 w-md-auto justify-content-md-end" id="reportsExportActions">
                @can('reports.export')
                    <a id="reportExportExcelBtn" href="{{ route('reports.export.excel', request()->all()) }}" class="btn btn-outline-success px-3 py-2 fw-semibold d-inline-flex align-items-center">
                        <i class="bi bi-file-earmark-spreadsheet-fill me-2"></i>Excel (.csv)
                    </a>
                    <a id="reportExportPdfBtn" href="{{ route('reports.export.pdf', request()->all()) }}" target="_blank" rel="noopener" class="btn btn-outline-primary px-3 py-2 fw-semibold d-inline-flex align-items-center">
                        <i class="bi bi-printer-fill me-2"></i>Imprimir PDF
                    </a>
                @endcan
            </div>
        </div>
    </div>

    {{-- =========================================================================
         2. TABS MODERNOS (AJAX SPA)
         ========================================================================= --}}
    @include('reports.partials.tabs', ['activeTab' => 'marcaciones'])

    {{-- =========================================================================
         3. CONTENIDO DINÁMICO DE PESTAÑAS
         ========================================================================= --}}
    <div id="reportsTabContent" class="reports-tab-transition">
        @include('reports.partials.marcaciones-content')
    </div>
</div>
@endsection

@section('scripts')
@include('reports.partials.scripts', ['initialTab' => 'marcaciones'])
@endsection
