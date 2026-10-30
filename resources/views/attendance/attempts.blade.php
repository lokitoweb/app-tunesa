@extends('layouts.app')

@section('title', 'Intentos rechazados')

@section('content')
<header class="page-head">
    <div class="page-head-text">
        <h1 class="page-title">Intentos rechazados</h1>
        <p class="page-subtitle">Marcaciones que la app no aceptó: fuera de la geocerca, ubicación simulada, GPS impreciso y más.</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('attendance.index') }}" class="btn btn-light border"><i class="bi bi-clock-history me-1"></i>Marcaciones y GPS</a>
        <a href="{{ route('attendance.calendar') }}" class="btn btn-light border"><i class="bi bi-calendar3 me-1"></i>Calendario</a>
        <a href="{{ route('live.index') }}" class="btn btn-light border"><i class="bi bi-broadcast me-1"></i>En vivo</a>
    </div>
</header>

{{-- Resumen por motivo: cada tarjeta filtra la lista --}}
<section class="dash-kpis" aria-label="Resumen por motivo">
    @foreach ([
        [null, 'Total en el rango', $total, 'brand', 'slash-circle', $uniqueUsersCount . ' colaboradores', 'Ver todos los intentos rechazados'],
        ['MOCK_LOCATION_DETECTED', 'Ubicación simulada', $mockCount, 'rose', 'phone-vibrate', 'Fake GPS detectado', 'Filtrar por ubicación simulada'],
        ['GEOFENCE_VIOLATION', 'Fuera de la geocerca', $byCode['GEOFENCE_VIOLATION'] ?? 0, 'amber', 'geo-alt', 'Fuera del radio', 'Filtrar por fuera de la geocerca'],
        ['GPS_ACCURACY_TOO_LOW', 'GPS impreciso', $byCode['GPS_ACCURACY_TOO_LOW'] ?? 0, 'blue', 'crosshair2', 'Baja precisión satelital', 'Filtrar por GPS impreciso'],
    ] as [$kcode, $klabel, $kvalue, $ktone, $kicon, $kmeta, $ktitle])
        @php $kactive = $kcode === null ? ! request('code') : request('code') === $kcode; @endphp
        <a href="{{ request()->fullUrlWithQuery(['code' => $kcode, 'page' => null]) }}" class="dash-kpi-card is-link {{ $kactive ? 'is-active' : '' }}" title="{{ $ktitle }}" @if($kactive) aria-current="true" @endif>
            <div class="dash-kpi-top">
                <span class="dash-kpi-label">{{ $klabel }}</span>
                <span class="dash-kpi-icon tone-{{ $ktone }}"><i class="bi bi-{{ $kicon }}"></i></span>
            </div>
            <div class="dash-kpi-value">{{ number_format($kvalue) }}</div>
            <div class="dash-kpi-meta">{{ $kmeta }}</div>
        </a>
    @endforeach
</section>

<section class="surface mb-3">
    <form method="GET" action="{{ route('attendance.attempts') }}" id="attemptsFilterForm" class="filter-row border-0" role="search">
        <input type="hidden" name="per_page" value="{{ request('per_page', $attempts->perPage()) }}">
        <div class="search-field">
            <i class="bi bi-search"></i>
            <input type="search" id="search" name="search" value="{{ request('search') }}" class="form-control" placeholder="Colaborador: nombre, código o DNI…" aria-label="Colaborador">
        </div>
        <div class="range-field">
            <input type="date" id="start_date" name="start_date" value="{{ $startDate }}" class="form-control" aria-label="Desde">
            <span class="text-body-secondary small">a</span>
            <input type="date" id="end_date" name="end_date" value="{{ $endDate }}" class="form-control" aria-label="Hasta">
        </div>
        <select id="branch_id" name="branch_id" class="form-select w-auto" aria-label="Sucursal">
            <option value="">Todas las sucursales</option>
            @foreach($branches as $b)
                <option value="{{ $b->id }}" @selected(request('branch_id') == $b->id)>{{ $b->name }}</option>
            @endforeach
        </select>
        <select id="code" name="code" class="form-select w-auto" aria-label="Motivo">
            <option value="">Todos los motivos</option>
            @foreach($codes as $value => $text)
                <option value="{{ $value }}" @selected(request('code') === $value)>{{ $text }}</option>
            @endforeach
        </select>
        <select id="type" name="type" class="form-select w-auto" aria-label="Marcación">
            <option value="">Entrada, salida y descansos</option>
            @foreach(\App\Modules\Core\Support\Labels::options('punch_type') as $value => $text)
                <option value="{{ $value }}" @selected(request('type') === $value)>{{ $text }}</option>
            @endforeach
        </select>
        <div class="d-flex gap-2 ms-auto">
            <button type="submit" class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Filtrar</button>
            @if(request()->hasAny(['search', 'branch_id', 'code', 'type', 'start_date', 'end_date']))
                <a href="{{ route('attendance.attempts') }}" class="btn btn-light border" title="Limpiar todos los filtros" aria-label="Limpiar filtros"><i class="bi bi-x-lg"></i></a>
            @endif
        </div>
    </form>
