@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
@php
    $navUser = Auth::user();
    $totalRegs = $kpis['total_attendances'] ?? $kpis['present_count'];
    $activeBranches = $branches->count();
    $brandTenant = app(\App\Modules\Core\Services\TenantContext::class)->getOrNull() ?? $navUser?->tenant;
    $brandSettings = \App\Modules\Core\Models\TenantSetting::forTenant($brandTenant);
    $currentHour = (int) now(tenant_timezone())->format('G');
    $initialGreeting = match(true) {
        $currentHour >= 6 && $currentHour < 12 => 'BUENOS DÍAS',
        $currentHour >= 12 && $currentHour < 19 => 'BUENAS TARDES',
        default => 'BUENAS NOCHES',
    };
@endphp

@if(!empty($platform))
    <div class="alert alert-primary d-flex align-items-center gap-2 mb-4" role="status" style="border-radius: 8px; font-size: 0.85rem;">
        <i class="bi bi-buildings fs-5 text-primary"></i>
        <div>
            <strong>Plataforma:</strong> {{ $platform['active_tenants'] }} de {{ $platform['tenants'] }} empresas activas.
            Las cifras, el mapa y las marcaciones suman todas las empresas.
        </div>
    </div>
@endif

{{-- 1. Cabecera con Saludo Dinámico, Reloj en Tiempo Real y Filtros Rápidos --}}
<div class="dashboard-header-bar">
    <div class="greeting-section">
        <div>
            <h1 class="greeting-title" id="liveGreetingTitle" data-user-name="{{ $navUser->first_name }} {{ $navUser->last_name }}">
                {{ $initialGreeting }}, {{ strtoupper($navUser->first_name . ' ' . $navUser->last_name) }}
            </h1>
            <div class="greeting-clock" id="liveClockText">
                {{ now()->format('h:i:s a') }} • {{ now()->isoFormat('dddd, D [de] MMMM [de] YYYY') }}
            </div>
        </div>
    </div>

    <div class="header-controls">
        @if($navUser->isSuperAdmin())
            <form method="POST" action="{{ route('admin.tenant.switch') }}" class="m-0">
                @csrf
                <div class="header-company-select" title="Filtrar por empresa">
                    <i class="bi bi-buildings text-primary"></i>
                    <select name="tenant_id" onchange="this.form.submit()" aria-label="Empresa a administrar">
                        <option value="">Todas las empresas</option>
                        @foreach(\App\Modules\Core\Models\Tenant::orderBy('company_name')->get(['id', 'company_name']) as $t)
                            <option value="{{ $t->id }}" @selected($brandTenant?->id === $t->id)>{{ $t->company_name }}</option>
                        @endforeach
                    </select>
                </div>
            </form>
        @endif

        {{-- Filtro de Sede / Oficina (Carga 100% interna asíncrona sin alterar URL) --}}
        <div class="m-0 d-inline-flex" id="branchFilterContainer">
            <div class="header-company-select" title="Filtrar por sede / oficina">
                <i class="bi bi-geo-alt text-primary"></i>
                <select id="branchSelectFilter" aria-label="Filtrar por oficina">
                    <option value="">Todas las sedes ({{ $allBranches->count() }})</option>
                    @foreach($allBranches as $br)
                        <option value="{{ $br->id }}" @selected($selectedBranchId === $br->id)>{{ $br->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        {{-- Filtro de Calendario / Período Interactivo Profesional (Screenshot 1) --}}
        <div class="dropdown" id="dashboardCalendarDropdown">
            <button type="button" class="date-filter-pill dropdown-toggle" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" id="dateFilterDropdownBtn">
                <i class="bi bi-calendar3 text-primary"></i>
                <span class="fw-bold text-dark" id="calTriggerLabel">{{ $presetLabel }}</span>
                <span class="divider">|</span>
                <span id="calTriggerFrom">{{ \Carbon\Carbon::parse($dateFrom)->format('d/m/Y') }}</span>
                <span class="divider">|</span>
                <span id="calTriggerTo">{{ \Carbon\Carbon::parse($dateTo)->format('d/m/Y') }}</span>
            </button>
            <div class="dropdown-menu dropdown-menu-end calendar-pro-popover shadow-lg" aria-labelledby="dateFilterDropdownBtn">
                <div class="calendar-pro-grid">
                    {{-- Columna Izquierda: ACCESOS RÁPIDOS --}}
                    <div class="calendar-sidebar-presets">
                        <div class="calendar-presets-heading">ACCESOS RÁPIDOS</div>
                        <button type="button" class="calendar-preset-btn {{ $preset === 'today' ? 'is-active' : '' }}" data-preset="today">
                            <span>Hoy</span>
                            <i class="bi bi-check-lg {{ $preset === 'today' ? '' : 'd-none' }}"></i>
                        </button>
                        <button type="button" class="calendar-preset-btn {{ $preset === 'yesterday' ? 'is-active' : '' }}" data-preset="yesterday">
                            <span>Ayer</span>
                            <i class="bi bi-check-lg {{ $preset === 'yesterday' ? '' : 'd-none' }}"></i>
                        </button>
                        <button type="button" class="calendar-preset-btn {{ in_array($preset, ['last_7_days', 'week']) ? 'is-active' : '' }}" data-preset="week">
                            <span>Últimos 7 días</span>
                            <i class="bi bi-check-lg {{ in_array($preset, ['last_7_days', 'week']) ? '' : 'd-none' }}"></i>
                        </button>
                        <button type="button" class="calendar-preset-btn {{ $preset === 'last_30_days' ? 'is-active' : '' }}" data-preset="last_30_days">
                            <span>Últimos 30 días</span>
                            <i class="bi bi-check-lg {{ $preset === 'last_30_days' ? '' : 'd-none' }}"></i>
                        </button>
                        <button type="button" class="calendar-preset-btn {{ in_array($preset, ['this_month', 'month']) ? 'is-active' : '' }}" data-preset="month">
                            <span>Este mes</span>
                            <i class="bi bi-check-lg {{ in_array($preset, ['this_month', 'month']) ? '' : 'd-none' }}"></i>
                        </button>
                        <button type="button" class="calendar-preset-btn {{ $preset === 'prev_month' ? 'is-active' : '' }}" data-preset="prev_month">
                            <span>Mes anterior</span>
                            <i class="bi bi-check-lg {{ $preset === 'prev_month' ? '' : 'd-none' }}"></i>
                        </button>
                    </div>

                    {{-- Columna Derecha: Selector de Fechas y Calendario del Mes --}}
                    <div class="calendar-content-pane">
                        <div class="calendar-date-inputs-row">
                            <div class="calendar-input-field is-active" id="calFromInputWrapper">
                                <label class="calendar-input-label">Desde</label>
                                <input type="text" id="calInputFrom" value="{{ \Carbon\Carbon::parse($dateFrom)->format('d/m/Y') }}" placeholder="DD/MM/AAAA" readonly>
                            </div>
                            <div class="calendar-input-field" id="calToInputWrapper">
                                <label class="calendar-input-label">Hasta</label>
                                <input type="text" id="calInputTo" value="{{ \Carbon\Carbon::parse($dateTo)->format('d/m/Y') }}" placeholder="DD/MM/AAAA" readonly>
                            </div>
                        </div>

                        {{-- Navegación del mes --}}
                        <div class="calendar-nav-header">
                            <button type="button" class="calendar-nav-btn" id="calPrevMonthBtn" title="Mes anterior">
                                <i class="bi bi-chevron-left"></i>
                            </button>
                            <span class="calendar-month-title" id="calCurrentMonthTitle">Octubre De 2026</span>
                            <button type="button" class="calendar-nav-btn" id="calNextMonthBtn" title="Mes siguiente">
                                <i class="bi bi-chevron-right"></i>
                            </button>
                        </div>

                        {{-- Días de la semana --}}
                        <div class="calendar-days-grid mb-1">
                            <div class="calendar-day-header">L</div>
                            <div class="calendar-day-header">M</div>
                            <div class="calendar-day-header">X</div>
                            <div class="calendar-day-header">J</div>
                            <div class="calendar-day-header">V</div>
                            <div class="calendar-day-header">S</div>
                            <div class="calendar-day-header">D</div>
                        </div>

                        {{-- Días dinámicos del mes --}}
                        <div class="calendar-days-grid" id="calDaysGrid">
                            {{-- Generado dinámicamente por JavaScript --}}
                        </div>

                        <div class="calendar-helper-note">
                            Selecciona la fecha inicial. Se incluyen ambos días.
                        </div>

                        <div class="calendar-footer-actions">
                            <button type="button" class="calendar-btn-cancel" id="calCancelBtn">Cancelar</button>
                            <button type="button" class="calendar-btn-apply" id="calApplyBtn">Aplicar</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- 2. Tarjetas KPI Principales con Bordes de Acento Neón --}}
