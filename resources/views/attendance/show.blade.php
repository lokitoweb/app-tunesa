@extends('layouts.app')

@section('title', 'Auditoría Forense de Marcación #' . $attendance->id)

@section('content')
<div style="margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
    <div>
        <a href="{{ route('attendance.index') }}" style="color: #818cf8; font-size: 0.85rem; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; margin-bottom: 0.5rem;">
            ← Volver al listado de asistencias
        </a>
        <h1 style="font-size: 1.75rem; font-weight: 800; color: #fff; letter-spacing: -0.5px;">Auditoría de Marcación #{{ $attendance->id }}</h1>
        <p style="color: var(--text-secondary); font-size: 0.9rem;">Fecha de operación: {{ $attendance->work_date ? $attendance->work_date->format('d/m/Y') : '--' }} • {{ $attendance->user->first_name }} {{ $attendance->user->last_name }}</p>
    </div>
    <div>
        <span style="font-size: 0.9rem; padding: 6px 14px; border-radius: 8px; font-weight: 700;
            @if($attendance->status === 'present') background: rgba(16,185,129,0.15); color: #10b981; border: 1px solid rgba(16,185,129,0.3);
            @elseif($attendance->status === 'late') background: rgba(245,158,11,0.15); color: #f59e0b; border: 1px solid rgba(245,158,11,0.3);
            @elseif($attendance->status === 'excused') background: rgba(59,130,246,0.15); color: #60a5fa; border: 1px solid rgba(59,130,246,0.3);
            @else background: rgba(239,68,68,0.15); color: #f87171; border: 1px solid rgba(239,68,68,0.3); @endif">
            Estado: {{ strtoupper($attendance->status) }}
        </span>
    </div>
