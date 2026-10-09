@extends('layouts.app')

@section('title', 'Tablero en vivo')

@section('styles')
<style>
    .live-counter { cursor: pointer; transition: box-shadow .15s ease, transform .15s ease; }
    .live-counter:hover, .live-counter:focus-visible { transform: translateY(-1px); box-shadow: 0 .25rem .75rem rgba(0,0,0,.08); }
    .live-counter.active { outline: 2px solid var(--brand, #1E40AF); outline-offset: -2px; }
    .live-counter .kpi-value { font-size: 1.6rem; }
    .live-thumb { width: 44px; height: 44px; border-radius: .5rem; object-fit: cover; flex-shrink: 0; background: var(--bs-secondary-bg); display: inline-flex; align-items: center; justify-content: center; font-weight: 600; color: var(--bs-secondary-color); border: 0; padding: 0; }
    #liveBoardMap { height: 360px; }
    @media (max-width: 767.98px) { #liveBoardMap { height: 260px; } }
    .live-row { gap: .75rem; }
    .live-row .min-w-0 { min-width: 0; }
    .live-times { font-variant-numeric: tabular-nums; }
    #liveList { max-height: 640px; overflow-y: auto; }
</style>
@endsection

@section('content')
<div class="page-header">
    <div>
        <h1>Tablero en vivo</h1>
        <p>Quién está trabajando ahora, en descanso, por llegar o ausente@if($globalView ?? false) en todas las empresas@endif.</p>
    </div>
    <div class="d-flex align-items-center gap-2 small fw-semibold" aria-live="polite">
        <span class="live-dot" id="liveDot"></span>
        <span id="liveUpdated" class="text-success">Cargando…</span>
    </div>
</div>

{{-- Contadores (clic = filtrar por ese estado) --}}
@php
    $counterCards = [
        ['working', 'Trabajando', 'person-workspace', 'success'],
        ['on_break', 'En descanso', 'cup-hot', 'info'],
        ['not_arrived', 'No ha llegado', 'person-x', 'danger'],
        ['upcoming', 'Por llegar', 'hourglass-split', 'secondary'],
        ['left', 'Salió', 'box-arrow-right', 'dark'],
        ['on_leave', 'Con permiso', 'calendar2-check', 'primary'],
    ];
    if ($hasSuspicious) {
        $counterCards[] = ['suspicious', 'Sospechosas', 'shield-exclamation', 'warning'];
    }
@endphp
<div class="row g-2 g-md-3 mb-4">
    @foreach($counterCards as [$key, $label, $icon, $tone])
        <div class="col-6 col-md-4 col-xl">
            <div class="card border-0 h-100 kpi-card live-counter" role="button" tabindex="0" data-status="{{ $key }}" aria-label="Filtrar: {{ $label }}">
                <div class="card-body py-3">
                    <div class="d-flex justify-content-between align-items-start mb-1">
                        <span class="kpi-label">{{ $label }}</span>
                        <span class="kpi-icon bg-{{ $tone }}-subtle text-{{ $tone }}-emphasis"><i class="bi bi-{{ $icon }}"></i></span>
                    </div>
                    <div class="kpi-value" data-counter="{{ $key }}">–</div>
                </div>
            </div>
        </div>
    @endforeach
</div>

<div class="row g-3">
    {{-- Lista filtrable --}}
    <div class="col-12 col-xl-7">
        <div class="card border-0 h-100">
            <div class="card-header border-0 pt-3">
                <form class="row g-2" id="liveFilters" onsubmit="return false">
                    <div class="col-12 col-md-4">
                        <label for="liveBranch" class="visually-hidden">Sucursal</label>
                        <select id="liveBranch" class="form-select form-select-sm">
                            <option value="">Todas las sucursales</option>
                            @foreach($branches as $b)
                                <option value="{{ $b->id }}">{{ $b->name }}@if($globalView ?? false) · {{ $tenantNames[$b->tenant_id] ?? '' }}@endif</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-md-4">
                        <label for="liveStatus" class="visually-hidden">Estado</label>
                        <select id="liveStatus" class="form-select form-select-sm">
                            <option value="">Todos los estados</option>
                            @foreach($statuses as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                            @if($hasSuspicious)<option value="suspicious">Sospechosas</option>@endif
                        </select>
                    </div>
                    <div class="col-6 col-md-4">
                        <label for="liveSearch" class="visually-hidden">Buscar</label>
                        <input type="search" id="liveSearch" class="form-control form-control-sm" placeholder="Buscar nombre o código">
                    </div>
                </form>
            </div>
            <div class="list-group list-group-flush" id="liveList" aria-live="polite">
                <div class="empty-state"><i class="bi bi-hourglass"></i>Cargando…</div>
            </div>
            <div class="card-footer bg-transparent small text-body-secondary" id="liveCount"></div>
        </div>
    </div>

    {{-- Mapa de las entradas de hoy --}}
    <div class="col-12 col-xl-5">
        <div class="card border-0 h-100">
            <div class="card-header border-0 pt-3">
                <h2 class="h6 fw-semibold mb-0">Entradas de hoy</h2>
                <small class="text-body-secondary">Punto GPS de la marcación de entrada</small>
            </div>
            <div class="card-body">
                <div id="liveBoardMap" class="map-frame rounded"></div>
                <div class="d-flex flex-wrap gap-3 small text-body-secondary mt-2">
                    <span><i class="bi bi-circle-fill text-primary"></i> Geocerca</span>
                    <span><i class="bi bi-circle-fill text-success"></i> Dentro</span>
                    <span><i class="bi bi-circle-fill text-danger"></i> Fuera</span>
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
    const TONES = { working: 'success', on_break: 'info', not_arrived: 'danger', upcoming: 'secondary', left: 'dark', on_leave: 'primary' };

    const els = {
        list: document.getElementById('liveList'),
        count: document.getElementById('liveCount'),
        updated: document.getElementById('liveUpdated'),
        dot: document.getElementById('liveDot'),
        branch: document.getElementById('liveBranch'),
        status: document.getElementById('liveStatus'),
        search: document.getElementById('liveSearch'),
    };

    let etag = null, payload = null, lastOk = null, failures = 0, timer = null, inFlight = false;

    // ---- Mapa ----
    const brand = Panel.cssVar('--brand') || '#1E40AF';
    const map = L.map('liveBoardMap', { scrollWheelZoom: false }).setView([-12.046374, -77.042793], 11);
    Panel.tiles(map);
    const branchBounds = [];
    BRANCHES.forEach((b) => {
        if (!b.latitude || !b.longitude) return;
        const center = [parseFloat(b.latitude), parseFloat(b.longitude)];
        L.circle(center, { radius: parseInt(b.allowed_radius_meters, 10) || 50, color: brand, fillColor: brand, fillOpacity: .1, weight: 2 })
            .addTo(map).bindPopup(`<strong>${Panel.esc(b.name)}</strong>`);
        branchBounds.push(center);
    });
    if (branchBounds.length) map.fitBounds(branchBounds, { padding: [30, 30], maxZoom: 15 });
    const markerLayer = L.layerGroup().addTo(map);
    let fitted = false;

    // ---- Sondeo con ETag, reintento exponencial y pausa en segundo plano ----
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

    function updateAgo() {
        if (!lastOk) {
            els.updated.textContent = failures ? 'Sin conexión, reintentando…' : 'Cargando…';
            return;
        }
        const s = Math.max(0, Math.round((Date.now() - lastOk) / 1000));
        const ago = s < 60 ? `${s} s` : `${Math.floor(s / 60)} min`;
        els.updated.textContent = (failures ? 'Sin conexión, reintentando · ' : '') + `Actualizado hace ${ago}`;
        els.updated.className = failures ? 'text-danger' : (s > 30 ? 'text-warning-emphasis' : 'text-success');
        els.dot.style.visibility = failures ? 'hidden' : 'visible';
    }
    setInterval(updateAgo, 1000);

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') { clearTimeout(timer); } else { poll(); }
    });

    // ---- Pintado ----
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
        const thumb = r.photo_url
            ? `<button type="button" class="live-thumb" data-photo="${Panel.esc(r.photo_url)}" data-photo-title="${Panel.esc(r.name)}" data-photo-meta="Entrada ${Panel.esc(r.clock_in || '')} · ${Panel.esc(r.branch || '')}" aria-label="Ver selfie de ${Panel.esc(r.name)}"><img src="${Panel.esc(r.photo_url)}" alt="" class="live-thumb" loading="lazy"></button>`
            : `<span class="live-thumb" aria-hidden="true">${Panel.esc(initials(r.name))}</span>`;
        const company = GLOBAL_VIEW && r.company ? `<span class="badge rounded-pill text-bg-primary bg-opacity-10 text-primary-emphasis border border-primary-subtle fw-medium ms-1">${Panel.esc(r.company)}</span>` : '';
        const geo = r.within_geofence === null ? ''
            : (r.within_geofence
                ? `<span class="text-success" title="Dentro de la geocerca"><i class="bi bi-geo-alt-fill"></i> ${Panel.esc(r.distance_meters ?? '-')} m</span>`
                : `<span class="text-danger fw-semibold" title="Fuera de la geocerca"><i class="bi bi-exclamation-triangle-fill"></i> ${Panel.esc(r.distance_meters ?? '-')} m</span>`);
        const times = [
            r.clock_in ? `Entrada ${Panel.esc(r.clock_in)}` : (r.shift_start ? `Turno ${Panel.esc(r.shift_start)}` : ''),
            r.break_start && r.status === 'on_break' ? `Descanso ${Panel.esc(r.break_start)}` : '',
            r.clock_out ? `Salida ${Panel.esc(r.clock_out)}` : '',
            r.late_minutes > 0 ? `<span class="text-warning-emphasis">+${r.late_minutes} min</span>` : '',
        ].filter(Boolean).join(' · ');
        const suspicious = r.suspicious ? '<span class="badge text-bg-warning ms-1"><i class="bi bi-shield-exclamation"></i> Sospechosa</span>' : '';
        const name = r.detail_url ? `<a href="${Panel.esc(r.detail_url)}" class="text-reset">${Panel.esc(r.name)}</a>` : Panel.esc(r.name);

        return `<div class="list-group-item d-flex align-items-center live-row">
            ${thumb}
            <div class="min-w-0 flex-grow-1">
                <div class="fw-semibold text-truncate">${name}${company}${suspicious}</div>
                <div class="small text-body-secondary text-truncate">${Panel.esc(r.branch || 'Sin sucursal')}${r.shift ? ' · ' + Panel.esc(r.shift) : ''}</div>
                <div class="small live-times">${times} ${geo}</div>
            </div>
            <span class="badge bg-${tone}-subtle text-${tone}-emphasis border border-${tone}-subtle flex-shrink-0">${Panel.esc(r.status_label)}</span>
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
            : '<div class="empty-state"><i class="bi bi-inbox"></i>Nadie coincide con los filtros.</div>';
        els.count.textContent = `${rows.length} de ${payload.rows.length} personas`;

        markerLayer.clearLayers();
        const pts = [];
        payload.rows.filter((r) => r.latitude && r.longitude).forEach((r) => {
            const color = r.within_geofence ? '#198754' : '#dc3545';
            const icon = L.divIcon({ className: '', html: `<div class="emp-marker" style="width:16px;height:16px;background:${color}"></div>`, iconSize: [16, 16], iconAnchor: [8, 8] });
            L.marker([r.latitude, r.longitude], { icon }).addTo(markerLayer).bindPopup(
                `<strong>${Panel.esc(r.name)}</strong><br><small>${Panel.esc(r.branch || '')} · ${Panel.esc(r.clock_in || '--')}</small><br><small>${Panel.esc(r.status_label)}</small>`);
            pts.push([r.latitude, r.longitude]);
        });
        if (!fitted && pts.length) {
            map.fitBounds(pts.concat(branchBounds), { padding: [30, 30], maxZoom: 16 });
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
