@extends('layouts.app')

@section('title', 'Asistencia del día')

@section('styles')
<style>
{{-- ==========================================================================
   ASISTENCIA DEL DÍA — DISEÑO EJECUTIVO ADAPTATIVO (MODO CLARO & OSCURO)
   ========================================================================== --}}
.cal-day-shell {
    --cal-bg: var(--bg-surface, #ffffff);
    --cal-bg-subtle: var(--bg-surface-elevated, #f8fafc);
    --cal-bg-elevated: var(--bg-surface-elevated, #f1f5f9);
    --cal-border: var(--border-color, #e2e8f0);
    --cal-border-light: var(--border-color-light, #cbd5e1);
    --cal-text-main: var(--text-primary, #0f172a);
    --cal-text-muted: var(--text-secondary, #475569);
    --cal-text-subtle: var(--text-muted, #64748b);
    --cal-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);

    display: flex;
    flex-direction: column;
    gap: 1.25rem;
    max-width: 100%;
}

[data-bs-theme="dark"] .cal-day-shell {
    --cal-bg: #111622;
    --cal-bg-subtle: #161d2d;
    --cal-bg-elevated: #1a2234;
    --cal-border: #243048;
    --cal-border-light: #2d3d5c;
    --cal-text-main: #f8fafc;
    --cal-text-muted: #94a3b8;
    --cal-text-subtle: #64748b;
    --cal-shadow: 0 4px 16px rgba(0, 0, 0, 0.35);
}

{{-- 1. Header Principal --}}
.cal-day-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 1rem;
    margin-bottom: 0.25rem;
}
.cal-day-header h1 {
    font-size: 1.65rem;
    font-weight: 800;
    color: var(--cal-text-main) !important;
    letter-spacing: -0.02em;
    line-height: 1.2;
    margin-bottom: 0.25rem;
}
.cal-day-header p {
    color: var(--cal-text-muted) !important;
    font-size: 0.875rem;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.cal-btn-back-month {
    height: 38px;
    padding: 0 1.15rem;
    border-radius: 8px;
    border: 1px solid var(--cal-border-light);
    background: var(--cal-bg-subtle);
    color: var(--cal-text-main);
    font-size: 0.825rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    text-decoration: none;
    transition: all 0.15s ease;
}
.cal-btn-back-month:hover {
    background: var(--cal-bg-elevated);
    border-color: #2563eb;
    color: #2563eb;
    transform: translateY(-1px);
}
[data-bs-theme="dark"] .cal-btn-back-month:hover {
    border-color: #60a5fa;
    color: #60a5fa;
}

{{-- 2. Panel de Filtros Moderno --}}
.cal-day-panel {
    background: var(--cal-bg);
    border: 1px solid var(--cal-border);
    border-radius: 10px;
    padding: 1.15rem 1.25rem;
    box-shadow: var(--cal-shadow);
}
.cal-day-form-label {
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--cal-text-subtle);
    margin-bottom: 0.4rem;
    display: block;
}

.cal-day-nav-group {
    display: flex;
    align-items: center;
    border: 1px solid var(--cal-border-light);
    border-radius: 8px;
    overflow: hidden;
    background: var(--cal-bg-subtle);
}
.cal-day-nav-btn {
    width: 36px;
    height: 38px;
    background: var(--cal-bg-elevated);
    color: var(--cal-text-main);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.12s ease;
    border: none;
    flex-shrink: 0;
    outline: none !important;
}
.cal-day-nav-btn:hover {
    background: #2563eb;
    color: #ffffff;
}
.cal-day-date-input {
    height: 38px;
    border: none;
    background: transparent;
    color: var(--cal-text-main);
    font-size: 0.85rem;
    font-weight: 600;
    text-align: center;
    padding: 0 0.5rem;
    width: 100%;
    outline: none;
}
[data-bs-theme="dark"] .cal-day-date-input::-webkit-calendar-picker-indicator {
    filter: invert(1);
}

.cal-day-select {
    height: 38px;
    border-radius: 8px;
    border: 1px solid var(--cal-border-light);
    font-size: 0.825rem;
    padding-left: 0.85rem;
    padding-right: 2.25rem !important;
    color: var(--cal-text-main);
    background-color: var(--cal-bg-subtle);
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' fill='%2364748b'%3E%3Cpath fill-rule='evenodd' d='M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 0.75rem center;
    background-size: 13px 11px;
    appearance: none;
    -webkit-appearance: none;
    -moz-appearance: none;
    font-weight: 500;
    width: 100%;
    cursor: pointer;
    outline: none !important;
    transition: all 0.15s ease;
}
[data-bs-theme="dark"] .cal-day-select {
    background-color: var(--cal-bg-subtle);
    border-color: var(--cal-border-light);
    color: var(--cal-text-main);
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' fill='%2394a3b8'%3E%3Cpath fill-rule='evenodd' d='M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z'/%3E%3C/svg%3E");
}
.cal-day-select:focus,
.cal-day-select:focus-visible {
    outline: none !important;
    border-color: #2563eb !important;
    background-color: var(--cal-bg) !important;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.22) !important;
}
[data-bs-theme="dark"] .cal-day-select:focus,
[data-bs-theme="dark"] .cal-day-select:focus-visible {
    border-color: #3b82f6 !important;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.3) !important;
}
[data-bs-theme="dark"] .cal-day-select option {
    background: #161d2d;
    color: #f8fafc;
}

.cal-day-search-box {
    position: relative;
    width: 100%;
}
.cal-day-search-box i {
    position: absolute;
    left: 11px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--cal-text-subtle);
    font-size: 0.85rem;
    pointer-events: none;
}
.cal-day-search-input {
    height: 38px;
    padding-left: 34px;
    padding-right: 12px;
    border-radius: 8px;
    border: 1px solid var(--cal-border-light);
    font-size: 0.825rem;
    color: var(--cal-text-main);
    width: 100%;
    background: var(--cal-bg-subtle);
    font-weight: 500;
    transition: all 0.12s ease;
    outline: none !important;
}
.cal-day-search-input:focus,
.cal-day-search-input:focus-visible {
    outline: none !important;
    border-color: #2563eb !important;
    background: var(--cal-bg) !important;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.22) !important;
}
[data-bs-theme="dark"] .cal-day-search-input:focus,
[data-bs-theme="dark"] .cal-day-search-input:focus-visible {
    border-color: #3b82f6 !important;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.3) !important;
}

.cal-day-btn-apply {
    height: 38px;
    padding: 0 1.25rem;
    border-radius: 8px;
    background: #2563eb;
    color: #ffffff;
    border: none;
    font-size: 0.825rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    cursor: pointer;
    box-shadow: 0 1px 3px rgba(37, 99, 235, 0.25);
    transition: all 0.12s ease;
    width: 100%;
    outline: none !important;
}
.cal-day-btn-apply:hover {
    background: #1d4ed8;
    transform: translateY(-1px);
}
.cal-day-btn-apply:focus,
.cal-day-btn-apply:focus-visible {
    outline: none !important;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.25) !important;
}

{{-- 3. Contenedor Dinámico Asíncrono con Overlay Localizado --}}
#cal-day-dynamic-container {
    position: relative;
    min-height: 350px;
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
}

#cal-day-loading-overlay {
    position: absolute;
    inset: 0;
    background: rgba(255, 255, 255, 0.78);
    backdrop-filter: blur(2px);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 50;
    border-radius: 8px;
}
[data-bs-theme="dark"] #cal-day-loading-overlay {
    background: rgba(17, 22, 34, 0.82);
}

