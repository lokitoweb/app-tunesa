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
        <p>Haz clic en el mapa o arrastra el pin para fijar el punto central, y ajusta el radio permitido.</p>
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
                        <input type="text" id="address" name="address" value="{{ old('address', $branch->address) }}" required placeholder="Av. Principal 123" class="form-control @error('address') is-invalid @enderror">
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
                        <small class="text-body-secondary">Haz clic o arrastra el pin para reubicar</small>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="myLocation"><i class="bi bi-crosshair me-1"></i>Usar mi ubicación</button>
                </div>
                <div class="card-body pt-2">
                    <div id="pickerMap" class="map-frame rounded" style="height: 480px;"></div>
                </div>
            </div>
        </div>
    </div>
</form>
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

    marker.on('dragend', () => { const p = marker.getLatLng(); moveTo(p.lat, p.lng); });
    map.on('click', (e) => moveTo(e.latlng.lat, e.latlng.lng));
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
        if (!isNaN(lat) && !isNaN(lng)) moveTo(lat, lng, true);
    }));

    document.getElementById('myLocation').addEventListener('click', () => {
        if (!navigator.geolocation) return alert('Tu navegador no permite obtener la ubicación.');
        navigator.geolocation.getCurrentPosition(
            (pos) => moveTo(pos.coords.latitude, pos.coords.longitude, true),
            () => alert('No se pudo obtener la ubicación del navegador.'),
            { enableHighAccuracy: true, timeout: 10000 }
        );
    });
});
</script>
@endsection
