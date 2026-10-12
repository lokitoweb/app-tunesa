@extends('layouts.app')

@section('title', 'Calendario de asistencia')

@section('styles')
<style>
    .cal-wrap { max-height: 70vh; overflow: auto; }
    .cal-table { border-collapse: separate; border-spacing: 0; font-size: .8rem; }
    .cal-table th, .cal-table td { white-space: nowrap; padding: .25rem; text-align: center; border-bottom: 1px solid var(--bs-border-color-translucent); }
    .cal-table thead th { position: sticky; top: 0; z-index: 2; background: var(--bs-body-bg); }
    .cal-table .cal-name { position: sticky; left: 0; z-index: 1; background: var(--bs-body-bg); text-align: left; min-width: 13rem; max-width: 16rem; }
    .cal-table thead .cal-name { z-index: 3; }
    .cal-table .cal-weekend { background: var(--bs-tertiary-bg); }
    .cal-table .cal-today { box-shadow: inset 0 -2px 0 var(--bs-primary); }
    .cal-cell { position: relative; display: inline-flex; align-items: center; justify-content: center; width: 1.7rem; height: 1.7rem; border-radius: .35rem; font-weight: 600; line-height: 1; }
    .cal-cell.is-scheduled { border: 1px dashed var(--bs-secondary-color); color: var(--bs-secondary-color); background: transparent; }
    .cal-cell.is-incomplete { outline: 2px solid var(--bs-warning-border-subtle); outline-offset: -2px; }
    .cal-cell .cal-flag { position: absolute; top: -3px; right: -3px; width: .55rem; height: .55rem; border-radius: 50%; background: #6f42c1; border: 1px solid var(--bs-body-bg); }
    button.cal-cell { border: 0; }
    .cal-total { font-variant-numeric: tabular-nums; }
</style>
@endsection

@section('content')
@php
    $monthLabel = \Carbon\Carbon::createFromFormat('!Y-m', $filters->month)->locale('es')->isoFormat('MMMM [de] YYYY');
@endphp
<div class="page-header">
    <div>
        <h1>Calendario de asistencia</h1>
        <p>Quién vino, quién llegó tarde y quién faltó, día por día.</p>
    </div>
    <a href="{{ route('attendance.calendar.excel', $filters->toQuery()) }}" class="btn btn-outline-success">
        <i class="bi bi-file-earmark-spreadsheet me-1"></i> Excel
    </a>
</div>

<div class="card border-0 mb-3">
    <div class="card-body">
        <form method="GET" action="{{ route('attendance.calendar') }}" class="row g-3 align-items-end">
            <div class="col-12 col-md-6 col-xl-3">
                <label for="month" class="form-label small">Mes</label>
                <div class="input-group">
                    <a href="{{ route('attendance.calendar', $filters->toQuery(['month' => $filters->previousMonth()])) }}" class="btn btn-outline-secondary" aria-label="Mes anterior"><i class="bi bi-chevron-left"></i></a>
                    <input type="month" id="month" name="month" value="{{ $filters->month }}" class="form-control">
                    <a href="{{ route('attendance.calendar', $filters->toQuery(['month' => $filters->nextMonth()])) }}" class="btn btn-outline-secondary" aria-label="Mes siguiente"><i class="bi bi-chevron-right"></i></a>
                </div>
            </div>
            <div class="col-6 col-md-3 col-xl-2">
                <label for="branch_id" class="form-label small">Sucursal</label>
                <select id="branch_id" name="branch_id" class="form-select">
                    <option value="">Todas</option>
                    @foreach($branches as $b)
                        <option value="{{ $b->id }}" @selected($filters->branchId == $b->id)>{{ $b->name }}</option>
                    @endforeach
                </select>
            </div>
            @if($zones->isNotEmpty())
                <div class="col-6 col-md-3 col-xl-2">
                    <label for="zone_id" class="form-label small">Zona</label>
                    <select id="zone_id" name="zone_id" class="form-select">
                        <option value="">Todas</option>
                        @foreach($zones as $z)
                            <option value="{{ $z->id }}" @selected($filters->zoneId == $z->id)>{{ $z->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            @if($departments->isNotEmpty())
                <div class="col-6 col-md-3 col-xl-2">
                    <label for="department_id" class="form-label small">Área</label>
                    <select id="department_id" name="department_id" class="form-select">
                        <option value="">Todas</option>
                        @foreach($departments as $d)
                            <option value="{{ $d->id }}" @selected($filters->departmentId == $d->id)>{{ $d->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            @if($positions->isNotEmpty())
                <div class="col-6 col-md-3 col-xl-2">
                    <label for="position_id" class="form-label small">Cargo</label>
                    <select id="position_id" name="position_id" class="form-select">
                        <option value="">Todos</option>
                        @foreach($positions as $p)
                            <option value="{{ $p->id }}" @selected($filters->positionId == $p->id)>{{ $p->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <div class="col-12 col-md-6 col-xl-3">
                <label for="q" class="form-label small">Colaborador</label>
                <input type="search" id="q" name="q" value="{{ $filters->search }}" class="form-control" placeholder="Nombre, código o DNI">
            </div>
            <div class="col-12 col-md-3 col-xl-auto d-grid">
                <button type="submit" class="btn btn-primary"><i class="bi bi-funnel me-1"></i> Aplicar</button>
            </div>
        </form>
    </div>
</div>

{{-- Leyenda: color y letra (se lee impreso o con daltonismo) --}}
<div class="d-flex flex-wrap align-items-center gap-3 small mb-3" aria-label="Leyenda">
    @foreach($states as $state => [$letter, $tone, $label])
        <span class="d-inline-flex align-items-center gap-1">
            <span class="cal-cell {{ $state === 'scheduled' ? 'is-scheduled' : "text-bg-{$tone}" }} {{ $state === 'incomplete' ? 'is-incomplete' : '' }}">{{ $letter }}</span>{{ $label }}
        </span>
    @endforeach
    <span class="d-inline-flex align-items-center gap-1">
        <span class="cal-cell text-bg-success"><span class="cal-flag"></span>P</span>Observada (motivos de sospecha)
    </span>
</div>

<div class="card border-0">
    <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
        <h2 class="h6 mb-0 text-capitalize">{{ $monthLabel }}</h2>
        <span class="small text-body-secondary">{{ $rows->total() }} colaboradores</span>
    </div>
    <div class="cal-wrap">
        <table class="cal-table w-100">
            <thead>
                <tr>
                    <th class="cal-name">Colaborador</th>
                    @foreach($days as $date => $d)
                        <th class="{{ $d['weekend'] ? 'cal-weekend' : '' }} {{ $date === $today ? 'cal-today' : '' }}" title="{{ $date }}">
                            <div class="text-body-secondary fw-normal">{{ $d['weekday'] }}</div>{{ $d['day'] }}
                        </th>
                    @endforeach
                    <th title="Faltas">F</th>
                    <th title="Tardanzas">T</th>
                    <th title="Minutos de tardanza">Min</th>
                    <th title="Horas trabajadas">Horas</th>
                </tr>
            </thead>
            <tbody>
                @forelse($rows as $r)
                    <tr>
                        <td class="cal-name">
                            <div class="fw-semibold text-truncate">{{ $r['last_name'] }}, {{ $r['first_name'] }}</div>
                            <div class="text-body-secondary text-truncate">{{ $r['employee_code'] ?? '—' }}{{ $r['position'] ? ' · ' . $r['position'] : '' }}</div>
                        </td>
                        @foreach($days as $date => $d)
                            @php $c = $r['cells'][$date] ?? null; @endphp
                            <td class="{{ $d['weekend'] ? 'cal-weekend' : '' }}">
                                @if($c)
                                    @php
                                        $tip = implode(' · ', array_filter([
                                            $c['label'],
                                            $c['clock_in'] ? 'Entrada ' . $c['clock_in'] : null,
                                            $c['clock_out'] ? 'Salida ' . $c['clock_out'] : null,
                                            $c['late_minutes'] ? $c['late_minutes'] . ' min tarde' : null,
                                            $c['note'],
                                            $c['suspicious'] ? 'Observada' : null,
                                        ]));
                                        $classes = 'cal-cell ' . ($c['state'] === 'scheduled' ? 'is-scheduled' : 'text-bg-' . $c['tone']) . ($c['state'] === 'incomplete' ? ' is-incomplete' : '');
                                    @endphp
                                    @if($canOpen && $c['attendance_id'])
                                        <button type="button" class="{{ $classes }}" title="{{ $tip }}" aria-label="{{ $date }}: {{ $tip }}" onclick="openEvidence({{ $c['attendance_id'] }})">{{ $c['letter'] }}@if($c['suspicious'])<span class="cal-flag"></span>@endif</button>
                                    @else
                                        <span class="{{ $classes }}" title="{{ $tip }}" aria-label="{{ $date }}: {{ $tip }}">{{ $c['letter'] }}@if($c['suspicious'])<span class="cal-flag"></span>@endif</span>
                                    @endif
                                @endif
                            </td>
                        @endforeach
                        <td class="cal-total {{ $r['totals']['absent'] ? 'text-danger fw-semibold' : '' }}">{{ $r['totals']['absent'] }}</td>
                        <td class="cal-total">{{ $r['totals']['late'] }}</td>
                        <td class="cal-total">{{ $r['totals']['late_minutes'] }}</td>
                        <td class="cal-total">{{ rtrim(rtrim(number_format($r['totals']['worked_hours'], 2, '.', ''), '0'), '.') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="{{ count($days) + 5 }}"><div class="empty-state"><i class="bi bi-calendar3"></i>No hay colaboradores con estos filtros.</div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($rows->hasPages())
        <div class="card-footer bg-transparent">{{ $rows->links() }}</div>
    @endif
</div>

@if($canOpen)
    @include('attendance.partials.evidence-modal')
@endif
@endsection
