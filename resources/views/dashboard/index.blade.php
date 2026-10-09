@extends('layouts.app')

@section('title', 'Dashboard en Vivo')

@section('styles')
<style>
    .kpi-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 1.25rem;
        margin-bottom: 2rem;
    }
    .kpi-card {
        background: var(--bg-surface, #111218);
        border: 1px solid var(--border, rgba(255,255,255,0.07));
        border-radius: var(--radius-lg, 16px);
        padding: 1.5rem;
        position: relative;
        overflow: hidden;
        transition: transform 0.2s, box-shadow 0.2s;
    }
    .kpi-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 30px rgba(0,0,0,0.4);
    }
    .kpi-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1rem;
    }
    .kpi-icon {
        width: 42px;
        height: 42px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .kpi-value {
        font-size: 2.25rem;
        font-weight: 800;
        line-height: 1.1;
        color: #fff;
        margin-bottom: 0.35rem;
    }
    .kpi-label {
        font-size: 0.85rem;
        color: var(--text-secondary, #94a3b8);
        font-weight: 500;
    }
    .dashboard-split {
        display: grid;
        grid-template-columns: 2fr 1fr;
        gap: 1.5rem;
        margin-bottom: 2rem;
    }
    @media (max-width: 1024px) {
        .dashboard-split {
            grid-template-columns: 1fr;
        }
    }
    .card-panel {
        background: var(--bg-surface, #111218);
        border: 1px solid var(--border, rgba(255,255,255,0.07));
        border-radius: var(--radius-lg, 16px);
        overflow: hidden;
    }
    .card-panel-header {
        padding: 1.25rem 1.5rem;
        border-bottom: 1px solid var(--border, rgba(255,255,255,0.07));
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .stream-list {
        max-height: 480px;
        overflow-y: auto;
    }
    .stream-item {
        padding: 1rem 1.25rem;
        border-bottom: 1px solid rgba(255,255,255,0.04);
        display: flex;
        align-items: center;
        gap: 12px;
        transition: background 0.2s;
    }
    .stream-item:hover {
        background: rgba(255,255,255,0.02);
    }
    .pulse-dot {
        width: 10px;
        height: 10px;
        border-radius: 50%;
        display: inline-block;
        animation: pulseAnimation 2s infinite;
    }
    @keyframes pulseAnimation {
        0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
        70% { transform: scale(1); box-shadow: 0 0 0 8px rgba(16, 185, 129, 0); }
        100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
    }
</style>
@endsection

@section('content')
@if($platform)
<div style="background: rgba(99, 102, 241, 0.12); border: 1px solid rgba(99, 102, 241, 0.3); border-radius: 12px; padding: 1rem 1.25rem; color: #c7d2fe; font-size: 0.9rem; margin-bottom: 1.5rem;">
    <strong>Plataforma:</strong> {{ $platform['active_tenants'] }} de {{ $platform['tenants'] }} empresas activas.
    Las cifras, el mapa y las marcaciones suman todas las empresas.
</div>
@endif
<div style="margin-bottom: 1.75rem; display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: gap;">
    <div>
        <h1 style="font-size: 1.85rem; font-weight: 800; color: #fff; margin-bottom: 0.25rem; letter-spacing: -0.5px;">Centro de Control en Tiempo Real</h1>
        <p style="color: var(--text-secondary); font-size: 0.92rem;">Monitoreo satelital de geocercas, marcaciones con selfie y flujo de colaboradores hoy: {{ \Carbon\Carbon::parse($today)->isoFormat('dddd D [de] MMMM, Y') }}</p>
    </div>
    <div style="display: flex; align-items: center; gap: 8px;">
        <span class="pulse-dot" style="background: #10b981;"></span>
        <span style="font-size: 0.85rem; color: #10b981; font-weight: 600;">Feed en vivo activo</span>
    </div>
</div>

<!-- 1. KPI CARDS -->
<div class="kpi-grid">
    <div class="kpi-card" style="border-top: 3px solid #10b981;">
        <div class="kpi-header">
            <span class="kpi-label">PRESENTES HOY</span>
            <div class="kpi-icon" style="background: rgba(16, 185, 129, 0.15); color: #10b981;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><polyline points="16 11 18 13 22 9"/></svg>
            </div>
        </div>
        <div class="kpi-value" id="kpiPresent">{{ $kpis['present_count'] }}</div>
        <div style="font-size: 0.8rem; color: #94a3b8;">
            <span style="color: #10b981; font-weight: 600;">{{ $kpis['attendance_rate'] }}%</span> de asistencia total ({{ $kpis['total_employees'] }} colaboradores)
        </div>
    </div>

    <div class="kpi-card" style="border-top: 3px solid #f59e0b;">
        <div class="kpi-header">
            <span class="kpi-label">TARDANZAS REGISTRADAS</span>
            <div class="kpi-icon" style="background: rgba(245, 158, 11, 0.15); color: #f59e0b;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </div>
        </div>
        <div class="kpi-value" id="kpiLate" style="color: #fbbf24;">{{ $kpis['late_count'] }}</div>
        <div style="font-size: 0.8rem; color: #94a3b8;">
            Tolerancia configurada por turno
        </div>
    </div>

    <div class="kpi-card" style="border-top: 3px solid #3b82f6;">
        <div class="kpi-header">
            <span class="kpi-label">EN DESCANSO / BREAK</span>
            <div class="kpi-icon" style="background: rgba(59, 130, 246, 0.15); color: #3b82f6;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 8h1a4 4 0 1 1 0 8h-1"/><path d="M3 8h14v9a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4Z"/><line x1="6" x2="6" y1="2" y2="4"/><line x1="10" x2="10" y1="2" y2="4"/><line x1="14" x2="14" y1="2" y2="4"/></svg>
            </div>
        </div>
        <div class="kpi-value" id="kpiBreak" style="color: #60a5fa;">{{ $kpis['on_break_count'] }}</div>
        <div style="font-size: 0.8rem; color: #94a3b8;">
            Pausa activa en este momento
        </div>
    </div>

    <div class="kpi-card" style="border-top: 3px solid #ef4444;">
        <div class="kpi-header">
            <span class="kpi-label">AUSENTES / PENDIENTES</span>
            <div class="kpi-icon" style="background: rgba(239, 68, 68, 0.15); color: #ef4444;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" x2="9" y1="9" y2="15"/><line x1="9" x2="15" y1="9" y2="15"/></svg>
            </div>
        </div>
        <div class="kpi-value" id="kpiAbsent" style="color: #f87171;">{{ $kpis['absent_count'] }}</div>
        <div style="font-size: 0.8rem; color: #94a3b8;">
            Sin registro de entrada hoy
        </div>
    </div>
</div>

<!-- 2. SPLIT: MAPA LEAFLET + LIVE STREAM -->
<div class="dashboard-split">
    <!-- MAPA LEAFLET INTERACTIVO -->
    <div class="card-panel">
        <div class="card-panel-header">
            <div>
                <h3 style="font-size: 1.1rem; font-weight: 700; color: #fff;">Mapa Satelital de Geocercas & Marcaciones</h3>
                <p style="font-size: 0.8rem; color: var(--text-secondary); margin-top: 2px;">Visualización de radio perimetral y puntos GPS de colaboradores</p>
            </div>
            <div style="font-size: 0.8rem; color: #818cf8; background: rgba(99, 102, 241, 0.1); padding: 4px 10px; border-radius: 8px;">
                {{ $branches->count() }} Sucursales Activas
            </div>
        </div>
        <div id="liveMap" style="height: 520px; width: 100%; background: #0a0b0f;"></div>
    </div>

    <!-- FEED EN TIEMPO REAL -->
    <div class="card-panel">
        <div class="card-panel-header">
            <div>
                <h3 style="font-size: 1.1rem; font-weight: 700; color: #fff;">Stream en Vivo</h3>
                <p style="font-size: 0.8rem; color: var(--text-secondary); margin-top: 2px;">Últimas marcaciones registradas</p>
            </div>
            <span style="font-size: 0.75rem; background: rgba(16, 185, 129, 0.15); color: #10b981; padding: 3px 8px; border-radius: 6px; font-weight: 600;">
                Auto-sync 15s
            </span>
        </div>
        <div class="stream-list" id="streamContainer">
            @forelse($recentAttendances as $att)
                <div class="stream-item">
                    <!-- Foto selfie o avatar -->
                    @if($att->clock_in_photo)
                        <img src="{{ attendance_photo_url($att, 'in') }}" alt="Selfie" style="width: 44px; height: 44px; border-radius: 10px; object-fit: cover; border: 2px solid {{ $att->clock_in_within_geofence ? '#10b981' : '#ef4444' }}; cursor: pointer;" onclick="openPhotoModal('{{ attendance_photo_url($att, 'in') }}', '{{ $att->user->first_name }} {{ $att->user->last_name }}', 'Hora: {{ $att->clock_in_at->format('H:i:s') }} | Distancia: {{ $att->clock_in_distance_meters }}m')">
                    @else
                        <div style="width: 44px; height: 44px; border-radius: 10px; background: #1e1b4b; display: flex; align-items: center; justify-content: center; color: #818cf8; font-weight: 700; font-size: 0.9rem;">
                            {{ substr($att->user->first_name ?? 'U', 0, 1) }}{{ substr($att->user->last_name ?? 'U', 0, 1) }}
                        </div>
                    @endif

                    <div style="flex: 1; min-width: 0;">
                        <div style="font-weight: 600; color: #fff; font-size: 0.9rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                            {{ $att->user->first_name }} {{ $att->user->last_name }}
                        </div>
                        @include('partials.tenant-badge', ['tenantId' => $att->tenant_id])
                        <div style="font-size: 0.75rem; color: #94a3b8;">
                            {{ $att->branch->name ?? 'Sucursal' }} • 
                            <span style="color: {{ $att->clock_in_within_geofence ? '#10b981' : '#ef4444' }};">
                                {{ $att->clock_in_distance_meters }}m ({{ $att->clock_in_within_geofence ? 'Dentro' : 'Fuera' }})
                            </span>
                        </div>
                    </div>

                    <div style="text-align: right;">
                        <div style="font-size: 0.85rem; font-weight: 700; color: #fff;">
                            {{ $att->clock_in_at ? $att->clock_in_at->format('H:i') : '--:--' }}
                        </div>
                        <span style="font-size: 0.7rem; padding: 2px 6px; border-radius: 4px; font-weight: 600; display: inline-block;
                            @if($att->status === 'present') background: rgba(16, 185, 129, 0.15); color: #10b981;
                            @elseif($att->status === 'late') background: rgba(245, 158, 11, 0.15); color: #f59e0b;
                            @else background: rgba(99, 102, 241, 0.15); color: #818cf8; @endif">
                            {{ $att->status === 'present' ? 'Puntual' : ($att->status === 'late' ? 'Tardanza' : $att->status) }}
                        </span>
                    </div>
                </div>
            @empty
                <div style="padding: 2.5rem 1rem; text-align: center; color: #64748b; font-size: 0.9rem;">
                    No hay marcaciones registradas todavía hoy.
                </div>
            @endforelse
        </div>
    </div>
</div>

<!-- 3. GRÁFICO DE PUNTUALIDAD SEMANAL (CHART.JS) -->
<div class="card-panel">
    <div class="card-panel-header">
        <div>
            <h3 style="font-size: 1.1rem; font-weight: 700; color: #fff;">Tendencia de Asistencia y Puntualidad (Últimos 7 Días)</h3>
            <p style="font-size: 0.8rem; color: var(--text-secondary); margin-top: 2px;">Comparativa diaria entre colaboradores puntuales, tardanzas e inasistencias</p>
        </div>
    </div>
    <div style="padding: 1.5rem; height: 320px;">
        <canvas id="weeklyChartCanvas"></canvas>
    </div>
</div>
@endsection

@section('scripts')
<script>
    // 1. INICIALIZACIÓN DEL MAPA LEAFLET
    const branchesData = @json($branches);
    let map;
    let employeeMarkers = [];

    document.addEventListener('DOMContentLoaded', function() {
        // Coordenadas centrales iniciales
        let initialLat = -12.046374;
        let initialLng = -77.042793;

        if (branchesData.length > 0) {
            initialLat = parseFloat(branchesData[0].latitude);
            initialLng = parseFloat(branchesData[0].longitude);
        }

        map = L.map('liveMap', {
            zoomControl: true,
            scrollWheelZoom: true
        }).setView([initialLat, initialLng], 14);

        // Capa de mapa CartoDB Dark Matter (tema oscuro premium)
        L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
            attribution: '&copy; OpenStreetMap contributors &copy; CARTO',
            subdomains: 'abcd',
            maxZoom: 19
        }).addTo(map);

        // Dibujar sucursales y sus geocercas permitidas (L.circle)
        branchesData.forEach(branch => {
            const lat = parseFloat(branch.latitude);
            const lng = parseFloat(branch.longitude);
            const radius = parseInt(branch.allowed_radius_meters) || 50;

            // Círculo de geocerca permitido
            L.circle([lat, lng], {
                color: '#6366f1',
                fillColor: '#6366f1',
                fillOpacity: 0.15,
                weight: 2,
                radius: radius
            }).addTo(map).bindPopup(`
                <div style="color: #0f172a; padding: 4px;">
                    <div style="font-weight: 700; font-size: 14px;">📍 ${branch.name}</div>
                    <div style="font-size: 12px; color: #64748b;">${branch.address}</div>
                    <div style="font-size: 11px; margin-top: 4px; color: #4338ca; font-weight: 600;">Radio Geocerca: ${radius} metros</div>
                </div>
            `);

            // Pin del centro de la sucursal
            L.marker([lat, lng]).addTo(map);
        });

        // 2. INICIALIZACIÓN DE CHART.JS
        initWeeklyChart();

        // 3. PRIMER RECARGA Y POLLING EN VIVO (CADA 15 SEGUNDOS)
        fetchLiveUpdates();
        setInterval(fetchLiveUpdates, 15000);
    });

    // POLLING EN VIVO
    function fetchLiveUpdates() {
        fetch('{{ route('dashboard.live-stream') }}')
            .then(res => res.json())
            .then(data => {
                if (!data.success) return;

                // Actualizar KPIs
                document.getElementById('kpiPresent').innerText = data.kpis.present_count;
                document.getElementById('kpiLate').innerText = data.kpis.late_count;
                document.getElementById('kpiBreak').innerText = data.kpis.on_break_count;
                document.getElementById('kpiAbsent').innerText = data.kpis.absent_count;

                // Limpiar marcadores antiguos de colaboradores
                employeeMarkers.forEach(m => map.removeLayer(m));
                employeeMarkers = [];

                // Renderizar nuevos marcadores en el mapa
                data.attendances.forEach(att => {
                    if (att.latitude && att.longitude) {
                        const markerColor = att.within_geofence ? '#10b981' : '#ef4444';
                        
                        // Marcador circular personalizado
                        const empIcon = L.divIcon({
                            className: 'custom-emp-marker',
                            html: `<div style="width: 22px; height: 22px; border-radius: 50%; background: ${markerColor}; border: 3px solid #fff; box-shadow: 0 0 10px ${markerColor};"></div>`,
                            iconSize: [22, 22],
                            iconAnchor: [11, 11]
                        });

                        const empMarker = L.marker([att.latitude, att.longitude], { icon: empIcon })
                            .addTo(map)
                            .bindPopup(`
                                <div style="color: #0f172a; padding: 4px; min-width: 180px;">
                                    ${att.photo_url ? `<img src="${att.photo_url}" style="width: 100%; height: 90px; object-fit: cover; border-radius: 6px; margin-bottom: 6px;">` : ''}
                                    <div style="font-weight: 700; font-size: 13px;">${att.user_name}</div>
                                    <div style="font-size: 11px; color: #64748b;">${att.user_position}</div>
                                    <div style="font-size: 11px; margin-top: 4px;">
                                        <strong>Hora:</strong> ${att.clock_in_at || '--'} | <strong>Dist:</strong> ${att.distance_meters}m
                                    </div>
                                    <div style="margin-top: 4px; font-size: 11px; font-weight: 700; color: ${att.within_geofence ? '#059669' : '#dc2626'};">
                                        ${att.within_geofence ? '✓ Dentro de Geocerca' : '⚠ Fuera de Geocerca'}
                                    </div>
                                </div>
                            `);

                        employeeMarkers.push(empMarker);
                    }
                });
            })
            .catch(err => console.error("Error sincronizando dashboard:", err));
    }

    // CHART.JS TENDENCIA
    function initWeeklyChart() {
        const chartData = @json($weeklyChart);
        const ctx = document.getElementById('weeklyChartCanvas').getContext('2d');

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: chartData.labels,
                datasets: [
                    {
                        label: 'Puntuales',
                        data: chartData.on_time,
                        backgroundColor: '#10b981',
                        borderRadius: 6,
                    },
                    {
                        label: 'Tardanzas',
                        data: chartData.late,
                        backgroundColor: '#f59e0b',
                        borderRadius: 6,
                    },
                    {
                        label: 'Ausencias',
                        data: chartData.absent,
                        backgroundColor: '#ef4444',
                        borderRadius: 6,
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        labels: { color: '#94a3b8', font: { family: 'Inter', size: 12 } }
                    }
                },
                scales: {
                    x: {
                        grid: { color: 'rgba(255,255,255,0.05)' },
                        ticks: { color: '#94a3b8', font: { family: 'Inter' } }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(255,255,255,0.05)' },
                        ticks: { color: '#94a3b8', font: { family: 'Inter' } }
                    }
                }
            }
        });
    }
</script>
@endsection
