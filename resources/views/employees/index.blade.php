@extends('layouts.app')

@section('title', 'Colaboradores')

@section('styles')
<style>
{{-- =============================================================================
Colaboradores — Executive Enterprise Design
Optimizado para pantallas estándar sin scroll horizontal y tema oscuro nativo
============================================================================= --}}
.emp-shell {
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
    width: 100%;
}
.emp-btn-primary {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.525rem 1.15rem;
    font-size: 0.835rem;
    font-weight: 600;
    border-radius: 8px;
    background: var(--brand);
    border: 1px solid rgba(56, 189, 248, 0.3);
    color: #ffffff !important;
    text-decoration: none;
    box-shadow: 0 2px 6px rgba(var(--brand-rgb), 0.2);
    transition: all 0.15s ease;
    cursor: pointer;
}
.emp-btn-primary:hover {
    background: #0369a1;
    border-color: rgba(56, 189, 248, 0.45);
    color: #ffffff !important;
    transform: translateY(-1px);
    box-shadow: 0 4px 10px rgba(var(--brand-rgb), 0.3);
}
[data-bs-theme="dark"] .emp-btn-primary {
    background: var(--brand);
    border-color: rgba(56, 189, 248, 0.3);
    color: #ffffff !important;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.35);
}
[data-bs-theme="dark"] .emp-btn-primary:hover {
    background: #0369a1;
    border-color: rgba(56, 189, 248, 0.5);
    color: #ffffff !important;
    box-shadow: 0 4px 12px rgba(var(--brand-rgb), 0.35);
}


{{-- =============================================================================
Tabla de Colaboradores — Ultra-Compact No-Overflow Design
============================================================================= --}}
.emp-table-card {
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
    position: relative;
}
.emp-table-wrapper {
    position: relative;
    width: 100%;
    transition: opacity 0.2s ease;
}
.emp-table-wrapper.is-loading {
    opacity: 0.45;
    pointer-events: none;
}
.emp-table-loading-bar {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: linear-gradient(90deg, var(--accent-cyan), #38bdf8, var(--accent-cyan));
    background-size: 200% 100%;
    animation: empBarMove 1.2s infinite linear;
    z-index: 10;
    display: none;
}
@keyframes empBarMove {
    0% { background-position: 200% 0; }
    100% { background-position: -200% 0; }
}

.emp-table-responsive {
    width: 100%;
    overflow-x: auto;
}
@media (min-width: 1100px) {
    .emp-table-responsive {
        overflow-x: hidden; {{-- Garantiza cero scroll horizontal en pantallas estándar --}}
    }
}

.emp-table {
    width: 100%;
    margin-bottom: 0;
    border-collapse: separate;
    border-spacing: 0;
    table-layout: auto;
}
.emp-table th {
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-muted);
    background: var(--bg-surface-elevated);
    border-bottom: 1px solid var(--border-color);
    padding: 0.65rem 0.85rem;
    white-space: nowrap;
}
.emp-table td {
    padding: 0.65rem 0.85rem;
    border-bottom: 1px solid var(--border-color);
    vertical-align: middle;
    color: var(--text-primary);
    font-size: 0.8rem;
    background: var(--bg-surface);
}
.emp-table tr:last-child td {
    border-bottom: none;
}
.emp-table tr:hover td {
    background: var(--bg-surface-elevated);
}

{{-- Celdas Compactas --}}
.emp-user-cell {
    display: flex;
    align-items: center;
    gap: 0.65rem;
}
.emp-avatar-box {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    overflow: hidden;
    border: 1px solid var(--border-color);
    background: var(--bg-surface-elevated);
}
.emp-avatar-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.emp-avatar-initials {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: 0.775rem;
    background: rgba(var(--brand-rgb), 0.12);
    color: var(--accent-cyan);
}
.emp-user-name {
    font-weight: 700;
    font-size: 0.825rem;
    color: var(--text-primary);
    line-height: 1.25;
}
.emp-user-sub {
    font-size: 0.72rem;
    color: var(--text-muted);
    margin-top: 2px;
    max-width: 220px;
}
.emp-tenant-wrap {
    margin-top: 2px;
}
.emp-tenant-wrap .badge {
    font-size: 0.68rem;
    padding: 1px 6px;
    font-weight: 600;
}

{{-- Código y Documento --}}
.emp-code-pill {
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-size: 0.76rem;
    font-weight: 700;
    color: var(--text-primary);
    letter-spacing: 0.02em;
}
.emp-doc-row {
    font-size: 0.7rem;
    color: var(--text-muted);
    display: flex;
    align-items: center;
    gap: 0.3rem;
    margin-top: 2px;
    white-space: nowrap;
}

{{-- Rol Badge --}}
.emp-role-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.2rem 0.5rem;
    font-size: 0.72rem;
    font-weight: 700;
    border-radius: 6px;
    background: var(--bg-surface-elevated);
    border: 1px solid var(--border-color);
    color: var(--text-primary);
    white-space: nowrap;
}
.emp-role-badge i {
    font-size: 0.75rem;
    color: var(--accent-cyan);
}

{{-- Sucursales Badges --}}
.emp-branch-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.2rem 0.5rem;
    border-radius: 6px;
    font-size: 0.7rem;
    font-weight: 600;
    background: var(--bg-surface-elevated);
    border: 1px solid var(--border-color);
    color: var(--text-secondary);
    white-space: nowrap;
}
.emp-branch-badge.is-primary {
    background: rgba(var(--brand-rgb), 0.1);
    border-color: rgba(var(--brand-rgb), 0.28);
    color: var(--accent-cyan);
}
.emp-branch-badge .star-icon {
    font-size: 0.6rem;
    color: #f59e0b;
}
.emp-branch-extra {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0.15rem 0.4rem;
    border-radius: 6px;
    font-size: 0.68rem;
    font-weight: 700;
    background: var(--bg-surface-elevated);
    border: 1px solid var(--border-color);
    color: var(--text-muted);
    cursor: default;
}
.emp-branch-empty {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.2rem 0.45rem;
    border-radius: 6px;
    font-size: 0.7rem;
    font-weight: 700;
    background: rgba(239, 68, 68, 0.1);
    border: 1px solid rgba(239, 68, 68, 0.25);
    color: #ef4444;
    white-space: nowrap;
}

{{-- Turno --}}
.emp-shift-tag {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    font-size: 0.75rem;
    font-weight: 700;
    color: var(--text-primary);
    white-space: nowrap;
}
.emp-shift-tag i {
    color: var(--accent-cyan);
    font-size: 0.8rem;
}
.emp-shift-empty {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    font-size: 0.725rem;
    color: var(--text-muted);
    white-space: nowrap;
}

{{-- Bloqueado Badge --}}
.emp-locked-badge {
    display: inline-flex;
    align-items: center;
    padding: 0.15rem 0.4rem;
    border-radius: 5px;
    background: rgba(245, 158, 11, 0.15);
    border: 1px solid rgba(245, 158, 11, 0.3);
    color: #f59e0b;
    font-size: 0.7rem;
}

{{-- Acciones Compactas --}}
.emp-table-actions {
    display: inline-flex;
    align-items: center;
    gap: 3px;
}
.btn-table-action {
    width: 28px;
    height: 28px;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.75rem;
    border-radius: 6px;
    border: 1px solid var(--border-color);
    background: var(--bg-surface-elevated);
    color: var(--text-secondary);
    text-decoration: none;
    transition: all 0.12s ease;
    cursor: pointer;
}
.btn-table-action:hover {
    color: var(--text-primary);
    border-color: var(--border-color-light);
    transform: translateY(-1px);
}
.btn-action-edit:hover {
    color: var(--brand);
    border-color: var(--brand);
}
.btn-action-view:hover {
    color: #3b82f6;
    border-color: #3b82f6;
}
.btn-action-danger:hover {
    color: #ef4444;
    border-color: #ef4444;
}

{{-- Botón de Activar / Desactivar con colores vivos e indicadores claros --}}
.btn-action-toggle {
    transition: all 0.15s cubic-bezier(0.4, 0, 0.2, 1);
}
.btn-action-toggle.is-active {
    background: rgba(16, 185, 129, 0.14) !important;
    border-color: rgba(16, 185, 129, 0.45) !important;
    color: #10b981 !important;
}
.btn-action-toggle.is-active:hover {
    background: rgba(239, 68, 68, 0.18) !important;
    border-color: rgba(239, 68, 68, 0.5) !important;
    color: #ef4444 !important;
    transform: translateY(-1px) scale(1.05);
}
.btn-action-toggle.is-inactive {
    background: rgba(239, 68, 68, 0.14) !important;
    border-color: rgba(239, 68, 68, 0.45) !important;
    color: #ef4444 !important;
}
.btn-action-toggle.is-inactive:hover {
    background: rgba(16, 185, 129, 0.18) !important;
    border-color: rgba(16, 185, 129, 0.5) !important;
    color: #10b981 !important;
    transform: translateY(-1px) scale(1.05);
}
.btn-action-toggle.is-loading {
    opacity: 0.5;
    pointer-events: none;
    animation: empPulse 0.7s infinite alternate;
}
@keyframes empPulse {
    from { opacity: 0.35; transform: scale(0.96); }
    to { opacity: 1; transform: scale(1.02); }
}

