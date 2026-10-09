@extends('layouts.app')

@section('title', 'Reportes')

@section('content')
<div class="page-header">
    <div>
        <h1>Reportes de asistencia y horas</h1>
        <p>Horas trabajadas, horas extra, puntualidad y cumplimiento de geocerca del periodo.</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="{{ route('reports.export.excel', request()->all()) }}" class="btn btn-outline-success">
            <i class="bi bi-file-earmark-spreadsheet me-1"></i> Excel (.csv)
        </a>
        <a href="{{ route('reports.export.pdf', request()->all()) }}" target="_blank" rel="noopener" class="btn btn-outline-primary">
            <i class="bi bi-printer me-1"></i> Imprimir PDF
        </a>
    </div>
</div>

@include('reports.partials.tabs')

{{-- Filtros --}}
<div class="card border-0 mb-4">
    <div class="card-body">
        <form method="GET" action="{{ route('reports.index') }}" class="row g-3 align-items-end">
            <div class="col-6 col-md-3 col-xl-2">
                <label for="start_date" class="form-label small">Desde</label>
                <input type="date" id="start_date" name="start_date" value="{{ $startDate }}" class="form-control">
            </div>
            <div class="col-6 col-md-3 col-xl-2">
                <label for="end_date" class="form-label small">Hasta</label>
                <input type="date" id="end_date" name="end_date" value="{{ $endDate }}" class="form-control">
            </div>
            <div class="col-12 col-md-3 col-xl-3">
                <label for="branch_id" class="form-label small">Sucursal</label>
                <select id="branch_id" name="branch_id" class="form-select">
                    <option value="">Todas las sucursales</option>
                    @foreach($branches as $b)
                        <option value="{{ $b->id }}" @selected($branchId == $b->id)>{{ $b->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12 col-md-3 col-xl-2">
                <label for="status" class="form-label small">Estado</label>
                <select id="status" name="status" class="form-select">
                    <option value="">Todos</option>
                    @foreach(['present' => 'Puntual', 'late' => 'Tardanza', 'absent' => 'Inasistencia', 'excused' => 'Justificado'] as $key => $label)
                        <option value="{{ $key }}" @selected($status == $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12 col-xl-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-funnel me-1"></i> Actualizar</button>
                <a href="{{ route('reports.index') }}" class="btn btn-light border">Limpiar</a>
            </div>
        </form>
    </div>
</div>

{{-- Indicadores del periodo --}}
<div class="row g-3 mb-4">
    @foreach([
        ['Registros', $totalRecords, 'Jornadas auditadas', 'list-check', 'primary'],
        ['Puntualidad', $punctualityRate . '%', $onTimeCount . ' a tiempo', 'check-circle', 'success'],
        ['Tardanzas', $lateCount, 'Fuera de tolerancia', 'alarm', 'warning'],
        ['Horas trabajadas', number_format((float) $totalHours, 1) . ' h', 'Efectivas', 'clock-history', 'info'],
        ['Horas extra', number_format((float) $overtimeHours, 1) . ' h', 'Para planilla', 'plus-circle', 'secondary'],
    ] as [$label, $value, $hint, $icon, $tone])
        <div class="col-6 col-md-4 col-xl">
            <div class="card border-0 h-100 kpi-card">
                <div class="card-body d-flex align-items-center gap-3">
                    <span class="kpi-icon bg-{{ $tone }}-subtle text-{{ $tone }}-emphasis"><i class="bi bi-{{ $icon }}"></i></span>
                    <div class="min-w-0">
                        <div class="kpi-label">{{ $label }}</div>
                        <div class="fs-4 fw-bold">{{ $value }}</div>
                        <div class="small text-body-secondary">{{ $hint }}</div>
                    </div>
                </div>
            </div>
        </div>
    @endforeach
</div>

{{-- Vista previa --}}
<div class="card border-0">
    <div class="card-header border-0 pt-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h2 class="h6 fw-semibold mb-0">Vista previa de registros</h2>
        <span class="small text-body-secondary">Mostrando {{ $attendances->count() }} de {{ $attendances->total() }}</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Colaborador</th>
                    <th>Sucursal</th>
                    <th>Entrada</th>
                    <th>Salida</th>
                    <th class="text-end">Retardo</th>
                    <th class="text-end">Horas</th>
                    <th class="text-end">Extra</th>
                    <th>Geocerca</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody>
                @forelse($attendances as $att)
                    <tr>
                        <td class="text-nowrap">{{ $att->work_date ? $att->work_date->format('d/m/Y') : '—' }}</td>
                        <td>
                            <div class="fw-semibold">{{ $att->user->first_name ?? 'N/A' }} {{ $att->user->last_name ?? '' }}</div>
                            @include('partials.tenant-badge', ['tenantId' => $att->tenant_id])
                        </td>
                        <td class="text-body-secondary">{{ $att->branch->name ?? '—' }}</td>
                        <td>{{ $att->clock_in_at ? $att->clock_in_at->format('H:i') : '—' }}</td>
                        <td>{{ $att->clock_out_at ? $att->clock_out_at->format('H:i') : '—' }}</td>
                        <td class="text-end {{ $att->late_minutes > 0 ? 'text-warning-emphasis fw-semibold' : 'text-body-secondary' }}">{{ $att->late_minutes ?? 0 }} min</td>
                        <td class="text-end fw-semibold">{{ number_format((float) ($att->worked_hours ?? 0), 2) }}</td>
                        <td class="text-end">{{ number_format((float) ($att->overtime_hours ?? 0), 2) }}</td>
                        <td>
                            @include('partials.badge', ['tone' => $att->clock_in_within_geofence ? 'success' : 'danger', 'label' => $att->clock_in_within_geofence ? 'Válida' : 'Fuera'])
                        </td>
                        <td>@include('partials.attendance-status', ['status' => $att->status])</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10" class="empty-state">
                            <i class="bi bi-inbox"></i>
                            No hay registros en el periodo seleccionado.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($attendances->hasPages())
        <div class="card-footer bg-transparent">{{ $attendances->links() }}</div>
    @endif
</div>
@endsection
