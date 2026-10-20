@extends('layouts.app')

@section('title', 'Calendario de ' . $row['first_name'] . ' ' . $row['last_name'])

@section('styles')
<style>
/* ==========================================================================
   CALENDARIO INDIVIDUAL DE ASISTENCIA — DISEÑO EJECUTIVO ADAPTATIVO
   ========================================================================== */
.cal-person-shell {
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

[data-bs-theme="dark"] .cal-person-shell {
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

/* 1. Header Principal */
.cal-person-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 1rem;
    margin-bottom: 0.25rem;
}
.cal-person-profile-box {
    display: flex;
    align-items: center;
    gap: 1rem;
}
.cal-person-avatar-img {
    width: 48px;
    height: 48px;
    border-radius: 10px;
    object-fit: cover;
    border: 1.5px solid var(--cal-border);
    flex-shrink: 0;
}
.cal-person-avatar-circle {
    width: 48px;
    height: 48px;
    border-radius: 10px;
    background: rgba(37, 99, 235, 0.12);
    color: #2563eb;
    border: 1px solid rgba(37, 99, 235, 0.25);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.15rem;
    font-weight: 800;
    flex-shrink: 0;
}
[data-bs-theme="dark"] .cal-person-avatar-circle {
    background: rgba(37, 99, 235, 0.22);
    color: #60a5fa;
    border-color: rgba(96, 165, 250, 0.35);
}

.cal-person-title {
    font-size: 1.65rem;
    font-weight: 800;
    color: var(--cal-text-main) !important;
    letter-spacing: -0.02em;
    line-height: 1.2;
    margin: 0 0 0.25rem 0;
}
.cal-person-subtitle {
    color: var(--cal-text-muted) !important;
    font-size: 0.85rem;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.cal-person-btn-action {
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
.cal-person-btn-action:hover {
    background: var(--cal-bg-elevated);
    border-color: #2563eb;
    color: #2563eb;
    transform: translateY(-1px);
}
[data-bs-theme="dark"] .cal-person-btn-action:hover {
    border-color: #60a5fa;
    color: #60a5fa;
}

/* 2. Barra de Navegación de Mes & Controles */
.cal-person-nav-panel {
    background: var(--cal-bg);
    border: 1px solid var(--cal-border);
    border-radius: 12px;
    padding: 0.85rem 1.25rem;
    box-shadow: var(--cal-shadow);
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 1rem;
}
.cal-person-month-nav {
    display: inline-flex;
    align-items: center;
    gap: 0.65rem;
}
.cal-nav-btn {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    border: 1px solid var(--cal-border-light);
    background: var(--cal-bg-elevated);
    color: var(--cal-text-main);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    transition: all 0.12s ease;
}
.cal-nav-btn:hover {
    background: #2563eb;
    border-color: #2563eb;
    color: #ffffff;
}
.cal-month-text {
    font-size: 1.15rem;
    font-weight: 800;
    color: var(--cal-text-main);
    text-transform: capitalize;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

/* 3. Deck de Tarjetas KPI */
.cal-person-kpis-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 0.9rem;
}
.cal-person-kpi-card {
    background: var(--cal-bg);
    border: 1px solid var(--cal-border);
    border-radius: 12px;
    padding: 1rem 1.15rem;
    display: flex;
    align-items: center;
    gap: 1rem;
    box-shadow: var(--cal-shadow);
    transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
}
.cal-person-kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.08);
    border-color: var(--cal-border-light);
}
.cal-person-kpi-icon {
    width: 44px;
    height: 44px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    flex-shrink: 0;
}
.cal-person-kpi-value {
    font-size: 1.55rem;
    font-weight: 800;
    line-height: 1.1;
    color: var(--cal-text-main);
    letter-spacing: -0.02em;
    font-variant-numeric: tabular-nums;
}
.cal-person-kpi-label {
    font-size: 0.725rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    font-weight: 700;
    color: var(--cal-text-subtle);
    margin-top: 3px;
}

/* 4. Tablero de Calendario Mensual */
.cal-person-grid-card {
    background: var(--cal-bg);
    border: 1px solid var(--cal-border);
    border-radius: 8px;
    box-shadow: var(--cal-shadow);
    overflow: hidden;
}
.cal-person-grid-header {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    background: var(--cal-bg-elevated);
    border-bottom: 2px solid var(--cal-border);
}
.cal-weekday-th {
    padding: 0.75rem 0.5rem;
    text-align: center;
    font-size: 0.75rem;
    font-weight: 800;
    color: var(--cal-text-muted);
    text-transform: uppercase;
    letter-spacing: 0.06em;
    border-right: 1px solid var(--cal-border);
}
.cal-weekday-th:last-child {
    border-right: none;
}
.cal-weekday-th.is-weekend {
    background: var(--cal-bg-subtle);
    color: var(--cal-text-subtle);
}

.cal-person-grid-body {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
}

/* Celda de Día Individual */
.cal-person-day-cell {
    min-height: 125px;
    border-right: 1px solid var(--cal-border);
    border-bottom: 1px solid var(--cal-border);
    padding: 9px 10px;
    background: var(--cal-bg);
    display: flex;
    flex-direction: column;
    justify-content: flex-start;
    position: relative;
    transition: all 0.15s ease;
}
.cal-person-day-cell:nth-child(7n) {
    border-right: none;
}
.cal-person-day-cell:hover {
    background: var(--cal-bg-subtle);
    z-index: 5;
}
.cal-person-day-cell.is-empty {
    background: var(--cal-bg-subtle);
    opacity: 0.35;
}
.cal-person-day-cell.is-today {
    box-shadow: inset 0 0 0 2px #2563eb !important;
}
[data-bs-theme="dark"] .cal-person-day-cell.is-today {
    background: rgba(37, 99, 235, 0.1) !important;
}
.cal-person-day-cell.is-selected-date {
    box-shadow: inset 0 0 0 2px #10b981, 0 0 16px rgba(16, 185, 129, 0.25) !important;
    background: rgba(16, 185, 129, 0.08) !important;
}

.cal-person-cell-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 6px;
}
.cal-person-day-num {
    font-size: 0.95rem;
    font-weight: 800;
    color: var(--cal-text-main);
    line-height: 1;
}