{{-- Footer y Paginador AJAX --}}
.emp-footer {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 0.85rem;
    padding: 0.75rem 1.15rem;
    background: var(--bg-surface);
    border-top: 1px solid var(--border-color);
    font-size: 0.775rem;
    color: var(--text-muted);
}
.emp-pagination {
    display: flex;
    align-items: center;
    gap: 3px;
    margin: 0;
    padding: 0;
    list-style: none;
}
.emp-page-item {
    display: inline-block;
}
.emp-page-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 28px;
    height: 28px;
    padding: 0 6px;
    font-size: 0.75rem;
    font-weight: 700;
    border-radius: 6px;
    background: var(--bg-surface-elevated);
    border: 1px solid var(--border-color);
    color: var(--text-secondary);
    text-decoration: none;
    transition: all 0.12s ease;
    cursor: pointer;
}
.emp-page-link:hover:not(.disabled) {
    color: var(--text-primary);
    border-color: var(--accent-cyan);
}
.emp-page-item.active .emp-page-link {
    background: var(--accent-cyan);
    border-color: var(--accent-cyan);
    color: #ffffff;
}
.emp-page-item.disabled .emp-page-link {
    opacity: 0.4;
    cursor: not-allowed;
}

{{-- =============================================================================
Estilos del Modal Dialog de Nuevo Colaborador
============================================================================= --}}
.emp-modal-content {
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: 14px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.35);
    color: var(--text-primary);
}
.emp-modal-header {
    background: var(--bg-surface);
    border-bottom: 1px solid var(--border-color);
    padding: 1rem 1.25rem;
}
.emp-modal-icon {
    width: 38px;
    height: 38px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.15rem;
    background: rgba(var(--brand-rgb), 0.12);
    color: var(--accent-cyan);
    border: 1px solid rgba(var(--brand-rgb), 0.25);
    flex-shrink: 0;
}
.emp-modal-icon.tone-emerald {
    background: rgba(16, 185, 129, 0.12);
    color: #10b981;
    border-color: rgba(16, 185, 129, 0.25);
    margin: 0 auto;
    width: 44px;
    height: 44px;
    font-size: 1.35rem;
}

.emp-modal-nav-wrap {
    background: var(--bg-surface-elevated);
    border-bottom: 1px solid var(--border-color);
    padding: 0.5rem 1.25rem;
}
.emp-modal-tabs {
    gap: 0.5rem;
}
.emp-modal-tabs .nav-link {
    padding: 0.45rem 0.85rem;
    font-size: 0.8rem;
    font-weight: 700;
    border-radius: 7px;
    color: var(--text-muted);
    background: transparent;
    border: 1px solid transparent;
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    transition: all 0.15s ease;
}
.emp-modal-tabs .nav-link:hover {
    color: var(--text-primary);
    background: rgba(255, 255, 255, 0.04);
}
.emp-modal-tabs .nav-link.active {
    background: var(--accent-cyan);
    color: #ffffff;
    box-shadow: 0 2px 6px rgba(var(--brand-rgb), 0.25);
}

.emp-modal-body {
    padding: 1.25rem;
    max-height: calc(85vh - 180px);
    overflow-y: auto;
}
.emp-section-card {
    background: var(--bg-surface-elevated);
    border: 1px solid var(--border-color);
    border-radius: 10px;
    padding: 1rem 1.15rem;
}
.emp-section-title {
    font-size: 0.775rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-muted);
    display: flex;
    align-items: center;
    gap: 0.45rem;
    margin-bottom: 0.85rem;
}
.emp-section-title i {
    color: var(--accent-cyan);
    font-size: 0.9rem;
}

.emp-form-label {
    display: block;
    font-size: 0.725rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--text-muted);
    margin-bottom: 0.35rem;
}
.emp-form-input,
.emp-form-select {
    width: 100%;
    height: 38px;
    padding: 0.45rem 0.75rem;
    font-size: 0.825rem;
    border-radius: 7px;
    border: 1px solid var(--border-color);
    background: var(--bg-surface);
    color: var(--text-primary);
    transition: all 0.15s ease;
}
.emp-form-input:focus,
.emp-form-select:focus {
    outline: none;
    border-color: var(--accent-cyan);
    box-shadow: 0 0 0 3px rgba(var(--brand-rgb), 0.15);
}
.emp-form-hint {
    font-size: 0.7rem;
    color: var(--text-muted);
    margin-top: 0.3rem;
}
.emp-info-banner {
    display: flex;
    align-items: flex-start;
    gap: 0.65rem;
    background: rgba(var(--brand-rgb), 0.08);
    border: 1px solid rgba(var(--brand-rgb), 0.2);
    border-radius: 8px;
    padding: 0.7rem 0.85rem;
    font-size: 0.775rem;
    color: var(--text-primary);
}
.emp-info-banner i {
    color: var(--accent-cyan);
    font-size: 0.95rem;
    margin-top: 2px;
}
.emp-switch-box {
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    padding: 0.75rem 0.85rem;
}

.emp-branches-container {
    display: flex;
    flex-direction: column;
    gap: 0.45rem;
    max-height: 220px;
    overflow-y: auto;
    padding-right: 4px;
}
.emp-branch-option-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.55rem 0.75rem;
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: 7px;
    transition: border-color 0.15s ease;
}
.emp-branch-option-row:hover {
    border-color: var(--accent-cyan);
}

.emp-modal-footer {
    background: var(--bg-surface);
    border-top: 1px solid var(--border-color);
    padding: 0.85rem 1.25rem;
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 0.65rem;
}

{{-- Credenciales Modal Card --}}
.emp-cred-card {
    background: var(--bg-surface-elevated);
    border: 1px solid var(--border-color);
    border-radius: 10px;
    padding: 1rem;
}
.emp-cred-field {
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: 6px;
    padding: 0.45rem 0.65rem;
    font-size: 0.85rem;
    color: var(--text-primary);
    user-select: all;
}

@media (min-width: 992px) {
    #employeeFileModal .modal-dialog {
        max-width: 980px;
    }
}
</style>
@endsection

@section('content')
@php
    $roleLabels = \App\Modules\Core\Support\Labels::options('user_role');
@endphp