</div>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1.5rem;">
    <!-- CARD ENTRADA (CLOCK-IN) -->
    <div style="background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 1.5rem;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem;">
            <h3 style="color: #fff; font-size: 1.1rem; font-weight: 700; display: flex; align-items: center; gap: 8px;">
                <span style="width: 10px; height: 10px; border-radius: 50%; background: #10b981;"></span>
                Marcación de Entrada (Clock-In)
            </h3>
            <span style="font-size: 0.78rem; padding: 3px 8px; border-radius: 6px; font-weight: 600; {{ $attendance->clock_in_within_geofence ? 'background: rgba(16,185,129,0.15); color: #10b981;' : 'background: rgba(239,68,68,0.15); color: #ef4444;' }}">
                {{ $attendance->clock_in_within_geofence ? '✓ Geocerca Válida' : '⚠ Fuera de Rango' }}
            </span>
        </div>

        <div style="display: grid; grid-template-columns: 140px 1fr; gap: 1.25rem; align-items: start;">
            <div>
                @if($attendance->clock_in_photo)
                    <img src="{{ asset('storage/' . $attendance->clock_in_photo) }}" alt="Selfie Entrada" style="width: 100%; height: 160px; object-fit: cover; border-radius: 12px; border: 2px solid {{ $attendance->clock_in_within_geofence ? '#10b981' : '#ef4444' }}; cursor: pointer;" onclick="openPhotoModal('{{ asset('storage/' . $attendance->clock_in_photo) }}', 'Selfie de Entrada', 'Hora: {{ $attendance->clock_in_at ? $attendance->clock_in_at->format('H:i:s') : '' }} | Distancia: {{ $attendance->clock_in_distance_meters }}m')">
                    <div style="font-size: 0.72rem; color: #818cf8; text-align: center; margin-top: 4px; cursor: pointer;">🔍 Clic para ampliar</div>
                @else
                    <div style="width: 100%; height: 160px; border-radius: 12px; background: #1a1c26; display: flex; align-items: center; justify-content: center; color: #64748b; font-size: 0.8rem; text-align: center; padding: 10px;">
                        Sin selfie registrada
                    </div>
                @endif
            </div>

            <div style="font-size: 0.88rem; line-height: 1.8;">
                <div><span style="color: #94a3b8;">Hora exacta:</span> <strong style="color: #fff;">{{ $attendance->clock_in_at ? $attendance->clock_in_at->format('H:i:s') : '--' }}</strong></div>
                <div><span style="color: #94a3b8;">Distancia calculada:</span> <strong style="color: {{ $attendance->clock_in_within_geofence ? '#10b981' : '#ef4444' }};">{{ $attendance->clock_in_distance_meters ?? 0 }} metros</strong> (Permitido: {{ $attendance->branch->allowed_radius_meters ?? 50 }}m)</div>
                <div><span style="color: #94a3b8;">Coordenadas GPS:</span> <span style="font-family: monospace; color: #cbd5e1;">{{ $attendance->clock_in_latitude }}, {{ $attendance->clock_in_longitude }}</span></div>
                <div><span style="color: #94a3b8;">Tardanza:</span> <strong style="color: {{ $attendance->late_minutes > 0 ? '#f59e0b' : '#10b981' }};">{{ $attendance->late_minutes ?? 0 }} minutos</strong></div>
                <div><span style="color: #94a3b8;">ID Dispositivo:</span> <span style="font-size: 0.75rem; color: #94a3b8; font-family: monospace;">{{ $attendance->clock_in_device_id ?? 'N/A' }}</span></div>
            </div>
        </div>
    </div>

    <!-- CARD SALIDA (CLOCK-OUT) -->
    <div style="background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 1.5rem;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem;">
            <h3 style="color: #fff; font-size: 1.1rem; font-weight: 700; display: flex; align-items: center; gap: 8px;">
                <span style="width: 10px; height: 10px; border-radius: 50%; background: #ef4444;"></span>
                Marcación de Salida (Clock-Out)
            </h3>
            <span style="font-size: 0.78rem; padding: 3px 8px; border-radius: 6px; font-weight: 600; {{ $attendance->clock_out_within_geofence ? 'background: rgba(16,185,129,0.15); color: #10b981;' : 'background: rgba(239,68,68,0.15); color: #ef4444;' }}">
                {{ $attendance->clock_out_at ? ($attendance->clock_out_within_geofence ? '✓ Geocerca Válida' : '⚠ Fuera de Rango') : 'Pendiente' }}
            </span>
        </div>

        <div style="display: grid; grid-template-columns: 140px 1fr; gap: 1.25rem; align-items: start;">
            <div>
                @if($attendance->clock_out_photo)
                    <img src="{{ asset('storage/' . $attendance->clock_out_photo) }}" alt="Selfie Salida" style="width: 100%; height: 160px; object-fit: cover; border-radius: 12px; border: 2px solid {{ $attendance->clock_out_within_geofence ? '#10b981' : '#ef4444' }}; cursor: pointer;" onclick="openPhotoModal('{{ asset('storage/' . $attendance->clock_out_photo) }}', 'Selfie de Salida', 'Hora: {{ $attendance->clock_out_at ? $attendance->clock_out_at->format('H:i:s') : '' }} | Distancia: {{ $attendance->clock_out_distance_meters }}m')">
                    <div style="font-size: 0.72rem; color: #818cf8; text-align: center; margin-top: 4px; cursor: pointer;">🔍 Clic para ampliar</div>
                @else
                    <div style="width: 100%; height: 160px; border-radius: 12px; background: #1a1c26; display: flex; align-items: center; justify-content: center; color: #64748b; font-size: 0.8rem; text-align: center; padding: 10px;">
                        {{ $attendance->clock_out_at ? 'Sin selfie' : 'Salida no marcada aún' }}
                    </div>
                @endif
            </div>

            <div style="font-size: 0.88rem; line-height: 1.8;">
                <div><span style="color: #94a3b8;">Hora exacta:</span> <strong style="color: #fff;">{{ $attendance->clock_out_at ? $attendance->clock_out_at->format('H:i:s') : '--:--:--' }}</strong></div>
                <div><span style="color: #94a3b8;">Distancia calculada:</span> <strong style="color: {{ $attendance->clock_out_within_geofence ? '#10b981' : '#ef4444' }};">{{ $attendance->clock_out_distance_meters ?? 0 }} metros</strong></div>
                <div><span style="color: #94a3b8;">Horas efectivas:</span> <strong style="color: #fff;">{{ number_format((float) ($attendance->worked_hours ?? 0), 2) }} horas</strong></div>
                <div><span style="color: #94a3b8;">Horas extras:</span> <strong style="color: #60a5fa;">{{ number_format((float) ($attendance->overtime_hours ?? 0), 2) }} horas</strong></div>
                <div><span style="color: #94a3b8;">Pausa/Almuerzo:</span> <span>{{ $attendance->break_minutes ?? 0 }} minutos consumidos</span></div>
            </div>
        </div>
    </div>
