@extends('layouts.app')

@section('title', 'Marcación #' . $attendance->id)

@section('content')
@php
    $branch = $attendance->branch;
    $moments = [
        'in' => [
            'title' => 'Entrada', 'icon' => 'box-arrow-in-right', 'photo' => $attendance->clock_in_photo, 'at' => $attendance->clock_in_at,
            'within' => $attendance->clock_in_within_geofence, 'distance' => $attendance->clock_in_distance_meters,
            'lat' => $attendance->clock_in_latitude, 'lng' => $attendance->clock_in_longitude,
            'offline' => data_get($attendance->clock_in_meta, 'offline'), 'synced' => data_get($attendance->clock_in_meta, 'synced_at'),
        ],
        'out' => [
            'title' => 'Salida', 'icon' => 'box-arrow-right', 'photo' => $attendance->clock_out_photo, 'at' => $attendance->clock_out_at,
            'within' => $attendance->clock_out_within_geofence, 'distance' => $attendance->clock_out_distance_meters,
            'lat' => $attendance->clock_out_latitude, 'lng' => $attendance->clock_out_longitude,
            'offline' => data_get($attendance->clock_out_meta, 'offline'), 'synced' => data_get($attendance->clock_out_meta, 'synced_at'),
        ],
    ];
@endphp

<nav aria-label="breadcrumb">
    <ol class="breadcrumb small mb-2">
        <li class="breadcrumb-item"><a href="{{ route('attendance.index') }}">Marcaciones</a></li>
        <li class="breadcrumb-item active" aria-current="page">#{{ $attendance->id }}</li>
    </ol>
</nav>

<div class="page-header">
    <div>
        <h1>{{ $attendance->user->first_name }} {{ $attendance->user->last_name }}</h1>
        <p>{{ $attendance->work_date?->format('d/m/Y') ?? '--' }} · {{ $branch->name ?? 'Sin sucursal' }}</p>
    </div>
    <div class="d-flex align-items-center gap-2">
        @include('partials.attendance-status', ['status' => $attendance->status])
        <button type="button" class="btn btn-primary" onclick="openEvidence({{ $attendance->id }})">
            <i class="bi bi-geo-alt me-1"></i> Ver selfie y mapa
        </button>
    </div>
</div>

