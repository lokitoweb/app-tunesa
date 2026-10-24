@extends('layouts.app')

@section('title', 'Control de faltas')

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h1 class="h3 fw-bold mb-1 text-body">Control de faltas</h1>
        <p class="text-body-secondary mb-0" style="max-width: 760px;">
            Colaboradores que tenían turno programado y no registraron asistencia. Es el mismo cálculo del calendario:
            vacaciones, permisos aprobados y feriados no cuentan como falta.
        </p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="{{ route('attendance.calendar') }}" class="btn btn-outline-secondary"><i class="bi bi-calendar3 me-1"></i>Calendario</a>
        @can('attendance.authorize_offshift')
            <a href="{{ route('attendance.offshift.index') }}" class="btn btn-outline-secondary"><i class="bi bi-calendar-plus me-1"></i>Marcación fuera de turno</a>
        @endcan
    </div>
</div>

<form method="GET" action="{{ route('attendance.absences') }}" class="card border-0 shadow-sm mb-4"
      data-ajax-filter="#absences-results" aria-label="Filtros de faltas">
    <div class="card-body p-3">
        <div class="row g-3 align-items-end">
            <div class="col-6 col-md-3 col-xl-2">
                <label for="from" class="form-label small fw-semibold mb-1">Desde</label>
                <input type="date" id="from" name="from" value="{{ $filters['from'] }}" data-default="{{ $defaults['from'] }}" max="{{ $defaults['to'] }}" class="form-control" data-autosubmit>
            </div>
            <div class="col-6 col-md-3 col-xl-2">
                <label for="to" class="form-label small fw-semibold mb-1">Hasta</label>
                <input type="date" id="to" name="to" value="{{ $filters['to'] }}" data-default="{{ $defaults['to'] }}" max="{{ $defaults['to'] }}" class="form-control" data-autosubmit>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
                <label for="branch_id" class="form-label small fw-semibold mb-1">Sucursal</label>
                <div class="pretty-select {{ $filters['branch_id'] ? 'is-active' : '' }}">
                    <i class="bi bi-geo-alt pretty-select-icon" aria-hidden="true"></i>
                    <select id="branch_id" name="branch_id" class="form-select" data-autosubmit>
                        <option value="">Todas las sucursales</option>
                        @foreach($branches as $b)
                            <option value="{{ $b->id }}" @selected((int) $filters['branch_id'] === $b->id)>{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="col-12 col-md-8 col-xl-3">
                <label for="q" class="form-label small fw-semibold mb-1">Buscar</label>
                <div class="input-group">
                    <span class="input-group-text bg-transparent"><i class="bi bi-search"></i></span>
                    <input type="search" id="q" name="q" value="{{ $filters['q'] }}" class="form-control" placeholder="Nombre, apellido o código">
                </div>
            </div>
            <div class="col-12 col-md-4 col-xl-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary text-white flex-grow-1"><i class="bi bi-funnel me-1"></i>Filtrar</button>
                <a href="{{ route('attendance.absences') }}" class="btn btn-outline-secondary" title="Limpiar filtros" aria-label="Limpiar filtros" data-ajax-reset><i class="bi bi-x-lg"></i></a>
            </div>
        </div>
    </div>
</form>

<div id="absences-results" aria-live="polite">
    <div class="row g-3 mb-3">
        <div class="col-6 col-md-4">
            <div class="card border-0 shadow-sm h-100"><div class="card-body d-flex align-items-center gap-3">
                <span class="kpi-icon bg-danger-subtle text-danger-emphasis"><i class="bi bi-person-x"></i></span>
                <div><div class="small text-body-secondary">Faltas en el rango</div><div class="fs-4 fw-bold">{{ $total }}</div></div>
            </div></div>
        </div>
        <div class="col-6 col-md-4">
            <div class="card border-0 shadow-sm h-100"><div class="card-body d-flex align-items-center gap-3">
                <span class="kpi-icon bg-warning-subtle text-warning-emphasis"><i class="bi bi-people"></i></span>
                <div><div class="small text-body-secondary">Personas con faltas</div><div class="fs-4 fw-bold">{{ $people }}</div></div>
            </div></div>
        </div>
        <div class="col-12 col-md-4">
            <div class="card border-0 shadow-sm h-100"><div class="card-body d-flex align-items-center gap-3">
                <span class="kpi-icon bg-primary-subtle text-primary-emphasis"><i class="bi bi-calendar-range"></i></span>
                <div><div class="small text-body-secondary">Periodo</div>
                    <div class="fw-semibold">{{ \Carbon\Carbon::parse($filters['from'])->format('d/m/Y') }} – {{ \Carbon\Carbon::parse($filters['to'])->format('d/m/Y') }}</div></div>
            </div></div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th scope="col" class="ps-4">Colaborador</th>
                        <th scope="col">Fecha</th>
                        <th scope="col">Turno</th>
                        <th scope="col">Estado</th>
                        <th scope="col" class="text-end pe-4"><span class="visually-hidden">Acciones</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($absences as $a)
                        <tr>
                            <td class="ps-4">
                                <div class="fw-semibold">{{ $a['name'] }}</div>
                                <div class="small text-body-secondary">{{ $a['position'] ?: ($a['employee_code'] ?? '') }}</div>
                            </td>
                            <td class="text-nowrap">{{ \Carbon\Carbon::parse($a['date'])->locale('es')->isoFormat('ddd D MMM YYYY') }}</td>
                            <td class="small">{{ $a['shift'] ?? '—' }}@if($a['shift_start']) <span class="text-body-secondary">· {{ $a['shift_start'] }}</span>@endif</td>
                            <td><span class="badge rounded-pill bg-danger-subtle text-danger-emphasis border border-danger-subtle">Falta</span></td>
                            <td class="text-end pe-4 text-nowrap">
                                @if($canEdit)
                                    <a href="{{ route('attendance.manual.edit', ['id' => $a['user_id'], 'date' => $a['date']]) }}" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-pencil-square me-1"></i>Regularizar
                                    </a>
                                @endif
                                <a href="{{ route('attendance.calendar.person', ['id' => $a['user_id'], 'month' => substr($a['date'], 0, 7)]) }}" class="btn btn-sm btn-outline-secondary" title="Ver su mes en el calendario" aria-label="Ver calendario de {{ $a['name'] }}">
                                    <i class="bi bi-calendar3"></i>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-body-secondary py-5">
                            <i class="bi bi-emoji-smile d-block fs-3 mb-2"></i>Sin faltas en este periodo.
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($absences->hasPages())
            <div class="card-footer bg-transparent border-0 px-4">{{ $absences->links() }}</div>
        @endif
    </div>
</div>
@endsection