{{-- 4. Deck de Tarjetas KPI --}}
.cal-day-kpis-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    gap: 0.9rem;
}
.cal-day-kpi-card {
    background: var(--cal-bg);
    border: 1px solid var(--cal-border);
    border-radius: 10px;
    padding: 1rem 1.15rem;
    display: flex;
    align-items: center;
    gap: 1rem;
    box-shadow: var(--cal-shadow);
    transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
}
.cal-day-kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.08);
    border-color: var(--cal-border-light);
}
.cal-day-kpi-icon {
    width: 44px;
    height: 44px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    flex-shrink: 0;
}
.cal-day-kpi-value {
    font-size: 1.55rem;
    font-weight: 800;
    line-height: 1.1;
    color: var(--cal-text-main);
    letter-spacing: -0.02em;
    font-variant-numeric: tabular-nums;
}
.cal-day-kpi-label {
    font-size: 0.725rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    font-weight: 700;
    color: var(--cal-text-subtle);
    margin-top: 3px;
}

{{-- 5. Banner de Fecha --}}
.cal-day-date-banner {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    padding: 0.75rem 1.15rem;
    background: var(--cal-bg);
    border: 1px solid var(--cal-border);
    border-radius: 8px;
    font-size: 0.95rem;
    font-weight: 800;
    color: var(--cal-text-main);
    box-shadow: var(--cal-shadow);
}
.cal-day-date-banner i {
    font-size: 1.15rem;
    color: #2563eb;
}

