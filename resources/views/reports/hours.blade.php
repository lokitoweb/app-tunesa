@extends('layouts.app')

@section('title', 'Reporte de horas y faltas')

@section('content')
<div class="reports-page-wrapper">
    <header class="page-head">
        <div class="page-head-text">
            <h1 class="page-title" id="reportsHeaderTitle">Reporte de horas y faltas</h1>
            <p class="page-subtitle" id="reportsHeaderSubtitle">Horas efectivas, tardanzas, horas extra diurnas y nocturnas (22:00–06:00) y faltas por colaborador.</p>
        </div>
        <div class="page-actions" id="reportsExportActions">
            @can('reports.export')
                <a id="reportExportExcelBtn" href="{{ route('reports.hours.excel', $filters->toQuery()) }}" class="btn btn-light border">
                    <i class="bi bi-file-earmark-spreadsheet me-1"></i>Excel (.xlsx)
                </a>
                <a id="reportExportPdfBtn" href="{{ route('reports.hours.pdf', $filters->toQuery()) }}" target="_blank" rel="noopener" class="btn btn-light border">
                    <i class="bi bi-file-earmark-pdf me-1"></i>Exportar PDF
                </a>
            @endcan
        </div>
    </header>

    @include('reports.partials.tabs', ['activeTab' => 'hours'])

    <div id="reportsTabContent" class="reports-tab-transition">
        @include('reports.partials.hours-content', ['rows' => $rows, 'totals' => $totals, 'filters' => $filters, 'branches' => $branches, 'employees' => $employees])
    </div>
</div>
@endsection

@section('scripts')
@include('reports.partials.scripts', ['initialTab' => 'hours'])
@endsection
