@extends('layouts.app')

@section('title', 'Calendario de asistencia')

@section('styles')
<style>
/* ==========================================================================
   CALENDARIO EJECUTIVO DE ASISTENCIA
   Diseño de Alta Fidelidad, Contraste Perfecto, Modo Claro & Oscuro Adaptativo
   ========================================================================== */
.cal-app-shell,
#dayDetailModal {
    --cal-bg: var(--bg-surface, #ffffff);
    --cal-bg-subtle: var(--bg-surface-elevated, #f8fafc);
    --cal-bg-elevated: var(--bg-surface-elevated, #f1f5f9);
    --cal-border: var(--border-color, #e2e8f0);
    --cal-border-light: var(--border-color-light, #cbd5e1);
    --cal-text-main: var(--text-primary, #0f172a);
    --cal-text-muted: var(--text-secondary, #475569);
    --cal-text-subtle: var(--text-muted, #64748b);
    --cal-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
}

[data-bs-theme="dark"] .cal-app-shell,
[data-bs-theme="dark"] #dayDetailModal {
    --cal-bg: #111622;
    --cal-bg-subtle: #161d2d;
    --cal-bg-elevated: #1a2234;
    --cal-border: #243048;
    --cal-border-light: #2d3d5c;
    --cal-text-main: #f8fafc;
    --cal-text-muted: #94a3b8;
    --cal-text-subtle: #64748b;
    --cal-shadow: 0 4px 16px rgba(0, 0, 0, 0.35);
}

.cal-app-shell {
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
    position: relative;
    max-width: 100%;
}

/* 1. Header con Título Principal y Exportación */
.cal-header-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 1rem;
    margin-bottom: 0.25rem;
}

.cal-header-title-box h1 {
    font-size: 1.65rem;
    font-weight: 800;
    color: var(--cal-text-main) !important;
    letter-spacing: -0.02em;
    line-height: 1.2;
}

.cal-header-title-box p {
    color: var(--cal-text-muted) !important;
    font-size: 0.875rem;
    margin: 0;
}

/* 2. Tarjetas KPI Ejecutivas */
.cal-kpis-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 1rem;
}

.cal-kpi-card {
    background: var(--cal-bg);
    border: 1px solid var(--cal-border);
    border-radius: 12px;
    padding: 1.15rem 1.25rem;
    display: flex;
    align-items: center;
    gap: 1.15rem;
    box-shadow: var(--cal-shadow);
    transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
}
.cal-kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.08);
    border-color: var(--cal-border-light);
}

.cal-kpi-icon-box {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.35rem;
    flex-shrink: 0;
}

.cal-kpi-value {
    font-size: 1.6rem;
    font-weight: 800;
    line-height: 1.1;
    color: var(--cal-text-main);
    letter-spacing: -0.02em;
}

.cal-kpi-title {
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    font-weight: 700;
    color: var(--cal-text-subtle);
    margin-top: 3px;
}

/* 3. Barra de Control de Navegación & Filtros Integrados */
.cal-control-panel {
    background: var(--cal-bg);
    border: 1px solid var(--cal-border);
    border-radius: 14px;
    padding: 1rem 1.25rem;
    box-shadow: var(--cal-shadow);
    display: flex;
    flex-direction: column;
    gap: 0.9rem;
}

.cal-control-row-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 1rem;
}

/* Navegador de Mes Moderno (Sin confusión con filtros de días) */
.cal-month-nav {
    display: inline-flex;
    align-items: center;
    gap: 0.65rem;
    flex-wrap: wrap;
}

.cal-nav-arrow-btn {
    width: 36px;
    height: 36px;
    border-radius: 9px;
    border: 1px solid var(--cal-border-light);
    background: var(--cal-bg-elevated);
    color: var(--cal-text-main);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.15s ease;
    font-size: 0.95rem;
}
.cal-nav-arrow-btn:hover {
    background: var(--cal-bg);
    border-color: #2563eb;
    color: #2563eb;
    transform: translateY(-1px);
}

.cal-month-display-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.55rem;
    padding: 0 0.5rem;
    color: var(--cal-text-main);
}
.cal-month-display-pill i {
    font-size: 1.15rem;
    color: #2563eb;
}
.cal-month-title {
    font-size: 1.18rem;
    font-weight: 800;
    text-transform: capitalize;
    letter-spacing: -0.01em;
}

.cal-btn-current-month {
    height: 32px;
    padding: 0 0.85rem;
    border-radius: 8px;
    border: 1px solid rgba(37, 99, 235, 0.3);
    background: rgba(37, 99, 235, 0.08);
    color: #2563eb;
    font-size: 0.775rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    cursor: pointer;
    transition: all 0.15s ease;
}
.cal-btn-current-month:hover {
    background: #2563eb;
    color: #ffffff;
    border-color: #2563eb;
}
[data-bs-theme="dark"] .cal-btn-current-month {
    color: #60a5fa;
    border-color: rgba(96, 165, 250, 0.35);
    background: rgba(37, 99, 235, 0.16);
}
[data-bs-theme="dark"] .cal-btn-current-month:hover {
    background: #2563eb;
    color: #ffffff;
}

.cal-sync-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 0.775rem;
    font-weight: 700;
    color: #065f46;
    background: #d1fae5;
    border: 1px solid #a7f3d0;
    padding: 5px 11px;
    border-radius: 8px;
}
[data-bs-theme="dark"] .cal-sync-pill {
    background: rgba(16, 185, 129, 0.15);
    border-color: rgba(16, 185, 129, 0.35);
    color: #34d399;
}
.cal-sync-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #10b981;
    box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.3);
}

/* Fila de Filtros Asíncronos */
.cal-control-row-bottom {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 0.75rem;
    padding-top: 0.85rem;
    border-top: 1px solid var(--cal-border);
}

.cal-filters-group {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    flex-wrap: wrap;
    flex-grow: 1;
}

.cal-search-container {
    position: relative;
    max-width: 260px;
    width: 100%;
}
.cal-search-container i {
    position: absolute;
    left: 11px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--cal-text-subtle);
    font-size: 0.85rem;
    pointer-events: none;
}
.cal-search-input {
    height: 38px;
    padding-left: 34px;
    padding-right: 12px;
    border-radius: 8px;
    border: 1px solid var(--cal-border-light);
    font-size: 0.825rem;
    color: var(--cal-text-main);
    width: 100%;
    background: var(--cal-bg-subtle);
    font-weight: 500;
    transition: all 0.12s ease;
}
.cal-search-input:focus {
    outline: none;
    border-color: #2563eb;
    background: var(--cal-bg);
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
}

.cal-select-control,
.cal-colab-switcher {
    height: 38px;
    border-radius: 8px;
    border: 1px solid var(--cal-border-light);
    font-size: 0.825rem;
    padding-left: 0.85rem;
    padding-right: 2.25rem !important;
    color: var(--cal-text-main);
    background-color: var(--cal-bg-subtle);
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' fill='%2364748b'%3E%3Cpath fill-rule='evenodd' d='M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 0.75rem center;
    background-size: 13px 11px;
    appearance: none;
    -webkit-appearance: none;
    -moz-appearance: none;
    font-weight: 500;
    max-width: 215px;
    cursor: pointer;
    outline: none !important;
    transition: all 0.15s ease;
}

.cal-colab-switcher {
    max-width: 250px;
    font-weight: 600;
}

[data-bs-theme="dark"] .cal-select-control,
[data-bs-theme="dark"] .cal-colab-switcher {
    background-color: var(--cal-bg-subtle);
    border-color: var(--cal-border-light);
    color: var(--cal-text-main);
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' fill='%2394a3b8'%3E%3Cpath fill-rule='evenodd' d='M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z'/%3E%3C/svg%3E");
}

.cal-select-control:focus,
.cal-select-control:focus-visible,
.cal-colab-switcher:focus,
.cal-colab-switcher:focus-visible,
.cal-search-input:focus,
.cal-search-input:focus-visible {
    outline: none !important;
    border-color: #2563eb !important;
    background-color: var(--cal-bg) !important;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.22) !important;
}

[data-bs-theme="dark"] .cal-select-control:focus,
[data-bs-theme="dark"] .cal-select-control:focus-visible,
[data-bs-theme="dark"] .cal-colab-switcher:focus,
[data-bs-theme="dark"] .cal-colab-switcher:focus-visible,
[data-bs-theme="dark"] .cal-search-input:focus,
[data-bs-theme="dark"] .cal-search-input:focus-visible {
    outline: none !important;
    border-color: #3b82f6 !important;
    background-color: var(--cal-bg) !important;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.3) !important;
}

[data-bs-theme="dark"] .cal-select-control option,
[data-bs-theme="dark"] .cal-colab-switcher option {
    background: #161d2d;
    color: #f8fafc;
}

.cal-submit-btn {
    height: 38px;
    padding: 0 1rem;
    border-radius: 8px;
    background: #2563eb;
    color: #ffffff;
    border: none;
    font-size: 0.825rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    cursor: pointer;
    box-shadow: 0 1px 3px rgba(37, 99, 235, 0.25);
    transition: all 0.12s ease;
    outline: none !important;
}
.cal-submit-btn:hover {
    background: #1d4ed8;
    transform: translateY(-1px);
}
.cal-submit-btn:focus,
.cal-submit-btn:focus-visible,
.cal-clear-btn:focus,
.cal-clear-btn:focus-visible {
    outline: none !important;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.25) !important;
}

