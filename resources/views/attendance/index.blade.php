@extends('layouts.app')

@section('title', 'Marcaciones y GPS')

@section('content')
{{-- Header con título y acción principal --}}
<div class="attendance-header-bar">
    <div>
        <h1 class="h2 mb-1 fw-bold text-dark">Marcaciones y GPS</h1>
        <p class="text-secondary mb-0" style="font-size: 0.875rem;">Revisa cada marcación con su selfie, hora, sucursal y validación de ubicación.</p>
    </div>
    <div>
        <a href="{{ route('reports.export.excel', request()->all()) }}" class="btn btn-outline-success d-inline-flex align-items-center gap-2 shadow-sm" style="height: 38px; border-radius: 8px; font-weight: 600; padding: 0 1.15rem;">
            <i class="bi bi-file-earmark-spreadsheet"></i>
            <span>Exportar a Excel</span>
        </a>
    </div>
</div>

{{-- Resumen del rango (KPI Cards) --}}
@isset($summary)
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="attendance-kpi-card">
            <div class="attendance-kpi-icon" style="background: rgba(2, 132, 199, 0.1); color: #0284c7;">
                <i class="bi bi-list-check"></i>
            </div>
            <div>
                <div class="attendance-kpi-val">{{ number_format($summary['total'] ?? 0) }}</div>
                <div class="attendance-kpi-label">Marcaciones totales</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="attendance-kpi-card">
            <div class="attendance-kpi-icon" style="background: rgba(16, 185, 129, 0.1); color: #10b981;">
                <i class="bi bi-check-circle"></i>
            </div>
            <div>
                <div class="attendance-kpi-val">{{ number_format($summary['on_time'] ?? 0) }}</div>
                <div class="attendance-kpi-label">Puntuales</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="attendance-kpi-card">
            <div class="attendance-kpi-icon" style="background: rgba(245, 158, 11, 0.1); color: #d97706;">
                <i class="bi bi-alarm"></i>
            </div>
            <div>
                <div class="attendance-kpi-val">{{ number_format($summary['late'] ?? 0) }}</div>
                <div class="attendance-kpi-label">Tardanzas</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="attendance-kpi-card">
            <div class="attendance-kpi-icon" style="background: rgba(239, 68, 68, 0.1); color: #ef4444;">
                <i class="bi bi-geo-alt"></i>
            </div>
            <div>
                <div class="attendance-kpi-val">{{ number_format($summary['out_of_geo'] ?? 0) }}</div>
                <div class="attendance-kpi-label">Fuera de ubicación</div>
            </div>
        </div>
    </div>
</div>
@endisset