<div class="emp-shell">
    {{-- 1. Encabezado y Acción Principal --}}
    <header class="page-head">
        <div class="page-head-text">
            <h1 class="page-title">Colaboradores</h1>
            <p class="page-subtitle">Gestión de personal, asignación de sedes, turnos y control de acceso.</p>
        </div>
        <div class="page-actions">
            {{-- Relación de personal: por defecto solo planilla (inspección); honorarios o todos a pedido --}}
            <div class="dropdown">
                <button type="button" class="btn btn-light border dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-file-earmark-spreadsheet me-1"></i>Exportar a Excel
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    @foreach (['planilla' => 'Solo en planilla', 'honorarios' => 'Solo recibo por honorarios', 'all' => 'Todos'] as $regime => $label)
                        <li><a class="dropdown-item" href="{{ route('finance-reports.download', ['report' => 'employees', 'from' => now()->toDateString(), 'to' => now()->toDateString(), 'regime' => $regime]) }}">{{ $label }}</a></li>
                    @endforeach
                </ul>
            </div>
            <button type="button" class="btn btn-light border" data-bs-toggle="modal" data-bs-target="#importEmployeesModal">
                <i class="bi bi-file-earmark-arrow-up me-1"></i>Importar planilla
            </button>
            <button type="button" class="btn btn-primary" id="btnNewEmployee" data-bs-toggle="modal" data-bs-target="#employeeModal">
                <i class="bi bi-person-plus me-1"></i>Nuevo colaborador
            </button>
        </div>
    </header>

    {{-- Importar planilla (sisgere FrmIplanilla): revisar y luego dar de alta solo a los nuevos --}}
    <div class="modal fade" id="importEmployeesModal" tabindex="-1" aria-labelledby="importEmployeesTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-scrollable modal-fullscreen-sm-down modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="importEmployeesTitle">Importar colaboradores desde Excel</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-body-secondary">Columnas: DNI, Apellidos y Nombres (o Colaborador), Cargo, Correo, Teléfono, Dirección, Sueldo, Banco, Tipo de cuenta y N.º de cuenta.
                        Solo se dan de alta los DNI nuevos; nadie se da de baja. <a href="{{ route('employees.import.template') }}">Descargar plantilla</a></p>
                    <div class="row g-2">
                        <div class="col-md-7">
                            <label class="form-label small fw-semibold" for="impFile">Archivo *</label>
                            <input type="file" id="impFile" class="form-control" accept=".xlsx,.xls,.csv">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small fw-semibold" for="impBranch">Sede de los nuevos *</label>
                            <select id="impBranch" class="form-select">
                                <option value="">Elige…</option>
                                @foreach($branches as $b)
                                    <option value="{{ $b->id }}">{{ $b->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div id="impError" class="alert alert-danger py-2 small mt-3 d-none" role="alert"></div>
                    <div id="impResult" class="mt-3 d-none">
                        <div class="d-flex flex-wrap gap-3 small mb-2">
                            <span class="badge bg-success-subtle text-success-emphasis fs-6" id="impNewCount"></span>
                            <span class="badge bg-secondary-subtle text-secondary-emphasis fs-6" id="impExistingCount"></span>
                            <span class="badge bg-warning-subtle text-warning-emphasis fs-6" id="impMissingCount"></span>
                        </div>
                        <div class="table-responsive" style="max-height: 240px">
                            <table class="table table-sm small mb-0"><thead class="table-light"><tr><th>DNI</th><th>Apellidos y nombres</th><th>Cargo</th><th>Cuenta</th></tr></thead><tbody id="impNewRows"></tbody></table>
                        </div>
                        <details class="small mt-2" id="impMissingBox"><summary>En el sistema y no en el archivo (solo para revisar)</summary><ul id="impMissingRows" class="mb-0"></ul></details>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-outline-primary" id="impPreviewBtn">Revisar archivo</button>
                    <button type="button" class="btn btn-success" id="impConfirmBtn" disabled>Dar de alta</button>
                </div>
            </div>
        </div>
    </div>

    {{-- 2. Alerta de Credenciales Flasheadas (si aplica por sesión previa) --}}
    @include('employees._credentials')

    {{-- 3. Deck de Indicadores (KPIs) --}}
    @if(isset($stats))
        <section class="dash-kpis" aria-label="Resumen del personal">
            @foreach ([['kpiTotal', 'Total colaboradores', 'total', 'brand', 'people'], ['kpiActive', 'Activos', 'active', 'emerald', 'person-check'], ['kpiWithShift', 'Con turno asignado', 'with_shift', 'blue', 'clock-history'], ['kpiWithBranch', 'Con sede asignada', 'with_branch', 'amber', 'geo-alt']] as [$kid, $klabel, $kkey, $ktone, $kicon])
                <article class="dash-kpi-card">
                    <div class="dash-kpi-top">
                        <span class="dash-kpi-label">{{ $klabel }}</span>
                        <span class="dash-kpi-icon tone-{{ $ktone }}"><i class="bi bi-{{ $kicon }}"></i></span>
                    </div>
                    <div class="dash-kpi-value" id="{{ $kid }}">{{ number_format($stats[$kkey] ?? 0) }}</div>
                </article>
            @endforeach
        </section>
    @endif

    {{-- 4. Filtros Avanzados (Conectados a AJAX en tiempo real) --}}
    {{-- Barra de filtros compartida (Panel.filters): se aplica al elegir, recuerda lo elegido y muestra etiquetas --}}
    <div class="emp-table-card">
    <form method="GET" action="{{ route('employees.index') }}" class="filter-bar filter-bar-flush" id="empFilterForm" data-filter-key="employees" role="search">
        <div class="filter-bar-row">
            <label class="filter-search mb-0">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input type="search" id="search" name="search" value="{{ request('search') }}" data-label="Búsqueda"
                       placeholder="Buscar por nombre, correo, código o DNI…" autocomplete="off" aria-label="Buscar colaborador">
            </label>
            <select id="branch_id" name="branch_id" class="filter-select" data-label="Sede" aria-label="Sede">
                <option value="">Todas las sedes</option>
                @foreach($branches as $b)
                    <option value="{{ $b->id }}" @selected(request('branch_id') == $b->id)>{{ $b->name }}</option>
                @endforeach
            </select>
            @if(isset($positions) && $positions->isNotEmpty())
                <select id="position_id" name="position_id" class="filter-select" data-label="Cargo" aria-label="Cargo">
                    <option value="">Todos los cargos</option>
                    @foreach($positions as $p)
                        <option value="{{ $p->id }}" @selected(request('position_id') == $p->id)>{{ $p->name }}</option>
                    @endforeach
                </select>
            @endif
            <select id="role" name="role" class="filter-select" data-label="Rol" aria-label="Rol">
                <option value="">Todos los roles</option>
                @foreach(['employee', 'supervisor', 'accountant', 'logistics', 'hr_manager', 'manager', 'company_admin'] as $r)
                    <option value="{{ $r }}" @selected(request('role') == $r)>{{ $roleLabels[$r] ?? ucfirst($r) }}</option>
                @endforeach
            </select>
            <select id="regime" name="regime" class="filter-select" data-label="Régimen" aria-label="Régimen">
                <option value="">Planilla y honorarios</option>
                @foreach (\App\Modules\Core\Models\User::PAYROLL_REGIMES as $value => $label)
                    <option value="{{ $value }}" @selected(request('regime') == $value)>{{ $label }}</option>
                @endforeach
            </select>
            <select id="status" name="status" class="filter-select" data-label="Estado" aria-label="Estado">
                <option value="">Todos los estados</option>
                <option value="active" @selected(request('status') == 'active')>Activo</option>
                <option value="inactive" @selected(request('status') == 'inactive')>Inactivo</option>
            </select>
        </div>
        <div class="filter-chips" data-filter-chips hidden></div>
    </form>

    {{-- 5. Contenedor de Tabla con Paginador AJAX (misma superficie que los filtros) --}}
        <div class="emp-table-loading-bar" id="empTableLoader"></div>
        <div id="empTableContainer">
            @include('employees.partials.table', ['employees' => $employees, 'stats' => $stats ?? null])
        </div>
    </div>
</div>

{{-- Modal Dialog de Creación de Colaborador --}}
@include('employees.partials.modal-create')

{{-- Modal Dialog de Edición de Colaborador --}}
@include('employees.partials.modal-edit')

{{-- Modal Dialog de Legajo y Expediente Laboral --}}
<div class="modal fade" id="employeeFileModal" tabindex="-1" aria-labelledby="employeeFileModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content emp-modal-content">
            <div class="modal-header emp-modal-header">
                <div class="d-flex align-items-center gap-3">
                    <div class="emp-modal-icon tone-blue">
                        <i class="bi bi-folder2-open"></i>
                    </div>
                    <div>
                        <h2 class="modal-title fs-5 fw-bold mb-0" id="employeeFileModalLabel" style="color: var(--text-primary); letter-spacing: -0.01em;">Expediente y Ficha del Colaborador</h2>
                        <p class="small text-muted mb-0" style="font-size: 0.775rem;">Cuentas bancarias de abono, régimen previsional, documentos oficiales y dotación.</p>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body p-4" id="employeeFileModalBody">
                <div class="py-5 text-center text-muted">
                    <div class="spinner-border spinner-border-sm text-primary mb-2" role="status"></div>
                    <div class="small fw-semibold">Cargando expediente digital…</div>
                </div>
            </div>
            <div class="modal-footer emp-modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm px-3" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>


@endsection

@section('scripts')
<script>
// Importar planilla: revisar (no guarda) y confirmar (vuelve a leer el archivo y da de alta a los nuevos)
document.addEventListener('DOMContentLoaded', function () {
    const $ = (id) => document.getElementById(id);
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const send = async (url) => {
        const data = new FormData();
        if ($('impFile').files[0]) data.append('file', $('impFile').files[0]);
        if ($('impBranch').value) data.append('branch_id', $('impBranch').value);
        const res = await fetch(url, { method: 'POST', body: data, headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', Accept: 'application/json' } });
        const json = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(Object.values(json.errors || {})[0]?.[0] || json.message || 'No se pudo procesar el archivo.');
        return json;
    };
    const fail = (e) => { $('impError').className = 'alert alert-danger py-2 small mt-3'; $('impError').textContent = e.message; };
    $('impFile')?.addEventListener('change', () => { $('impConfirmBtn').disabled = true; $('impResult').classList.add('d-none'); });
    $('impPreviewBtn')?.addEventListener('click', async () => {
        $('impError').classList.add('d-none');
        try {
            const r = await send('{{ route('employees.import.preview') }}');
            $('impNewCount').textContent = `${r.new.length} nuevo(s)`;
            $('impExistingCount').textContent = `${r.existing} ya registrado(s)`;
            $('impMissingCount').textContent = `${r.missing.length} no están en el archivo`;
            $('impNewRows').innerHTML = r.new.map((n) => `<tr><td class="font-monospace">${esc(n.dni)}</td><td>${esc(n.last_name)}, ${esc(n.first_name)}</td><td>${esc(n.position || '—')}</td><td>${esc(n.bank_account_number ? `${n.bank_name || ''} ${n.bank_account_number}` : '—')}</td></tr>`).join('');
            $('impMissingRows').innerHTML = r.missing.map((m) => `<li>${esc(m.name)} · ${esc(m.dni)}</li>`).join('');
            $('impMissingBox').classList.toggle('d-none', !r.missing.length);
            $('impResult').classList.remove('d-none');
            $('impConfirmBtn').disabled = !r.new.length;
            $('impConfirmBtn').textContent = `Dar de alta ${r.new.length}`;
        } catch (e) { fail(e); }
    });
    $('impConfirmBtn')?.addEventListener('click', async () => {
        $('impError').classList.add('d-none');
        $('impConfirmBtn').disabled = true;
        try {
            const r = await send('{{ route('employees.import') }}');
            $('impError').className = 'alert alert-success py-2 small mt-3';
            $('impError').textContent = r.message;
            setTimeout(() => window.location.reload(), 1500);
        } catch (e) { fail(e); $('impConfirmBtn').disabled = false; }
    });
});

document.addEventListener('DOMContentLoaded', function () {
    const tableContainer = document.getElementById('empTableContainer');
    const tableLoader = document.getElementById('empTableLoader');
    const filterForm = document.getElementById('empFilterForm');
    const searchInput = document.getElementById('search');
    const branchSelect = document.getElementById('branch_id');
    const positionSelect = document.getElementById('position_id');
    const roleSelect = document.getElementById('role');
    const statusSelect = document.getElementById('status');

    let currentInternalPage = 1;
    let searchDebounceTimer = null;

    {{-- ========================================================================= --}}
    {{-- 1. Paginación y Filtrado AJAX Interno (Sin recargar página ni URL) --}}
    {{-- ========================================================================= --}}
    async function fetchEmployees(page = 1) {
        currentInternalPage = page;
        if (tableLoader) tableLoader.style.display = 'block';
        Panel.skeleton.on(tableContainer);

        const params = new URLSearchParams({
            page: page,
            search: searchInput ? searchInput.value.trim() : '',
            branch_id: branchSelect ? branchSelect.value : '',
            position_id: positionSelect ? positionSelect.value : '',
            role: roleSelect ? roleSelect.value : '',
            status: statusSelect ? statusSelect.value : '',
            regime: document.getElementById('regime')?.value || '',
            per_page: '10'
        });
        // Sin parámetros vacíos (search=&branch_id=...)
        [...params.keys()].forEach((key) => { if (params.get(key) === '') params.delete(key); });

        try {
            const response = await fetch(`{{ route('employees.index') }}?${params.toString()}`, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'text/html, application/json'
                }
            });

            if (!response.ok) throw new Error('Error al cargar datos');

            const html = await response.text();
            tableContainer.innerHTML = html;
            if (filterForm) Panel.filters.sync(filterForm);
        } catch (error) {
            console.error('Error fetching employees:', error);
        } finally {
            if (tableLoader) tableLoader.style.display = 'none';
            Panel.skeleton.off(tableContainer);
        }
    }

    {{-- ========================================================================= --}}
    {{-- Interceptar clics en la tabla (Paginador, Toggle de Acceso, Editar, Legajo) --}}
    {{-- ========================================================================= --}}
    tableContainer.addEventListener('click', async function (e) {
        {{-- 1. Paginador AJAX interno --}}
        const link = e.target.closest('.ajax-page-link');
        if (link && link.dataset.page) {
            e.preventDefault();
            const targetPage = parseInt(link.dataset.page, 10);
            if (!isNaN(targetPage) && targetPage > 0) {
                fetchEmployees(targetPage);
            }
            return;
        }

        {{-- 2. Activar / Desactivar Acceso Inmediato (Sin recargar la página) --}}
        const btnToggle = e.target.closest('.btn-action-toggle');
        if (btnToggle) {
            e.preventDefault();
            const id = btnToggle.dataset.id;
            const currentStatus = btnToggle.dataset.status;
            const empName = btnToggle.dataset.name || 'el colaborador';
            const toggleUrl = btnToggle.dataset.url || `/employees/${id}/toggle-status`;

            btnToggle.classList.add('is-loading');

            try {
                const res = await fetch(toggleUrl, {
                    method: 'PATCH',
                    headers: {
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    }
                });

                const data = await res.json();

                if (!res.ok || !data.success) {
                    throw new Error(data.message || 'No se pudo cambiar el estado de acceso.');
                }

                const newStatus = data.status; {{-- 'active' o 'inactive' --}}
                btnToggle.dataset.status = newStatus;

                {{-- Actualizar botón de acción (Color verde esmeralda para activo, carmesí para inactivo) --}}
                if (newStatus === 'active') {
                    btnToggle.className = 'btn-table-action btn-action-toggle is-active';
                    btnToggle.title = 'Acceso activo — Clic para desactivar';
                    btnToggle.innerHTML = '<i class="bi bi-check-circle-fill"></i>';
                } else {
                    btnToggle.className = 'btn-table-action btn-action-toggle is-inactive';
                    btnToggle.title = 'Acceso desactivado — Clic para activar';
                    btnToggle.innerHTML = '<i class="bi bi-slash-circle"></i>';
                }

                {{-- Actualizar badge en la columna Estado --}}
                const statusCell = document.getElementById(`status-container-${id}`);
                if (statusCell) {
                    if (newStatus === 'active') {
                        statusCell.innerHTML = `<span class="badge rounded-2 bg-success-subtle text-success border border-success-subtle d-inline-flex align-items-center" style="gap: 6px;"><span class="badge-dot"></span><span>Activo</span></span>`;
                    } else {
                        statusCell.innerHTML = `<span class="badge rounded-2 bg-secondary-subtle text-secondary border border-secondary-subtle d-inline-flex align-items-center" style="gap: 6px;"><span class="badge-dot"></span><span>Inactivo</span></span>`;
                    }
                }

                {{-- Sincronizar KPI contador de colaboradores activos --}}
                const kpiActive = document.getElementById('kpiActive');
                if (kpiActive) {
                    let activeCount = parseInt(kpiActive.textContent.replace(/,/g, ''), 10) || 0;
                    if (newStatus === 'active') activeCount++;
                    else if (activeCount > 0) activeCount--;
                    kpiActive.textContent = activeCount.toLocaleString();
                }

                showToast(data.message || `Acceso ${newStatus === 'active' ? 'activado' : 'desactivado'} para ${empName}`, newStatus === 'active' ? 'success' : 'danger');
            } catch (err) {
                console.error('Error toggling status:', err);
                showToast(err.message || 'Error al actualizar acceso del colaborador.', 'danger');
            } finally {
                btnToggle.classList.remove('is-loading');
            }
            return;
        }

        {{-- 3. Abrir Modal Dialog de Edición --}}
        const btnEdit = e.target.closest('.btn-action-edit');
        if (btnEdit) {
            e.preventDefault();
            const id = btnEdit.dataset.id;
            openEditModal(id);
            return;
        }

        {{-- 4. Abrir Modal Dialog de Legajo y Expediente Laboral --}}
        const btnView = e.target.closest('.btn-action-view');
        if (btnView) {
            e.preventDefault();
            const id = btnView.dataset.id;
            openFileModal(id);
            return;
        }
    });

    {{-- Filtros: se aplican al elegir (los selectores) o al dejar de escribir (búsqueda) --}}
    if (filterForm) {
        filterForm.addEventListener('submit', function (e) {
            e.preventDefault();
            fetchEmployees(1);
        });
        filterForm.querySelectorAll('select').forEach((sel) => sel.addEventListener('change', () => fetchEmployees(1)));
        {{-- Recuerda lo elegido, etiquetas y "Limpiar" (Panel.filters, compartido con las demás pantallas) --}}
        Panel.filters.init(filterForm, { apply: () => fetchEmployees(1) });
    }

    {{-- Búsqueda en tiempo real con debounce de 350ms --}}
    if (searchInput) {
        searchInput.addEventListener('input', () => {
            clearTimeout(searchDebounceTimer);
            searchDebounceTimer = setTimeout(() => {
                fetchEmployees(1);
            }, 350);
        });
    }

    {{-- ========================================================================= --}}
    {{-- 2. Lógica del Modal Dialog: Nuevo Colaborador --}}
    {{-- ========================================================================= --}}
    const createForm = document.getElementById('createEmployeeForm');
    const modalDocNumber = document.getElementById('modal_document_number');
    const modalEmail = document.getElementById('modal_email');
    const modalPosition = document.getElementById('modal_position_id');
    const modalDepartment = document.getElementById('modal_department_id');
    const modalRoaming = document.getElementById('modal_roaming_attendance_enabled');
    const modalRole = document.getElementById('modal_role');
    const scopeContainer = document.getElementById('scopeContainer');
    const modalScopeType = document.getElementById('modal_scope_type');
    const scopeZoneWrap = document.getElementById('scopeZoneWrap');
    const scopeBranchWrap = document.getElementById('scopeBranchWrap');
    const modalTenantId = document.getElementById('modal_tenant_id');
    const modalBranchesList = document.getElementById('modalBranchesList');
    const modalShiftSelect = document.getElementById('modal_shift_id');
    const btnSubmitEmployee = document.getElementById('btnSubmitEmployee');
    const btnSubmitSpinner = document.getElementById('btnSubmitSpinner');
    const btnSubmitIcon = document.getElementById('btnSubmitIcon');
    const btnSubmitText = document.getElementById('btnSubmitText');
    const modalErrorAlert = document.getElementById('modalFormErrorAlert');
    const modalErrorText = document.getElementById('modalFormErrorText');

    let currentDomain = "{{ $loginDomain ?? 'empresa.com' }}";

    {{-- Sugerencia de correo al tipear número de documento --}}
    if (modalDocNumber && modalEmail) {
        modalDocNumber.addEventListener('input', function () {
            const docVal = this.value.trim().replace(/\s+/g, '');
            if (!modalEmail.value || modalEmail.value.startsWith('DNI@') || modalEmail.value.endsWith('@' + currentDomain)) {
                if (docVal) {
                    modalEmail.placeholder = `${docVal}@${currentDomain}`;
                } else {
                    modalEmail.placeholder = `DNI@${currentDomain}`;
                }
            }
        });
    }

    {{-- Al seleccionar cargo del catálogo: vincular departamento y marcación itinerante --}}
    if (modalPosition) {
        modalPosition.addEventListener('change', function () {
            const selectedOpt = this.selectedOptions[0];
            if (!selectedOpt) return;
            const deptId = selectedOpt.dataset.department;
            const isRoaming = selectedOpt.dataset.roaming === '1';

            if (deptId && modalDepartment) {
                modalDepartment.value = deptId;
            }
            if (modalRoaming && isRoaming) {
                modalRoaming.checked = true;
            }
        });
    }

    {{-- Ocultar alcance en el panel si el rol es 'employee' --}}
    function evaluateRoleScope() {
        if (!modalRole || !scopeContainer) return;
        const role = modalRole.value;
        if (role === 'employee') {
            scopeContainer.style.display = 'none';
        } else {
            scopeContainer.style.display = 'block';
        }
    }
    if (modalRole) {
        modalRole.addEventListener('change', evaluateRoleScope);
        evaluateRoleScope();
    }

    {{-- Mostrar/ocultar zona o sede según alcance territorial --}}
    if (modalScopeType) {
        modalScopeType.addEventListener('change', function () {
            const val = this.value;
            if (scopeZoneWrap) scopeZoneWrap.classList.toggle('d-none', val !== 'zone');
            if (scopeBranchWrap) scopeBranchWrap.classList.toggle('d-none', val !== 'branch');
        });
    }

    {{-- Sincronizar sucursales: marcar la primera activa como principal si no hay ninguna --}}
    if (modalBranchesList) {
        modalBranchesList.addEventListener('change', function (e) {
            if (e.target.classList.contains('branch-check')) {
                const checkedBoxes = modalBranchesList.querySelectorAll('.branch-check:checked');
                const radioForThis = document.getElementById(`m_primary_${e.target.value}`);

                if (e.target.checked) {
                    {{-- Si es la única seleccionada, marcarla como principal --}}
                    if (checkedBoxes.length === 1 && radioForThis) {
                        radioForThis.checked = true;
                    }
                } else {
                    {{-- Si se desmarcó y era la principal, mover principal a la primera disponible --}}
                    if (radioForThis && radioForThis.checked && checkedBoxes.length > 0) {
                        const firstLeft = checkedBoxes[0].value;
                        const firstRadio = document.getElementById(`m_primary_${firstLeft}`);
                        if (firstRadio) firstRadio.checked = true;
                    }
                }
            }

            if (e.target.classList.contains('primary-branch-radio')) {
                const val = e.target.value;
                const check = document.getElementById(`m_branch_${val}`);
                if (check) check.checked = true;
            }
        });
    }

    {{-- Si Super Admin cambia de empresa en el selector: cargar opciones vía AJAX --}}
    if (modalTenantId) {
        modalTenantId.addEventListener('change', async function () {
            const tenantId = this.value;
            if (!tenantId) return;

            try {
                const res = await fetch(`/employees/tenant-options/${tenantId}`, {
                    headers: { 'Accept': 'application/json' }
                });
                if (!res.ok) return;
                const data = await res.json();

                if (data.login_domain) {
                    currentDomain = data.login_domain;
                    const docVal = modalDocNumber ? modalDocNumber.value.trim() : '';
                    if (modalEmail) modalEmail.placeholder = docVal ? `${docVal}@${currentDomain}` : `DNI@${currentDomain}`;
                    const hintEl = document.getElementById('modalEmailHint');
                    if (hintEl) hintEl.innerHTML = `Si se deja vacío, el sistema genera automáticamente: <code>DNI@${currentDomain}</code>`;
                }

                {{-- Cargar sucursales --}}
                if (modalBranchesList && data.branches) {
                    if (data.branches.length === 0) {
                        modalBranchesList.innerHTML = `<div class="p-3 text-center text-muted small">No hay sucursales activas en esta empresa.</div>`;
                    } else {
                        modalBranchesList.innerHTML = data.branches.map((b, idx) => `
                            <div class="emp-branch-option-row">
                                <div class="form-check d-flex align-items-center gap-2 mb-0">
                                    <input class="form-check-input branch-check" type="checkbox" name="branch_ids[]" value="${Panel.esc(b.id)}" id="m_branch_${Panel.esc(b.id)}" ${idx === 0 ? 'checked' : ''}>
                                    <label class="form-check-label fw-bold" for="m_branch_${Panel.esc(b.id)}" style="font-size: 0.825rem; color: var(--text-primary);">${Panel.esc(b.name)}</label>
                                </div>
                                <div class="form-check form-check-inline mb-0">
                                    <input class="form-check-input primary-branch-radio" type="radio" name="primary_branch_id" value="${Panel.esc(b.id)}" id="m_primary_${Panel.esc(b.id)}" ${idx === 0 ? 'checked' : ''}>
                                    <label class="form-check-label small" for="m_primary_${Panel.esc(b.id)}" style="font-size: 0.75rem; color: var(--accent-cyan); cursor: pointer;">
                                        <i class="bi bi-star-fill star-icon me-1"></i>Sede Principal
                                    </label>
                                </div>
                            </div>
                        `).join('');
                    }
                }

                {{-- Cargar turnos --}}
                if (modalShiftSelect && data.shifts) {
                    modalShiftSelect.innerHTML = `<option value="">Sin turno asignado (se asignará después)</option>` +
                        data.shifts.map(s => `<option value="${Panel.esc(s.id)}">${Panel.esc(s.name)} (${Panel.esc(s.start_time || '')} - ${Panel.esc(s.end_time || '')})</option>`).join('');
                }

                {{-- Cargar cargos --}}
                if (modalPosition && data.positions) {
                    modalPosition.innerHTML = `<option value="">Sin cargo asignado</option>` +
                        data.positions.map(p => `<option value="${Panel.esc(p.id)}" data-department="${Panel.esc(p.department_id || '')}" data-roaming="${p.is_roaming ? '1' : '0'}">${Panel.esc(p.name)}</option>`).join('');
                }

                {{-- Cargar departamentos --}}
                if (modalDepartment && data.departments) {
                    modalDepartment.innerHTML = `<option value="">Sin área específica</option>` +
                        data.departments.map(d => `<option value="${Panel.esc(d.id)}">${Panel.esc(d.name)}</option>`).join('');
                }
            } catch (err) {
                console.error('Error fetching tenant options:', err);
            }
        });
    }

    {{-- ========================================================================= --}}
    {{-- 3. Envío AJAX del Formulario de Creación de Colaborador --}}
    {{-- ========================================================================= --}}
    if (createForm) {
        createForm.addEventListener('submit', async function (e) {
            e.preventDefault();

            {{-- Limpiar errores previos --}}
            createForm.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
            createForm.querySelectorAll('.invalid-feedback').forEach(el => el.textContent = '');
            if (modalErrorAlert) modalErrorAlert.classList.add('d-none');

            {{-- Estado de carga --}}
            if (btnSubmitEmployee) btnSubmitEmployee.disabled = true;
            if (btnSubmitSpinner) btnSubmitSpinner.classList.remove('d-none');
            if (btnSubmitIcon) btnSubmitIcon.classList.add('d-none');
            if (btnSubmitText) btnSubmitText.textContent = 'Guardando…';

            const formData = new FormData(createForm);

            try {
                const response = await fetch(createForm.action, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    }
                });

                const data = await response.json();

                if (response.status === 422) {
                    {{-- Errores de validación --}}
                    const errors = data.errors || {};
                    let firstErrElement = null;

                    for (const [key, messages] of Object.entries(errors)) {
                        const cleanKey = key.replace(/\.\d+/g, '');
                        const inputEl = createForm.querySelector(`[name="${cleanKey}"]`) ||
                                        createForm.querySelector(`[name="${cleanKey}[]"]`);
                        const errEl = document.getElementById(`err_${cleanKey}`);

                        if (inputEl) {
                            inputEl.classList.add('is-invalid');
                            if (!firstErrElement) firstErrElement = inputEl;
                        }
                        if (errEl) {
                            errEl.textContent = messages[0];
                        }
                    }

                    if (modalErrorAlert) {
                        modalErrorText.textContent = data.message || 'Por favor revisa los campos señalados en rojo.';
                        modalErrorAlert.classList.remove('d-none');
                    }

                    {{-- Cambiar a la pestaña que tiene el primer error --}}
                    if (firstErrElement) {
                        const tabPane = firstErrElement.closest('.tab-pane');
                        if (tabPane && tabPane.id) {
                            const triggerBtn = document.querySelector(`[data-bs-target="#${tabPane.id}"]`);
                            if (triggerBtn && window.bootstrap) {
                                bootstrap.Tab.getOrCreateInstance(triggerBtn).show();
                            }
                        }
                        firstErrElement.focus();
                    }
                } else if (response.ok && data.success) {
                    {{-- Éxito: cerrar modal de alta --}}
                    const empModalEl = document.getElementById('employeeModal');
                    if (empModalEl && window.bootstrap) {
                        bootstrap.Modal.getOrCreateInstance(empModalEl).hide();
                    }
                    createForm.reset();

                    {{-- Mostrar modal con las credenciales generadas --}}
                    if (data.credentials) {
                        document.getElementById('credModalName').textContent = data.credentials.name || '—';
                        document.getElementById('credModalEmail').textContent = data.credentials.email || '—';
                        document.getElementById('credModalPass').textContent = data.credentials.password || '—';

                        {{-- Configurar enlace de WhatsApp --}}
                        const waText = encodeURIComponent(
                            `Hola ${data.credentials.name}, se ha creado tu acceso a la app móvil de asistencia:\n\n` +
                            `*Usuario:* ${data.credentials.email}\n` +
                            `*Contraseña temporal:* ${data.credentials.password}\n\n` +
                            `Por favor ingresa y actualiza tu clave en tu primer ingreso.`
                        );
                        const waBtn = document.getElementById('btnWhatsappShare');
                        if (waBtn) waBtn.href = `https://wa.me/?text=${waText}`;

                        const credModalEl = document.getElementById('credentialsSuccessModal');
                        if (credModalEl && window.bootstrap) {
                            bootstrap.Modal.getOrCreateInstance(credModalEl).show();
                        }
                    }

                    {{-- Refrescar la tabla de colaboradores vía AJAX inmediatamente --}}
                    fetchEmployees(1);
                } else {
                    throw new Error(data.message || 'Ocurrió un error inesperado');
                }
            } catch (err) {
                console.error('Error submitting employee form:', err);
                if (modalErrorAlert) {
                    modalErrorText.textContent = err.message || 'No se pudo registrar el colaborador. Verifica tu conexión.';
                    modalErrorAlert.classList.remove('d-none');
                }
            } finally {
                if (btnSubmitEmployee) btnSubmitEmployee.disabled = false;
                if (btnSubmitSpinner) btnSubmitSpinner.classList.add('d-none');
                if (btnSubmitIcon) btnSubmitIcon.classList.remove('d-none');
                if (btnSubmitText) btnSubmitText.textContent = 'Crear Colaborador';
            }
        });
    }

    {{-- Copiar credenciales desde el modal de éxito --}}
    const btnCopyCredsModal = document.getElementById('btnCopyCredsModal');
    if (btnCopyCredsModal) {
        btnCopyCredsModal.addEventListener('click', function () {
            const email = document.getElementById('credModalEmail').textContent;
            const pass = document.getElementById('credModalPass').textContent;
            const textToCopy = `Usuario: ${email}\nContraseña temporal: ${pass}`;

            if (navigator.clipboard) {
                navigator.clipboard.writeText(textToCopy).then(() => {
                    const textSpan = document.getElementById('btnCopyCredsText');
                    if (textSpan) textSpan.textContent = '¡Copiado!';
                    setTimeout(() => {
                        if (textSpan) textSpan.textContent = 'Copiar Credenciales';
                    }, 2500);
                });
            }
        });
    }

    {{-- ========================================================================= --}}
    {{-- 4. Toast Flotante de Notificación Rápida --}}
    {{-- ========================================================================= --}}
    {{-- Avisos de la pantalla en la pila compartida (Panel.toast) --}}
    function showToast(message, tone = 'success') {
        const ok = tone === 'success';
        Panel.toast(message, ok ? 'success' : 'error', ok ? 'Listo' : 'Atención');
    }

    {{-- ========================================================================= --}}
    {{-- 5. Modal Dialog: Edición de Colaborador --}}
    {{-- ========================================================================= --}}
    const editModalEl = document.getElementById('employeeEditModal');
    const editForm = document.getElementById('editEmployeeForm');
    const editErrorAlert = document.getElementById('editModalErrorAlert');
    const editErrorText = document.getElementById('editModalErrorText');
    const btnSubmitEdit = document.getElementById('btnSubmitEditEmployee');
    const btnSubmitEditSpinner = document.getElementById('btnEditSpinner');
    const btnSubmitEditIcon = document.getElementById('btnEditIcon');
    const btnSubmitEditText = document.getElementById('btnEditText');
    const editBranchesList = document.getElementById('editBranchesList');

    {{-- Cambios dinámicos en checkboxes y radio de sucursales en modal de edición --}}
    if (editBranchesList) {
        editBranchesList.addEventListener('change', function (e) {
            if (e.target.classList.contains('edit-branch-check')) {
                const checkedBoxes = editBranchesList.querySelectorAll('.edit-branch-check:checked');
                const radioForThis = document.getElementById(`edit_primary_${e.target.value}`);

                if (e.target.checked) {
                    if (checkedBoxes.length === 1 && radioForThis) {
                        radioForThis.checked = true;
                    }
                } else {
                    if (radioForThis && radioForThis.checked && checkedBoxes.length > 0) {
                        const firstLeft = checkedBoxes[0].value;
                        const firstRadio = document.getElementById(`edit_primary_${firstLeft}`);
                        if (firstRadio) firstRadio.checked = true;
                    }
                }
            }

            if (e.target.classList.contains('edit-primary-branch-radio')) {
                const val = e.target.value;
                const check = document.getElementById(`edit_branch_${val}`);
                if (check) check.checked = true;
            }
        });
    }

    {{-- Al seleccionar cargo en edición: auto-seleccionar departamento y marcación itinerante --}}
    const editPosition = document.getElementById('edit_position_id');
    const editDepartment = document.getElementById('edit_department_id');
    const editRoaming = document.getElementById('edit_roaming_attendance_enabled');
    if (editPosition) {
        editPosition.addEventListener('change', function () {
            const selectedOpt = this.selectedOptions[0];
            if (!selectedOpt) return;
            const deptId = selectedOpt.dataset.department;
            const isRoaming = selectedOpt.dataset.roaming === '1';

            if (deptId && editDepartment) {
                editDepartment.value = deptId;
            }
            if (editRoaming && isRoaming) {
                editRoaming.checked = true;
            }
        });
    }

    {{-- Evaluar alcance según rol en edición --}}
    function evaluateEditRoleScope() {
        const editRole = document.getElementById('edit_role');
        const editScopeContainer = document.getElementById('editScopeContainer');
        if (!editRole || !editScopeContainer) return;
        editScopeContainer.style.display = (editRole.value === 'employee') ? 'none' : 'block';
    }
    const editRoleEl = document.getElementById('edit_role');
    if (editRoleEl) editRoleEl.addEventListener('change', evaluateEditRoleScope);

    const editScopeType = document.getElementById('edit_scope_type');
    if (editScopeType) {
        editScopeType.addEventListener('change', function () {
            const val = this.value;
            const scopeZoneWrap = document.getElementById('editScopeZoneWrap');
            const scopeBranchWrap = document.getElementById('editScopeBranchWrap');
            if (scopeZoneWrap) scopeZoneWrap.classList.toggle('d-none', val !== 'zone');
            if (scopeBranchWrap) scopeBranchWrap.classList.toggle('d-none', val !== 'branch');
        });
    }

    async function openEditModal(id) {
        if (!editModalEl) return;
        const modalInstance = bootstrap.Modal.getOrCreateInstance(editModalEl);
        modalInstance.show();

        {{-- Limpiar errores previos --}}
        editForm.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
        editForm.querySelectorAll('.invalid-feedback').forEach(el => el.textContent = '');
        if (editErrorAlert) editErrorAlert.classList.add('d-none');

        {{-- Resetear a primera pestaña --}}
        const tab1Btn = document.getElementById('tab-edit-personal-btn');
        if (tab1Btn) bootstrap.Tab.getOrCreateInstance(tab1Btn).show();

        try {
            const res = await fetch(`/employees/${id}/edit`, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            });

            if (!res.ok) throw new Error('No se pudo cargar la información del colaborador.');

            const data = await res.json();
            const emp = data.employee;

            {{-- Form action y ID --}}
            editForm.action = `/employees/${emp.id}`;
            document.getElementById('edit_employee_id').value = emp.id;

            {{-- Título --}}
            const titleEl = document.getElementById('employeeEditModalLabel');
            if (titleEl) titleEl.textContent = `Editar Colaborador: ${emp.first_name} ${emp.last_name}`;

            {{-- Tab 1: Datos Personales --}}
            document.getElementById('edit_first_name').value = emp.first_name || '';
            document.getElementById('edit_last_name').value = emp.last_name || '';
            document.getElementById('edit_document_type').value = emp.document_type || 'dni';
            document.getElementById('edit_document_number').value = emp.document_number || '';
            document.getElementById('edit_phone').value = emp.phone || '';
            document.getElementById('edit_birth_date').value = emp.birth_date || '';
            document.getElementById('edit_email').value = emp.email || '';
            document.getElementById('edit_status').value = emp.status || 'active';

            {{-- Tab 2: Puesto y Rol --}}
            if (document.getElementById('edit_employee_code')) document.getElementById('edit_employee_code').value = emp.employee_code || '';
            if (document.getElementById('edit_position_id')) document.getElementById('edit_position_id').value = emp.position_id || '';
            if (document.getElementById('edit_department_id')) document.getElementById('edit_department_id').value = emp.department_id || '';
            if (document.getElementById('edit_hire_date')) document.getElementById('edit_hire_date').value = emp.hire_date || '';
            if (document.getElementById('edit_base_salary')) document.getElementById('edit_base_salary').value = emp.base_salary || 0;
        if (document.getElementById('edit_bonus')) document.getElementById('edit_bonus').value = emp.bonus || '';
            if (document.getElementById('edit_roaming_attendance_enabled')) document.getElementById('edit_roaming_attendance_enabled').checked = !!emp.roaming_attendance_enabled;
            if (document.getElementById('edit_role')) {
                document.getElementById('edit_role').value = emp.role || 'employee';
                evaluateEditRoleScope();
                window.filterCustomRoles?.('edit');
                if (document.getElementById('edit_custom_role_id')) document.getElementById('edit_custom_role_id').value = emp.custom_role_id || '';
            }
            if (document.getElementById('edit_scope_type')) {
                document.getElementById('edit_scope_type').value = emp.scope_type || 'global';
                const scopeZoneWrap = document.getElementById('editScopeZoneWrap');
                const scopeBranchWrap = document.getElementById('editScopeBranchWrap');
                if (scopeZoneWrap) scopeZoneWrap.classList.toggle('d-none', emp.scope_type !== 'zone');
                if (scopeBranchWrap) scopeBranchWrap.classList.toggle('d-none', emp.scope_type !== 'branch');
            }
            if (document.getElementById('edit_scope_zone_id')) document.getElementById('edit_scope_zone_id').value = emp.scope_zone_id || '';
            if (document.getElementById('edit_scope_branch_id')) document.getElementById('edit_scope_branch_id').value = emp.scope_branch_id || '';

            {{-- Tab 3: Sedes y Turno --}}
            window.renderEmployeeSchedule?.(emp.schedule);

            {{-- Sincronizar checkboxes de sucursales --}}
            const branchChecks = document.querySelectorAll('.edit-branch-check');
            const primaryRadios = document.querySelectorAll('.edit-primary-branch-radio');
            branchChecks.forEach(cb => {
                cb.checked = (emp.branch_ids || []).includes(parseInt(cb.value, 10));
            });
            primaryRadios.forEach(rb => {
                rb.checked = (parseInt(rb.value, 10) === parseInt(emp.primary_branch_id, 10));
            });

        } catch (err) {
            console.error('Error loading employee edit data:', err);
            showToast(err.message || 'Error al cargar datos del colaborador', 'danger');
            modalInstance.hide();
        }
    }

    {{-- Envío AJAX del formulario de edición --}}
    if (editForm) {
        editForm.addEventListener('submit', async function (e) {
            e.preventDefault();

            {{-- Limpiar errores previos --}}
            editForm.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
            editForm.querySelectorAll('.invalid-feedback').forEach(el => el.textContent = '');
            if (editErrorAlert) editErrorAlert.classList.add('d-none');

            if (btnSubmitEdit) btnSubmitEdit.disabled = true;
            if (btnSubmitEditSpinner) btnSubmitEditSpinner.classList.remove('d-none');
            if (btnSubmitEditIcon) btnSubmitEditIcon.classList.add('d-none');
            if (btnSubmitEditText) btnSubmitEditText.textContent = 'Guardando…';

            const formData = new FormData(editForm);

            try {
                const response = await fetch(editForm.action, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    }
                });

                const data = await response.json();

                if (response.status === 422) {
                    const errors = data.errors || {};
                    let firstErrElement = null;

                    for (const [key, messages] of Object.entries(errors)) {
                        const cleanKey = key.replace(/\.\d+/g, '');
                        const inputEl = editForm.querySelector(`[name="${cleanKey}"]`) ||
                                        editForm.querySelector(`[name="${cleanKey}[]"]`);
                        const errEl = document.getElementById(`edit_err_${cleanKey}`);

                        if (inputEl) {
                            inputEl.classList.add('is-invalid');
                            if (!firstErrElement) firstErrElement = inputEl;
                        }
                        if (errEl) {
                            errEl.textContent = messages[0];
                        }
                    }

                    if (editErrorAlert) {
                        editErrorText.textContent = data.message || 'Por favor revisa los campos señalados en rojo.';
                        editErrorAlert.classList.remove('d-none');
                    }

                    if (firstErrElement) {
                        const tabPane = firstErrElement.closest('.tab-pane');
                        if (tabPane && tabPane.id) {
                            const triggerBtn = document.querySelector(`[data-bs-target="#${tabPane.id}"]`);
                            if (triggerBtn) bootstrap.Tab.getOrCreateInstance(triggerBtn).show();
                        }
                        firstErrElement.focus();
                    }
                } else if (response.ok && data.success) {
                    bootstrap.Modal.getOrCreateInstance(editModalEl).hide();
                    showToast(data.message || 'Datos actualizados correctamente.', 'success');
                    fetchEmployees(currentInternalPage);
                } else {
                    throw new Error(data.message || 'Ocurrió un error inesperado');
                }
            } catch (err) {
                console.error('Error updating employee:', err);
                if (editErrorAlert) {
                    editErrorText.textContent = err.message || 'No se pudo actualizar el colaborador.';
                    editErrorAlert.classList.remove('d-none');
                }
            } finally {
                if (btnSubmitEdit) btnSubmitEdit.disabled = false;
                if (btnSubmitEditSpinner) btnSubmitEditSpinner.classList.add('d-none');
                if (btnSubmitEditIcon) btnSubmitEditIcon.classList.remove('d-none');
                if (btnSubmitEditText) btnSubmitEditText.textContent = 'Guardar Cambios';
            }
        });
    }

    {{-- ========================================================================= --}}
    {{-- 6. Modal Dialog: Expediente y Ficha del Colaborador --}}
    {{-- ========================================================================= --}}
    const fileModalEl = document.getElementById('employeeFileModal');
    const fileModalBody = document.getElementById('employeeFileModalBody');

    {{-- Función global y robusta de copiado al portapapeles (con fallback seguro) --}}
    window.copyLegajoText = function (text, btn) {
        if (!text || text === '—') return;

        function notifySuccess() {
            if (btn) {
                const icon = btn.querySelector('i');
                if (icon) {
                    const origClass = icon.className;
                    icon.className = 'bi bi-check2 text-success';
                    setTimeout(() => { icon.className = origClass; }, 1600);
                }
            }
            showToast(`Copiado al portapapeles: ${text}`, 'success');
        }

        function fallbackCopy(val) {
            try {
                const textArea = document.createElement('textarea');
                textArea.value = val;
                textArea.style.position = 'fixed';
                textArea.style.left = '-999999px';
                textArea.style.top = '-999999px';
                document.body.appendChild(textArea);
                textArea.focus();
                textArea.select();
                const ok = document.execCommand('copy');
                document.body.removeChild(textArea);
                if (ok) {
                    notifySuccess();
                } else {
                    showToast('No se pudo copiar automáticamente', 'danger');
                }
            } catch (err) {
                console.error('Fallback copy error:', err);
                showToast('Error al copiar al portapapeles', 'danger');
            }
        }

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(notifySuccess).catch(() => fallbackCopy(text));
        } else {
            fallbackCopy(text);
        }
    };

    {{-- Delegación de eventos para el botón de copiado --}}
    document.addEventListener('click', function (e) {
        const copyBtn = e.target.closest('.legajo-copy-btn');
        if (copyBtn) {
            e.preventDefault();
            e.stopPropagation();
            const textToCopy = copyBtn.getAttribute('data-copy') || copyBtn.dataset.copy;
            if (textToCopy) {
                window.copyLegajoText(textToCopy, copyBtn);
            }
        }
    });

    {{-- 1. Alternador de números de cuenta (máscara / desenmascarado) --}}
    window.isNumbersMasked = false;
    window.toggleEmployeeAccountMask = function () {
        window.isNumbersMasked = !window.isNumbersMasked;
        const body = document.getElementById('fileModalBody');
        if (body) {
            const unmasked = body.querySelectorAll('.unmasked-val');
            const masked = body.querySelectorAll('.masked-val');
            const label = document.getElementById('btnMaskLabel');
            const icon = document.getElementById('btnMaskIcon');

            unmasked.forEach(el => el.classList.toggle('d-none', window.isNumbersMasked));
            masked.forEach(el => el.classList.toggle('d-none', !window.isNumbersMasked));

            if (label) label.textContent = window.isNumbersMasked ? 'Mostrar números' : 'Ocultar números';
            if (icon) icon.className = window.isNumbersMasked ? 'bi bi-eye' : 'bi bi-eye-slash';
        }
    };

    {{-- 2. Alternador de Modo Lectura / Modo Modificar Ficha --}}
    window.toggleEmployeeProfileEdit = function (forceState) {
        const viewSection = document.getElementById('profileDetailsView');
        const editSection = document.getElementById('profileEditFormWrapper');
        const btnEditLabel = document.getElementById('btnEditProfileText');
        const btnEdit = document.getElementById('btnToggleEditProfile');
        const btnEditIcon = document.getElementById('btnEditProfileIcon');

        if (!viewSection || !editSection) return;

        const isCurrentlyEditing = !editSection.classList.contains('d-none');
        const showEdit = (typeof forceState === 'boolean') ? forceState : !isCurrentlyEditing;

        if (showEdit) {
            viewSection.classList.add('d-none');
            editSection.classList.remove('d-none');
            if (btnEditLabel) btnEditLabel.textContent = 'Ver Ficha';
            if (btnEditIcon) btnEditIcon.className = 'bi bi-eye';
            if (btnEdit) {
                btnEdit.classList.add('is-editing');
                btnEdit.setAttribute('title', 'Volver a la vista de lectura');
            }
        } else {
            editSection.classList.add('d-none');
            viewSection.classList.remove('d-none');
            if (btnEditLabel) btnEditLabel.textContent = 'Modificar Ficha';
            if (btnEditIcon) btnEditIcon.className = 'bi bi-pencil-square';
            if (btnEdit) {
                btnEdit.classList.remove('is-editing');
                btnEdit.setAttribute('title', 'Modificar datos de la ficha');
            }
        }
    };

    {{-- 3. Envío Asíncrono del Formulario de Edición de Ficha --}}
    window.submitEmployeeProfileForm = async function (e, form) {
        if (e) {
            e.preventDefault();
            e.stopPropagation();
        }
        const formProfile = form || document.getElementById('formUpdateEmployeeProfile');
        if (!formProfile) return false;

        const btnSave = document.getElementById('btnSaveProfile');
        const alertBox = document.getElementById('profileFormAlert');
        if (alertBox) alertBox.classList.add('d-none');
        if (btnSave) {
            btnSave.disabled = true;
            btnSave.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span>Guardando…';
        }

        try {
            const formData = new FormData(formProfile);
            const res = await fetch(formProfile.action, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                body: formData
            });

            const data = await res.json();
            if (!res.ok) {
                const msg = data.message || Object.values(data.errors || {})[0]?.[0] || 'Por favor revisa los datos ingresados.';
                throw new Error(msg);
            }

            if (typeof showToast === 'function') {
                showToast('Ficha laboral actualizada con éxito.', 'success');
            }

            const match = formProfile.action.match(/employees\/(\d+)/);
            const empId = match ? match[1] : null;
            if (empId && typeof openFileModal === 'function') {
                await openFileModal(empId);
            } else {
                window.location.reload();
            }
        } catch (err) {
            if (alertBox) {
                alertBox.textContent = err.message || 'Error al actualizar la ficha.';
                alertBox.classList.remove('d-none');
            }
            if (btnSave) {
                btnSave.disabled = false;
                btnSave.innerHTML = '<i class="bi bi-check2-circle"></i><span>Guardar Cambios</span>';
            }
        }
        return false;
    };

    {{-- Delegación de eventos de respaldo --}}
    document.addEventListener('click', function (e) {
        const maskBtn = e.target.closest('#btnToggleMaskAccounts');
        if (maskBtn && !maskBtn.hasAttribute('onclick')) {
            e.preventDefault();
            window.toggleEmployeeAccountMask();
            return;
        }

        const toggleBtn = e.target.closest('#btnToggleEditProfile');
        if (toggleBtn && !toggleBtn.hasAttribute('onclick')) {
            e.preventDefault();
            window.toggleEmployeeProfileEdit();
            return;
        }

        const cancelBtn = e.target.closest('#btnCancelEditProfile');
        if (cancelBtn && !cancelBtn.hasAttribute('onclick')) {
            e.preventDefault();
            window.toggleEmployeeProfileEdit(false);
            return;
        }
    });

    document.addEventListener('submit', function (e) {
        const formProfile = e.target.closest('#formUpdateEmployeeProfile');
        if (formProfile && !formProfile.hasAttribute('onsubmit')) {
            window.submitEmployeeProfileForm(e, formProfile);
        }
    });

    async function openFileModal(id) {
        if (!fileModalEl || !fileModalBody) return;
        const modalInstance = bootstrap.Modal.getOrCreateInstance(fileModalEl);
        modalInstance.show();

        fileModalBody.innerHTML = `
            <div class="py-5 text-center text-muted">
                <div class="spinner-border spinner-border-sm text-primary mb-2" role="status"></div>
                <div class="small fw-semibold">Cargando expediente digital…</div>
            </div>
        `;

        try {
            const res = await fetch(`/employees/${id}/file`, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'text/html'
                }
            });

            if (!res.ok) throw new Error('No se pudo cargar el expediente del colaborador.');

            const html = await res.text();
            fileModalBody.innerHTML = html;

            {{-- Re-ejecutar scripts que vengan dentro del HTML inyectado --}}
            const scripts = fileModalBody.querySelectorAll('script');
            scripts.forEach(oldScript => {
                const newScript = document.createElement('script');
                Array.from(oldScript.attributes).forEach(attr => newScript.setAttribute(attr.name, attr.value));
                newScript.appendChild(document.createTextNode(oldScript.innerHTML));
                oldScript.parentNode.replaceChild(newScript, oldScript);
            });
        } catch (err) {
            console.error('Error loading employee file:', err);
            fileModalBody.innerHTML = `
                <div class="alert alert-danger py-3 px-4 d-flex align-items-center gap-2 m-3">
                    <i class="bi bi-exclamation-triangle-fill fs-5"></i>
                    <div>${Panel.esc(err.message || 'No se pudo cargar el expediente. Por favor inténtalo de nuevo.')}</div>
                </div>
            `;
        }
    }
});
</script>
@endsection
