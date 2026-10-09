@extends('layouts.app')

@section('title', 'Reportes Ejecutivos & Nómina')

@section('content')
<div style="margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1 style="font-size: 1.75rem; font-weight: 800; color: #fff; margin-bottom: 0.25rem;">Reportes Ejecutivos de Asistencia & Nómina</h1>
        <p style="color: var(--text-secondary); font-size: 0.9rem;">Consolidación de horas trabajadas, horas extras, puntualidad y cumplimiento geográfico.</p>
    </div>
    <div style="display: flex; gap: 10px;">
        <a href="{{ route('reports.export.excel', request()->all()) }}" style="background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.3); color: #6ee7b7; padding: 0.65rem 1.25rem; border-radius: 10px; text-decoration: none; font-size: 0.88rem; font-weight: 600; display: flex; align-items: center; gap: 8px;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" y2="3"/></svg>
            Descargar en Excel (.CSV)
        </a>
        <a href="{{ route('reports.export.pdf', request()->all()) }}" target="_blank" style="background: rgba(99, 102, 241, 0.15); border: 1px solid rgba(99, 102, 241, 0.3); color: #818cf8; padding: 0.65rem 1.25rem; border-radius: 10px; text-decoration: none; font-size: 0.88rem; font-weight: 600; display: flex; align-items: center; gap: 8px;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14"/></svg>
            Imprimir Reporte PDF
        </a>
    </div>
</div>

