@extends('layouts.app')

@section('title', 'Asistencia del día')

@section('styles')
<style>
    .day-thumb { width: 2.5rem; height: 2.5rem; border-radius: .5rem; object-fit: cover; background: var(--bs-tertiary-bg); }
    .day-thumb-empty { display: inline-flex; align-items: center; justify-content: center; color: var(--bs-secondary-color); }
    .day-counter { font-variant-numeric: tabular-nums; }
    button.day-photo { border: 0; padding: 0; background: none; }
</style>
@endsection

@section('content')
@php
    $dateLabel = \Carbon\Carbon::parse($filters->day)->locale('es')->isoFormat('dddd D [de] MMMM [de] YYYY');
    $outcomes = [
        'marked'   => ['Marcó', 'success'],
        'upcoming' => ['Por llegar', 'secondary'],
        'missing'  => [$isToday ? 'No ha llegado' : 'No marcó', 'danger'],
        'excused'  => ['Con permiso', 'primary'],
    ];
@endphp
<div class="page-header">
    <div>
        <h1>Asistencia del día</h1>
        <p>Quién debía venir y quién marcó, por turno. @if($isToday)Se actualiza cada minuto.@endif</p>
    </div>
    <a href="{{ route('attendance.calendar', ['month' => $filters->month] + array_filter(['branch_id' => $filters->branchId])) }}" class="btn btn-outline-secondary">
        <i class="bi bi-calendar3 me-1"></i> Ver el mes
    </a>
</div>

<div class="card border-0 mb-3">
    <div class="card-body">
        <form method="GET" action="{{ route('attendance.calendar.day') }}" class="row g-3 align-items-end">
            <div class="col-12 col-md-6 col-xl-3">
                <label for="date" class="form-label small">Día</label>
                <div class="input-group">
                    <a href="{{ route('attendance.calendar.day', $filters->toQuery(['date' => $filters->previousDay()])) }}" class="btn btn-outline-secondary" aria-label="Día anterior"><i class="bi bi-chevron-left"></i></a>
                    <input type="date" id="date" name="date" value="{{ $filters->day }}" class="form-control">
                    <a href="{{ route('attendance.calendar.day', $filters->toQuery(['date' => $filters->nextDay()])) }}" class="btn btn-outline-secondary" aria-label="Día siguiente"><i class="bi bi-chevron-right"></i></a>
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
            <div class="col-6 col-md-3 col-xl-2">
                <label for="shift_id" class="form-label small">Turno</label>
                <select id="shift_id" name="shift_id" class="form-select">
                    <option value="">Todos</option>
                    @foreach($shifts as $s)
                        <option value="{{ $s->id }}" @selected($filters->shiftId == $s->id)>{{ $s->name }}</option>
                    @endforeach
                </select>
            </div>
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

<div class="row g-3 mb-3">
    @foreach([
        ['Esperados', $counters['expected'], 'people', 'body'],
        ['Marcaron', $counters['marked'], 'check2-circle', 'success'],
        ['Tardanzas', $counters['late'], 'alarm', 'warning'],
        [$isToday ? 'No han llegado' : 'No marcaron', $counters['missing'], 'x-circle', 'danger'],
        ['Por llegar', $counters['upcoming'], 'hourglass-split', 'secondary'],
        ['Con permiso', $counters['excused'], 'calendar2-check', 'primary'],
    ] as [$label, $value, $icon, $tone])
        @continue($label === 'Por llegar' && ! $isToday)
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card border-0 h-100">
                <div class="card-body py-3">
                    <div class="small text-body-secondary"><i class="bi bi-{{ $icon }} me-1 text-{{ $tone }}"></i>{{ $label }}</div>
                    <div class="fs-4 fw-semibold day-counter">{{ $value }}</div>
                </div>
            </div>
        </div>
    @endforeach
</div>

<h2 class="h6 text-body-secondary text-capitalize mb-2">{{ $dateLabel }}</h2>

