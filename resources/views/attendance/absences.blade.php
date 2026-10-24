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
        <a href="{{ route('attendance.calendar') }}" class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1.5 shadow-sm" style="border-radius: 8px; font-weight: 600; padding: 7px 14px; border: 1px solid var(--border-color); background: var(--bg-surface); color: var(--text-body);">
            <i class="bi bi-calendar3"></i><span>Calendario</span>
        </a>
        @can('attendance.authorize_offshift')
            <a href="{{ route('attendance.offshift.index') }}" class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1.5 shadow-sm" style="border-radius: 8px; font-weight: 600; padding: 7px 14px; border: 1px solid var(--border-color); background: var(--bg-surface); color: var(--text-body);">
                <i class="bi bi-calendar-plus"></i><span>Marcación fuera de turno</span>
            </a>
        @endcan
    </div>
</div>

<form method="GET" action="{{ route('attendance.absences') }}" class="card border-0 shadow-sm mb-4"
      data-ajax-filter="#absences-results" aria-label="Filtros de faltas"
      style="border: 1px solid var(--border-color) !important; border-radius: 10px; background: var(--bg-surface);">
    <div class="card-body p-3">
        <div class="row g-3 align-items-end">
            <div class="col-6 col-md-3 col-xl-2">
                <label for="from" class="form-label small fw-semibold mb-1 text-body">Desde</label>
                <div class="position-relative">
                    <input type="date" id="from" name="from" value="{{ $filters['from'] }}" data-default="{{ $defaults['from'] }}" max="{{ $defaults['to'] }}" class="form-control" data-autosubmit style="height: 38px; border-radius: 8px; border-color: var(--border-color); font-size: 0.85rem;">
                </div>
            </div>
            <div class="col-6 col-md-3 col-xl-2">
                <label for="to" class="form-label small fw-semibold mb-1 text-body">Hasta</label>
                <div class="position-relative">
                    <input type="date" id="to" name="to" value="{{ $filters['to'] }}" data-default="{{ $defaults['to'] }}" max="{{ $defaults['to'] }}" class="form-control" data-autosubmit style="height: 38px; border-radius: 8px; border-color: var(--border-color); font-size: 0.85rem;">
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
                <label for="branch_id" class="form-label small fw-semibold mb-1 text-body">Sucursal</label>
                <div class="pretty-select {{ $filters['branch_id'] ? 'is-active' : '' }}" style="border-radius: 8px;">
                    <i class="bi bi-geo-alt pretty-select-icon" aria-hidden="true"></i>
                    <select id="branch_id" name="branch_id" class="form-select" data-autosubmit style="height: 38px; border-radius: 8px; border-color: var(--border-color); font-size: 0.85rem;">
                        <option value="">Todas las sucursales</option>
                        @foreach($branches as $b)
                            <option value="{{ $b->id }}" @selected((int) $filters['branch_id'] === $b->id)>{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="col-12 col-md-8 col-xl-3">
                <label for="q" class="form-label small fw-semibold mb-1 text-body">Buscar</label>
                <div class="position-relative">
                    <i class="bi bi-search position-absolute top-50 translate-middle-y text-secondary" style="left: 0.85rem; pointer-events: none; font-size: 0.85rem;"></i>
                    <input type="search" id="q" name="q" value="{{ $filters['q'] }}" class="form-control" placeholder="Nombre, apellido o código" style="padding-left: 2.35rem; height: 38px; border-radius: 8px; border-color: var(--border-color); font-size: 0.85rem;">
                </div>
            </div>
            <div class="col-12 col-md-4 col-xl-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary text-white flex-grow-1 d-inline-flex align-items-center justify-content-center gap-1.5 shadow-sm" style="height: 38px; border-radius: 8px; font-weight: 600; font-size: 0.85rem;">
                    <i class="bi bi-funnel"></i><span>Filtrar</span>
                </button>
                <a href="{{ route('attendance.absences') }}" class="btn btn-outline-secondary d-inline-flex align-items-center justify-content-center" title="Limpiar filtros" aria-label="Limpiar filtros" data-ajax-reset style="height: 38px; width: 38px; border-radius: 8px; border-color: var(--border-color); flex-shrink: 0;">
                    <i class="bi bi-x-lg"></i>
                </a>
            </div>
        </div>
    </div>
</form>

<div id="absences-results" aria-live="polite">
    <div class="row g-3 mb-3">
        <div class="col-6 col-md-4">
            <div class="card border-0 shadow-sm h-100" style="border: 1px solid var(--border-color) !important; border-radius: 10px; background: var(--bg-surface);">
                <div class="card-body d-flex align-items-center gap-3 p-3">
                    <span class="kpi-icon bg-danger-subtle text-danger-emphasis d-inline-flex align-items-center justify-content-center rounded-3" style="width: 44px; height: 44px; font-size: 1.25rem;"><i class="bi bi-person-x"></i></span>
                    <div>
                        <div class="small text-secondary" style="font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.4px;">Faltas en el rango</div>
                        <div class="fs-4 fw-bold text-body mt-0.5">{{ $total }}</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="card border-0 shadow-sm h-100" style="border: 1px solid var(--border-color) !important; border-radius: 10px; background: var(--bg-surface);">
                <div class="card-body d-flex align-items-center gap-3 p-3">
                    <span class="kpi-icon bg-warning-subtle text-warning-emphasis d-inline-flex align-items-center justify-content-center rounded-3" style="width: 44px; height: 44px; font-size: 1.25rem;"><i class="bi bi-people"></i></span>
                    <div>
                        <div class="small text-secondary" style="font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.4px;">Personas con faltas</div>
                        <div class="fs-4 fw-bold text-body mt-0.5">{{ $people }}</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="card border-0 shadow-sm h-100" style="border: 1px solid var(--border-color) !important; border-radius: 10px; background: var(--bg-surface);">
                <div class="card-body d-flex align-items-center gap-3 p-3">
                    <span class="kpi-icon bg-primary-subtle text-primary-emphasis d-inline-flex align-items-center justify-content-center rounded-3" style="width: 44px; height: 44px; font-size: 1.25rem;"><i class="bi bi-calendar-range"></i></span>
                    <div>
                        <div class="small text-secondary" style="font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.4px;">Periodo</div>
                        <div class="fw-semibold text-body mt-0.5" style="font-size: 0.9rem;">{{ \Carbon\Carbon::parse($filters['from'])->format('d/m/Y') }} – {{ \Carbon\Carbon::parse($filters['to'])->format('d/m/Y') }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm" style="border: 1px solid var(--border-color) !important; border-radius: 10px; background: var(--bg-surface); overflow: hidden;">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead style="background: var(--bg-surface-elevated); border-bottom: 1px solid var(--border-color);">
                    <tr>
                        <th scope="col" class="ps-4 py-3 text-secondary" style="font-size: 0.725rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">Colaborador</th>
                        <th scope="col" class="py-3 text-secondary" style="font-size: 0.725rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">Fecha</th>
                        <th scope="col" class="py-3 text-secondary" style="font-size: 0.725rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">Turno</th>
                        <th scope="col" class="py-3 text-secondary" style="font-size: 0.725rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">Estado</th>
                        <th scope="col" class="text-end pe-4 py-3 text-secondary" style="font-size: 0.725rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;"><span class="visually-hidden">Acciones</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($absences as $a)
                        <tr style="border-bottom: 1px solid var(--border-color);">
                            <td class="ps-4 py-3">
                                <div class="fw-semibold text-body" style="font-size: 0.875rem;">{{ $a['name'] }}</div>
                                <div class="small text-secondary" style="font-size: 0.75rem;">{{ $a['position'] ?: ($a['employee_code'] ?? '') }}</div>
                            </td>
                            <td class="text-nowrap text-body py-3" style="font-size: 0.85rem;">{{ \Carbon\Carbon::parse($a['date'])->locale('es')->isoFormat('ddd D MMM YYYY') }}</td>
                            <td class="small py-3"><span class="text-body fw-medium">{{ $a['shift'] ?? '—' }}</span>@if($a['shift_start']) <span class="text-secondary">· {{ $a['shift_start'] }}</span>@endif</td>
                            <td class="py-3">
                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2.5 py-1" style="border-radius: 6px; font-weight: 600; font-size: 0.75rem;">Falta</span>
                            </td>
                            <td class="text-end pe-4 py-3 text-nowrap">
                                <div class="d-inline-flex align-items-center gap-1.5 justify-content-end">
                                    @if($canEdit)
                                        <a href="{{ route('attendance.manual.edit', ['id' => $a['user_id'], 'date' => $a['date']]) }}" class="btn btn-sm btn-outline-primary shadow-sm" title="Regularizar asistencia de {{ $a['name'] }}" aria-label="Regularizar asistencia" style="border-radius: 8px; width: 34px; height: 34px; display: inline-flex; align-items: center; justify-content: center; padding: 0;">
                                            <i class="bi bi-pencil-square"></i>
                                        </a>
                                    @endif
                                    <a href="{{ route('attendance.calendar.person', ['id' => $a['user_id'], 'month' => substr($a['date'], 0, 7)]) }}" class="btn btn-sm btn-outline-secondary shadow-sm" title="Ver su mes en el calendario" aria-label="Ver calendario de {{ $a['name'] }}" style="border-radius: 8px; width: 34px; height: 34px; display: inline-flex; align-items: center; justify-content: center; padding: 0; border-color: var(--border-color);">
                                        <i class="bi bi-calendar3"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-body-secondary py-5">
                            <i class="bi bi-emoji-smile d-block fs-3 mb-2 text-muted"></i>Sin faltas en este periodo.
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($absences->hasPages())
            <div class="card-footer bg-transparent border-0 px-4 py-3">{{ $absences->links() }}</div>
        @endif
    </div>
</div>
@endsection