<!-- BARRA DE FILTROS -->
<div style="background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.25rem; margin-bottom: 1.5rem;">
    <form method="GET" action="{{ route('reports.index') }}" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)) auto; gap: 1rem; align-items: flex-end;">
        <div>
            <label style="display: block; font-size: 0.8rem; color: #94a3b8; margin-bottom: 0.4rem;">Fecha Desde:</label>
            <input type="date" name="start_date" value="{{ $startDate }}" class="form-control" style="background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.6rem; border-radius: 8px; width: 100%;">
        </div>

        <div>
            <label style="display: block; font-size: 0.8rem; color: #94a3b8; margin-bottom: 0.4rem;">Fecha Hasta:</label>
            <input type="date" name="end_date" value="{{ $endDate }}" class="form-control" style="background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.6rem; border-radius: 8px; width: 100%;">
        </div>

        <div>
            <label style="display: block; font-size: 0.8rem; color: #94a3b8; margin-bottom: 0.4rem;">Sucursal / Obra:</label>
            <select name="branch_id" class="form-control" style="background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.6rem; border-radius: 8px; width: 100%;">
                <option value="">Todas las sucursales</option>
                @foreach($branches as $b)
                    <option value="{{ $b->id }}" {{ $branchId == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label style="display: block; font-size: 0.8rem; color: #94a3b8; margin-bottom: 0.4rem;">Estado:</label>
            <select name="status" class="form-control" style="background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.6rem; border-radius: 8px; width: 100%;">
                <option value="">Todos</option>
                <option value="present" {{ $status == 'present' ? 'selected' : '' }}>Puntual</option>
                <option value="late" {{ $status == 'late' ? 'selected' : '' }}>Tardanza</option>
                <option value="absent" {{ $status == 'absent' ? 'selected' : '' }}>Inasistencia</option>
                <option value="excused" {{ $status == 'excused' ? 'selected' : '' }}>Justificado</option>
            </select>
        </div>

        <div>
            <button type="submit" style="background: #6366f1; border: none; color: #fff; padding: 0.65rem 1.25rem; border-radius: 8px; font-weight: 600; cursor: pointer;">
                Actualizar Reporte
            </button>
        </div>
    </form>
</div>

<!-- RESUMEN ANALÍTICO (KPIS CONSOLIDADOS) -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1.25rem; margin-bottom: 1.5rem;">
    <div style="background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.25rem;">
        <div style="font-size: 0.78rem; color: #94a3b8; margin-bottom: 0.35rem;">REGISTROS TOTALES</div>
        <div style="font-size: 1.85rem; font-weight: 800; color: #fff;">{{ $totalRecords }}</div>
        <div style="font-size: 0.75rem; color: #64748b;">Jornadas auditadas</div>
    </div>

    <div style="background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.25rem;">
        <div style="font-size: 0.78rem; color: #94a3b8; margin-bottom: 0.35rem;">ÍNDICE PUNTUALIDAD</div>
        <div style="font-size: 1.85rem; font-weight: 800; color: #10b981;">{{ $punctualityRate }}%</div>
        <div style="font-size: 0.75rem; color: #64748b;">{{ $onTimeCount }} llegadas a tiempo</div>
    </div>

    <div style="background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.25rem;">
        <div style="font-size: 0.78rem; color: #94a3b8; margin-bottom: 0.35rem;">TARDANZAS / RETARDOS</div>
        <div style="font-size: 1.85rem; font-weight: 800; color: #f59e0b;">{{ $lateCount }}</div>
        <div style="font-size: 0.75rem; color: #64748b;">Fuera de tolerancia</div>
    </div>

    <div style="background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.25rem;">
        <div style="font-size: 0.78rem; color: #94a3b8; margin-bottom: 0.35rem;">HORAS TOTALES TRABAJADAS</div>
        <div style="font-size: 1.85rem; font-weight: 800; color: #60a5fa;">{{ number_format((float)$totalHours, 1) }}h</div>
        <div style="font-size: 0.75rem; color: #64748b;">Efectivas computadas</div>
    </div>

    <div style="background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.25rem;">
        <div style="font-size: 0.78rem; color: #94a3b8; margin-bottom: 0.35rem;">HORAS EXTRAS ACUMULADAS</div>
        <div style="font-size: 1.85rem; font-weight: 800; color: #c084fc;">{{ number_format((float)$overtimeHours, 1) }}h</div>
        <div style="font-size: 0.75rem; color: #64748b;">Para pago de nómina</div>
    </div>
</div>

<!-- PREVISUALIZACIÓN DE DATOS -->
<div style="background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-lg); overflow: hidden;">
    <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center;">
        <h3 style="color: #fff; font-size: 1.05rem; font-weight: 700;">Vista Previa de Registros Consolidados</h3>
        <span style="font-size: 0.8rem; color: #94a3b8;">Mostrando {{ $attendances->count() }} filas de {{ $attendances->total() }}</span>
    </div>

    <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 0.85rem;">
            <thead>
                <tr style="border-bottom: 1px solid var(--border); background: rgba(0,0,0,0.2); color: #94a3b8;">
                    <th style="padding: 0.85rem 1.25rem;">Fecha</th>
                    <th style="padding: 0.85rem;">Colaborador</th>
                    <th style="padding: 0.85rem;">Sucursal</th>
                    <th style="padding: 0.85rem;">Entrada</th>
                    <th style="padding: 0.85rem;">Salida</th>
                    <th style="padding: 0.85rem;">Retardo (min)</th>
                    <th style="padding: 0.85rem;">Horas Trab.</th>
                    <th style="padding: 0.85rem;">Horas Extras</th>
                    <th style="padding: 0.85rem;">Geocerca</th>
                    <th style="padding: 0.85rem;">Estado</th>
                </tr>
            </thead>
            <tbody>
                @forelse($attendances as $att)
                    <tr style="border-bottom: 1px solid rgba(255,255,255,0.04);">
                        <td style="padding: 0.85rem 1.25rem; color: #cbd5e1; white-space: nowrap;">
                            {{ $att->work_date ? $att->work_date->format('Y-m-d') : '--' }}
                        @include('partials.tenant-badge', ['tenantId' => $att->tenant_id])</td>
                        <td style="padding: 0.85rem; color: #fff; font-weight: 600;">
                            {{ $att->user->first_name ?? 'N/A' }} {{ $att->user->last_name ?? '' }}
                        </td>
                        <td style="padding: 0.85rem; color: #94a3b8;">
                            {{ $att->branch->name ?? 'N/A' }}
                        </td>
                        <td style="padding: 0.85rem; color: #cbd5e1;">
                            {{ $att->clock_in_at ? $att->clock_in_at->format('H:i') : '--' }}
                        </td>
                        <td style="padding: 0.85rem; color: #cbd5e1;">
                            {{ $att->clock_out_at ? $att->clock_out_at->format('H:i') : '--' }}
                        </td>
                        <td style="padding: 0.85rem; color: {{ $att->late_minutes > 0 ? '#f59e0b' : '#94a3b8' }};">
                            {{ $att->late_minutes ?? 0 }}m
                        </td>
                        <td style="padding: 0.85rem; color: #fff; font-weight: 600;">
                            {{ number_format((float) ($att->worked_hours ?? 0), 2) }}
                        </td>
                        <td style="padding: 0.85rem; color: #60a5fa; font-weight: 600;">
                            {{ number_format((float) ($att->overtime_hours ?? 0), 2) }}
                        </td>
                        <td style="padding: 0.85rem;">
                            <span style="font-size: 0.72rem; padding: 2px 6px; border-radius: 4px; {{ $att->clock_in_within_geofence ? 'background: rgba(16,185,129,0.15); color: #10b981;' : 'background: rgba(239,68,68,0.15); color: #ef4444;' }}">
                                {{ $att->clock_in_within_geofence ? 'Válida' : 'Fuera' }}
                            </span>
                        </td>
                        <td style="padding: 0.85rem;">
                            <span style="font-size: 0.72rem; padding: 2px 6px; border-radius: 4px;
                                @if($att->status === 'present') background: rgba(16,185,129,0.15); color: #10b981;
                                @elseif($att->status === 'late') background: rgba(245,158,11,0.15); color: #f59e0b;
                                @else background: rgba(99,102,241,0.15); color: #818cf8; @endif">
                                {{ ucfirst($att->status) }}
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10" style="padding: 3rem; text-align: center; color: #64748b;">
                            No se encontraron registros en el periodo seleccionado.
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
