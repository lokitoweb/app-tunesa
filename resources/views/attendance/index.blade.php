@extends('layouts.app')

@section('title', 'Control de Asistencias & GPS')

@section('content')
<div style="margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1 style="font-size: 1.75rem; font-weight: 800; color: #fff; margin-bottom: 0.25rem;">Registro de Asistencias & Auditoría GPS</h1>
        <p style="color: var(--text-secondary); font-size: 0.9rem;">Inspección forense de marcaciones con selfies en tiempo real y cumplimiento de geocercas.</p>
    </div>
    <div style="display: flex; gap: 10px;">
        <a href="{{ route('reports.export.excel', request()->all()) }}" class="btn" style="background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.3); color: #6ee7b7; padding: 0.6rem 1.1rem; border-radius: 10px; text-decoration: none; font-size: 0.85rem; font-weight: 600; display: flex; align-items: center; gap: 6px;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
            Exportar a Excel
        </a>
    </div>
</div>

<!-- BARRA DE FILTROS -->
<div style="background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.25rem; margin-bottom: 1.5rem;">
    <form method="GET" action="{{ route('attendance.index') }}" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)) auto; gap: 1rem; align-items: flex-end;">
        <div>
            <label style="display: block; font-size: 0.8rem; color: #94a3b8; margin-bottom: 0.4rem;">Desde:</label>
            <input type="date" name="start_date" value="{{ $startDate }}" class="form-control" style="background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.6rem; border-radius: 8px; width: 100%;">
        </div>

        <div>
            <label style="display: block; font-size: 0.8rem; color: #94a3b8; margin-bottom: 0.4rem;">Hasta:</label>
            <input type="date" name="end_date" value="{{ $endDate }}" class="form-control" style="background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.6rem; border-radius: 8px; width: 100%;">
        </div>

        <div>
            <label style="display: block; font-size: 0.8rem; color: #94a3b8; margin-bottom: 0.4rem;">Sucursal / Obra:</label>
            <select name="branch_id" class="form-control" style="background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.6rem; border-radius: 8px; width: 100%;">
                <option value="">Todas las sucursales</option>
                @foreach($branches as $b)
                    <option value="{{ $b->id }}" {{ request('branch_id') == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label style="display: block; font-size: 0.8rem; color: #94a3b8; margin-bottom: 0.4rem;">Estado:</label>
            <select name="status" class="form-control" style="background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.6rem; border-radius: 8px; width: 100%;">
                <option value="">Todos los estados</option>
                <option value="present" {{ request('status') == 'present' ? 'selected' : '' }}>Puntual</option>
                <option value="late" {{ request('status') == 'late' ? 'selected' : '' }}>Tardanza</option>
                <option value="absent" {{ request('status') == 'absent' ? 'selected' : '' }}>Ausente</option>
                <option value="excused" {{ request('status') == 'excused' ? 'selected' : '' }}>Justificado</option>
            </select>
        </div>

        <div>
            <label style="display: block; font-size: 0.8rem; color: #94a3b8; margin-bottom: 0.4rem;">Buscar Empleado:</label>
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Nombre o código..." class="form-control" style="background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.6rem; border-radius: 8px; width: 100%;">
        </div>

        <div>
            <button type="submit" style="background: #6366f1; border: none; color: #fff; padding: 0.65rem 1.25rem; border-radius: 8px; font-weight: 600; cursor: pointer;">
                Filtrar
            </button>
        </div>
    </form>
</div>

<!-- TABLA DE MARCADORES -->
<div style="background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-lg); overflow: hidden;">
    <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 0.88rem;">
            <thead>
                <tr style="border-bottom: 1px solid var(--border); background: rgba(0,0,0,0.2); color: #94a3b8;">
                    <th style="padding: 1rem 1.25rem;">Colaborador</th>
                    <th style="padding: 1rem;">Fecha</th>
                    <th style="padding: 1rem;">Selfie Entrada</th>
                    <th style="padding: 1rem;">Hora Entrada</th>
                    <th style="padding: 1rem;">GPS & Distancia</th>
                    <th style="padding: 1rem;">Hora Salida</th>
                    <th style="padding: 1rem;">Total Horas</th>
                    <th style="padding: 1rem;">Estado</th>
                    <th style="padding: 1rem; text-align: center;">Acciones</th>
                </tr>
            </thead>
            <tbody>
                @forelse($attendances as $att)
                    <tr style="border-bottom: 1px solid rgba(255,255,255,0.04); transition: background 0.2s;">
                        <td style="padding: 1rem 1.25rem;">
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <div style="width: 36px; height: 36px; border-radius: 50%; background: #1e1b4b; display: flex; align-items: center; justify-content: center; font-weight: 700; color: #818cf8; font-size: 0.8rem;">
                                    {{ substr($att->user->first_name ?? 'U', 0, 1) }}{{ substr($att->user->last_name ?? 'A', 0, 1) }}
                                </div>
                                <div>
                                    <div style="font-weight: 600; color: #fff;">{{ $att->user->first_name ?? 'N/A' }} {{ $att->user->last_name ?? '' }}</div>
                                    <div style="font-size: 0.75rem; color: #94a3b8;">{{ $att->user->employee_code ?? 'EMP' }} • {{ $att->branch->name ?? 'Sucursal' }}</div>
                                </div>
                            </div>
                        </td>
                        <td style="padding: 1rem; color: #cbd5e1; white-space: nowrap;">
                            {{ $att->work_date ? $att->work_date->format('d/m/Y') : '--' }}
                        </td>
                        <td style="padding: 1rem;">
                            @if($att->clock_in_photo)
                                <img src="{{ asset('storage/' . $att->clock_in_photo) }}" alt="Selfie" style="width: 38px; height: 38px; border-radius: 8px; object-fit: cover; border: 2px solid {{ $att->clock_in_within_geofence ? '#10b981' : '#ef4444' }}; cursor: pointer;" onclick="openPhotoModal('{{ asset('storage/' . $att->clock_in_photo) }}', '{{ $att->user->first_name }} {{ $att->user->last_name }}', 'Hora: {{ $att->clock_in_at ? $att->clock_in_at->format('H:i:s') : '' }} | Distancia: {{ $att->clock_in_distance_meters }}m')">
                            @else
                                <span style="font-size: 0.75rem; color: #64748b;">Sin foto</span>
                            @endif
                        </td>
                        <td style="padding: 1rem; color: #fff; font-weight: 600;">
                            {{ $att->clock_in_at ? $att->clock_in_at->format('H:i:s') : '--:--:--' }}
                            @if($att->late_minutes > 0)
                                <div style="font-size: 0.72rem; color: #f59e0b; font-weight: normal;">+{{ $att->late_minutes }}m retardo</div>
                            @endif
                        </td>
                        <td style="padding: 1rem;">
                            @if($att->clock_in_distance_meters !== null)
                                <div style="display: flex; align-items: center; gap: 6px;">
                                    <span style="font-size: 0.8rem; font-weight: 700; color: {{ $att->clock_in_within_geofence ? '#10b981' : '#ef4444' }};">
                                        {{ $att->clock_in_distance_meters }}m
                                    </span>
                                    <span style="font-size: 0.72rem; padding: 2px 6px; border-radius: 4px; {{ $att->clock_in_within_geofence ? 'background: rgba(16,185,129,0.15); color: #10b981;' : 'background: rgba(239,68,68,0.15); color: #ef4444;' }}">
                                        {{ $att->clock_in_within_geofence ? 'En Geocerca' : 'Fuera' }}
                                    </span>
                                </div>
                            @else
                                <span style="color: #64748b; font-size: 0.8rem;">No registrado</span>
                            @endif
                        </td>
                        <td style="padding: 1rem; color: #cbd5e1;">
                            {{ $att->clock_out_at ? $att->clock_out_at->format('H:i:s') : '--:--:--' }}
                        </td>
                        <td style="padding: 1rem; color: #fff; font-weight: 600;">
                            {{ number_format((float) ($att->worked_hours ?? 0), 2) }} hrs
                        </td>
                        <td style="padding: 1rem;">
                            <span style="padding: 3px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: 600;
                                @if($att->status === 'present') background: rgba(16,185,129,0.15); color: #10b981;
                                @elseif($att->status === 'late') background: rgba(245,158,11,0.15); color: #f59e0b;
                                @elseif($att->status === 'excused') background: rgba(59,130,246,0.15); color: #60a5fa;
                                @else background: rgba(239,68,68,0.15); color: #f87171; @endif">
                                {{ ucfirst($att->status) }}
                            </span>
                        </td>
                        <td style="padding: 1rem; text-align: center;">
                            <a href="{{ route('attendance.show', $att->id) }}" style="display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 8px; background: rgba(99,102,241,0.15); color: #818cf8; text-decoration: none;" title="Ver auditoría completa">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" style="padding: 3rem; text-align: center; color: #64748b;">
                            No se encontraron registros de asistencia para los filtros seleccionados.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($attendances->hasPages())
        <div style="padding: 1rem 1.5rem; border-top: 1px solid var(--border);">
            {{ $attendances->links() }}
        </div>
    @endif
</div>
@endsection