</section>

{{-- Mapa: dónde intentaron marcar --}}
@if($mapPoints->isNotEmpty())
<section class="surface mb-3 overflow-hidden">
    <div class="surface-head align-items-center flex-wrap">
        <div>
            <h2 class="surface-title">Dónde intentaron marcar</h2>
            <p class="surface-sub">Intentos de esta página y radio de cada sede (línea punteada: tolerancia del borde)</p>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="status-pill tone-brand">Sedes y perímetro</span>
            <span class="status-pill tone-rose">GPS simulado</span>
            <span class="status-pill tone-amber">Fuera de radio</span>
            <button type="button" class="btn btn-sm btn-light border" id="toggleMapBtn">
                <i class="bi bi-arrows-angle-contract me-1" id="toggleMapIcon"></i><span id="toggleMapText">Contraer</span>
            </button>
        </div>
    </div>
    <div id="attemptsMapWrapper">
        <div id="attemptsMap" style="height: 330px;" class="w-100"></div>
    </div>
</section>
@endif

{{-- Tabla de Intentos Rechazados (Totalmente Adaptable sin Scrollbar Forzado) --}}
<section class="surface">
    <div class="table-responsive">
        <table class="table data-table table-cards align-middle mb-0">
            <thead>
                <tr>
                    <th style="width: 22%;">Colaborador</th>
                    <th style="width: 14%;">Fecha y hora</th>
                    <th style="width: 11%;">Marcación</th>
                    <th style="width: 24%;">Motivo</th>
                    <th style="width: 15%;">Ubicación</th>
                    <th style="width: 9%;">Teléfono</th>
                    <th class="text-end">Auditoría</th>
                </tr>
            </thead>
            <tbody>
                @forelse($attempts as $a)
                    @php
                        $userFullName = trim(($a->user->first_name ?? 'N/A') . ' ' . ($a->user->last_name ?? ''));
                        $userInitials = strtoupper(mb_substr($a->user->first_name ?? 'U', 0, 1) . mb_substr($a->user->last_name ?? '', 0, 1));
                        $userPos = $a->user->position ?: 'Colaborador';
                        $attemptJson = [
                            'id' => $a->id,
                            'full_name' => $userFullName,
                            'employee_code' => $a->user->employee_code ?? '—',
                            'document_number' => $a->user->document_number ?? '—',
                            'position' => $userPos,
                            'avatar_url' => !empty($a->user->avatar_path) ? asset('storage/' . $a->user->avatar_path) : null,
                            'branch_name' => $a->branch->name ?? 'Sin sucursal',
                            'date_formatted' => $a->created_at->format('d/m/Y h:i:s a'),
                            'punch_type' => label('punch_type', $a->type),
                            'error_code' => $a->error_code,
                            'error_label' => label('punch_attempt_code', $a->error_code),
                            'message' => $a->message,
                            'source' => $a->source === 'app' ? 'App Móvil (Bloqueo previo)' : 'Servidor (Backend)',
                            'distance_meters' => $a->distance_meters,
                            'allowed_radius' => $a->allowed_radius,
                            'accuracy_meters' => $a->accuracy_meters,
                            'mock_location' => (bool) $a->mock_location_detected,
                            'latitude' => $a->latitude,
                            'longitude' => $a->longitude,
                            'device_os' => $a->device_os ?? '—',
                            'app_version' => $a->app_version ?? '—',
                            'device_id' => $a->device_id ?? '—',
                            'ip' => $a->ip ?? '—',
                            'offline' => (bool) $a->offline,
                            'offline_recorded_at' => $a->offline_recorded_at?->timezone(config('app.timezone'))->format('d/m/Y h:i a') ?? '—',
                            'device_time' => $a->device_time?->timezone(config('app.timezone'))->format('d/m/Y h:i a') ?? '—',
                            'device_flags' => $a->device_flags ?? [],
                            'map_url' => $a->mapUrl(),
                        ];
                    @endphp
                    <tr>
                        {{-- 1. Colaborador --}}
                        <td class="cell-main">
                            <div class="d-flex align-items-center gap-2.5">
                                @if(!empty($a->user?->avatar_path))
                                    <img src="{{ asset('storage/' . $a->user->avatar_path) }}" alt="{{ $userFullName }}" class="attempts-avatar-img">
                                @else
                                    <span class="attempts-avatar-circle">{{ $userInitials }}</span>
                                @endif
                                <div class="min-w-0" style="max-width: 210px;">
                                    <div class="fw-bold text-body text-truncate" style="font-size: 0.85rem;" title="{{ $userFullName }}">
                                        {{ $userFullName }}
                                    </div>
                                    <div class="small text-body-secondary d-flex align-items-center gap-1 flex-wrap mt-0.5" style="font-size: 0.725rem;">
                                        @if($a->user?->employee_code)
                                            <span class="badge border py-0 px-1" style="font-size: 0.65rem; border-radius: 4px; background: var(--bg-surface-elevated); color: var(--text-muted); border-color: var(--border-color) !important;">{{ $a->user->employee_code }}</span>
                                        @endif
                                        <span class="text-truncate" style="max-width: 140px;" title="{{ $a->branch->name ?? 'Sin sucursal' }}">{{ $a->branch->name ?? 'Sin sucursal' }}</span>
                                    </div>
                                    <div class="text-body-secondary text-truncate small" style="font-size: 0.72rem; max-width: 200px;" title="{{ $userPos }}">{{ $userPos }}</div>
                                    @include('partials.tenant-badge', ['tenantId' => $a->tenant_id])
                                </div>
                            </div>
                        </td>

                        {{-- 2. Fecha y hora --}}
                        <td data-label="Fecha">
                            <div class="fw-bold text-body" style="font-size: 0.825rem;">{{ $a->created_at->format('d/m/Y') }}</div>
                            <div class="text-body-secondary small d-flex align-items-center mt-0.5" style="font-size: 0.75rem;">
                                <i class="bi bi-clock text-muted" style="margin-right: 5px;"></i>
                                <span>{{ $a->created_at->format('h:i:s a') }}</span>
                            </div>
                            <div class="d-flex align-items-center gap-1 mt-1 flex-wrap">
                                @if($a->fromApp())
                                    <span class="attempts-pill bg-info-subtle text-info-emphasis border border-info-subtle" title="La app bloqueó la marcación antes de enviarla">
                                        <i class="bi bi-phone" style="margin-right: 4px;"></i>
                                        <span>Desde la app</span>
                                    </span>
                                @endif
                                @if($a->offline)
                                    <span class="attempts-pill bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle" title="Hecha sin conexión el {{ $a->offline_recorded_at?->timezone(config('app.timezone'))->format('d/m/Y h:i a') }}">
                                        <i class="bi bi-wifi-off" style="margin-right: 4px;"></i>
                                        <span>Sin conexión</span>
                                    </span>
                                @endif
                            </div>
                        </td>

                        {{-- 3. Tipo de Marcación --}}
                        <td data-label="Marcación">
                            <x-status-badge type="punch_type" :value="$a->type" />
                        </td>

                        {{-- 4. Motivo del Rechazo --}}
                        <td data-label="Motivo">
                            <x-status-badge type="punch_attempt_code" :value="$a->error_code" />
                            <div class="attempts-msg-box mt-1" title="{{ $a->message }}">{{ $a->message }}</div>
                        </td>

                        {{-- 5. Ubicación y Telemetría --}}
                        <td class="small" data-label="Ubicación">
                            @if($a->distance_meters !== null)
                                <div class="text-danger fw-semibold d-flex align-items-center" style="font-size: 0.78rem; white-space: nowrap;">
                                    <i class="bi bi-geo-alt-fill" style="margin-right: 5px;"></i>
                                    <span>{{ $a->distance_meters }} m <small class="text-secondary fw-normal">de {{ $a->allowed_radius ?? '—' }} m permitidos</small></span>
                                </div>
                                @if($a->allowed_radius !== null && $a->distance_meters > $a->allowed_radius)
                                    <div class="small fw-semibold text-danger-emphasis" style="font-size: 0.72rem;" title="Distancia fuera del radio de la sede">
                                        Excedió por {{ $a->distance_meters - $a->allowed_radius }} m
                                    </div>
                                @endif
                            @endif
                            @if($a->accuracy_meters !== null)
                                <div class="text-secondary small mt-0.5 d-flex align-items-center" style="font-size: 0.725rem; white-space: nowrap;">
                                    <i class="bi bi-crosshair" style="margin-right: 5px;"></i>
                                    <span>Precisión {{ rtrim(rtrim(number_format($a->accuracy_meters, 1, '.', ''), '0'), '.') }} m</span>
                                </div>
                            @endif
                            @if($a->mapUrl())
                                <div class="mt-0.5">
                                    <a href="{{ $a->mapUrl() }}" target="_blank" rel="noopener" class="link-primary text-decoration-none fw-medium d-inline-flex align-items-center" style="font-size: 0.725rem;">
                                        <i class="bi bi-box-arrow-up-right" style="margin-right: 5px;"></i>
                                        <span>Ver en el mapa</span>
                                    </a>
                                </div>
                            @endif
                            @if($a->mock_location_detected)
                                <div class="mt-1">
                                    <span class="attempts-pill bg-danger-subtle text-danger border border-danger-subtle fw-bold">
                                        <i class="bi bi-phone-vibrate" style="margin-right: 4px;"></i>
                                        <span>GPS simulado</span>
                                    </span>
                                </div>
                            @endif
                        </td>

                        {{-- 6. Teléfono & Dispositivo --}}
                        <td class="small" data-label="Teléfono">
                            <div class="text-truncate fw-semibold text-body d-flex align-items-center" style="font-size: 0.8rem;">
                                <i class="bi bi-phone text-secondary" style="margin-right: 6px;"></i>
                                <span>{{ $a->device_os ?? '—' }}</span>
                            </div>
                            <div class="text-secondary small mt-0.5" style="font-size: 0.725rem;">
                                App v{{ $a->app_version ?? '—' }}
                            </div>
                            @if(!empty($a->device_flags))
                                <div class="d-flex flex-wrap gap-1 mt-1">
                                    @foreach($a->device_flags as $flag)
                                        <x-status-badge type="device_flag" :value="$flag" />
                                    @endforeach
                                </div>
                            @endif
                            @if($a->ip)
                                <div class="text-body-secondary mt-0.5 font-monospace text-truncate" style="font-size: 0.7rem; max-width: 120px;" title="{{ $a->ip }}">
                                    {{ $a->ip }}
                                </div>
                            @endif
                        </td>

                        {{-- 7. Acciones Forenses --}}
                        <td class="text-end">
                            <button type="button" 
                                    class="attempts-btn-audit" 
                                    onclick="openAttemptAudit(@js($attemptJson))"
                                    title="Inspeccionar telemetría completa y auditoría forense">
                                <i class="bi bi-shield-check" style="margin-right: 6px;"></i>
                                <span>Auditar</span>
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr class="row-empty">
                        <td colspan="7">
                            <div class="dash-empty">
                                <span class="dash-empty-icon"><i class="bi bi-shield-check"></i></span>
                                <p class="dash-empty-title">No hubo intentos rechazados en este rango.</p>
                                <p class="dash-empty-text">Todas las marcaciones del periodo cumplieron la geocerca, la precisión GPS y la seguridad del dispositivo.</p>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($attempts->total() > 0)
        <div class="table-pagination-footer">
            <div class="table-pagination-info">
                <span>
                    Mostrando <strong>{{ $attempts->firstItem() }}</strong> a <strong>{{ $attempts->lastItem() }}</strong> de <strong>{{ $attempts->total() }}</strong> intentos
                </span>
                <div class="per-page-selector ms-sm-2">
                    <label for="attemptsPerPageSelect" class="text-secondary small mb-0">Mostrar:</label>
                    <select id="attemptsPerPageSelect" class="form-select form-select-sm" onchange="changeAttemptsPerPage(this.value)">
                        <option value="10" @selected($attempts->perPage() == 10)>10</option>
                        <option value="25" @selected($attempts->perPage() == 25)>25</option>
                        <option value="50" @selected($attempts->perPage() == 50)>50</option>
                        <option value="100" @selected($attempts->perPage() == 100)>100</option>
                    </select>
                </div>
            </div>

            <div class="d-flex align-items-center gap-2">
                @if($attempts->hasPages())
                    {{ $attempts->links() }}
                @else
                    <nav aria-label="Navegación de páginas">
                        <ul class="pagination pagination-sm mb-0">
                            <li class="page-item disabled" aria-disabled="true">
                                <span class="page-link" aria-label="Anterior">&lsaquo;</span>
                            </li>
                            <li class="page-item active" aria-current="page">
                                <span class="page-link">1</span>
                            </li>
                            <li class="page-item disabled" aria-disabled="true">
                                <span class="page-link" aria-label="Siguiente">&rsaquo;</span>
                            </li>
                        </ul>
                    </nav>
                @endif
                <span class="pagination-page-indicator">
                    Pág. {{ $attempts->currentPage() }} de {{ $attempts->lastPage() }}
                </span>
            </div>
        </div>
    @endif
