@extends('layouts.app')

@section('title', 'Tablero en vivo')

@section('styles')
<style>
    #liveBoardMap { height: 460px; border-radius: 10px; border: 1px solid var(--border-color); }
    @media (max-width: 767.98px) { #liveBoardMap { height: 300px; } }
    #liveList { max-height: 520px; overflow-y: auto; scrollbar-width: thin; }
    #liveList::-webkit-scrollbar { width: 5px; }
    #liveList::-webkit-scrollbar-thumb { background: rgba(148, 163, 184, 0.35); border-radius: 4px; }
    .live-times { font-variant-numeric: tabular-nums; }
</style>
@endsection

@section('content')
<!-- Header del Tablero en Vivo -->
<div class="live-board-header">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1">
            <h1 class="h2 fw-bold mb-0" style="color: var(--text-primary); letter-spacing: -0.02em;">Tablero en vivo</h1>
        </div>
        <p class="mb-0" style="color: var(--text-muted); font-size: 0.875rem;">Quién está trabajando ahora, en descanso, por llegar o ausente{{ ($globalView ?? false) ? ' en todas las empresas' : '' }}.</p>
    </div>

    <!-- Indicador de En Vivo & Botón de Actualizar -->
    <div class="d-flex align-items-center gap-2">
        <div class="live-pulse-badge" id="livePulseBadge" title="Transmisión activa">
            <span class="live-pulse-dot" id="liveDot"></span>
            <span id="liveUpdated">En vivo</span>
        </div>
        <button type="button" id="btnManualPoll" class="live-refresh-btn" title="Actualizar ahora" aria-label="Actualizar">
            <i class="bi bi-arrow-clockwise"></i>
        </button>
    </div>
</div>

{{-- Contadores Interactivos (KPI Status Cards) --}}
@php
    $counterCards = [
        ['working', 'Trabajando', 'person-workspace', 'rgba(16, 185, 129, 0.1)', '#10b981'],
        ['on_break', 'En descanso', 'cup-hot', 'rgba(245, 158, 11, 0.1)', '#f59e0b'],
        ['not_arrived', 'No ha llegado', 'person-x', 'rgba(239, 68, 68, 0.1)', '#ef4444'],
        ['upcoming', 'Por llegar', 'hourglass-split', 'rgba(100, 116, 139, 0.1)', '#64748b'],
        ['left', 'Salió', 'box-arrow-right', 'rgba(71, 85, 105, 0.1)', '#475569'],
        ['on_leave', 'Con permiso', 'calendar2-check', 'rgba(2, 132, 199, 0.1)', '#0284c7'],
    ];
    if ($hasSuspicious) {
        $counterCards[] = ['suspicious', 'Sospechosas', 'shield-exclamation', 'rgba(217, 119, 6, 0.1)', '#d97706'];
    }
@endphp
<div class="row g-2 g-md-3 mb-4">
    @foreach($counterCards as [$key, $label, $icon, $bgColor, $iconColor])
        <div class="col-6 col-md-4 col-xl">
            <div class="card border-0 h-100 live-status-card live-counter" role="button" tabindex="0" data-status="{{ $key }}" aria-label="Filtrar por: {{ $label }}">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <span class="live-status-label">{{ $label }}</span>
                    <span class="live-status-icon" style="background: {{ $bgColor }}; color: {{ $iconColor }};">
                        <i class="bi bi-{{ $icon }}"></i>
                    </span>
                </div>
                <div class="live-status-val" data-counter="{{ $key }}">–</div>
            </div>
        </div>
    @endforeach
</div>

<div class="row g-3">
    {{-- Columna Izquierda: Lista Filtrable de Colaboradores --}}
    <div class="col-12 col-xl-7">
        <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; border: 1px solid var(--border-color) !important; background: var(--bg-surface); overflow: hidden;">
            <div class="card-header border-bottom p-3" style="background: var(--bg-surface); border-color: var(--border-color) !important;">
                <form class="row g-2 align-items-center" id="liveFilters" onsubmit="return false">
                    <div class="col-12 col-sm-4">
                        <select id="liveBranch" class="form-select form-select-sm" style="border-radius: 8px; border-color: var(--border-color); background-color: var(--bg-surface); color: var(--text-primary); height: 36px; font-size: 0.825rem;">
                            <option value="">Todas las sucursales</option>
                            @foreach($branches as $b)
                                <option value="{{ $b->id }}">{{ $b->name }}@if($globalView ?? false) · {{ $tenantNames[$b->tenant_id] ?? '' }}@endif</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-sm-3">
                        <select id="liveStatus" class="form-select form-select-sm" style="border-radius: 8px; border-color: var(--border-color); background-color: var(--bg-surface); color: var(--text-primary); height: 36px; font-size: 0.825rem;">
                            <option value="">Todos los estados</option>
                            @foreach($statuses as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                            @if($hasSuspicious)<option value="suspicious">Sospechosas</option>@endif
                        </select>
                    </div>
                    <div class="col-6 col-sm-5">
                        <div class="company-search-wrapper" style="width: 100%;">
                            <i class="bi bi-search search-icon"></i>
                            <input type="search" id="liveSearch" class="company-search-input" style="height: 36px;" placeholder="Buscar colaborador o código...">
                        </div>
                    </div>
                </form>
            </div>

            <!-- Listado en vivo con scroll suave -->
            <div class="list-group list-group-flush" id="liveList" aria-live="polite">
                <div class="text-center py-5">
                    <div class="spinner-border spinner-border-sm text-primary mb-2" role="status"></div>
                    <div class="text-secondary small fw-medium">Sincronizando estado en vivo…</div>
                </div>
            </div>

            <!-- Contador de personas en footer -->
            <div class="card-footer border-top px-3 py-2 d-flex align-items-center justify-content-between small text-secondary" style="background: var(--bg-surface); border-color: var(--border-color) !important;">
                <span id="liveCount"><i class="bi bi-people me-1"></i>0 colaboradores</span>
                <span class="text-muted" style="font-size: 0.75rem;">Monitoreo en vivo cada 10s</span>
            </div>
        </div>
    </div>

    {{-- Columna Derecha: Mapa de Marcaciones en Tiempo Real --}}
    <div class="col-12 col-xl-5">
        <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; border: 1px solid var(--border-color) !important; background: var(--bg-surface); overflow: hidden;">
            <div class="card-header border-bottom p-3 d-flex align-items-center justify-content-between" style="background: var(--bg-surface); border-color: var(--border-color) !important;">
                <div>
                    <h2 class="h6 fw-bold mb-0 d-flex align-items-center gap-2" style="color: var(--text-primary);">
                        <i class="bi bi-geo-alt-fill text-primary"></i>
                        <span>Entradas por Ubicación</span>
                    </h2>
                    <small class="text-muted" style="font-size: 0.75rem;">Puntos GPS y ubicaciones autorizadas de hoy</small>
                </div>
                <button type="button" id="btnCenterMap" class="btn btn-sm d-inline-flex align-items-center gap-1" style="border-radius: 8px; font-size: 0.775rem; border: 1px solid var(--border-color); background: var(--bg-surface); color: var(--text-muted);" title="Reencuadrar mapa en sucursales">
                    <i class="bi bi-crosshair"></i>
                    <span class="d-none d-sm-inline">Centrar</span>
                </button>
            </div>
            <div class="card-body p-3" style="background: var(--bg-surface);">
                <div id="liveBoardMap"></div>
                <!-- Leyenda de colores -->
                <div class="d-flex flex-wrap align-items-center gap-3 small text-muted mt-2 px-1" style="font-size: 0.775rem;">
                    <span class="d-inline-flex align-items-center gap-1">
                        <span style="display:inline-block; width:10px; height:10px; border-radius:3px; background:#0284c7;"></span>
                        <span>Ubicación autorizada</span>
                    </span>
                    <span class="d-inline-flex align-items-center gap-1">
                        <span style="display:inline-block; width:10px; height:10px; border-radius:3px; background:#10b981;"></span>
                        <span>Dentro de ubicación</span>
                    </span>
                    <span class="d-inline-flex align-items-center gap-1">
                        <span style="display:inline-block; width:10px; height:10px; border-radius:3px; background:#ef4444;"></span>
                        <span>Fuera de ubicación</span>
                    </span>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const DATA_URL = @json(route('live.data'));
    const BRANCHES = @json($branches);
    const GLOBAL_VIEW = @json((bool) ($globalView ?? false));
    const BASE_DELAY = 10000;     // sondeo normal: 10 s
    const MAX_DELAY = 300000;     // tope del reintento: 5 min
    const TONES = { 
        working: 'success', 
        on_break: 'warning', 
        not_arrived: 'danger', 
        upcoming: 'secondary', 
        left: 'dark', 
        on_leave: 'primary' 
    };

    const els = {
        list: document.getElementById('liveList'),
        count: document.getElementById('liveCount'),
        updated: document.getElementById('liveUpdated'),
        dot: document.getElementById('liveDot'),
        branch: document.getElementById('liveBranch'),
        status: document.getElementById('liveStatus'),
        search: document.getElementById('liveSearch'),
        btnManualPoll: document.getElementById('btnManualPoll'),
        btnCenterMap: document.getElementById('btnCenterMap'),
    };

    let etag = null, payload = null, lastOk = null, failures = 0, timer = null, inFlight = false;

    // ---- Mapa Leaflet ----
    const brand = Panel.cssVar('--brand') || '#0284c7';
    const map = L.map('liveBoardMap', { scrollWheelZoom: false }).setView([-8.1118, -79.0287], 12);
    Panel.tiles(map);
    const branchBounds = [];
    BRANCHES.forEach((b) => {
        if (!b.latitude || !b.longitude) return;
        const center = [parseFloat(b.latitude), parseFloat(b.longitude)];
        L.circle(center, { 
            radius: parseInt(b.allowed_radius_meters, 10) || 50, 
            color: '#0284c7', 
            fillColor: '#0284c7', 
            fillOpacity: .12, 
            weight: 2 
        }).addTo(map).bindPopup(`<strong style="font-size:0.9rem;">${Panel.esc(b.name)}</strong><br><small class="text-muted">Radio permitido: ${b.allowed_radius_meters || 50}m</small>`);
        branchBounds.push(center);
    });

    if (branchBounds.length) {
        map.fitBounds(branchBounds, { padding: [35, 35], maxZoom: 15 });
    }
    const markerLayer = L.layerGroup().addTo(map);
    let fitted = false;

    let currentMarkerPoints = [];

    function centerMap() {
        const allPoints = [];
        if (currentMarkerPoints && currentMarkerPoints.length) {
            allPoints.push(...currentMarkerPoints);
        }
        if (branchBounds && branchBounds.length) {
            allPoints.push(...branchBounds);
        }
        map.invalidateSize();
        if (allPoints.length > 1) {
            map.fitBounds(allPoints, { padding: [40, 40], maxZoom: 15, animate: true });
        } else if (allPoints.length === 1) {
            map.setView(allPoints[0], 14, { animate: true });
        } else {
            map.setView([-8.1118, -79.0287], 12, { animate: true });
        }
    }

    if (els.btnCenterMap) {
        els.btnCenterMap.addEventListener('click', centerMap);
    }

    // ---- Sondeo con ETag y pausa en segundo plano ----
    function schedule() {
        clearTimeout(timer);
        if (document.visibilityState === 'hidden') return;
        const delay = failures ? Math.min(BASE_DELAY * 2 ** failures, MAX_DELAY) : BASE_DELAY;
        timer = setTimeout(poll, delay);
    }

    async function poll() {
        if (inFlight || document.visibilityState === 'hidden') return;
        inFlight = true;
        try {
            const url = new URL(DATA_URL, window.location.origin);
            if (els.branch.value) url.searchParams.set('branch_id', els.branch.value);
            const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
            if (etag) headers['If-None-Match'] = etag;

            const res = await fetch(url, { headers, credentials: 'same-origin', cache: 'no-store' });
            if (res.status === 401 || res.status === 419) { window.location.reload(); return; }
            if (res.status !== 304) {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                payload = await res.json();
                etag = res.headers.get('ETag');
                render();
            }
            lastOk = Date.now();
            failures = 0;
        } catch (err) {
            failures++;
            console.warn('Tablero en vivo: no se pudo actualizar', err);
        } finally {
            inFlight = false;
            updateAgo();
            schedule();
        }
    }

    if (els.btnManualPoll) {
        els.btnManualPoll.addEventListener('click', () => {
            etag = null;
            poll();
        });
    }

    function updateAgo() {
        if (!lastOk) {
            els.updated.textContent = failures ? 'Sin conexión' : 'En vivo';
            return;
        }
        const s = Math.max(0, Math.round((Date.now() - lastOk) / 1000));
        const ago = s < 60 ? `${s} s` : `${Math.floor(s / 60)} min`;
        
        // El texto visible siempre es "En vivo" como solicitó el usuario, y el tiempo va en el title
        els.updated.textContent = failures ? 'Reintentando…' : 'En vivo';
        els.updated.className = failures ? 'text-danger fw-bold' : 'text-success fw-bold';
        document.getElementById('livePulseBadge')?.setAttribute('title', failures ? 'Sin conexión al servidor' : `Transmisión activa · Sincronizado hace ${ago}`);
        els.dot.style.visibility = failures ? 'hidden' : 'visible';
    }
    setInterval(updateAgo, 1000);

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') { clearTimeout(timer); } else { poll(); }
    });

    // ---- Renderizado ----
    function initials(name) {
        return String(name || '?').split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0]).join('').toUpperCase();
    }

    function matches(row) {
        const status = els.status.value;
        if (status === 'suspicious' ? !row.suspicious : (status && row.status !== status)) return false;
        const q = els.search.value.trim().toLowerCase();
        if (!q) return true;
        return [row.name, row.employee_code, row.branch, row.company].some((v) => String(v || '').toLowerCase().includes(q));
    }

    function rowHtml(r) {
        const tone = TONES[r.status] || 'secondary';
        const photoSrc = r.photo_url || r.avatar_url;
        const init = initials(r.name);
        
        // Avatar: Foto de marcación o perfil si existe, o iniciales estilizadas
        const thumb = photoSrc
            ? `<div class="live-collab-avatar has-photo" data-photo="${Panel.esc(photoSrc)}" data-photo-title="${Panel.esc(r.name)}" data-photo-meta="${Panel.esc(r.branch || '')} · ${Panel.esc(r.clock_in ? 'Marcación ' + r.clock_in : 'Perfil')}" title="Clic para ampliar foto">
                   <img src="${Panel.esc(photoSrc)}" alt="${Panel.esc(r.name)}" onerror="this.onerror=null; this.parentElement.classList.remove('has-photo'); this.parentElement.innerHTML='${Panel.esc(init)}';">
               </div>`
            : `<div class="live-collab-avatar" title="${Panel.esc(r.name)}">${Panel.esc(init)}</div>`;
        
        const nameLink = r.detail_url 
            ? `<a href="${Panel.esc(r.detail_url)}" class="live-collab-name text-decoration-none fw-bold">${Panel.esc(r.name)}</a>` 
            : `<span class="live-collab-name fw-bold">${Panel.esc(r.name)}</span>`;
        
        const suspiciousBadge = r.suspicious 
            ? '<span class="badge bg-warning-subtle text-warning border border-warning-subtle rounded-2 ms-1" style="font-size:0.7rem;"><i class="bi bi-shield-exclamation"></i> Sospechosa</span>' 
            : '';

        // Horarios: ABAJO DE LA SEDE PRINCIPAL (como en la Imagen 2)
        let timeMain = '';
        if (r.clock_in) {
            timeMain = `<i class="bi bi-box-arrow-in-right text-success me-1"></i><span class="fw-semibold" style="color: var(--text-primary);">${Panel.esc(r.clock_in)}</span>`;
        } else if (r.shift_start) {
            timeMain = `<i class="bi bi-clock me-1 text-muted"></i><span>Turno ${Panel.esc(r.shift_start)}</span>`;
        }

        const lateTag = r.late_minutes > 0 
            ? `<span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle rounded-2 px-1.5 py-0 ms-1" style="font-size:0.675rem;">+${r.late_minutes} min tarde</span>` 
            : '';

        // Empresa (DONDE EL USUARIO PIDIÓ MOVERLA, EN EL CENTRO)
        const companyName = Panel.esc(r.company || 'Empresa');
        const companyHtml = `
            <div class="live-collab-company-cell">
                <span class="live-company-badge" title="Empresa: ${companyName}">
                    <i class="bi bi-building text-muted me-1"></i>${companyName}
                </span>
            </div>
        `;

        // Ubicación (NO GEOCERCA, BADGE RECTANGULAR CON BORDES SUAVES, NADA DE PILL REDONDA)
        let locationBadge = '';
        if (r.within_geofence !== null) {
            if (r.within_geofence) {
                locationBadge = `<span class="live-location-badge in-range" title="Marcación en ubicación autorizada (${Panel.esc(r.distance_meters ?? 0)} m)"><i class="bi bi-geo-alt-fill"></i>En ubicación (${Panel.esc(r.distance_meters ?? 0)} m)</span>`;
            } else {
                locationBadge = `<span class="live-location-badge out-range" title="Marcación fuera de la ubicación autorizada (${Panel.esc(r.distance_meters ?? '-')} m)"><i class="bi bi-exclamation-triangle-fill"></i>Fuera de ubicación (${Panel.esc(r.distance_meters ?? '-')} m)</span>`;
            }
        }

        return `
        <div class="live-collab-row">
            ${thumb}
            
            <!-- Columna 1: Colaborador + Sede/Turno + Hora (Abajo, exactamente como en Imagen 2) -->
            <div class="live-collab-primary min-w-0">
                <div class="d-flex align-items-center gap-1 flex-wrap mb-0.5">
                    ${nameLink}
                    ${suspiciousBadge}
                </div>
                <div class="live-collab-subtitle text-truncate">
                    <i class="bi bi-building text-muted me-1"></i>${Panel.esc(r.branch || 'Sin sucursal')}${r.shift ? ' · <i class="bi bi-calendar2-week text-muted me-1"></i>' + Panel.esc(r.shift) : ''}
                </div>
                <div class="live-collab-time-line text-truncate">
                    ${timeMain}${lateTag}
                </div>
            </div>

            <!-- Columna 2: Empresa (donde estaba la línea roja) -->
            ${companyHtml}

            <!-- Columna 3: Ubicación (Badges no redondeados tipo pill) -->
            <div class="live-collab-location-cell flex-shrink-0 text-end">
                ${locationBadge}
            </div>

            <!-- Columna 4: Estado -->
            <div class="live-collab-status text-end flex-shrink-0">
                <span class="badge bg-${tone}-subtle text-${tone}-emphasis border border-${tone}-subtle rounded-2 px-2.5 py-1.5 fw-semibold" style="font-size:0.75rem;">
                    ${Panel.esc(r.status_label)}
                </span>
            </div>
        </div>`;
    }

    function render() {
        if (!payload) return;
        Object.entries(payload.counters).forEach(([key, value]) => {
            const el = document.querySelector(`[data-counter="${key}"]`);
            if (el) el.textContent = value;
        });
        document.querySelectorAll('.live-counter').forEach((c) => c.classList.toggle('active', c.dataset.status === els.status.value));

        const rows = payload.rows.filter(matches);
        els.list.innerHTML = rows.length
            ? rows.map(rowHtml).join('')
            : '<div class="text-center py-5 text-muted small"><i class="bi bi-inbox fs-2 d-block mb-2 text-secondary"></i>No hay colaboradores que coincidan con los filtros.</div>';
        
        els.count.innerHTML = `<i class="bi bi-people me-1 text-primary"></i><strong>${rows.length}</strong> de ${payload.rows.length} colaboradores`;

        markerLayer.clearLayers();
        const pts = [];
        payload.rows.filter((r) => r.latitude && r.longitude).forEach((r) => {
            const color = r.within_geofence ? '#10b981' : '#ef4444';
            const icon = L.divIcon({ 
                className: '', 
                html: `<div style="width:14px;height:14px;border-radius:50%;background:${color};border:2px solid #ffffff;box-shadow:0 0 5px rgba(0,0,0,0.3);"></div>`, 
                iconSize: [14, 14], 
                iconAnchor: [7, 7] 
            });
            L.marker([r.latitude, r.longitude], { icon }).addTo(markerLayer).bindPopup(
                `<strong style="font-size:0.9rem;">${Panel.esc(r.name)}</strong><br><small class="text-muted"><i class="bi bi-building me-1"></i>${Panel.esc(r.branch || '')}</small><br><small class="fw-semibold text-primary"><i class="bi bi-clock me-1"></i>Entrada: ${Panel.esc(r.clock_in || '--')}</small><br><span class="badge bg-${r.within_geofence ? 'success' : 'danger'}-subtle text-${r.within_geofence ? 'success' : 'danger'} border rounded-2 px-1.5 py-0.5 mt-1" style="font-size:0.7rem;">${r.within_geofence ? 'Dentro de ubicación' : 'Fuera de ubicación'}</span>`);
            pts.push([r.latitude, r.longitude]);
        });
        currentMarkerPoints = pts;
        if (!fitted && pts.length) {
            centerMap();
            fitted = true;
        }
    }

    // ---- Filtros ----
    els.status.addEventListener('change', render);
    els.search.addEventListener('input', render);
    els.branch.addEventListener('change', () => { etag = null; fitted = false; poll(); });
    document.querySelectorAll('.live-counter').forEach((card) => {
        const toggle = () => { els.status.value = els.status.value === card.dataset.status ? '' : card.dataset.status; render(); };
        card.addEventListener('click', toggle);
        card.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(); } });
    });

    poll();
});
</script>
@endsection
