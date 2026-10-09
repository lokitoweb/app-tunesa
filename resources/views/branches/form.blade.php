@extends('layouts.app')

@section('title', ($isEdit ? 'Editar ' : 'Nueva ') . 'Sucursal & Geocerca')

@section('content')
<div style="margin-bottom: 1.5rem;">
    <a href="{{ route('branches.index') }}" style="color: #818cf8; font-size: 0.85rem; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; margin-bottom: 0.5rem;">
        ← Volver al listado de sucursales
    </a>
    <h1 style="font-size: 1.75rem; font-weight: 800; color: #fff;">{{ $isEdit ? 'Editar Sucursal: ' . $branch->name : 'Registrar Nueva Sucursal / Obra' }}</h1>
    <p style="color: var(--text-secondary); font-size: 0.9rem;">Haz clic sobre el mapa para fijar el punto GPS central y ajusta el radio perimetral permitido.</p>
</div>

@if ($errors->any())
    <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.3); border-radius: 12px; padding: 1rem 1.25rem; color: #fca5a5; font-size: 0.88rem; margin-bottom: 1.5rem;">
        <ul style="margin: 0; padding-left: 1.25rem;">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form method="POST" action="{{ $isEdit ? route('branches.update', $branch->id) : route('branches.store') }}">
    @csrf
    @if($isEdit)
        @method('PUT')
    @endif

    <div style="display: grid; grid-template-columns: 1fr 1.3fr; gap: 1.5rem;">
        <!-- PANEL FORMULARIO -->
        <div style="background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 1.5rem;">
            <h3 style="color: #fff; font-size: 1.1rem; font-weight: 700; margin-bottom: 1.25rem; border-bottom: 1px solid var(--border); padding-bottom: 0.75rem;">
                Configuración de la Geocerca
            </h3>

            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.82rem; color: #94a3b8; margin-bottom: 0.4rem;">Nombre de la Sede / Obra *</label>
                <input type="text" name="name" value="{{ old('name', $branch->name) }}" required class="form-control" placeholder="Ej: Sucursal Central Miraflores" style="width: 100%; background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.65rem; border-radius: 8px;">
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                <div>
                    <label style="display: block; font-size: 0.82rem; color: #94a3b8; margin-bottom: 0.4rem;">Código Interno</label>
                    <input type="text" name="code" value="{{ old('code', $branch->code) }}" placeholder="SUC-01" class="form-control" style="width: 100%; background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.65rem; border-radius: 8px;">
                </div>
                <div>
                    <label style="display: block; font-size: 0.82rem; color: #94a3b8; margin-bottom: 0.4rem;">Supervisor a Cargo</label>
                    <select name="supervisor_id" class="form-control" style="width: 100%; background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.65rem; border-radius: 8px;">
                        <option value="">-- Sin supervisor --</option>
                        @foreach($supervisors as $s)
                            <option value="{{ $s->id }}" {{ old('supervisor_id', $branch->supervisor_id) == $s->id ? 'selected' : '' }}>
                                {{ $s->first_name }} {{ $s->last_name }} ({{ ucfirst($s->role) }})
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.82rem; color: #94a3b8; margin-bottom: 0.4rem;">Dirección Física *</label>
                <input type="text" name="address" value="{{ old('address', $branch->address) }}" required placeholder="Av. Principal 123" class="form-control" style="width: 100%; background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.65rem; border-radius: 8px;">
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.25rem;">
                <div>
                    <label style="display: block; font-size: 0.82rem; color: #94a3b8; margin-bottom: 0.4rem;">Ciudad</label>
                    <input type="text" name="city" value="{{ old('city', $branch->city ?? 'Lima') }}" class="form-control" style="width: 100%; background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.65rem; border-radius: 8px;">
                </div>
                <div>
                    <label style="display: block; font-size: 0.82rem; color: #94a3b8; margin-bottom: 0.4rem;">País</label>
                    <input type="text" name="country" value="{{ old('country', $branch->country ?? 'Perú') }}" class="form-control" style="width: 100%; background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.65rem; border-radius: 8px;">
                </div>
            </div>

            <!-- CONTROL DEL RADIO PERMITIDO (SLIDER REACTIVO) -->
            <div style="background: #1a1c26; border-radius: 12px; padding: 1.25rem; margin-bottom: 1.25rem; border: 1px solid rgba(255,255,255,0.06);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
                    <label style="font-weight: 700; color: #fff; font-size: 0.9rem;">Radio Permitido (Geocerca)</label>
                    <span id="radiusBadge" style="font-size: 1rem; font-weight: 800; color: #10b981; background: rgba(16,185,129,0.15); padding: 3px 10px; border-radius: 6px;">
                        {{ old('allowed_radius_meters', $branch->allowed_radius_meters ?? 50) }} metros
                    </span>
                </div>
                <input type="range" id="radiusSlider" name="allowed_radius_meters" min="10" max="500" step="5" value="{{ old('allowed_radius_meters', $branch->allowed_radius_meters ?? 50) }}" style="width: 100%; accent-color: #6366f1; cursor: pointer;">
                <div style="display: flex; justify-content: space-between; font-size: 0.72rem; color: #64748b; margin-top: 4px;">
                    <span>10m (Estricto)</span>
                    <span>100m</span>
                    <span>250m</span>
                    <span>500m (Amplio/Obra)</span>
                </div>
            </div>

            <!-- COORDENADAS NUMÉRICAS -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem;">
                <div>
                    <label style="display: block; font-size: 0.8rem; color: #94a3b8; margin-bottom: 0.3rem;">Latitud GPS *</label>
                    <input type="number" step="0.00000001" id="latInput" name="latitude" value="{{ old('latitude', $branch->latitude ?? -12.046374) }}" required class="form-control" style="width: 100%; background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.6rem; border-radius: 8px; font-family: monospace;">
                </div>
                <div>
                    <label style="display: block; font-size: 0.8rem; color: #94a3b8; margin-bottom: 0.3rem;">Longitud GPS *</label>
                    <input type="number" step="0.00000001" id="lngInput" name="longitude" value="{{ old('longitude', $branch->longitude ?? -77.042793) }}" required class="form-control" style="width: 100%; background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.6rem; border-radius: 8px; font-family: monospace;">
                </div>
            </div>

            <div style="margin-bottom: 1.5rem;">
                <label style="display: flex; align-items: center; gap: 8px; font-size: 0.85rem; color: #fff; cursor: pointer;">
                    <input type="checkbox" name="is_active" value="1" {{ old('is_active', $branch->is_active ?? true) ? 'checked' : '' }} style="accent-color: #6366f1; width: 16px; height: 16px;">
                    Sucursal activa y habilitada para marcaciones
                </label>
            </div>

            <div style="display: flex; gap: 10px;">
                <button type="submit" style="flex: 1; padding: 0.85rem; background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%); border: none; border-radius: 10px; color: #fff; font-weight: 700; cursor: pointer; box-shadow: 0 4px 15px rgba(99,102,241,0.35);">
                    {{ $isEdit ? 'Guardar Cambios' : 'Crear Sucursal' }}
                </button>
                <a href="{{ route('branches.index') }}" style="padding: 0.85rem 1.25rem; background: rgba(255,255,255,0.05); border: 1px solid var(--border); border-radius: 10px; color: #cbd5e1; text-decoration: none; font-weight: 600; text-align: center;">
                    Cancelar
                </a>
            </div>
        </div>

        <!-- SELECTOR INTERACTIVO EN MAPA LEAFLET -->
        <div style="background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-lg); overflow: hidden; display: flex; flex-direction: column;">
            <div style="padding: 1rem 1.25rem; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; background: rgba(0,0,0,0.2);">
                <div>
                    <h4 style="color: #fff; font-size: 0.95rem; font-weight: 700;">Selector Visual de Geocerca</h4>
                    <p style="font-size: 0.75rem; color: #94a3b8; margin: 0;">Haz clic o arrastra el pin para reubicar</p>
                </div>
                <button type="button" onclick="getCurrentLocation()" style="background: rgba(99,102,241,0.15); border: 1px solid rgba(99,102,241,0.3); color: #818cf8; padding: 5px 10px; border-radius: 6px; font-size: 0.75rem; font-weight: 600; cursor: pointer;">
                    📍 Mi Ubicación GPS
                </button>
            </div>
            <div id="pickerMap" style="flex: 1; min-height: 480px; background: #0a0b0f;"></div>
        </div>
    </div>
