@extends('layouts.app')

@section('title', 'Marcación #' . $attendance->id)

@section('styles')
<style>
{{-- Botón Volver --}}
.btn-action-back {
    width: 38px;
    height: 38px;
    border-radius: 8px;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background-color: #ffffff;
    border: 1px solid #cbd5e1;
    color: #475569;
    text-decoration: none;
    transition: all 0.15s ease-in-out;
}
.btn-action-back:hover {
    background-color: #f1f5f9;
    border-color: #94a3b8;
    color: #0f172a;
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.06);
}
.btn-action-back:active {
    background-color: #e2e8f0;
    transform: translateY(0);
}

{{-- Botón Ver Selfie y Mapa --}}
.btn-action-evidence {
    height: 38px;
    border-radius: 8px;
    padding: 0 1.25rem;
    font-size: 0.82rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    gap: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background-color: var(--brand, #3C729E);
    border: 1px solid transparent;
    color: #ffffff;
    transition: all 0.15s ease-in-out;
}
.btn-action-evidence:hover {
    background-color: #2b5577;
    color: #ffffff;
    transform: translateY(-1px);
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.12), 0 2px 4px -2px rgba(0, 0, 0, 0.08);
}
.btn-action-evidence:active {
    transform: translateY(0);
}

{{-- Botón Rechazar --}}
.btn-action-reject {
    height: 38px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 0.85rem;
    padding: 0 1.25rem;
    gap: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background-color: #ffffff;
    border: 1.5px solid #ef4444;
    color: #dc2626;
    transition: all 0.15s ease-in-out;
    box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
    cursor: pointer;
}
.btn-action-reject i,
.btn-action-reject span {
    color: inherit;
    transition: color 0.15s ease-in-out;
}
.btn-action-reject:hover,
.btn-action-reject:focus,
.btn-action-reject:focus-visible {
    background-color: #dc2626 !important;
    border-color: #dc2626 !important;
    color: #ffffff !important;
    transform: translateY(-1px);
    box-shadow: 0 4px 8px -1px rgba(220, 38, 38, 0.35);
    outline: none;
}
.btn-action-reject:hover i,
.btn-action-reject:hover span,
.btn-action-reject:focus i,
.btn-action-reject:focus span,
.btn-action-reject:focus-visible i,
.btn-action-reject:focus-visible span {
    color: #ffffff !important;
}
.btn-action-reject:active {
    background-color: #b91c1c !important;
    border-color: #b91c1c !important;
    color: #ffffff !important;
    transform: translateY(0);
}

{{-- Botón Aprobar --}}
.btn-action-approve {
    height: 38px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 0.85rem;
    padding: 0 1.35rem;
    gap: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background-color: #10b981;
    border: 1.5px solid #059669;
    color: #ffffff;
    transition: all 0.15s ease-in-out;
    box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
    cursor: pointer;
}
.btn-action-approve i,
.btn-action-approve span {
    color: #ffffff;
}
.btn-action-approve:hover,
.btn-action-approve:focus,
.btn-action-approve:focus-visible {
    background-color: #059669 !important;
    border-color: #047857 !important;
    color: #ffffff !important;
    transform: translateY(-1px);
    box-shadow: 0 4px 8px -1px rgba(16, 185, 129, 0.35);
    outline: none;
}
.btn-action-approve:hover i,
.btn-action-approve:hover span,
.btn-action-approve:focus i,
.btn-action-approve:focus span,
.btn-action-approve:focus-visible i,
.btn-action-approve:focus-visible span {
    color: #ffffff !important;
}
.btn-action-approve:active {
    background-color: #047857 !important;
    border-color: #065f46 !important;
    color: #ffffff !important;
    transform: translateY(0);
}

{{-- Adaptación a modo oscuro --}}
[data-bs-theme="dark"] .btn-action-back {
    background-color: #1e293b;
    border-color: #334155;
    color: #cbd5e1;
}
[data-bs-theme="dark"] .btn-action-back:hover {
    background-color: #334155;
    border-color: #475569;
    color: #ffffff;
}
[data-bs-theme="dark"] .btn-action-reject {
    background-color: #1e293b;
    border-color: #ef4444;
    color: #fca5a5;
}
[data-bs-theme="dark"] .btn-action-reject:hover {
    background-color: #dc2626 !important;
    border-color: #dc2626 !important;
    color: #ffffff !important;
}
</style>
@endsection

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