</div>

<!-- MAPA FORENSE: POSICIÓN DE MARCACIÓN VS RADIO DE SUCURSAL -->
<div style="background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-lg); overflow: hidden; margin-bottom: 1.5rem;">
    <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h3 style="color: #fff; font-size: 1.1rem; font-weight: 700;">Validación Geográfica Forense</h3>
            <p style="font-size: 0.8rem; color: #94a3b8; margin-top: 2px;">Círculo azul: Geocerca autorizada de {{ $attendance->branch->name ?? 'Sucursal' }} ({{ $attendance->branch->allowed_radius_meters ?? 50 }}m) | Pin: Coordenadas transmitidas por el móvil</p>
        </div>
    </div>
    <div id="auditMap" style="height: 380px; width: 100%; background: #0a0b0f;"></div>
</div>

<!-- SECCIÓN DE JUSTIFICACIÓN & REVISIÓN -->
@if($attendance->notes || $attendance->justification_status)
    <div style="background: var(--bg-surface); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 1.5rem;">
        <h3 style="color: #fff; font-size: 1.1rem; font-weight: 700; margin-bottom: 1rem;">Gestión de Justificación</h3>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem;">
            <div>
                <div style="font-size: 0.85rem; color: #94a3b8; margin-bottom: 0.5rem;">Explicación remitida por el colaborador:</div>
                <div style="background: #1a1c26; border: 1px solid var(--border); border-radius: 8px; padding: 1rem; color: #cbd5e1; font-size: 0.9rem; line-height: 1.5; white-space: pre-wrap;">{{ $attendance->notes ?: 'Sin comentarios adjuntos.' }}</div>

                @if($attendance->justification_file)
                    <div style="margin-top: 1rem;">
                        <a href="{{ asset('storage/' . $attendance->justification_file) }}" target="_blank" style="color: #818cf8; font-size: 0.85rem; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
                            📎 Ver comprobante / documento adjunto
                        </a>
                    </div>
                @endif
            </div>

            <div>
                <div style="font-size: 0.85rem; color: #94a3b8; margin-bottom: 0.5rem;">Resolución de RRHH / Supervisor:</div>
                @if($attendance->justification_status === 'approved' || $attendance->justification_status === 'rejected')
                    <div style="background: rgba(255,255,255,0.03); border: 1px solid var(--border); border-radius: 8px; padding: 1rem;">
                        <div style="font-weight: 700; font-size: 0.95rem; color: {{ $attendance->justification_status === 'approved' ? '#10b981' : '#ef4444' }};">
                            Justificación {{ $attendance->justification_status === 'approved' ? 'Aprobada' : 'Rechazada' }}
                        </div>
                        <div style="font-size: 0.8rem; color: #94a3b8; margin-top: 4px;">
                            Revisado por: {{ $attendance->reviewer ? $attendance->reviewer->first_name . ' ' . $attendance->reviewer->last_name : 'Administrador' }}
                            el {{ $attendance->reviewed_at ? $attendance->reviewed_at->format('d/m/Y H:i') : '' }}
                        </div>
                    </div>
                @else
                    <form method="POST" action="{{ route('attendance.review', $attendance->id) }}">
                        @csrf
                        <div style="margin-bottom: 1rem;">
                            <label style="display: block; font-size: 0.8rem; color: #94a3b8; margin-bottom: 0.4rem;">Notas de resolución:</label>
                            <textarea name="review_notes" rows="2" class="form-control" style="width: 100%; background: #1a1c26; border: 1px solid var(--border); color: #fff; padding: 0.6rem; border-radius: 8px; font-size: 0.85rem;" placeholder="Motivo de aprobación o rechazo..."></textarea>
                        </div>
                        <div style="display: flex; gap: 10px;">
                            <button type="submit" name="justification_status" value="approved" style="flex: 1; padding: 0.6rem; background: #10b981; border: none; border-radius: 8px; color: #fff; font-weight: 600; cursor: pointer;">
                                ✓ Aprobar Justificación
                            </button>
                            <button type="submit" name="justification_status" value="rejected" style="flex: 1; padding: 0.6rem; background: #ef4444; border: none; border-radius: 8px; color: #fff; font-weight: 600; cursor: pointer;">
                                ✕ Rechazar
                            </button>
                        </div>
                    </form>
                @endif
            </div>
        </div>
    </div>