{{-- 6. Tarjetas de Turno & Tablas --}}
.cal-shift-card {
    background: var(--cal-bg);
    border: 1px solid var(--cal-border);
    border-radius: 8px;
    overflow: hidden;
    box-shadow: var(--cal-shadow);
    margin-bottom: 0.5rem;
}
.cal-shift-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.9rem 1.25rem;
    background: var(--cal-bg-elevated);
    border-bottom: 1px solid var(--cal-border);
    flex-wrap: wrap;
    gap: 0.75rem;
}
.cal-shift-title-group {
    display: flex;
    align-items: center;
    gap: 0.65rem;
}
.cal-shift-icon-box {
    width: 32px;
    height: 32px;
    border-radius: 6px;
    background: rgba(37, 99, 235, 0.1);
    color: #2563eb;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.95rem;
    flex-shrink: 0;
}
[data-bs-theme="dark"] .cal-shift-icon-box {
    background: rgba(37, 99, 235, 0.2);
    color: #60a5fa;
}
.cal-shift-title {
    font-size: 0.975rem;
    font-weight: 800;
    color: var(--cal-text-main);
    margin: 0;
}
.cal-shift-time-badge {
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--cal-text-muted);
}

{{-- Píldora de Conteo: Esquinas limpias (6px), holgura generosa y espaciado claro entre icono y texto --}}
.cal-shift-count-badge {
    font-size: 0.8rem;
    padding: 6px 14px;
    border-radius: 6px;
    background: var(--cal-bg);
    border: 1px solid var(--cal-border-light);
    color: var(--cal-text-main);
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-weight: 500;
}
.cal-shift-count-badge i {
    font-size: 0.95rem;
    margin-right: 2px;
}
.cal-shift-count-badge strong {
    margin-right: 4px;
    color: #16a34a;
}
[data-bs-theme="dark"] .cal-shift-count-badge strong {
    color: #34d399;
}

{{-- Tabla de Colaboradores --}}
.cal-shift-table {
    width: 100%;
    border-collapse: collapse;
    margin: 0;
    font-size: 0.8125rem;
}
.cal-shift-table thead th {
    background: var(--cal-bg-subtle);
    border-bottom: 2px solid var(--cal-border);
    color: var(--cal-text-subtle);
    font-size: 0.7rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    padding: 0.85rem 1.15rem;
    white-space: nowrap;
}
.cal-shift-table tbody td {
    padding: 0.95rem 1.15rem;
    vertical-align: middle;
    border-bottom: 1px solid var(--cal-border);
    color: var(--cal-text-main);
}
.cal-shift-table tbody tr:hover {
    background: var(--cal-bg-subtle);
}
.cal-shift-table tbody tr:last-child td {
    border-bottom: none;
}