/* Ficha de Estado (Píldora del Día) */
.cal-person-token {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    border-radius: 6px;
    font-weight: 800;
    font-size: 0.725rem;
    line-height: 1;
    border: 1px solid transparent;
}
.cal-person-token.is-present { background: #dcfce7; border-color: #86efac; color: #14532d; }
.cal-person-token.is-late    { background: #fef3c7; border-color: #fcd34d; color: #78350f; }
.cal-person-token.is-absent  { background: #fee2e2; border-color: #fca5a5; color: #7f1d1d; }
.cal-person-token.is-excused { background: #dbeafe; border-color: #93c5fd; color: #1e3a8a; }
.cal-person-token.is-rest    { background: var(--cal-bg-elevated); border-color: var(--cal-border-light); color: var(--cal-text-muted); }
.cal-person-token.is-scheduled { background: transparent; border: 1px dashed var(--cal-border-light); color: var(--cal-text-subtle); }

[data-bs-theme="dark"] .cal-person-token.is-present { background: rgba(16, 185, 129, 0.22); border-color: rgba(16, 185, 129, 0.4); color: #34d399; }
[data-bs-theme="dark"] .cal-person-token.is-late    { background: rgba(245, 158, 11, 0.22); border-color: rgba(245, 158, 11, 0.4); color: #fbbf24; }
[data-bs-theme="dark"] .cal-person-token.is-absent  { background: rgba(239, 68, 68, 0.22); border-color: rgba(239, 68, 68, 0.4); color: #f87171; }
[data-bs-theme="dark"] .cal-person-token.is-excused { background: rgba(59, 130, 246, 0.22); border-color: rgba(59, 130, 246, 0.4); color: #60a5fa; }
[data-bs-theme="dark"] .cal-person-token.is-rest    { background: var(--cal-bg-elevated); border-color: var(--cal-border); color: var(--cal-text-subtle); }

/* Detalle interno de la celda */
.cal-person-cell-detail {
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-size: 0.725rem;
}
.cal-person-status-label {
    font-weight: 700;
    color: var(--cal-text-main);
    display: flex;
    align-items: center;
    gap: 4px;
}
.cal-person-time-row {
    font-family: ui-monospace, SFMono-Regular, monospace;
    font-weight: 700;
    color: var(--cal-text-muted);
    font-size: 0.75rem;
    display: flex;
    align-items: center;
    gap: 4px;
}
.cal-person-badge-late {
    background: rgba(245, 158, 11, 0.15);
    color: #d97706;
    border: 1px solid rgba(245, 158, 11, 0.3);
    font-size: 0.675rem;
    font-weight: 700;
    padding: 2px 6px;
    border-radius: 4px;
    width: fit-content;
}
[data-bs-theme="dark"] .cal-person-badge-late {
    color: #fbbf24;
    border-color: rgba(245, 158, 11, 0.4);
}

.cal-person-badge-ot {
    background: rgba(16, 185, 129, 0.15);
    color: #10b981;
    border: 1px solid rgba(16, 185, 129, 0.3);
    font-size: 0.675rem;
    font-weight: 700;
    padding: 2px 6px;
    border-radius: 4px;
    width: fit-content;
}

.cal-person-btn-evidence {
    margin-top: auto;
    height: 24px;
    padding: 0 8px;
    border-radius: 6px;
    border: 1px solid #bfdbfe;
    background: #eff6ff;
    color: #1d4ed8;
    font-size: 0.68rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    cursor: pointer;
    transition: all 0.12s ease;
    width: fit-content;
    text-decoration: none;
}
.cal-person-btn-evidence:hover {
    background: #2563eb;
    color: #ffffff;
    border-color: #2563eb;
}
[data-bs-theme="dark"] .cal-person-btn-evidence {
    background: rgba(37, 99, 235, 0.18);
    color: #60a5fa;
    border-color: rgba(96, 165, 250, 0.3);
}

.cal-person-btn-edit {
    position: absolute;
    bottom: 6px;
    right: 6px;
    width: 22px;
    height: 22px;
    border-radius: 5px;
    background: var(--cal-bg-elevated);
    border: 1px solid var(--cal-border);
    color: var(--cal-text-subtle);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.725rem;
    text-decoration: none;
    transition: all 0.12s ease;
}
.cal-person-btn-edit:hover {
    background: #2563eb;
    color: #ffffff;
    border-color: #2563eb;
}

/* Leyenda de Convenciones */
.cal-person-legend {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 1.5rem;
    padding: 0.85rem 1.25rem;
    background: var(--cal-bg);
    border: 1px solid var(--cal-border);
    border-radius: 8px;
    font-size: 0.8rem;
    color: var(--cal-text-muted);
    flex-wrap: wrap;
    box-shadow: var(--cal-shadow);
}
.cal-person-legend-item {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}
.cal-person-legend-item .cal-person-token {
    flex-shrink: 0;
}
</style>
@endsection

@section('content')
@php
    $monthLabel = \Carbon\Carbon::createFromFormat('!Y-m', $filters->month)->locale('es')->isoFormat('MMMM [de] YYYY');
    $t = $row['totals'];
    $fmtHours = fn ($h) => rtrim(rtrim(number_format((float) $h, 2, '.', ''), '0'), '.') . ' h';
    $initials = strtoupper(substr($row['first_name'] ?? '', 0, 1) . substr($row['last_name'] ?? '', 0, 1));
@endphp

<div class="cal-person-shell">
    <!-- 1. Encabezado Principal y Perfil -->
    <div class="cal-person-header">
        <div class="cal-person-profile-box">
            @if(!empty($employee->avatar_path))
                <img src="{{ asset('storage/' . $employee->avatar_path) }}" alt="{{ $row['first_name'] }}" class="cal-person-avatar-img" onerror="this.style.display='none'; if(this.nextElementSibling) this.nextElementSibling.style.display='flex';">
                <div class="cal-person-avatar-circle" style="display: none;">{{ $initials ?: 'U' }}</div>
            @else
                <div class="cal-person-avatar-circle">{{ $initials ?: 'U' }}</div>
            @endif
            <div>
                <h1 class="cal-person-title">{{ $row['first_name'] }} {{ $row['last_name'] }}</h1>
                <p class="cal-person-subtitle">
                    @if(!empty($row['employee_code']))
                        <span class="badge border py-1 px-2" style="font-size: 0.7rem; font-weight: 700; border-radius: 5px; background: var(--cal-bg-elevated); color: var(--cal-text-muted); border-color: var(--cal-border) !important;">{{ $row['employee_code'] }}</span>
                    @endif
                    <span>{{ $row['position'] ?: 'Colaborador' }}</span>
                    <span style="opacity: 0.4;">•</span>
                    <span>Calendario mensual de asistencia</span>
                </p>
            </div>
        </div>

        <div class="d-flex align-items-center gap-2">
            <a href="{{ route('attendance.calendar', ['month' => $filters->month]) }}" class="cal-person-btn-action" title="Volver al calendario mensual">
                <i class="bi bi-calendar3"></i>
                <span>Todo el equipo</span>
            </a>
            @can('profiles.manage')
                <a href="{{ route('employees.edit', $employee->id) }}" class="cal-person-btn-action" title="Ver ficha del colaborador">
                    <i class="bi bi-person-badge"></i>
                    <span>Ficha</span>
                </a>
            @endcan
        </div>
    </div>

    <!-- 2. Navegador de Mes y Métricas Resumen -->
    <div class="cal-person-nav-panel">
        <div class="cal-person-month-nav">
            <a href="{{ route('attendance.calendar.person', ['id' => $friendlySlug, 'month' => $filters->previousMonth()] + ($selectedDate ? ['date' => $selectedDate] : [])) }}" class="cal-nav-btn" aria-label="Mes anterior" title="Mes anterior">
                <i class="bi bi-chevron-left"></i>
            </a>
            <div class="cal-month-text">
                <i class="bi bi-calendar-check text-primary"></i>
                <span>{{ $monthLabel }}</span>
            </div>
            <a href="{{ route('attendance.calendar.person', ['id' => $friendlySlug, 'month' => $filters->nextMonth()] + ($selectedDate ? ['date' => $selectedDate] : [])) }}" class="cal-nav-btn" aria-label="Mes siguiente" title="Mes siguiente">
                <i class="bi bi-chevron-right"></i>
            </a>
        </div>

        @if($selectedDate)
            <div class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-2 fw-bold" style="font-size: 0.8rem; border-radius: 6px;">
                <i class="bi bi-geo-alt-fill me-1"></i>Día enfocado: {{ \Carbon\Carbon::parse($selectedDate)->locale('es')->isoFormat('D [de] MMMM') }}
            </div>
        @endif
    </div>

    <!-- 3. KPIs Ejecutivos del Colaborador -->
    <div class="cal-person-kpis-grid">
        <div class="cal-person-kpi-card">
            <div class="cal-person-kpi-icon" style="background: rgba(239, 68, 68, 0.12); color: #ef4444;">
                <i class="bi bi-x-circle-fill"></i>
            </div>
            <div>
                <div class="cal-person-kpi-value text-danger">{{ $t['absent'] }}</div>
                <div class="cal-person-kpi-label">Faltas acumuladas</div>
            </div>
        </div>

        <div class="cal-person-kpi-card">
            <div class="cal-person-kpi-icon" style="background: rgba(245, 158, 11, 0.12); color: #d97706;">
                <i class="bi bi-clock-history"></i>
            </div>
            <div>
                <div class="cal-person-kpi-value text-warning-emphasis">{{ $t['late'] }}</div>
                <div class="cal-person-kpi-label">Tardanzas ({{ $t['late_minutes'] }} min)</div>
            </div>
        </div>

        <div class="cal-person-kpi-card">
            <div class="cal-person-kpi-icon" style="background: rgba(14, 165, 233, 0.12); color: #0ea5e9;">
                <i class="bi bi-shield-check"></i>
            </div>
            <div>
                <div class="cal-person-kpi-value">{{ $t['excused'] }}</div>
                <div class="cal-person-kpi-label">Justificados</div>
            </div>
        </div>

        <div class="cal-person-kpi-card">
            <div class="cal-person-kpi-icon" style="background: rgba(37, 99, 235, 0.12); color: #2563eb;">
                <i class="bi bi-briefcase-fill"></i>
            </div>
            <div>
                <div class="cal-person-kpi-value">{{ rtrim(rtrim(number_format($t['worked_hours'], 2, '.', ''), '0'), '.') ?: 0 }} h</div>
                <div class="cal-person-kpi-label">Horas trabajadas</div>
            </div>
        </div>

        @if($t['overtime_approved'] || $t['overtime_pending'])
            <div class="cal-person-kpi-card">
                <div class="cal-person-kpi-icon" style="background: rgba(16, 185, 129, 0.12); color: #10b981;">
                    <i class="bi bi-plus-circle-fill"></i>
                </div>
                <div>
                    <div class="cal-person-kpi-value text-success">{{ $fmtHours($t['overtime_approved']) }}</div>
                    <div class="cal-person-kpi-label">Horas extra @if($t['overtime_pending'])<span class="text-warning-emphasis">(+{{ $fmtHours($t['overtime_pending']) }} pte)</span>@endif</div>
                </div>
            </div>
        @endif

        @if($t['suspicious'])
            <div class="cal-person-kpi-card">
                <div class="cal-person-kpi-icon" style="background: rgba(245, 158, 11, 0.15); color: #f59e0b;">
                    <i class="bi bi-shield-exclamation"></i>
                </div>
                <div>
                    <div class="cal-person-kpi-value text-warning-emphasis">{{ $t['suspicious'] }}</div>
                    <div class="cal-person-kpi-label">Observadas</div>
                </div>
            </div>
        @endif
    </div>

    <!-- 4. Cuadrícula Mensual del Colaborador -->
    <div class="cal-person-grid-card">
        <div class="cal-person-grid-header">
            @foreach(['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'] as $idx => $wd)
                <div class="cal-weekday-th {{ $idx >= 5 ? 'is-weekend' : '' }}">{{ $wd }}</div>
            @endforeach
        </div>

        <div class="cal-person-grid-body">
            @foreach($weeks as $week)
                @foreach($week as $date)
                    @if(! $date)
                        <div class="cal-person-day-cell is-empty"></div>
                        @continue
                    @endif
                    @php
                        $c = $row['cells'][$date];
                        $num = (int) substr($date, 8, 2);
                        $isTodayCell = ($date === $today);
                        $isSelectedCell = ($selectedDate && $date === $selectedDate);

                        $tokenStateClass = match($c['state'] ?? '') {
                            'present' => 'is-present',
                            'late', 'incomplete' => 'is-late',
                            'absent' => 'is-absent',
                            'excused', 'half_day' => 'is-excused',
                            'rest' => 'is-rest',
                            default => 'is-scheduled',
                        };
                    @endphp
                    <div class="cal-person-day-cell {{ $isTodayCell ? 'is-today' : '' }} {{ $isSelectedCell ? 'is-selected-date' : '' }}" id="cell-{{ $date }}">
                        <div class="cal-person-cell-head">
                            <span class="cal-person-day-num">{{ $num }}</span>
                            @if($c)
                                <span class="cal-person-token {{ $tokenStateClass }}" title="{{ $c['label'] }}">{{ $c['letter'] }}</span>
                            @endif
                        </div>

                        @if($c)
                            <div class="cal-person-cell-detail">
                                <div class="cal-person-status-label">
                                    <span>{{ $c['label'] }}</span>
                                    @if($c['suspicious'])
                                        <i class="bi bi-shield-exclamation text-warning" title="Observada"></i>
                                    @endif
                                </div>

                                @if($c['clock_in'] || $c['clock_out'])
                                    <div class="cal-person-time-row">
                                        <i class="bi bi-clock"></i>
                                        <span>{{ $c['clock_in'] ?? '—' }} – {{ $c['clock_out'] ?? '—' }}</span>
                                    </div>
                                @endif

                                @if($c['late_minutes'])
                                    <div class="cal-person-badge-late">
                                        <i class="bi bi-alarm me-1"></i>+{{ $c['late_minutes'] }} min tarde
                                    </div>
                                @endif

                                @if($c['overtime_status'] === 'pending')
                                    <div class="cal-person-badge-late">
                                        <i class="bi bi-plus me-0.5"></i>HE {{ $fmtHours($c['overtime_hours']) }} por aprobar
                                    </div>
                                @elseif($c['overtime_status'] === 'approved')
                                    <div class="cal-person-badge-ot">
                                        <i class="bi bi-check me-0.5"></i>HE {{ $fmtHours($c['overtime_hours']) }} aprobadas
                                    </div>
                                @endif

                                @if($c['manual'] ?? false)
                                    <div style="font-size: 0.675rem; color: var(--cal-text-subtle);">
                                        <i class="bi bi-pencil-square me-1"></i>Corregida
                                    </div>
                                @endif

                                @if($c['note'] && $c['state'] !== 'scheduled')
                                    <div style="font-size: 0.675rem; color: var(--cal-text-muted);" class="text-truncate" title="{{ $c['note'] }}">
                                        {{ $c['note'] }}
                                    </div>
                                @endif

                                @if($canOpen && $c['attendance_id'])
                                    <button type="button" class="cal-person-btn-evidence" onclick="openEvidence({{ $c['attendance_id'] }})" title="Ver selfie y geolocalización">
                                        <i class="bi bi-camera-fill"></i>
                                        <span>Evidencia</span>
                                    </button>
                                @endif
                            </div>
                        @endif

                        @if($canEdit && $date <= $today)
                            <a href="{{ route('attendance.manual.edit', ['id' => $employee->id, 'date' => $date]) }}" class="cal-person-btn-edit" title="Corregir asistencia del {{ $num }}" aria-label="Corregir asistencia del {{ $date }}">
                                <i class="bi bi-pencil"></i>
                            </a>
                        @endif
                    </div>
                @endforeach
            @endforeach
        </div>
    </div>

    <!-- 5. Barra de Convenciones -->
    <div class="cal-person-legend" aria-label="Leyenda">
        <span class="fw-bold" style="color: var(--cal-text-main);"><i class="bi bi-info-circle text-primary me-1"></i> Convenciones:</span>
        @foreach($states as $state => [$letter, $tone, $label])
            @php
                $legendStateClass = match($state) {
                    'present' => 'is-present',
                    'late', 'incomplete' => 'is-late',
                    'absent' => 'is-absent',
                    'excused', 'half_day' => 'is-excused',
                    'rest' => 'is-rest',
                    default => 'is-scheduled',
                };
            @endphp
            <div class="cal-person-legend-item">
                <span class="cal-person-token {{ $legendStateClass }}">{{ $letter }}</span>
                <span>{{ $label }}</span>
            </div>
        @endforeach
    </div>
</div>

@if($canOpen)
    @include('attendance.partials.evidence-modal')
@endif
@endsection

@section('scripts')
<script>
// Sincronizar URL en la barra de navegación para que sea 100% amigable
document.addEventListener('DOMContentLoaded', function() {
    const friendlyUrl = '{{ route("attendance.calendar.person", ["id" => $friendlySlug, "month" => $filters->month] + ($selectedDate ? ["date" => $selectedDate] : [])) }}';
    if (window.location.pathname.includes('/person/{{ $employee->id }}')) {
        window.history.replaceState({}, '', friendlyUrl);
    }

    @if($selectedDate)
        const targetCell = document.getElementById('cell-{{ $selectedDate }}');
        if (targetCell) {
            targetCell.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    @endif
});
</script>
@endsection
