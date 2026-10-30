@extends('layouts.app')

@section('title', 'Canal ético — Gestión de Denuncias')

@section('content')
<div class="ethics-page-wrapper">
    <header class="page-head">
        <div class="page-head-text">
            <h1 class="page-title">Canal ético</h1>
            <p class="page-subtitle">Recepción, investigación imparcial y seguimiento de denuncias. Confidencial y cifrado.</p>
            <div id="ethicsHeaderUnreadBadge" class="mt-2">
                @if(($counts['unread'] ?? 0) > 0)
                    <span class="status-pill tone-rose"><span class="ethics-unread-pulse"></span>{{ $counts['unread'] }} con mensajes nuevos</span>
                @endif
            </div>
        </div>
        <div class="page-actions">
            @php($ethicsTenant = $currentTenant ?? (app(\App\Modules\Core\Services\TenantContext::class)->resolved() ? app(\App\Modules\Core\Services\TenantContext::class)->get() : null))
            @if($ethicsTenant)
                <button type="button" class="btn btn-light border" onclick="copyPublicUrl('{{ route('ethics.public', $ethicsTenant->slug) }}', this)" title="Copiar el enlace del formulario web para colaboradores">
                    <i class="bi bi-clipboard me-1"></i>Copiar enlace público
                </button>
                <a href="{{ route('ethics.public', $ethicsTenant->slug) }}" target="_blank" rel="noopener noreferrer" class="btn btn-light border" title="Abrir el formulario web anónimo en una pestaña nueva">
                    <i class="bi bi-box-arrow-up-right me-1"></i>Abrir portal
                </a>
            @endif
        </div>
    </header>

    {{-- Zona que se actualiza por AJAX (bandeja, filtros y página) --}}
    <div id="ethicsDynamicWrapper">
    <section class="surface">
        <form method="GET" action="{{ route('ethics.index') }}" id="ethicsFilterForm" onsubmit="event.preventDefault(); applyEthicsFilter();">
            <input type="hidden" name="status" id="ethicsStatusInput" value="{{ $status }}">
            @if(request()->boolean('unread'))
                <input type="hidden" name="unread" id="ethicsUnreadInput" value="1">
            @endif
            <div class="filter-row justify-content-between">
                <div class="seg-tabs" role="tablist" aria-label="Bandeja">
                    @php($tabs = [
                        'open'       => ['label' => 'Abiertas', 'count' => $counts['open'] ?? 0],
                        'received'   => ['label' => 'Recibidas', 'count' => $counts['received'] ?? 0],
                        'in_review'  => ['label' => 'En investigación', 'count' => $counts['in_review'] ?? 0],
                        'needs_info' => ['label' => 'Esperan info', 'count' => $counts['needs_info'] ?? 0],
                        'closed'     => ['label' => 'Cerradas', 'count' => $counts['closed'] ?? 0],
                        'all'        => ['label' => 'Todas', 'count' => $counts['all'] ?? 0],
                    ])
                    @foreach($tabs as $tabKey => $tabData)
                        @php($isActive = $status === $tabKey && ! request()->boolean('unread'))
                        <a href="{{ route('ethics.index', ['status' => $tabKey] + request()->only('category', 'code', 'origin', 'assignee')) }}"
                           role="tab" class="seg-tab {{ $isActive ? 'is-active' : '' }}" aria-selected="{{ $isActive ? 'true' : 'false' }}"
                           onclick="switchEthicsStatus('{{ $tabKey }}', event)">
                            {{ $tabData['label'] }} <span class="count-pill {{ $isActive ? 'tone-brand' : '' }}">{{ $tabData['count'] }}</span>
                        </a>
                    @endforeach
                    @if(($counts['unread'] ?? 0) > 0)
                        <a href="{{ route('ethics.index', ['unread' => 1] + request()->only('category', 'code', 'origin', 'assignee')) }}"
                           role="tab" class="seg-tab {{ request()->boolean('unread') ? 'is-active' : '' }}" aria-selected="{{ request()->boolean('unread') ? 'true' : 'false' }}"
                           onclick="switchEthicsUnread(event)" title="Denuncias con mensajes nuevos sin leer">
                            Nuevas sin leer <span class="count-pill tone-rose">{{ $counts['unread'] }}</span>
                        </a>
                    @endif
                </div>
                <div class="d-flex align-items-center gap-2 small text-body-secondary">
                    @if(request()->hasAny(['code', 'category', 'origin', 'assignee', 'unread']) || $status !== 'open')
                        <a href="{{ route('ethics.index') }}" onclick="resetEthicsFilters(event)" class="btn btn-sm btn-link text-decoration-none"><i class="bi bi-x-circle me-1"></i>Limpiar filtros</a>
                    @endif
                    <span>{{ $cases->total() }} expediente(s)</span>
                </div>
            </div>
            <div class="filter-row">
                <div class="search-field">
                    <i class="bi bi-search"></i>
                    <input type="search" name="code" id="ethicsSearchCode" value="{{ request('code') }}" placeholder="Buscar por código (ej. 490081)…" aria-label="Buscar por código"
                           class="form-control" autocomplete="off" oninput="debounceEthicsSearch()">
                </div>
                <select name="category" id="ethicsFilterCategory" class="form-select w-auto" onchange="applyEthicsFilter()" aria-label="Filtrar por categoría">
                    <option value="">Todas las categorías</option>
                    @foreach(\App\Modules\Ethics\Models\EthicsCase::CATEGORIES as $k => $text)
                        <option value="{{ $k }}" @selected(request('category') === $k)>{{ $text }}</option>
                    @endforeach
                </select>
                <select name="origin" id="ethicsFilterOrigin" class="form-select w-auto" onchange="applyEthicsFilter()" aria-label="Filtrar por canal de origen">
                    <option value="">Cualquier origen</option>
                    <option value="web" @selected(request('origin') === 'web')>Web 100% anónima</option>
                    <option value="app" @selected(request('origin') === 'app')>App móvil (identidad reservada)</option>
                </select>
                <select name="assignee" id="ethicsFilterAssignee" class="form-select w-auto" onchange="applyEthicsFilter()" aria-label="Filtrar por investigador">
                    <option value="">Todos los investigadores</option>
                    <option value="unassigned" @selected(request('assignee') === 'unassigned')>Sin asignar</option>
                    @foreach($investigators as $inv)
                        <option value="{{ $inv->id }}" @selected(request('assignee') == $inv->id)>{{ $inv->first_name }} {{ $inv->last_name }}</option>
                    @endforeach
                </select>
            </div>
        </form>

        @php($showCompany = ($globalView ?? false) || ! $currentTenant)
        @php($toneMap = ['primary' => 'brand', 'success' => 'emerald', 'warning' => 'amber', 'danger' => 'rose', 'info' => 'blue', 'secondary' => 'neutral', 'dark' => 'neutral'])
        <div class="table-responsive">
            <table class="table data-table table-cards align-middle mb-0 ethics-table">
                <thead>
                    <tr>
                        <th>Expediente</th>
                        <th>Categoría</th>
                        <th>Origen</th>
                        @if($showCompany)<th>Empresa</th>@endif
                        <th>Investigador</th>
                        <th>Recepción</th>
                        <th>Estado</th>
                        <th class="text-end">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($cases as $c)
                        <tr class="ethics-row {{ $c->investigator_unread ? 'row-unread' : '' }}" data-case-id="{{ $c->id }}">
                            <td class="cell-main">
                                <div class="d-flex align-items-center gap-2">
                                    <a href="#" class="cell-code text-decoration-none" onclick="event.preventDefault(); openEthicsQuickModal({{ $c->id }})" title="Ver expediente #{{ $c->public_code }}">#{{ $c->public_code }}</a>
                                    @if($c->investigator_unread)
                                        <span class="status-pill tone-rose"><span class="ethics-unread-pulse"></span>Nuevo</span>
                                    @endif
                                </div>
                            </td>
                            <td data-label="Categoría">
                                <span class="status-pill tone-{{ $toneMap[$c->categoryTone()] ?? 'neutral' }}"><i class="bi bi-{{ $c->categoryIcon() }}"></i>{{ $c->categoryLabel() }}</span>
                            </td>
                            <td data-label="Origen">
                                <span class="status-pill tone-{{ $c->isFromApp() ? 'blue' : 'neutral' }}" title="{{ $c->originLabel() }}"><i class="bi bi-{{ $c->isFromApp() ? 'person-lock' : 'incognito' }}"></i>{{ $c->isFromApp() ? 'App móvil' : 'Web anónima' }}</span>
                            </td>
                            @if($showCompany)
                                <td data-label="Empresa">
                                    <span class="text-truncate" title="{{ $c->tenant?->company_name ?? 'Empresa' }}">{{ $c->tenant?->company_name ?? 'Empresa' }}</span>
                                </td>
                            @endif
                            <td data-label="Investigador">
                                @if($c->assignee)
                                    <span class="text-truncate">{{ $c->assignee->first_name }} {{ $c->assignee->last_name }}</span>
                                @else
                                    <span class="status-pill tone-amber">Sin asignar</span>
                                @endif
                            </td>
                            <td class="text-nowrap" data-label="Recepción">
                                <span>{{ $c->reported_on->format('d/m/Y') }}</span>
                            </td>
                            <td data-label="Estado">
                                <x-status-badge type="ethics_status" :value="$c->status" />
                            </td>
                            <td>
                                <div class="row-actions">
                                    <button type="button" class="icon-btn" onclick="openEthicsQuickModal({{ $c->id }})" title="Ver denuncia #{{ $c->public_code }}" aria-label="Ver denuncia #{{ $c->public_code }}">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr class="row-empty">
                            <td colspan="{{ $showCompany ? 8 : 7 }}">
                                <div class="dash-empty">
                                    <span class="dash-empty-icon"><i class="bi bi-shield-check"></i></span>
                                    <p class="dash-empty-title">No hay denuncias en esta bandeja</p>
                                    <p class="dash-empty-text">No hay expedientes que coincidan con los filtros, o el canal está al día.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($cases->hasPages())
            <footer class="surface-foot">
                <div class="small text-body-secondary">
                    Mostrando del <strong>{{ $cases->firstItem() }}</strong> al <strong>{{ $cases->lastItem() }}</strong> de <strong>{{ $cases->total() }}</strong> expedientes
                </div>
                <div>{{ $cases->links() }}</div>
            </footer>
        @endif
    </section>
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
        btn.classList.remove('btn-light');
        btn.classList.add('btn-success');
        btn.innerHTML = '<i class="bi bi-check2 me-1"></i>¡Copiado!';
        setTimeout(() => {
            btn.innerHTML = originalHtml;
            btn.classList.remove('btn-success');
            btn.classList.add('btn-light');
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