@forelse($groups as $shiftName => $rows)
    <div class="card border-0 mb-3">
        <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
            <h3 class="h6 mb-0">{{ $shiftName }}@if($rows->first()['cell']['shift_start']) <span class="text-body-secondary fw-normal">· entrada {{ $rows->first()['cell']['shift_start'] }}</span>@endif</h3>
            <span class="small text-body-secondary">{{ $rows->where('outcome', 'marked')->count() }} de {{ $rows->count() }} marcaron</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Colaborador</th>
                        <th>Estado</th>
                        <th class="text-end">Entrada</th>
                        <th class="text-end">Salida</th>
                        <th>Selfie</th>
                        <th class="text-end">Distancia al punto</th>
                        <th>Sucursal</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $r)
                        @php
                            $c = $r['cell'];
                            $a = $r['attendance'];
                            [$outcomeLabel, $outcomeTone] = $outcomes[$r['outcome']];
                            $thumb = $a ? attendance_photo_thumb_url($a, 'in') : null;
                        @endphp
                        <tr>
                            <td>
                                <a href="{{ route('attendance.calendar.person', ['id' => $r['user_id'], 'month' => $filters->month]) }}" class="fw-semibold text-decoration-none">{{ $r['last_name'] }}, {{ $r['first_name'] }}</a>
                                <div class="small text-body-secondary">{{ $r['employee_code'] ?? '—' }}{{ $r['position'] ? ' · ' . $r['position'] : '' }}</div>
                            </td>
                            <td>
                                @if($c['clock_in'] || $c['state'] === 'excused')
                                    <span class="badge rounded-pill text-bg-{{ $c['tone'] }}">{{ $c['label'] }}{{ $c['late_minutes'] ? ' · ' . $c['late_minutes'] . ' min' : '' }}</span>
                                @else
                                    <span class="badge rounded-pill text-bg-{{ $outcomeTone }}">{{ $outcomeLabel }}</span>
                                @endif
                                @if($c['suspicious'])
                                    <span class="badge rounded-pill text-bg-warning" title="Tiene motivos de sospecha"><i class="bi bi-shield-exclamation"></i></span>
                                @endif
                                @if($c['state'] === 'excused' && $c['note'])
                                    <div class="small text-body-secondary">{{ $c['note'] }}</div>
                                @endif
                            </td>
                            <td class="text-end font-monospace">{{ $c['clock_in'] ?? '—' }}</td>
                            <td class="text-end font-monospace">{{ $c['clock_out'] ?? '—' }}</td>
                            <td>
                                @if($thumb && $canOpen)
                                    <button type="button" class="day-photo" onclick="openEvidence({{ $a->id }})" aria-label="Ver selfie y mapa de {{ $r['first_name'] }}">
                                        <img src="{{ $thumb }}" alt="" class="day-thumb" loading="lazy">
                                    </button>
                                @elseif($thumb)
                                    <img src="{{ $thumb }}" alt="Selfie de entrada" class="day-thumb" loading="lazy">
                                @elseif($a && $canOpen)
                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="openEvidence({{ $a->id }})"><i class="bi bi-geo-alt"></i><span class="visually-hidden">Ver mapa</span></button>
                                @else
                                    <span class="day-thumb day-thumb-empty"><i class="bi bi-camera-video-off"></i></span>
                                @endif
                            </td>
                            <td class="text-end text-nowrap">
                                @if($a && $a->clock_in_distance_meters !== null)
                                    <span class="{{ $a->clock_in_within_geofence ? '' : 'text-danger fw-semibold' }}">{{ $a->clock_in_distance_meters }} m</span>
                                    @unless($a->clock_in_within_geofence)
                                        <div class="small text-danger">Fuera del radio</div>
                                    @endunless
                                @else
                                    —
                                @endif
                            </td>
                            <td>{{ $a?->branch?->name ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@empty
    <div class="card border-0">
        <div class="empty-state"><i class="bi bi-calendar-day"></i>Nadie tenía turno, permiso ni marcación este día con estos filtros.</div>
    </div>
@endforelse

@if($page->hasPages())
    <div class="mt-2">{{ $page->links() }}</div>
@endif

@if($canOpen)
    @include('attendance.partials.evidence-modal')
@endif
@endsection

@section('scripts')
@if($isToday)
<script>
// Hoy: recarga cada minuto mientras la pestaña está visible y no hay un visor abierto
setInterval(function () {
    if (!document.hidden && !document.querySelector('.modal.show')) location.reload();
}, 60000);
</script>
@endif
@endsection
