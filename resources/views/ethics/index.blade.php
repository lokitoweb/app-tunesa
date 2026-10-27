@extends('layouts.app')

@section('title', 'Canal ético — Gestión de Denuncias')

@section('content')
<div class="ethics-page-wrapper">
    {{-- Header Ejecutivo del Módulo --}}
    <div class="ethics-header-bar">
        <div class="d-flex align-items-center gap-3">
            <div class="ethics-header-icon shadow-sm">
                <i class="bi bi-shield-lock-fill"></i>
            </div>
            <div>
                <div class="d-flex align-items-center gap-3 flex-wrap">
                    <h1 class="h3 fw-bold mb-0 text-body">Canal ético</h1>
                    <span class="badge rounded-2 bg-primary-subtle text-primary border border-primary-subtle px-2.5 py-1 small fw-semibold">
                        <i class="bi bi-lock-fill me-1"></i>Confidencial & Cifrado
                    </span>
                    <div id="ethicsHeaderUnreadBadge">
                        @if(($counts['unread'] ?? 0) > 0)
                            <span class="badge rounded-2 bg-danger-subtle text-danger border border-danger-subtle px-2.5 py-1 small fw-semibold d-inline-flex align-items-center gap-1.5">
                                <span class="ethics-unread-pulse"></span>
                                <span>{{ $counts['unread'] }} con mensajes nuevos</span>
                            </span>
                        @endif
                    </div>
                </div>
                <p class="text-secondary small mb-0 mt-1">
                    Bandeja oficial para la recepción, investigación imparcial y seguimiento de denuncias corporativas.
                </p>
            </div>
        </div>

        {{-- Acciones Globales & Enlace al Portal Web Anónimo --}}
        <div class="d-flex align-items-center gap-2 flex-wrap">
            @php($ethicsTenant = $currentTenant ?? (app(\App\Modules\Core\Services\TenantContext::class)->resolved() ? app(\App\Modules\Core\Services\TenantContext::class)->get() : null))
            @if($ethicsTenant)
                <div class="ethics-public-chip d-flex align-items-center gap-2 p-1.5 pe-2 rounded-3 border">
                    <span class="badge bg-body-secondary text-secondary rounded-2 px-2 py-1 small font-monospace">
                        <i class="bi bi-globe2 me-1"></i>Web Pública
                    </span>
                    <span class="small text-truncate font-monospace d-none d-md-inline" style="max-width: 200px;">
                        {{ $ethicsTenant->slug }}
                    </span>
                    <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-2 d-inline-flex align-items-center gap-1" 
                            onclick="copyPublicUrl('{{ route('ethics.public', $ethicsTenant->slug) }}', this)" 
                            title="Copiar enlace web para colaboradores">
                        <i class="bi bi-clipboard"></i>
                        <span class="d-none d-sm-inline">Copiar link</span>
                    </button>
                    <a href="{{ route('ethics.public', $ethicsTenant->slug) }}" target="_blank" rel="noopener noreferrer" 
                       class="btn btn-sm btn-primary py-1 px-2.5 d-inline-flex align-items-center gap-1"
                       title="Abrir formulario web anónimo en una pestaña nueva">
                        <i class="bi bi-box-arrow-up-right"></i>
                        <span class="d-none d-sm-inline">Abrir portal</span>
                    </a>
                </div>
            @endif
        </div>
    </div>

    {{-- Contenedor Reactivo Dinámico para AJAX --}}
    <div id="ethicsDynamicWrapper">
        {{-- Barra de Controles, Pestañas y Filtros Inteligentes --}}
        <div class="card border-0 mb-3 shadow-sm" style="background: var(--bg-surface); border: 1px solid var(--border-color) !important; border-radius: 8px;">
            <div class="card-body p-3">
                <form method="GET" action="{{ route('ethics.index') }}" id="ethicsFilterForm" onsubmit="event.preventDefault(); applyEthicsFilter();">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3 pb-3 border-bottom">
                        {{-- Grupo de Pestañas Segmentadas --}}
                        <div class="ethics-tab-group" role="tablist">
                            @php($tabs = [
                                'open'       => ['label' => 'Abiertas', 'count' => $counts['open'] ?? 0],
                                'received'   => ['label' => 'Recibidas', 'count' => $counts['received'] ?? 0],
                                'in_review'  => ['label' => 'En investigación', 'count' => $counts['in_review'] ?? 0],
                                'needs_info' => ['label' => 'Esperan info', 'count' => $counts['needs_info'] ?? 0],
                                'closed'     => ['label' => 'Cerradas', 'count' => $counts['closed'] ?? 0],
                                'all'        => ['label' => 'Todas', 'count' => $counts['all'] ?? 0],
                            ])
                            @foreach($tabs as $tabKey => $tabData)
                                <a href="{{ route('ethics.index', ['status' => $tabKey] + request()->only('category', 'code', 'origin', 'assignee')) }}" 
                                   class="ethics-tab-btn {{ $status === $tabKey && !request()->boolean('unread') ? 'active' : '' }}"
                                   onclick="switchEthicsStatus('{{ $tabKey }}', event)">
                                    <span>{{ $tabData['label'] }}</span>
                                    <span class="ethics-counter-pill {{ $status === $tabKey && !request()->boolean('unread') ? 'bg-primary text-white' : 'ethics-counter-pill-subtle' }}">
                                        {{ $tabData['count'] }}
                                    </span>
                                </a>
                            @endforeach

                            @if(($counts['unread'] ?? 0) > 0)
                                <a href="{{ route('ethics.index', ['unread' => 1] + request()->only('category', 'code', 'origin', 'assignee')) }}" 
                                   class="ethics-tab-btn ethics-tab-unread {{ request()->boolean('unread') ? 'active' : '' }}"
                                   onclick="switchEthicsUnread(event)"
                                   title="Filtrar denuncias con mensajes nuevos sin leer">
                                    <i class="bi bi-bell-fill text-danger"></i>
                                    <span>Nuevas sin leer</span>
                                    <span class="ethics-counter-pill bg-danger text-white">
                                        {{ $counts['unread'] }}
                                    </span>
                                </a>
                            @endif
                        </div>

                        {{-- Conteo y Limpieza de Filtros --}}
                        <div class="d-flex align-items-center gap-2">
                            @if(request()->hasAny(['code', 'category', 'origin', 'assignee', 'unread']) || $status !== 'open')
                                <a href="{{ route('ethics.index') }}" onclick="resetEthicsFilters(event)" class="btn btn-sm btn-link text-danger text-decoration-none d-inline-flex align-items-center gap-1">
                                    <i class="bi bi-x-circle"></i>
                                    <span>Limpiar filtros</span>
                                </a>
                            @endif
                            <span class="text-secondary small fw-medium">
                                <i class="bi bi-funnel me-1"></i>{{ $cases->total() }} expediente(s)
                            </span>
                        </div>
                    </div>

                    {{-- Filtros Secundarios --}}
                    <input type="hidden" name="status" id="ethicsStatusInput" value="{{ $status }}">
                    @if(request()->boolean('unread'))
                        <input type="hidden" name="unread" id="ethicsUnreadInput" value="1">
                    @endif
                    <div class="row g-2 align-items-center">
                        {{-- Buscador de Código --}}
                        <div class="col-12 col-md-4 col-xl-3">
                            <div class="ethics-search-box w-100 max-w-none">
                                <i class="bi bi-search ethics-search-icon"></i>
                                <input type="search" name="code" id="ethicsSearchCode" value="{{ request('code') }}" 
                                       placeholder="Buscar por código (ej. 490081)..." 
                                       class="ethics-search-input"
                                       autocomplete="off"
                                       oninput="debounceEthicsSearch()">
                                @if(request('code'))
                                    <button type="button" class="ethics-search-clear" onclick="clearEthicsSearch()" title="Borrar búsqueda">
                                        <i class="bi bi-x-circle-fill"></i>
                                    </button>
                                @endif
                            </div>
                        </div>

                        {{-- Filtro: Tipo de Hecho / Categoría --}}
                        <div class="col-6 col-md-3 col-xl-3">
                            <select name="category" id="ethicsFilterCategory" class="form-select form-select-sm" onchange="applyEthicsFilter()" aria-label="Filtrar por categoría">
                                <option value="">Todas las categorías</option>
                                @foreach(\App\Modules\Ethics\Models\EthicsCase::CATEGORIES as $k => $text)
                                    <option value="{{ $k }}" @selected(request('category') === $k)>{{ $text }}</option>
                                @endforeach
                            </select>
                        </div>

                        {{-- Filtro: Origen (Web Anónima vs App Móvil) --}}
                        <div class="col-6 col-md-2 col-xl-2">
                            <select name="origin" id="ethicsFilterOrigin" class="form-select form-select-sm" onchange="applyEthicsFilter()" aria-label="Filtrar por canal de origen">
                                <option value="">Cualquier origen</option>
                                <option value="web" @selected(request('origin') === 'web')>Web 100% Anónima</option>
                                <option value="app" @selected(request('origin') === 'app')>App Móvil (Identidad reservada)</option>
                            </select>
                        </div>

                        {{-- Filtro: Investigador Asignado --}}
                        <div class="col-6 col-md-3 col-xl-3">
                            <select name="assignee" id="ethicsFilterAssignee" class="form-select form-select-sm" onchange="applyEthicsFilter()" aria-label="Filtrar por investigador">
                                <option value="">Todos los investigadores</option>
                                <option value="unassigned" @selected(request('assignee') === 'unassigned')>Sin asignar</option>
                                @foreach($investigators as $inv)
                                    <option value="{{ $inv->id }}" @selected(request('assignee') == $inv->id)>
                                        {{ $inv->first_name }} {{ $inv->last_name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        {{-- Botón Buscar / Aplicar --}}
                        <div class="col-6 col-md-2 col-xl-1 d-grid">
                            <button type="submit" class="btn btn-sm btn-primary d-inline-flex align-items-center justify-content-center gap-1">
                                <i class="bi bi-search"></i>
                                <span class="d-none d-xl-inline">Filtrar</span>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

    {{-- Tabla Principal de Denuncias --}}
    <div class="card border-0 shadow-sm overflow-hidden" style="background: var(--bg-surface); border: 1px solid var(--border-color) !important; border-radius: 12px;">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 ethics-table">
                <thead>
                    <tr>
                        <th style="min-width: 140px;">Expediente</th>
                        <th style="min-width: 170px;">Categoría</th>
                        <th style="min-width: 150px;">Origen</th>
                        @if(($globalView ?? false) || !$currentTenant)
                            <th style="min-width: 160px;">Empresa</th>
                        @endif
                        <th style="min-width: 160px;">Investigador</th>
                        <th style="min-width: 120px;">Recepción</th>
                        <th style="min-width: 130px;">Estado</th>
                        <th class="text-end pe-3" style="min-width: 140px;">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($cases as $c)
                        <tr class="ethics-row {{ $c->investigator_unread ? 'row-unread' : '' }}" data-case-id="{{ $c->id }}">
                            {{-- Expediente / Código --}}
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="ethics-code-badge" role="button" onclick="openEthicsQuickModal({{ $c->id }})" title="Ver expediente #{{ $c->public_code }}">
                                        <i class="bi bi-shield-lock-fill text-primary"></i>
                                        <span>#{{ $c->public_code }}</span>
                                    </span>
                                    @if($c->investigator_unread)
                                        <span class="badge rounded-2 text-bg-danger fw-bold d-inline-flex align-items-center gap-1" style="font-size: 0.675rem; padding: 3px 6px;">
                                            <span class="ethics-unread-pulse" style="width: 5px; height: 5px;"></span>
                                            <span>NUEVO</span>
                                        </span>
                                    @endif
                                </div>
                            </td>

                            {{-- Categoría --}}
                            <td>
                                <span class="badge rounded-2 bg-{{ $c->categoryTone() }}-subtle text-{{ $c->categoryTone() }}-emphasis border border-{{ $c->categoryTone() }}-subtle px-2.5 py-1 fw-medium" style="font-size: 0.75rem; letter-spacing: 0.01em;">
                                    <i class="bi bi-{{ $c->categoryIcon() }} me-1.5"></i>{{ $c->categoryLabel() }}
                                </span>
                            </td>

                            {{-- Origen --}}
                            <td>
                                <span class="ethics-origin-pill {{ $c->isFromApp() ? 'origin-app' : 'origin-web' }}" title="{{ $c->originLabel() }}">
                                    <i class="bi bi-{{ $c->isFromApp() ? 'person-lock' : 'incognito' }}"></i>
                                    <span>{{ $c->isFromApp() ? 'App móvil' : 'Web anónima' }}</span>
                                </span>
                            </td>

                            {{-- Empresa (Multi-tenant) --}}
                            @if(($globalView ?? false) || !$currentTenant)
                                <td>
                                    <div class="small fw-semibold text-secondary text-truncate" style="max-width: 170px;" title="{{ $c->tenant?->company_name ?? 'Empresa' }}">
                                        <i class="bi bi-buildings text-muted me-1"></i>{{ $c->tenant?->company_name ?? 'Empresa' }}
                                    </div>
                                </td>
                            @endif

                            {{-- Investigador Asignado --}}
                            <td>
                                @if($c->assignee)
                                    <div class="d-flex align-items-center gap-1.5 small">
                                        <div class="ethics-investigator-avatar">
                                            {{ mb_substr($c->assignee->first_name, 0, 1) }}{{ mb_substr($c->assignee->last_name, 0, 1) }}
                                        </div>
                                        <span class="fw-semibold text-body text-truncate" style="max-width: 120px;" title="{{ $c->assignee->first_name }} {{ $c->assignee->last_name }}">
                                            {{ $c->assignee->first_name }} {{ $c->assignee->last_name }}
                                        </span>
                                    </div>
                                @else
                                    <span class="badge rounded-2 bg-warning-subtle text-warning-emphasis border border-warning-subtle px-2 py-1 small" style="font-size: 0.725rem;">
                                        <i class="bi bi-question-circle me-1"></i>Sin asignar
                                    </span>
                                @endif
                            </td>

                            {{-- Fecha de Recepción --}}
                            <td>
                                <div class="small text-secondary text-nowrap">
                                    <i class="bi bi-calendar3 me-1 text-muted"></i>{{ $c->reported_on->format('d/m/Y') }}
                                </div>
                            </td>

                            {{-- Estado del Expediente --}}
                            <td>
                                <x-status-badge type="ethics_status" :value="$c->status" />
                            </td>

                            {{-- Acciones --}}
                            <td class="text-end pe-3">
                                <button type="button" 
                                        class="btn-table-action" 
                                        onclick="openEthicsQuickModal({{ $c->id }})" 
                                        title="Ver denuncia #{{ $c->public_code }}" 
                                        aria-label="Ver denuncia #{{ $c->public_code }}">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ (($globalView ?? false) || !$currentTenant) ? '8' : '7' }}">
                                <div class="text-center py-5">
                                    <div class="ethics-empty-icon mb-3">
                                        <i class="bi bi-shield-check"></i>
                                    </div>
                                    <h3 class="h5 fw-bold text-body mb-1">No hay denuncias en esta bandeja</h3>
                                    <p class="text-secondary small mb-3" style="max-width: 420px; margin: 0 auto;">
                                        No se encontraron expedientes que coincidan con los filtros seleccionados o el canal ético se encuentra al día.
                                    </p>
                                    @if(request()->hasAny(['code', 'category', 'origin', 'assignee', 'unread', 'status']))
                                        <a href="{{ route('ethics.index') }}" class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1">
                                            <i class="bi bi-arrow-counterclockwise"></i>
                                            <span>Restablecer filtros</span>
                                        </a>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($cases->hasPages())
            <div class="card-footer border-top bg-transparent p-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div class="small text-secondary">
                    Mostrando del <strong>{{ $cases->firstItem() }}</strong> al <strong>{{ $cases->lastItem() }}</strong> de <strong>{{ $cases->total() }}</strong> expedientes
                </div>
                <div>
                    {{ $cases->links() }}
                </div>
            </div>
        @endif
    </div>
    </div> {{-- Fin #ethicsDynamicWrapper --}}
</div>

{{-- Modal de Vista Rápida del Expediente --}}
<div class="modal fade" id="ethicsQuickModal" tabindex="-1" aria-labelledby="ethicsModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable modal-fullscreen-sm-down modal-dialog-centered modal-lg" style="max-width: 840px;">
        <div class="modal-content emp-modal-content" id="ethicsModalContainer">
            {{-- Contenido cargado dinámicamente vía AJAX --}}
            <div class="p-5 text-center">
                <div class="spinner-border text-primary mb-3" role="status"></div>
                <div class="fw-semibold text-body">Cargando expediente seguro…</div>
                <div class="text-secondary small">Descifrando datos y comprobando permisos de auditoría</div>
            </div>
        </div>
    </div>
</div>

{{-- Lightbox Modal de Fotos Adjuntas --}}
<div class="modal fade" id="ethicsPhotoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable modal-fullscreen-sm-down modal-dialog-centered modal-lg">
        <div class="modal-content border-0" style="background: transparent;">
            <div class="modal-body text-center p-0 position-relative">
                <button type="button" class="btn-close btn-close-white position-absolute top-0 end-0 m-3 shadow" data-bs-dismiss="modal" aria-label="Cerrar" style="z-index: 10;"></button>
                <img id="ethicsModalPhotoImg" src="" alt="Foto adjunta" class="img-fluid rounded-3 shadow-lg" style="max-height: 85vh; object-fit: contain;">
                <div id="ethicsModalPhotoCaption" class="text-white mt-2 small font-monospace"></div>
            </div>
        </div>
    </div>
</div>

@section('scripts')
<script>
{{-- Estado reactivo interno de Canal Ético (Asíncrono y desacoplado de la URL del navegador) --}}
let currentEthicsState = {
    status: '{{ $status }}',
    unread: '{{ request()->boolean('unread') ? '1' : '' }}',
    code: '{{ request('code') }}',
    category: '{{ request('category') }}',
    origin: '{{ request('origin') }}',
    assignee: '{{ request('assignee') }}',
    per_page: '{{ $cases->perPage() }}',
    page: {{ $cases->currentPage() }}
};

let ethicsAbortController = null;
let ethicsSearchTimer = null;

async function loadEthics(overrides = {}) {
    Object.assign(currentEthicsState, overrides);

    const wrapper = document.getElementById('ethicsDynamicWrapper');
    if (wrapper) {
        wrapper.classList.add('is-loading');
    }

    if (ethicsAbortController) {
        ethicsAbortController.abort();
    }
    ethicsAbortController = new AbortController();

    try {
        const url = new URL('{{ route("ethics.index") }}', window.location.origin);
        if (currentEthicsState.status) url.searchParams.set('status', currentEthicsState.status);
        if (currentEthicsState.unread) url.searchParams.set('unread', currentEthicsState.unread);
        if (currentEthicsState.code) url.searchParams.set('code', currentEthicsState.code);
        if (currentEthicsState.category) url.searchParams.set('category', currentEthicsState.category);
        if (currentEthicsState.origin) url.searchParams.set('origin', currentEthicsState.origin);
        if (currentEthicsState.assignee) url.searchParams.set('assignee', currentEthicsState.assignee);
        if (currentEthicsState.per_page) url.searchParams.set('per_page', currentEthicsState.per_page);
        if (currentEthicsState.page) url.searchParams.set('page', currentEthicsState.page);

        const response = await fetch(url.toString(), {
            signal: ethicsAbortController.signal,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'text/html'
            }
        });

        if (!response.ok) throw new Error('Error al actualizar bandeja de denuncias');

        const html = await response.text();
        const doc = new DOMParser().parseFromString(html, 'text/html');

        const newWrapper = doc.getElementById('ethicsDynamicWrapper');
        if (newWrapper && wrapper) {
            wrapper.innerHTML = newWrapper.innerHTML;
        }

        const newHeaderBadge = doc.getElementById('ethicsHeaderUnreadBadge');
        const currentHeaderBadge = document.getElementById('ethicsHeaderUnreadBadge');
        if (newHeaderBadge && currentHeaderBadge) {
            currentHeaderBadge.innerHTML = newHeaderBadge.innerHTML;
        }

        {{-- Mantener la URL del navegador completamente limpia sin cadenas de consulta (ej: /ethics) --}}
        window.history.replaceState({}, '', '{{ route("ethics.index") }}');
    } catch (err) {
        if (err.name !== 'AbortError') {
            console.error('Error al cargar canal ético vía AJAX:', err);
        }
    } finally {
        if (wrapper) {
            wrapper.classList.remove('is-loading');
        }
    }
}

function switchEthicsStatus(status, e) {
    if (e) e.preventDefault();
    loadEthics({ status: status, unread: '', page: 1 });
}

function switchEthicsUnread(e) {
    if (e) e.preventDefault();
    loadEthics({ unread: '1', page: 1 });
}

function applyEthicsFilter() {
    const codeVal = document.getElementById('ethicsSearchCode')?.value || '';
    const catVal = document.getElementById('ethicsFilterCategory')?.value || '';
    const originVal = document.getElementById('ethicsFilterOrigin')?.value || '';
    const assigneeVal = document.getElementById('ethicsFilterAssignee')?.value || '';

    loadEthics({
        code: codeVal,
        category: catVal,
        origin: originVal,
        assignee: assigneeVal,
        page: 1
    });
}

function debounceEthicsSearch() {
    clearTimeout(ethicsSearchTimer);
    ethicsSearchTimer = setTimeout(() => {
        applyEthicsFilter();
    }, 350);
}

function clearEthicsSearch() {
    const input = document.getElementById('ethicsSearchCode');
    if (input) input.value = '';
    applyEthicsFilter();
}

function resetEthicsFilters(e) {
    if (e) e.preventDefault();
    currentEthicsState = {
        status: 'open',
        unread: '',
        code: '',
        category: '',
        origin: '',
        assignee: '',
        per_page: '20',
        page: 1
    };
    loadEthics(currentEthicsState);
}

{{-- Delegación de eventos para la paginación interna sin alterar la URL ni recargar la pantalla --}}
document.addEventListener('click', function(e) {
    const pageLink = e.target.closest('#ethicsDynamicWrapper .pagination a.page-link');
    if (pageLink) {
        e.preventDefault();
        const href = pageLink.getAttribute('href');
        if (href) {
            try {
                const targetUrl = new URL(href, window.location.origin);
                const page = targetUrl.searchParams.get('page') || 1;
                loadEthics({ page: parseInt(page, 10) });
            } catch (ex) {
                {{-- fallback --}}
            }
        }
    }
});

{{-- Limpiar la URL inicial al cargar la página y abrir modal si se especificó open_case --}}
document.addEventListener('DOMContentLoaded', function() {
    @if(request('open_case'))
        const targetTab = '{{ request("tab") === "gestion" ? "tab-ethics-gestion-btn" : (request("tab") === "comunicacion" ? "tab-ethics-comunicacion-btn" : "") }}';
        openEthicsQuickModal({{ (int) request('open_case') }}, targetTab || null);
    @endif
    window.history.replaceState({}, '', '{{ route("ethics.index") }}');
});

{{-- Copiar URL pública con feedback visual --}}
function copyPublicUrl(url, btn) {
    if (!navigator.clipboard) {
        prompt('Copia este enlace para el canal ético web:', url);
        return;
    }
    navigator.clipboard.writeText(url).then(() => {
        const originalHtml = btn.innerHTML;
        btn.classList.remove('btn-outline-secondary');
        btn.classList.add('btn-success');
        btn.innerHTML = '<i class="bi bi-check2"></i> <span class="d-none d-sm-inline">¡Copiado!</span>';
        setTimeout(() => {
            btn.innerHTML = originalHtml;
            btn.classList.remove('btn-success');
            btn.classList.add('btn-outline-secondary');
        }, 2200);
    }).catch(() => {
        prompt('Copia este enlace para el canal ético web:', url);
    });
}

{{-- Abrir modal de vista rápida vía AJAX --}}
window.openEthicsQuickModal = function(caseId, activeTabId = null) {
    const modalEl = document.getElementById('ethicsQuickModal');
    const container = document.getElementById('ethicsModalContainer');
    if (!modalEl || !container) return;

    {{-- Spinner inicial --}}
    container.innerHTML = `
        <div class="p-5 text-center">
            <div class="spinner-border text-primary mb-3" role="status"></div>
            <div class="fw-semibold text-body">Cargando expediente seguro…</div>
            <div class="text-secondary small">Descifrando datos y comprobando permisos de auditoría</div>
        </div>
    `;

    const bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
    bsModal.show();

    fetch(`/ethics/${caseId}`, {
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'text/html'
        }
    })
    .then(res => {
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return res.text();
    })
    .then(html => {
        container.innerHTML = html;
        bindModalFormEvents(container, caseId);
        if (activeTabId) {
            const tabBtn = container.querySelector(`#${activeTabId}`);
            if (tabBtn) {
                const bsTab = bootstrap.Tab.getOrCreateInstance(tabBtn);
                bsTab.show();
            }
        }
    })
    .catch(err => {
        container.innerHTML = `
            <div class="p-4 text-center">
                <i class="bi bi-exclamation-triangle-fill text-danger fs-2 d-block mb-2"></i>
                <div class="fw-bold text-body">No se pudo cargar el expediente</div>
                <div class="text-secondary small mb-3">${Panel.esc(err.message)}</div>
                <a href="/ethics/${encodeURIComponent(caseId)}" class="btn btn-primary btn-sm">Abrir en página completa</a>
            </div>
        `;
    });
};

