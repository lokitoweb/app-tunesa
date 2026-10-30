@extends('layouts.app')

@section('title', 'Incorporación')

@section('content')
@php
    $tabLabels = [
        'submitted' => 'Por revisar',
        'pending'   => 'En proceso',
        'rejected'  => 'Observadas',
        'approved'  => 'Aprobadas',
        'all'       => 'Todas'
    ];
    $submittedCount = (int) ($counts['submitted'] ?? 0);
@endphp

<header class="page-head">
    <div class="page-head-text">
        <h1 class="page-title">Incorporación</h1>
        <p class="page-subtitle">Nadie marca asistencia hasta que apruebes su incorporación: datos, tallas, documentos e inducción.</p>
    </div>
    <div class="page-actions">
        @if($submittedCount > 0)
            <span class="status-pill tone-rose"><i class="bi bi-bell"></i>{{ $submittedCount }} por revisar</span>
        @else
            <span class="status-pill tone-emerald"><i class="bi bi-check-circle"></i>Al día</span>
        @endif
        @can('onboarding.manage')
            <button type="button" class="btn btn-light border" data-bs-toggle="modal" data-bs-target="#onboardingSettingsModal" onclick="openOnboardingSettingsModal()">
                <i class="bi bi-sliders me-1"></i>Configurar etapas
            </button>
        @endcan
    </div>
</header>

