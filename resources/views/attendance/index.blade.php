@extends('layouts.app')

@section('title', request('status') === 'absent' ? 'Control de faltas' : 'Marcaciones y GPS')

@section('content')
<div class="page-header">
    <div>
        <h1>{{ request('status') === 'absent' ? 'Control de faltas' : 'Marcaciones y GPS' }}</h1>
        <p>Revisa cada marcación con su selfie, hora, sucursal y validación de geocerca.</p>
    </div>
    <a href="{{ route('reports.export.excel', request()->all()) }}" class="btn btn-outline-success">
        <i class="bi bi-file-earmark-spreadsheet me-1"></i> Exportar a Excel
    </a>
</div>

{{-- Resumen del rango --}}
@isset($summary)
<div class="row g-3 mb-4">
    @foreach([
        ['Marcaciones', $summary['total'] ?? 0, 'list-check', 'primary'],
        ['Puntuales', $summary['on_time'] ?? 0, 'check-circle', 'success'],
        ['Tardanzas', $summary['late'] ?? 0, 'alarm', 'warning'],
        ['Fuera de geocerca', $summary['out_of_geo'] ?? 0, 'geo-alt', 'danger'],
    ] as [$label, $value, $icon, $tone])
        <div class="col-6 col-lg-3">
            <div class="card border-0 h-100 kpi-card">
                <div class="card-body d-flex align-items-center gap-3">
                    <span class="kpi-icon bg-{{ $tone }}-subtle text-{{ $tone }}-emphasis"><i class="bi bi-{{ $icon }}"></i></span>
                    <div>
                        <div class="kpi-label">{{ $label }}</div>
                        <div class="fs-4 fw-bold">{{ $value }}</div>
                    </div>
                </div>
            </div>
        </div>
    @endforeach
</div>
@endisset