<div class="row g-3 mb-4">
    {{-- Card 1: Volumen de Asistencia (Acento Cian) --}}
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card accent-cyan">
            <div class="kpi-header">
                <span class="kpi-title">Asistencias del Período</span>
                <span class="kpi-icon-squircle cyan"><i class="bi bi-clock-history"></i></span>
            </div>
            <div class="kpi-number" id="kpiTotal">{{ $totalRegs }}</div>
            <div class="kpi-footer-row">
                <span class="kpi-footer-bullet"><span class="bullet-dot emerald"></span> Puntuales: <b id="kpiPresentText">{{ $kpis['on_time_count'] }}</b></span>
                <span class="kpi-footer-bullet"><span class="bullet-dot blue"></span> Tardanzas: <b id="kpiLateText">{{ $kpis['late_count'] }}</b></span>
            </div>
        </div>
    </div>

    {{-- Card 2: Tasa de Puntualidad (Acento Esmeralda) --}}
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card accent-emerald">
            <div class="kpi-header">
                <span class="kpi-title">Tasa de Puntualidad</span>
                <span class="kpi-icon-squircle emerald"><i class="bi bi-award"></i></span>
            </div>
            <div class="kpi-number emerald" id="kpiRate">{{ $kpis['punctuality_rate'] }}%</div>
            <div class="kpi-footer-row justify-content-between">
                <span id="kpiAbsentText">{{ $kpis['absent_count'] }} ausentes</span>
                <span class="fw-bold">Meta: <span class="text-emerald">≥ 95%</span></span>
            </div>
        </div>
    </div>

    {{-- Card 3: Sedes y Geocercas (Acento Azul Eléctrico) --}}
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card accent-blue">
            <div class="kpi-header">
                <span class="kpi-title">Sedes y Geocercas</span>
                <span class="kpi-icon-squircle blue"><i class="bi bi-geo-alt-fill"></i></span>
            </div>
            <div class="kpi-number" id="kpiActiveBranches">{{ $activeBranches }}</div>
            <div class="kpi-footer-row">
                <span>Perímetros: <span class="{{ ($calibratedRate ?? 100) >= 100 ? 'text-success' : (($calibratedRate ?? 100) >= 80 ? 'text-primary' : 'text-warning') }} fw-bold" id="kpiCalibratedText">{{ $calibratedRate ?? 100 }}% Calibrados</span></span>
            </div>
        </div>
    </div>

    {{-- Card 4: Colaboradores en Turno (Acento Ámbar) --}}
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card accent-amber">
            <div class="kpi-header">
                <span class="kpi-title">Colaboradores en Turno</span>
                <span class="kpi-icon-squircle amber"><i class="bi bi-people-fill"></i></span>
            </div>
            <div class="kpi-number" id="kpiActiveEmp">{{ $totalRegs }} / {{ $kpis['total_employees'] }}</div>
            <div class="kpi-footer-row">
                <span class="kpi-footer-bullet"><span class="bullet-dot emerald"></span> En sede: <span class="text-success fw-bold" id="kpiOnShiftText">{{ $kpis['active_on_shift'] ?? $totalRegs }} activos</span></span>
            </div>
        </div>
    </div>
</div>