{{-- Colaborador celda --}}
.cal-day-colab-cell {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}
.cal-avatar-img {
    width: 38px;
    height: 38px;
    border-radius: 8px;
    object-fit: cover;
    border: 1px solid var(--cal-border);
    flex-shrink: 0;
}
.cal-avatar-circle {
    width: 38px;
    height: 38px;
    border-radius: 8px;
    font-weight: 800;
    font-size: 0.8rem;
    background: rgba(37, 99, 235, 0.1);
    color: #2563eb;
    border: 1px solid rgba(37, 99, 235, 0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
[data-bs-theme="dark"] .cal-avatar-circle {
    background: rgba(37, 99, 235, 0.2);
    color: #60a5fa;
    border-color: rgba(96, 165, 250, 0.3);
}

.cal-day-colab-name {
    font-size: 0.875rem;
    font-weight: 700;
    color: var(--cal-text-main);
    text-decoration: none;
    line-height: 1.25;
}
.cal-day-colab-name:hover {
    color: #2563eb;
}
.cal-day-colab-sub {
    font-size: 0.75rem;
    color: var(--cal-text-muted);
    margin-top: 4px;
    display: flex;
    align-items: center;
    gap: 6px;
}

{{-- Píldoras de Estado: Esquinas menos redondeadas (6px), holgura generosa y espaciado de icono --}}
.cal-day-badge {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    font-size: 0.7875rem;
    font-weight: 600;
    padding: 6px 13px;
    border-radius: 6px;
    line-height: 1.2;
    border: 1px solid transparent;
}
.cal-day-badge i {
    font-size: 0.85rem;
    margin-right: 3px;
}

.cal-day-badge.is-marked,
.cal-day-badge.is-present {
    background: #dcfce7;
    border-color: #86efac;
    color: #166534;
}
[data-bs-theme="dark"] .cal-day-badge.is-marked,
[data-bs-theme="dark"] .cal-day-badge.is-present {
    background: rgba(16, 185, 129, 0.16);
    border-color: rgba(16, 185, 129, 0.35);
    color: #34d399;
}

.cal-day-badge.is-late {
    background: #fef3c7;
    border-color: #fcd34d;
    color: #92400e;
}
[data-bs-theme="dark"] .cal-day-badge.is-late {
    background: rgba(245, 158, 11, 0.16);
    border-color: rgba(245, 158, 11, 0.35);
    color: #fbbf24;
}

.cal-day-badge.is-missing {
    background: #fee2e2;
    border-color: #fca5a5;
    color: #991b1b;
}
[data-bs-theme="dark"] .cal-day-badge.is-missing {
    background: rgba(239, 68, 68, 0.16);
    border-color: rgba(239, 68, 68, 0.35);
    color: #f87171;
}

.cal-day-badge.is-upcoming {
    background: #f1f5f9;
    border-color: #cbd5e1;
    color: #475569;
}
[data-bs-theme="dark"] .cal-day-badge.is-upcoming {
    background: rgba(100, 116, 139, 0.16);
    border-color: rgba(100, 116, 139, 0.35);
    color: #94a3b8;
}

.cal-day-badge.is-excused {
    background: #dbeafe;
    border-color: #93c5fd;
    color: #1e40af;
}
[data-bs-theme="dark"] .cal-day-badge.is-excused {
    background: rgba(59, 130, 246, 0.16);
    border-color: rgba(59, 130, 246, 0.35);
    color: #60a5fa;
}

{{-- Selfie Thumb & Acciones --}}
.cal-selfie-thumb {
    width: 36px;
    height: 36px;
    border-radius: 6px;
    object-fit: cover;
    border: 1.5px solid var(--cal-border);
    transition: transform 0.12s ease;
    cursor: pointer;
}
.cal-selfie-thumb:hover {
    transform: scale(1.15);
    border-color: #2563eb;
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25);
}
.cal-selfie-btn {
    border: none;
    background: none;
    padding: 0;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.cal-selfie-empty {
    width: 36px;
    height: 36px;
    border-radius: 6px;
    background: var(--cal-bg-elevated);
    border: 1px dashed var(--cal-border-light);
    color: var(--cal-text-subtle);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.95rem;
}

.cal-btn-edit-action {
    width: 32px;
    height: 32px;
    border-radius: 6px;
    background: var(--cal-bg-elevated);
    border: 1px solid var(--cal-border);
    color: var(--cal-text-muted);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    transition: all 0.12s ease;
}
.cal-btn-edit-action:hover {
    background: #2563eb;
    border-color: #2563eb;
    color: #ffffff;
}

.cal-empty-state-card {
    background: var(--cal-bg);
    border: 1px solid var(--cal-border);
    border-radius: 8px;
    padding: 3rem 1.5rem;
    text-align: center;
    color: var(--cal-text-muted);
    box-shadow: var(--cal-shadow);
}
.cal-empty-state-card i {
    font-size: 2.5rem;
    color: var(--cal-text-subtle);
    display: block;
    margin-bottom: 0.75rem;
}
</style>
@endsection

@section('content')
@php
    $dateLabel = \Carbon\Carbon::parse($filters->day)->locale('es')->isoFormat('dddd D [de] MMMM [de] YYYY');
    $outcomes = [
        'marked'   => ['Marcó', 'is-marked'],
        'upcoming' => ['Por llegar', 'is-upcoming'],
        'missing'  => [$isToday ? 'No ha llegado' : 'No marcó', 'is-missing'],
        'excused'  => ['Con permiso', 'is-excused'],
    ];
@endphp

<div class="cal-day-shell">
    {{-- 1. Encabezado de la Página --}}
    <div class="cal-day-header">
        <div>
            <h1>Asistencia del día</h1>
            <p>
                <span>Quién debía venir y quién marcó, por turno.</span>
                @if($isToday)
                    <span class="badge bg-success-subtle text-success border border-success-subtle fw-semibold">
                        <i class="bi bi-broadcast me-1"></i>En tiempo real (recarga cada minuto)
                    </span>
                @endif
            </p>
        </div>
        <a href="{{ route('attendance.calendar', ['month' => $filters->month] + array_filter(['branch_id' => $filters->branchId])) }}" class="cal-btn-back-month">
            <i class="bi bi-calendar3"></i>
            <span>Ver el mes</span>
        </a>
    </div>

    {{-- 2. Filtros de Navegación Asíncronos (Sin recarga de página ni parámetros vacíos) --}}
    <div class="cal-day-panel">
        <form id="dayFilterForm" onsubmit="event.preventDefault(); applyDayFilter();" class="row g-3 align-items-end">
            {{-- Selector de Fecha con Botones Prev/Next Asíncronos --}}
            <div class="col-12 col-md-6 col-xl-3">
                <label for="date" class="cal-day-form-label">Día</label>
                <div class="cal-day-nav-group">
                    <button type="button" class="cal-day-nav-btn" onclick="navDay('{{ $filters->previousDay() }}')" aria-label="Día anterior" title="Día anterior">
                        <i class="bi bi-chevron-left"></i>
                    </button>
                    <input type="date" id="date" name="date" value="{{ $filters->day }}" class="cal-day-date-input" onchange="applyDayFilter()">
                    <button type="button" class="cal-day-nav-btn" onclick="navDay('{{ $filters->nextDay() }}')" aria-label="Día siguiente" title="Día siguiente">
                        <i class="bi bi-chevron-right"></i>
                    </button>
                </div>
            </div>

            {{-- Filtro por Sucursal --}}
            <div class="col-6 col-md-3 col-xl-2">
                <label for="branch_id" class="cal-day-form-label">Sucursal</label>
                <select id="branch_id" name="branch_id" class="cal-day-select" onchange="applyDayFilter()">
                    <option value="">🏢 Todas las sucursales</option>
                    @foreach($branches as $b)
                        <option value="{{ $b->id }}" @selected($filters->branchId == $b->id)>{{ $b->name }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Filtro por Turno --}}
            <div class="col-6 col-md-3 col-xl-2">
                <label for="shift_id" class="cal-day-form-label">Turno</label>
                <select id="shift_id" name="shift_id" class="cal-day-select" onchange="applyDayFilter()">
                    <option value="">🕒 Todos los turnos</option>
                    @foreach($shifts as $s)
                        <option value="{{ $s->id }}" @selected($filters->shiftId == $s->id)>{{ $s->name }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Búsqueda de Colaborador --}}
            <div class="col-12 col-md-6 col-xl-3">
                <label for="q" class="cal-day-form-label">Colaborador</label>
                <div class="cal-day-search-box">
                    <i class="bi bi-search"></i>
                    <input type="search" id="q" name="q" value="{{ $filters->search }}" class="cal-day-search-input" placeholder="Nombre, código o DNI..." onkeydown="if(event.key === 'Enter') applyDayFilter();">
                </div>
            </div>

            {{-- Botón Aplicar --}}
            <div class="col-12 col-md-6 col-xl-2">
                <button type="submit" class="cal-day-btn-apply">
                    <i class="bi bi-funnel"></i>
                    <span>Aplicar</span>
                </button>
            </div>
        </form>
    </div>

    {{-- 3. Contenedor Dinámico Asíncrono: Se actualiza sin recargar la página completa --}}
    <div id="cal-day-dynamic-container">
        {{-- Overlay de Carga Asíncrona (Solo cubre los resultados inferiores) --}}
        <div id="cal-day-loading-overlay">
            <div class="d-flex flex-column align-items-center">
                <div class="spinner-border text-primary mb-2" role="status" style="width: 2.2rem; height: 2.2rem;"></div>
                <span class="small fw-bold" style="color: var(--cal-text-main); letter-spacing: 0.02em;">Cargando jornada...</span>
            </div>
        </div>

        {{-- Métricas Ejecutivas del Día --}}
        <div class="cal-day-kpis-grid">
            @foreach([
                ['Esperados', $counters['expected'], 'people-fill', 'rgba(2, 132, 199, 0.12)', '#0284c7', ''],
                ['Marcaron', $counters['marked'], 'check-circle-fill', 'rgba(16, 185, 129, 0.12)', '#10b981', 'text-success'],
                ['Tardanzas', $counters['late'], 'clock-history', 'rgba(245, 158, 11, 0.12)', '#d97706', 'text-warning-emphasis'],
                [$isToday ? 'No han llegado' : 'No marcaron', $counters['missing'], 'x-circle-fill', 'rgba(239, 68, 68, 0.12)', '#ef4444', 'text-danger'],
                ['Por llegar', $counters['upcoming'], 'hourglass-split', 'rgba(99, 102, 241, 0.12)', '#6366f1', ''],
                ['Con permiso', $counters['excused'], 'shield-check', 'rgba(14, 165, 233, 0.12)', '#0ea5e9', ''],
            ] as [$label, $value, $icon, $bg, $color, $valueClass])
                @continue($label === 'Por llegar' && ! $isToday)
                <div class="cal-day-kpi-card">
                    <div class="cal-day-kpi-icon" style="background: {{ $bg }}; color: {{ $color }};">
                        <i class="bi bi-{{ $icon }}"></i>
                    </div>
                    <div>
                        <div class="cal-day-kpi-value {{ $valueClass }}">{{ $value }}</div>
                        <div class="cal-day-kpi-label">{{ $label }}</div>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Banner con la fecha formateada --}}
        <div class="cal-day-date-banner">
            <i class="bi bi-calendar-event"></i>
            <span class="text-capitalize">{{ $dateLabel }}</span>
        </div>

        {{-- Grupos por Turno y Tablas de Asistencia --}}
        @forelse($groups as $shiftName => $rows)
            <div class="cal-shift-card">
                <div class="cal-shift-card-header">
                    <div class="cal-shift-title-group">
                        <div class="cal-shift-icon-box">
                            <i class="bi bi-clock-fill"></i>
                        </div>
                        <div>
                            <h2 class="cal-shift-title">{{ $shiftName }}</h2>
                            @if($rows->first()['cell']['shift_start'])
                                <span class="cal-shift-time-badge">· entrada {{ hora($rows->first()['cell']['shift_start']) }}</span>
                            @endif
                        </div>
                    </div>
                    <div class="cal-shift-count-badge">
                        <i class="bi bi-check2-circle text-success"></i>
                        <span><strong>{{ $rows->where('outcome', 'marked')->count() }}</strong>de {{ $rows->count() }} marcaron</span>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="cal-shift-table">
                        <thead>
                            <tr>
                                <th>Colaborador</th>
                                <th>Estado</th>
                                <th class="text-end">Entrada</th>
                                <th class="text-end">Salida</th>
                                <th class="text-center">Selfie</th>
                                <th class="text-end">Distancia al punto</th>
                                <th>Sucursal</th>
                                @if($canEdit)<th class="text-end">Acciones</th>@endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($rows as $r)
                                @php
                                    $c = $r['cell'];
                                    $a = $r['attendance'];
                                    [$outcomeLabel, $outcomeClass] = $outcomes[$r['outcome']];
                                    $thumb = $a ? attendance_photo_thumb_url($a, 'in') : null;
                                    $initials = strtoupper(substr($r['first_name'] ?? '', 0, 1) . substr($r['last_name'] ?? '', 0, 1));
                                    $personSlug = \Illuminate\Support\Str::slug("{$r['first_name']} {$r['last_name']}") ?: $r['user_id'];
                                @endphp
                                <tr>
                                    <td>
                                        <div class="cal-day-colab-cell">
                                            @if(!empty($r['avatar_url']))
                                                <img src="{{ $r['avatar_url'] }}" alt="{{ $r['first_name'] }}" class="cal-avatar-img" onerror="this.style.display='none'; if(this.nextElementSibling) this.nextElementSibling.style.display='flex';">
                                                <div class="cal-avatar-circle" style="display: none;">{{ $initials ?: 'U' }}</div>
                                            @else
                                                <div class="cal-avatar-circle">{{ $initials ?: 'U' }}</div>
                                            @endif
                                            <div>
                                                <a href="{{ route('attendance.calendar.person', ['id' => $personSlug, 'month' => $filters->month, 'date' => $filters->day]) }}" class="cal-day-colab-name" title="Ver calendario mensual de {{ $r['first_name'] }}">
                                                    {{ $r['last_name'] }}, {{ $r['first_name'] }}
                                                </a>
                                                <div class="cal-day-colab-sub">
                                                    @if(!empty($r['employee_code']))
                                                        <span class="badge border py-1 px-2" style="font-size: 0.68rem; font-weight: 700; border-radius: 5px; background: var(--cal-bg-elevated); color: var(--cal-text-muted); border-color: var(--cal-border) !important;">{{ $r['employee_code'] }}</span>
                                                    @endif
                                                    <span>{{ $r['position'] ?: 'Colaborador' }}</span>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        @if($c['clock_in'] || $c['state'] === 'excused')
                                            @php
                                                $badgeStyle = match($c['state'] ?? '') {
                                                    'present' => 'is-present',
                                                    'late', 'incomplete' => 'is-late',
                                                    'absent' => 'is-absent',
                                                    'excused', 'half_day' => 'is-excused',
                                                    default => 'is-present',
                                                };
                                            @endphp
                                            <span class="cal-day-badge {{ $badgeStyle }}">
                                                @if($c['state'] === 'present') <i class="bi bi-check-circle-fill"></i>
                                                @elseif($c['state'] === 'late' || $c['state'] === 'incomplete') <i class="bi bi-clock-fill"></i>
                                                @elseif($c['state'] === 'absent') <i class="bi bi-x-circle-fill"></i>
                                                @else <i class="bi bi-shield-check"></i> @endif
                                                <span>{{ $c['label'] }}{{ $c['late_minutes'] ? ' · ' . $c['late_minutes'] . ' min' : '' }}</span>
                                            </span>
                                        @else
                                            <span class="cal-day-badge {{ $outcomeClass }}">
                                                @if($r['outcome'] === 'missing') <i class="bi bi-x-circle-fill"></i>
                                                @elseif($r['outcome'] === 'upcoming') <i class="bi bi-hourglass-split"></i>
                                                @else <i class="bi bi-check-circle-fill"></i> @endif
                                                <span>{{ $outcomeLabel }}</span>
                                            </span>
                                        @endif

                                        @if($c['suspicious'])
                                            <span class="badge bg-warning text-dark border border-warning ms-1" title="Tiene motivos de sospecha">
                                                <i class="bi bi-shield-exclamation"></i>
                                            </span>
                                        @endif

                                        @if($c['state'] === 'excused' && $c['note'])
                                            <div class="small mt-1" style="color: var(--cal-text-muted); font-size: 0.725rem;">
                                                <i class="bi bi-info-circle me-1"></i>{{ $c['note'] }}
                                            </div>
                                        @endif
                                    </td>
                                    <td class="text-end font-monospace fw-bold" style="color: var(--cal-text-main);">
                                        {{ $c['clock_in'] ?? '—' }}
                                    </td>
                                    <td class="text-end font-monospace fw-bold" style="color: var(--cal-text-main);">
                                        {{ $c['clock_out'] ?? '—' }}
                                    </td>
                                    <td class="text-center">
                                        @if($thumb && $canOpen)
                                            <button type="button" class="cal-selfie-btn" onclick="openEvidence({{ $a->id }})" aria-label="Ver selfie y mapa de {{ $r['first_name'] }}" title="Ver evidencia fotográfica y GPS">
                                                <img src="{{ $thumb }}" alt="Selfie" class="cal-selfie-thumb" loading="lazy">
                                            </button>
                                        @elseif($thumb)
                                            <img src="{{ $thumb }}" alt="Selfie de entrada" class="cal-selfie-thumb" loading="lazy">
                                        @elseif($a && $canOpen)
                                            <button type="button" class="btn btn-sm btn-outline-primary py-1 px-2" onclick="openEvidence({{ $a->id }})" title="Ver mapa GPS">
                                                <i class="bi bi-geo-alt"></i>
                                                <span class="visually-hidden">Ver mapa</span>
                                            </button>
                                        @else
                                            <span class="cal-selfie-empty" title="Sin captura fotográfica">
                                                <i class="bi bi-camera-video-off"></i>
                                            </span>
                                        @endif
                                    </td>
                                    <td class="text-end text-nowrap">
                                        @if($a && $a->clock_in_distance_meters !== null)
                                            @if($a->clock_in_within_geofence)
                                                <span class="badge bg-success-subtle text-success border border-success-subtle fw-bold" style="font-size: 0.75rem;">
                                                    <i class="bi bi-geo-alt-fill me-1"></i>{{ $a->clock_in_distance_meters }} m
                                                </span>
                                            @else
                                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle fw-bold" style="font-size: 0.75rem;">
                                                    <i class="bi bi-exclamation-triangle-fill me-1"></i>{{ $a->clock_in_distance_meters }} m
                                                </span>
                                                <div class="small text-danger fw-bold mt-0.5" style="font-size: 0.675rem;">Fuera del radio</div>
                                            @endif
                                        @else
                                            <span style="color: var(--cal-text-subtle);">—</span>
                                        @endif
                                    </td>
                                    <td>
                                        <span style="color: var(--cal-text-main); font-weight: 500;">
                                            <i class="bi bi-building me-1 text-primary" style="font-size: 0.8rem;"></i>{{ $a?->branch?->name ?? '—' }}
                                        </span>
                                    </td>
                                    @if($canEdit)
                                        <td class="text-end">
                                            @if((int) $r['user_id'] !== (int) auth()->id() && $filters->day <= now(tenant_timezone())->toDateString())
                                                <a href="{{ route('attendance.manual.edit', ['id' => $r['user_id'], 'date' => $filters->day]) }}" class="cal-btn-edit-action" title="Corregir asistencia de {{ $r['first_name'] }}">
                                                    <i class="bi bi-pencil"></i>
                                                    <span class="visually-hidden">Corregir asistencia de {{ $r['first_name'] }}</span>
                                                </a>
                                            @endif
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @empty
            <div class="cal-empty-state-card">
                <i class="bi bi-calendar2-x"></i>
                <div class="fs-6 fw-bold mb-1" style="color: var(--cal-text-main);">Nadie tenía turno, permiso ni marcación este día con estos filtros.</div>
                <div class="small" style="color: var(--cal-text-muted);">Prueba seleccionando otra fecha, sucursal o turno en el panel superior.</div>
            </div>
        @endforelse

        @if($page->total() > 0)
            <div class="d-flex justify-content-between align-items-center py-2">
                <small style="color: var(--cal-text-muted);">Mostrando {{ $page->firstItem() }} a {{ $page->lastItem() }} de {{ $page->total() }} registros</small>
                <div>
                    @if($page->hasPages())
                        {{ $page->links() }}
                    @else
                        <span class="badge bg-secondary-subtle text-secondary px-2 py-1" style="font-size: 0.75rem;">Pág. 1 de 1</span>
                    @endif
                </div>
            </div>
        @endif
    </div>