{{-- Navegación y encabezado --}}
<nav aria-label="breadcrumb">
    <ol class="breadcrumb small mb-2">
        <li class="breadcrumb-item"><a href="{{ route('attendance.index') }}" class="text-decoration-none">Marcaciones</a></li>
        <li class="breadcrumb-item active text-secondary" aria-current="page">Auditoría #{{ $attendance->id }}</li>
    </ol>
</nav>

<div class="attendance-header-bar">
    <div>
        <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
            <h1 class="h2 mb-0 fw-bold text-dark">{{ $attendance->user->first_name }} {{ $attendance->user->last_name }}</h1>
            @include('partials.attendance-status', ['status' => $attendance->status])
            @if($attendance->is_suspicious)
                <span class="badge rounded-2 bg-warning-subtle text-warning-emphasis border border-warning-subtle" style="font-size: 0.775rem;">
                    <i class="bi bi-shield-exclamation me-1"></i>Sospechosa
                </span>
            @endif
        </div>
        <p class="text-secondary mb-0" style="font-size: 0.875rem;">
            <span>{{ $attendance->work_date?->format('d/m/Y') ?? '--' }}</span> · <span>{{ $branch->name ?? 'Sin sucursal' }}</span>
            @if($attendance->shift)
                · <span>Turno: {{ $attendance->shift->name }}</span>
            @endif
        </p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <a href="{{ route('attendance.index') }}" class="btn-action-back shadow-sm" title="Volver a marcaciones">
            <i class="bi bi-arrow-left fs-5"></i>
        </a>
        <button type="button" class="btn-action-evidence shadow-sm" onclick="openEvidence({{ $attendance->id }})">
            <i class="bi bi-geo-alt"></i>
            <span>VER SELFIE Y MAPA</span>
        </button>
    </div>
</div>

@php $altered = array_filter($integrity ?? [], fn ($state) => $state && $state !== 'ok'); @endphp
@if($altered)
    <div class="alert alert-danger d-flex gap-2 mb-4" style="border-radius: 10px;" role="alert">
        <i class="bi bi-shield-exclamation fs-5"></i>
        <div>
            <strong>Evidencia alterada.</strong>
            {{ count($altered) === 1 ? 'Una selfie de esta jornada' : count($altered) . ' selfies de esta jornada' }}
            ya no coincide con la huella SHA-256 que se guardó al recibirla (el archivo cambió o falta). Quedó registrado en la auditoría.
        </div>
    </div>
@endif

