@extends('layouts.app')

@section('title', 'Calendario de ' . $row['first_name'])

@section('styles')
<style>
    .person-cal { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: .5rem; }
    .person-cal .wd { text-align: center; font-size: .75rem; color: var(--bs-secondary-color); }
    .person-day { min-height: 6.5rem; border-radius: .5rem; padding: .4rem .5rem; background: var(--bs-body-bg); border: 1px solid var(--bs-border-color-translucent); font-size: .8rem; text-align: left; width: 100%; }
    .person-day.is-today { box-shadow: inset 0 0 0 2px var(--bs-primary); }
    .person-day .num { font-weight: 600; }
    .person-day .chip { display: inline-flex; align-items: center; justify-content: center; min-width: 1.6rem; height: 1.6rem; border-radius: .35rem; font-weight: 600; }
    .person-day .chip.is-scheduled { border: 1px dashed var(--bs-secondary-color); color: var(--bs-secondary-color); }
    button.person-day:hover { border-color: var(--bs-primary); }
    .person-cell { position: relative; }
    .person-cell > .person-day { height: 100%; }
    .person-cell .day-edit { position: absolute; right: .3rem; bottom: .2rem; font-size: .8rem; line-height: 1; padding: .15rem .25rem; border-radius: .25rem; color: var(--bs-secondary-color); }
    .person-cell .day-edit:hover { color: var(--bs-primary); background: var(--bs-tertiary-bg); }
    @media (max-width: 575.98px) { .person-day { min-height: 4.5rem; padding: .25rem; } .person-day .detail { display: none; } }
</style>
@endsection

@section('content')
@php
    $monthLabel = \Carbon\Carbon::createFromFormat('!Y-m', $filters->month)->locale('es')->isoFormat('MMMM [de] YYYY');
    $t = $row['totals'];
    $fmtHours = fn ($h) => rtrim(rtrim(number_format((float) $h, 2, '.', ''), '0'), '.') . ' h';
@endphp
<div class="page-header">
    <div>
        <h1>{{ $row['first_name'] }} {{ $row['last_name'] }}</h1>
        <p>{{ $row['employee_code'] ?? 'Sin código' }}{{ $row['position'] ? ' · ' . $row['position'] : '' }} · Calendario de asistencia</p>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('attendance.calendar', ['month' => $filters->month]) }}" class="btn btn-outline-secondary"><i class="bi bi-calendar3 me-1"></i> Todo el equipo</a>
        @can('profiles.manage')
            <a href="{{ route('employees.edit', $employee->id) }}" class="btn btn-outline-primary"><i class="bi bi-person me-1"></i> Ficha</a>
        @endcan
    </div>
</div>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
    <div class="btn-group" role="group" aria-label="Mes">
        <a href="{{ route('attendance.calendar.person', ['id' => $employee->id, 'month' => $filters->previousMonth()]) }}" class="btn btn-outline-secondary" aria-label="Mes anterior"><i class="bi bi-chevron-left"></i></a>
        <span class="btn btn-outline-secondary disabled text-capitalize">{{ $monthLabel }}</span>
        <a href="{{ route('attendance.calendar.person', ['id' => $employee->id, 'month' => $filters->nextMonth()]) }}" class="btn btn-outline-secondary" aria-label="Mes siguiente"><i class="bi bi-chevron-right"></i></a>
    </div>
    <div class="d-flex flex-wrap gap-3 small">
        <span><strong class="{{ $t['absent'] ? 'text-danger' : '' }}">{{ $t['absent'] }}</strong> faltas</span>
        <span><strong>{{ $t['late'] }}</strong> tardanzas ({{ $t['late_minutes'] }} min)</span>
        <span><strong>{{ $t['excused'] }}</strong> justificados</span>
        <span><strong>{{ rtrim(rtrim(number_format($t['worked_hours'], 2, '.', ''), '0'), '.') ?: 0 }}</strong> horas</span>
        @if($t['overtime_approved'] || $t['overtime_pending'])
            <span><strong>{{ $fmtHours($t['overtime_approved']) }}</strong> extra aprobadas @if($t['overtime_pending'])<span class="text-warning-emphasis">(+{{ $fmtHours($t['overtime_pending']) }} por aprobar)</span>@endif</span>
        @endif
        @if($t['suspicious'])
            <span class="text-warning-emphasis"><strong>{{ $t['suspicious'] }}</strong> observadas</span>
        @endif
    </div>