{{-- 3. Barra Horizontal de Estado / Telemetría Operativa Real --}}
<div class="telemetry-strip">
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <span class="telemetry-pill-btn">
            <i class="bi bi-shield-check"></i> Monitoreo Operativo
        </span>
        <span class="telemetry-badge-pill" id="telemetryBranchesBadge">
            <i class="bi bi-geo-alt text-primary"></i> Geocercas GPS: {{ $activeBranches }} Sedes
        </span>
        <span class="telemetry-badge-pill">
            <i class="bi bi-camera text-info"></i>
            @if($brandSettings->face_detection_required)
                Detección Facial y Selfie Activas
            @elseif($brandSettings->selfie_required)
                Evidencia Biométrica (Selfie)
            @else
                Evidencia Fotográfica Opcional
            @endif
        </span>
    </div>

    <div class="d-flex align-items-center gap-2">
        <span class="live-indicator-pill">
            <span class="live-pulse-dot"></span> En Vivo
        </span>
        <button type="button" class="topbar-icon-btn" id="telemetryRefreshBtn" onclick="window.refreshDashboardTelemetry(this)" title="Sincronizar telemetría internamente" style="width: 32px; height: 32px;">
            <i class="bi bi-arrow-repeat"></i>
        </button>
    </div>
</div>

{{-- 4. Sección Central: Métricas Circulares, Distribución Horaria y Mapa GPS --}}
<div class="row g-3 mb-4">
    {{-- Columna Izquierda: Diales de Progreso Circular --}}
    <div class="col-12 col-xl-3">
        <div class="dark-panel-card">
            <div class="card-header-clean">
                <span class="card-title-clean">Métricas del Periodo</span>
                <i class="bi bi-pie-chart text-secondary"></i>
            </div>

            <div class="progress-dial-group">
                <div class="circular-dial-wrapper">
                    <svg width="52" height="52" viewBox="0 0 52 52">
                        <circle class="dial-bg" cx="26" cy="26" r="21" />
                        <circle class="dial-meter" id="dialPunctualityMeter" cx="26" cy="26" r="21" stroke-dasharray="131.9" stroke-dashoffset="{{ 131.9 - (131.9 * min(max($kpis['punctuality_rate'], 0), 100) / 100) }}" style="stroke: var(--accent-cyan);" />
                    </svg>
                    <span class="dial-center-text" id="dialPunctualityText">{{ $kpis['punctuality_rate'] }}%</span>
                </div>
                <div class="dial-details">
                    <div class="dial-details-title">Puntualidad del Período</div>
                    <div class="dial-details-val" id="dialPunctualityVal">{{ $kpis['on_time_count'] }} puntuales</div>
                    <div class="dial-details-sub" id="dialPunctualitySub">{{ $kpis['late_count'] }} con tardanza</div>
                </div>
            </div>

            <div class="progress-dial-group">
                @php
                    $coverageRate = $kpis['coverage_rate'] ?? ($kpis['total_employees'] > 0 ? round(($totalRegs / $kpis['total_employees']) * 100) : 0);
                @endphp
                <div class="circular-dial-wrapper">
                    <svg width="52" height="52" viewBox="0 0 52 52">
                        <circle class="dial-bg" cx="26" cy="26" r="21" />
                        <circle class="dial-meter" id="dialCoverageMeter" cx="26" cy="26" r="21" stroke-dasharray="131.9" stroke-dashoffset="{{ 131.9 - (131.9 * min(max($coverageRate, 0), 100) / 100) }}" style="stroke: var(--accent-emerald);" />
                    </svg>
                    <span class="dial-center-text" id="dialCoverageText">{{ $coverageRate }}%</span>
                </div>
                <div class="dial-details">
                    <div class="dial-details-title">Cobertura de Turno</div>
                    <div class="dial-details-val" id="dialCoverageVal">{{ $totalRegs }} / {{ $kpis['total_employees'] }}</div>
                    <div class="dial-details-sub" id="dialCoverageSub">{{ $kpis['absent_count'] }} ausentes</div>
                </div>
            </div>

            {{-- 3er Dial: Cumplimiento de Geocercas GPS (Elimina espacio muerto) --}}
            <div class="progress-dial-group">
                <div class="circular-dial-wrapper">
                    <svg width="52" height="52" viewBox="0 0 52 52">
                        <circle class="dial-bg" cx="26" cy="26" r="21" />
                        <circle class="dial-meter" id="dialGeofenceMeter" cx="26" cy="26" r="21" stroke-dasharray="131.9" stroke-dashoffset="{{ 131.9 - (131.9 * min(max($kpis['geofence_rate'] ?? 100, 0), 100) / 100) }}" style="stroke: var(--accent-blue);" />
                    </svg>
                    <span class="dial-center-text" id="dialGeofenceText">{{ $kpis['geofence_rate'] ?? 100 }}%</span>
                </div>
                <div class="dial-details">
                    <div class="dial-details-title">Cumplimiento Geocercas</div>
                    <div class="dial-details-val" id="dialGeofenceVal">{{ $kpis['within_geofence_count'] ?? $totalRegs }} en radio</div>
                    <div class="dial-details-sub" id="dialGeofenceSub">{{ $kpis['out_of_geofence'] ?? 0 }} fuera de radio</div>
                </div>
            </div>

            <div class="mt-auto pt-3 small text-secondary d-flex align-items-center gap-2">
                <span class="bullet-dot emerald"></span> Sincronización continua activa (15s)
            </div>
        </div>
    </div>

    {{-- Columna Central: Gráfico de Distribución Horaria --}}
    <div class="col-12 col-xl-5">
        <div class="dark-panel-card">
            <div class="card-header-clean">
                <span class="card-title-clean">Distribución Horaria de Actividad</span>
                <span class="badge text-bg-light border" style="font-size: 0.7rem;">Hoy (24 Horas)</span>
            </div>
            <div style="height: 250px; position: relative;">
                <canvas id="hourlyActivityChart" aria-label="Distribución horaria" role="img"></canvas>
            </div>
        </div>
    </div>

    {{-- Columna Derecha: Geolocalización / Mapa de Geocercas --}}
    <div class="col-12 col-xl-4">
        <div class="dark-panel-card">
            <div class="card-header-clean">
                <span class="card-title-clean"><i class="bi bi-globe2 text-primary me-1"></i> Geolocalización GPS</span>
                <span class="text-secondary small" style="font-size: 0.75rem;">{{ $activeBranches }} sedes activas</span>
            </div>
            <div id="liveMap" class="map-frame" style="height: 250px;"></div>
            <div class="d-flex justify-content-between align-items-center mt-2 pt-1 small text-secondary" style="font-size: 0.75rem;">
                <span><i class="bi bi-circle-fill text-primary me-1" style="font-size: 0.6rem;"></i> Perímetro</span>
                <span><i class="bi bi-circle-fill text-success me-1" style="font-size: 0.6rem;"></i> Dentro</span>
                <span><i class="bi bi-circle-fill text-danger me-1" style="font-size: 0.6rem;"></i> Fuera</span>
            </div>
        </div>
    </div>
