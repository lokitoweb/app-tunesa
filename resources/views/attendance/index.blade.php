@extends('layouts.app')

@section('title', 'Marcaciones y GPS')

@section('content')
<header class="page-head">
    <div class="page-head-text">
        <h1 class="page-title">Marcaciones y GPS</h1>
        <p class="page-subtitle">Revisa cada marcación con su selfie, hora, sucursal y validación de ubicación.</p>
    </div>
    <div class="page-actions" id="att-export">
        <a href="{{ route('reports.export.excel', request()->all()) }}" class="btn btn-light border">
            <i class="bi bi-file-earmark-spreadsheet me-1"></i>Exportar a Excel
        </a>
    </div>
</header>

{{-- Resumen del rango --}}
<div id="att-kpis">
@isset($summary)
<section class="dash-kpis" aria-label="Resumen del rango">
    @foreach ([['Marcaciones totales', 'total', 'brand', 'list-check'], ['Puntuales', 'on_time', 'emerald', 'check-circle'], ['Tardanzas', 'late', 'amber', 'alarm'], ['Fuera de ubicación', 'out_of_geo', 'rose', 'geo-alt']] as [$klabel, $kkey, $ktone, $kicon])
        <article class="dash-kpi-card">
            <div class="dash-kpi-top">
                <span class="dash-kpi-label">{{ $klabel }}</span>
                <span class="dash-kpi-icon tone-{{ $ktone }}"><i class="bi bi-{{ $kicon }}"></i></span>
            </div>
            <div class="dash-kpi-value">{{ number_format($summary[$kkey] ?? 0) }}</div>
        </article>
    @endforeach
</section>
@endisset
</div>

<section class="surface">
{{-- Filtros: se aplican al elegir (AJAX) --}}
<form method="GET" action="{{ route('attendance.index') }}" class="filter-row" id="attFilterForm" role="search"
      data-ajax-filter="#att-kpis, #att-results, #att-export">
    <input type="hidden" name="per_page" value="{{ request('per_page', $attendances->perPage()) }}" data-default="20">
    <div class="search-field">
        <i class="bi bi-search"></i>
        <input type="search" id="search" name="search" value="{{ request('search') }}" placeholder="Colaborador: nombre o código…" class="form-control" aria-label="Colaborador">
    </div>
    <div class="range-field">
        <input type="date" id="start_date" name="start_date" value="{{ $startDate }}" data-default="{{ now(tenant_timezone())->toDateString() }}" class="form-control" aria-label="Desde" data-autosubmit>
        <span class="text-body-secondary small">a</span>
        <input type="date" id="end_date" name="end_date" value="{{ $endDate }}" data-default="{{ now(tenant_timezone())->toDateString() }}" class="form-control" aria-label="Hasta" data-autosubmit>
    </div>
    <select id="branch_id" name="branch_id" class="form-select w-auto" aria-label="Sucursal" data-autosubmit>
        <option value="">Todas las sucursales</option>
        @foreach($branches as $b)
            <option value="{{ $b->id }}" @selected(request('branch_id') == $b->id)>{{ $b->name }}</option>
        @endforeach
    </select>
    <select id="status" name="status" class="form-select w-auto" aria-label="Estado" data-autosubmit>
        <option value="">Todos los estados</option>
        <option value="present" @selected(request('status') == 'present')>Puntual</option>
        <option value="late" @selected(request('status') == 'late')>Tardanza</option>
        <option value="excused" @selected(request('status') == 'excused')>Justificado</option>
        <option value="suspicious" @selected(request('status') == 'suspicious')>Solo sospechosas</option>
    </select>
    <div class="d-flex gap-2 ms-auto">
        <button type="submit" class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Filtrar</button>
        <a href="{{ route('attendance.index') }}" data-ajax-reset class="btn btn-light border" title="Limpiar filtros" aria-label="Limpiar filtros"><i class="bi bi-x-lg"></i></a>
    </div>
</form>

