@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
@if($platform)
    <div class="alert alert-primary d-flex gap-2" role="status">
        <i class="bi bi-buildings"></i>
        <div>
            <strong>Consolidado de la plataforma:</strong> {{ $platform['active_tenants'] }} de {{ $platform['tenants'] }} empresas activas.
            Las cifras suman todas las empresas. Para ver el mapa, las marcaciones y los módulos de una empresa, elígela en el selector superior.
        </div>
    </div>
@endif

<div class="page-header">
    <div>
        <h1>Dashboard</h1>
        <p >Asistencia de hoy, {{ \Carbon\Carbon::parse($today)->isoFormat('dddd D [de] MMMM [de] YYYY') }}</p>
    </div>
    <div class="d-flex align-items-center gap-2 small text-success fw-semibold">
        <span class="live-dot"></span> Actualización automática cada 15 s
    </div>
</div>

{{-- 1. KPIs --}}
@php
    $kpiCards = [
        ['id' => 'kpiPresent', 'label' => 'Presentes hoy', 'value' => $kpis['present_count'], 'icon' => 'person-check', 'tone' => 'success',
         'hint' => "{$kpis['attendance_rate']}% de {$kpis['total_employees']} colaboradores"],
        ['id' => 'kpiLate', 'label' => 'Tardanzas', 'value' => $kpis['late_count'], 'icon' => 'alarm', 'tone' => 'warning',
         'hint' => 'Según la tolerancia de cada turno'],
        ['id' => 'kpiBreak', 'label' => 'En descanso', 'value' => $kpis['on_break_count'], 'icon' => 'cup-hot', 'tone' => 'info',
         'hint' => 'Pausa activa en este momento'],
        ['id' => 'kpiAbsent', 'label' => 'Sin marcar', 'value' => $kpis['absent_count'], 'icon' => 'person-dash', 'tone' => 'danger',
         'hint' => "{$kpis['out_of_geofence']} marcaciones fuera de geocerca"],
    ];
@endphp
<div class="row g-3 mb-4">
    @foreach($kpiCards as $card)
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card border-0 h-100 kpi-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <span class="kpi-label">{{ $card['label'] }}</span>
                        <span class="kpi-icon bg-{{ $card['tone'] }}-subtle text-{{ $card['tone'] }}-emphasis"><i class="bi bi-{{ $card['icon'] }}"></i></span>
                    </div>
                    <div class="kpi-value" id="{{ $card['id'] }}">{{ $card['value'] }}</div>
                    <div class="small text-body-secondary mt-1">{{ $card['hint'] }}</div>
                </div>
            </div>
        </div>
    @endforeach
</div>

{{-- 2. Mapa + actividad reciente --}}
<div class="row g-3 mb-4">
    <div class="col-12 col-xl-8">
        <div class="card border-0 h-100">
            <div class="card-header border-0 pt-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h2 class="h6 fw-semibold mb-0">Mapa de geocercas y marcaciones</h2>
                    <small class="text-body-secondary">Radio permitido de cada sucursal y ubicación GPS de cada marcación</small>
                </div>
                <span class="badge text-bg-light border">{{ $branches->count() }} sucursales activas</span>
            </div>
            <div class="card-body pt-2">
                <div id="liveMap" class="map-frame rounded"></div>
                <div class="d-flex flex-wrap gap-3 small text-body-secondary mt-2">
                    <span><i class="bi bi-circle-fill text-primary"></i> Geocerca</span>
                    <span><i class="bi bi-circle-fill text-success"></i> Dentro</span>
                    <span><i class="bi bi-circle-fill text-danger"></i> Fuera</span>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-4">
        <div class="card border-0 h-100">
            <div class="card-header border-0 pt-3">
                <h2 class="h6 fw-semibold mb-0">Actividad reciente</h2>
                <small class="text-body-secondary">Últimas entradas registradas hoy</small>
            </div>
            <div class="list-group list-group-flush stream-list" id="streamContainer">
                @forelse($recentAttendances as $att)
                    <div class="list-group-item d-flex align-items-center gap-3">
                        @if($att->clock_in_photo)
                            <img src="{{ attendance_photo_url($att, 'in') }}" alt="Selfie de {{ $att->user->first_name }}"
                                 class="selfie-thumb {{ $att->clock_in_within_geofence ? '' : 'is-outside' }}"
                                 data-photo="{{ attendance_photo_url($att, 'in') }}"
                                 data-photo-title="{{ $att->user->first_name }} {{ $att->user->last_name }}"
                                 data-photo-meta="Hora: {{ $att->clock_in_at->format('H:i:s') }} · Distancia: {{ $att->clock_in_distance_meters }} m">
                        @else
                            <span class="avatar">{{ mb_substr($att->user->first_name ?? 'U', 0, 1) }}{{ mb_substr($att->user->last_name ?? '', 0, 1) }}</span>
                        @endif
                        <div class="flex-grow-1 min-w-0">
                            <div class="fw-semibold text-truncate">{{ $att->user->first_name }} {{ $att->user->last_name }}</div>
                            <div class="small text-body-secondary text-truncate">
                                {{ $att->branch->name ?? 'Sin sucursal' }} ·
                                <span class="{{ $att->clock_in_within_geofence ? 'text-success' : 'text-danger' }}">{{ $att->clock_in_distance_meters }} m</span>
                            </div>
                        </div>
                        <div class="text-end">
                            <div class="fw-semibold">{{ $att->clock_in_at?->format('H:i') ?? '--:--' }}</div>
                            @include('partials.attendance-status', ['status' => $att->status])
                        </div>
                    </div>
                @empty
                    <div class="empty-state"><i class="bi bi-inbox"></i>No hay marcaciones registradas hoy.</div>
                @endforelse
            </div>
        </div>
    </div>