.cal-clear-btn {
    height: 38px;
    width: 38px;
    border-radius: 8px;
    border: 1px solid var(--cal-border-light);
    background: var(--cal-bg-subtle);
    color: var(--cal-text-muted);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.12s ease;
    outline: none !important;
}
.cal-clear-btn:hover {
    background: var(--cal-bg-elevated);
    color: var(--cal-text-main);
    border-color: var(--cal-text-subtle);
}

/* Conmutador de Vistas */
.cal-views-group {
    display: flex;
    align-items: center;
    gap: 0.6rem;
}

.cal-seg-toggle {
    display: inline-flex;
    background: var(--cal-bg-elevated);
    border: 1px solid var(--cal-border-light);
    border-radius: 8px;
    padding: 2px;
}
.cal-seg-button {
    height: 32px;
    padding: 0 0.85rem;
    border-radius: 6px;
    border: none;
    background: transparent;
    color: var(--cal-text-muted);
    font-size: 0.7875rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    cursor: pointer;
    transition: all 0.12s ease;
}
.cal-seg-button.active {
    background: var(--cal-bg);
    color: var(--cal-text-main);
    box-shadow: var(--cal-shadow);
}

/* ==========================================================================
   4. TABLERO CALENDARIO (7 COLUMNAS LUN - DOM) — MODO CLARO & OSCURO
   ========================================================================== */
.cal-grid-card {
    background: var(--cal-bg);
    border: 1px solid var(--cal-border);
    border-radius: 8px;
    box-shadow: var(--cal-shadow);
    overflow: hidden;
}

.cal-grid-header {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    background: var(--cal-bg-elevated);
    border-bottom: 2px solid var(--cal-border);
}

.cal-weekday-label {
    padding: 0.75rem 0.5rem;
    text-align: center;
    font-size: 0.75rem;
    font-weight: 800;
    color: var(--cal-text-muted);
    text-transform: uppercase;
    letter-spacing: 0.06em;
    border-right: 1px solid var(--cal-border);
}
.cal-weekday-label:last-child {
    border-right: none;
}
.cal-weekday-label.is-weekend {
    background: var(--cal-bg-subtle);
    color: var(--cal-text-subtle);
}

.cal-grid-body {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
}

/* Celda Diaria */
.cal-day-cell {
    min-height: 116px;
    border-right: 1px solid var(--cal-border);
    border-bottom: 1px solid var(--cal-border);
    padding: 9px 10px;
    background: var(--cal-bg);
    display: flex;
    flex-direction: column;
    justify-content: flex-start;
    transition: all 0.15s cubic-bezier(0.16, 1, 0.3, 1);
    cursor: pointer;
    position: relative;
    user-select: none;
}
.cal-day-cell:nth-child(7n) {
    border-right: none;
}
.cal-day-cell:hover {
    background: var(--cal-bg-subtle);
    box-shadow: inset 0 0 0 2px #3b82f6;
    z-index: 5;
}
.cal-day-cell.is-weekend {
    background: var(--cal-bg);
}
.cal-day-cell.is-today {
    background: #eff6ff !important;
    box-shadow: inset 0 0 0 2px #2563eb;
}
[data-bs-theme="dark"] .cal-day-cell.is-today {
    background: rgba(37, 99, 235, 0.15) !important;
    box-shadow: inset 0 0 0 2px #3b82f6;
}
.cal-day-cell.is-padded {
    background: var(--cal-bg-subtle);
    opacity: 0.45;
    cursor: default;
}

/* Cabecera de la Celda */
.cal-cell-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 7px;
}

.cal-cell-day-num {
    font-size: 0.95rem;
    font-weight: 800;
    color: var(--cal-text-main);
    line-height: 1;
    font-variant-numeric: tabular-nums;
}
.cal-cell-day-num.is-padded {
    color: var(--cal-text-subtle);
    font-weight: 500;
}

.cal-today-badge {
    width: 26px;
    height: 26px;
    border-radius: 50%;
    background: #2563eb;
    color: #ffffff !important;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.825rem;
    font-weight: 800;
    box-shadow: 0 2px 6px rgba(37, 99, 235, 0.4);
}
.cal-today-pill {
    font-size: 0.65rem;
    font-weight: 800;
    color: #2563eb;
    background: #dbeafe;
    border: 1px solid #bfdbfe;
    padding: 1px 6px;
    border-radius: 4px;
}
[data-bs-theme="dark"] .cal-today-pill {
    color: #60a5fa;
    background: rgba(37, 99, 235, 0.2);
    border-color: rgba(96, 165, 250, 0.3);
}

/* Píldoras de Asistencia de Alto Contraste */
.cal-pills-stack {
    display: flex;
    flex-direction: column;
    gap: 4px;
    flex-grow: 1;
}

.cal-badge-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 3.5px 8px;
    border-radius: 6px;
    font-size: 0.75rem;
    font-weight: 700;
    line-height: 1.25;
    border: 1px solid transparent;
    width: fit-content;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.03);
}
.cal-badge-pill i {
    font-size: 0.775rem;
}

/* Colores Adaptativos Claro & Oscuro */
.cal-badge-pill.is-present {
    background: #dcfce7;
    border-color: #86efac;
    color: #14532d;
}
.cal-badge-pill.is-present i { color: #16a34a; }
[data-bs-theme="dark"] .cal-badge-pill.is-present {
    background: rgba(16, 185, 129, 0.18);
    border-color: rgba(16, 185, 129, 0.35);
    color: #34d399;
}
[data-bs-theme="dark"] .cal-badge-pill.is-present i { color: #34d399; }

.cal-badge-pill.is-late {
    background: #fef3c7;
    border-color: #fcd34d;
    color: #78350f;
}
.cal-badge-pill.is-late i { color: #d97706; }
[data-bs-theme="dark"] .cal-badge-pill.is-late {
    background: rgba(245, 158, 11, 0.18);
    border-color: rgba(245, 158, 11, 0.35);
    color: #fbbf24;
}
[data-bs-theme="dark"] .cal-badge-pill.is-late i { color: #fbbf24; }

.cal-badge-pill.is-absent {
    background: #fee2e2;
    border-color: #fca5a5;
    color: #7f1d1d;
}
.cal-badge-pill.is-absent i { color: #dc2626; }
[data-bs-theme="dark"] .cal-badge-pill.is-absent {
    background: rgba(239, 68, 68, 0.18);
    border-color: rgba(239, 68, 68, 0.35);
    color: #f87171;
}
[data-bs-theme="dark"] .cal-badge-pill.is-absent i { color: #f87171; }

.cal-badge-pill.is-excused {
    background: #dbeafe;
    border-color: #93c5fd;
    color: #1e3a8a;
}
.cal-badge-pill.is-excused i { color: #2563eb; }
[data-bs-theme="dark"] .cal-badge-pill.is-excused {
    background: rgba(59, 130, 246, 0.18);
    border-color: rgba(59, 130, 246, 0.35);
    color: #60a5fa;
}
[data-bs-theme="dark"] .cal-badge-pill.is-excused i { color: #60a5fa; }

.cal-badge-pill.is-rest {
    background: #f1f5f9;
    border-color: #cbd5e1;
    color: #475569;
    font-weight: 600;
}
[data-bs-theme="dark"] .cal-badge-pill.is-rest {
    background: rgba(100, 116, 139, 0.18);
    border-color: rgba(100, 116, 139, 0.35);
    color: #94a3b8;
}

/* Vista Individual del Colaborador */
.cal-person-stack {
    display: flex;
    flex-direction: column;
    gap: 4px;
    height: 100%;
}

.cal-time-row {
    font-size: 0.725rem;
    font-family: ui-monospace, SFMono-Regular, monospace;
    font-weight: 700;
    color: var(--cal-text-main);
    display: flex;
    align-items: center;
    gap: 4px;
}

.cal-evidence-trigger {
    margin-top: auto;
    height: 24px;
    padding: 0 8px;
    border-radius: 6px;
    border: 1px solid #bfdbfe;
    background: #eff6ff;
    color: #1d4ed8;
    font-size: 0.7rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    cursor: pointer;
    transition: all 0.12s ease;
    width: fit-content;
}
.cal-evidence-trigger:hover {
    background: #2563eb;
    color: #ffffff;
    border-color: #2563eb;
    box-shadow: 0 2px 5px rgba(37, 99, 235, 0.3);
}
[data-bs-theme="dark"] .cal-evidence-trigger {
    background: rgba(37, 99, 235, 0.18);
    color: #60a5fa;
    border-color: rgba(96, 165, 250, 0.3);
}

/* Indicador de acción al pasar el mouse */
.cal-hover-action {
    margin-top: auto;
    font-size: 0.7rem;
    font-weight: 700;
    color: #2563eb;
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 3px;
    opacity: 0;
    transform: translateY(2px);
    transition: all 0.15s ease;
}
[data-bs-theme="dark"] .cal-hover-action {
    color: #60a5fa;
}
.cal-day-cell:hover .cal-hover-action {
    opacity: 1;
    transform: translateY(0);
}

/* ==========================================================================
   BOTÓN EXPORTAR EXCEL (ADAPTATIVO Y LUMINOSO)
   ========================================================================== */
.cal-btn-excel {
    height: 38px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 0.825rem;
    padding: 0 1.15rem;
    color: #15803d;
    background: #f0fdf4;
    border: 1px solid #86efac;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    text-decoration: none;
    transition: all 0.15s ease;
}
.cal-btn-excel:hover {
    background: #16a34a;
    color: #ffffff;
    border-color: #16a34a;
    box-shadow: 0 4px 12px rgba(22, 163, 74, 0.25);
}
[data-bs-theme="dark"] .cal-btn-excel {
    color: #34d399 !important;
    background: rgba(16, 185, 129, 0.14) !important;
    border-color: rgba(52, 211, 153, 0.45) !important;
    box-shadow: 0 0 14px rgba(16, 185, 129, 0.12);
}
[data-bs-theme="dark"] .cal-btn-excel:hover {
    background: #059669 !important;
    color: #ffffff !important;
    border-color: #10b981 !important;
    box-shadow: 0 0 20px rgba(16, 185, 129, 0.35);
}

/* ==========================================================================
   MODAL DE JORNADA DEL DÍA — MODO CLARO & OSCURO
   ========================================================================== */
#dayDetailModal .modal-dialog {
    max-width: 800px;
    margin: 1.25rem auto;
    max-height: calc(100vh - 2.5rem);
    height: auto;
}

#dayDetailModal .modal-content {
    background: var(--cal-bg) !important;
    border: 1px solid var(--cal-border) !important;
    border-radius: 12px;
    box-shadow: 0 25px 60px -10px rgba(0, 0, 0, 0.45);
    max-height: calc(100vh - 2.5rem);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

#dayDetailModal .modal-header {
    background: var(--cal-bg) !important;
    border-bottom: 1px solid var(--cal-border) !important;
    padding: 1rem 1.4rem;
    flex-shrink: 0;
}

#dayDetailModal .modal-body {
    background: var(--cal-bg-subtle) !important;
    padding: 1.25rem 1.4rem;
    flex: 1 1 auto;
    overflow-y: auto !important;
    scrollbar-width: thin;
}

#dayDetailModal .modal-footer {
    background: var(--cal-bg) !important;
    border-top: 1px solid var(--cal-border) !important;
    padding: 0.85rem 1.4rem;
    flex-shrink: 0;
}

[data-bs-theme="dark"] #dayDetailModal .btn-close {
    filter: invert(1) grayscale(100%) brightness(200%);
}

.cal-modal-header-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    background: rgba(37, 99, 235, 0.08);
    color: #2563eb;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    border: 1px solid rgba(37, 99, 235, 0.2);
    flex-shrink: 0;
}
[data-bs-theme="dark"] .cal-modal-header-icon {
    color: #60a5fa;
    border-color: rgba(96, 165, 250, 0.35);
    background: rgba(37, 99, 235, 0.22);
}

.cal-modal-stat-card {
    padding: 0.85rem 1rem;
    border-radius: 10px;
    border: 1px solid var(--cal-border) !important;
    display: flex;
    align-items: center;
    gap: 0.85rem;
    background: var(--cal-bg) !important;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.02);
}
.cal-modal-stat-icon {
    width: 38px;
    height: 38px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.15rem;
    flex-shrink: 0;
}

.cal-modal-count-pill {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    border-radius: 6px;
    background: var(--cal-bg-elevated);
    border: 1px solid var(--cal-border);
    color: var(--cal-text-muted);
    font-size: 0.75rem;
    font-weight: 600;
}

.cal-modal-cards-list {
    display: flex;
    flex-direction: column;
    gap: 1rem;
    padding: 2px 2px 8px 2px;
}

.cal-modal-employee-card {
    background: var(--cal-bg) !important;
    border: 1px solid var(--cal-border) !important;
    border-radius: 14px;
    padding: 1.2rem 1.45rem;
    margin-bottom: 0;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 1.25rem;
    transition: all 0.18s ease;
}
.cal-modal-employee-card:hover {
    border-color: var(--cal-border-light) !important;
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.08);
    transform: translateY(-2px);
}