@endif
@endsection

@section('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const branchLat = {{ (float) ($attendance->branch->latitude ?? -12.046374) }};
        const branchLng = {{ (float) ($attendance->branch->longitude ?? -77.042793) }};
        const radius    = {{ (int) ($attendance->branch->allowed_radius_meters ?? 50) }};

        const empLat = {{ (float) ($attendance->clock_in_latitude ?? 0) }};
        const empLng = {{ (float) ($attendance->clock_in_longitude ?? 0) }};
        const withinGeo = {{ $attendance->clock_in_within_geofence ? 'true' : 'false' }};

        // Centrar mapa
        const centerLat = empLat !== 0 ? (branchLat + empLat) / 2 : branchLat;
        const centerLng = empLng !== 0 ? (branchLng + empLng) / 2 : branchLng;

        const auditMap = L.map('auditMap').setView([centerLat, centerLng], 17);

        L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
            maxZoom: 19
        }).addTo(auditMap);

        // Geocerca de la sucursal (círculo)
        L.circle([branchLat, branchLng], {
            color: '#6366f1',
            fillColor: '#6366f1',
            fillOpacity: 0.2,
            weight: 2,
            radius: radius
        }).addTo(auditMap).bindPopup(`<b>Sucursal:</b> {{ $attendance->branch->name ?? '' }}<br>Radio: ${radius}m`);

        // Marcador del centro de sucursal
        L.marker([branchLat, branchLng]).addTo(auditMap).bindPopup("Centro Oficial de la Sucursal");

        // Si hay coordenadas del empleado
        if (empLat !== 0 && empLng !== 0) {
            const empIcon = L.divIcon({
                className: 'custom-emp-pin',
                html: `<div style="width: 24px; height: 24px; border-radius: 50%; background: ${withinGeo ? '#10b981' : '#ef4444'}; border: 3px solid #fff; box-shadow: 0 0 12px ${withinGeo ? '#10b981' : '#ef4444'};"></div>`,
                iconSize: [24, 24],
                iconAnchor: [12, 12]
            });

            L.marker([empLat, empLng], { icon: empIcon })
                .addTo(auditMap)
                .bindPopup(`<b>Punto de Marcación:</b><br>{{ $attendance->clock_in_distance_meters }}m de distancia.<br>${withinGeo ? 'Dentro de geocerca' : 'Fuera de geocerca'}`)
                .openPopup();

            // Línea conectora
            L.polyline([
                [branchLat, branchLng],
                [empLat, empLng]
            ], {
                color: withinGeo ? '#10b981' : '#ef4444',
                weight: 2,
                dashArray: '5, 10'
            }).addTo(auditMap);
        }
    });
</script>
@endsection