</form>
@endsection

@section('scripts')
<script>
    let pickerMap;
    let marker;
    let circle;

    document.addEventListener('DOMContentLoaded', function() {
        const latInput = document.getElementById('latInput');
        const lngInput = document.getElementById('lngInput');
        const radiusSlider = document.getElementById('radiusSlider');
        const radiusBadge = document.getElementById('radiusBadge');

        let currentLat = parseFloat(latInput.value) || -12.046374;
        let currentLng = parseFloat(lngInput.value) || -77.042793;
        let currentRadius = parseInt(radiusSlider.value) || 50;

        pickerMap = L.map('pickerMap').setView([currentLat, currentLng], 16);

        L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
            maxZoom: 19
        }).addTo(pickerMap);

        // Marcador arrastrable
        marker = L.marker([currentLat, currentLng], {
            draggable: true
        }).addTo(pickerMap);

        // Círculo interactivo que representa la geocerca
        circle = L.circle([currentLat, currentLng], {
            color: '#6366f1',
            fillColor: '#6366f1',
            fillOpacity: 0.2,
            weight: 2,
            radius: currentRadius
        }).addTo(pickerMap);

        // Evento al arrastrar el marcador
        marker.on('dragend', function(e) {
            const pos = marker.getLatLng();
            updateLocation(pos.lat, pos.lng);
        });

        // Evento al hacer clic en cualquier parte del mapa
        pickerMap.on('click', function(e) {
            updateLocation(e.latlng.lat, e.latlng.lng);
        });

        // Evento slider de radio
        radiusSlider.addEventListener('input', function() {
            const r = parseInt(this.value);
            radiusBadge.innerText = r + ' metros';
            circle.setRadius(r);
        });

        // Eventos inputs de latitud/longitud
        latInput.addEventListener('change', () => syncFromInputs());
        lngInput.addEventListener('change', () => syncFromInputs());

        function updateLocation(lat, lng) {
            latInput.value = lat.toFixed(7);
            lngInput.value = lng.toFixed(7);
            marker.setLatLng([lat, lng]);
            circle.setLatLng([lat, lng]);
        }

        function syncFromInputs() {
            const lat = parseFloat(latInput.value);
            const lng = parseFloat(lngInput.value);
            if (!isNaN(lat) && !isNaN(lng)) {
                marker.setLatLng([lat, lng]);
                circle.setLatLng([lat, lng]);
                pickerMap.panTo([lat, lng]);
            }
        }
    });

    function getCurrentLocation() {
        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(function(pos) {
                const lat = pos.coords.latitude;
                const lng = pos.coords.longitude;
                document.getElementById('latInput').value = lat.toFixed(7);
                document.getElementById('lngInput').value = lng.toFixed(7);
                marker.setLatLng([lat, lng]);
                circle.setLatLng([lat, lng]);
                pickerMap.setView([lat, lng], 17);
            }, function() {
                alert("No se pudo obtener la geolocalización del navegador.");
            });
        }
    }
</script>
@endsection
