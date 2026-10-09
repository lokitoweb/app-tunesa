@extends('layouts.app')

@section('title', 'Reporte de horas y faltas')

@section('content')
<div class="page-header">
    <div>
        <h1>Reporte de horas y faltas</h1>
        <p>Horas efectivas, tardanzas, horas extra diurnas y nocturnas (22:00–06:00) y faltas por colaborador.</p>
    </div>
    @can('reports.export')
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('reports.hours.excel', $filters->toQuery()) }}" class="btn btn-outline-success">
                <i class="bi bi-file-earmark-spreadsheet me-1"></i> Excel
            </a>
            <a href="{{ route('reports.hours.pdf', $filters->toQuery()) }}" class="btn btn-outline-danger">
                <i class="bi bi-file-earmark-pdf me-1"></i> PDF
            </a>
        </div>
    @endcan
</div>

@include('reports.partials.tabs')

{{-- Filtros --}}
<div class="card border-0 mb-4">
    <div class="card-body">
        <form method="GET" action="{{ route('reports.hours') }}" class="row g-3 align-items-end">
            <div class="col-6 col-md-3 col-xl-2">
                <label for="from" class="form-label small">Desde</label>
                <input type="date" id="from" name="from" value="{{ $filters->from }}" class="form-control" required>
            </div>
            <div class="col-6 col-md-3 col-xl-2">
                <label for="to" class="form-label small">Hasta</label>
                <input type="date" id="to" name="to" value="{{ $filters->to }}" class="form-control" required>
            </div>
            <div class="col-12 col-md-6 col-xl-2">
                <label for="branch_id" class="form-label small">Sucursal</label>
                <select id="branch_id" name="branch_id" class="form-select">
                    <option value="">Todas</option>
                    @foreach($branches as $b)
                        <option value="{{ $b->id }}" @selected($filters->branchId == $b->id)>{{ $b->name }}@if($globalView ?? false) · {{ $tenantNames[$b->tenant_id] ?? '' }}@endif</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12 col-md-6 col-xl-2">
                <label for="employee_id" class="form-label small">Colaborador</label>
                <select id="employee_id" name="employee_id" class="form-select">
                    <option value="">Todos</option>
                    @foreach($employees as $e)
                        <option value="{{ $e->id }}" @selected($filters->userId == $e->id)>{{ $e->last_name }}, {{ $e->first_name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12 col-md-6 col-xl-2">
                <label for="q" class="form-label small">Buscar</label>
                <input type="search" id="q" name="q" value="{{ $filters->search }}" class="form-control" placeholder="Nombre, código o DNI">
            </div>
            <div class="col-12 col-xl-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-funnel me-1"></i> Aplicar</button>
                <a href="{{ route('reports.hours') }}" class="btn btn-light border">Limpiar</a>
            </div>
        </form>
    </div>
</div>

{{-- Totales del periodo --}}
<div class="row g-3 mb-4">
    @foreach([
        ['Horas trabajadas', number_format($totals['worked_hours'], 1) . ' h', $totals['days_worked'] . ' jornadas', 'clock-history', 'primary'],
        ['Tardanza', $totals['late_minutes'] . ' min', $totals['late_days'] . ' días con tardanza', 'alarm', 'warning'],
        ['HE diurnas', number_format($totals['overtime_day_hours'], 1) . ' h', 'Sobre la jornada del turno', 'sun', 'info'],
        ['HE nocturnas', number_format($totals['overtime_night_hours'], 1) . ' h', number_format($totals['night_hours'], 1) . ' h nocturnas en total', 'moon-stars', 'secondary'],
        ['Faltas injustificadas', $totals['absences_unjustified'], $totals['absences_justified'] . ' justificadas', 'person-x', 'danger'],
    ] as [$label, $value, $hint, $icon, $tone])
        <div class="col-6 col-md-4 col-xl">
            <div class="card border-0 h-100 kpi-card">
                <div class="card-body d-flex align-items-center gap-3">
                    <span class="kpi-icon bg-{{ $tone }}-subtle text-{{ $tone }}-emphasis"><i class="bi bi-{{ $icon }}"></i></span>
                    <div class="min-w-0">
                        <div class="kpi-label">{{ $label }}</div>
                        <div class="fs-4 fw-bold">{{ $value }}</div>
                        <div class="small text-body-secondary">{{ $hint }}</div>
                    </div>
                </div>
            </div>
        </div>
    @endforeach
</div>

{{-- Vista previa del resumen --}}
<div class="card border-0">
    <div class="card-header border-0 pt-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h2 class="h6 fw-semibold mb-0">Resumen por colaborador</h2>
        <span class="small text-body-secondary">
            {{ \Carbon\Carbon::parse($filters->from)->format('d/m/Y') }} – {{ \Carbon\Carbon::parse($filters->to)->format('d/m/Y') }}
            · {{ $rows->total() }} colaboradores
        </span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Colaborador</th>
                    <th>Sucursal</th>
                    <th class="text-end" title="Días laborables según su turno">Prog.</th>
                    <th class="text-end">Trab.</th>
                    <th class="text-end">Horas</th>
                    <th class="text-end">Tardanza</th>
                    <th class="text-end" title="Salidas anticipadas">Sal. antic.</th>
                    <th class="text-end">HE diurnas</th>
                    <th class="text-end">HE nocturnas</th>
                    <th class="text-end">Nocturnas</th>
                    <th class="text-end">Faltas</th>
                    <th class="text-end">Justif.</th>
                </tr>
            </thead>
            <tbody>
                @forelse($rows as $r)
                    @php($s = $r['summary'])
                    <tr>
                        <td>
                            <div class="fw-semibold">{{ $r['name'] }}</div>
                            <div class="small text-body-secondary">{{ $r['employee_code'] ?? '' }}{{ $r['position'] ? ' · ' . $r['position'] : '' }}</div>
                            @include('partials.tenant-badge', ['tenantId' => $r['tenant_id']])
                        </td>
                        <td class="text-body-secondary">{{ $r['branch'] ?? '—' }}</td>
                        <td class="text-end">{{ $s['scheduled_days'] }}</td>
                        <td class="text-end">{{ $s['days_worked'] }}</td>
                        <td class="text-end fw-semibold">{{ number_format($s['worked_hours'], 2) }}</td>
                        <td class="text-end {{ $s['late_minutes'] > 0 ? 'text-warning-emphasis fw-semibold' : 'text-body-secondary' }}">{{ $s['late_minutes'] }} min</td>
                        <td class="text-end text-body-secondary">{{ $s['early_minutes'] }} min</td>
                        <td class="text-end">{{ number_format($s['overtime_day_hours'], 2) }}</td>
                        <td class="text-end">{{ number_format($s['overtime_night_hours'], 2) }}</td>
                        <td class="text-end">{{ number_format($s['night_hours'], 2) }}</td>
                        <td class="text-end {{ $s['absences_unjustified'] > 0 ? 'text-danger fw-semibold' : '' }}">{{ $s['absences_unjustified'] }}</td>
                        <td class="text-end">{{ $s['absences_justified'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="12" class="empty-state">
                            <i class="bi bi-inbox"></i>
                            No hay colaboradores para los filtros elegidos.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($rows->hasPages())
        <div class="card-footer bg-transparent">{{ $rows->links() }}</div>
    @endif
</div>
<p class="small text-body-secondary mt-3 mb-0">
    Horas efectivas = salida − entrada − refrigerio (si el refrigerio no es pagado en "Marca y app" &gt; Reglas).
    Las horas extra son lo trabajado sobre la jornada del turno y se cuentan al final de la jornada; son nocturnas si caen entre 22:00 y 06:00.
    Las faltas solo cuentan días laborables ya cerrados, sin feriado ni solicitud aprobada.
</p>
@endsection
