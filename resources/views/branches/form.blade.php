@extends('layouts.app')

@section('title', $isEdit ? 'Editar sucursal' : 'Nueva sucursal')

@section('content')
@php $radius = old('allowed_radius_meters', $branch->allowed_radius_meters ?? 50); @endphp

<nav aria-label="breadcrumb">
    <ol class="breadcrumb small mb-2">
        <li class="breadcrumb-item"><a href="{{ route('branches.index') }}">Sucursales</a></li>
        <li class="breadcrumb-item active" aria-current="page">{{ $isEdit ? 'Editar' : 'Nueva' }}</li>
    </ol>
</nav>
<div class="page-header">
    <div>
        <h1>{{ $isEdit ? 'Editar sucursal: ' . $branch->name : 'Nueva sucursal u obra' }}</h1>
        <p>Busca la dirección, haz clic en el mapa o arrastra el pin para fijar el punto central, y ajusta el radio permitido.</p>
    </div>
</div>

<form method="POST" action="{{ $isEdit ? route('branches.update', $branch->id) : route('branches.store') }}">
    @csrf
    @if($isEdit) @method('PUT') @endif

    <div class="row g-3">
        <div class="col-12 col-xl-5">
            <div class="card border-0 h-100">
                <div class="card-body">
                    @if($companies)
                        {{-- Vista global del Super Admin: la sucursal se crea para la empresa elegida aquí --}}
                        <div class="mb-3">
                            <label for="tenant_id" class="form-label">Empresa <span class="text-danger">*</span></label>
                            <select id="tenant_id" name="tenant_id" required class="form-select @error('tenant_id') is-invalid @enderror">
                                <option value="">Elige la empresa…</option>
                                @foreach($companies as $id => $companyName)
                                    <option value="{{ $id }}" @selected(old('tenant_id') == $id)>{{ $companyName }}</option>
                                @endforeach
                            </select>
                            @error('tenant_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    @elseif(($globalView ?? false) && $branch->tenant)
                        <p class="small text-body-secondary mb-3"><i class="bi bi-buildings me-1"></i>Empresa: <strong>{{ $branch->tenant->company_name }}</strong></p>
                    @endif
                    <div class="mb-3">
                        <label for="name" class="form-label">Nombre de la sede u obra <span class="text-danger">*</span></label>
                        <input type="text" id="name" name="name" value="{{ old('name', $branch->name) }}" required class="form-control @error('name') is-invalid @enderror" placeholder="Ej.: Sede central Miraflores">
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-sm-5">
                            <label for="code" class="form-label">Código interno</label>
                            <input type="text" id="code" name="code" value="{{ old('code', $branch->code) }}" placeholder="SUC-01" class="form-control">
                        </div>
                        @php
                            // Zonas de la empresa de la sede (en la vista global aún no hay empresa: se asigna luego en Organización)
                            $zones = $branch->tenant_id ? \App\Modules\Core\Models\Zone::where('tenant_id', $branch->tenant_id)->orderBy('name')->get(['id', 'name']) : collect();
                            $structureLocked = app(\App\Modules\Core\Services\OrgScope::class)->restricted();
                        @endphp
                        <div class="col-sm-6">
                            <label for="branch_type" class="form-label">Tipo de sede</label>
                            <select id="branch_type" name="branch_type" class="form-select @error('branch_type') is-invalid @enderror">
                                @foreach(\App\Modules\Core\Models\Branch::TYPES as $value => $label)
                                    <option value="{{ $value }}" @selected(old('branch_type', $branch->branch_type ?? 'agency') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        @if($zones->isNotEmpty() && ! $structureLocked)
                            <div class="col-sm-6">
                                <label for="zone_id" class="form-label">Zona</label>
                                <select id="zone_id" name="zone_id" class="form-select @error('zone_id') is-invalid @enderror">
                                    <option value="">Sin zona</option>
                                    @foreach($zones as $zone)
                                        <option value="{{ $zone->id }}" @selected(old('zone_id', $branch->zone_id) == $zone->id)>{{ $zone->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                        <div class="col-sm-7">
                            <label for="supervisor_id" class="form-label">Supervisor a cargo</label>
                            <select id="supervisor_id" name="supervisor_id" class="form-select @error('supervisor_id') is-invalid @enderror">
                                <option value="">Sin supervisor</option>
                                @foreach($supervisors as $s)
                                    <option value="{{ $s->id }}" data-tenant="{{ $s->tenant_id }}" @selected(old('supervisor_id', $branch->supervisor_id) == $s->id)>{{ $s->first_name }} {{ $s->last_name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="address" class="form-label">Dirección <span class="text-danger">*</span></label>
                        <input type="text" id="address" name="address" value="{{ old('address', $branch->address) }}" required maxlength="255" placeholder="Av. Principal 123" class="form-control @error('address') is-invalid @enderror">
                        <div class="small mt-1 d-none" id="addressSuggestion">
                            <i class="bi bi-geo-alt me-1 text-body-secondary"></i><span class="text-body-secondary">Dirección del punto en el mapa:</span>
                            <span id="addressSuggestionText"></span>
                            <button type="button" class="btn btn-link btn-sm p-0 align-baseline" id="useSuggestedAddress">Usar dirección sugerida</button>
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <label for="city" class="form-label">Ciudad</label>
                            <input type="text" id="city" name="city" value="{{ old('city', $branch->city ?? 'Lima') }}" class="form-control">
                        </div>
                        <div class="col-sm-6">
                            <label for="country" class="form-label">País</label>
                            <input type="text" id="country" name="country" value="{{ old('country', $branch->country ?? 'Perú') }}" class="form-control">
                        </div>
                    </div>

                    {{-- Radio de la geocerca: el empleado solo puede marcar dentro de este círculo --}}
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center gap-2 mb-1">
                            <label for="radiusInput" class="form-label mb-0">Radio de la geocerca</label>
                            <div class="input-group input-group-sm" style="max-width: 140px;">
                                <input type="number" class="form-control text-end @error('allowed_radius_meters') is-invalid @enderror" id="radiusInput" name="allowed_radius_meters" min="10" max="5000" step="1" value="{{ $radius }}" required>
                                <span class="input-group-text">m</span>
                            </div>
                        </div>
                        <input type="range" class="form-range" id="radiusSlider" min="10" max="1000" step="5" value="{{ min($radius, 1000) }}" aria-label="Radio de la geocerca en metros">
                        <div class="d-flex justify-content-between small text-body-secondary">
                            <span>10 m (estricto)</span><span>500 m</span><span>1 km (obra)</span>
                        </div>
                        @error('allowed_radius_meters')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        <div class="form-text">Para más de 1 km escribe el valor (máximo 5000 m).</div>
                    </div>

                    {{-- Ajustes de geocerca de la sede: vacío = el valor de la empresa --}}
                    @php $tuned = old('geofence_tolerance_meters', $branch->geofence_tolerance_meters ?? null) !== null
                        || old('max_gps_accuracy_meters', $branch->max_gps_accuracy_meters ?? null) !== null
                        || old('geofence_policy', $branch->geofence_policy ?? null) !== null; @endphp
                    <details class="mb-3" @if($tuned || $errors->hasAny(['geofence_tolerance_meters', 'max_gps_accuracy_meters', 'geofence_policy'])) open @endif>
                        <summary class="small fw-semibold mb-2">Ajustes de geocerca de esta sede</summary>
                        <div class="row g-3">
                            <div class="col-sm-6">
                                <label for="toleranceInput" class="form-label">Tolerancia de borde</label>
                                <div class="input-group">
                                    <input type="number" class="form-control @error('geofence_tolerance_meters') is-invalid @enderror" id="toleranceInput" name="geofence_tolerance_meters" min="0" max="100" step="1"
                                           value="{{ old('geofence_tolerance_meters', $branch->geofence_tolerance_meters ?? '') }}" placeholder="{{ \App\Modules\Core\Models\Branch::DEFAULT_EDGE_TOLERANCE }}">
                                    <span class="input-group-text">m</span>
                                </div>
                                @error('geofence_tolerance_meters')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                                <div class="form-text">Si el GPS es impreciso, acepta hasta estos metros fuera del radio y la marca como observada. 0 la desactiva.</div>
                            </div>
                            <div class="col-sm-6">
                                <label for="accuracyInput" class="form-label">Precisión GPS máxima</label>
                                <div class="input-group">
                                    <input type="number" class="form-control @error('max_gps_accuracy_meters') is-invalid @enderror" id="accuracyInput" name="max_gps_accuracy_meters" min="5" max="500" step="1"
                                           value="{{ old('max_gps_accuracy_meters', $branch->max_gps_accuracy_meters ?? '') }}" placeholder="La de la empresa">
                                    <span class="input-group-text">m</span>
                                </div>
                                @error('max_gps_accuracy_meters')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                                <div class="form-text">Lecturas menos precisas se aceptan solo si todo su margen de error queda dentro del radio; si no, se rechazan. En sedes techadas sube este valor o el radio.</div>
                            </div>
                            <div class="col-12">
                                <label for="policyInput" class="form-label">Fuera del radio</label>
                                <select id="policyInput" name="geofence_policy" class="form-select @error('geofence_policy') is-invalid @enderror">
                                    <option value="">Lo que defina la empresa</option>
                                    @foreach (\App\Modules\Core\Models\CompanyConfiguration::GEOFENCE_POLICIES as $key => [$label])
                                        <option value="{{ $key }}" @selected(old('geofence_policy', $branch->geofence_policy ?? '') === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                @error('geofence_policy')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </details>

                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <label for="latInput" class="form-label">Latitud <span class="text-danger">*</span></label>
                            <input type="number" step="0.00000001" id="latInput" name="latitude" value="{{ old('latitude', $branch->latitude ?? -12.046374) }}" required class="form-control font-monospace">
                        </div>
                        <div class="col-sm-6">
                            <label for="lngInput" class="form-label">Longitud <span class="text-danger">*</span></label>
                            <input type="number" step="0.00000001" id="lngInput" name="longitude" value="{{ old('longitude', $branch->longitude ?? -77.042793) }}" required class="form-control font-monospace">
                        </div>
                    </div>

                    <div class="form-check form-switch mb-4">
                        <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1" @checked(old('is_active', $branch->is_active ?? true))>
                        <label class="form-check-label" for="is_active">Sucursal activa y habilitada para marcar</label>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-fill">{{ $isEdit ? 'Guardar cambios' : 'Crear sucursal' }}</button>
                        <a href="{{ route('branches.index') }}" class="btn btn-outline-secondary">Cancelar</a>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-7">
            <div class="card border-0 h-100">
                <div class="card-header border-0 pt-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <h2 class="h6 fw-semibold mb-0">Ubicación en el mapa</h2>
                        <small class="text-body-secondary">Busca una dirección, haz clic o arrastra el pin para reubicar</small>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="myLocation"><i class="bi bi-crosshair me-1"></i>Usar mi ubicación</button>
                </div>
                <div class="card-body pt-2">
                    {{-- Búsqueda predictiva de direcciones (GET /geocode/search) --}}
                    <div class="position-relative mb-2" id="addressSearchBox">
                        <label for="addressSearch" class="visually-hidden">Buscar dirección</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                            <input type="search" id="addressSearch" class="form-control" placeholder="Buscar dirección (ej.: Av. Larco 345, Miraflores)"
                                   autocomplete="off" spellcheck="false" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="addressResults"
                                   data-search-url="{{ url('/geocode/search') }}" data-reverse-url="{{ url('/geocode/reverse') }}">
                            <span class="input-group-text d-none" id="addressSearchSpinner"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span><span class="visually-hidden">Buscando…</span></span>
                        </div>
                        <ul class="list-group position-absolute w-100 shadow-sm d-none geo-results" id="addressResults" role="listbox" aria-label="Resultados de la búsqueda"></ul>
                        <div class="small mt-1 d-none" id="addressSearchStatus" aria-live="polite"></div>
                    </div>
                    <div id="pickerMap" class="map-frame rounded" style="height: 480px;"></div>
                </div>
            </div>
        </div>
    </div>
</form>
@endsection

@section('styles')
<style>
    /* Sobre los controles de Leaflet (z-index 1000) */
    .geo-results { z-index: 1100; max-height: 280px; overflow-y: auto; top: 100%; left: 0; }
    .geo-results [role="option"] { cursor: pointer; }
</style>
@endsection

@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const latInput = document.getElementById('latInput');
    const lngInput = document.getElementById('lngInput');
    const slider = document.getElementById('radiusSlider');
    const radiusInput = document.getElementById('radiusInput');
    const brand = Panel.cssVar('--brand') || '#1E40AF';

    const start = [parseFloat(latInput.value) || -12.046374, parseFloat(lngInput.value) || -77.042793];
    const map = L.map('pickerMap').setView(start, 16);
    Panel.tiles(map);

    const marker = L.marker(start, { draggable: true, title: 'Centro de la sucursal' }).addTo(map);
    const circle = L.circle(start, { radius: parseInt(radiusInput.value, 10) || 50, color: brand, fillColor: brand, fillOpacity: .15, weight: 2 }).addTo(map);

    function moveTo(lat, lng, pan) {
        latInput.value = lat.toFixed(7);
        lngInput.value = lng.toFixed(7);
        marker.setLatLng([lat, lng]);
        circle.setLatLng([lat, lng]);
        if (pan) map.setView([lat, lng], Math.max(map.getZoom(), 16));
    }


    // ===================== Búsqueda de direcciones y geocodificación inversa =====================
    const search = document.getElementById('addressSearch');
    const results = document.getElementById('addressResults');
    const spinner = document.getElementById('addressSearchSpinner');
    const status = document.getElementById('addressSearchStatus');
    const addressInput = document.getElementById('address');
    const cityInput = document.getElementById('city');
    const suggestion = document.getElementById('addressSuggestion');
    const suggestionText = document.getElementById('addressSuggestionText');
    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    // La dirección se completa sola mientras el usuario no la haya escrito a mano
    let addressAuto = addressInput.value.trim() === '';
    let suggested = null;
    addressInput.addEventListener('input', () => {
        addressAuto = addressInput.value.trim() === '';
        if (addressAuto) hideSuggestion();
    });

    function showStatus(message, type) {
        status.className = 'small mt-1 ' + (type === 'error' ? 'text-danger' : 'text-body-secondary');
        status.innerHTML = message ? (type === 'error' ? '<i class="bi bi-exclamation-triangle me-1"></i>' : '') + esc(message) : '';
        status.classList.toggle('d-none', !message);
    }

    async function getJson(url, signal) {
        const res = await fetch(url, { headers: { 'Accept': 'application/json' }, signal: signal });
        let data = null;
        try { data = await res.json(); } catch (e) { /* no JSON */ }
        if (res.status === 404 && !(data && 'success' in data)) throw new Error('La búsqueda de direcciones no está disponible en este servidor.');
        if (res.status === 429) throw new Error('Demasiadas búsquedas seguidas; espera unos segundos.');
        if (!res.ok || !data || data.success === false) throw new Error((data && data.message) || 'No se pudo consultar la dirección. Intenta de nuevo o ubica el punto en el mapa.');
        return data.data;
    }

    // "345, Avenida José Larco, Miraflores, Lima, Provincia de Lima, 15074, Perú" → dirección + ciudad
    function splitLabel(label) {
        let parts = String(label || '').split(',').map((p) => p.trim()).filter(Boolean)
            .filter((p) => !/^\d{4,6}$/.test(p) && !/^(per[uú]|peru)$/i.test(p) && !/^(provincia|departamento|regi[oó]n)\b/i.test(p));
        parts = parts.filter((p, i) => i === 0 || p.toLowerCase() !== parts[i - 1].toLowerCase());
        if (parts.length > 1 && /^\d+[a-z]?$/i.test(parts[0])) parts.splice(0, 2, parts[1] + ' ' + parts[0]);
        const city = parts.length > 1 ? parts.pop().replace(/\s+\d{4,6}$/, "") : null;
        return { address: parts.join(', ').slice(0, 255), city: city };
    }

    function fillAddress(label, withCity) {
        const parts = splitLabel(label);
        addressInput.value = parts.address || String(label).slice(0, 255);
        if (withCity && parts.city) cityInput.value = parts.city.slice(0, 100);
        addressAuto = true;
        addressInput.classList.remove('is-invalid');
        hideSuggestion();
    }

    function hideSuggestion() { suggested = null; suggestion.classList.add('d-none'); }
    document.getElementById('useSuggestedAddress').addEventListener('click', () => { if (suggested) fillAddress(suggested, true); });

    // --- Búsqueda predictiva (400 ms, mínimo 3 caracteres) ---
    let items = [];
    let active = -1;
    let searchTimer = null;
    let searchCtrl = null;

    function closeResults() {
        results.classList.add('d-none');
        results.innerHTML = '';
        search.setAttribute('aria-expanded', 'false');
        search.removeAttribute('aria-activedescendant');
        items = [];
        active = -1;
    }

    function highlight(index) {
        active = index;
        results.querySelectorAll('[role="option"]').forEach((li, i) => {
            li.classList.toggle('active', i === index);
            li.setAttribute('aria-selected', i === index ? 'true' : 'false');
            if (i === index) { li.scrollIntoView({ block: 'nearest' }); search.setAttribute('aria-activedescendant', li.id); }
        });
    }

    function renderResults(list) {
        items = list;
        active = -1;
        results.innerHTML = list.length
            ? list.map((r, i) => '<li class="list-group-item list-group-item-action small" role="option" id="geo-opt-' + i + '" data-index="' + i + '" aria-selected="false">' +
                '<i class="bi bi-geo-alt me-1 text-body-secondary"></i>' + esc(r.label) + '</li>').join('')
            : '<li class="list-group-item small text-body-secondary">Sin resultados. Prueba con calle y distrito, o ubica el punto en el mapa.</li>';
        results.classList.remove('d-none');
        search.setAttribute('aria-expanded', 'true');
    }

    function choose(index) {
        const r = items[index];
        if (!r) return;
        const lat = parseFloat(r.lat), lng = parseFloat(r.lng);
        if (isNaN(lat) || isNaN(lng)) return;
        moveTo(lat, lng, true);
        fillAddress(r.label, true);
        search.value = r.label;
        closeResults();
        showStatus('');
    }

    async function runSearch(q) {
        searchCtrl?.abort();
        searchCtrl = new AbortController();
        const url = new URL(search.dataset.searchUrl, window.location.origin);
        url.searchParams.set('q', q);
        spinner.classList.remove('d-none');
        try {
            const list = await getJson(url, searchCtrl.signal);
            if (search.value.trim() !== q) return; // llegó tarde: ya se escribió otra cosa
            showStatus('');
            renderResults(Array.isArray(list) ? list : []);
        } catch (e) {
            if (e.name === 'AbortError') return;
            closeResults();
            showStatus(e.message, 'error');
        } finally {
            spinner.classList.add('d-none');
        }
    }

    search.addEventListener('input', () => {
        clearTimeout(searchTimer);
        const q = search.value.trim();
        if (q.length < 3) { searchCtrl?.abort(); closeResults(); showStatus(q.length ? 'Escribe al menos 3 caracteres.' : ''); return; }
        showStatus('');
        searchTimer = setTimeout(() => runSearch(q), 400);
    });

    search.addEventListener('keydown', (e) => {
        const open = !results.classList.contains('d-none') && items.length > 0;
        if (e.key === 'ArrowDown' && open) { e.preventDefault(); highlight((active + 1) % items.length); }
        else if (e.key === 'ArrowUp' && open) { e.preventDefault(); highlight(active <= 0 ? items.length - 1 : active - 1); }
        else if (e.key === 'Enter') {
            // Nunca envía el formulario desde el buscador
            e.preventDefault();
            if (open) choose(active >= 0 ? active : 0);
        } else if (e.key === 'Escape') { closeResults(); }
    });

    // mousedown (no click) para que el input no pierda el foco antes de elegir
    results.addEventListener('mousedown', (e) => {
        const li = e.target.closest('[data-index]');
        if (!li) return;
        e.preventDefault();
        choose(parseInt(li.dataset.index, 10));
    });
    search.addEventListener('blur', () => setTimeout(closeResults, 150));

    // --- Geocodificación inversa al mover el pin o hacer clic en el mapa ---
    let reverseTimer = null;
    let reverseCtrl = null;
    function reverseGeocode() {
        clearTimeout(reverseTimer);
        reverseTimer = setTimeout(async () => {
            reverseCtrl?.abort();
            reverseCtrl = new AbortController();
            const url = new URL(search.dataset.reverseUrl, window.location.origin);
            url.searchParams.set('lat', latInput.value);
            url.searchParams.set('lng', lngInput.value);
            try {
                const data = await getJson(url, reverseCtrl.signal);
                if (!data || !data.address) return;
                if (addressAuto) {
                    fillAddress(data.address, true);
                } else if (data.address !== addressInput.value) {
                    suggested = data.address;
                    suggestionText.textContent = data.address;
                    suggestion.classList.remove('d-none');
                }
                showStatus('');
            } catch (e) {
                if (e.name !== 'AbortError') showStatus('No se pudo obtener la dirección de ese punto: ' + e.message, 'error');
            }
        }, 600);
    }

    marker.on('dragend', () => { const p = marker.getLatLng(); moveTo(p.lat, p.lng); reverseGeocode(); });
    map.on('click', (e) => { moveTo(e.latlng.lat, e.latlng.lng); reverseGeocode(); });
    // Slider y campo numérico van sincronizados; el círculo se redibuja en vivo
    function setRadius(meters, fit) {
        circle.setRadius(meters);
        if (fit) map.fitBounds(circle.getBounds(), { maxZoom: 17, padding: [24, 24] });
    }
    slider.addEventListener('input', () => {
        radiusInput.value = slider.value;
        setRadius(parseInt(slider.value, 10));
    });
    slider.addEventListener('change', () => setRadius(parseInt(slider.value, 10), true));
    radiusInput.addEventListener('input', () => {
        const meters = parseInt(radiusInput.value, 10);
        if (isNaN(meters) || meters < 10) return;
        slider.value = Math.min(meters, 1000);
        setRadius(Math.min(meters, 5000));
    });
    radiusInput.addEventListener('change', () => {
        const meters = Math.min(5000, Math.max(10, parseInt(radiusInput.value, 10) || 50));
        radiusInput.value = meters;
        slider.value = Math.min(meters, 1000);
        setRadius(meters, true);
    });

    // Vista global: solo se ofrecen los supervisores de la empresa elegida
    const company = document.getElementById('tenant_id');
    if (company) {
        const supervisor = document.getElementById('supervisor_id');
        const filterSupervisors = () => {
            supervisor.querySelectorAll('option[data-tenant]').forEach((opt) => {
                const show = opt.dataset.tenant === company.value;
                opt.hidden = !show;
                opt.disabled = !show;
                if (!show && opt.selected) supervisor.value = '';
            });
        };
        company.addEventListener('change', filterSupervisors);
        filterSupervisors();
    }
    [latInput, lngInput].forEach((input) => input.addEventListener('change', () => {
        const lat = parseFloat(latInput.value), lng = parseFloat(lngInput.value);
        if (!isNaN(lat) && !isNaN(lng)) { moveTo(lat, lng, true); reverseGeocode(); }
    }));

    document.getElementById('myLocation').addEventListener('click', () => {
        if (!navigator.geolocation) return Panel.toast('Tu navegador no permite obtener la ubicación.', 'warning');
        navigator.geolocation.getCurrentPosition(
            (pos) => { moveTo(pos.coords.latitude, pos.coords.longitude, true); reverseGeocode(); },
            () => Panel.toast('No se pudo obtener la ubicación del navegador.', 'warning'),
            { enableHighAccuracy: true, timeout: 10000 }
        );
    });
});
</script>
@endsection