</div>

{{-- 3. Tendencia semanal --}}
<div class="card border-0">
    <div class="card-header border-0 pt-3">
        <h2 class="h6 fw-semibold mb-0">Puntualidad de los últimos 7 días</h2>
        <small class="text-body-secondary">Puntuales, tardanzas y ausencias por día</small>
    </div>
    <div class="card-body" style="height: 320px;">
        <canvas id="weeklyChartCanvas" aria-label="Gráfico de puntualidad semanal" role="img"></canvas>
    </div>
</div>
@endsection

@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const branches = @json($branches);
    const chartData = @json($weeklyChart);
    const brand = Panel.cssVar('--brand') || '#1E40AF';

    // 1. Mapa (OpenStreetMap, sin API key)
    const first = branches[0];
    const map = L.map('liveMap', { scrollWheelZoom: false })
        .setView(first ? [parseFloat(first.latitude), parseFloat(first.longitude)] : [-12.046374, -77.042793], first ? 14 : 11);
    Panel.tiles(map);

    const bounds = [];
    branches.forEach((b) => {
        const center = [parseFloat(b.latitude), parseFloat(b.longitude)];
        const radius = parseInt(b.allowed_radius_meters, 10) || 50;
        L.circle(center, { radius, color: brand, fillColor: brand, fillOpacity: .12, weight: 2 })
            .addTo(map)
            .bindPopup(`<strong>${Panel.esc(b.name)}</strong><br><small>${Panel.esc(b.address || '')}</small><br><small>Radio: ${radius} m</small>`);
        bounds.push(center);
    });
    if (bounds.length > 1) map.fitBounds(bounds, { padding: [40, 40], maxZoom: 15 });

    // 2. Marcaciones en vivo
    let markers = [];
    const setText = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };

    function refresh() {
        fetch(@json(route('dashboard.live-stream')), { headers: { Accept: 'application/json' } })
            .then((r) => r.ok ? r.json() : Promise.reject(r.status))
            .then((data) => {
                if (!data.success) return;
                setText('kpiPresent', data.kpis.present_count);
                setText('kpiLate', data.kpis.late_count);
                setText('kpiBreak', data.kpis.on_break_count);
                setText('kpiAbsent', data.kpis.absent_count);

                markers.forEach((m) => map.removeLayer(m));
                markers = data.attendances
                    .filter((a) => a.latitude && a.longitude)
                    .map((a) => {
                        const color = a.within_geofence ? '#198754' : '#dc3545';
                        const icon = L.divIcon({
                            className: '',
                            html: `<div class="emp-marker" style="width:18px;height:18px;background:${color}"></div>`,
                            iconSize: [18, 18],
                            iconAnchor: [9, 9],
                        });
                        const photo = a.photo_url ? `<img src="${Panel.esc(a.photo_url)}" alt="" class="rounded mb-2" style="width:100%;height:90px;object-fit:cover">` : '';
                        return L.marker([a.latitude, a.longitude], { icon }).addTo(map).bindPopup(`
                            <div style="min-width:170px">${photo}
                                <strong>${Panel.esc(a.user_name)}</strong><br>
                                <small>${Panel.esc(a.user_position)} · ${Panel.esc(a.branch_name)}</small><br>
                                <small>Hora: ${Panel.esc(a.clock_in_at || '--')} · ${Panel.esc(a.distance_meters ?? '-')} m</small><br>
                                <small style="color:${color};font-weight:600">${a.within_geofence ? 'Dentro de la geocerca' : 'Fuera de la geocerca'}</small>
                            </div>`);
                    });
            })
            .catch((err) => console.warn('No se pudo actualizar el dashboard', err));
    }
    refresh();
    setInterval(refresh, 15000);

    // 3. Gráfico semanal
    let chart;
    function drawChart() {
        const text = Panel.cssVar('--bs-secondary-color');
        const grid = Panel.cssVar('--bs-border-color');
        if (chart) chart.destroy();
        chart = new Chart(document.getElementById('weeklyChartCanvas'), {
            type: 'bar',
            data: {
                labels: chartData.labels,
                datasets: [
                    { label: 'Puntuales', data: chartData.on_time, backgroundColor: '#198754', borderRadius: 4 },
                    { label: 'Tardanzas', data: chartData.late, backgroundColor: '#f59e0b', borderRadius: 4 },
                    { label: 'Ausencias', data: chartData.absent, backgroundColor: '#dc3545', borderRadius: 4 },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { labels: { color: text } } },
                scales: {
                    x: { grid: { display: false }, ticks: { color: text } },
                    y: { beginAtZero: true, grid: { color: grid }, ticks: { color: text, precision: 0 } },
                },
            },
        });
    }
    drawChart();
    document.addEventListener('panel:theme', drawChart);
});
</script>
@endsection