{{-- Zona que se actualiza por AJAX (bandeja, búsqueda y página) sin cambiar la URL --}}
<div id="onboardingDynamicWrapper" class="onb-dynamic-area">
<section class="surface">
    <div class="filter-row justify-content-between">
        <div class="seg-tabs" role="tablist" aria-label="Bandeja">
            @foreach($tabLabels as $key => $text)
                @php $cnt = (int) ($counts[$key] ?? 0); @endphp
                <button type="button" role="tab" class="seg-tab {{ $status === $key ? 'is-active' : '' }}" data-status="{{ $key }}" aria-selected="{{ $status === $key ? 'true' : 'false' }}" onclick="switchOnbStatus('{{ $key }}')">
                    {{ $text }}
                    @if($cnt > 0)<span class="count-pill {{ $key === 'submitted' ? 'tone-rose' : '' }}">{{ $cnt }}</span>@endif
                </button>
            @endforeach
        </div>
        <div class="search-field">
            <i class="bi bi-search"></i>
            <input type="search" id="onbSearchInput" class="form-control" placeholder="Nombre o DNI…" aria-label="Buscar por nombre o DNI"
                   value="{{ request('search') }}" autocomplete="off"
                   onkeydown="if(event.key === 'Enter') { event.preventDefault(); submitOnbSearch(); }">
        </div>
        @if(request('search'))
            <button type="button" class="btn btn-sm btn-link text-decoration-none" onclick="clearOnbSearch()"><i class="bi bi-x-circle me-1"></i>Limpiar búsqueda</button>
        @endif
    </div>

    <div class="table-responsive">
        <table class="table data-table table-cards align-middle mb-0">
            <thead>
                <tr>
                    <th>Colaborador</th>
                    <th>Etapas</th>
                    <th>Estado</th>
                    <th>Enviada</th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody>
                @forelse($processes as $p)
                    @php
                        $evaluated = collect($stages[$p->id] ?? [])->where('status', '!=', 'not_applicable');
                        $user = $p->user;
                    @endphp
                    <tr>
                        <td class="cell-main">
                            <div class="d-flex align-items-center gap-3">
                                @if(!empty($user->avatar_path))
                                    <img src="{{ asset('storage/' . $user->avatar_path) }}" alt="{{ $user->first_name }}" class="attendance-user-avatar">
                                @else
                                    <span class="attendance-user-initials">{{ mb_substr($user->first_name ?? 'U', 0, 1) }}{{ mb_substr($user->last_name ?? '', 0, 1) }}</span>
                                @endif
                                <div class="min-w-0">
                                    <div class="cell-title text-truncate">{{ $user->full_name }}</div>
                                    <div class="cell-sub text-truncate">{{ label('id_document_type', $user->document_type) }} {{ $user->document_number }} · {{ $user->branches->firstWhere('pivot.is_primary', true)?->name ?? 'Sin sucursal' }}</div>
                                    @include('partials.tenant-badge', ['tenantId' => $p->tenant_id])
                                </div>
                            </div>
                        </td>
                        <td data-label="Etapas">
                            @if($p->isApproved())
                                <span class="status-pill tone-emerald"><i class="bi bi-check-circle"></i>Completas</span>
                            @else
                                <div class="d-flex flex-wrap gap-1 align-items-center">
                                    @foreach($evaluated as $s)
                                        <span class="status-pill {{ $s['status'] === 'completed' ? 'tone-emerald' : 'tone-amber' }}" title="{{ $s['status'] === 'completed' ? 'Completa' : 'Falta: ' . implode(', ', $s['missing']) }}">
                                            <i class="bi {{ $s['status'] === 'completed' ? 'bi-check-circle' : 'bi-hourglass-split' }}"></i>{{ $s['title'] }}
                                        </span>
                                    @endforeach
                                </div>
                            @endif
                        </td>
                        <td data-label="Estado">
                            <x-status-badge type="onboarding_status" :value="$p->status" />
                        </td>
                        <td class="text-nowrap" data-label="Enviada">
                            @if($p->submitted_at)
                                <div>
                                    <div class="cell-title">{{ $p->submitted_at->format('d/m/Y') }}</div>
                                    <div class="cell-sub">{{ $p->submitted_at->format('h:i a') }}</div>
                                </div>
                            @else
                                <span class="cell-sub">—</span>
                            @endif
                        </td>
                        <td>
                            <div class="row-actions">
                                <button type="button" class="btn btn-sm {{ $p->status === 'submitted' ? 'btn-primary' : 'btn-light border' }}"
                                        data-bs-toggle="modal" data-bs-target="#onboardingModal" onclick="openOnboardingModal({{ $p->user_id }})">
                                    <i class="bi {{ $p->status === 'submitted' ? 'bi-shield-check' : 'bi-eye' }} me-1"></i>{{ $p->status === 'submitted' ? 'Revisar' : 'Ver' }}
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr class="row-empty">
                        <td colspan="5">
                            <div class="dash-empty">
                                <span class="dash-empty-icon"><i class="bi bi-person-check"></i></span>
                                <p class="dash-empty-title">No hay incorporaciones en esta bandeja</p>
                                <p class="dash-empty-text">No se encontraron colaboradores en proceso de incorporación para este filtro o búsqueda.</p>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

        @if($processes->total() > 0)
            <div class="table-pagination-footer">
                <div class="table-pagination-info">
                    <span>
                        Mostrando <strong>{{ $processes->firstItem() }}</strong> a <strong>{{ $processes->lastItem() }}</strong> de <strong>{{ $processes->total() }}</strong> incorporaciones
                    </span>
                    <div class="per-page-selector ms-sm-2">
                        <label for="onbPerPageSelect" class="text-secondary small mb-0">Mostrar:</label>
                        <select id="onbPerPageSelect" class="form-select form-select-sm" onchange="changeOnbPerPage(this.value)">
                            <option value="10" @selected($processes->perPage() == 10)>10</option>
                            <option value="20" @selected($processes->perPage() == 20)>20</option>
                            <option value="50" @selected($processes->perPage() == 50)>50</option>
                            <option value="100" @selected($processes->perPage() == 100)>100</option>
                        </select>
                    </div>
                </div>

                <div class="d-flex align-items-center gap-2">
                    @if($processes->hasPages())
                        {{ $processes->links() }}
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
                        Pág. {{ $processes->currentPage() }} de {{ $processes->lastPage() }}
                    </span>
                </div>
            </div>
        @endif
</section>
</div>