{{-- Filtros integrados ejecutivos --}}
<div class="attendance-filter-card">
    <form method="GET" action="{{ route('attendance.index') }}" class="row g-3 align-items-end">
        <input type="hidden" name="per_page" value="{{ request('per_page', $attendances->perPage()) }}">
        <div class="col-6 col-md-3 col-xl-2">
            <label for="start_date" class="attendance-filter-label">Desde</label>
            <input type="date" id="start_date" name="start_date" value="{{ $startDate }}" class="form-control attendance-filter-input">
        </div>
        <div class="col-6 col-md-3 col-xl-2">
            <label for="end_date" class="attendance-filter-label">Hasta</label>
            <input type="date" id="end_date" name="end_date" value="{{ $endDate }}" class="form-control attendance-filter-input">
        </div>
        <div class="col-6 col-md-3 col-xl-2">
            <label for="branch_id" class="attendance-filter-label">Sucursal</label>
            <select id="branch_id" name="branch_id" class="form-select attendance-filter-select">
                <option value="">Todas las sucursales</option>
                @foreach($branches as $b)
                    <option value="{{ $b->id }}" @selected(request('branch_id') == $b->id)>{{ $b->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-6 col-md-3 col-xl-2">
            <label for="status" class="attendance-filter-label">Estado</label>
            <select id="status" name="status" class="form-select attendance-filter-select">
                <option value="">Todos los estados</option>
                <option value="present" @selected(request('status') == 'present')>Puntual</option>
                <option value="late" @selected(request('status') == 'late')>Tardanza</option>
                <option value="excused" @selected(request('status') == 'excused')>Justificado</option>
                <option value="suspicious" @selected(request('status') == 'suspicious')>Solo sospechosas</option>
            </select>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <label for="search" class="attendance-filter-label">Colaborador</label>
            <div class="attendance-search-wrapper">
                <i class="bi bi-search search-icon"></i>
                <input type="search" id="search" name="search" value="{{ request('search') }}" placeholder="Nombre o código…" class="attendance-search-input">
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="d-flex align-items-center gap-2">
                <button type="submit" class="btn btn-primary d-inline-flex align-items-center justify-content-center shadow-sm flex-grow-1" style="height: 38px; border-radius: 8px; font-weight: 600; gap: 6px;">
                    <i class="bi bi-funnel"></i>
                    <span>Filtrar</span>
                </button>
                @if(request()->hasAny(['search', 'branch_id', 'status', 'start_date', 'end_date']))
                    <a href="{{ route('attendance.index') }}" class="btn btn-outline-secondary d-inline-flex align-items-center justify-content-center flex-shrink-0" style="height: 38px; width: 38px; border-radius: 8px;" title="Limpiar filtros">
                        <i class="bi bi-x-lg"></i>
                    </a>
                @endif
            </div>
        </div>
    </form>
</div>

{{-- Tabla de marcaciones --}}
<div class="card border-0 shadow-sm" style="border-radius: 14px; overflow: hidden; border: 1px solid #e2e8f0;">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead style="background: #f8fafc; border-bottom: 1px solid #e2e8f0;">
                <tr>
                    <th class="ps-4 py-3 text-secondary text-uppercase fw-semibold" style="font-size: 0.725rem; letter-spacing: 0.05em;">Colaborador</th>
                    <th class="py-3 text-secondary text-uppercase fw-semibold" style="font-size: 0.725rem; letter-spacing: 0.05em;">Fecha</th>
                    <th class="py-3 text-secondary text-uppercase fw-semibold" style="font-size: 0.725rem; letter-spacing: 0.05em;">Selfie</th>
                    <th class="py-3 text-secondary text-uppercase fw-semibold" style="font-size: 0.725rem; letter-spacing: 0.05em;">Entrada</th>
                    <th class="py-3 text-secondary text-uppercase fw-semibold" style="font-size: 0.725rem; letter-spacing: 0.05em;">Ubicación</th>
                    <th class="py-3 text-secondary text-uppercase fw-semibold" style="font-size: 0.725rem; letter-spacing: 0.05em;">Salida</th>
                    <th class="py-3 text-secondary text-uppercase fw-semibold" style="font-size: 0.725rem; letter-spacing: 0.05em;">Horas</th>
                    <th class="py-3 text-secondary text-uppercase fw-semibold" style="font-size: 0.725rem; letter-spacing: 0.05em;">Estado</th>
                    <th class="pe-4 py-3 text-secondary text-uppercase fw-semibold text-end" style="font-size: 0.725rem; letter-spacing: 0.05em;">Acciones</th>
                </tr>
            </thead>
            <tbody>
                @forelse($attendances as $att)
                    <tr style="border-bottom: 1px solid #f1f5f9;">
                        <td class="ps-4 py-3">
                            <div class="d-flex align-items-center gap-3">
                                @if(!empty($att->user?->avatar_path))
                                    <img src="{{ asset('storage/' . $att->user->avatar_path) }}" alt="{{ $att->user->first_name }}" class="attendance-user-avatar">
                                @else
                                    <span class="attendance-user-initials">
                                        {{ mb_substr($att->user->first_name ?? 'U', 0, 1) }}{{ mb_substr($att->user->last_name ?? '', 0, 1) }}
                                    </span>
                                @endif
                                <div class="min-w-0">
                                    <div class="fw-bold text-dark text-truncate" style="font-size: 0.875rem;">
                                        {{ $att->user->first_name ?? 'N/A' }} {{ $att->user->last_name ?? '' }}
                                    </div>
                                    <div class="small text-secondary text-truncate" style="font-size: 0.775rem;">
                                        <span>{{ $att->user->employee_code ?? '—' }}</span> · <span>{{ $att->branch->name ?? 'Sin sucursal' }}</span>
                                    </div>
                                    @include('partials.tenant-badge', ['tenantId' => $att->tenant_id])
                                </div>
                            </div>
                        </td>
                        <td class="py-3 text-nowrap" style="font-size: 0.85rem; font-weight: 500; color: #334155;">
                            {{ $att->work_date?->format('d/m/Y') ?? '--' }}
                        </td>
                        <td class="py-3">
                            @if($att->clock_in_photo)
                                <div class="attendance-selfie-thumb-wrapper {{ $att->clock_in_within_geofence ? '' : 'is-outside' }}"
                                     role="button" onclick="openEvidence({{ $att->id }})" title="Ver selfie y ubicación">
                                    <img src="{{ attendance_photo_thumb_url($att, 'in') }}" alt="Selfie de entrada" class="attendance-selfie-thumb">
                                    <span class="selfie-zoom-badge"><i class="bi bi-zoom-in"></i></span>
                                </div>
                            @else
                                <span class="badge rounded-2 bg-light text-secondary border" style="font-weight: 500; font-size: 0.725rem; padding: 4px 6px;">
                                    <i class="bi bi-camera-video-off me-1"></i>Sin foto
                                </span>
                            @endif
                        </td>
                        <td class="py-3 text-nowrap">
                            <span class="fw-bold text-dark" style="font-size: 0.875rem;">{{ $att->clock_in_at?->format('h:i:s a') ?? '--:--:--' }}</span>
                            @if(data_get($att->clock_in_meta, 'offline'))
                                <span class="badge rounded-2 bg-secondary-subtle text-secondary-emphasis ms-1" style="font-size: 0.7rem;" title="Marcada sin conexión y sincronizada después">
                                    <i class="bi bi-wifi-off"></i> Sin conexión
                                </span>
                            @endif
                            @if($att->late_minutes > 0)
                                <div class="small text-warning fw-semibold mt-0.5" style="font-size: 0.75rem;">
                                    <i class="bi bi-clock-history me-0.5"></i> +{{ $att->late_minutes }} min
                                </div>
                            @endif
                        </td>
                        <td class="py-3 text-nowrap">
                            @if($att->clock_in_distance_meters !== null)
                                @if($att->clock_in_within_geofence)
                                    <span class="live-location-badge in-range">
                                        <span class="badge-dot" style="width: 6px; height: 6px; border-radius: 50%; background: #10b981; display: inline-block;"></span>
                                        En ubicación ({{ $att->clock_in_distance_meters }} m)
                                    </span>
                                @else
                                    <span class="live-location-badge out-range">
                                        <span class="badge-dot" style="width: 6px; height: 6px; border-radius: 50%; background: #ef4444; display: inline-block;"></span>
                                        Fuera ({{ $att->clock_in_distance_meters }} m)
                                    </span>
                                @endif
                            @else
                                <span class="small text-secondary" style="font-size: 0.775rem;">No registrado</span>
                            @endif
                        </td>
                        <td class="py-3 text-nowrap">
                            <span class="text-dark" style="font-size: 0.85rem;">{{ $att->clock_out_at?->format('h:i:s a') ?? '--:--:--' }}</span>
                            @if(data_get($att->clock_out_meta, 'offline'))
                                <span class="badge rounded-2 bg-secondary-subtle text-secondary-emphasis ms-1" style="font-size: 0.7rem;" title="Salida marcada sin conexión">
                                    <i class="bi bi-wifi-off"></i> Sin conexión
                                </span>
                            @endif
                        </td>
                        <td class="py-3 text-nowrap fw-semibold text-dark" style="font-size: 0.85rem;">
                            {{ number_format((float) ($att->worked_hours ?? 0), 2) }} h
                        </td>
                        <td class="py-3 text-nowrap">
                            @include('partials.attendance-status', ['status' => $att->status])
                            @if($att->is_suspicious)
                                <span class="badge rounded-2 bg-warning-subtle text-warning-emphasis border border-warning-subtle ms-1" style="font-size: 0.7rem;" title="Ubicación simulada, hora desfasada, rostro no verificado o fuera de la ubicación">
                                    <i class="bi bi-shield-exclamation"></i> Sospechosa
                                </span>
                            @endif
                        </td>
                        <td class="pe-4 py-3 text-end text-nowrap">
                            <div class="d-inline-flex gap-1">
                                <button type="button" class="btn-table-action" onclick="openEvidence({{ $att->id }})" title="Ver selfie y ubicación" aria-label="Ver selfie y ubicación">
                                    <i class="bi bi-geo-alt"></i>
                                </button>
                                <a href="{{ route('attendance.show', $att->id) }}" class="btn-table-action" title="Auditoría completa" aria-label="Auditoría completa">
                                    <i class="bi bi-eye"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="py-5 text-center">
                            <div class="empty-state py-4">
                                <div style="width: 54px; height: 54px; border-radius: 12px; background: #f1f5f9; color: #64748b; display: inline-flex; align-items: center; justify-content: center; font-size: 1.6rem; margin-bottom: 1rem;">
                                    <i class="bi bi-calendar2-x"></i>
                                </div>
                                <div class="fw-bold text-dark fs-6">No hay marcaciones encontradas</div>
                                <div class="text-secondary small mt-1" style="max-width: 420px; margin: 0 auto;">Ajusta los filtros o las fechas seleccionadas para consultar otros registros de asistencia.</div>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($attendances->total() > 0)
        <div class="table-pagination-footer">
            <div class="table-pagination-info">
                <span>
                    Mostrando <strong>{{ $attendances->firstItem() }}</strong> a <strong>{{ $attendances->lastItem() }}</strong> de <strong>{{ $attendances->total() }}</strong> marcaciones
                </span>
                <div class="per-page-selector ms-sm-2">
                    <label for="perPageSelect" class="text-secondary small mb-0">Mostrar:</label>
                    <select id="perPageSelect" class="form-select form-select-sm" onchange="changeAttendancePerPage(this.value)">
                        <option value="10" @selected($attendances->perPage() == 10)>10</option>
                        <option value="20" @selected($attendances->perPage() == 20)>20</option>
                        <option value="50" @selected($attendances->perPage() == 50)>50</option>
                        <option value="100" @selected($attendances->perPage() == 100)>100</option>
                    </select>
                </div>
            </div>

            <div class="d-flex align-items-center gap-2">
                @if($attendances->hasPages())
                    {{ $attendances->links() }}
                @else
                    <nav aria-label="Navegación de páginas">
                        <ul class="pagination pagination-sm mb-0">
                            <li class="page-item disabled" aria-disabled="true">
                                <span class="page-link" aria-label="Anterior">&lsaquo;</span>
                            </li>
                            <li class="page-item active" aria-current="page">
                                <span class="page-link">1</span>
                            </li>
                            <li class="page-item disabled" aria-disabled="true">
                                <span class="page-link" aria-label="Siguiente">&rsaquo;</span>
                            </li>
                        </ul>
                    </nav>
                @endif
                <span class="pagination-page-indicator">
                    Pág. {{ $attendances->currentPage() }} de {{ $attendances->lastPage() }}
                </span>
            </div>
        </div>
    @endif
</div>

<script>
function changeAttendancePerPage(perPage) {
    const url = new URL(window.location.href);
    url.searchParams.set('per_page', perPage);
    url.searchParams.set('page', '1');
    window.location.href = url.toString();
}
</script>

@include('attendance.partials.evidence-modal')
@endsection
