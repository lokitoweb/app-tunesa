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

{{-- Header Ejecutivo con Título, Subtítulo, Contador y Acción de Configuración --}}
<div class="onb-header-bar">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1">
            <h1 class="h3 fw-bold mb-0 text-body">Incorporación</h1>
            @if($submittedCount > 0)
                <span class="badge rounded-pill bg-danger-subtle text-danger border border-danger-subtle px-2.5 py-1 d-inline-flex align-items-center" style="font-size: 0.775rem; font-weight: 700;">
                    <i class="bi bi-bell-fill" style="margin-right: 6px;"></i>
                    <span>{{ $submittedCount }} por revisar</span>
                </span>
            @else
                <span class="badge rounded-pill bg-success-subtle text-success border border-success-subtle px-2.5 py-1 d-inline-flex align-items-center" style="font-size: 0.775rem; font-weight: 600;">
                    <i class="bi bi-check-circle" style="margin-right: 6px;"></i>
                    <span>Al día</span>
                </span>
            @endif
        </div>
        <p class="text-secondary small mb-0">Nadie marca asistencia hasta que apruebes su incorporación: datos, tallas, documentos e inducción.</p>
    </div>
    @can('onboarding.manage')
        <button type="button" 
                class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center shadow-sm onb-settings-btn" 
                style="border-radius: 8px; font-weight: 600; padding: 7px 15px; gap: 8px;"
                data-bs-toggle="modal"
                data-bs-target="#onboardingSettingsModal"
                onclick="openOnboardingSettingsModal()">
            <i class="bi bi-sliders"></i>
            <span>Configurar etapas</span>
        </button>
    @endcan
</div>