{{-- Filtros --}}
<div class="card border-0 mb-4">
    <div class="card-body">
        <form method="GET" action="{{ route('attendance.index') }}" class="row g-3 align-items-end">
            <div class="col-6 col-md-3 col-xl-2">
                <label for="start_date" class="form-label small">Desde</label>
                <input type="date" id="start_date" name="start_date" value="{{ $startDate }}" class="form-control">
            </div>
            <div class="col-6 col-md-3 col-xl-2">
                <label for="end_date" class="form-label small">Hasta</label>
                <input type="date" id="end_date" name="end_date" value="{{ $endDate }}" class="form-control">
            </div>
            <div class="col-12 col-md-6 col-xl-3">
                <label for="branch_id" class="form-label small">Sucursal</label>
                <select id="branch_id" name="branch_id" class="form-select">
                    <option value="">Todas las sucursales</option>
                    @foreach($branches as $b)
                        <option value="{{ $b->id }}" @selected(request('branch_id') == $b->id)>{{ $b->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <label for="status" class="form-label small">Estado</label>
                <select id="status" name="status" class="form-select">
                    <option value="">Todos</option>
                    <option value="present" @selected(request('status') == 'present')>Puntual</option>
                    <option value="late" @selected(request('status') == 'late')>Tardanza</option>
                    <option value="absent" @selected(request('status') == 'absent')>Ausente</option>
                    <option value="excused" @selected(request('status') == 'excused')>Justificado</option>
                    <option value="suspicious" @selected(request('status') == 'suspicious')>Solo sospechosas</option>
                </select>
            </div>
            <div class="col-6 col-md-5 col-xl-2">
                <label for="search" class="form-label small">Colaborador</label>
                <input type="search" id="search" name="search" value="{{ request('search') }}" placeholder="Nombre o código" class="form-control">
            </div>
            <div class="col-12 col-md-3 col-xl-1 d-grid">
                <button type="submit" class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Filtrar</button>
            </div>
        </form>
    </div>
</div>

{{-- Tabla --}}
<div class="card border-0">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Colaborador</th>
                    <th>Fecha</th>
                    <th>Selfie</th>
                    <th>Entrada</th>
                    <th>Geocerca</th>
                    <th>Salida</th>
                    <th>Horas</th>
                    <th>Estado</th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody>
                @forelse($attendances as $att)
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <span class="avatar avatar-sm">{{ mb_substr($att->user->first_name ?? 'U', 0, 1) }}{{ mb_substr($att->user->last_name ?? '', 0, 1) }}</span>
                                <div class="min-w-0">
                                    <div class="fw-semibold text-truncate">{{ $att->user->first_name ?? 'N/A' }} {{ $att->user->last_name ?? '' }}</div>
                                    <div class="small text-body-secondary text-truncate">{{ $att->user->employee_code ?? '—' }} · {{ $att->branch->name ?? 'Sin sucursal' }}</div>
                                </div>
                            </div>
                         @include('partials.tenant-badge', ['tenantId' => $att->tenant_id])</td>
                        <td class="text-nowrap">{{ $att->work_date?->format('d/m/Y') ?? '--' }}</td>
                        <td>
                            @if($att->clock_in_photo)
                                <img src="{{ attendance_photo_thumb_url($att, 'in') }}" alt="Selfie de entrada"
                                     class="selfie-thumb {{ $att->clock_in_within_geofence ? '' : 'is-outside' }}"
                                     role="button" onclick="openEvidence({{ $att->id }})" title="Ver selfie, mapa y geocerca">
                            @else
                                <span class="small text-body-secondary">Sin foto</span>
                            @endif
                        </td>
                        <td class="text-nowrap">
                            <span class="fw-semibold">{{ $att->clock_in_at?->format('h:i:s a') ?? '--:--:--' }}</span>
                            @if(data_get($att->clock_in_meta, 'offline'))
                                <span class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis ms-1" title="Marcada sin conexión y sincronizada después"><i class="bi bi-wifi-off"></i> Sin conexión</span>
                            @endif
                            @if($att->late_minutes > 0)
                                <div class="small text-warning-emphasis">+{{ $att->late_minutes }} min</div>
                            @endif
                        </td>
                        <td class="text-nowrap">
                            @if($att->clock_in_distance_meters !== null)
                                <span class="badge rounded-pill {{ $att->clock_in_within_geofence ? 'bg-success-subtle text-success-emphasis' : 'bg-danger-subtle text-danger-emphasis' }}">
                                    {{ $att->clock_in_within_geofence ? 'Dentro' : 'Fuera' }} · {{ $att->clock_in_distance_meters }} m
                                </span>
                            @else
                                <span class="small text-body-secondary">No registrado</span>
                            @endif
                        </td>
                        <td class="text-nowrap">{{ $att->clock_out_at?->format('h:i:s a') ?? '--:--:--' }}
                            @if(data_get($att->clock_out_meta, 'offline'))
                                <span class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis ms-1" title="Salida marcada sin conexión"><i class="bi bi-wifi-off"></i> Sin conexión</span>
                            @endif
                        </td>
                        <td class="text-nowrap">{{ number_format((float) ($att->worked_hours ?? 0), 2) }} h</td>
                        <td class="text-nowrap">
                            @include('partials.attendance-status', ['status' => $att->status])
                            @if($att->is_suspicious)
                                <span class="badge rounded-pill text-bg-warning ms-1" title="Ubicación simulada, hora desfasada, rostro no verificado o fuera de la geocerca"><i class="bi bi-shield-exclamation"></i> Sospechosa</span>
                            @endif
                        </td>
                        <td class="text-end text-nowrap">
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="openEvidence({{ $att->id }})" title="Ver selfie y mapa" aria-label="Ver selfie y mapa">
                                <i class="bi bi-geo-alt"></i>
                            </button>
                            <a href="{{ route('attendance.show', $att->id) }}" class="btn btn-sm btn-outline-secondary" title="Auditoría completa" aria-label="Auditoría completa">
                                <i class="bi bi-eye"></i>
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9"><div class="empty-state"><i class="bi bi-inbox"></i>No hay marcaciones para los filtros seleccionados.</div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($attendances->hasPages())
        <div class="card-footer bg-transparent">{{ $attendances->links() }}</div>
    @endif
</div>

@include('attendance.partials.evidence-modal')
@endsection