{{-- Eventos internos de los formularios del modal (ajaxify) --}}
function bindModalFormEvents(container, caseId) {
    {{-- Garantizar cambio instantáneo de pestañas en el modal dinámico --}}
    container.querySelectorAll('#ethicsModalTabs button[data-bs-toggle="pill"]').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const targetId = this.getAttribute('data-bs-target');
            if (!targetId) return;

            container.querySelectorAll('#ethicsModalTabs .nav-link').forEach(b => {
                b.classList.remove('active');
                b.setAttribute('aria-selected', 'false');
            });
            this.classList.add('active');
            this.setAttribute('aria-selected', 'true');

            container.querySelectorAll('#ethicsModalTabContent .tab-pane').forEach(p => {
                p.classList.remove('show', 'active');
            });
            const targetPane = container.querySelector(targetId);
            if (targetPane) {
                targetPane.classList.add('show', 'active');
            }
        });
    });

    {{-- Interceptar envío de respuesta --}}
    const replyForm = container.querySelector('form[action*="/reply"]');
    if (replyForm) {
        replyForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const btn = replyForm.querySelector('button[type="submit"]');
            const originalBtn = btn ? btn.innerHTML : '';
            if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Enviando…'; }

            const formData = new FormData(replyForm);
            fetch(replyForm.action, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    openEthicsQuickModal(caseId, 'tab-ethics-comunicacion-btn'); {{-- Mantener pestaña de chat --}}
                } else {
                    Panel.toast(data.message || 'Error al enviar respuesta', 'error');
                    if (btn) { btn.disabled = false; btn.innerHTML = originalBtn; }
                }
            })
            .catch(() => {
                replyForm.submit(); {{-- Fallback tradicional --}}
            });
        });
    }

    {{-- Interceptar cambio de estado --}}
    const statusForm = container.querySelector('form[action*="/status"]');
    if (statusForm) {
        statusForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const btn = statusForm.querySelector('button[type="submit"]');
            const originalBtn = btn ? btn.innerHTML : '';
            if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Guardando…'; }

            const formData = new FormData(statusForm);
            fetch(statusForm.action, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    openEthicsQuickModal(caseId, 'tab-ethics-gestion-btn'); {{-- Mantener pestaña de gestión --}}
                } else {
                    Panel.toast(data.message || 'Error al actualizar estado', 'error');
                    if (btn) { btn.disabled = false; btn.innerHTML = originalBtn; }
                }
            })
            .catch(() => {
                statusForm.submit();
            });
        });
    }

    {{-- Interceptar asignación --}}
    const assignForm = container.querySelector('form[action*="/assign"]');
    if (assignForm) {
        assignForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const btn = assignForm.querySelector('button[type="submit"]');
            const originalBtn = btn ? btn.innerHTML : '';
            if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Asignando…'; }

            const formData = new FormData(assignForm);
            fetch(assignForm.action, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    openEthicsQuickModal(caseId, 'tab-ethics-gestion-btn'); {{-- Mantener pestaña de gestión --}}
                } else {
                    Panel.toast(data.message || 'Error al asignar', 'error');
                    if (btn) { btn.disabled = false; btn.innerHTML = originalBtn; }
                }
            })
            .catch(() => {
                assignForm.submit();
            });
        });
    }

    {{-- Interceptar consulta de identidad reservada vía AJAX --}}
    const identityForm = container.querySelector('form[action*="/identity"]');
    if (identityForm) {
        identityForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const btn = identityForm.querySelector('button[type="submit"]') || identityForm.querySelector('button');
            window.revealEthicsIdentity(caseId, btn);
        });
    }
}

