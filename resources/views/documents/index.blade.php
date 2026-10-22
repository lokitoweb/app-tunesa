@extends('layouts.app')

@section('title', 'Legajos Digitales')

@section('content')
@php
    use App\Modules\HR\Models\EmployeeDocument;
    $tabs = [
        'pending'  => ['label' => 'Por revisar', 'icon' => 'bi-hourglass-split', 'count' => $counts['pending'] ?? 0],
        'approved' => ['label' => 'Aprobados',   'icon' => 'bi-check-circle-fill', 'count' => $counts['approved'] ?? 0],
        'rejected' => ['label' => 'Rechazados',  'icon' => 'bi-x-circle-fill', 'count' => $counts['rejected'] ?? 0],
        'all'      => ['label' => 'Todos',        'icon' => 'bi-collection-fill', 'count' => array_sum($counts->all() ?? [])],
    ];
@endphp

<div class="documents-page-wrapper">
    <!-- =========================================================================
         1. CABECERA EJECUTIVA DEL MÓDULO
         ========================================================================= -->
    <div class="documents-header-card p-4 mb-4 shadow-sm">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3">
            <div class="d-flex align-items-center gap-3">
                <div class="documents-header-icon shadow-sm">
                    <i class="bi bi-folder-check"></i>
                </div>
                <div>
                    <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                        <h1 class="h4 fw-bold mb-0 text-body">Legajos Digitales</h1>
                        <span class="badge rounded-pill bg-primary-subtle text-primary border border-primary-subtle px-2 py-1 small">
                            <i class="bi bi-shield-check me-1"></i>Verificación RRHH
                        </span>
                    </div>
                    <p class="text-body-secondary small mb-0">
                        Bandeja de revisión y validación de documentos cargados por colaboradores desde la app móvil.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- =========================================================================
         2. TARJETAS DE INDICADORES (KPIS EJECUTIVOS)
         ========================================================================= -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="documents-kpi-card p-3 shadow-sm h-100">
                <div class="d-flex align-items-center gap-3">
                    <div class="documents-kpi-pill bg-warning-subtle text-warning-emphasis">
                        <i class="bi bi-hourglass-split"></i>
                    </div>
                    <div>
                        <div class="text-body-secondary small fw-medium text-uppercase" style="letter-spacing: 0.05em; font-size: 0.72rem;">
                            Por revisar
                        </div>
                        <div class="fs-4 fw-bold text-body lh-1 mt-1">{{ $counts['pending'] ?? 0 }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-lg-3">
            <div class="documents-kpi-card p-3 shadow-sm h-100">
                <div class="d-flex align-items-center gap-3">
                    <div class="documents-kpi-pill bg-success-subtle text-success">
                        <i class="bi bi-check-circle-fill"></i>
                    </div>
                    <div>
                        <div class="text-body-secondary small fw-medium text-uppercase" style="letter-spacing: 0.05em; font-size: 0.72rem;">
                            Aprobados
                        </div>
                        <div class="fs-4 fw-bold text-body lh-1 mt-1">{{ $counts['approved'] ?? 0 }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-lg-3">
            <div class="documents-kpi-card p-3 shadow-sm h-100">
                <div class="d-flex align-items-center gap-3">
                    <div class="documents-kpi-pill bg-danger-subtle text-danger">
                        <i class="bi bi-x-circle-fill"></i>
                    </div>
                    <div>
                        <div class="text-body-secondary small fw-medium text-uppercase" style="letter-spacing: 0.05em; font-size: 0.72rem;">
                            Rechazados
                        </div>
                        <div class="fs-4 fw-bold text-body lh-1 mt-1">{{ $counts['rejected'] ?? 0 }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-lg-3">
            <div class="documents-kpi-card p-3 shadow-sm h-100">
                <div class="d-flex align-items-center gap-3">
                    <div class="documents-kpi-pill bg-info-subtle text-info">
                        <i class="bi bi-calendar-event-fill"></i>
                    </div>
                    <div>
                        <div class="text-body-secondary small fw-medium text-uppercase" style="letter-spacing: 0.05em; font-size: 0.72rem;">
                            Vencen en 30 días
                        </div>
                        <div class="fs-4 fw-bold lh-1 mt-1 {{ $expiring->count() ? 'text-warning' : 'text-body' }}">
                            {{ $expiring->count() }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- =========================================================================
         3. BARRA DE FILTROS 100% INTERNA EN UNA SOLA LÍNEA HORIZONTAL
         ========================================================================= -->
    <div class="documents-filter-card p-3 mb-4 shadow-sm">
        <div class="d-flex flex-wrap flex-xl-nowrap align-items-center justify-content-between gap-3">
            <!-- Segmented Control de Estados -->
            <div class="training-segmented-bar flex-shrink-0" role="tablist" id="statusTabs">
                @foreach($tabs as $key => $tab)
                    <button
                        type="button"
                        class="training-segmented-item {{ $status === $key ? 'is-active' : '' }}"
                        data-status="{{ $key }}"
                    >
                        <i class="bi {{ $tab['icon'] }}" style="margin-right: 6px !important;"></i>
                        <span>{{ $tab['label'] }}</span>
                        <span class="training-segmented-pill">{{ $tab['count'] }}</span>
                    </button>
                @endforeach
            </div>

            <!-- Filtros Complementarios: Tipo, Buscador y Limpiar EN UNA SOLA LÍNEA HORIZONTAL -->
            <div class="d-flex align-items-center flex-nowrap" style="gap: 12px; min-width: 0;">
                <!-- Selector de Tipo -->
                <div class="modern-input-wrapper flex-shrink-0" style="width: 210px; min-width: 175px;">
                    <span class="modern-input-icon"><i class="bi bi-files text-primary"></i></span>
                    <select id="docTypeSelect" class="modern-input-control" aria-label="Filtrar por tipo de documento">
                        <option value="">Todos los tipos</option>
                        @foreach(EmployeeDocument::TYPES as $key => $label)
                            <option value="{{ $key }}" @selected(request('type') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <!-- Buscador en tiempo real -->
                <div class="modern-input-wrapper flex-shrink-0" style="width: 280px; min-width: 210px;">
                    <span class="modern-input-icon"><i class="bi bi-search"></i></span>
                    <input
                        id="docSearchInput"
                        type="search"
                        class="modern-input-control"
                        placeholder="Buscar por nombre, DNI o código..."
                        value="{{ request('search') }}"
                        aria-label="Buscar colaborador"
                    >
                    <button
                        id="docSearchClearBtn"
                        type="button"
                        class="btn btn-sm text-secondary p-0 me-2 border-0 d-none"
                        style="width: 22px; height: 22px;"
                        title="Limpiar búsqueda"
                    >
                        <i class="bi bi-x-circle-fill"></i>
                    </button>
                </div>

                <!-- Botón Limpiar Filtros -->
                <button
                    id="docResetBtn"
                    type="button"
                    class="btn btn-outline-secondary px-3 py-1 fw-medium d-inline-flex align-items-center flex-shrink-0 text-nowrap"
                    style="border-radius: 8px; height: 38px;"
                    title="Restablecer filtros"
                >
                    <i class="bi bi-arrow-counterclockwise" style="margin-right: 6px !important;"></i>
                    <span>Limpiar</span>
                </button>
            </div>
        </div>
    </div>

    <!-- =========================================================================
         4. CONTENEDOR DE TABLA (ACTUALIZADO DINÁMICAMENTE POR AJAX)
         ========================================================================= -->
    <div class="documents-table-card shadow-sm mb-4" id="documentsTableContainer">
        @include('documents.partials.table', ['documents' => $documents, 'status' => $status])
    </div>

    <!-- =========================================================================
         5. DOCUMENTOS POR VENCER
         ========================================================================= -->
    @if($expiring->count())
        <div class="documents-section-card shadow-sm mb-4 p-4">
            <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
                <h2 class="h6 fw-bold mb-0 text-body d-flex align-items-center">
                    <i class="bi bi-clock-history me-2 text-warning fs-5"></i>
                    Documentos Próximos a Vencer (30 días)
                </h2>
                <span class="badge bg-warning-subtle text-warning-emphasis">{{ $expiring->count() }} por vencer</span>
            </div>

            <div class="row g-2">
                @foreach($expiring as $doc)
                    <div class="col-12 col-md-6 col-xl-4">
                        <div class="p-3 rounded-3 border bg-surface-elevated d-flex align-items-center justify-content-between gap-2">
                            <div class="min-w-0">
                                <a href="{{ route('employees.file', $doc->user_id) }}" class="fw-bold text-body text-decoration-none small d-block text-truncate">
                                    {{ $doc->user?->full_name }}
                                </a>
                                <div class="text-body-secondary" style="font-size: 0.75rem;">
                                    {{ label('document_type', $doc->type) }}
                                </div>
                            </div>
                            <span class="badge rounded-pill flex-shrink-0 {{ $doc->expires_at->isPast() ? 'bg-danger text-white' : 'bg-warning-subtle text-warning-emphasis border border-warning-subtle' }}">
                                {{ $doc->expires_at->isPast() ? 'Vencido' : 'Vence: ' . $doc->expires_at->format('d/m/Y') }}
                            </span>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>

{{-- Modal de Rechazo de Documento --}}
<div class="modal fade" id="rejectModal" tabindex="-1" aria-labelledby="rejectModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content shadow-lg border-0" id="rejectForm" style="border-radius: 12px; overflow: hidden;">
            @csrf
            <input type="hidden" name="decision" value="rejected">
            <div class="modal-header border-bottom py-3">
                <div class="d-flex align-items-center gap-2">
                    <span class="badge rounded-circle bg-danger-subtle text-danger p-2" style="width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center;">
                        <i class="bi bi-x-circle-fill fs-6"></i>
                    </span>
                    <h2 class="modal-title fs-6 fw-bold text-body mb-0" id="rejectModalTitle">Rechazar Documento</h2>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body p-4">
                <div class="p-3 mb-3 rounded-3 bg-surface-elevated border">
                    <div class="small text-body-secondary mb-1">Documento a observar:</div>
                    <div class="fw-bold text-body small" id="rejectLabel"></div>
                </div>

                <label for="rejectReason" class="modern-field-label">Motivo del rechazo <span class="text-danger">*</span></label>
                <textarea
                    id="rejectReason"
                    name="reason"
                    class="form-control"
                    rows="3"
                    minlength="5"
                    maxlength="255"
                    required
                    style="border-radius: 8px;"
                    placeholder="Describe de forma clara la razón del rechazo para que el colaborador lo corrija..."
                ></textarea>
                <div class="text-body-secondary small mt-1" style="font-size: 0.725rem;">
                    Este motivo será visible para el colaborador en la app móvil.
                </div>
            </div>
            <div class="modal-footer border-top py-2 px-4">
                <button type="button" class="btn btn-outline-secondary px-3" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-danger px-4 fw-semibold">Confirmar Rechazo</button>
            </div>
        </form>
    </div>
</div>
@endsection

@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    // Modal de rechazo
    const rejectModal = document.getElementById('rejectModal');
    if (rejectModal) {
        rejectModal.addEventListener('show.bs.modal', (e) => {
            const btn = e.relatedTarget;
            document.getElementById('rejectForm').action = btn.dataset.action;
            document.getElementById('rejectLabel').textContent = btn.dataset.label;
            document.getElementById('rejectReason').value = '';
        });
    }

    // Estado de filtros 100% internos (sin mutar URL del navegador)
    let currentStatus = '{{ $status }}';
    let currentType = document.getElementById('docTypeSelect').value;
    let currentSearch = document.getElementById('docSearchInput').value;
    let searchDebounceTimeout = null;

    const tableContainer = document.getElementById('documentsTableContainer');
    const searchInput = document.getElementById('docSearchInput');
    const searchClearBtn = document.getElementById('docSearchClearBtn');
    const typeSelect = document.getElementById('docTypeSelect');
    const resetBtn = document.getElementById('docResetBtn');
    const statusTabs = document.querySelectorAll('#statusTabs .training-segmented-item');

    function updateSearchClearVisibility() {
        if (searchInput.value.trim().length > 0) {
            searchClearBtn.classList.remove('d-none');
        } else {
            searchClearBtn.classList.add('d-none');
        }
    }
    updateSearchClearVisibility();

    // Carga interna por AJAX
    async function fetchDocuments(page = 1) {
        tableContainer.style.opacity = '0.5';
        tableContainer.style.pointerEvents = 'none';

        const params = new URLSearchParams();
        if (currentStatus) params.append('status', currentStatus);
        if (currentType) params.append('type', currentType);
        if (currentSearch) params.append('search', currentSearch);
        if (page > 1) params.append('page', page);

        try {
            const response = await fetch(`/documents?${params.toString()}`, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'text/html'
                }
            });

            if (response.ok) {
                const html = await response.text();
                tableContainer.innerHTML = html;
            }
        } catch (error) {
            console.error('Error al filtrar documentos:', error);
        } finally {
            tableContainer.style.opacity = '1';
            tableContainer.style.pointerEvents = 'auto';
        }
    }

    // Tabs de Estado
    statusTabs.forEach(tab => {
        tab.addEventListener('click', () => {
            statusTabs.forEach(t => t.classList.remove('is-active'));
            tab.classList.add('is-active');
            currentStatus = tab.dataset.status;
            fetchDocuments(1);
        });
    });

    // Selector de Tipo
    typeSelect.addEventListener('change', () => {
        currentType = typeSelect.value;
        fetchDocuments(1);
    });

    // Buscador predictivo en tiempo real
    searchInput.addEventListener('input', () => {
        updateSearchClearVisibility();
        clearTimeout(searchDebounceTimeout);
        searchDebounceTimeout = setTimeout(() => {
            currentSearch = searchInput.value.trim();
            fetchDocuments(1);
        }, 300);
    });

    // Limpiar buscador
    searchClearBtn.addEventListener('click', () => {
        searchInput.value = '';
        updateSearchClearVisibility();
        currentSearch = '';
        fetchDocuments(1);
    });

    // Reset general
    resetBtn.addEventListener('click', () => {
        statusTabs.forEach(t => t.classList.remove('is-active'));
        const pendingTab = document.querySelector('#statusTabs [data-status="pending"]');
        if (pendingTab) pendingTab.classList.add('is-active');
        currentStatus = 'pending';

        typeSelect.value = '';
        currentType = '';

        searchInput.value = '';
        currentSearch = '';
        updateSearchClearVisibility();

        fetchDocuments(1);
    });

    // Delegación de eventos para los botones de paginación AJAX
    tableContainer.addEventListener('click', (e) => {
        const pageBtn = e.target.closest('.ajax-page-btn');
        if (pageBtn && !pageBtn.disabled) {
            e.preventDefault();
            const page = parseInt(pageBtn.dataset.page, 10);
            if (page) {
                fetchDocuments(page);
            }
        }
    });
});
</script>
@endsection