{{-- Tarjetas de Entrada y Salida --}}
<div class="row g-3 mb-4">
    @foreach($moments as $key => $m)
        <div class="col-12 col-lg-6">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; border: 1px solid #e2e8f0 !important;">
                <div class="card-header bg-white border-0 pt-3 pb-2 px-4 d-flex justify-content-between align-items-center" style="border-bottom: 1px solid #f1f5f9 !important;">
                    <h2 class="h6 fw-bold mb-0 text-dark d-flex align-items-center gap-2">
                        <i class="bi bi-{{ $m['icon'] }} text-primary"></i>
                        <span>{{ $m['title'] }}</span>
                        @if($m['offline'])
                            <span class="badge rounded-2 bg-secondary-subtle text-secondary-emphasis ms-1 fw-normal" style="font-size: 0.7rem;" title="Sincronizada {{ $m['synced'] ? \Illuminate\Support\Carbon::parse($m['synced'])->setTimezone(tenant_timezone())->format('d/m/Y h:i a') : '' }}"><i class="bi bi-wifi-off"></i> Sin conexión</span>
                        @endif
                    </h2>
                    @if($m['at'])
                        @if($m['within'])
                            <span class="live-location-badge in-range">
                                <span class="badge-dot" style="width: 6px; height: 6px; border-radius: 50%; background: #10b981; display: inline-block;"></span>
                                En ubicación ({{ $m['distance'] ?? 0 }} m)
                            </span>
                        @else
                            <span class="live-location-badge out-range">
                                <span class="badge-dot" style="width: 6px; height: 6px; border-radius: 50%; background: #ef4444; display: inline-block;"></span>
                                Fuera ({{ $m['distance'] ?? 0 }} m)
                            </span>
                        @endif
                    @else
                        <span class="badge rounded-2 bg-light text-secondary border" style="font-size: 0.75rem;">Pendiente</span>
                    @endif
                </div>
                <div class="card-body p-4">
                    <div class="row g-3">
                        <div class="col-5 col-sm-4">
                            @if($m['photo'])
                                @php $url = attendance_photo_url($attendance, $key); @endphp
                                <button type="button" class="btn p-0 border-0 w-100 text-start" data-photo="{{ $url }}"
                                        data-photo-title="Selfie de {{ strtolower($m['title']) }}"
                                        data-photo-meta="Hora: {{ $m['at']?->format('h:i:s a') }} · Distancia: {{ $m['distance'] }} m">
                                    <img src="{{ $url }}" alt="Selfie de {{ strtolower($m['title']) }}" class="img-fluid rounded-3 border border-2 {{ $m['within'] ? 'border-success' : 'border-danger' }}" style="aspect-ratio: 3/4; object-fit: cover;">
                                    <span class="d-block small text-secondary mt-1"><i class="bi bi-zoom-in"></i> Ampliar</span>
                                </button>
                            @else
                                <div style="height: 150px; border-radius: 10px; background: #f8fafc; border: 1px dashed #cbd5e1;" class="d-flex flex-column align-items-center justify-content-center text-secondary p-3 text-center">
                                    <i class="bi bi-camera-video-off fs-4 mb-1 text-muted"></i>
                                    <span class="small fw-medium">{{ $m['at'] ? 'Sin selfie' : 'Aún no marca' }}</span>
                                </div>
                            @endif
                        </div>
                        <div class="col-7 col-sm-8">
                            <dl class="kv small mb-0">
                                <dt class="text-secondary fw-semibold">Hora exacta</dt>
                                <dd class="fw-bold text-dark">{{ $m['at']?->format('h:i:s a') ?? '--:--:--' }}</dd>
                                
                                <dt class="text-secondary fw-semibold">Ubicación / Distancia</dt>
                                @if($m['distance'] !== null)
                                    <dd class="{{ $m['within'] ? 'text-success fw-bold' : 'text-danger fw-bold' }}">
                                        {{ $m['distance'] }} m <span class="text-secondary fw-normal">de {{ $branch->allowed_radius_meters ?? 50 }} m permitidos</span>
                                    </dd>
                                @else
                                    <dd class="text-secondary">—</dd>
                                @endif
                                
                                <dt class="text-secondary fw-semibold">Coordenadas GPS</dt>
                                <dd class="font-monospace small text-dark">{{ $m['lat'] !== null ? $m['lat'] . ', ' . $m['lng'] : '—' }}</dd>
                                
                                @if($key === 'in')
                                    <dt class="text-secondary fw-semibold">Tardanza</dt>
                                    <dd class="{{ $attendance->late_minutes > 0 ? 'text-warning fw-bold' : 'text-dark' }}">
                                        {{ $attendance->late_minutes ?? 0 }} min
                                    </dd>
                                    
                                    <dt class="text-secondary fw-semibold">Dispositivo</dt>
                                    <dd class="font-monospace small text-dark text-break">{{ $attendance->clock_in_device_id ?? '—' }}</dd>
                                @else
                                    <dt class="text-secondary fw-semibold">Horas trabajadas</dt>
                                    <dd class="fw-bold text-dark">{{ number_format((float) ($attendance->worked_hours ?? 0), 2) }} h</dd>
                                    
                                    <dt class="text-secondary fw-semibold">Horas extra</dt>
                                    <dd class="text-dark">{{ number_format((float) ($attendance->overtime_hours ?? 0), 2) }} h
                                        @if($attendance->overtime_status)
                                            <x-status-badge type="overtime_status" :value="$attendance->overtime_status" />
                                            @if($attendance->overtime_status === 'approved' && (float) $attendance->overtime_approved_hours < (float) $attendance->overtime_hours)
                                                <span class="small text-secondary">({{ number_format((float) $attendance->overtime_approved_hours, 2) }} h aprobadas)</span>
                                            @endif
                                        @endif
                                    </dd>
                                    
                                    <dt class="text-secondary fw-semibold">Descanso</dt>
                                    <dd class="text-dark">{{ $attendance->break_minutes ?? 0 }} min</dd>
                                @endif
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endforeach
</div>