</section>

{{-- Modal de Auditoría Forense Completa de Intento --}}
<div class="modal fade" id="attemptAuditModal" tabindex="-1" aria-labelledby="attemptAuditModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div class="d-flex align-items-center gap-2">
                    <span class="d-inline-flex align-items-center justify-content-center rounded-3 bg-danger-subtle text-danger p-2" style="width: 40px; height: 40px;">
                        <i class="bi bi-shield-shaded fs-5"></i>
                    </span>
                    <div>
                        <h2 class="modal-title h6 fw-bold mb-0 text-body" id="attemptAuditModalLabel">
                            Auditoría Forense de Intento #<span id="auditIdText"></span>
                        </h2>
                        <span class="small text-body-secondary" id="auditTimestampText"></span>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>

            <div class="modal-body">
                {{-- Sección 1: Colaborador y Sucursal --}}
                <div class="audit-card-section">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <span class="text-uppercase fw-bold" style="font-size: 0.725rem; letter-spacing: 0.05em; color: var(--text-muted);">
                            <i class="bi bi-person-badge" style="margin-right: 6px;"></i>Colaborador e Identidad
                        </span>
                        <span id="auditPunchTypeBadge"></span>
                    </div>
                    <div class="d-flex align-items-center gap-3">
                        <div id="auditAvatarContainer"></div>
                        <div class="min-w-0 flex-grow-1">
                            <div class="fw-bold fs-6 text-body" id="auditFullNameText"></div>
                            <div class="text-body-secondary small mt-0.5 d-flex align-items-center gap-2 flex-wrap">
                                <span>Código: <strong id="auditEmployeeCodeText"></strong></span>
                                <span>·</span>
                                <span>DNI: <strong id="auditDocumentNumberText"></strong></span>
                                <span>·</span>
                                <span>Cargo: <strong id="auditPositionText"></strong></span>
                            </div>
                            <div class="text-body-secondary small mt-1">
                                <i class="bi bi-building text-primary" style="margin-right: 6px;"></i>Sucursal objetivo: <strong id="auditBranchText"></strong>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Sección 2: Veredicto de Seguridad y Bloqueo --}}
                <div class="audit-card-section">
                    <span class="text-uppercase fw-bold d-block mb-3" style="font-size: 0.725rem; letter-spacing: 0.05em; color: var(--text-muted);">
                        <i class="bi bi-shield-exclamation" style="margin-right: 6px;"></i>Veredicto del Sistema de Seguridad
                    </span>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="audit-field-label">Causa del rechazo</div>
                            <div id="auditReasonBadgeContainer" class="mb-1"></div>
                            <div class="small text-body-secondary" id="auditMessageText" style="line-height: 1.4;"></div>
                        </div>
                        <div class="col-md-6">
                            <div class="audit-field-label">Origen de la detección</div>
                            <div class="audit-field-value" id="auditSourceText"></div>
                            <div class="audit-field-label mt-2">Marcación sin conexión (Offline)</div>
                            <div class="audit-field-value" id="auditOfflineText"></div>
                        </div>
                    </div>
                </div>

                {{-- Sección 3: Telemetría Geoespacial y GPS --}}
                <div class="audit-card-section">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <span class="text-uppercase fw-bold" style="font-size: 0.725rem; letter-spacing: 0.05em; color: var(--text-muted);">
                            <i class="bi bi-geo-alt" style="margin-right: 6px;"></i>Telemetría Satelital & Coordenadas
                        </span>
                        <div id="auditMockBadgeContainer"></div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-6 col-md-4">
                            <div class="audit-field-label">Distancia calculada</div>
                            <div class="audit-field-value text-danger" id="auditDistanceText"></div>
                        </div>
                        <div class="col-6 col-md-4">
                            <div class="audit-field-label">Radio permitido</div>
                            <div class="audit-field-value text-body" id="auditAllowedRadiusText"></div>
                        </div>
                        <div class="col-12 col-md-4">
                            <div class="audit-field-label">Precisión GPS</div>
                            <div class="audit-field-value text-body" id="auditAccuracyText"></div>
                        </div>
                    </div>

                    <div class="p-3 rounded-2" style="background: var(--bg-surface-elevated); border: 1px solid var(--border-color);">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                            <div>
                                <div class="audit-field-label">Coordenadas exactas (Lat, Lng)</div>
                                <div class="font-monospace fw-bold text-body" style="font-size: 0.85rem;" id="auditCoordsText"></div>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <button type="button" class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center" id="copyCoordsBtn" onclick="copyAuditCoords()" style="border-radius: 6px;">
                                    <i class="bi bi-clipboard" style="margin-right: 6px;"></i>
                                    <span id="copyCoordsBtnText">Copiar</span>
                                </button>
                                <a href="#" target="_blank" rel="noopener" class="btn btn-sm btn-primary d-inline-flex align-items-center" id="auditMapLinkBtn" style="border-radius: 6px;">
                                    <i class="bi bi-box-arrow-up-right" style="margin-right: 6px;"></i>
                                    <span>Abrir en Google Maps</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Sección 4: Dispositivo, Hardware & Red --}}
                <div class="audit-card-section mb-0">
                    <span class="text-uppercase fw-bold d-block mb-3" style="font-size: 0.725rem; letter-spacing: 0.05em; color: var(--text-muted);">
                        <i class="bi bi-phone" style="margin-right: 6px;"></i>Huella Digital del Dispositivo & Red
                    </span>
                    <div class="row g-3">
                        <div class="col-6 col-md-3">
                            <div class="audit-field-label">Sistema Operativo</div>
                            <div class="audit-field-value text-body" id="auditDeviceOsText"></div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="audit-field-label">Versión de App</div>
                            <div class="audit-field-value text-body" id="auditAppVersionText"></div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="audit-field-label">Dirección IP</div>
                            <div class="audit-field-value text-body font-monospace" id="auditIpText"></div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="audit-field-label">Hora del Celular</div>
                            <div class="audit-field-value text-body" id="auditDeviceTimeText"></div>
                        </div>
                        <div class="col-12">
                            <div class="audit-field-label">Banderas de Seguridad de Hardware (Root / Emulador / Debugger)</div>
                            <div id="auditFlagsContainer" class="d-flex flex-wrap gap-1 mt-1"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius: 7px;">Cerrar</button>
            </div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
