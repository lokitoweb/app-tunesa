@extends('layouts.app')

@section('title', 'Colaboradores')

@section('content')
@php
    $roleLabels = \App\Support\Labels::options('user_role');
@endphp
<div class="page-header">
    <div>
        <h1>Colaboradores</h1>
        <p>Sucursales autorizadas, turnos y accesos de cada persona.</p>
    </div>
    <a href="{{ route('employees.create') }}" class="btn btn-primary"><i class="bi bi-person-plus me-1"></i>Nuevo colaborador</a>
</div>

<div class="card border-0 mb-4">
    <div class="card-body">
        <form method="GET" action="{{ route('employees.index') }}" class="row g-3 align-items-end">
            <div class="col-12 col-md-4">
                <label for="search" class="form-label small">Buscar</label>
                <input type="search" id="search" name="search" value="{{ request('search') }}" placeholder="Nombre, correo o código" class="form-control">
            </div>
            <div class="col-6 col-md-3">
                <label for="branch_id" class="form-label small">Sucursal</label>
                <select id="branch_id" name="branch_id" class="form-select">
                    <option value="">Todas</option>
                    @foreach($branches as $b)
                        <option value="{{ $b->id }}" @selected(request('branch_id') == $b->id)>{{ $b->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label for="role" class="form-label small">Rol</label>
                <select id="role" name="role" class="form-select">
                    <option value="">Todos</option>
                    @foreach(['employee', 'supervisor', 'hr_manager', 'company_admin'] as $r)
                        <option value="{{ $r }}" @selected(request('role') == $r)>{{ $roleLabels[$r] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label for="status" class="form-label small">Estado</label>
                <select id="status" name="status" class="form-select">
                    <option value="">Todos</option>
                    <option value="active" @selected(request('status') == 'active')>Activo</option>
                    <option value="inactive" @selected(request('status') == 'inactive')>Inactivo</option>
                </select>
            </div>
            <div class="col-6 col-md-1 d-grid">
                <button type="submit" class="btn btn-primary" aria-label="Filtrar"><i class="bi bi-funnel"></i></button>
            </div>
        </form>
    </div>
</div>

<div class="card border-0">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Colaborador</th>
                    <th>Código y documento</th>
                    <th>Rol</th>
                    <th>Sucursales</th>
                    <th>Turno</th>
                    <th>Estado</th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody>
                @forelse($employees as $emp)
                    @php $activeShift = $emp->employeeShifts->where('is_active', true)->first(); @endphp
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                @if($emp->avatar_path)
                                    <img src="{{ asset('storage/' . $emp->avatar_path) }}" alt="" class="avatar">
                                @else
                                    <span class="avatar">{{ mb_substr($emp->first_name, 0, 1) }}{{ mb_substr($emp->last_name, 0, 1) }}</span>
                                @endif
                                <div class="min-w-0">
                                    <div class="fw-semibold text-truncate">{{ $emp->first_name }} {{ $emp->last_name }}</div>
                                    <div class="small text-body-secondary text-truncate">{{ $emp->email }} · {{ $emp->position ?? 'Sin cargo' }}</div>
                                </div>
                            </div>
                         @include('partials.tenant-badge', ['tenantId' => $emp->tenant_id])</td>
                        <td>
                            <div class="fw-semibold">{{ $emp->employee_code ?? 'EMP-' . $emp->id }}</div>
                            <div class="small text-body-secondary">{{ label('id_document_type', $emp->document_type) }} {{ $emp->document_number }}</div>
                        </td>
                        <td><span class="badge text-bg-light border">{{ label('user_role', $emp->role) }}</span></td>
                        <td>
                            <div class="d-flex flex-wrap gap-1">
                                @forelse($emp->branches as $b)
                                    <span class="badge rounded-pill {{ $b->pivot->is_primary ? 'bg-primary-subtle text-primary-emphasis border border-primary-subtle' : 'text-bg-light border' }}">
                                        {{ $b->name }}@if($b->pivot->is_primary) <i class="bi bi-star-fill" title="Principal"></i>@endif
                                    </span>
                                @empty
                                    <span class="small text-danger">Sin sucursales</span>
                                @endforelse
                            </div>
                        </td>
                        <td class="small">{{ $activeShift?->shift?->name ?? 'No asignado' }}</td>
                        <td>
                            <x-status-badge type="user_status" :value="$emp->status" />
                            @if($emp->isLocked())
                                <span class="badge rounded-pill bg-warning-subtle text-warning-emphasis"><i class="bi bi-lock"></i> Bloqueado</span>
                            @endif
                        </td>
                        <td class="text-end text-nowrap">
                            <a href="{{ route('employees.edit', $emp->id) }}" class="btn btn-sm btn-outline-secondary" title="Editar" aria-label="Editar a {{ $emp->first_name }}"><i class="bi bi-pencil"></i></a>
                            @can('profiles.manage')
                                <a href="{{ route('employees.file', $emp->id) }}" class="btn btn-sm btn-outline-primary" title="Legajo y ficha laboral" aria-label="Legajo de {{ $emp->first_name }}"><i class="bi bi-folder2-open"></i></a>
                            @endcan
                            @if($emp->isLocked())
                                <form method="POST" action="{{ route('employees.unlock', $emp->id) }}" class="d-inline">
                                    @csrf @method('PATCH')
                                    <button type="submit" class="btn btn-sm btn-outline-warning" title="Bloqueado por intentos fallidos hasta {{ $emp->locked_until->format('H:i') }}. Desbloquear." aria-label="Desbloquear a {{ $emp->first_name }}"><i class="bi bi-unlock"></i></button>
                                </form>
                            @endif
                            <form method="POST" action="{{ route('employees.toggle-status', $emp->id) }}" class="d-inline">
                                @csrf @method('PATCH')
                                <button type="submit" class="btn btn-sm {{ $emp->status === 'active' ? 'btn-outline-danger' : 'btn-outline-success' }}"
                                        title="{{ $emp->status === 'active' ? 'Desactivar' : 'Activar' }}" aria-label="{{ $emp->status === 'active' ? 'Desactivar' : 'Activar' }} a {{ $emp->first_name }}">
                                    <i class="bi {{ $emp->status === 'active' ? 'bi-person-slash' : 'bi-person-check' }}"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7"><div class="empty-state"><i class="bi bi-people"></i>No se encontraron colaboradores.</div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($employees->hasPages())
        <div class="card-footer bg-transparent">{{ $employees->links() }}</div>
    @endif
</div>
@endsection