{{-- Bitácora de marcaciones --}}
<div class="card border-0 shadow-sm mb-4" style="border-radius: 12px; border: 1px solid #e2e8f0 !important; overflow: hidden;">
    <div class="card-header bg-white border-0 pt-3 pb-2 px-4" style="border-bottom: 1px solid #f1f5f9 !important;">
        <h2 class="h6 fw-bold mb-0 text-dark">Bitácora inmutable de marcaciones</h2>
        <small class="text-secondary">Registro técnico de cada evento tal como llegó del dispositivo: seguro e inalterable.</small>
    </div>
    @if($attendance->punches->isEmpty())
        <div class="card-body p-4 text-center text-secondary small">
            Esta marcación es anterior a la bitácora: sus datos están en las tarjetas de arriba.
        </div>
    @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead style="background: #f8fafc; border-bottom: 1px solid #e2e8f0;">
                    <tr>
                        <th class="ps-4 py-3 text-secondary text-uppercase fw-semibold" style="font-size: 0.725rem;">Evento</th>
                        <th class="py-3 text-secondary text-uppercase fw-semibold" style="font-size: 0.725rem;">Hora del servidor</th>
                        <th class="py-3 text-secondary text-uppercase fw-semibold" style="font-size: 0.725rem;">Hora del celular</th>
                        <th class="py-3 text-secondary text-uppercase fw-semibold text-end" style="font-size: 0.725rem;">Precisión</th>
                        <th class="py-3 text-secondary text-uppercase fw-semibold text-end" style="font-size: 0.725rem;">Distancia</th>
                        <th class="py-3 text-secondary text-uppercase fw-semibold" style="font-size: 0.725rem;">Dirección</th>
                        <th class="py-3 text-secondary text-uppercase fw-semibold" style="font-size: 0.725rem;">Verificación</th>
                        <th class="pe-4 py-3 text-secondary text-uppercase fw-semibold text-end" style="font-size: 0.725rem;"><span class="visually-hidden">Foto</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($attendance->punches as $punch)
                        @php $tz = tenant_timezone(); @endphp
                        <tr class="{{ $punch->suspicious ? 'table-warning' : '' }}" style="border-bottom: 1px solid #f1f5f9;">
                            <td class="ps-4 text-nowrap"><x-status-badge type="punch_type" :value="$punch->type" /></td>
                            <td class="text-nowrap">{{ $punch->server_time?->copy()->setTimezone($tz)->format('d/m/Y h:i:s a') ?? '—' }}</td>
                            <td class="text-nowrap">
                                {{ $punch->device_time?->copy()->setTimezone($tz)->format('h:i:s a') ?? '—' }}
                                @if($punch->time_drift_seconds)
                                    <span class="text-secondary small">({{ $punch->time_drift_seconds > 0 ? '+' : '−' }}{{ abs($punch->time_drift_seconds) }} s)</span>
                                @endif
                                @if($punch->offline)
                                    <span class="badge rounded-2 bg-secondary-subtle text-secondary-emphasis fw-normal" style="font-size: 0.7rem;"><i class="bi bi-wifi-off"></i> Sin conexión</span>
                                @endif
                            </td>
                            <td class="text-end text-nowrap">{{ $punch->accuracy_meters !== null ? '± ' . round($punch->accuracy_meters) . ' m' : '—' }}</td>
                            <td class="text-end text-nowrap {{ $punch->within_geofence === false ? 'text-danger fw-bold' : '' }}">{{ $punch->distance_meters !== null ? $punch->distance_meters . ' m' : '—' }}</td>
                            <td class="text-break" style="min-width: 12rem;">{{ $punch->address ?? ($punch->latitude !== null ? number_format($punch->latitude, 6) . ', ' . number_format($punch->longitude, 6) : '—') }}</td>
                            <td>
                                <div class="d-flex flex-wrap gap-1">
                                    @if($punch->face_detected === true)
                                        <span class="badge rounded-2 bg-success-subtle text-success-emphasis fw-normal" style="font-size: 0.7rem;"><i class="bi bi-person-check"></i> Rostro</span>
                                    @endif
                                    @forelse($punch->suspicion_reasons ?? [] as $reason)
                                        <x-status-badge type="suspicion_reason" :value="$reason" />
                                    @empty
                                        <span class="badge rounded-2 bg-success-subtle text-success-emphasis fw-normal" style="font-size: 0.7rem;">Sin observaciones</span>
                                    @endforelse
                                </div>
                            </td>
                            <td class="pe-4 text-end">
                                @if($url = $punch->photoUrl())
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-photo="{{ $url }}"
                                            data-photo-title="Foto: {{ label('punch_type', $punch->type) }}"
                                            data-photo-meta="{{ $punch->server_time?->copy()->setTimezone($tz)->format('d/m/Y h:i:s a') }}{{ $punch->photo_sha256 ? ' · SHA-256 ' . substr($punch->photo_sha256, 0, 12) . '…' : '' }}">
                                        <i class="bi bi-image"></i><span class="visually-hidden">Ver foto</span>
                                    </button>
                                @elseif($punch->photo_purged_at)
                                    <span class="small text-secondary" title="Borrada por retención el {{ $punch->photo_purged_at->copy()->setTimezone($tz)->format('d/m/Y') }}"><i class="bi bi-image-alt"></i> Foto borrada</span>
                                @endif
                                @if(($integrity[$punch->id] ?? null) === 'altered')
                                    <div class="small text-danger fw-semibold mt-1"><i class="bi bi-shield-exclamation"></i> Alterada</div>
                                @elseif(($integrity[$punch->id] ?? null) === 'missing')
                                    <div class="small text-danger fw-semibold mt-1"><i class="bi bi-shield-exclamation"></i> Falta el archivo</div>
                                @endif
                                @if($punch->photo_sha256)
                                    <div class="small text-secondary font-monospace mt-1" title="Huella SHA-256 de la foto guardada: {{ $punch->photo_sha256 }}">{{ substr($punch->photo_sha256, 0, 12) }}…</div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