</div>

{{-- 5. Actividad Reciente y Puntualidad Semanal --}}
<div class="row g-3">
    <div class="col-12 col-xl-7">
        <div class="dark-panel-card">
            <div class="card-header-clean">
                <span class="card-title-clean">Puntualidad de los Últimos 7 Días</span>
                <span class="badge text-bg-light border" style="font-size: 0.72rem;">Semana en curso</span>
            </div>
            <div style="height: 280px; position: relative;">
                <canvas id="weeklyChartCanvas" aria-label="Gráfico semanal" role="img"></canvas>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-5">
        <div class="dark-panel-card">
            <div class="card-header-clean">
                <span class="card-title-clean">Transmisión de Marcaciones en Vivo</span>
                <span class="badge bg-success-subtle"><span class="badge-dot"></span> Tiempo Real</span>
            </div>
            <div class="list-group list-group-flush stream-list" id="streamContainer" style="max-height: 290px; overflow-y: auto;">
                @forelse($recentAttendances as $att)
                    <div class="list-group-item d-flex align-items-center gap-3 px-2 py-2" data-attendance-id="{{ $att->id }}">
                        @if($att->clock_in_photo)
                            <div class="selfie-wrapper">
                                <img src="{{ attendance_photo_thumb_url($att, 'in') }}" alt="Selfie de {{ $att->user->first_name }}"
                                     class="selfie-thumb {{ $att->clock_in_within_geofence ? '' : 'is-outside' }}"
                                     data-photo="{{ attendance_photo_url($att, 'in') }}"
                                     data-photo-title="{{ $att->user->first_name }} {{ $att->user->last_name }}"
                                     data-photo-meta="Hora: {{ $att->clock_in_at->format('h:i:s a') }} · Distancia: {{ $att->clock_in_distance_meters }} m"
                                     onerror="this.onerror=null; this.parentElement.innerHTML='<span class=\'topbar-avatar\' style=\'width:36px;height:36px;\'>{{ mb_substr($att->user->first_name ?? 'U', 0, 1) }}</span>'">
                            </div>
                        @else
                            <div class="selfie-wrapper">
                                <span class="topbar-avatar" style="width: 36px; height: 36px;">{{ mb_substr($att->user->first_name ?? 'U', 0, 1) }}{{ mb_substr($att->user->last_name ?? '', 0, 1) }}</span>
                            </div>
                        @endif
                        <div class="flex-grow-1 min-w-0">
                            <div class="fw-bold text-truncate" style="font-size: 0.85rem; color: var(--text-primary);">{{ $att->user->first_name }} {{ $att->user->last_name }}</div>
                            <div class="small text-secondary text-truncate" style="font-size: 0.75rem;">
                                {{ $att->branch->name ?? 'Sede Principal' }} ·
                                <span class="{{ $att->clock_in_within_geofence ? 'text-success' : 'text-danger' }}">{{ $att->clock_in_distance_meters }} m</span>
                            </div>
                        </div>
                        <div class="text-end">
                            <div class="fw-bold" style="font-size: 0.825rem; color: var(--text-primary);">{{ $att->clock_in_at?->format('h:i a') ?? '--:--' }}</div>
                            @include('partials.attendance-status', ['status' => $att->status])
                        </div>
                    </div>
                @empty
                    <div class="empty-state text-center py-4">
                        <i class="bi bi-inbox text-secondary fs-3 d-block mb-2"></i>
                        <div class="fw-semibold small" style="color: var(--text-primary);">Sin actividad reciente en el periodo seleccionado</div>
                        <div class="text-secondary small" style="font-size: 0.75rem;">Las transmisiones aparecerán automáticamente aquí.</div>
                    </div>
                @endforelse
            </div>
            <div class="px-3 py-2 border-top bg-light-subtle d-flex justify-content-between align-items-center" style="font-size: 0.725rem; color: #64748b; border-color: var(--border-color) !important;">
                <span>Mostrando <b id="streamCount">{{ count($recentAttendances) }}</b> marcaciones</span>
                <span><i class="bi bi-shield-check text-success me-1"></i> Telemetría activa</span>
            </div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    // 0. Mantener la barra de direcciones 100% limpia en /dashboard sin query strings
    if (window.location.search) {
        try {
            window.history.replaceState({}, document.title, window.location.pathname);
        } catch (e) {
            /* ignore */
        }
    }

    const branches = @json($branches);
    const allBranchesList = @json($allBranches);
    const chartData = @json($weeklyChart);
    const hoursLabels = @json($hourlyLabels);
    const hourlyActivity = @json($hourlyDistribution);

    // 1. Mapa Leaflet Dinámico con Capa Reactiva de Sedes
    const first = branches[0] || allBranchesList[0];
    const map = L.map('liveMap', { scrollWheelZoom: false })
        .setView(first && first.latitude ? [parseFloat(first.latitude), parseFloat(first.longitude)] : [-8.11823, -79.02052], first ? 14 : 12);
    Panel.tiles(map);

    const mapCirclesLayer = L.layerGroup().addTo(map);

    function renderMapBranches(branchId = null) {
        mapCirclesLayer.clearLayers();
        const bounds = [];
        const toShow = branchId 
            ? allBranchesList.filter(b => b.id === branchId)
            : allBranchesList;

        toShow.forEach((b) => {
            if (!b.latitude || !b.longitude) return;
            const center = [parseFloat(b.latitude), parseFloat(b.longitude)];
            const radius = parseInt(b.allowed_radius_meters, 10) || 50;
            L.circle(center, { radius, color: '#0ea5e9', fillColor: '#0ea5e9', fillOpacity: .18, weight: 2 })
                .addTo(mapCirclesLayer)
                .bindPopup(`<strong>${Panel.esc(b.name)}</strong><br><small>Radio: ${radius} m</small>`);
            bounds.push(center);
        });

        if (bounds.length === 1) {
            map.setView(bounds[0], 15);
        } else if (bounds.length > 1) {
            map.fitBounds(bounds, { padding: [30, 30], maxZoom: 15 });
        }
    }

    let currentBranchId = @json($selectedBranchId);
    renderMapBranches(currentBranchId);

    // 2. Gráfico de Distribución Horaria Real
    const hourlyCtx = document.getElementById('hourlyActivityChart');
    let hourlyChartInstance = null;
    if (hourlyCtx) {
        hourlyChartInstance = new Chart(hourlyCtx, {
            type: 'bar',
            data: {
                labels: hoursLabels,
                datasets: [{
                    label: 'Registros',
                    data: hourlyActivity,
                    backgroundColor: '#0284c7',
                    hoverBackgroundColor: '#0369a1',
                    borderRadius: 4,
                    barThickness: 14,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#0f172a',
                        borderColor: '#e2e8f0',
                        borderWidth: 1,
                        titleColor: '#fff',
                        bodyColor: '#cbd5e1',
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { color: '#64748b', font: { size: 10 } }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(0, 0, 0, 0.06)' },
                        ticks: { color: '#64748b', font: { size: 10 }, stepSize: 2 }
                    }
                }
            }
        });
    }

    // 3. Gráfico Semanal / Tendencia
    const weeklyCtx = document.getElementById('weeklyChartCanvas');
    let weeklyChartInstance = null;
    if (weeklyCtx) {
        weeklyChartInstance = new Chart(weeklyCtx, {
            type: 'bar',
            data: {
                labels: chartData.labels,
                datasets: [
                    { label: 'Puntuales', data: chartData.on_time, backgroundColor: '#059669', borderRadius: 4, barThickness: 14 },
                    { label: 'Tardanzas', data: chartData.late, backgroundColor: '#d97706', borderRadius: 4, barThickness: 14 },
                    { label: 'Ausencias', data: chartData.absent, backgroundColor: '#dc2626', borderRadius: 4, barThickness: 14 },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        align: 'end',
                        labels: {
                            color: '#64748b',
                            usePointStyle: true,
                            pointStyle: 'circle',
                            font: { family: "'Plus Jakarta Sans', sans-serif", size: 11 }
                        }
                    },
                    tooltip: {
                        backgroundColor: '#0f172a',
                        borderColor: '#e2e8f0',
                        borderWidth: 1,
                        titleColor: '#fff',
                        bodyColor: '#cbd5e1',
                        cornerRadius: 8,
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { color: '#64748b', font: { size: 11 } }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(0, 0, 0, 0.06)' },
                        ticks: { color: '#64748b', precision: 0, font: { size: 11 } }
                    },
                },
            },
        });
    }

    // Filtro de Sede / Oficina Reactivo 100% Asíncrono (Cero recarga de página, cero parámetros en URL)
    const branchSelectEl = document.getElementById('branchSelectFilter');
    if (branchSelectEl) {
        branchSelectEl.addEventListener('change', (e) => {
            currentBranchId = e.target.value ? parseInt(e.target.value, 10) : null;
            renderMapBranches(currentBranchId);
            window.fetchDashboardData({ branch_id: currentBranchId }, true, false);
        });
    }

    // 4. Variables de estado del dashboard
    let activePreset = @json($preset) || 'today';
    let activeDateFrom = @json($dateFrom);
    let activeDateTo = @json($dateTo);
    let renderedAttendanceIds = Array.from(document.querySelectorAll('#streamContainer [data-attendance-id]'))
        .map(el => parseInt(el.dataset.attendanceId, 10));

    function formatToDMY(isoDateStr) {
        if (!isoDateStr) return '';
        const parts = isoDateStr.split('-');
        if (parts.length === 3) return `${parts[2]}/${parts[1]}/${parts[0]}`;
        return isoDateStr;
    }

    function formatToISO(dmyStr) {
        if (!dmyStr) return '';
        const parts = dmyStr.split('/');
        if (parts.length === 3) return `${parts[2]}-${parts[1]}-${parts[0]}`;
        return dmyStr;
    }

    // 5. MOTOR DEL CALENDARIO INTERACTIVO (Screenshot 1)
    let calViewDate = new Date(activeDateFrom ? activeDateFrom + 'T00:00:00' : Date.now());
    let selectedCalStart = activeDateFrom;
    let selectedCalEnd = activeDateTo;
    let selectingEndStep = false;

    const monthNamesEs = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

    function renderInteractiveCalendar() {
        const year = calViewDate.getFullYear();
        const month = calViewDate.getMonth();

        const monthTitle = document.getElementById('calCurrentMonthTitle');
        if (monthTitle) {
            monthTitle.textContent = `${monthNamesEs[month]} De ${year}`;
        }

        const daysGrid = document.getElementById('calDaysGrid');
        if (!daysGrid) return;
        daysGrid.innerHTML = '';

        // Primer día del mes (1=Lunes, 7=Domingo)
        const firstDayOfMonth = new Date(year, month, 1);
        let startingDayOfWeek = firstDayOfMonth.getDay();
        startingDayOfWeek = startingDayOfWeek === 0 ? 7 : startingDayOfWeek;

        // Días del mes actual
        const daysInCurrentMonth = new Date(year, month + 1, 0).getDate();
        // Días del mes anterior
        const daysInPrevMonth = new Date(year, month, 0).getDate();

        // Rellenar días anteriores
        for (let i = startingDayOfWeek - 1; i > 0; i--) {
            const dayNum = daysInPrevMonth - i + 1;
            const cell = document.createElement('div');
            cell.className = 'calendar-day-cell is-outside-month';
            cell.textContent = dayNum;
            daysGrid.appendChild(cell);
        }

        // Rellenar días del mes en curso
        for (let d = 1; d <= daysInCurrentMonth; d++) {
            const dStr = String(d).padStart(2, '0');
            const mStr = String(month + 1).padStart(2, '0');
            const iso = `${year}-${mStr}-${dStr}`;

            const cell = document.createElement('div');
            cell.className = 'calendar-day-cell';
            cell.textContent = d;
            cell.dataset.date = iso;

            const isStart = iso === selectedCalStart;
            const isEnd = iso === selectedCalEnd;
            const inRange = selectedCalStart && selectedCalEnd && iso > selectedCalStart && iso < selectedCalEnd;

            if (isStart) cell.classList.add('is-selected-start');
            if (isEnd) cell.classList.add('is-selected-end');
            if (inRange) cell.classList.add('is-in-range');

            cell.addEventListener('click', () => {
                if (!selectingEndStep || !selectedCalStart) {
                    selectedCalStart = iso;
                    selectedCalEnd = iso;
                    selectingEndStep = true;
                    document.getElementById('calFromInputWrapper')?.classList.remove('is-active');
                    document.getElementById('calToInputWrapper')?.classList.add('is-active');
                } else {
                    if (iso < selectedCalStart) {
                        selectedCalEnd = selectedCalStart;
                        selectedCalStart = iso;
                    } else {
                        selectedCalEnd = iso;
                    }
                    selectingEndStep = false;
                    document.getElementById('calToInputWrapper')?.classList.remove('is-active');
                    document.getElementById('calFromInputWrapper')?.classList.add('is-active');
                }

                const inpFrom = document.getElementById('calInputFrom');
                const inpTo = document.getElementById('calInputTo');
                if (inpFrom) inpFrom.value = formatToDMY(selectedCalStart);
                if (inpTo) inpTo.value = formatToDMY(selectedCalEnd);

                // Desmarcar presets rápidos
                document.querySelectorAll('.calendar-preset-btn').forEach(b => {
                    b.classList.remove('is-active');
                    b.querySelector('.bi-check-lg')?.classList.add('d-none');
                });

                renderInteractiveCalendar();
            });

            daysGrid.appendChild(cell);
        }

        // Rellenar días del mes siguiente para completar la cuadrícula (múltiplo de 7)
        const totalCells = (startingDayOfWeek - 1) + daysInCurrentMonth;
        const remaining = (7 - (totalCells % 7)) % 7;
        for (let nextD = 1; nextD <= remaining; nextD++) {
            const cell = document.createElement('div');
            cell.className = 'calendar-day-cell is-outside-month';
            cell.textContent = nextD;
            daysGrid.appendChild(cell);
        }
    }

    // Botones de navegación del mes (< y >)
    document.getElementById('calPrevMonthBtn')?.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        calViewDate.setMonth(calViewDate.getMonth() - 1);
        renderInteractiveCalendar();
    });

    document.getElementById('calNextMonthBtn')?.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        calViewDate.setMonth(calViewDate.getMonth() + 1);
        renderInteractiveCalendar();
    });

    // Cancelar cierra el dropdown
    document.getElementById('calCancelBtn')?.addEventListener('click', () => {
        const toggleBtn = document.getElementById('dateFilterDropdownBtn');
        const dd = bootstrap.Dropdown.getInstance(toggleBtn);
        if (dd) dd.hide();
    });

    // Aplicar rango seleccionado por calendario (Carga 100% Interna Asíncrona sin alterar URL)
    document.getElementById('calApplyBtn')?.addEventListener('click', () => {
        const toggleBtn = document.getElementById('dateFilterDropdownBtn');
        const dd = bootstrap.Dropdown.getInstance(toggleBtn);
        if (dd) dd.hide();

        window.fetchDashboardData({
            date_from: selectedCalStart,
            date_to: selectedCalEnd
        }, true, false);
    });

    // Clic en los presets de la izquierda (Hoy, Ayer, Esta semana, etc.)
    document.querySelectorAll('.calendar-preset-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            const presetVal = btn.dataset.preset;
            if (!presetVal) return;

            document.querySelectorAll('.calendar-preset-btn').forEach(b => {
                b.classList.remove('is-active');
                b.querySelector('.bi-check-lg')?.classList.add('d-none');
            });
            btn.classList.add('is-active');
            btn.querySelector('.bi-check-lg')?.classList.remove('d-none');

            const toggleBtn = document.getElementById('dateFilterDropdownBtn');
            const dd = bootstrap.Dropdown.getInstance(toggleBtn);
            if (dd) dd.hide();

            window.fetchDashboardData({ preset: presetVal }, true, false);
        });
    });

    // Helper para crear un elemento de marcación en vivo
    function createStreamItemElement(att) {
        const div = document.createElement('div');
        div.className = 'list-group-item d-flex align-items-center gap-3 px-2 py-2';
        div.dataset.attendanceId = att.id;

        const initialChar = Panel.esc((att.user_name || 'U').charAt(0));
        const safePhotoUrl = Panel.esc(att.photo_url || att.thumb_url || '');
        const safeThumbUrl = Panel.esc(att.thumb_url || att.photo_url || '');
        const safeUserName = Panel.esc(att.user_name || 'Colaborador');
        const safeClockIn = Panel.esc(att.clock_in_at || '--:--');
        const safeDist = parseInt(att.distance_meters, 10) || 0;

        const avatarOrPhoto = safeThumbUrl
            ? `<div class="selfie-wrapper">
                 <img src="${safeThumbUrl}" alt="Selfie de ${safeUserName}" 
                      class="selfie-thumb ${att.within_geofence ? '' : 'is-outside'}"
                      data-photo="${safePhotoUrl}"
                      data-photo-title="${safeUserName}"
                      data-photo-meta="Hora: ${safeClockIn} · Distancia: ${safeDist} m"
                      onerror="this.onerror=null; this.parentElement.innerHTML='<span class=\\'topbar-avatar\\' style=\\'width:36px;height:36px;\\'>${initialChar}</span>'">
               </div>`
            : `<div class="selfie-wrapper">
                 <span class="topbar-avatar" style="width: 36px; height: 36px;">${initialChar}</span>
               </div>`;

        const statusBadge = att.status === 'present'
            ? `<span class="badge bg-success-subtle"><span class="badge-dot"></span> Puntual</span>`
            : (att.status === 'late'
                ? `<span class="badge bg-warning-subtle"><span class="badge-dot"></span> Tardanza</span>`
                : `<span class="badge bg-secondary-subtle">${Panel.esc(att.status_label || att.status || '')}</span>`);

        div.innerHTML = `
            ${avatarOrPhoto}
            <div class="flex-grow-1 min-w-0">
                <div class="fw-bold text-truncate" style="font-size: 0.85rem; color: var(--text-primary);">${safeUserName}</div>
                <div class="small text-secondary text-truncate" style="font-size: 0.75rem;">
                    ${Panel.esc(att.branch_name || 'Sin asignar')} ·
                    <span class="${att.within_geofence ? 'text-success' : 'text-danger'}">${safeDist} m</span>
                </div>
            </div>
            <div class="text-end">
                <div class="fw-bold" style="font-size: 0.825rem; color: var(--text-primary);">${safeClockIn}</div>
                ${statusBadge}
            </div>`;
        return div;
    }

    // Render inicial del calendario
    renderInteractiveCalendar();

    // 6. SINCRONIZACIÓN Y ACTUALIZACIÓN INTERNA AJAX (Sin recarga de página)
    // 6. SINCRONIZACIÓN Y ACTUALIZACIÓN INTERNA AJAX (Sin recarga de página y SIN modificar URL)
    window.fetchDashboardData = function(paramsObj = {}, showToast = true, isAutoPolling = false) {
        const refreshIcon = document.querySelector('#telemetryRefreshBtn .bi-arrow-repeat');
        if (refreshIcon && !isAutoPolling) refreshIcon.classList.add('is-spinning');

        // Actualizar estado interno
        if (paramsObj.preset) {
            activePreset = paramsObj.preset;
        } else if (paramsObj.date_from) {
            activePreset = 'custom';
            activeDateFrom = paramsObj.date_from;
            activeDateTo = paramsObj.date_to;
        }
        if (paramsObj.branch_id !== undefined) {
            currentBranchId = paramsObj.branch_id;
        }

        const queryParams = new URLSearchParams();
        if (activePreset && activePreset !== 'custom') {
            queryParams.set('preset', activePreset);
        } else {
            queryParams.set('date_from', activeDateFrom);
            queryParams.set('date_to', activeDateTo);
        }
        if (currentBranchId) {
            queryParams.set('branch_id', currentBranchId);
        }

        // Mantener la barra de direcciones 100% limpia en /dashboard sin query parameters
        if (window.location.search) {
            try {
                window.history.replaceState({}, document.title, window.location.pathname);
            } catch (e) { /* ignore */ }
        }

        fetch(`{{ route('dashboard.live-stream') }}?${queryParams.toString()}`, {
            headers: { 
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(res => {
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            return res.json();
        })
        .then(data => {
            if (!data.success) return;

            if (data.preset) activePreset = data.preset;
            if (data.dateFrom) activeDateFrom = data.dateFrom;
            if (data.dateTo) activeDateTo = data.dateTo;
            selectedCalStart = data.dateFrom;
            selectedCalEnd = data.dateTo;

            // Actualizar etiquetas del botón disparador del calendario
            const triggerLabel = document.getElementById('calTriggerLabel');
            const triggerFrom = document.getElementById('calTriggerFrom');
            const triggerTo = document.getElementById('calTriggerTo');
            if (triggerLabel) triggerLabel.textContent = data.presetLabel;
            if (triggerFrom) triggerFrom.textContent = formatToDMY(data.dateFrom);
            if (triggerTo) triggerTo.textContent = formatToDMY(data.dateTo);

            // Inputs del popover
            const inpFrom = document.getElementById('calInputFrom');
            const inpTo = document.getElementById('calInputTo');
            if (inpFrom) inpFrom.value = formatToDMY(data.dateFrom);
            if (inpTo) inpTo.value = formatToDMY(data.dateTo);

            // Re-render calendario solo si el dropdown no está abierto para no interrumpir al usuario
            const isDropdownOpen = document.querySelector('#dashboardCalendarDropdown .dropdown-menu.show');
            if (!isDropdownOpen) {
                calViewDate = new Date(data.dateFrom + 'T00:00:00');
                renderInteractiveCalendar();
            }

            // Sincronizar selector de sucursal
            if (data.selectedBranchId !== undefined) {
                currentBranchId = data.selectedBranchId;
                const branchSel = document.getElementById('branchSelectFilter');
                if (branchSel && String(branchSel.value || '') !== String(data.selectedBranchId || '')) {
                    branchSel.value = data.selectedBranchId || '';
                }
            }

            // Actualizar Sedes Activas y Calibración
            const activeBranchesEl = document.getElementById('kpiActiveBranches');
            if (activeBranchesEl && data.activeBranchesCount !== undefined) {
                activeBranchesEl.textContent = data.activeBranchesCount;
            }
            const telemetryBadge = document.getElementById('telemetryBranchesBadge');
            if (telemetryBadge && data.activeBranchesCount !== undefined) {
                telemetryBadge.innerHTML = `<i class="bi bi-geo-alt text-primary"></i> Geocercas GPS: ${data.activeBranchesCount} Sedes`;
            }
            const calibEl = document.getElementById('kpiCalibratedText');
            if (calibEl && data.calibratedRate !== undefined) {
                calibEl.textContent = `${data.calibratedRate}% Calibrados`;
                calibEl.className = `${data.calibratedRate >= 100 ? 'text-success' : (data.calibratedRate >= 80 ? 'text-primary' : 'text-warning')} fw-bold`;
            }

            // 1. KPIs Superiores (Datos reales y verificados, cero duplicación)
            const total = data.kpis.total_attendances ?? (data.kpis.present_count || 0);
            const elTot = document.getElementById('kpiTotal');
            if (elTot) elTot.textContent = total;
            const elPres = document.getElementById('kpiPresentText');
            if (elPres) elPres.textContent = data.kpis.on_time_count ?? 0;
            const elLate = document.getElementById('kpiLateText');
            if (elLate) elLate.textContent = data.kpis.late_count ?? 0;
            const elRate = document.getElementById('kpiRate');
            if (elRate) elRate.textContent = `${data.kpis.punctuality_rate ?? 0}%`;
            const elAbsent = document.getElementById('kpiAbsentText');
            if (elAbsent) elAbsent.textContent = `${data.kpis.absent_count ?? 0} ausentes`;
            const elActive = document.getElementById('kpiActiveEmp');
            if (elActive) elActive.textContent = `${total} / ${data.kpis.total_employees ?? 0}`;
            const elOnShift = document.getElementById('kpiOnShiftText');
            if (elOnShift) elOnShift.textContent = `${data.kpis.active_on_shift ?? total} activos`;

            // 2. Diales Circulares (Los 3)
            const punctualityRate = Math.min(Math.max(data.kpis.punctuality_rate ?? 100, 0), 100);
            const pOffset = 131.9 - (131.9 * punctualityRate / 100);
            const pMeter = document.getElementById('dialPunctualityMeter');
            if (pMeter) pMeter.style.strokeDashoffset = pOffset;
            const pText = document.getElementById('dialPunctualityText');
            if (pText) pText.textContent = `${punctualityRate}%`;
            const pVal = document.getElementById('dialPunctualityVal');
            if (pVal) pVal.textContent = `${data.kpis.on_time_count ?? 0} puntuales`;
            const pSub = document.getElementById('dialPunctualitySub');
            if (pSub) pSub.textContent = `${data.kpis.late_count ?? 0} con tardanza`;

            const totalEmp = data.kpis.total_employees ?? 0;
            const coverageRate = Math.min(Math.max(data.kpis.coverage_rate ?? (totalEmp > 0 ? Math.round((total / totalEmp) * 100) : 0), 0), 100);
            const cOffset = 131.9 - (131.9 * coverageRate / 100);
            const cMeter = document.getElementById('dialCoverageMeter');
            if (cMeter) cMeter.style.strokeDashoffset = cOffset;
            const cText = document.getElementById('dialCoverageText');
            if (cText) cText.textContent = `${coverageRate}%`;
            const cVal = document.getElementById('dialCoverageVal');
            if (cVal) cVal.textContent = `${total} / ${totalEmp}`;
            const cSub = document.getElementById('dialCoverageSub');
            if (cSub) cSub.textContent = `${data.kpis.absent_count ?? 0} ausentes`;

            const geofenceRate = Math.min(Math.max(data.kpis.geofence_rate ?? 100, 0), 100);
            const gOffset = 131.9 - (131.9 * geofenceRate / 100);
            const gMeter = document.getElementById('dialGeofenceMeter');
            if (gMeter) gMeter.style.strokeDashoffset = gOffset;
            const gText = document.getElementById('dialGeofenceText');
            if (gText) gText.textContent = `${geofenceRate}%`;
            const gVal = document.getElementById('dialGeofenceVal');
            if (gVal) gVal.textContent = `${data.kpis.within_geofence_count ?? total} en radio`;
            const gSub = document.getElementById('dialGeofenceSub');
            if (gSub) gSub.textContent = `${data.kpis.out_of_geofence ?? 0} fuera de radio`;

            // 3. Gráficos Reactivos (Distribución Horaria y Tendencia Semanal)
            if (hourlyChartInstance && data.hourlyDistribution) {
                hourlyChartInstance.data.datasets[0].data = data.hourlyDistribution;
                hourlyChartInstance.update();
            }

            if (weeklyChartInstance && data.weeklyChart) {
                weeklyChartInstance.data.labels = data.weeklyChart.labels;
                weeklyChartInstance.data.datasets[0].data = data.weeklyChart.on_time;
                weeklyChartInstance.data.datasets[1].data = data.weeklyChart.late;
                weeklyChartInstance.data.datasets[2].data = data.weeklyChart.absent;
                weeklyChartInstance.update();
            }

            // 4. Feed de Marcaciones en Vivo (Reconciliación Inteligente: No parpadear fotos ya cargadas)
            const streamBox = document.getElementById('streamContainer');
            if (streamBox) {
                const incomingAttendances = data.attendances || [];
                const incomingIds = incomingAttendances.map(a => a.id);

                const isIdentical = incomingIds.length === renderedAttendanceIds.length &&
                    incomingIds.every((id, idx) => id === renderedAttendanceIds[idx]);

                if (!isIdentical) {
                    if (isAutoPolling && renderedAttendanceIds.length > 0) {
                        // Solo agregar marcaciones nuevas que hayan llegado arriba
                        const existingIdSet = new Set(renderedAttendanceIds);
                        const brandNewItems = incomingAttendances.filter(a => !existingIdSet.has(a.id));

                        if (brandNewItems.length > 0) {
                            brandNewItems.reverse().forEach(att => {
                                const newEl = createStreamItemElement(att);
                                streamBox.insertBefore(newEl, streamBox.firstChild);
                            });
                            while (streamBox.children.length > 25) {
                                streamBox.removeChild(streamBox.lastChild);
                            }
                            renderedAttendanceIds = incomingIds;
                        } else {
                            renderFullStream(incomingAttendances, streamBox);
                        }
                    } else {
                        // Cambio explícito de período/preset: renderizar lista completa
                        renderFullStream(incomingAttendances, streamBox);
                    }
                }
            }

            function renderFullStream(items, container) {
                if (!items || items.length === 0) {
                    container.innerHTML = `
                        <div class="empty-state text-center py-4">
                            <i class="bi bi-inbox text-secondary fs-3 d-block mb-2"></i>
                            <div class="fw-semibold small" style="color: var(--text-primary);">Sin actividad reciente en el periodo seleccionado</div>
                            <div class="text-secondary small" style="font-size: 0.75rem;">Las transmisiones aparecerán automáticamente aquí.</div>
                        </div>`;
                    renderedAttendanceIds = [];
                } else {
                    const fragment = document.createDocumentFragment();
                    items.forEach(att => {
                        fragment.appendChild(createStreamItemElement(att));
                    });
                    container.innerHTML = '';
                    container.appendChild(fragment);
                    renderedAttendanceIds = items.map(a => a.id);
                }
            }

            const streamCountEl = document.getElementById('streamCount');
            if (streamCountEl) {
                streamCountEl.textContent = data.attendances ? data.attendances.length : 0;
            }

            if (showToast && window.Toast) {
                window.Toast.success('Telemetría actualizada', 'Sincronizado');
            }
        })
        .catch(err => {
            console.warn('Sync telemetría error:', err);
            if (showToast && window.Toast) {
                window.Toast.info('Datos al día', 'Información');
            }
        })
        .finally(() => {
            if (refreshIcon && !isAutoPolling) {
                setTimeout(() => {
                    refreshIcon.classList.remove('is-spinning');
                }, 400);
            }
        });
    };

    // Botón manual de refresco de telemetría (100% interno sin tocar URL)
    window.refreshDashboardTelemetry = function(btn) {
        window.fetchDashboardData({}, true, false);
    };

    // Auto-polling en segundo plano cada 20 segundos sin resetear filtros y sin tocar URL
    setInterval(() => {
        window.fetchDashboardData({}, false, true);
    }, 20000);
});
</script>
@endsection