{{-- Modal Diálogo Ejecutivo de Detalle y Acciones de Incorporación --}}
<div class="modal fade" id="onboardingModal" tabindex="-1" aria-labelledby="onbModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content" id="onboardingModalContent">
            {{-- Cargado dinámicamente vía AJAX --}}
        </div>
    </div>
</div>

{{-- Modal Diálogo Ejecutivo de Configuración de Etapas --}}
<div class="modal fade" id="onboardingSettingsModal" tabindex="-1" aria-labelledby="onbSettingsModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content" id="onboardingSettingsModalContent">
            {{-- Cargado dinámicamente vía AJAX --}}
        </div>
    </div>
</div>

<script>
{{-- Estado reactivo interno de Incorporación (Actualización Asíncrona sin exposición en URL) --}}
let currentOnbState = {
    status: @js($status),
    search: @js(request('search', '')),
    per_page: @js((string) $processes->perPage()),
    page: @js($processes->currentPage())
};

let onbAbortController = null;

async function loadOnboarding(overrides = {}) {
    Object.assign(currentOnbState, overrides);

    const wrapper = document.getElementById('onboardingDynamicWrapper');
    if (wrapper) {
        wrapper.classList.add('is-loading');
    }

    if (onbAbortController) {
        onbAbortController.abort();
    }
    onbAbortController = new AbortController();

    try {
        const url = new URL('{{ route("onboarding.index") }}', window.location.origin);
        if (currentOnbState.status) url.searchParams.set('status', currentOnbState.status);
        if (currentOnbState.search) url.searchParams.set('search', currentOnbState.search);
        if (currentOnbState.per_page) url.searchParams.set('per_page', currentOnbState.per_page);
        if (currentOnbState.page) url.searchParams.set('page', currentOnbState.page);

        const response = await fetch(url.toString(), {
            signal: onbAbortController.signal,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'text/html'
            }
        });

        if (!response.ok) throw new Error('Error al cargar datos');

        const html = await response.text();
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');

        const newDynamicArea = doc.getElementById('onboardingDynamicWrapper');
        if (newDynamicArea && wrapper) {
            wrapper.innerHTML = newDynamicArea.innerHTML;
            attachOnbPaginationInterceptors();
        }

        {{-- Mantener la URL del navegador limpia sin exponer parámetros internos --}}
        window.history.replaceState({}, '', '{{ route("onboarding.index") }}');
    } catch (err) {
        if (err.name !== 'AbortError') {
            console.error('Error al actualizar incorporación:', err);
        }
    } finally {
        if (wrapper) {
            wrapper.classList.remove('is-loading');
        }
    }
}

function switchOnbStatus(newStatus) {
    if (currentOnbState.status === newStatus) return;
    loadOnboarding({ status: newStatus, page: 1 });
}

function submitOnbSearch() {
    const input = document.getElementById('onbSearchInput');
    const term = input ? input.value.trim() : '';
    loadOnboarding({ search: term, page: 1 });
}

function clearOnbSearch() {
    const input = document.getElementById('onbSearchInput');
    if (input) input.value = '';
    loadOnboarding({ search: '', page: 1 });
}

function changeOnbPerPage(newPerPage) {
    loadOnboarding({ per_page: newPerPage, page: 1 });
}

function attachOnbPaginationInterceptors() {
    const container = document.getElementById('onboardingDynamicWrapper');
    if (!container) return;

    container.querySelectorAll('.pagination a.page-link').forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const href = this.getAttribute('href');
            if (!href) return;
            try {
                const targetUrl = new URL(href, window.location.origin);
                const pageParam = targetUrl.searchParams.get('page');
                if (pageParam) {
                    loadOnboarding({ page: parseInt(pageParam, 10) });
                }
            } catch (err) {
                console.error('Error interpretando paginador:', err);
            }
        });
    });
}