</div>

@if($canOpen)
    @include('attendance.partials.evidence-modal')
@endif
@endsection

@section('scripts')
<script>
{{-- Filtro Asíncrono: No genera recargas completas ni parámetros vacíos en la URL --}}
async function applyDayFilter(customParams = {}) {
    const form = document.getElementById('dayFilterForm');
    const formData = new FormData(form);
    const params = Object.fromEntries(formData.entries());
    Object.assign(params, customParams);

    Panel.skeleton.on('#cal-day-dynamic-container');

    try {
        const url = new URL('{{ route("attendance.calendar.day") }}', window.location.origin);
        {{-- Solo agregar parámetros que no estén vacíos para mantener la URL completamente limpia --}}
        Object.entries(params).forEach(([k, v]) => {
            if (v !== null && v !== undefined && String(v).trim() !== '') {
                url.searchParams.set(k, String(v).trim());
            }
        });

        const res = await fetch(url.toString(), {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'text/html'
            }
        });

        if (!res.ok) throw new Error('Error al actualizar');

        const html = await res.text();
        const doc = new DOMParser().parseFromString(html, 'text/html');
        const newContent = doc.getElementById('cal-day-dynamic-container');

        if (newContent) {
            document.getElementById('cal-day-dynamic-container').innerHTML = newContent.innerHTML;
            {{-- Mantener la barra de navegación del navegador limpia y profesional --}}
            window.history.replaceState({}, '', url.toString());
        }
    } catch (e) {
        console.error('Error al actualizar la jornada:', e);
    } finally {
        Panel.skeleton.off('#cal-day-dynamic-container');
    }
}

function navDay(dateStr) {
    const dateInput = document.getElementById('date');
    if (dateInput) {
        dateInput.value = dateStr;
    }
    applyDayFilter({ date: dateStr });
}

@if($isToday)
{{-- Hoy: recarga cada minuto mientras la pestaña está visible y no hay un visor abierto --}}
setInterval(function () {
    if (!document.hidden && !document.querySelector('.modal.show')) location.reload();
}, 60000);
@endif
</script>
@endsection
