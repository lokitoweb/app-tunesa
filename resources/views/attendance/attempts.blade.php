@extends('layouts.app')

@section('title', 'Intentos rechazados')

@section('content')
<div class="page-header">
    <div>
        <h1>Intentos rechazados</h1>
        <p>Marcaciones que la app no aceptó: fuera de la geocerca, ubicación simulada, GPS impreciso y más.</p>
    </div>
</div>

{{-- Resumen por motivo: cada tarjeta filtra la tabla --}}
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <a href="{{ request()->fullUrlWithQuery(['code' => null, 'page' => null]) }}" class="card border-0 h-100 kpi-card text-decoration-none">
            <div class="card-body d-flex align-items-center gap-3">
                <span class="kpi-icon bg-primary-subtle text-primary-emphasis"><i class="bi bi-slash-circle"></i></span>
                <div>
                    <div class="kpi-label">Total en el rango</div>
                    <div class="fs-4 fw-bold text-body">{{ $total }}</div>
                </div>
            </div>
        </a>
    </div>
    @foreach($byCode->sortDesc()->take(3) as $code => $count)
        @php $tone = \App\Modules\Core\Support\Labels::tone('punch_attempt_code', $code); @endphp
        <div class="col-6 col-lg-3">
            <a href="{{ request()->fullUrlWithQuery(['code' => $code, 'page' => null]) }}" class="card border-0 h-100 kpi-card text-decoration-none">
                <div class="card-body d-flex align-items-center gap-3">
                    <span class="kpi-icon bg-{{ $tone }}-subtle text-{{ $tone }}-emphasis"><i class="bi bi-geo-alt"></i></span>
                    <div>
                        <div class="kpi-label">{{ label('punch_attempt_code', $code) }}</div>
                        <div class="fs-4 fw-bold text-body">{{ $count }}</div>
                    </div>
                </div>
            </a>
        </div>
    @endforeach
</div>