<div class="row g-3 mb-4">
    @foreach($moments as $key => $m)
        <div class="col-12 col-lg-6">
            <div class="card border-0 h-100">
                <div class="card-header border-0 pt-3 d-flex justify-content-between align-items-center">
                    <h2 class="h6 fw-semibold mb-0"><i class="bi bi-{{ $m['icon'] }} me-1"></i>{{ $m['title'] }}
                        @if($m['offline'])
                            <span class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis ms-1 fw-normal" title="Sincronizada {{ $m['synced'] ? \Illuminate\Support\Carbon::parse($m['synced'])->setTimezone(tenant_timezone())->format('d/m/Y H:i') : '' }}"><i class="bi bi-wifi-off"></i> Sin conexión</span>
                        @endif
                    </h2>
                    @if($m['at'])
                        <span class="badge rounded-pill {{ $m['within'] ? 'bg-success-subtle text-success-emphasis' : 'bg-danger-subtle text-danger-emphasis' }}">
                            {{ $m['within'] ? 'Dentro de la geocerca' : 'Fuera de la geocerca' }}
                        </span>
                    @else
                        <span class="badge rounded-pill text-bg-light border">Pendiente</span>
                    @endif
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-5 col-sm-4">
                            @if($m['photo'])
                                @php $url = attendance_photo_url($attendance, $key); @endphp
                                <button type="button" class="btn p-0 border-0 w-100" data-photo="{{ $url }}"
                                        data-photo-title="Selfie de {{ strtolower($m['title']) }}"
                                        data-photo-meta="Hora: {{ $m['at']?->format('H:i:s') }} · Distancia: {{ $m['distance'] }} m">
                                    <img src="{{ $url }}" alt="Selfie de {{ strtolower($m['title']) }}" class="img-fluid rounded border border-2 {{ $m['within'] ? 'border-success' : 'border-danger' }}" style="aspect-ratio: 3/4; object-fit: cover;">
                                    <span class="d-block small text-body-secondary mt-1"><i class="bi bi-zoom-in"></i> Ampliar</span>
                                </button>
                            @else
                                <div class="ratio ratio-1x1 bg-body-tertiary rounded">
                                    <div class="d-flex align-items-center justify-content-center small text-body-secondary text-center p-2">
                                        {{ $m['at'] ? 'Sin selfie' : 'Aún no marca' }}
                                    </div>
                                </div>
                            @endif
                        </div>
                        <div class="col-7 col-sm-8">
                            <dl class="kv small">
                                <dt>Hora</dt><dd class="fw-semibold">{{ $m['at']?->format('H:i:s') ?? '--:--:--' }}</dd>
                                <dt>Distancia</dt>
                                <dd class="{{ $m['within'] ? 'text-success' : 'text-danger' }}">{{ $m['distance'] ?? '—' }} m <span class="text-body-secondary">de {{ $branch->allowed_radius_meters ?? 50 }} m</span></dd>
                                <dt>Coordenadas</dt><dd class="font-monospace">{{ $m['lat'] !== null ? $m['lat'] . ', ' . $m['lng'] : '—' }}</dd>
                                @if($key === 'in')
                                    <dt>Tardanza</dt><dd class="{{ $attendance->late_minutes > 0 ? 'text-warning-emphasis' : '' }}">{{ $attendance->late_minutes ?? 0 }} min</dd>
                                    <dt>Dispositivo</dt><dd class="font-monospace text-break">{{ $attendance->clock_in_device_id ?? '—' }}</dd>
                                @else
                                    <dt>Horas trabajadas</dt><dd>{{ number_format((float) ($attendance->worked_hours ?? 0), 2) }} h</dd>
                                    <dt>Horas extra</dt><dd>{{ number_format((float) ($attendance->overtime_hours ?? 0), 2) }} h</dd>
                                    <dt>Descanso</dt><dd>{{ $attendance->break_minutes ?? 0 }} min</dd>
                                @endif
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endforeach
</div>

<div class="card border-0 mb-4">
    <div class="card-header border-0 pt-3">
        <h2 class="h6 fw-semibold mb-0">Validación geográfica</h2>
        <small class="text-body-secondary">Círculo: geocerca de {{ $branch->name ?? 'la sucursal' }} ({{ $branch->allowed_radius_meters ?? 50 }} m). Punto: ubicación enviada por el celular al marcar la entrada.</small>
    </div>
    <div class="card-body pt-2">
        <div id="auditMap" class="map-frame rounded" style="height: 380px;"></div>
    </div>
</div>

