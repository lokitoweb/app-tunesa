@extends('layouts.app')

@section('title', 'Turnos y horarios')

@section('content')
@php $days = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb']; @endphp

<div class="page-header">
    <div>
        <h1>Turnos y horarios</h1>
        <p>Turnos fijos y rotativos, tolerancia de tardanza y asignación de colaboradores.</p>
    </div>
    <a href="{{ route('shifts.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Nuevo turno</a>
</div>

<div class="card border-0 mb-4">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Turno</th>
                    <th>Tipo</th>
                    <th>Horario</th>
                    <th>Días / ciclo</th>
                    <th>Tolerancia</th>
                    <th>Asignados</th>
                    <th>Estado</th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody>
                @forelse($shifts as $shift)
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <span class="rounded-circle d-inline-block flex-shrink-0" style="width: 10px; height: 10px; background: {{ $shift->color }}"></span>
                                <div>
                                    <div class="fw-semibold">{{ $shift->name }}</div>
                                    @if($shift->code)<div class="small text-body-secondary">{{ $shift->code }}</div>@endif
                                </div>
                            </div>
                         @include('partials.tenant-badge', ['tenantId' => $shift->tenant_id])</td>
                        <td><span class="badge text-bg-light border">{{ $shift->isRotating() ? 'Rotativo' : 'Fijo' }}</span></td>
                        <td class="text-nowrap">
                            {{ substr($shift->start_time, 0, 5) }} – {{ substr($shift->end_time, 0, 5) }}
                            @if($shift->crosses_midnight)<span class="small text-body-secondary">(+1 día)</span>@endif
                        </td>
                        <td>
                            @if($shift->isRotating())
                                {{ $shift->rotation_work_days }} × {{ $shift->rotation_rest_days }}
                            @else
                                {{ collect($shift->working_days ?? [])->map(fn ($d) => $days[$d] ?? $d)->implode(', ') }}
                            @endif
                        </td>
                        <td>{{ $shift->grace_period_minutes }} min</td>
                        <td>{{ $shift->employees_count }}</td>
                        <td>
                            <span class="badge rounded-pill {{ $shift->is_active ? 'bg-success-subtle text-success-emphasis' : 'bg-secondary-subtle text-secondary-emphasis' }}">
                                {{ $shift->is_active ? 'Activo' : 'Inactivo' }}
                            </span>
                        </td>
                        <td class="text-end text-nowrap">
                            <a href="{{ route('shifts.edit', $shift->id) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i> Editar</a>
                            <form method="POST" action="{{ route('shifts.destroy', $shift->id) }}" class="d-inline" onsubmit="return confirm(@js('¿Eliminar el turno ' . $shift->name . '?'))">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger" aria-label="Eliminar {{ $shift->name }}"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8"><div class="empty-state"><i class="bi bi-calendar-x"></i>Aún no hay turnos. Crea el primero.</div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="row g-3">
    @can('shifts.assign')
    <div class="col-12 col-xl-5">
        <div class="card border-0 h-100">
            <div class="card-header border-0 pt-3"><h2 class="h6 fw-semibold mb-0">Asignar turno</h2></div>
            <div class="card-body">
                <form method="POST" action="{{ route('shifts.assign') }}">
                    @csrf
                    <div class="mb-3">
                        <label for="assign_shift" class="form-label">Turno</label>
                        <select id="assign_shift" name="shift_id" class="form-select" required>
                            @foreach($shifts->where('is_active', true) as $shift)
                                <option value="{{ $shift->id }}">{{ $shift->name }} ({{ substr($shift->start_time, 0, 5) }}–{{ substr($shift->end_time, 0, 5) }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="assign_branch" class="form-label">Sucursal</label>
                        <select id="assign_branch" name="branch_id" class="form-select" required>
                            @foreach($branches as $branch)
                                <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="assign_users" class="form-label">Colaboradores</label>
                        <select id="assign_users" name="user_ids[]" class="form-select" multiple size="6" required aria-describedby="assign_users_help">
                            @foreach($employees as $e)
                                <option value="{{ $e->id }}">{{ $e->last_name }}, {{ $e->first_name }} {{ $e->employee_code ? "· {$e->employee_code}" : '' }}</option>
                            @endforeach
                        </select>
                        <div id="assign_users_help" class="form-text">Mantén Ctrl (o Cmd) para elegir varios.</div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-sm-4">
                            <label for="effective_from" class="form-label small">Desde</label>
                            <input type="date" id="effective_from" name="effective_from" class="form-control" value="{{ now()->toDateString() }}" required>
                        </div>
                        <div class="col-sm-4">
                            <label for="effective_until" class="form-label small">Hasta (opcional)</label>
                            <input type="date" id="effective_until" name="effective_until" class="form-control">
                        </div>
                        <div class="col-sm-4">
                            <label for="rotation_anchor_date" class="form-label small">Inicio de ciclo</label>
                            <input type="date" id="rotation_anchor_date" name="rotation_anchor_date" class="form-control">
                        </div>
                    </div>
                    <input type="hidden" name="replace_current" value="1">
                    <button class="btn btn-primary w-100">Asignar</button>
                    <div class="form-text">La asignación vigente de cada colaborador se cierra el día anterior.</div>
                </form>
            </div>
        </div>
    </div>
    @endcan

    <div class="col-12 col-xl-7">
        <div class="card border-0 h-100">
            <div class="card-header border-0 pt-3"><h2 class="h6 fw-semibold mb-0">Asignaciones vigentes</h2></div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Colaborador</th><th>Turno</th><th>Sucursal</th><th>Vigencia</th><th></th></tr></thead>
                    <tbody>
                        @forelse($assignments as $a)
                            <tr>
                                <td class="fw-semibold">{{ $a->user?->full_name }} @include('partials.tenant-badge', ['tenantId' => $a->tenant_id])</td>
                                <td>
                                    <span class="rounded-circle d-inline-block me-1" style="width: 8px; height: 8px; background: {{ $a->shift?->color }}"></span>{{ $a->shift?->name }}
                                </td>
                                <td>{{ $a->branch?->name }}</td>
                                <td class="text-nowrap small">{{ $a->effective_from?->format('d/m/Y') }} → {{ $a->effective_until?->format('d/m/Y') ?? 'indefinido' }}</td>
                                <td class="text-end">
                                    @can('shifts.assign')
                                    <form method="POST" action="{{ route('shifts.unassign', $a->id) }}" class="d-inline" onsubmit="return confirm('¿Finalizar esta asignación hoy?')">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-sm btn-outline-secondary">Finalizar</button>
                                    </form>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5"><div class="empty-state">Sin asignaciones vigentes.</div></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($assignments->hasPages())
                <div class="card-footer bg-transparent">{{ $assignments->links() }}</div>
            @endif
        </div>
    </div>
</div>
@endsection
