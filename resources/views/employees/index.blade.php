@extends('layouts.app')

@section('title', 'Gestión de Colaboradores')

@section('content')
<div style="margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1 style="font-size: 1.75rem; font-weight: 800; color: #fff; margin-bottom: 0.25rem;">Directorio de Personal & Colaboradores</h1>
        <p style="color: var(--text-secondary); font-size: 0.9rem;">Asignación de sucursales autorizadas, horarios de trabajo y control de accesos.</p>
    </div>
    <div>
        <a href="{{ route('employees.create') }}" style="background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%); color: #fff; text-decoration: none; padding: 0.65rem 1.25rem; border-radius: 10px; font-weight: 600; font-size: 0.9rem; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 4px 15px rgba(99,102,241,0.35);">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Nuevo Colaborador
        </a>
    </div>
</div>

<!-- FILTROS -->
<div style="background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.25rem; margin-bottom: 1.5rem;">
    <form method="GET" action="{{ route('employees.index') }}" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)) auto; gap: 1rem; align-items: flex-end;">
        <div>
            <label style="display: block; font-size: 0.8rem; color: #94a3b8; margin-bottom: 0.4rem;">Buscar:</label>
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Nombre, email, código..." class="form-control" style="background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.6rem; border-radius: 8px; width: 100%;">
        </div>

        <div>
            <label style="display: block; font-size: 0.8rem; color: #94a3b8; margin-bottom: 0.4rem;">Sucursal Asignada:</label>
            <select name="branch_id" class="form-control" style="background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.6rem; border-radius: 8px; width: 100%;">
                <option value="">Todas las sucursales</option>
                @foreach($branches as $b)
                    <option value="{{ $b->id }}" {{ request('branch_id') == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label style="display: block; font-size: 0.8rem; color: #94a3b8; margin-bottom: 0.4rem;">Rol:</label>
            <select name="role" class="form-control" style="background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.6rem; border-radius: 8px; width: 100%;">
                <option value="">Todos los roles</option>
                <option value="employee" {{ request('role') == 'employee' ? 'selected' : '' }}>Empleado Móvil</option>
                <option value="supervisor" {{ request('role') == 'supervisor' ? 'selected' : '' }}>Supervisor</option>
                <option value="hr_manager" {{ request('role') == 'hr_manager' ? 'selected' : '' }}>RRHH Manager</option>
                <option value="company_admin" {{ request('role') == 'company_admin' ? 'selected' : '' }}>Administrador</option>
            </select>
        </div>

        <div>
            <label style="display: block; font-size: 0.8rem; color: #94a3b8; margin-bottom: 0.4rem;">Estado:</label>
            <select name="status" class="form-control" style="background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.6rem; border-radius: 8px; width: 100%;">
                <option value="">Todos</option>
                <option value="active" {{ request('status') == 'active' ? 'selected' : '' }}>Activo</option>
                <option value="inactive" {{ request('status') == 'inactive' ? 'selected' : '' }}>Inactivo</option>
            </select>
        </div>

        <div>
            <button type="submit" style="background: #6366f1; border: none; color: #fff; padding: 0.65rem 1.25rem; border-radius: 8px; font-weight: 600; cursor: pointer;">
                Filtrar
            </button>
        </div>
    </form>
</div>

<!-- TABLA DE EMPLEADOS -->
<div style="background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-lg); overflow: hidden;">
    <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 0.88rem;">
            <thead>
                <tr style="border-bottom: 1px solid var(--border); background: rgba(0,0,0,0.2); color: #94a3b8;">
                    <th style="padding: 1rem 1.25rem;">Colaborador</th>
                    <th style="padding: 1rem;">Código & Documento</th>
                    <th style="padding: 1rem;">Rol en Sistema</th>
                    <th style="padding: 1rem;">Sucursales Autorizadas</th>
                    <th style="padding: 1rem;">Turno Activo</th>
                    <th style="padding: 1rem;">Estado</th>
                    <th style="padding: 1rem; text-align: center;">Acciones</th>
                </tr>
            </thead>
            <tbody>
                @forelse($employees as $emp)
                    <tr style="border-bottom: 1px solid rgba(255,255,255,0.04); transition: background 0.2s;">
                        <td style="padding: 1rem 1.25rem;">
                            <div style="display: flex; align-items: center; gap: 10px;">
                                @if($emp->avatar_path)
                                    <img src="{{ asset('storage/' . $emp->avatar_path) }}" alt="{{ $emp->first_name }}" style="width: 38px; height: 38px; border-radius: 50%; object-fit: cover;">
                                @else
                                    <div style="width: 38px; height: 38px; border-radius: 50%; background: #312e81; display: flex; align-items: center; justify-content: center; font-weight: 700; color: #c7d2fe; font-size: 0.85rem;">
                                        {{ substr($emp->first_name, 0, 1) }}{{ substr($emp->last_name, 0, 1) }}
                                    </div>
                                @endif
                                <div>
                                    <div style="font-weight: 600; color: #fff;">{{ $emp->first_name }} {{ $emp->last_name }}</div>
                                    <div style="font-size: 0.75rem; color: #94a3b8;">{{ $emp->email }} • {{ $emp->position ?? 'Sin cargo' }}</div>
                                </div>
                            </div>
                        </td>
                        <td style="padding: 1rem; color: #cbd5e1;">
                            <div style="font-weight: 600; color: #fff;">{{ $emp->employee_code ?? 'EMP-' . $emp->id }}</div>
                            <div style="font-size: 0.75rem; color: #94a3b8;">{{ $emp->document_type }}: {{ $emp->document_number }}</div>
                        </td>
                        <td style="padding: 1rem;">
                            <span style="padding: 3px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: 600; background: rgba(99,102,241,0.15); color: #818cf8;">
                                {{ str_replace('_', ' ', ucfirst($emp->role)) }}
                            </span>
                        </td>
                        <td style="padding: 1rem;">
                            <div style="display: flex; flex-wrap: wrap; gap: 4px;">
                                @forelse($emp->branches as $b)
                                    <span style="font-size: 0.72rem; padding: 2px 6px; border-radius: 4px; background: rgba(255,255,255,0.06); color: #cbd5e1; {{ $b->pivot->is_primary ? 'border: 1px solid #6366f1; color: #818cf8;' : '' }}">
                                        {{ $b->name }} {{ $b->pivot->is_primary ? '★' : '' }}
                                    </span>
                                @empty
                                    <span style="font-size: 0.75rem; color: #ef4444;">Sin sucursales</span>
                                @endforelse
                            </div>
                        </td>
                        <td style="padding: 1rem; color: #cbd5e1; font-size: 0.85rem;">
                            @php
                                $activeShift = $emp->employeeShifts->where('is_active', true)->first();
                            @endphp
                            {{ $activeShift && $activeShift->shift ? $activeShift->shift->name : 'No asignado' }}
                        </td>
                        <td style="padding: 1rem;">
                            <span style="padding: 3px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: 600; {{ $emp->status === 'active' ? 'background: rgba(16,185,129,0.15); color: #10b981;' : 'background: rgba(239,68,68,0.15); color: #ef4444;' }}">
                                {{ $emp->status === 'active' ? 'Activo' : 'Inactivo' }}
                            </span>
                        </td>
                        <td style="padding: 1rem; text-align: center;">
                            <div style="display: flex; align-items: center; justify-content: center; gap: 8px;">
                                <a href="{{ route('employees.edit', $emp->id) }}" style="padding: 6px; border-radius: 6px; background: rgba(255,255,255,0.05); color: #cbd5e1; text-decoration: none;" title="Editar">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/></svg>
                                </a>

                                @can('profiles.manage')
                                <a href="{{ route('employees.file', $emp->id) }}" style="padding: 6px; border-radius: 6px; background: rgba(16,185,129,0.1); color: #6ee7b7; display: inline-flex;" title="Legajo y ficha laboral">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h6l2 2h8a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"/></svg>
                                </a>
                                @endcan

                                @if($emp->isLocked())
                                <form method="POST" action="{{ route('employees.unlock', $emp->id) }}" style="display: inline;">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" style="padding: 6px; border-radius: 6px; border: none; cursor: pointer; background: rgba(245,158,11,0.15); color: #f59e0b;" title="Bloqueado por intentos fallidos hasta {{ $emp->locked_until->format('H:i') }}. Clic para desbloquear.">
                                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                    </button>
                                </form>
                                @endif

                                <form method="POST" action="{{ route('employees.toggle-status', $emp->id) }}" style="display: inline;">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" style="padding: 6px; border-radius: 6px; border: none; cursor: pointer; {{ $emp->status === 'active' ? 'background: rgba(239,68,68,0.1); color: #f87171;' : 'background: rgba(16,185,129,0.1); color: #6ee7b7;' }}" title="{{ $emp->status === 'active' ? 'Desactivar' : 'Activar' }}">
                                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="m4.93 4.93 14.14 14.14"/></svg>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" style="padding: 3rem; text-align: center; color: #64748b;">
                            No se encontraron colaboradores registrados.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($employees->hasPages())
        <div style="padding: 1rem 1.5rem; border-top: 1px solid var(--border);">
            {{ $employees->links() }}
        </div>
    @endif
</div>
@endsection