@if($mapPoints->isNotEmpty())
<script>
document.addEventListener('DOMContentLoaded', () => {
    const BRANCHES = @json($mapBranches);
    const POINTS = @json($mapPoints);
    const brand = Panel.cssVar('--brand') || '#0284c7';

    const mapElement = document.getElementById('attemptsMap');
    if (!mapElement) return;

    const map = L.map('attemptsMap', { scrollWheelZoom: false });
    Panel.tiles(map);
    const bounds = [];

    {{-- 1. Dibujar Sedes y Tolerancia --}}
    BRANCHES.forEach((b) => {
        L.circle([b.lat, b.lng], {
            radius: b.radius || 50,
            color: brand,
            fillColor: brand,
            fillOpacity: 0.12,
            weight: 2
        }).addTo(map).bindPopup(`
            <div style="font-family: inherit; font-size: 0.825rem;">
                <div style="font-weight: 700; color: #0f172a; margin-bottom: 2px;">${Panel.esc(b.name)}</div>
                <div style="color: #64748b; font-size: 0.75rem;">Radio autorizado: <strong>${b.radius} m</strong></div>
            </div>
        `);

        if (b.tolerance > 0) {
            L.circle([b.lat, b.lng], {
                radius: b.radius + b.tolerance,
                color: brand,
                weight: 1,
                dashArray: '4 4',
                fill: false,
                interactive: false
            }).addTo(map);
        }
        bounds.push([b.lat, b.lng]);
    });

    {{-- 2. Dibujar Puntos de Intentos Rechazados --}}
    POINTS.forEach((p) => {
        const markerColor = p.mock ? '#dc2626' : '#d97706';
        const marker = L.circleMarker([p.lat, p.lng], {
            radius: 8,
            color: markerColor,
            fillColor: markerColor,
            fillOpacity: 0.85,
            weight: 2.5
        }).addTo(map);

        const distanceHtml = p.distance ? `<div style="color: #dc2626; font-weight: 600; margin-top: 3px;">${p.distance} m (Radio máx: ${p.allowed || '—'} m)</div>` : '';
        const mockAlert = p.mock ? `<div style="background: #fee2e2; color: #991b1b; padding: 2px 6px; border-radius: 4px; font-weight: 700; font-size: 0.7rem; margin-top: 4px;">⚠️ Fake GPS detectado</div>` : '';

        marker.bindPopup(`
            <div style="font-family: inherit; font-size: 0.825rem; min-width: 170px;">
                <div style="font-weight: 700; color: #0f172a;">${Panel.esc(p.name)}</div>
                <div style="color: #64748b; font-size: 0.75rem; margin-top: 1px;">${Panel.esc(p.code)} · ${Panel.esc(p.at)}</div>
                ${distanceHtml}
                ${mockAlert}
            </div>
        `);

        bounds.push([p.lat, p.lng]);
    });

    if (bounds.length > 0) {
        map.fitBounds(bounds, { padding: [35, 35], maxZoom: 16 });
    }

    {{-- Toggle de contraer / expandir mapa --}}
    const toggleBtn = document.getElementById('toggleMapBtn');
    const mapWrapper = document.getElementById('attemptsMapWrapper');
    const toggleIcon = document.getElementById('toggleMapIcon');
    const toggleText = document.getElementById('toggleMapText');

    if (toggleBtn && mapWrapper) {
        let isExpanded = true;
        toggleBtn.addEventListener('click', () => {
            isExpanded = !isExpanded;
            if (isExpanded) {
                mapWrapper.style.display = 'block';
                toggleIcon.className = 'bi bi-arrows-angle-contract me-1';
                toggleText.textContent = 'Contraer';
                map.invalidateSize();
            } else {
                mapWrapper.style.display = 'none';
                toggleIcon.className = 'bi bi-arrows-angle-expand me-1';
                toggleText.textContent = 'Expandir';
            }
        });
    }
});
</script>
@endif