window.openOnboardingModal = async function(userId) {
    const modalEl = document.getElementById('onboardingModal');
    const contentEl = document.getElementById('onboardingModalContent');
    if (!modalEl || !contentEl) return;

    if (modalEl.parentElement !== document.body) {
        document.body.appendChild(modalEl);
    }

    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);

    contentEl.innerHTML = `
        <div class="p-5 text-center">
            <div class="spinner-border text-primary mb-3" role="status" style="width: 2.2rem; height: 2.2rem;">
                <span class="visually-hidden">Cargando...</span>
            </div>
            <div class="text-secondary small fw-medium">Cargando detalle de incorporación...</div>
        </div>
    `;

    modal.show();

    try {
        const showUrl = '{{ url("onboarding") }}/' + userId;
        const response = await fetch(showUrl, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'text/html'
            }
        });

        if (!response.ok) throw new Error('Error al obtener datos');

        const html = await response.text();
        contentEl.innerHTML = html;
    } catch (err) {
        contentEl.innerHTML = `
            <div class="modal-header">
                <h5 class="modal-title text-danger"><i class="bi bi-exclamation-triangle me-2"></i>Error</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body text-center py-4">
                <p class="text-secondary mb-0">No se pudo cargar la información de la incorporación. Por favor, reintenta.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cerrar</button>
            </div>
        `;
        console.error('Error cargando modal de incorporación:', err);
    }
};

document.addEventListener('DOMContentLoaded', () => {
    const modalEl = document.getElementById('onboardingModal');
    if (modalEl && modalEl.parentElement !== document.body) {
        document.body.appendChild(modalEl);
    }
    const settingsModalEl = document.getElementById('onboardingSettingsModal');
    if (settingsModalEl && settingsModalEl.parentElement !== document.body) {
        document.body.appendChild(settingsModalEl);
    }
    attachOnbPaginationInterceptors();
});

window.openOnboardingSettingsModal = async function(tenantId = null) {
    const modalEl = document.getElementById('onboardingSettingsModal');
    const contentEl = document.getElementById('onboardingSettingsModalContent');
    if (!modalEl || !contentEl) return;

    if (modalEl.parentElement !== document.body) {
        document.body.appendChild(modalEl);
    }

    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);

    contentEl.innerHTML = `
        <div class="p-5 text-center">
            <div class="spinner-border text-primary mb-3" role="status" style="width: 2.2rem; height: 2.2rem;">
                <span class="visually-hidden">Cargando...</span>
            </div>
            <div class="text-secondary small fw-medium">Cargando configuración de etapas...</div>
        </div>
    `;

    modal.show();
    await loadOnboardingSettingsModal(tenantId);
};

window.loadOnboardingSettingsModal = async function(tenantId = null) {
    const contentEl = document.getElementById('onboardingSettingsModalContent');
    if (!contentEl) return;

    contentEl.innerHTML = `
        <div class="p-5 text-center">
            <div class="spinner-border text-primary mb-3" role="status" style="width: 2.2rem; height: 2.2rem;">
                <span class="visually-hidden">Cargando...</span>
            </div>
            <div class="text-secondary small fw-medium">Cargando configuración de etapas...</div>
        </div>
    `;

    try {
        let settingsUrl = '{{ route("onboarding.settings") }}';
        if (tenantId) {
            settingsUrl += '?tenant_id=' + tenantId;
        }

        const response = await fetch(settingsUrl, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'text/html'
            }
        });

        if (!response.ok) throw new Error('Error al cargar configuración');

        const html = await response.text();
        contentEl.innerHTML = html;
    } catch (err) {
        contentEl.innerHTML = `
            <div class="modal-header">
                <h5 class="modal-title text-danger"><i class="bi bi-exclamation-triangle me-2"></i>Error</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body text-center py-4">
                <p class="text-secondary mb-0">No se pudo cargar la configuración de etapas. Por favor, reintenta.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cerrar</button>
            </div>
        `;
        console.error('Error cargando modal de configuración:', err);
    }
};
</script>
@endsection