.cal-modal-avatar {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    font-weight: 700;
    font-size: 0.9rem;
    background: rgba(37, 99, 235, 0.08);
    color: #2563eb;
    border: 1px solid rgba(37, 99, 235, 0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
[data-bs-theme="dark"] .cal-modal-avatar {
    background: rgba(37, 99, 235, 0.18);
    color: #60a5fa;
    border-color: rgba(96, 165, 250, 0.3);
}

.cal-modal-avatar-img {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    object-fit: cover;
    border: 1.5px solid var(--cal-border);
    flex-shrink: 0;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
}

.cal-avatar-img {
    width: 34px;
    height: 34px;
    border-radius: 8px;
    object-fit: cover;
    border: 1px solid var(--cal-border);
    flex-shrink: 0;
}

/* Status Badges en el Modal con colores naturales */
.cal-modal-status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 0.775rem;
    font-weight: 600;
    padding: 5px 12px;
    border-radius: 8px;
    border: 1px solid transparent;
    line-height: 1.2;
}
.cal-modal-status-badge i {
    font-size: 0.8rem;
    margin-right: 2px;
}

.cal-modal-status-badge.is-present {
    background: #dcfce7;
    border-color: #86efac;
    color: #166534;
}
.cal-modal-status-badge.is-present i { color: #16a34a; }
[data-bs-theme="dark"] .cal-modal-status-badge.is-present {
    background: rgba(16, 185, 129, 0.16);
    border-color: rgba(16, 185, 129, 0.35);
    color: #34d399;
}
[data-bs-theme="dark"] .cal-modal-status-badge.is-present i { color: #34d399; }

.cal-modal-status-badge.is-late {
    background: #fef3c7;
    border-color: #fcd34d;
    color: #92400e;
}
.cal-modal-status-badge.is-late i { color: #d97706; }
[data-bs-theme="dark"] .cal-modal-status-badge.is-late {
    background: rgba(245, 158, 11, 0.16);
    border-color: rgba(245, 158, 11, 0.35);
    color: #fbbf24;
}
[data-bs-theme="dark"] .cal-modal-status-badge.is-late i { color: #fbbf24; }

.cal-modal-status-badge.is-absent {
    background: #fee2e2;
    border-color: #fca5a5;
    color: #991b1b;
}
.cal-modal-status-badge.is-absent i { color: #dc2626; }
[data-bs-theme="dark"] .cal-modal-status-badge.is-absent {
    background: rgba(239, 68, 68, 0.16);
    border-color: rgba(239, 68, 68, 0.35);
    color: #f87171;
}
[data-bs-theme="dark"] .cal-modal-status-badge.is-absent i { color: #f87171; }

.cal-modal-status-badge.is-excused {
    background: #dbeafe;
    border-color: #93c5fd;
    color: #1e40af;
}
.cal-modal-status-badge.is-excused i { color: #2563eb; }
[data-bs-theme="dark"] .cal-modal-status-badge.is-excused {
    background: rgba(59, 130, 246, 0.16);
    border-color: rgba(59, 130, 246, 0.35);
    color: #60a5fa;
}
[data-bs-theme="dark"] .cal-modal-status-badge.is-excused i { color: #60a5fa; }

.cal-modal-status-badge.is-incomplete {
    background: #fef9c3;
    border-color: #fde047;
    color: #854d0e;
}
.cal-modal-status-badge.is-incomplete i { color: #ca8a04; }
[data-bs-theme="dark"] .cal-modal-status-badge.is-incomplete {
    background: rgba(234, 179, 8, 0.16);
    border-color: rgba(234, 179, 8, 0.35);
    color: #facc15;
}
[data-bs-theme="dark"] .cal-modal-status-badge.is-incomplete i { color: #facc15; }

/* Botón Ver Evidencia */
.cal-modal-btn-evidence {
    height: 34px;
    padding: 0 14px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 0.8rem;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    color: #1d4ed8;
    cursor: pointer;
    transition: all 0.15s ease;
    text-decoration: none;
}
.cal-modal-btn-evidence i {
    font-size: 0.85rem;
    margin-right: 4px;
}
.cal-modal-btn-evidence:hover {
    background: #2563eb;
    color: #ffffff;
    border-color: #2563eb;
    box-shadow: 0 2px 8px rgba(37, 99, 235, 0.25);
}
[data-bs-theme="dark"] .cal-modal-btn-evidence {
    background: rgba(37, 99, 235, 0.16);
    border-color: rgba(96, 165, 250, 0.35);
    color: #60a5fa;
}
[data-bs-theme="dark"] .cal-modal-btn-evidence:hover {
    background: #2563eb;
    color: #ffffff;
    border-color: #3b82f6;
    box-shadow: 0 0 12px rgba(59, 130, 246, 0.35);
}

.cal-modal-clock-box {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    font-size: 0.85rem;
    font-family: ui-monospace, SFMono-Regular, monospace;
    font-weight: 700;
    color: var(--cal-text-main);
}
.cal-modal-clock-box i {
    margin-right: 6px;
    font-size: 0.9rem;
}

.cal-modal-btn-monitor {
    height: 38px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 0.825rem;
    padding: 0 1.25rem;
    background: var(--cal-bg-subtle);
    border: 1px solid var(--cal-border);
    color: var(--cal-text-main);
    display: inline-flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
    transition: all 0.15s ease;
}
.cal-modal-btn-monitor i {
    margin-right: 4px;
}
.cal-modal-btn-monitor:hover {
    background: var(--cal-bg-elevated);
    border-color: #2563eb;
    color: #2563eb;
}
[data-bs-theme="dark"] .cal-modal-btn-monitor:hover {
    border-color: #60a5fa;
    color: #60a5fa;
}

.cal-modal-btn-close {
    height: 38px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 0.825rem;
    padding: 0 1.5rem;
    background: var(--cal-bg-elevated);
    border: 1px solid var(--cal-border);
    color: var(--cal-text-main);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.15s ease;
}
.cal-modal-btn-close:hover {
    background: #e2e8f0;
    color: #0f172a;
}
[data-bs-theme="dark"] .cal-modal-btn-close:hover {
    background: #1e293b;
    color: #f8fafc;
}

/* ==========================================================================
   5. VISTA MATRIZ TRADICIONAL
   ========================================================================== */
.cal-matrix-scroll {
    max-height: 68vh;
    overflow-x: auto;
    overflow-y: auto;
    scrollbar-width: thin;
}
.cal-matrix-table {
    border-collapse: separate;
    border-spacing: 0;
    font-size: 0.75rem;
    width: 100%;
}
.cal-matrix-table th, .cal-matrix-table td {
    white-space: nowrap;
    padding: 0.65rem 0.18rem;
    text-align: center;
    vertical-align: middle;
    border-bottom: 1px solid var(--cal-border);
}
.cal-matrix-table thead th {
    position: sticky;
    top: 0;
    z-index: 10;
    background: var(--cal-bg-elevated);
    border-bottom: 2px solid var(--cal-border);
    color: var(--cal-text-muted);
    padding: 0.6rem 0.18rem;
}
.cal-matrix-table .cal-fixed-col {
    position: sticky;
    left: 0;
    z-index: 8;
    background: var(--cal-bg);
    color: var(--cal-text-main);
    text-align: left;
    width: 14rem;
    min-width: 14rem;
    max-width: 14rem;
    box-sizing: border-box;
    padding: 0.65rem 0.85rem !important;
    border-right: 2px solid var(--cal-border);
    box-shadow: 4px 0 8px -2px rgba(0, 0, 0, 0.04);
    overflow: hidden;
}
.cal-matrix-table thead .cal-fixed-col {
    z-index: 15;
    background: var(--cal-bg-elevated);
    border-right: 2px solid var(--cal-border);
    padding: 0.6rem 0.85rem !important;
}

.cal-matrix-table tbody tr {
    transition: background-color 0.15s ease;
}
.cal-matrix-table tbody tr:hover td {
    background-color: rgba(2, 132, 199, 0.04);
}
.cal-matrix-table tbody tr:hover .cal-fixed-col {
    background-color: var(--cal-bg-subtle);
}
[data-bs-theme="dark"] .cal-matrix-table tbody tr:hover td {
    background-color: rgba(56, 189, 248, 0.05);
}
[data-bs-theme="dark"] .cal-matrix-table tbody tr:hover .cal-fixed-col {
    background-color: var(--cal-bg-subtle);
}

.cal-colab-cell {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    max-width: 100%;
    overflow: hidden;
    padding: 0.12rem 0;
}
.cal-colab-info {
    min-width: 0;
    flex: 1 1 auto;
    overflow: hidden;
}
.cal-colab-name {
    font-size: 0.825rem;
    font-weight: 700;
    color: var(--cal-text-main);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    display: block;
    line-height: 1.3;
    margin-bottom: 2px;
}
.cal-colab-name:hover {
    color: #2563eb;
}
.cal-colab-sub {
    font-size: 0.72rem;
    color: var(--cal-text-muted);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    display: flex;
    align-items: center;
    line-height: 1.25;
}

/* Columnas de Totales en Matriz */
.cal-col-tot-f,
.cal-col-tot-t {
    min-width: 2.2rem;
    width: 2.2rem;
    text-align: center;
}
.cal-col-tot-min {
    min-width: 3.2rem;
    width: 3.2rem;
    text-align: center;
    font-variant-numeric: tabular-nums;
}
.cal-col-tot-hours {
    min-width: 3.5rem;
    width: 3.5rem;
    text-align: center;
    font-variant-numeric: tabular-nums;
}
.cal-col-tot-he {
    min-width: 3.8rem;
    width: 3.8rem;
    text-align: center;
    font-variant-numeric: tabular-nums;
}

.cal-cell-token {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    border-radius: 6px;
    font-weight: 700;
    font-size: 0.7rem;
    line-height: 1;
    border: 1px solid transparent;
}
button.cal-cell-token { cursor: pointer; outline: none; background: transparent; }
.cal-cell-token.is-present { background: #dcfce7; border-color: #86efac; color: #14532d; }
.cal-cell-token.is-late    { background: #fef3c7; border-color: #fcd34d; color: #78350f; }
.cal-cell-token.is-incomplete { background: #fef3c7; border: 1.5px dashed #f59e0b; color: #92400e; }
.cal-cell-token.is-absent  { background: #fee2e2; border-color: #fca5a5; color: #7f1d1d; }
.cal-cell-token.is-excused { background: #dbeafe; border-color: #93c5fd; color: #1e3a8a; }
.cal-cell-token.is-half_day { background: #f0fdfa; border-color: #99f6e4; color: #0f766e; }
.cal-cell-token.is-rest    { background: var(--cal-bg-elevated); border-color: var(--cal-border-light); color: var(--cal-text-muted); }
.cal-cell-token.is-scheduled { background: transparent; border: 1px dashed var(--cal-border-light); color: var(--cal-text-subtle); }
.cal-cell-token .cal-flag {
    position: absolute;
    top: -2px;
    right: -2px;
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #8b5cf6;
    border: 1px solid #ffffff;
}

[data-bs-theme="dark"] .cal-cell-token.is-present { background: rgba(16, 185, 129, 0.22); border-color: rgba(16, 185, 129, 0.4); color: #34d399; }
[data-bs-theme="dark"] .cal-cell-token.is-late    { background: rgba(245, 158, 11, 0.22); border-color: rgba(245, 158, 11, 0.4); color: #fbbf24; }
[data-bs-theme="dark"] .cal-cell-token.is-incomplete { background: rgba(245, 158, 11, 0.22); border: 1.5px dashed #f59e0b; color: #fbbf24; }
[data-bs-theme="dark"] .cal-cell-token.is-absent  { background: rgba(239, 68, 68, 0.22); border-color: rgba(239, 68, 68, 0.4); color: #f87171; }
[data-bs-theme="dark"] .cal-cell-token.is-excused { background: rgba(59, 130, 246, 0.22); border-color: rgba(59, 130, 246, 0.4); color: #60a5fa; }
[data-bs-theme="dark"] .cal-cell-token.is-half_day { background: rgba(20, 184, 166, 0.22); border-color: rgba(20, 184, 166, 0.4); color: #2dd4bf; }
[data-bs-theme="dark"] .cal-cell-token.is-rest    { background: var(--cal-bg-elevated); border-color: var(--cal-border); color: var(--cal-text-subtle); }

.cal-avatar-circle {
    width: 34px;
    height: 34px;
    border-radius: 8px;
    font-weight: 800;
    font-size: 0.78rem;
    background: rgba(37, 99, 235, 0.1);
    color: #2563eb;
    border: 1px solid rgba(37, 99, 235, 0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
[data-bs-theme="dark"] .cal-avatar-circle {
    background: rgba(37, 99, 235, 0.2);
    color: #60a5fa;
    border-color: rgba(96, 165, 250, 0.3);
}

/* Overlay AJAX - Solo cubre las vistas de grilla/matriz, dejando cabecera y filtros 100% visibles */
#cal-views-wrapper {
    position: relative;
    min-height: 420px;
    border-radius: 8px;
}
#cal-loading-overlay {
    position: absolute;
    inset: 0;
    background: rgba(255, 255, 255, 0.78);
    backdrop-filter: blur(2px);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 50;
    border-radius: 8px;
}
[data-bs-theme="dark"] #cal-loading-overlay {
    background: rgba(17, 22, 34, 0.82);
}

/* Barra de Convenciones */
.cal-legend-bar {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 1.5rem;
    padding: 0.75rem 1rem;
    background: var(--cal-bg);
    border: 1px solid var(--cal-border);
    border-radius: 10px;
    font-size: 0.775rem;
    color: var(--cal-text-muted);
    flex-wrap: wrap;
    box-shadow: var(--cal-shadow);
}
.cal-legend-item {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-weight: 600;
}
.cal-legend-item .badge-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
}
</style>
@endsection

@section('content')
@php
    $monthLabel = \Carbon\Carbon::createFromFormat('!Y-m', $filters->month)->locale('es')->isoFormat('MMMM [de] YYYY');
    $fmtHours = fn ($h, $unit = true) => rtrim(rtrim(number_format((float) $h, 2, '.', ''), '0'), '.') . ($unit ? ' h' : '');

    $items = collect($rows->items());
    $totalEmployees = $rows->total();
    $totalFaltas = $items->sum(fn($r) => $r['totals']['absent'] ?? 0);
    $totalTardanzas = $items->sum(fn($r) => $r['totals']['late'] ?? 0);
    $totalMinutosTarde = $items->sum(fn($r) => $r['totals']['late_minutes'] ?? 0);

    // Semanas para el calendario mensual (7 columnas Lun - Dom)
    $firstDate = \Carbon\CarbonImmutable::createFromFormat('!Y-m', $filters->month);
    $firstDayOfWeek = $firstDate->dayOfWeekIso; // 1 = Lunes, 7 = Domingo
    $daysKeys = array_keys($days);
    
    // Cálculo de días previos para padding visual elegante
    $prevMonthDate = $firstDate->subMonth();
    $daysInPrevMonth = $prevMonthDate->daysInMonth;
    $prevPadded = [];
    for ($i = $firstDayOfWeek - 1; $i > 0; $i--) {
        $prevPadded[] = $daysInPrevMonth - $i + 1;
    }
    
    $paddedDays = array_merge(array_fill(0, $firstDayOfWeek - 1, null), $daysKeys);
    $weeks = array_chunk($paddedDays, 7);

    // Agregados diarios para la vista general
    $dailyAggregates = [];
    foreach ($daysKeys as $dateKey) {
        $p = 0; $t = 0; $f = 0; $j = 0; $d = 0;
        foreach ($items as $rowItem) {
            $cellState = $rowItem['cells'][$dateKey]['state'] ?? null;
            if ($cellState === 'present') $p++;
            elseif ($cellState === 'late' || $cellState === 'incomplete') $t++;
            elseif ($cellState === 'absent') $f++;
            elseif ($cellState === 'excused' || $cellState === 'half_day') $j++;
            elseif ($cellState === 'rest') $d++;
        }
        $dailyAggregates[$dateKey] = [
            'present' => $p,
            'late'    => $t,
            'absent'  => $f,
            'excused' => $j,
            'rest'    => $d,
        ];
    }
@endphp

<div class="cal-app-shell" id="calendar-app-container">
    <script id="calendarDataScript" type="application/json">@json($rows->items())</script>

    <!-- 1. Encabezado Principal y Acción de Exportación -->
    <div class="cal-header-bar">
        <div class="cal-header-title-box">
            <h1 class="h2 mb-1 fw-bold" style="color: var(--cal-text-main);">Calendario de asistencia</h1>
            <p style="color: var(--cal-text-muted);">Control visual mensual, planificación sincronizada y semáforo de asistencias.</p>
        </div>
        <div>
            <a href="{{ route('attendance.calendar.excel', $filters->toQuery()) }}" class="cal-btn-excel shadow-xs" title="Descargar reporte mensual en Excel">
                <i class="bi bi-file-earmark-spreadsheet"></i>
                <span>Exportar a Excel</span>
            </a>
        </div>
    </div>

    <!-- 2. KPIs Ejecutivos (Métricas de Alto Impacto y Definición) -->
    <div class="cal-kpis-grid">
        <div class="cal-kpi-card">
            <div class="cal-kpi-icon-box" style="background: rgba(2, 132, 199, 0.12); color: #0284c7;">
                <i class="bi bi-people-fill"></i>
            </div>
            <div>
                <div class="cal-kpi-value">{{ number_format($totalEmployees) }}</div>
                <div class="cal-kpi-title">Colaboradores en plantilla</div>
            </div>
        </div>

        <div class="cal-kpi-card">
            <div class="cal-kpi-icon-box" style="background: rgba(239, 68, 68, 0.12); color: #ef4444;">
                <i class="bi bi-x-circle-fill"></i>
            </div>
            <div>
                <div class="cal-kpi-value">{{ number_format($totalFaltas) }}</div>
                <div class="cal-kpi-title">Faltas acumuladas</div>
            </div>
        </div>

        <div class="cal-kpi-card">
            <div class="cal-kpi-icon-box" style="background: rgba(245, 158, 11, 0.12); color: #d97706;">
                <i class="bi bi-clock-history"></i>
            </div>
            <div>
                <div class="cal-kpi-value">{{ number_format($totalTardanzas) }}</div>
                <div class="cal-kpi-title">Tardanzas registradas</div>
            </div>
        </div>

        <div class="cal-kpi-card">
            <div class="cal-kpi-icon-box" style="background: rgba(16, 185, 129, 0.12); color: #10b981;">
                <i class="bi bi-stopwatch-fill"></i>
            </div>
            <div>
                <div class="cal-kpi-value">{{ number_format($totalMinutosTarde) }} min</div>
                <div class="cal-kpi-title">Minutos de tardanza</div>
            </div>
        </div>
    </div>

    <!-- 3. Panel de Control: Navegador de Mes, Filtros Asíncronos y Conmutador de Vistas -->
    <div class="cal-control-panel">
        <div class="cal-control-row-top">
            <div class="cal-month-nav">
                <button type="button" class="cal-nav-arrow-btn" title="Mes anterior" onclick="changeMonthInternal('{{ $filters->previousMonth() }}')">
                    <i class="bi bi-chevron-left"></i>
                </button>
                <div class="cal-month-display-pill">
                    <i class="bi bi-calendar3"></i>
                    <span class="cal-month-title">{{ $monthLabel }}</span>
                </div>
                <button type="button" class="cal-nav-arrow-btn" title="Mes siguiente" onclick="changeMonthInternal('{{ $filters->nextMonth() }}')">
                    <i class="bi bi-chevron-right"></i>
                </button>

                @if($filters->month !== now(tenant_timezone())->format('Y-m'))
                    <button type="button" class="cal-btn-current-month" onclick="changeMonthInternal('{{ now(tenant_timezone())->format('Y-m') }}')" title="Volver al mes actual">
                        <i class="bi bi-calendar2-check"></i>
                        <span>Mes actual</span>
                    </button>
                @endif
            </div>

            <div class="cal-sync-pill">
                <span class="cal-sync-dot"></span>
                <span>Sincronizado en tiempo real</span>
            </div>
        </div>

        <div class="cal-control-row-bottom">
            <form id="internalFilterForm" class="cal-filters-group" onsubmit="event.preventDefault(); applyInternalFilter();">
                <input type="hidden" id="monthInput" name="month" value="{{ $filters->month }}">

                <div class="cal-search-container">
                    <i class="bi bi-search"></i>
                    <input type="search" id="q" name="q" class="cal-search-input" value="{{ $filters->search }}" placeholder="Buscar colaborador o DNI..." onkeydown="if(event.key === 'Enter') applyInternalFilter();">
                </div>

                <select id="branch_id" name="branch_id" class="cal-select-control" onchange="applyInternalFilter()">
                    <option value="">🏢 Todas las sucursales</option>
                    @foreach($branches as $b)
                        <option value="{{ $b->id }}" @selected($filters->branchId == $b->id)>{{ $b->name }}</option>
                    @endforeach
                </select>

                @if($positions->isNotEmpty())
                    <select id="position_id" name="position_id" class="cal-select-control" onchange="applyInternalFilter()">
                        <option value="">💼 Todos los cargos</option>
                        @foreach($positions as $p)
                            <option value="{{ $p->id }}" @selected($filters->positionId == $p->id)>{{ $p->name }}</option>
                        @endforeach
                    </select>
                @endif

                <button type="submit" class="cal-submit-btn">
                    <i class="bi bi-funnel"></i>
                    <span>Filtrar</span>
                </button>

                @if($filters->branchId || $filters->positionId || $filters->search)
                    <button type="button" class="cal-clear-btn" title="Limpiar filtros" onclick="resetInternalFilter()">
                        <i class="bi bi-x-lg"></i>
                    </button>
                @endif
            </form>

            <div class="cal-views-group">
                <select id="quickCollaboratorSelect" class="cal-colab-switcher" onchange="switchCollaboratorCalendar(this.value)">
                    <option value="all">👥 Vista General (Equipo)</option>
                    @foreach($rows as $r)
                        <option value="{{ $r['user_id'] }}">{{ $r['last_name'] }}, {{ $r['first_name'] }}</option>
                    @endforeach
                </select>

                <div class="cal-seg-toggle">
                    <button type="button" class="cal-seg-button active" id="btnViewCalendar" onclick="toggleViewMode('calendar')" title="Vista Calendario Mensual">
                        <i class="bi bi-calendar3"></i>
                        <span>Calendario</span>
                    </button>
                    <button type="button" class="cal-seg-button" id="btnViewMatrix" onclick="toggleViewMode('matrix')" title="Vista Matriz Completa">
                        <i class="bi bi-table"></i>
                        <span>Matriz</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Contenedor con Overlay Scoped: Solo cubre las vistas de grilla/matriz, dejando cabecera y filtros 100% visibles -->
    <div id="cal-views-wrapper">
        <div id="cal-loading-overlay">
            <div class="d-flex flex-column align-items-center">
                <div class="spinner-border text-primary mb-2" role="status" style="width: 2.2rem; height: 2.2rem;"></div>
                <span class="small fw-bold" style="color: var(--cal-text-main); letter-spacing: 0.02em;">Sincronizando calendario...</span>
            </div>
        </div>

        <!-- 4. VISTA CALENDARIO MENSUAL (7 Columnas Lun - Dom, Alta Definición y Ricos Colores) -->
        <div id="viewCalendarContainer" class="cal-grid-card">
        <div class="cal-grid-header">
            @foreach(['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'] as $idx => $wd)
                <div class="cal-weekday-label {{ $idx >= 5 ? 'is-weekend' : '' }}">{{ $wd }}</div>
            @endforeach
        </div>

        <div class="cal-grid-body">
            @php $padIdx = 0; @endphp
            @foreach($weeks as $week)
                @foreach($week as $date)
                    @if(!$date)
                        @php
                            $paddedNum = $prevPadded[$padIdx] ?? '';
                            $padIdx++;
                        @endphp
                        <div class="cal-day-cell is-padded">
                            <div class="cal-cell-head">
                                <span class="cal-cell-day-num is-padded">{{ $paddedNum }}</span>
                            </div>
                        </div>
                    @else
                        @php
                            $dInfo = $days[$date];
                            $dayNum = $dInfo['day'];
                            $isWeekend = $dInfo['weekend'];
                            $isToday = ($date === $today);
                            $agg = $dailyAggregates[$date] ?? ['present' => 0, 'late' => 0, 'absent' => 0, 'excused' => 0, 'rest' => 0];
                        @endphp
                        <div class="cal-day-cell {{ $isWeekend ? 'is-weekend' : '' }} {{ $isToday ? 'is-today' : '' }}" onclick="openDayDetailModal('{{ $date }}', '{{ $dayNum }}', '{{ $dInfo['weekday'] }}')">
                            <div class="cal-cell-head">
                                @if($isToday)
                                    <span class="cal-today-badge" title="Hoy">{{ $dayNum }}</span>
                                    <span class="cal-today-pill">HOY</span>
                                @else
                                    <span class="cal-cell-day-num">{{ $dayNum }}</span>
                                @endif
                            </div>

                            <!-- MODO A: VISTA GENERAL (Píldoras Claras, Nítidas y Bien Definidas) -->
                            <div class="cal-pills-stack cal-content-general">
                                @if($agg['present'] > 0)
                                    <div class="cal-badge-pill is-present" title="{{ $agg['present'] }} puntuales">
                                        <i class="bi bi-check-circle-fill"></i>
                                        <span><strong>{{ $agg['present'] }}</strong> puntual{{ $agg['present'] > 1 ? 'es' : '' }}</span>
                                    </div>
                                @endif
                                @if($agg['late'] > 0)
                                    <div class="cal-badge-pill is-late" title="{{ $agg['late'] }} tardanzas">
                                        <i class="bi bi-clock-fill"></i>
                                        <span><strong>{{ $agg['late'] }}</strong> tardanza{{ $agg['late'] > 1 ? 's' : '' }}</span>
                                    </div>
                                @endif
                                @if($agg['absent'] > 0)
                                    <div class="cal-badge-pill is-absent" title="{{ $agg['absent'] }} faltas">
                                        <i class="bi bi-x-circle-fill"></i>
                                        <span><strong>{{ $agg['absent'] }}</strong> falta{{ $agg['absent'] > 1 ? 's' : '' }}</span>
                                    </div>
                                @endif
                                @if($isWeekend && $agg['present'] === 0 && $agg['late'] === 0 && $agg['absent'] === 0)
                                    <div class="cal-badge-pill is-rest">
                                        <i class="bi bi-cup-hot-fill"></i>
                                        <span>Descanso</span>
                                    </div>
                                @endif
                            </div>

                            <!-- MODO B: VISTA POR COLABORADOR ESPECÍFICO -->
                            @foreach($rows as $r)
                                @php
                                    $c = $r['cells'][$date] ?? null;
                                    $cState = $c['state'] ?? 'scheduled';
                                    $chipClass = match($cState) {
                                        'present' => 'is-present',
                                        'late', 'incomplete' => 'is-late',
                                        'absent'  => 'is-absent',
                                        'excused' => 'is-excused',
                                        default   => '',
                                    };
                                    $isScheduled = ($cState === 'scheduled');
                                    $isRest = ($cState === 'rest');

                                    // Tooltip para pruebas automatizadas
                                    $tipColab = $c ? implode(' · ', array_filter([
                                        $c['label'],
                                        $c['clock_in'] ? 'Entrada ' . $c['clock_in'] : null,
                                        $c['clock_out'] ? 'Salida ' . $c['clock_out'] : null,
                                        $c['late_minutes'] ? $c['late_minutes'] . ' min tarde' : null,
                                        $c['note'] ?? null,
                                    ])) : '';
                                @endphp
                                <div class="cal-person-stack cal-content-person d-none flex-grow-1" data-user-id="{{ $r['user_id'] }}" title="{{ $tipColab }}">
                                    @if($c && !$isRest && !$isScheduled)
                                        <div class="cal-badge-pill {{ $chipClass }}">
                                            @if($cState === 'present') <i class="bi bi-check-circle-fill"></i>
                                            @elseif($cState === 'late' || $cState === 'incomplete') <i class="bi bi-clock-fill"></i>
                                            @elseif($cState === 'absent') <i class="bi bi-x-circle-fill"></i>
                                            @else <i class="bi bi-info-circle-fill"></i> @endif
                                            <span>{{ $c['label'] }}</span>
                                        </div>

                                        @if($c['clock_in'])
                                            <div class="cal-time-row">
                                                <i class="bi bi-clock"></i>
                                                <span>{{ $c['clock_in'] }}@if($c['clock_out']) – {{ $c['clock_out'] }}@endif</span>
                                                @if($c['late_minutes'])
                                                    <span class="badge bg-warning text-dark py-0 px-1" style="font-size: 0.65rem;">+{{ $c['late_minutes'] }}m</span>
                                                @endif
                                            </div>
                                        @endif

                                        @if($canOpen && $c['attendance_id'])
                                            <button type="button" class="cal-evidence-trigger" onclick="event.stopPropagation(); openEvidence({{ $c['attendance_id'] }})" title="Ver selfie y geolocalización">
                                                <i class="bi bi-camera-fill"></i>
                                                <span>Evidencia</span>
                                            </button>
                                        @endif
                                    @elseif($isRest)
                                        <div class="cal-badge-pill is-rest">
                                            <i class="bi bi-cup-hot-fill"></i>
                                            <span>Descanso</span>
                                        </div>
                                    @endif
                                </div>
                            @endforeach

                            <div class="cal-hover-action">
                                <span>Ver detalle</span>
                                <i class="bi bi-arrow-right"></i>
                            </div>
                        </div>
                    @endif
                @endforeach
            @endforeach
        </div>
    </div>

    <!-- 5. VISTA MATRIZ DE EQUIPO (Tabla Completa de 31 Días) -->
    <div id="viewMatrixContainer" class="cal-grid-card d-none">
        <div class="cal-matrix-scroll">
            <table class="cal-matrix-table">
                <thead>
                    <tr>
                        <th class="cal-fixed-col">Colaborador</th>
                        @foreach($days as $date => $d)
                            @php
                                $isToday = ($date === $today);
                                $isWeekend = $d['weekend'];
                            @endphp
                            <th class="{{ $isWeekend ? 'cal-cell-weekend' : '' }}" title="Ver el {{ $date }} por turno">
                                <a href="{{ route('attendance.calendar.day', array_filter(['date' => $date, 'branch_id' => $filters->branchId])) }}" class="text-reset text-decoration-none d-flex flex-column align-items-center">
                                    <span class="fw-semibold text-uppercase" style="font-size: 0.65rem; color: var(--cal-text-muted);">{{ $d['weekday'] }}</span>
                                    <span class="fw-bold mt-0.5" style="font-size: 0.8rem; color: {{ $isToday ? '#2563eb' : 'var(--cal-text-main)' }};">{{ $d['day'] }}</span>
                                </a>
                            </th>
                        @endforeach
                        <th class="border-start cal-col-tot-f" title="Faltas"><span class="badge bg-danger-subtle text-danger border border-danger-subtle" style="font-size: 0.68rem; padding: 2px 6px;">F</span></th>
                        <th class="cal-col-tot-t" title="Tardanzas"><span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle" style="font-size: 0.68rem; padding: 2px 6px;">T</span></th>
                        <th class="cal-col-tot-min" title="Minutos de tardanza"><span class="small fw-bold" style="color: var(--cal-text-muted); font-size: 0.725rem;">Min</span></th>
                        <th class="cal-col-tot-hours" title="Horas trabajadas"><span class="small fw-bold" style="color: var(--cal-text-muted); font-size: 0.725rem;">Horas</span></th>
                        <th class="cal-col-tot-he" title="Horas Extra (aprobadas y por aprobar)"><span class="small fw-bold" style="color: var(--cal-text-muted); font-size: 0.725rem;">HE</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rows as $r)
                        <tr>
                            <td class="cal-fixed-col">
                                @php
                                    $initials = strtoupper(substr($r['first_name'] ?? '', 0, 1) . substr($r['last_name'] ?? '', 0, 1));
                                    $fullName = ($r['last_name'] ?? '') . ', ' . ($r['first_name'] ?? '');
                                    $posName = $r['position'] ?: 'Colaborador';
                                    $codeStr = $r['employee_code'] ?? '';
                                    $colabTooltip = $fullName . ($codeStr ? " ({$codeStr})" : '') . " · {$posName}";
                                @endphp
                                <div class="cal-colab-cell">
                                    @if(!empty($r['avatar_url']))
                                        <img src="{{ $r['avatar_url'] }}" alt="{{ $r['first_name'] }}" class="cal-avatar-img" onerror="this.style.display='none'; if(this.nextElementSibling) this.nextElementSibling.style.display='flex';">
                                        <div class="cal-avatar-circle" style="display: none;">{{ $initials ?: 'U' }}</div>
                                    @else
                                        <div class="cal-avatar-circle">{{ $initials ?: 'U' }}</div>
                                    @endif
                                    <div class="cal-colab-info">
                                        <a href="{{ route('attendance.calendar.person', ['id' => $r['user_id'], 'month' => $filters->month]) }}" 
                                           class="cal-colab-name text-decoration-none" 
                                           title="{{ $fullName }}">
                                            {{ $fullName }}
                                        </a>
                                        <div class="cal-colab-sub" title="{{ $colabTooltip }}">
                                            @if($codeStr)
                                                <span class="badge border py-0 px-1 me-1 flex-shrink-0" style="font-size: 0.625rem; background: var(--cal-bg-elevated); color: var(--cal-text-muted); border-color: var(--cal-border) !important;">{{ $codeStr }}</span>
                                            @endif
                                            <span class="text-truncate">{{ $posName }}</span>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            @foreach($days as $date => $d)
                                @php
                                    $c = $r['cells'][$date] ?? null;
                                    $stateClass = match($c['state'] ?? '') {
                                        'present'    => 'is-present',
                                        'late'       => 'is-late',
                                        'incomplete' => 'is-incomplete',
                                        'absent'     => 'is-absent',
                                        'excused'    => 'is-excused',
                                        'half_day'   => 'is-half_day',
                                        'rest'       => 'is-rest',
                                        default      => 'is-scheduled',
                                    };
                                    $tip = $c ? implode(' · ', array_filter([
                                        $c['label'],
                                        $c['clock_in'] ? 'Entrada ' . $c['clock_in'] : null,
                                        $c['clock_out'] ? 'Salida ' . $c['clock_out'] : null,
                                        $c['late_minutes'] ? $c['late_minutes'] . ' min tarde' : null,
                                        $c['note'] ?? null,
                                        $c['suspicious'] ? 'Observada' : null,
                                        match ($c['overtime_status'] ?? null) {
                                            'pending'  => 'HE ' . $fmtHours($c['overtime_hours']) . ' por aprobar',
                                            'approved' => 'HE ' . $fmtHours($c['overtime_hours']) . ' aprobadas',
                                            'rejected' => 'HE rechazadas',
                                            default    => null,
                                        },
                                    ])) : '';
                                @endphp
                                <td>
                                    @if($c)
                                        @if($canOpen && $c['attendance_id'])
                                            <button type="button" class="cal-cell-token {{ $stateClass }}" onclick="openEvidence({{ $c['attendance_id'] }})" title="{{ $tip }}" aria-label="{{ $date }}: {{ $tip }}">
                                                {{ $c['letter'] }}
                                                @if($c['suspicious'])<span class="cal-flag"></span>@endif
                                            </button>
                                        @else
                                            <span class="cal-cell-token {{ $stateClass }}" title="{{ $tip }}" aria-label="{{ $date }}: {{ $tip }}">
                                                {{ $c['letter'] }}
                                                @if($c['suspicious'])<span class="cal-flag"></span>@endif
                                            </span>
                                        @endif
                                    @endif
                                </td>
                            @endforeach
                            <td class="border-start cal-col-tot-f fw-bold {{ $r['totals']['absent'] ? 'text-danger' : 'text-muted opacity-50' }}">{{ $r['totals']['absent'] }}</td>
                            <td class="cal-col-tot-t fw-bold {{ $r['totals']['late'] ? 'text-warning-emphasis' : 'text-muted opacity-50' }}">{{ $r['totals']['late'] }}</td>
                            <td class="cal-col-tot-min" style="color: var(--cal-text-muted);">{{ $r['totals']['late_minutes'] ?: '—' }}</td>
                            <td class="cal-col-tot-hours fw-medium" style="color: var(--cal-text-main);">{{ rtrim(rtrim(number_format($r['totals']['worked_hours'], 2, '.', ''), '0'), '.') }}</td>
                            <td class="cal-col-tot-he text-success fw-bold" style="white-space: nowrap;">
                                {{ $r['totals']['overtime_approved'] ? $fmtHours($r['totals']['overtime_approved'], false) : '—' }}
                                @if($r['totals']['overtime_pending'])
                                    <span class="d-block small text-warning-emphasis" style="font-size: 0.65rem; font-weight: 700;" title="HE por aprobar: +{{ $fmtHours($r['totals']['overtime_pending'], false) }}">+{{ $fmtHours($r['totals']['overtime_pending'], false) }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ count($days) + 6 }}" class="text-center py-4" style="color: var(--cal-text-muted);">
                                No hay colaboradores con estos filtros.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    </div>

    <!-- Convenciones / Indicadores -->
    <div class="cal-legend-bar">
        <span class="fw-bold" style="color: var(--cal-text-main);"><i class="bi bi-info-circle text-primary me-1"></i> Convenciones:</span>
        <div class="cal-legend-item">
            <span class="badge-dot" style="background: #16a34a;"></span>
            <span>Puntual (P)</span>
        </div>
        <div class="cal-legend-item">
            <span class="badge-dot" style="background: #d97706;"></span>
            <span>Tardanza (T)</span>
        </div>
        <div class="cal-legend-item">
            <span class="badge-dot" style="background: #dc2626;"></span>
            <span>Falta (F)</span>
        </div>
        <div class="cal-legend-item">
            <span class="badge-dot" style="background: #2563eb;"></span>
            <span>Justificado (J)</span>
        </div>
        <div class="cal-legend-item">
            <span class="badge-dot" style="background: #64748b;"></span>
            <span>Descanso (D)</span>
        </div>
    </div>

    @if($rows->total() > 0)
        <div class="card-footer border-0 py-3 px-4 d-flex justify-content-between align-items-center" style="background: var(--cal-bg); border-top: 1px solid var(--cal-border) !important;">
            <small style="color: var(--cal-text-muted);">Mostrando {{ $rows->firstItem() }} a {{ $rows->lastItem() }} de {{ $rows->total() }} colaboradores</small>
            <div>
                @if($rows->hasPages())
                    {{ $rows->links() }}
                @else
                    <span class="badge bg-secondary-subtle text-secondary px-2 py-1" style="font-size: 0.75rem;">Pág. 1 de 1</span>
                @endif
            </div>
        </div>
    @endif

    <!-- Script de datos embebidos para sincronización garantizada tras AJAX -->
    <script id="calendarDataScript" type="application/json">@json($rows->items())</script>
</div>

<!-- Modal de Detalle de Jornada Sincronizado (Adaptativo Claro & Oscuro) -->
<div class="modal fade" id="dayDetailModal" tabindex="-1" aria-labelledby="dayDetailModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-3 px-4">
                <div class="d-flex align-items-center gap-3">
                    <div class="cal-modal-header-icon">
                        <i class="bi bi-calendar2-week-fill"></i>
                    </div>
                    <div>
                        <h2 class="h5 mb-0 fw-bold" id="dayDetailModalTitle" style="color: var(--cal-text-main);">Jornada del Día</h2>
                        <div class="small mt-0.5" id="dayDetailModalSubtitle" style="color: var(--cal-text-muted); font-size: 0.8rem; font-weight: 500;">Detalle y auditoría de asistencia en tiempo real</div>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body p-4">
                <div id="dayDetailModalContent">
                    <!-- Contenido dinámico renderizado por JS -->
                </div>
            </div>
            <div class="modal-footer py-3 px-4 d-flex justify-content-between">
                <a id="dayDetailFullLink" href="#" class="cal-modal-btn-monitor">
                    <i class="bi bi-box-arrow-up-right"></i>
                    <span>Ver monitor completo del día</span>
                </a>
                <button type="button" class="cal-modal-btn-close" data-bs-dismiss="modal">
                    <span>Cerrar</span>
                </button>
            </div>
        </div>
    </div>
</div>

@if($canOpen)
    @include('attendance.partials.evidence-modal')
@endif
@endsection

@section('scripts')
<script>
// Obtener siempre los datos reactivos del DOM (incluso tras actualizaciones AJAX)
function getCalendarRows() {
    const el = document.getElementById('calendarDataScript');
    if (el) {
        try {
            return JSON.parse(el.textContent);
        } catch (e) {
            console.error('Error al parsear datos del calendario', e);
        }
    }
    return window.calendarRows || [];
}

window.calendarRows = @json($rows->items());

let currentCalViewMode = 'calendar';

// Función central de búsqueda interna sin cambiar la URL
async function executeInternalFetch(params) {
    const overlay = document.getElementById('cal-loading-overlay');
    if (overlay) overlay.style.display = 'flex';

    try {
        const url = new URL('{{ route("attendance.calendar") }}', window.location.origin);
        Object.entries(params).forEach(([k, v]) => {
            if (v !== null && v !== undefined && v !== '') {
                url.searchParams.set(k, v);
            }
        });

        const res = await fetch(url.toString(), {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'text/html'
            }
        });

        if (!res.ok) throw new Error('Error al actualizar el calendario');

        const html = await res.text();
        const doc = new DOMParser().parseFromString(html, 'text/html');
        const newContainer = doc.getElementById('calendar-app-container');

        if (newContainer) {
            document.getElementById('calendar-app-container').innerHTML = newContainer.innerHTML;
            window.calendarRows = getCalendarRows();
            
            // Restaurar modo matriz si estaba activo
            if (currentCalViewMode === 'matrix') {
                toggleViewMode('matrix');
            }
            
            // Garantizar que la barra del navegador permanezca limpia: /attendance-calendar
            window.history.replaceState({}, '', '{{ route("attendance.calendar") }}');
        }
    } catch (err) {
        console.error(err);
    } finally {
        const overlay = document.getElementById('cal-loading-overlay');
        if (overlay) overlay.style.display = 'none';
    }
}

// Aplicar filtros internos
function applyInternalFilter() {
    const form = document.getElementById('internalFilterForm');
    const formData = new FormData(form);
    const params = Object.fromEntries(formData.entries());
    executeInternalFetch(params);
}

// Navegar mes internamente sin cambiar la URL visible
function changeMonthInternal(monthValue) {
    const form = document.getElementById('internalFilterForm');
    const formData = new FormData(form);
    const params = Object.fromEntries(formData.entries());
    params.month = monthValue;
    executeInternalFetch(params);
}

// Limpiar filtros internamente
function resetInternalFilter() {
    const month = document.getElementById('monthInput').value;
    executeInternalFetch({ month });
}

// Alternar entre Vista Calendario y Vista Matriz
function toggleViewMode(mode) {
    currentCalViewMode = mode;
    const calContainer = document.getElementById('viewCalendarContainer');
    const matrixContainer = document.getElementById('viewMatrixContainer');
    const btnCal = document.getElementById('btnViewCalendar');
    const btnMat = document.getElementById('btnViewMatrix');

    if (mode === 'calendar') {
        if (calContainer) calContainer.classList.remove('d-none');
        if (matrixContainer) matrixContainer.classList.add('d-none');
        if (btnCal) btnCal.classList.add('active');
        if (btnMat) btnMat.classList.remove('active');
    } else {
        if (calContainer) calContainer.classList.add('d-none');
        if (matrixContainer) matrixContainer.classList.remove('d-none');
        if (btnMat) btnMat.classList.add('active');
        if (btnCal) btnCal.classList.remove('active');
    }
}

// Cambiar rápidamente entre vista general y colaborador individual
function switchCollaboratorCalendar(userId) {
    const generals = document.querySelectorAll('.cal-content-general');
    const persons = document.querySelectorAll('.cal-content-person');

    if (userId === 'all') {
        generals.forEach(el => el.classList.remove('d-none'));
        persons.forEach(el => el.classList.add('d-none'));
    } else {
        generals.forEach(el => el.classList.add('d-none'));
        persons.forEach(el => {
            if (el.getAttribute('data-user-id') === userId) {
                el.classList.remove('d-none');
            } else {
                el.classList.add('d-none');
            }
        });
    }
}

// Abrir detalle modal del día seleccionado
function openDayDetailModal(date, dayNum, weekday) {
    const modalTitle = document.getElementById('dayDetailModalTitle');
    const modalSubtitle = document.getElementById('dayDetailModalSubtitle');
    const content = document.getElementById('dayDetailModalContent');
    const fullLink = document.getElementById('dayDetailFullLink');

    // Parsear fecha en español completo (Ej: "Viernes, 16 de Octubre de 2026")
    const parts = date.split('-');
    const year = parseInt(parts[0], 10);
    const month = parseInt(parts[1], 10);
    const day = parseInt(parts[2], 10);
    const dt = new Date(year, month - 1, day);

    const dayNames = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
    const monthNames = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

    const fullDayName = dayNames[dt.getDay()];
    const fullMonthName = monthNames[month - 1];

    modalTitle.textContent = `${fullDayName}, ${day} de ${fullMonthName} de ${year}`;
    fullLink.href = `{{ route('attendance.calendar.day') }}?date=${date}`;

    let totalAssigned = 0;
    let presentCount = 0;
    let lateCount = 0;
    let absentCount = 0;

    const currentRows = getCalendarRows();
    let rowsHtml = '';
    currentRows.forEach(r => {
        const c = r.cells[date];
        if (!c) return;
        totalAssigned++;

        let statusIcon = '<i class="bi bi-info-circle-fill"></i>';
        let badgeModClass = 'is-incomplete';

        if (c.state === 'present') {
            presentCount++;
            statusIcon = '<i class="bi bi-check-circle-fill"></i>';
            badgeModClass = 'is-present';
        } else if (c.state === 'late') {
            lateCount++;
            statusIcon = '<i class="bi bi-clock-fill"></i>';
            badgeModClass = 'is-late';
        } else if (c.state === 'incomplete') {
            lateCount++;
            statusIcon = '<i class="bi bi-exclamation-circle-fill"></i>';
            badgeModClass = 'is-incomplete';
        } else if (c.state === 'absent') {
            absentCount++;
            statusIcon = '<i class="bi bi-x-circle-fill"></i>';
            badgeModClass = 'is-absent';
        } else if (c.state === 'excused' || c.state === 'half_day') {
            statusIcon = '<i class="bi bi-shield-check"></i>';
            badgeModClass = 'is-excused';
        }

        const initials = ((r.first_name ? r.first_name[0] : '') + (r.last_name ? r.last_name[0] : '')).toUpperCase();
        const avatarHtml = r.avatar_url
            ? `<img src="${r.avatar_url}" alt="${r.first_name}" class="cal-modal-avatar-img" onerror="this.style.display='none'; if(this.nextElementSibling) this.nextElementSibling.style.display='flex';"><div class="cal-modal-avatar" style="display: none;">${initials}</div>`
            : `<div class="cal-modal-avatar">${initials}</div>`;

        rowsHtml += `
            <div class="cal-modal-employee-card">
                <div class="d-flex align-items-center gap-3">
                    ${avatarHtml}
                    <div>
                        <div class="fw-bold" style="color: var(--cal-text-main); font-size: 0.975rem; line-height: 1.25; margin-bottom: 0.35rem;">${r.last_name}, ${r.first_name}</div>
                        <div class="small d-flex align-items-center gap-2" style="color: var(--cal-text-muted); font-size: 0.8rem;">
                            <span><i class="bi bi-briefcase me-1" style="color: var(--cal-text-subtle);"></i>${r.position || 'Colaborador'}</span>
                            <span style="opacity: 0.4;">•</span>
                            <span><i class="bi bi-clock me-1" style="color: var(--cal-text-subtle);"></i>${c.shift || 'Turno Mañana'}</span>
                        </div>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-3 flex-wrap">
                    ${c.clock_in ? `
                        <div class="text-end">
                            <div class="cal-modal-clock-box">
                                <i class="bi bi-box-arrow-in-right text-primary me-1.5"></i>
                                <span>${c.clock_in} ${c.clock_out ? '– ' + c.clock_out : ''}</span>
                            </div>
                            ${c.late_minutes ? `
                                <div class="mt-1 text-end">
                                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle fw-bold" style="font-size: 0.7rem; padding: 3px 8px; border-radius: 6px;">
                                        <i class="bi bi-alarm me-1"></i>+${c.late_minutes} min tarde
                                    </span>
                                </div>
                            ` : ''}
                        </div>
                    ` : ''}
                    <span class="cal-modal-status-badge ${badgeModClass}">
                        ${statusIcon}
                        <span>${c.label}</span>
                    </span>
                    ${c.attendance_id ? `
                        <button type="button" class="cal-modal-btn-evidence" onclick="openEvidence(${c.attendance_id})">
                            <i class="bi bi-camera-fill me-1.5"></i>
                            <span>Ver evidencia</span>
                        </button>
                    ` : ''}
                </div>
            </div>
        `;
    });

    modalSubtitle.textContent = `${totalAssigned} colaboradores programados · Auditoría sincronizada de asistencia`;

    const html = `
        <!-- Deck de Métricas del Día -->
        <div class="row g-2 mb-3">
            <div class="col-6 col-md-3">
                <div class="cal-modal-stat-card">
                    <div class="cal-modal-stat-icon" style="background: rgba(2, 132, 199, 0.1); color: #0284c7;">
                        <i class="bi bi-people-fill"></i>
                    </div>
                    <div>
                        <div class="fw-bold fs-6 lh-1" style="color: var(--cal-text-main);">${totalAssigned}</div>
                        <div class="small mt-1" style="font-size: 0.7rem; font-weight: 700; text-transform: uppercase; color: var(--cal-text-subtle); letter-spacing: 0.03em;">Programados</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="cal-modal-stat-card">
                    <div class="cal-modal-stat-icon" style="background: rgba(16, 185, 129, 0.1); color: #10b981;">
                        <i class="bi bi-check-circle-fill"></i>
                    </div>
                    <div>
                        <div class="fw-bold fs-6 lh-1 text-success">${presentCount}</div>
                        <div class="small mt-1" style="font-size: 0.7rem; font-weight: 700; text-transform: uppercase; color: var(--cal-text-subtle); letter-spacing: 0.03em;">Asistieron</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="cal-modal-stat-card">
                    <div class="cal-modal-stat-icon" style="background: rgba(245, 158, 11, 0.1); color: #d97706;">
                        <i class="bi bi-clock-history"></i>
                    </div>
                    <div>
                        <div class="fw-bold fs-6 lh-1 text-warning-emphasis">${lateCount}</div>
                        <div class="small mt-1" style="font-size: 0.7rem; font-weight: 700; text-transform: uppercase; color: var(--cal-text-subtle); letter-spacing: 0.03em;">Tardanzas</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="cal-modal-stat-card">
                    <div class="cal-modal-stat-icon" style="background: rgba(239, 68, 68, 0.1); color: #ef4444;">
                        <i class="bi bi-x-circle-fill"></i>
                    </div>
                    <div>
                        <div class="fw-bold fs-6 lh-1 text-danger">${absentCount}</div>
                        <div class="small mt-1" style="font-size: 0.7rem; font-weight: 700; text-transform: uppercase; color: var(--cal-text-subtle); letter-spacing: 0.03em;">Faltas</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex align-items-center justify-content-between" style="margin-top: 1.5rem; margin-bottom: 1.15rem; padding: 0 4px;">
            <span class="text-uppercase fw-bold" style="font-size: 0.75rem; letter-spacing: 0.06em; color: var(--cal-text-subtle);">Lista de Colaboradores</span>
            <span class="cal-modal-count-pill">${totalAssigned} registrados</span>
        </div>

        <div class="cal-modal-cards-list">
            ${rowsHtml || '<div class="text-center py-5 rounded-3 border" style="background: var(--cal-bg); color: var(--cal-text-muted); border-color: var(--cal-border) !important;">No hay colaboradores asignados para esta fecha.</div>'}
        </div>
    `;

    content.innerHTML = html;
    new bootstrap.Modal(document.getElementById('dayDetailModal')).show();
}
</script>
@endsection