<script>
let currentAuditCoords = '';

function openAttemptAudit(data) {
    if (!data) return;

    {{-- 1. Título y Fechas --}}
    document.getElementById('auditIdText').textContent = data.id || '';
    document.getElementById('auditTimestampText').textContent = data.date_formatted || '';
    document.getElementById('auditPunchTypeBadge').innerHTML = `<span class="attempts-pill bg-primary-subtle text-primary border border-primary-subtle">${Panel.esc(data.punch_type)}</span>`;

    {{-- 2. Avatar e Identidad --}}
    const avatarContainer = document.getElementById('auditAvatarContainer');
    if (data.avatar_url) {
        avatarContainer.innerHTML = `<img src="${Panel.esc(data.avatar_url)}" alt="${Panel.esc(data.full_name)}" class="attempts-avatar-img" style="width: 46px; height: 46px; border-radius: 10px;">`;
    } else {
        const initials = (data.full_name || 'U').split(' ').map(n => n.charAt(0)).slice(0, 2).join('').toUpperCase();
        avatarContainer.innerHTML = `<span class="attempts-avatar-circle" style="width: 46px; height: 46px; border-radius: 10px; font-size: 0.95rem;">${Panel.esc(initials)}</span>`;
    }

    document.getElementById('auditFullNameText').textContent = data.full_name || 'N/A';
    document.getElementById('auditEmployeeCodeText').textContent = data.employee_code || '—';
    document.getElementById('auditDocumentNumberText').textContent = data.document_number || '—';
    document.getElementById('auditPositionText').textContent = data.position || 'Colaborador';
    document.getElementById('auditBranchText').textContent = data.branch_name || 'Sin sucursal asignada';

    {{-- 3. Veredicto del Rechazo --}}
    const toneClass = data.mock_location ? 'danger' : 'warning';
    document.getElementById('auditReasonBadgeContainer').innerHTML = `<span class="attempts-pill bg-${toneClass}-subtle text-${toneClass}-emphasis border border-${toneClass}-subtle fw-bold">${Panel.esc(data.error_label)}</span>`;
    document.getElementById('auditMessageText').textContent = data.message || 'Sin mensaje de error disponible.';
    document.getElementById('auditSourceText').textContent = data.source || 'Servidor';
    document.getElementById('auditOfflineText').textContent = data.offline ? `Sí (Registrada: ${data.offline_recorded_at})` : 'No (Transmisión en tiempo real)';

    {{-- 4. Telemetría GPS --}}
    const mockBadgeContainer = document.getElementById('auditMockBadgeContainer');
    if (data.mock_location) {
        mockBadgeContainer.innerHTML = `<span class="attempts-pill bg-danger-subtle text-danger border border-danger-subtle fw-bold"><i class="bi bi-phone-vibrate" style="margin-right: 5px;"></i>¡Alerta! Fake GPS detectado</span>`;
    } else {
        mockBadgeContainer.innerHTML = `<span class="attempts-pill bg-success-subtle text-success border border-success-subtle"><i class="bi bi-check-circle" style="margin-right: 5px;"></i>GPS Auténtico</span>`;
    }

    document.getElementById('auditDistanceText').textContent = data.distance_meters !== null ? `${data.distance_meters} metros` : 'No aplicable';
    document.getElementById('auditAllowedRadiusText').textContent = data.allowed_radius !== null ? `${data.allowed_radius} metros` : '—';
    document.getElementById('auditAccuracyText').textContent = data.accuracy_meters !== null ? `±${data.accuracy_meters} m` : 'No informada';

    const coordsText = (data.latitude !== null && data.longitude !== null) ? `${data.latitude}, ${data.longitude}` : 'Sin coordenadas registradas';
    document.getElementById('auditCoordsText').textContent = coordsText;
    currentAuditCoords = (data.latitude !== null && data.longitude !== null) ? `${data.latitude},${data.longitude}` : '';

    const mapBtn = document.getElementById('auditMapLinkBtn');
    const copyBtn = document.getElementById('copyCoordsBtn');
    if (data.map_url) {
        mapBtn.href = data.map_url;
        mapBtn.style.display = 'inline-flex';
        copyBtn.style.display = 'inline-flex';
    } else {
        mapBtn.style.display = 'none';
        copyBtn.style.display = 'none';
    }

    {{-- 5. Dispositivo y Red --}}
    document.getElementById('auditDeviceOsText').textContent = data.device_os || '—';
    document.getElementById('auditAppVersionText').textContent = data.app_version ? `v${data.app_version}` : '—';
    document.getElementById('auditIpText').textContent = data.ip || '—';
    document.getElementById('auditDeviceTimeText').textContent = data.device_time || '—';

    const flagsContainer = document.getElementById('auditFlagsContainer');
    if (data.device_flags && data.device_flags.length > 0) {
        flagsContainer.innerHTML = data.device_flags.map(f => `<span class="attempts-pill bg-danger-subtle text-danger border border-danger-subtle"><i class="bi bi-shield-slash" style="margin-right: 5px;"></i>${Panel.esc(f)}</span>`).join(' ');
    } else {
        flagsContainer.innerHTML = `<span class="attempts-pill bg-success-subtle text-success border border-success-subtle"><i class="bi bi-shield-check" style="margin-right: 5px;"></i>Dispositivo íntegro (Sin anomalías)</span>`;
    }

    {{-- 6. Mostrar Modal --}}
    const modalEl = document.getElementById('attemptAuditModal');
    if (modalEl) {
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
}

function copyAuditCoords() {
    if (!currentAuditCoords) return;
    navigator.clipboard.writeText(currentAuditCoords).then(() => {
        const btnText = document.getElementById('copyCoordsBtnText');
        if (btnText) {
            btnText.textContent = '¡Copiado!';
            setTimeout(() => { btnText.textContent = 'Copiar'; }, 2000);
        }
    });
}

function changeAttemptsPerPage(perPage) {
    const url = new URL(window.location.href);
    url.searchParams.set('per_page', perPage);
    url.searchParams.set('page', '1');
    window.location.href = url.toString();
}
</script>
@endsection