{{-- Tabla de marcaciones --}}
<div id="att-results">
    <div class="table-responsive">
        <table class="table data-table table-cards align-middle mb-0">
            <thead>
                <tr>
                    <th>Colaborador</th>
                    <th>Fecha</th>
                    <th>Selfie</th>
                    <th>Entrada</th>
                    <th>Ubicación</th>
                    <th>Salida</th>
                    <th>Horas</th>
                    <th>Estado</th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody>
                @forelse($attendances as $att)
                    <tr>
                        <td class="cell-main">
                            <div class="d-flex align-items-center gap-3">
                                @if(!empty($att->user?->avatar_path))
                                    <img src="{{ asset('storage/' . $att->user->avatar_path) }}" alt="{{ $att->user->first_name }}" class="attendance-user-avatar">
                                @else
                                    <span class="attendance-user-initials">
                                        {{ mb_substr($att->user->first_name ?? 'U', 0, 1) }}{{ mb_substr($att->user->last_name ?? '', 0, 1) }}
                                    </span>
                                @endif
                                <div class="min-w-0">
                                    <div class="cell-title text-truncate">{{ $att->user->first_name ?? 'N/A' }} {{ $att->user->last_name ?? '' }}</div>
                                    <div class="cell-sub text-truncate">{{ $att->user->employee_code ?? '—' }} · {{ $att->branch->name ?? 'Sin sucursal' }}</div>
                                    @include('partials.tenant-badge', ['tenantId' => $att->tenant_id])
                                </div>
                            </div>
                        </td>
                        <td class="text-nowrap" data-label="Fecha">
                            <span>{{ $att->work_date?->format('d/m/Y') ?? '--' }}</span>
                        </td>
                        <td data-label="Selfie">
                            @if($att->clock_in_photo)
                                <div class="attendance-selfie-thumb-wrapper {{ $att->clock_in_within_geofence ? '' : 'is-outside' }}"
                                     role="button" onclick="openEvidence({{ $att->id }})" title="Ver selfie y ubicación">
                                    <img src="{{ attendance_photo_thumb_url($att, 'in') }}" alt="Selfie de entrada" class="attendance-selfie-thumb">
                                    <span class="selfie-zoom-badge"><i class="bi bi-zoom-in"></i></span>
                                </div>
                            @else
                                <span class="status-pill tone-neutral"><i class="bi bi-camera-video-off"></i>Sin foto</span>
                            @endif
                        </td>
                        <td class="text-nowrap" data-label="Entrada">
                            <div>
                                <span class="cell-title">{{ $att->clock_in_at?->format('h:i:s a') ?? '--:--:--' }}</span>
                                @if(data_get($att->clock_in_meta, 'offline'))
                                    <span class="status-pill tone-neutral ms-1" title="Marcada sin conexión y sincronizada después"><i class="bi bi-wifi-off"></i>Sin conexión</span>
                                @endif
                                @if($att->late_minutes > 0)
                                    <div class="cell-sub text-warning-emphasis fw-semibold"><i class="bi bi-clock-history me-1"></i>+{{ minutes_label($att->late_minutes) }}</div>
                                @endif
                            </div>
                        </td>
                        <td class="text-nowrap" data-label="Ubicación">
                            @if($att->clock_in_distance_meters !== null)
                                @if($att->clock_in_within_geofence)
                                    <span class="status-pill tone-emerald"><i class="bi bi-geo-alt"></i>En ubicación ({{ $att->clock_in_distance_meters }} m)</span>
                                @else
                                    <span class="status-pill tone-rose"><i class="bi bi-geo-alt"></i>Fuera ({{ $att->clock_in_distance_meters }} m)</span>
                                @endif
                            @else
                                <span class="cell-sub">No registrado</span>
                            @endif
                        </td>
                        <td class="text-nowrap" data-label="Salida">
                            <div>
                                <span>{{ $att->clock_out_at?->format('h:i:s a') ?? '--:--:--' }}</span>
                                @if(data_get($att->clock_out_meta, 'offline'))
                                    <span class="status-pill tone-neutral ms-1" title="Salida marcada sin conexión"><i class="bi bi-wifi-off"></i>Sin conexión</span>
                                @endif
                            </div>
                        </td>
                        <td class="text-nowrap fw-semibold" data-label="Horas">
                            <span>{{ number_format((float) ($att->worked_hours ?? 0), 2) }} h</span>
                        </td>
                        <td class="text-nowrap" data-label="Estado">
                            <div class="d-flex flex-wrap gap-1">
                                @include('partials.attendance-status', ['status' => $att->status])
                                @if($att->is_suspicious)
                                    <span class="status-pill tone-amber" title="Ubicación simulada, hora desfasada, rostro no verificado o fuera de la ubicación"><i class="bi bi-shield-exclamation"></i>Sospechosa</span>
                                @endif
                            </div>
                        </td>
                        <td>
                            <div class="row-actions">
                                <button type="button" class="icon-btn" onclick="openEvidence({{ $att->id }})" title="Ver selfie y ubicación" aria-label="Ver selfie y ubicación">
                                    <i class="bi bi-geo-alt"></i>
                                </button>
                                <a href="{{ route('attendance.show', $att->id) }}" class="icon-btn" title="Auditoría completa" aria-label="Auditoría completa">
                                    <i class="bi bi-eye"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr class="row-empty">
                        <td colspan="9">
                            <div class="dash-empty">
                                <span class="dash-empty-icon"><i class="bi bi-calendar2-x"></i></span>
                                <p class="dash-empty-title">No hay marcaciones</p>
                                <p class="dash-empty-text">Ajusta los filtros o las fechas para consultar otros registros de asistencia.</p>
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
</section>

<script>
// Cantidad por página: va en el formulario de filtros (AJAX, URL limpia)
function changeAttendancePerPage(perPage) {
    const form = document.getElementById('attFilterForm');
    form.querySelector('[name="per_page"]').value = perPage;
    form.requestSubmit();
}
</script>

@include('attendance.partials.evidence-modal')
@endsection