<div class="card border-0 mb-4">
    <div class="card-body">
        <form method="GET" action="{{ route('attendance.attempts') }}" class="row g-3 align-items-end">
            <div class="col-6 col-md-3 col-xl-2">
                <label for="start_date" class="form-label small">Desde</label>
                <input type="date" id="start_date" name="start_date" value="{{ $startDate }}" class="form-control">
            </div>
            <div class="col-6 col-md-3 col-xl-2">
                <label for="end_date" class="form-label small">Hasta</label>
                <input type="date" id="end_date" name="end_date" value="{{ $endDate }}" class="form-control">
            </div>
            <div class="col-12 col-md-6 col-xl-2">
                <label for="branch_id" class="form-label small">Sucursal</label>
                <select id="branch_id" name="branch_id" class="form-select">
                    <option value="">Todas</option>
                    @foreach($branches as $b)
                        <option value="{{ $b->id }}" @selected(request('branch_id') == $b->id)>{{ $b->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <label for="code" class="form-label small">Motivo</label>
                <select id="code" name="code" class="form-select">
                    <option value="">Todos</option>
                    @foreach($codes as $value => $text)
                        <option value="{{ $value }}" @selected(request('code') === $value)>{{ $text }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-5 col-xl-3">
                <label for="search" class="form-label small">Colaborador</label>
                <input type="search" id="search" name="search" value="{{ request('search') }}" placeholder="Nombre, código o DNI" class="form-control">
            </div>
            <div class="col-12 col-md-3 col-xl-1 d-grid">
                <button type="submit" class="btn btn-primary" aria-label="Filtrar"><i class="bi bi-funnel"></i></button>
            </div>
        </form>
    </div>
</div>

@if($mapPoints->isNotEmpty())
<div class="card border-0 mb-4">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h2 class="h6 mb-0">Dónde intentaron marcar</h2>
            <span class="small text-body-secondary">Intentos de esta página y radio de cada sede (línea punteada: tolerancia del borde)</span>
        </div>
        <div id="attemptsMap" style="height: 320px" class="rounded"></div>
    </div>
</div>
@endif

<div class="card border-0">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Colaborador</th>
                    <th>Fecha y hora</th>
                    <th>Marcación</th>
                    <th>Motivo</th>
                    <th>Ubicación</th>
                    <th>Teléfono</th>
                </tr>
            </thead>
            <tbody>
                @forelse($attempts as $a)
                    <tr>
                        <td>
                            <div class="fw-semibold text-truncate">{{ $a->user->first_name ?? 'N/A' }} {{ $a->user->last_name ?? '' }}</div>
                            <div class="small text-body-secondary text-truncate">{{ $a->user->employee_code ?? '—' }} · {{ $a->branch->name ?? 'Sin sucursal' }}</div>
                            @include('partials.tenant-badge', ['tenantId' => $a->tenant_id])
                        </td>
                        <td class="text-nowrap">
                            <div class="fw-semibold">{{ $a->created_at->format('d/m/Y h:i:s a') }}</div>
                            @if($a->fromApp())
                                <span class="badge rounded-pill bg-info-subtle text-info-emphasis" title="La app bloqueó la marcación antes de enviarla"><i class="bi bi-phone"></i> Desde la app</span>
                            @endif
                            @if($a->offline)
                                <span class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis" title="Hecha sin conexión el {{ $a->offline_recorded_at?->timezone(config('app.timezone'))->format('d/m/Y h:i a') }}"><i class="bi bi-wifi-off"></i> Sin conexión</span>
                            @endif
                        </td>
                        <td><x-status-badge type="punch_type" :value="$a->type" /></td>
                        <td>
                            <x-status-badge type="punch_attempt_code" :value="$a->error_code" />
                            <div class="small text-body-secondary mt-1">{{ $a->message }}</div>
                        </td>
                        <td class="text-nowrap small">
                            @if($a->distance_meters !== null)
                                <div class="text-danger-emphasis fw-semibold">{{ $a->distance_meters }} m de {{ $a->allowed_radius ?? '—' }} m permitidos</div>
                            @endif
                            @if($a->accuracy_meters !== null)
                                <div class="text-body-secondary">Precisión {{ rtrim(rtrim(number_format($a->accuracy_meters, 1, '.', ''), '0'), '.') }} m</div>
                            @endif
                            @if($a->mapUrl())
                                <a href="{{ $a->mapUrl() }}" target="_blank" rel="noopener" class="link-primary"><i class="bi bi-map me-1"></i>Ver en el mapa</a>
                            @endif
                            @if($a->mock_location_detected)
                                <div><span class="badge rounded-pill bg-danger-subtle text-danger-emphasis"><i class="bi bi-phone-vibrate"></i> GPS simulado</span></div>
                            @endif
                        </td>
                        <td class="small">
                            <div class="text-truncate">{{ $a->device_os ?? '—' }}</div>
                            <div class="text-body-secondary">App {{ $a->app_version ?? '—' }}</div>
                            @foreach($a->device_flags ?? [] as $flag)
                                <x-status-badge type="device_flag" :value="$flag" />
                            @endforeach
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6"><div class="empty-state"><i class="bi bi-shield-check"></i>No hubo intentos rechazados en este rango.</div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($attempts->hasPages())
        <div class="card-footer bg-transparent">{{ $attempts->links() }}</div>
    @endif
</div>
@endsection

@section('scripts')
@if($mapPoints->isNotEmpty())
<script>
document.addEventListener('DOMContentLoaded', () => {
    const BRANCHES = @json($mapBranches);
    const POINTS = @json($mapPoints);
    const brand = Panel.cssVar('--brand') || '#1E40AF';
    const map = L.map('attemptsMap', { scrollWheelZoom: false });
    Panel.tiles(map);
    const bounds = [];
    BRANCHES.forEach((b) => {
        L.circle([b.lat, b.lng], { radius: b.radius || 50, color: brand, fillColor: brand, fillOpacity: .1, weight: 2 })
            .addTo(map).bindPopup(`<strong>${Panel.esc(b.name)}</strong><br>Radio ${b.radius} m`);
        if (b.tolerance > 0) {
            L.circle([b.lat, b.lng], { radius: b.radius + b.tolerance, color: brand, weight: 1, dashArray: '4 4', fill: false, interactive: false }).addTo(map);
        }
    });
    POINTS.forEach((p) => {
        L.circleMarker([p.lat, p.lng], { radius: 7, color: p.mock ? '#b91c1c' : '#d97706', fillOpacity: .8, weight: 2 })
            .addTo(map).bindPopup(`<strong>${Panel.esc(p.name)}</strong><br>${Panel.esc(p.code)}<br>${Panel.esc(p.at)}`);
        bounds.push([p.lat, p.lng]);
    });
    map.fitBounds(bounds, { padding: [30, 30], maxZoom: 16 });
});
</script>
@endif
@endsection