{{-- Validación geográfica --}}
<div class="card border-0 shadow-sm mb-4" style="border-radius: 12px; border: 1px solid #e2e8f0 !important; overflow: hidden;">
    <div class="card-header bg-white border-0 pt-3 pb-2 px-4" style="border-bottom: 1px solid #f1f5f9 !important;">
        <h2 class="h6 fw-bold mb-0 text-dark">Validación geográfica</h2>
        <small class="text-secondary">Círculo: radio de ubicación permitido para {{ $branch->name ?? 'la sucursal' }} ({{ $branch->allowed_radius_meters ?? 50 }} m). Punto: ubicación GPS registrada al marcar la entrada.</small>
    </div>
    <div class="card-body p-3">
        <div id="auditMap" class="map-frame rounded-3" style="height: 380px; border: 1px solid #cbd5e1;"></div>
    </div>
</div>

@if($attendance->notes || $attendance->justification_status)
    <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px; border: 1px solid #e2e8f0 !important; overflow: hidden;">
        <div class="card-header bg-white border-0 pt-3 pb-2 px-4" style="border-bottom: 1px solid #f1f5f9 !important;">
            <h2 class="h6 fw-bold mb-0 text-dark d-flex align-items-center gap-2">
                <i class="bi bi-file-earmark-text text-primary"></i>
                <span>Gestión de Justificación</span>
            </h2>
            <small class="text-secondary">Revisión y dictamen de la justificación enviada por el colaborador.</small>
        </div>
        <div class="card-body p-4">
            <div class="row g-4 align-items-start">
                {{-- Columna izquierda: Explicación del colaborador --}}
                <div class="col-12 col-lg-6 d-flex flex-column">
                    <label class="form-label small fw-bold text-dark text-uppercase mb-2" style="letter-spacing: 0.04em;">
                        <i class="bi bi-chat-left-quote text-secondary me-1"></i> Explicación del colaborador
                    </label>
                    <div class="rounded-3 border d-flex flex-column justify-content-center" style="height: 100px; border-color: #cbd5e1 !important; background-color: #f8fafc !important; overflow: hidden;">
                        @if($attendance->notes)
                            <div class="p-3" style="white-space: pre-wrap; font-size: 0.875rem; color: #1e293b; line-height: 1.5; overflow-y: auto; height: 100%;">
                                {{ $attendance->notes }}
                            </div>
                        @else
                            <div class="d-flex align-items-center justify-content-center text-center text-secondary h-100 p-3" style="font-size: 0.875rem;">
                                Sin comentarios registrados por el colaborador.
                            </div>
                        @endif
                    </div>
                    @if($attendance->justification_file)
                        <div class="mt-2">
                            <a href="{{ route('attendance.justification-file', $attendance->id) }}" target="_blank" rel="noopener" class="btn btn-sm btn-white border d-inline-flex align-items-center shadow-sm" style="border-radius: 6px; font-weight: 500; font-size: 0.775rem; gap: 6px;">
                                <i class="bi bi-paperclip text-primary"></i>
                                <span>Ver comprobante adjunto</span>
                            </a>
                        </div>
                    @endif
                </div>

                {{-- Columna derecha: Resolución o Formulario de dictamen --}}
                <div class="col-12 col-lg-6 d-flex flex-column">
                    @if(in_array($attendance->justification_status, ['approved', 'rejected'], true))
                        <label class="form-label small fw-bold text-dark text-uppercase mb-2" style="letter-spacing: 0.04em;">
                            <i class="bi bi-shield-check text-secondary me-1"></i> Resolución emitida
                        </label>
                        <div class="alert {{ $attendance->justification_status === 'approved' ? 'alert-success border-success-subtle' : 'alert-danger border-danger-subtle' }} mb-0 d-flex flex-column justify-content-center p-3" style="border-radius: 8px; height: 100px;">
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <i class="bi bi-{{ $attendance->justification_status === 'approved' ? 'check-circle-fill fs-5' : 'x-circle-fill fs-5' }}"></i>
                                <span class="fw-bold fs-6">Justificación {{ $attendance->justification_status === 'approved' ? 'Aprobada' : 'Rechazada' }}</span>
                            </div>
                            <div class="small">
                                Por <span class="fw-semibold">{{ $attendance->reviewer ? $attendance->reviewer->first_name . ' ' . $attendance->reviewer->last_name : 'Administrador' }}</span>
                                el {{ $attendance->reviewed_at?->format('d/m/Y h:i a') }}
                            </div>
                            @if($attendance->review_notes)
                                <div class="mt-1 pt-1 border-top small" style="white-space: pre-wrap; opacity: 0.95; overflow-y: auto;">
                                    <strong>Motivo:</strong> {{ $attendance->review_notes }}
                                </div>
                            @endif
                        </div>
                    @else
                        <form method="POST" action="{{ route('attendance.review', $attendance->id) }}" class="d-flex flex-column m-0">
                            @csrf
                            <label for="review_notes" class="form-label small fw-bold text-dark text-uppercase mb-2" style="letter-spacing: 0.04em;">
                                <i class="bi bi-pencil-square text-secondary me-1"></i> Dictamen y notas de resolución
                            </label>
                            <textarea id="review_notes" name="review_notes" rows="3" class="form-control mb-3" style="height: 100px; border-radius: 8px; border-color: #cbd5e1; font-size: 0.875rem; resize: none;" placeholder="Escribe el motivo o notas de la aprobación o el rechazo..."></textarea>
                            <div class="d-flex justify-content-end align-items-center gap-2">
                                <button type="submit" name="justification_status" value="rejected" class="btn-action-reject">
                                    <i class="bi bi-x-circle fs-6"></i>
                                    <span>Rechazar</span>
                                </button>
                                <button type="submit" name="justification_status" value="approved" class="btn-action-approve">
                                    <i class="bi bi-check-circle fs-6"></i>
                                    <span>Aprobar</span>
                                </button>
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
        $mapBranch = $branch ? ['lat' => (float) $branch->latitude, 'lng' => (float) $branch->longitude, 'radius' => (int) $branch->allowed_radius_meters, 'name' => $branch->name] : null;
        $mapPoint  = $attendance->clock_in_latitude !== null ? ['lat' => (float) $attendance->clock_in_latitude, 'lng' => (float) $attendance->clock_in_longitude] : null;
    @endphp
    const branch = @json($mapBranch);
    const point = @json($mapPoint);
    const within = @json((bool) $attendance->clock_in_within_geofence);
    const distance = @json($attendance->clock_in_distance_meters);
    const brand = Panel.cssVar('--brand') || '#3C729E';

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
            .bindPopup(`<strong>Punto de marcación</strong><br>${Panel.esc(distance ?? '?')} m del centro<br>${within ? 'Dentro' : 'Fuera'} de la ubicación permitida`)
            .addTo(layer).openPopup();
        if (branch) L.polyline([[branch.lat, branch.lng], [point.lat, point.lng]], { color, weight: 2, dashArray: '5 8' }).addTo(layer);
    }

    const bounds = layer.getBounds();
    if (bounds.isValid()) map.fitBounds(bounds.pad(.4), { maxZoom: 18 });
    else map.setView([-12.046374, -77.042793], 12);
});
</script>
@endsection