{{-- Función global para revelar identidad protegida de forma auditada --}}
window.revealEthicsIdentity = function(caseId, btn) {
    if (!btn) btn = document.getElementById('btnRevealIdentity_' + caseId);
    const form = document.getElementById('modalIdentityForm_' + caseId);
    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') 
               || form?.querySelector('input[name="_token"]')?.value 
               || '';

    const originalBtn = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status"></span><span>Descifrando…</span>';
    }

    fetch(`/ethics/${caseId}/identity`, {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': token
        },
        body: form ? new FormData(form) : undefined
    })
    .then(async res => {
        let data = {};
        try {
            data = await res.json();
        } catch (_) {}
        if (!res.ok) {
            throw new Error(data.message || 'HTTP ' + res.status);
        }
        return data;
    })
    .then(data => {
        if (data.success && data.identity) {
            const wrap = document.getElementById('modalIdentityRevealWrapper');
            if (wrap) {
                const id = data.identity;
                wrap.innerHTML = `
                    <div class="ethics-identity-data-card">
                        <div class="d-flex align-items-center justify-content-between mb-3 pb-1 border-bottom">
                            <span class="small fw-bold text-primary d-flex align-items-center gap-2">
                                <i class="bi bi-person-vcard fs-6 me-1.5"></i>
                                <span>Datos Registrados del Colaborador</span>
                            </span>
                            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-2.5 py-1" style="font-size: 0.725rem; border-radius: 6px;">
                                <i class="bi bi-shield-lock me-1"></i>Confidencial · Autorizado
                            </span>
                        </div>
                        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem 1.25rem; font-size: 0.8125rem;">
                            <div>
                                <span class="text-secondary d-block small mb-1" style="font-size: 0.75rem;">Nombre Completo:</span>
                                <strong class="text-body d-block" style="font-size: 0.8125rem; font-weight: 600;">${Panel.esc(id.name || '—')}</strong>
                            </div>
                            <div>
                                <span class="text-secondary d-block small mb-1" style="font-size: 0.75rem;">Código Empleado:</span>
                                <strong class="text-body d-block" style="font-size: 0.8125rem; font-weight: 600;">${Panel.esc(id.employee_code || '—')}</strong>
                            </div>
                            <div>
                                <span class="text-secondary d-block small mb-1" style="font-size: 0.75rem;">N° Documento:</span>
                                <strong class="text-body d-block" style="font-size: 0.8125rem; font-weight: 600;">${Panel.esc(id.document ? (id.document.startsWith('DNI') ? id.document : 'DNI ' + id.document) : '—')}</strong>
                            </div>
                            <div>
                                <span class="text-secondary d-block small mb-1" style="font-size: 0.75rem;">Puesto / Cargo:</span>
                                <strong class="text-body d-block" style="font-size: 0.8125rem; font-weight: 600;">${Panel.esc(id.position || '—')}</strong>
                            </div>
                            <div>
                                <span class="text-secondary d-block small mb-1" style="font-size: 0.75rem;">Correo Electrónico:</span>
                                <strong class="text-body d-block" style="font-size: 0.8125rem; font-weight: 600; word-break: break-all;">${Panel.esc(id.email || '—')}</strong>
                            </div>
                            <div>
                                <span class="text-secondary d-block small mb-1" style="font-size: 0.75rem;">Teléfono:</span>
                                <strong class="text-body d-block" style="font-size: 0.8125rem; font-weight: 600;">${Panel.esc(id.phone || '—')}</strong>
                            </div>
                        </div>
                    </div>
                `;
            }
            const badgeHtml = `
                <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle px-2.5 py-1.5 fw-semibold d-inline-flex align-items-center gap-1.5" style="font-size: 0.75rem; border-radius: 6px;">
                    <i class="bi bi-shield-check me-1"></i>
                    <span>Identidad Revelada</span>
                </span>
            `;
            const btnWrap = document.getElementById('modalIdentityBtnWrapper');
            if (btnWrap) {
                btnWrap.innerHTML = badgeHtml;
            } else if (form) {
                form.outerHTML = badgeHtml;
            } else if (btn) {
                btn.outerHTML = badgeHtml;
            }
        } else {
            Panel.toast(data.message || 'No se pudo obtener la identidad.', 'error');
            if (btn) { btn.disabled = false; btn.innerHTML = originalBtn; }
        }
    })
    .catch(err => {
        Panel.toast('Error al consultar identidad: ' + err.message, 'error');
        if (btn) { btn.disabled = false; btn.innerHTML = originalBtn; }
    });
};

window.toggleModalResolutionField = function(caseId) {
    const select = document.getElementById('modalStatusSelect_' + caseId);
    const wrap = document.getElementById('modalResolutionWrap_' + caseId);
    if (select && wrap) {
        wrap.style.display = select.value === 'closed' ? 'block' : 'none';
    }
};

{{-- Copiar código de expediente con feedback visual --}}
window.copyEthicsCode = function(code, btn) {
    if (!navigator.clipboard) return;
    navigator.clipboard.writeText(code).then(() => {
        const original = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check2 text-success"></i> <span>¡Copiado!</span>';
        setTimeout(() => { btn.innerHTML = original; }, 2000);
    });
};

{{-- Lightbox modal de fotos adjuntas --}}
window.previewEthicsPhoto = function(url, caption) {
    const modalEl = document.getElementById('ethicsPhotoModal');
    const imgEl = document.getElementById('ethicsModalPhotoImg');
    const captionEl = document.getElementById('ethicsModalPhotoCaption');
    if (!modalEl || !imgEl) return;

    imgEl.src = url;
    if (captionEl) captionEl.textContent = caption || '';
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.show();
};
</script>
@endsection
@endsection