</div>

<div class="card border-0">
    <div class="card-body">
        <div class="person-cal mb-2" aria-hidden="true">
            @foreach(['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'] as $wd)
                <div class="wd">{{ $wd }}</div>
            @endforeach
        </div>
        @foreach($weeks as $week)
            <div class="person-cal mb-2">
                @foreach($week as $date)
                    @if(! $date)
                        <div></div>
                        @continue
                    @endif
                    @php
                        $c = $row['cells'][$date];
                        $num = (int) substr($date, 8, 2);
                    @endphp
                    <div class="person-cell">
                    @if($canOpen && $c && $c['attendance_id'])
                        <button type="button" class="person-day {{ $date === $today ? 'is-today' : '' }}" onclick="openEvidence({{ $c['attendance_id'] }})" aria-label="{{ $date }}: {{ $c['label'] }}">
                    @else
                        <div class="person-day {{ $date === $today ? 'is-today' : '' }}">
                    @endif
                        <div class="d-flex justify-content-between align-items-start">
                            <span class="num">{{ $num }}</span>
                            @if($c)
                                <span class="chip {{ $c['state'] === 'scheduled' ? 'is-scheduled' : 'text-bg-' . $c['tone'] }}" title="{{ $c['label'] }}">{{ $c['letter'] }}</span>
                            @endif
                        </div>
                        @if($c)
                            <div class="detail mt-1">
                                <div class="text-body-secondary">{{ $c['label'] }}@if($c['suspicious']) <i class="bi bi-shield-exclamation text-warning" title="Observada"></i>@endif</div>
                                @if($c['clock_in'] || $c['clock_out'])
                                    <div class="font-monospace">{{ $c['clock_in'] ?? '—' }} – {{ $c['clock_out'] ?? '—' }}</div>
                                @endif
                                @if($c['late_minutes'])
                                    <div class="text-warning-emphasis">{{ $c['late_minutes'] }} min tarde</div>
                                @endif
                                @if($c['overtime_status'] === 'pending')
                                    <div class="text-warning-emphasis">HE {{ $fmtHours($c['overtime_hours']) }} por aprobar</div>
                                @elseif($c['overtime_status'] === 'approved')
                                    <div class="text-success-emphasis">HE {{ $fmtHours($c['overtime_hours']) }} aprobadas</div>
                                @endif
                                @if($c['manual'] ?? false)
                                    <div class="text-body-secondary"><i class="bi bi-pencil-square"></i> Corregida</div>
                                @endif
                                @if($c['note'] && $c['state'] !== 'scheduled')
                                    <div class="text-body-secondary text-truncate" title="{{ $c['note'] }}">{{ $c['note'] }}</div>
                                @endif
                            </div>
                        @endif
                    @if($canOpen && $c && $c['attendance_id'])
                        </button>
                    @else
                        </div>
                    @endif
                    @if($canEdit && $date <= $today)
                        <a href="{{ route('attendance.manual.edit', ['id' => $employee->id, 'date' => $date]) }}" class="day-edit" title="Corregir asistencia del {{ $num }}" aria-label="Corregir asistencia del {{ $date }}"><i class="bi bi-pencil"></i></a>
                    @endif
                    </div>
                @endforeach
                @for($i = count($week); $i < 7; $i++)
                    <div></div>
                @endfor
            </div>
        @endforeach

        <div class="d-flex flex-wrap align-items-center gap-3 small mt-3" aria-label="Leyenda">
            @foreach($states as $state => [$letter, $tone, $label])
                <span class="d-inline-flex align-items-center gap-1">
                    <span class="chip {{ $state === 'scheduled' ? 'is-scheduled' : "text-bg-{$tone}" }}" style="display:inline-flex;align-items:center;justify-content:center;min-width:1.4rem;height:1.4rem;border-radius:.3rem;font-weight:600;">{{ $letter }}</span>{{ $label }}
                </span>
            @endforeach
        </div>
    </div>
</div>

@if($canOpen)
    @include('attendance.partials.evidence-modal')
@endif
@endsection
