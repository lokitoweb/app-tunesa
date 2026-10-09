@extends('layouts.app')

@section('title', 'Turnos y Horarios')

@section('content')
@php $days = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb']; @endphp

<div class="page-head">
    <div>
        <h1>Turnos y Horarios</h1>
        <p>Turnos fijos y rotativos, tolerancia de tardanza y asignación de colaboradores.</p>
    </div>
    <a href="{{ route('shifts.create') }}" class="btn btn-primary">+ Nuevo turno</a>
</div>

@if ($errors->any())
    <div class="card" style="margin-bottom: 1rem; border-color: rgba(239,68,68,0.3);">
        <div class="card-body" style="color: #fca5a5;">{{ $errors->first() }}</div>
    </div>
@endif

<div class="card" style="margin-bottom: 1.5rem;">
    <table class="attendance-live-table">
        <thead>
            <tr>
                <th>Turno</th>
                <th>Tipo</th>
                <th>Horario</th>
                <th>Días / ciclo</th>
                <th>Tolerancia</th>
                <th>Asignados</th>
                <th>Estado</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse($shifts as $shift)
                <tr>
                    <td><span class="swatch" style="background: {{ $shift->color }}"></span><strong style="color:#fff">{{ $shift->name }}</strong> <span class="text-muted">{{ $shift->code }}</span></td>
                    <td>{{ $shift->isRotating() ? 'Rotativo' : 'Fijo' }}</td>
                    <td>{{ substr($shift->start_time, 0, 5) }} – {{ substr($shift->end_time, 0, 5) }}@if($shift->crosses_midnight) <span class="text-muted">(+1 día)</span>@endif</td>
                    <td>
                        @if($shift->isRotating())
                            {{ $shift->rotation_work_days }}x{{ $shift->rotation_rest_days }}
                        @else
                            {{ collect($shift->working_days ?? [])->map(fn ($d) => $days[$d] ?? $d)->implode(', ') }}
                        @endif
                    </td>
                    <td>{{ $shift->grace_period_minutes }} min</td>
                    <td>{{ $shift->employees_count }}</td>
                    <td><span class="badge {{ $shift->is_active ? 'badge-present' : 'badge-draft' }}">{{ $shift->is_active ? 'Activo' : 'Inactivo' }}</span></td>
                    <td style="text-align: right; white-space: nowrap;">
                        <a href="{{ route('shifts.edit', $shift->id) }}" class="btn btn-ghost btn-sm">Editar</a>
                        <form method="POST" action="{{ route('shifts.destroy', $shift->id) }}" class="inline-form" onsubmit="return confirm('¿Eliminar el turno {{ $shift->name }}?')">
                            @csrf @method('DELETE')
                            <button class="btn btn-danger btn-sm">Eliminar</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-muted">Aún no hay turnos. Crea el primero.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="grid-2">
    @can('shifts.assign')
    <div class="card">
        <div class="card-header"><div class="card-title">Asignar turno</div></div>
        <div class="card-body">
            <form method="POST" action="{{ route('shifts.assign') }}">
                @csrf
                <div class="form-group">
                    <label class="form-label">Turno</label>
                    <select name="shift_id" class="form-control" required>
                        @foreach($shifts->where('is_active', true) as $shift)
                            <option value="{{ $shift->id }}">{{ $shift->name }} ({{ substr($shift->start_time, 0, 5) }}–{{ substr($shift->end_time, 0, 5) }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Sucursal</label>
                    <select name="branch_id" class="form-control" required>
                        @foreach($branches as $branch)
                            <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Colaboradores (Ctrl/Cmd para varios)</label>
                    <select name="user_ids[]" class="form-control" multiple size="6" required>
                        @foreach($employees as $e)
                            <option value="{{ $e->id }}">{{ $e->last_name }}, {{ $e->first_name }} {{ $e->employee_code ? "· {$e->employee_code}" : '' }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="grid-3">
                    <div class="form-group">
                        <label class="form-label">Desde</label>
                        <input type="date" name="effective_from" class="form-control" value="{{ now()->toDateString() }}" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Hasta (opcional)</label>
                        <input type="date" name="effective_until" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Inicio de ciclo (rotativos)</label>
                        <input type="date" name="rotation_anchor_date" class="form-control">
                    </div>
                </div>
                <input type="hidden" name="replace_current" value="1">
                <button class="btn btn-primary">Asignar</button>
                <p class="text-muted" style="font-size: 12px; margin-top: 8px;">La asignación vigente de cada colaborador se cierra el día anterior.</p>
            </form>
        </div>
    </div>
    @endcan

    <div class="card">
        <div class="card-header"><div class="card-title">Asignaciones vigentes</div></div>
        <table class="attendance-live-table">
            <thead><tr><th>Colaborador</th><th>Turno</th><th>Sucursal</th><th>Vigencia</th><th></th></tr></thead>
            <tbody>
                @forelse($assignments as $a)
                    <tr>
                        <td>{{ $a->user?->full_name }}</td>
                        <td><span class="swatch" style="background: {{ $a->shift?->color }}"></span>{{ $a->shift?->name }}</td>
                        <td>{{ $a->branch?->name }}</td>
                        <td>{{ $a->effective_from?->format('d/m/Y') }} → {{ $a->effective_until?->format('d/m/Y') ?? 'indefinido' }}</td>
                        <td style="text-align: right;">
                            @can('shifts.assign')
                            <form method="POST" action="{{ route('shifts.unassign', $a->id) }}" class="inline-form" onsubmit="return confirm('¿Finalizar esta asignación hoy?')">
                                @csrf @method('DELETE')
                                <button class="btn btn-ghost btn-sm">Finalizar</button>
                            </form>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-muted">Sin asignaciones vigentes.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="card-body">{{ $assignments->links() }}</div>
    </div>
</div>
@endsection
