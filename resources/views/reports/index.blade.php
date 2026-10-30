@extends('layouts.app')

@section('title', 'Reportes de Asistencia')

@section('content')
<div class="reports-page-wrapper">
    <header class="page-head">
        <div class="page-head-text">
            <h1 class="page-title" id="reportsHeaderTitle">Reportes de asistencia y jornadas</h1>
            <p class="page-subtitle" id="reportsHeaderSubtitle">Cómputo de horas trabajadas, sobretiempos, tardanzas, tolerancias y cumplimiento de geocercas.</p>
        </div>
        <div class="page-actions" id="reportsExportActions">
            @can('reports.export')
                <a id="reportExportExcelBtn" href="{{ route('reports.export.excel', request()->all()) }}" class="btn btn-light border">
                    <i class="bi bi-file-earmark-spreadsheet me-1"></i>Excel (.csv)
                </a>
                <a id="reportExportPdfBtn" href="{{ route('reports.export.pdf', request()->all()) }}" target="_blank" rel="noopener" class="btn btn-light border">
                    <i class="bi bi-printer me-1"></i>Imprimir PDF
                </a>
            @endcan
        </div>
    </header>

    @include('reports.partials.tabs', ['activeTab' => 'marcaciones'])

    <div id="reportsTabContent" class="reports-tab-transition">
        @include('reports.partials.marcaciones-content')
    </div>
</div>
@endsection

@section('scripts')
@include('reports.partials.scripts', ['initialTab' => 'marcaciones'])
@endsection