{{-- Contenedor Reactivo Dinámico AJAX (Actualización Fluida sin Recargas ni Parámetros Expuestos en URL) --}}
<div id="onboardingDynamicWrapper" class="onb-dynamic-area">
    {{-- Barra de Navegación Segmentada y Buscador Integrado --}}
    <div class="onb-controls-bar">
        <div class="onb-tab-group" role="tablist">
            @foreach($tabLabels as $key => $text)
                <button type="button" 
                        class="onb-tab-btn {{ $status === $key ? 'active' : '' }}" 
                        data-status="{{ $key }}" 
                        onclick="switchOnbStatus('{{ $key }}')"
                        @if($status === $key) aria-current="page" @endif>
                    <span>{{ $text }}</span>
                    @php $cnt = (int) ($counts[$key] ?? 0); @endphp
                    @if($key === 'submitted' && $cnt > 0)
                        <span class="onb-counter-pill onb-counter-pill-alert">{{ $cnt }}</span>
                    @elseif($cnt > 0)
                        <span class="onb-counter-pill onb-counter-pill-subtle">{{ $cnt }}</span>
                    @endif
                </button>
            @endforeach
        </div>

        <div class="onb-search-box">
            <i class="bi bi-search onb-search-icon"></i>
            <input type="search" 
                   id="onbSearchInput" 
                   class="onb-search-input" 
                   placeholder="Nombre o DNI..." 
                   value="{{ request('search') }}"
                   autocomplete="off"
                   onkeydown="if(event.key === 'Enter') { event.preventDefault(); submitOnbSearch(); }">
            @if(request('search'))
                <button type="button" class="onb-search-clear" onclick="clearOnbSearch()" title="Limpiar búsqueda">
                    <i class="bi bi-x-circle-fill"></i>
                </button>
            @endif
        </div>
    </div>

    {{-- Tarjeta Ejecutiva con Tabla de Incorporaciones --}}
    <div class="onb-card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th class="ps-4">Colaborador</th>
                        <th>Etapas</th>
                        <th>Estado</th>
                        <th>Enviada</th>
                        <th class="text-end pe-4">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($processes as $p)
                        @php 
                            $evaluated = collect($stages[$p->id] ?? [])->where('status', '!=', 'not_applicable');
                            $user = $p->user;
                        @endphp
                        <tr>
                            <td class="ps-4">
                                <div class="d-flex align-items-center gap-3">
                                    @if(!empty($user->avatar_path))
                                        <img src="{{ asset('storage/' . $user->avatar_path) }}" alt="{{ $user->first_name }}" class="attendance-user-avatar">
                                    @else
                                        <span class="attendance-user-initials">
                                            {{ mb_substr($user->first_name ?? 'U', 0, 1) }}{{ mb_substr($user->last_name ?? '', 0, 1) }}
                                        </span>
                                    @endif
                                    <div class="min-w-0">
                                        <div class="fw-bold text-body text-truncate" style="font-size: 0.875rem;">
                                            {{ $user->full_name }}
                                        </div>
                                        <div class="small text-secondary text-truncate" style="font-size: 0.775rem;">
                                            {{ label('id_document_type', $user->document_type) }} {{ $user->document_number }} · {{ $user->branches->firstWhere('pivot.is_primary', true)?->name ?? 'Sin sucursal' }}
                                        </div>
                                        @include('partials.tenant-badge', ['tenantId' => $p->tenant_id])
                                    </div>
                                </div>
                            </td>
                            <td>
                                @if($p->isApproved())
                                    <span class="onb-stage-completed-all">
                                        <i class="bi bi-check-circle-fill"></i>
                                        <span>Completas</span>
                                    </span>
                                @else
                                    <div class="d-flex flex-wrap gap-1 align-items-center">
                                        @foreach($evaluated as $s)
                                            <span class="onb-stage-badge {{ $s['status'] === 'completed' ? 'onb-stage-completed' : 'onb-stage-pending' }}" 
                                                  title="{{ $s['status'] === 'completed' ? 'Completa' : 'Falta: ' . implode(', ', $s['missing']) }}">
                                                <i class="bi {{ $s['status'] === 'completed' ? 'bi-check-circle-fill' : 'bi-hourglass-split' }}"></i>
                                                <span>{{ $s['title'] }}</span>
                                            </span>
                                        @endforeach
                                    </div>
                                @endif
                            </td>
                            <td>
                                <x-status-badge type="onboarding_status" :value="$p->status" />
                            </td>
                            <td class="text-nowrap">
                                @if($p->submitted_at)
                                    <div class="fw-semibold text-body" style="font-size: 0.825rem;">
                                        <i class="bi bi-clock-history text-muted" style="margin-right: 6px;"></i>{{ $p->submitted_at->format('d/m/Y') }}
                                    </div>
                                    <div class="text-secondary small" style="font-size: 0.75rem;">
                                        {{ $p->submitted_at->format('h:i a') }}
                                    </div>
                                @else
                                    <span class="text-muted small">—</span>
                                @endif
                            </td>
                            <td class="text-end pe-4">
                                <button type="button" 
                                   class="btn btn-sm {{ $p->status === 'submitted' ? 'btn-primary' : 'btn-outline-secondary' }} d-inline-flex align-items-center shadow-sm"
                                   style="border-radius: 6px; font-weight: 600; padding: 6px 14px; font-size: 0.8rem; gap: 8px;"
                                   data-bs-toggle="modal"
                                   data-bs-target="#onboardingModal"
                                   onclick="openOnboardingModal({{ $p->user_id }})">
                                    <i class="bi {{ $p->status === 'submitted' ? 'bi-shield-check' : 'bi-eye' }}"></i>
                                    <span>{{ $p->status === 'submitted' ? 'Revisar' : 'Ver' }}</span>
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="py-5 text-center">
                                <div class="empty-state py-4">
                                    <div style="width: 54px; height: 54px; border-radius: 12px; background: var(--bg-surface-elevated); color: var(--text-muted); display: inline-flex; align-items: center; justify-content: center; font-size: 1.6rem; margin-bottom: 1rem; border: 1px solid var(--border-color);">
                                        <i class="bi bi-person-check"></i>
                                    </div>
                                    <div class="fw-bold text-body fs-6">No hay incorporaciones en esta bandeja.</div>
                                    <div class="text-secondary small mt-1" style="max-width: 420px; margin: 0 auto;">No se encontraron colaboradores en proceso de incorporación para el filtro o término de búsqueda seleccionado.</div>
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
    </div>
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