@if($attendance->notes || $attendance->justification_status)
    <div class="card border-0">
        <div class="card-header border-0 pt-3">
            <h2 class="h6 fw-semibold mb-0">Justificación</h2>
        </div>
        <div class="card-body">
            <div class="row g-4">
                <div class="col-12 col-lg-6">
                    <div class="small text-body-secondary mb-1">Explicación del colaborador</div>
                    <div class="bg-body-tertiary rounded p-3" style="white-space: pre-wrap;">{{ $attendance->notes ?: 'Sin comentarios.' }}</div>
                    @if($attendance->justification_file)
                        <a href="{{ route('attendance.justification-file', $attendance->id) }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary mt-2">
                            <i class="bi bi-paperclip me-1"></i> Ver comprobante adjunto
                        </a>
                    @endif
                </div>
                <div class="col-12 col-lg-6">
                    <div class="small text-body-secondary mb-1">Resolución</div>
                    @if(in_array($attendance->justification_status, ['approved', 'rejected'], true))
                        <div class="alert {{ $attendance->justification_status === 'approved' ? 'alert-success' : 'alert-danger' }} mb-0">
                            <div class="fw-semibold">Justificación {{ $attendance->justification_status === 'approved' ? 'aprobada' : 'rechazada' }}</div>
                            <div class="small">
                                Por {{ $attendance->reviewer ? $attendance->reviewer->first_name . ' ' . $attendance->reviewer->last_name : 'Administrador' }}
                                el {{ $attendance->reviewed_at?->format('d/m/Y H:i') }}
                            </div>
                        </div>
                    @else
                        <form method="POST" action="{{ route('attendance.review', $attendance->id) }}">
                            @csrf
                            <label for="review_notes" class="form-label small">Notas de resolución</label>
                            <textarea id="review_notes" name="review_notes" rows="2" class="form-control mb-3" placeholder="Motivo de la aprobación o el rechazo"></textarea>
                            <div class="d-flex gap-2">
                                <button type="submit" name="justification_status" value="approved" class="btn btn-success flex-fill"><i class="bi bi-check-lg me-1"></i>Aprobar</button>
                                <button type="submit" name="justification_status" value="rejected" class="btn btn-outline-danger flex-fill"><i class="bi bi-x-lg me-1"></i>Rechazar</button>
                            </div>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endif

@include('attendance.partials.evidence-modal')
@endsection

@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    @php
        // @json no admite arreglos con comas dentro de un ternario: se arman antes
        $mapBranch = $branch ? ['lat' => (float) $branch->latitude, 'lng' => (float) $branch->longitude, 'radius' => (int) $branch->allowed_radius_meters, 'name' => $branch->name] : null;
        $mapPoint  = $attendance->clock_in_latitude !== null ? ['lat' => (float) $attendance->clock_in_latitude, 'lng' => (float) $attendance->clock_in_longitude] : null;
    @endphp
    const branch = @json($mapBranch);
    const point = @json($mapPoint);
    const within = @json((bool) $attendance->clock_in_within_geofence);
    const distance = @json($attendance->clock_in_distance_meters);
    const brand = Panel.cssVar('--brand') || '#1E40AF';

    // Leaflet necesita una vista antes de agregar círculos (getBounds los proyecta)
    const center = point ? [point.lat, point.lng] : (branch ? [branch.lat, branch.lng] : [-12.046374, -77.042793]);
    const map = L.map('auditMap', { scrollWheelZoom: false }).setView(center, 16);
    Panel.tiles(map);
    const layer = L.featureGroup().addTo(map);

    if (branch) {
        L.circle([branch.lat, branch.lng], { radius: branch.radius || 50, color: brand, fillColor: brand, fillOpacity: .15, weight: 2 })
            .bindPopup(`<strong>${Panel.esc(branch.name)}</strong><br>Radio: ${branch.radius} m`).addTo(layer);
        L.circleMarker([branch.lat, branch.lng], { radius: 5, color: brand, fillOpacity: 1 }).bindTooltip('Centro de la sucursal').addTo(layer);
    }
    if (point) {
        const color = within ? '#198754' : '#dc3545';
        L.circleMarker([point.lat, point.lng], { radius: 9, color: '#fff', weight: 3, fillColor: color, fillOpacity: 1 })
            .bindPopup(`<strong>Punto de marcación</strong><br>${Panel.esc(distance ?? '?')} m del centro<br>${within ? 'Dentro' : 'Fuera'} de la geocerca`)
            .addTo(layer).openPopup();
        if (branch) L.polyline([[branch.lat, branch.lng], [point.lat, point.lng]], { color, weight: 2, dashArray: '5 8' }).addTo(layer);
    }

    const bounds = layer.getBounds();
    if (bounds.isValid()) map.fitBounds(bounds.pad(.4), { maxZoom: 18 });
    else map.setView([-12.046374, -77.042793], 12);
});
</script>
@endsection
