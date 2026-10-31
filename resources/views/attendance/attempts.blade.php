@extends('layouts.app')

@section('title', 'Intentos rechazados')

@section('styles')
<style>
/* ==========================================================================
   MÓDULO: INTENTOS DE MARCACIÓN RECHAZADOS (ATTENDANCE ATTEMPTS)
   UI/UX EJECUTIVO, MODERNO, EN UNA SOLA LÍNEA HORIZONTAL (MODO CLARO Y OSCURO)
   ========================================================================== */
.attempts-module,
#attemptAuditModal {
    --att-bg: var(--bg-surface, #ffffff);
    --att-bg-subtle: var(--bg-surface-elevated, #f8fafc);
    --att-bg-elevated: #f1f5f9;
    --att-border: var(--border-color, #e2e8f0);
    --att-border-light: var(--border-color-light, #cbd5e1);
    --att-text-main: var(--text-primary, #0f172a);
    --att-text-muted: var(--text-secondary, #475569);
    --att-text-subtle: var(--text-muted, #64748b);
    --att-radius: 10px; /* Esquinas ejecutivas modernas - no excesivamente redondas */
    --att-radius-sm: 8px;
    --att-radius-xs: 6px;
    --att-shadow: 0 1px 3px rgba(0, 0, 0, 0.04), 0 1px 2px rgba(0, 0, 0, 0.02);
    --att-shadow-hover: 0 6px 16px -2px rgba(0, 0, 0, 0.07), 0 2px 6px -1px rgba(0, 0, 0, 0.04);
}

/* El diálogo de auditoría vive fuera del módulo: comparte las variables, no el layout */
.attempts-module {
    display: flex;
    flex-direction: column;
    gap: 1.15rem;
    max-width: 100%;
}

[data-bs-theme="dark"] .attempts-module,
[data-bs-theme="dark"] #attemptAuditModal {
    --att-bg: #111622;
    --att-bg-subtle: #161d2d;
    --att-bg-elevated: #1a2234;
    --att-border: #243048;
    --att-border-light: #2d3d5c;
    --att-text-main: #f8fafc;
    --att-text-muted: #94a3b8;
    --att-text-subtle: #64748b;
    --att-shadow: 0 2px 8px rgba(0, 0, 0, 0.35);
    --att-shadow-hover: 0 8px 24px rgba(0, 0, 0, 0.45);
}

/* 1. Header del Módulo */
.attempts-header-wrapper {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 1rem;
    padding-bottom: 0.25rem;
}
.attempts-header-icon {
    width: 44px;
    height: 44px;
    border-radius: var(--att-radius-sm);
    background: rgba(239, 68, 68, 0.1);
    color: #ef4444;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.35rem;
    flex-shrink: 0;
}
.attempts-count-badge {
    display: inline-flex;
    align-items: center;
    padding: 2px 8px;
    border-radius: var(--att-radius-xs);
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.03em;
    background: rgba(239, 68, 68, 0.12);
    color: #dc2626;
    border: 1px solid rgba(239, 68, 68, 0.25);
}
[data-bs-theme="dark"] .attempts-count-badge {
    background: rgba(239, 68, 68, 0.18);
    color: #f87171;
    border-color: rgba(239, 68, 68, 0.35);
}

.attempts-header-actions {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-wrap: wrap;
}
.attempts-nav-btn {
    height: 38px;
    padding: 0 1rem;
    border-radius: var(--att-radius-sm);
    border: 1px solid var(--att-border);
    background: var(--att-bg);
    color: var(--att-text-main);
    font-size: 0.825rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    text-decoration: none;
    box-shadow: var(--att-shadow);
    transition: all 0.18s cubic-bezier(0.16, 1, 0.3, 1);
}
.attempts-nav-btn:hover {
    background: var(--att-bg-subtle);
    border-color: var(--brand, #2563eb);
    color: var(--brand, #2563eb);
    transform: translateY(-1px);
    box-shadow: var(--att-shadow-hover);
}
.attempts-nav-btn i {
    font-size: 0.9rem;
}

/* 2. Banner de Notificación de Sedes Flexibles */
.attempts-notice-banner {
    display: flex;
    align-items: flex-start;
    gap: 0.85rem;
    padding: 0.85rem 1.15rem;
    border-radius: var(--att-radius);
    background: rgba(245, 158, 11, 0.08);
    border: 1px solid rgba(245, 158, 11, 0.25);
    color: var(--att-text-main);
    font-size: 0.825rem;
    line-height: 1.45;
}
[data-bs-theme="dark"] .attempts-notice-banner {
    background: rgba(245, 158, 11, 0.12);
    border-color: rgba(245, 158, 11, 0.3);
}
.attempts-notice-icon {
    font-size: 1.1rem;
    color: #d97706;
    line-height: 1;
    margin-top: 2px;
    flex-shrink: 0;
}
.attempts-notice-content strong {
    color: #b45309;
}
[data-bs-theme="dark"] .attempts-notice-content strong {
    color: #fbbf24;
}
.attempts-notice-link {
    font-weight: 600;
    color: var(--brand, #2563eb);
    text-decoration: underline;
    text-underline-offset: 2px;
}
.attempts-notice-link:hover {
    color: var(--brand, #1d4ed8);
}

/* 3. Deck de Tarjetas KPI de Resumen */
.attempts-kpi-grid {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 0.85rem;
}
@media (max-width: 1200px) {
    .attempts-kpi-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}
@media (max-width: 768px) {
    .attempts-kpi-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
@media (max-width: 480px) {
    .attempts-kpi-grid {
        grid-template-columns: 1fr;
    }
}

.attempts-kpi-card {
    background: var(--att-bg);
    border: 1px solid var(--att-border);
    border-radius: var(--att-radius);
    padding: 1rem 1.15rem;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    text-decoration: none;
    color: inherit;
    box-shadow: var(--att-shadow);
    transition: all 0.18s cubic-bezier(0.16, 1, 0.3, 1);
    position: relative;
    overflow: hidden;
    cursor: pointer;
}
.attempts-kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--att-shadow-hover);
    border-color: var(--att-border-light);
    color: inherit;
}
.attempts-kpi-card.is-active {
    border-color: var(--brand, #2563eb);
    background: var(--att-bg-subtle);
    box-shadow: 0 0 0 1px var(--brand, #2563eb), var(--att-shadow);
}
.attempts-kpi-card.is-active::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: var(--brand, #2563eb);
}

.attempts-kpi-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    margin-bottom: 0.6rem;
}
.attempts-kpi-label {
    font-size: 0.775rem;
    font-weight: 700;
    color: var(--att-text-muted);
    letter-spacing: 0.01em;
    line-height: 1.25;
}
.attempts-kpi-icon-wrap {
    width: 32px;
    height: 32px;
    border-radius: var(--att-radius-xs);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.95rem;
    flex-shrink: 0;
}
.attempts-kpi-icon-wrap.tone-brand { background: rgba(var(--brand-rgb, 37, 99, 235), 0.12); color: var(--brand, #2563eb); }
.attempts-kpi-icon-wrap.tone-rose { background: rgba(244, 63, 94, 0.12); color: #f43f5e; }
.attempts-kpi-icon-wrap.tone-amber { background: rgba(245, 158, 11, 0.12); color: #d97706; }
.attempts-kpi-icon-wrap.tone-blue { background: rgba(59, 130, 246, 0.12); color: #3b82f6; }
.attempts-kpi-icon-wrap.tone-neutral { background: rgba(100, 116, 139, 0.12); color: #64748b; }

.attempts-kpi-value {
    font-size: 1.85rem;
    font-weight: 800;
    letter-spacing: -0.03em;
    line-height: 1.1;
    color: var(--att-text-main);
    font-variant-numeric: tabular-nums;
    margin-bottom: 0.35rem;
}
.attempts-kpi-meta {
    font-size: 0.725rem;
    font-weight: 500;
    color: var(--att-text-subtle);
    display: flex;
    align-items: center;
    gap: 4px;
}

/* 4. BARRA DE FILTROS MODERNA EN UNA SOLA LÍNEA HORIZONTAL (ADAPTABLE SIN ESPACIOS MUERTOS) */
.attempts-filter-bar {
    background: var(--att-bg);
    border: 1px solid var(--att-border);
    border-radius: var(--att-radius);
    padding: 0.65rem 0.85rem;
    box-shadow: var(--att-shadow);
    width: 100%;
}
.attempts-filter-row {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    width: 100%;
    flex-wrap: nowrap;
    overflow: visible;
}
@media (max-width: 1200px) {
    .attempts-filter-row {
        flex-wrap: wrap;
    }
}

/* Buscador adaptable, limpio y alineado a la izquierda */
.attempts-search-wrap {
    position: relative;
    flex: 1.15 1 0px;
    min-width: 160px;
}
.attempts-search-icon {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--att-text-subtle);
    font-size: 0.825rem;
    pointer-events: none;
    z-index: 2;
}
.attempts-search-input {
    height: 38px;
    width: 100%;
    box-sizing: border-box;
    padding-left: 36px;
    padding-right: 12px;
    border-radius: var(--att-radius-sm);
    border: 1px solid var(--att-border-light);
    background: var(--att-bg-subtle);
    color: var(--att-text-main);
    font-size: 0.815rem;
    font-weight: 500;
    text-align: left;
    outline: none !important;
    transition: all 0.15s ease;
}
.attempts-search-input:focus {
    border-color: var(--brand, #2563eb);
    background: var(--att-bg);
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.16);
}

/* Pill de Calendario Moderno Popover */
.attempts-filter-row #attemptsCalendarDropdown,
.attempts-filter-row .date-filter-pill {
    flex: 0 0 auto !important;
}
.attempts-filter-row .date-filter-pill {
    height: 38px;
    padding: 0 0.85rem;
    border-radius: var(--att-radius-sm);
    border: 1px solid var(--att-border-light);
    background: var(--att-bg-subtle);
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.8rem;
    color: var(--att-text-main);
    cursor: pointer;
    white-space: nowrap;
    transition: all 0.15s ease;
}
.attempts-filter-row .date-filter-pill:hover,
.attempts-filter-row .date-filter-pill:focus {
    border-color: var(--brand, #2563eb);
    background: var(--att-bg);
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.16);
}
.attempts-filter-row .date-filter-pill .divider {
    color: var(--att-border-light);
}

/* Comboboxes Modernos Adaptados (Sin espacios muertos y con texto completo) */
.attempts-filter-row .header-filter-dropdown {
    flex: 1 1 0px !important;
    min-width: 145px;
    max-width: none !important;
}
.attempts-filter-row .header-filter-btn {
    height: 38px;
    width: 100% !important;
    max-width: 100% !important;
    min-width: 0 !important;
    padding: 0 0.75rem 0 0.6rem;
    border-radius: var(--att-radius-sm);
    border: 1px solid var(--att-border-light);
    background: var(--att-bg-subtle);
    color: var(--att-text-main);
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.8rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.15s ease;
}
.attempts-filter-row .header-filter-btn:hover,
.show > .attempts-filter-row .header-filter-btn {
    border-color: var(--brand, #2563eb);
    background: var(--att-bg);
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.16);
}
.attempts-filter-row .header-filter-icon-box {
    width: 24px;
    height: 24px;
    border-radius: var(--att-radius-xs);
    background: rgba(var(--brand-rgb, 37, 99, 235), 0.1);
    color: var(--brand, #2563eb);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.85rem;
    flex-shrink: 0;
}
.attempts-filter-row .header-filter-text {
    flex: 1 1 auto;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    text-align: left;
}
.attempts-filter-row .header-filter-chevron {
    font-size: 0.75rem;
    color: var(--att-text-subtle);
    transition: transform 0.2s ease;
    flex-shrink: 0;
}
.show > .header-filter-btn .header-filter-chevron {
    transform: rotate(180deg);
}

/* Menú Dropdown Estilo Screenshot 3 */
.attempts-filter-row .header-filter-menu {
    min-width: 280px;
    max-width: 330px;
    max-height: 380px;
    border-radius: var(--att-radius);
    border: 1px solid var(--att-border);
    background: var(--att-bg);
    box-shadow: 0 16px 36px rgba(0, 0, 0, 0.15);
    padding: 0;
    overflow: hidden;
    z-index: 1060;
}
[data-bs-theme="dark"] .attempts-filter-row .header-filter-menu {
    background: #181d28;
    border-color: #2a3447;
    box-shadow: 0 20px 45px rgba(0, 0, 0, 0.55);
}
.attempts-filter-row .tenant-menu-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.75rem 0.95rem;
    border-bottom: 1px solid var(--att-border);
    background: var(--att-bg-subtle);
}
.attempts-filter-row .tenant-menu-title {
    font-size: 0.72rem;
    font-weight: 800;
    letter-spacing: 0.05em;
    color: var(--att-text-subtle);
    text-transform: uppercase;
}
.attempts-filter-row .tenant-menu-badge {
    font-size: 0.68rem;
    font-weight: 700;
    padding: 1px 6px;
    border-radius: var(--att-radius-xs);
    background: var(--att-border-light);
    color: var(--att-text-muted);
}
.attempts-filter-row .tenant-options-list {
    max-height: 280px;
    overflow-y: auto;
    padding: 0.4rem;
}
.attempts-filter-row .tenant-menu-item {
    width: 100%;
    display: flex;
    align-items: center;
    gap: 0.65rem;
    padding: 0.5rem 0.65rem;
    border-radius: var(--att-radius-xs);
    border: none;
    background: transparent;
    color: var(--att-text-main);
    text-align: left;
    cursor: pointer;
    transition: all 0.12s ease;
}
.attempts-filter-row .tenant-menu-item:hover {
    background: var(--att-bg-subtle);
}
.attempts-filter-row .tenant-menu-item.is-selected {
    background: rgba(var(--brand-rgb, 37, 99, 235), 0.1);
}
.attempts-filter-row .tenant-item-icon {
    width: 32px;
    height: 32px;
    border-radius: var(--att-radius-xs);
    background: var(--att-bg-subtle);
    color: var(--att-text-muted);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.95rem;
    flex-shrink: 0;
}
.attempts-filter-row .tenant-menu-item.is-selected .tenant-item-icon {
    background: var(--brand, #2563eb);
    color: #ffffff;
}
.attempts-filter-row .tenant-item-content {
    flex: 1 1 auto;
    min-width: 0;
}
.attempts-filter-row .tenant-item-name {
    display: block;
    font-size: 0.815rem;
    font-weight: 600;
    color: var(--att-text-main);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.attempts-filter-row .tenant-item-sub {
    display: block;
    font-size: 0.71rem;
    color: var(--att-text-subtle);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.attempts-filter-row .tenant-item-check {
    font-size: 1rem;
    color: var(--brand, #2563eb);
    flex-shrink: 0;
}

/* Botones Dinámicos de Filtrado */
.attempts-btn-filter {
    height: 38px;
    padding: 0 1rem;
    border-radius: var(--att-radius-sm);
    background: var(--brand, #2563eb);
    color: #ffffff;
    border: none;
    font-size: 0.825rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    cursor: pointer;
    box-shadow: 0 1px 3px rgba(37, 99, 235, 0.25);
    transition: all 0.15s cubic-bezier(0.16, 1, 0.3, 1);
    outline: none !important;
    white-space: nowrap;
    flex-shrink: 0;
}
.attempts-btn-filter:hover {
    background: var(--brand-hover, #1d4ed8);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.35);
}
.attempts-btn-filter:active {
    transform: translateY(0);
}

.attempts-btn-reset {
    height: 38px;
    width: 38px;
    padding: 0;
    border-radius: var(--att-radius-sm);
    background: var(--att-bg-subtle);
    border: 1px solid var(--att-border-light);
    color: var(--att-text-muted);
    font-size: 0.85rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    transition: all 0.15s ease;
    flex-shrink: 0;
}
.attempts-btn-reset:hover {
    background: var(--att-bg-elevated);
    border-color: var(--att-border);
    color: var(--att-text-main);
    transform: rotate(-45deg);
}

/* 5. Mapa: Dónde intentaron marcar */
.attempts-map-card {
    background: var(--att-bg);
    border: 1px solid var(--att-border);
    border-radius: var(--att-radius);
    overflow: hidden;
    box-shadow: var(--att-shadow);
}
.attempts-map-header {
    padding: 0.9rem 1.25rem;
    border-bottom: 1px solid var(--att-border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 0.75rem;
    background: var(--att-bg);
}
.attempts-map-pill {
    display: inline-flex;
    align-items: center;
    padding: 3px 9px;
    border-radius: var(--att-radius-xs);
    font-size: 0.72rem;
    font-weight: 600;
    line-height: 1.25;
}
.attempts-map-pill.tone-brand { background: rgba(var(--brand-rgb, 37, 99, 235), 0.12); color: var(--brand, #2563eb); border: 1px solid rgba(var(--brand-rgb, 37, 99, 235), 0.25); }
.attempts-map-pill.tone-rose { background: rgba(244, 63, 94, 0.12); color: #f43f5e; border: 1px solid rgba(244, 63, 94, 0.25); }
.attempts-map-pill.tone-amber { background: rgba(245, 158, 11, 0.12); color: #d97706; border: 1px solid rgba(245, 158, 11, 0.25); }

/* 6. Tabla de Intentos Rechazados */
.attempts-table-card {
    background: var(--att-bg);
    border: 1px solid var(--att-border);
    border-radius: var(--att-radius);
    box-shadow: var(--att-shadow);
    overflow: hidden;
}
.attempts-table {
    margin-bottom: 0;
    width: 100%;
}
.attempts-table thead th {
    background: var(--att-bg-subtle) !important;
    border-bottom: 1px solid var(--att-border) !important;
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--att-text-subtle);
    padding: 0.85rem 1rem;
    white-space: nowrap;
}
.attempts-table tbody td {
    padding: 0.95rem 1rem;
    border-bottom: 1px solid var(--att-border);
    color: var(--att-text-main);
    vertical-align: middle;
    transition: background 0.12s ease;
}
.attempts-table tbody tr:hover td {
    background-color: var(--att-bg-subtle);
}

/* Identidad y Colaborador */
.attempts-avatar-img {
    width: 38px;
    height: 38px;
    border-radius: var(--att-radius-xs);
    object-fit: cover;
    flex-shrink: 0;
    border: 1px solid var(--att-border);
}
.attempts-avatar-circle {
    width: 38px;
    height: 38px;
    border-radius: var(--att-radius-xs);
    background: rgba(var(--brand-rgb, 37, 99, 235), 0.12);
    color: var(--brand, #2563eb);
    font-weight: 700;
    font-size: 0.85rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    border: 1px solid rgba(var(--brand-rgb, 37, 99, 235), 0.25);
}
[data-bs-theme="dark"] .attempts-avatar-circle {
    background: rgba(56, 189, 248, 0.15);
    color: #38bdf8;
    border-color: rgba(56, 189, 248, 0.3);
}

.attempts-code-badge {
    font-size: 0.65rem;
    font-weight: 700;
    padding: 1px 5px;
    border-radius: var(--att-radius-xs);
    background: var(--att-bg-subtle);
    color: var(--att-text-muted);
    border: 1px solid var(--att-border);
    letter-spacing: 0.02em;
}

/* Micro-pills */
.attempts-pill {
    display: inline-flex;
    align-items: center;
    padding: 2px 7px;
    border-radius: var(--att-radius-xs);
    font-size: 0.72rem;
    font-weight: 600;
    line-height: 1.25;
}

/* MEJORA CRUCIAL DEL UI: CAJA DIAGNÓSTICA DE MOTIVO */
.attempts-reason-wrap {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 4px;
}
.attempts-diag-box {
    display: inline-flex;
    align-items: flex-start;
    gap: 6px;
    padding: 4px 8px;
    border-radius: var(--att-radius-xs);
    background: var(--att-bg-subtle);
    border: 1px solid var(--att-border);
    max-width: 290px;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.02);
}
[data-bs-theme="dark"] .attempts-diag-box {
    background: rgba(255, 255, 255, 0.035);
    border-color: rgba(255, 255, 255, 0.08);
}
.attempts-diag-icon {
    font-size: 0.75rem;
    color: var(--att-text-subtle);
    margin-top: 1px;
    flex-shrink: 0;
}
.attempts-diag-text {
    font-size: 0.735rem;
    font-weight: 500;
    color: var(--att-text-muted);
    line-height: 1.35;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Botón Dinámico de Auditoría Forense */
.attempts-btn-audit {
    height: 34px;
    padding: 0 12px;
    border-radius: var(--att-radius-sm);
    font-weight: 600;
    font-size: 0.785rem;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: var(--att-bg-subtle);
    border: 1px solid var(--att-border-light);
    color: var(--att-text-main);
    cursor: pointer;
    transition: all 0.16s cubic-bezier(0.16, 1, 0.3, 1);
    text-decoration: none;
    white-space: nowrap;
    box-shadow: var(--att-shadow);
}
.attempts-btn-audit:hover {
    background: var(--brand, #2563eb);
    color: #ffffff;
    border-color: var(--brand, #2563eb);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.28);
}
.attempts-btn-audit:active {
    transform: translateY(0);
}

/* 7. Footer de Paginación */
.attempts-pagination-footer {
    padding: 0.85rem 1.25rem;
    background: var(--att-bg-subtle);
    border-top: 1px solid var(--att-border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 0.75rem;
}
.attempts-per-page-select {
    height: 32px;
    border-radius: var(--att-radius-xs);
    border: 1px solid var(--att-border-light);
    background: var(--att-bg);
    color: var(--att-text-main);
    font-size: 0.775rem;
    font-weight: 600;
    padding: 0 0.5rem;
    outline: none;
}
.attempts-pagination-footer .pagination {
    margin-bottom: 0;
    gap: 4px;
}
.attempts-pagination-footer .page-link {
    border-radius: var(--att-radius-xs) !important;
    border: 1px solid var(--att-border-light);
    background: var(--att-bg);
    color: var(--att-text-main);
    font-size: 0.775rem;
    font-weight: 600;
    padding: 0.35rem 0.65rem;
    transition: all 0.12s ease;
}
.attempts-pagination-footer .page-link:hover {
    background: var(--att-bg-elevated);
    border-color: var(--brand, #2563eb);
    color: var(--brand, #2563eb);
}
.attempts-pagination-footer .page-item.active .page-link {
    background: var(--brand, #2563eb);
    border-color: var(--brand, #2563eb);
    color: #ffffff;
}

/* 8. Modal de Auditoría Forense */
.attempts-modal-content {
    border: 1px solid var(--att-border) !important;
    border-radius: 12px !important;
    background: var(--att-bg);
    box-shadow: var(--att-shadow-hover);
    overflow: hidden;
}
.attempts-audit-section {
    padding: 1rem 1.15rem;
    border-radius: var(--att-radius-sm);
    background: var(--att-bg-subtle);
    border: 1px solid var(--att-border);
    margin-bottom: 1rem;
}
.attempts-audit-label {
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--att-text-subtle);
    margin-bottom: 0.35rem;
}
.attempts-audit-value {
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--att-text-main);
}
</style>
@endsection

@section('content')
@php
    $todayStr = \Carbon\Carbon::now($tz)->toDateString();
    $yesterdayStr = \Carbon\Carbon::now($tz)->subDay()->toDateString();
    $weekStr = \Carbon\Carbon::now($tz)->subDays(6)->toDateString();
    $thirtyStr = \Carbon\Carbon::now($tz)->subDays(29)->toDateString();
    $monthStartStr = \Carbon\Carbon::now($tz)->startOfMonth()->toDateString();
    $monthEndStr = \Carbon\Carbon::now($tz)->endOfMonth()->toDateString();

    $presetLabel = 'Personalizado';
    $activePreset = '';
    if ($startDate === $todayStr && $endDate === $todayStr) {
        $presetLabel = 'Hoy';
        $activePreset = 'today';
    } elseif ($startDate === $yesterdayStr && $endDate === $yesterdayStr) {
        $presetLabel = 'Ayer';
        $activePreset = 'yesterday';
    } elseif ($startDate === $weekStr && $endDate === $todayStr) {
        $presetLabel = 'Últimos 7 días';
        $activePreset = 'week';
    } elseif ($startDate === $thirtyStr && $endDate === $todayStr) {
        $presetLabel = 'Últimos 30 días';
        $activePreset = 'last_30_days';
    } elseif ($startDate === $monthStartStr && ($endDate === $monthEndStr || $endDate === $todayStr)) {
        $presetLabel = 'Este mes';
        $activePreset = 'month';
    }

    $selectedBranchName = 'Todas las sedes';
    if (request('branch_id')) {
        $brMatch = $branches->firstWhere('id', (int) request('branch_id'));
        if ($brMatch) $selectedBranchName = $brMatch->name;
    }

    $selectedCodeName = 'Todos los motivos';
    if (request('code')) {
        $selectedCodeName = $codes[request('code')] ?? request('code');
    }

    $punchTypes = \App\Modules\Core\Support\Labels::options('punch_type');
    $selectedTypeName = 'Entrada, salida y descansos';
    if (request('type')) {
        $selectedTypeName = $punchTypes[request('type')] ?? request('type');
    }
@endphp

<div class="attempts-module">

    {{-- 1. Header con Título, Contador y Enlaces Rápidos --}}
    <header class="attempts-header-wrapper">
        <div class="d-flex align-items-center gap-3">
            <div class="attempts-header-icon">
                <i class="bi bi-shield-x"></i>
            </div>
            <div>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <h1 class="page-title mb-0" style="font-size: 1.55rem; font-weight: 800; letter-spacing: -0.02em;">Intentos rechazados</h1>
                    <span class="attempts-count-badge">{{ number_format($total) }} registrados</span>
                </div>
                <p class="page-subtitle mb-0 mt-1" style="color: var(--att-text-muted); font-size: 0.85rem;">
                    Marcaciones bloqueadas por geocerca, ubicación simulada (Fake GPS), GPS impreciso o políticas de turno.
                </p>
            </div>
        </div>
        <div class="attempts-header-actions">
            <a href="{{ route('attendance.index') }}" class="attempts-nav-btn">
                <i class="bi bi-clock-history"></i>
                <span>Marcaciones y GPS</span>
            </a>
            <a href="{{ route('attendance.calendar') }}" class="attempts-nav-btn">
                <i class="bi bi-calendar3"></i>
                <span>Calendario</span>
            </a>
            <a href="{{ route('live.index') }}" class="attempts-nav-btn">
                <i class="bi bi-broadcast"></i>
                <span>En vivo</span>
            </a>
        </div>
    </header>

    {{-- 2. Banner de Información de Sedes --}}
    @if($flaggingBranches->isNotEmpty())
        <div class="attempts-notice-banner" role="status">
            <i class="bi bi-info-circle-fill attempts-notice-icon"></i>
            <div class="attempts-notice-content">
                <strong>{{ $flaggingBranches->count() === 1 ? 'La sede ' . $flaggingBranches->first() . ' acepta' : $flaggingBranches->count() . ' sedes aceptan' }} las marcaciones fuera de la geocerca.</strong>
                Esas marcaciones no se rechazan: quedan registradas como «Fuera de ubicación» en
                <a href="{{ route('attendance.index', ['start_date' => $startDate, 'end_date' => $endDate]) }}" class="attempts-notice-link">Marcaciones y GPS</a>.
                Aquí solo aparecen las que la app o el servidor no aceptaron. La regla se define en Ajustes › Reglas de asistencia y puede ajustarse por sede.
            </div>
        </div>
    @endif

    {{-- 3. Resumen por Motivo (KPI Cards con Esquinas Modernas No Circulares) --}}
    <section class="attempts-kpi-grid" aria-label="Resumen por motivo">
        @foreach ([
            [null, 'Total en el rango', $total, 'brand', 'slash-circle', $uniqueUsersCount . ' colaboradores', 'Ver todos los intentos rechazados'],
            ['MOCK_LOCATION_DETECTED', 'Ubicación simulada', $mockCount, 'rose', 'phone-vibrate', 'Fake GPS detectado', 'Filtrar por ubicación simulada'],
            ['GEOFENCE_VIOLATION', 'Fuera de la geocerca', $byCode['GEOFENCE_VIOLATION'] ?? 0, 'amber', 'geo-alt', 'Fuera del radio', 'Filtrar por fuera de la geocerca'],
            ['GPS_ACCURACY_TOO_LOW', 'GPS impreciso', $byCode['GPS_ACCURACY_TOO_LOW'] ?? 0, 'blue', 'crosshair2', 'Baja precisión satelital', 'Filtrar por GPS impreciso'],
            [\App\Modules\Attendance\Controllers\Web\PunchAttemptWebController::OTHER_CODE, 'Otros motivos', $otherCount, 'neutral', 'three-dots', 'Turno, selfie, sin sede…', 'Filtrar por otros motivos'],
        ] as [$kcode, $klabel, $kvalue, $ktone, $kicon, $kmeta, $ktitle])
            @php $kactive = $kcode === null ? ! request('code') : request('code') === $kcode; @endphp
            <a href="{{ request()->fullUrlWithQuery(['code' => $kcode, 'page' => null]) }}" 
               class="attempts-kpi-card {{ $kactive ? 'is-active' : '' }}" 
               title="{{ $ktitle }}" 
               @if($kactive) aria-current="true" @endif>
                <div class="attempts-kpi-top">
                    <span class="attempts-kpi-label">{{ $klabel }}</span>
                    <span class="attempts-kpi-icon-wrap tone-{{ $ktone }}"><i class="bi bi-{{ $kicon }}"></i></span>
                </div>
                <div class="attempts-kpi-value">{{ number_format($kvalue) }}</div>
                <div class="attempts-kpi-meta">
                    <i class="bi bi-dot" style="font-size: 1.25rem; line-height: 0;"></i>
                    <span>{{ $kmeta }}</span>
                </div>
            </a>
        @endforeach
    </section>

    {{-- 4. FILTROS MODERNOS EN UNA SOLA LÍNEA HORIZONTAL (ADAPTABLES 100% SIN ESPACIOS MUERTOS) --}}
    <section class="attempts-filter-bar">
        <form method="GET" action="{{ route('attendance.attempts') }}" id="attemptsFilterForm" class="attempts-filter-row" role="search" autocomplete="off">
            <input type="hidden" name="per_page" value="{{ request('per_page', $attempts->perPage()) }}">
            <input type="hidden" name="start_date" id="start_date" value="{{ $startDate }}">
            <input type="hidden" name="end_date" id="end_date" value="{{ $endDate }}">
            <input type="hidden" name="branch_id" id="branch_id" value="{{ request('branch_id') }}">
            <input type="hidden" name="code" id="code" value="{{ request('code') }}">
            <input type="hidden" name="type" id="type" value="{{ request('type') }}">

            {{-- 1. Buscador Adaptable y Sin Autocompletados Invasivos --}}
            <div class="attempts-search-wrap">
                <i class="bi bi-search attempts-search-icon"></i>
                <input type="text" id="search" name="search" value="{{ request('search') }}" 
                       class="attempts-search-input" 
                       placeholder="Buscar por colaborador o DNI…" 
                       autocomplete="off"
                       autocorrect="off"
                       autocapitalize="off"
                       spellcheck="false"
                       aria-label="Colaborador">
            </div>

            {{-- 2. Filtro de Calendario / Período Interactivo Profesional (Screenshot 2) --}}
            <div class="dropdown" id="attemptsCalendarDropdown">
                <button type="button" class="date-filter-pill dropdown-toggle" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" id="attemptsDateFilterDropdownBtn" title="Seleccionar periodo">
                    <i class="bi bi-calendar3 text-primary"></i>
                    <span class="fw-bold text-dark" id="attemptsCalTriggerLabel">{{ $presetLabel }}</span>
                    <span class="divider">|</span>
                    <span id="attemptsCalTriggerFrom">{{ \Carbon\Carbon::parse($startDate)->format('d/m/Y') }}</span>
                    <span class="divider">|</span>
                    <span id="attemptsCalTriggerTo">{{ \Carbon\Carbon::parse($endDate)->format('d/m/Y') }}</span>
                </button>
                <div class="dropdown-menu dropdown-menu-start calendar-pro-popover shadow-lg" aria-labelledby="attemptsDateFilterDropdownBtn">
                    <div class="calendar-pro-grid">
                        {{-- Columna Izquierda: ACCESOS RÁPIDOS --}}
                        <div class="calendar-sidebar-presets">
                            <div class="calendar-presets-heading">ACCESOS RÁPIDOS</div>
                            <button type="button" class="calendar-preset-btn {{ $activePreset === 'today' ? 'is-active' : '' }}" data-preset="today">
                                <span>Hoy</span>
                                <i class="bi bi-check-lg {{ $activePreset === 'today' ? '' : 'd-none' }}"></i>
                            </button>
                            <button type="button" class="calendar-preset-btn {{ $activePreset === 'yesterday' ? 'is-active' : '' }}" data-preset="yesterday">
                                <span>Ayer</span>
                                <i class="bi bi-check-lg {{ $activePreset === 'yesterday' ? '' : 'd-none' }}"></i>
                            </button>
                            <button type="button" class="calendar-preset-btn {{ $activePreset === 'week' ? 'is-active' : '' }}" data-preset="week">
                                <span>Últimos 7 días</span>
                                <i class="bi bi-check-lg {{ $activePreset === 'week' ? '' : 'd-none' }}"></i>
                            </button>
                            <button type="button" class="calendar-preset-btn {{ $activePreset === 'last_30_days' ? 'is-active' : '' }}" data-preset="last_30_days">
                                <span>Últimos 30 días</span>
                                <i class="bi bi-check-lg {{ $activePreset === 'last_30_days' ? '' : 'd-none' }}"></i>
                            </button>
                            <button type="button" class="calendar-preset-btn {{ $activePreset === 'month' ? 'is-active' : '' }}" data-preset="month">
                                <span>Este mes</span>
                                <i class="bi bi-check-lg {{ $activePreset === 'month' ? '' : 'd-none' }}"></i>
                            </button>
                            <button type="button" class="calendar-preset-btn {{ $activePreset === 'prev_month' ? 'is-active' : '' }}" data-preset="prev_month">
                                <span>Mes anterior</span>
                                <i class="bi bi-check-lg {{ $activePreset === 'prev_month' ? '' : 'd-none' }}"></i>
                            </button>
                        </div>

                        {{-- Columna Derecha: Selector de Fechas y Calendario del Mes --}}
                        <div class="calendar-content-pane">
                            <div class="calendar-date-inputs-row">
                                <div class="calendar-input-field is-active" id="attemptsCalFromInputWrapper">
                                    <label class="calendar-input-label">Desde</label>
                                    <input type="text" id="attemptsCalInputFrom" value="{{ \Carbon\Carbon::parse($startDate)->format('d/m/Y') }}" placeholder="DD/MM/AAAA" readonly>
                                </div>
                                <div class="calendar-input-field" id="attemptsCalToInputWrapper">
                                    <label class="calendar-input-label">Hasta</label>
                                    <input type="text" id="attemptsCalInputTo" value="{{ \Carbon\Carbon::parse($endDate)->format('d/m/Y') }}" placeholder="DD/MM/AAAA" readonly>
                                </div>
                            </div>

                            {{-- Navegación del mes --}}
                            <div class="calendar-nav-header">
                                <button type="button" class="calendar-nav-btn" id="attemptsCalPrevMonthBtn" title="Mes anterior">
                                    <i class="bi bi-chevron-left"></i>
                                </button>
                                <span class="calendar-month-title" id="attemptsCalCurrentMonthTitle"></span>
                                <button type="button" class="calendar-nav-btn" id="attemptsCalNextMonthBtn" title="Mes siguiente">
                                    <i class="bi bi-chevron-right"></i>
                                </button>
                            </div>

                            {{-- Días de la semana --}}
                            <div class="calendar-days-grid mb-1">
                                <div class="calendar-day-header">L</div>
                                <div class="calendar-day-header">M</div>
                                <div class="calendar-day-header">X</div>
                                <div class="calendar-day-header">J</div>
                                <div class="calendar-day-header">V</div>
                                <div class="calendar-day-header">S</div>
                                <div class="calendar-day-header">D</div>
                            </div>

                            {{-- Días dinámicos del mes --}}
                            <div class="calendar-days-grid" id="attemptsCalDaysGrid"></div>

                            <div class="calendar-helper-note">
                                Selecciona la fecha inicial. Se incluyen ambos días.
                            </div>

                            <div class="calendar-footer-actions">
                                <button type="button" class="calendar-btn-cancel" id="attemptsCalCancelBtn">Cancelar</button>
                                <button type="button" class="calendar-btn-apply" id="attemptsCalApplyBtn">Aplicar</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- 3. Combobox Moderno: Sucursal (Screenshot 3) --}}
            <div class="dropdown header-filter-dropdown" id="branchDropdownWrapper">
                <button type="button" class="header-filter-btn" data-bs-toggle="dropdown" aria-expanded="false" id="branchDropdownBtn" title="{{ $selectedBranchName }}">
                    <span class="header-filter-icon-box">
                        <i class="bi bi-geo-alt"></i>
                    </span>
                    <span class="header-filter-text" id="branchBtnText">{{ $selectedBranchName }}</span>
                    <i class="bi bi-chevron-down header-filter-chevron"></i>
                </button>
                <div class="dropdown-menu dropdown-menu-start header-filter-menu shadow-lg" id="branchMenu" aria-labelledby="branchDropdownBtn">
                    <div class="tenant-menu-header">
                        <span class="tenant-menu-title"><i class="bi bi-geo-alt-fill text-primary me-1"></i> FILTRAR SEDE</span>
                        <span class="tenant-menu-badge">{{ $branches->count() }} sedes</span>
                    </div>
                    @if($branches->count() >= 4)
                        <div class="tenant-search-box p-2 border-bottom">
                            <input type="text" class="form-control form-control-sm" placeholder="Buscar sede..." oninput="filterTenantMenu(this, '#branchMenu')">
                        </div>
                    @endif
                    <div class="tenant-options-list">
                        <button type="button" class="tenant-menu-item {{ empty(request('branch_id')) ? 'is-selected' : '' }}" 
                                onclick="selectAttemptBranch('', 'Todas las sedes', this)" data-search="todas las sedes sucursales">
                            <span class="tenant-item-icon">
                                <i class="bi bi-geo-alt-fill"></i>
                            </span>
                            <div class="tenant-item-content">
                                <span class="tenant-item-name">Todas las sedes</span>
                                <span class="tenant-item-sub">Consolidado general</span>
                            </div>
                            <i class="bi bi-check2 tenant-item-check {{ empty(request('branch_id')) ? '' : 'd-none' }}"></i>
                        </button>
                        @foreach($branches as $b)
                            <button type="button" class="tenant-menu-item {{ (int)request('branch_id') === (int)$b->id ? 'is-selected' : '' }}" 
                                    onclick="selectAttemptBranch('{{ $b->id }}', '{{ addslashes($b->name) }}', this)" data-search="{{ strtolower($b->name) }}">
                                <span class="tenant-item-icon">
                                    <i class="bi bi-pin-map"></i>
                                </span>
                                <div class="tenant-item-content">
                                    <span class="tenant-item-name">{{ $b->name }}</span>
                                    <span class="tenant-item-sub">Radio: {{ $b->allowed_radius_meters ?? 50 }} m</span>
                                </div>
                                <i class="bi bi-check2 tenant-item-check {{ (int)request('branch_id') === (int)$b->id ? '' : 'd-none' }}"></i>
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- 4. Combobox Moderno: Motivo (Screenshot 3) --}}
            <div class="dropdown header-filter-dropdown" id="codeDropdownWrapper">
                <button type="button" class="header-filter-btn" data-bs-toggle="dropdown" aria-expanded="false" id="codeDropdownBtn" title="{{ $selectedCodeName }}">
                    <span class="header-filter-icon-box">
                        <i class="bi bi-shield-exclamation"></i>
                    </span>
                    <span class="header-filter-text" id="codeBtnText">{{ $selectedCodeName }}</span>
                    <i class="bi bi-chevron-down header-filter-chevron"></i>
                </button>
                <div class="dropdown-menu dropdown-menu-start header-filter-menu shadow-lg" id="codeMenu" aria-labelledby="codeDropdownBtn">
                    <div class="tenant-menu-header">
                        <span class="tenant-menu-title"><i class="bi bi-shield-fill-exclamation text-primary me-1"></i> FILTRAR MOTIVO</span>
                        <span class="tenant-menu-badge">{{ count($codes) }} disp.</span>
                    </div>
                    <div class="tenant-options-list">
                        <button type="button" class="tenant-menu-item {{ empty(request('code')) ? 'is-selected' : '' }}" 
                                onclick="selectAttemptCode('', 'Todos los motivos', this)" data-search="todos los motivos general">
                            <span class="tenant-item-icon">
                                <i class="bi bi-shield-check"></i>
                            </span>
                            <div class="tenant-item-content">
                                <span class="tenant-item-name">Todos los motivos</span>
                                <span class="tenant-item-sub">Muestra todos los rechazos</span>
                            </div>
                            <i class="bi bi-check2 tenant-item-check {{ empty(request('code')) ? '' : 'd-none' }}"></i>
                        </button>
                        @foreach($codes as $val => $txt)
                            @php
                                $codeIcon = match($val) {
                                    'MOCK_LOCATION_DETECTED' => 'phone-vibrate text-danger',
                                    'GEOFENCE_VIOLATION' => 'geo-alt text-warning',
                                    'GPS_ACCURACY_TOO_LOW' => 'crosshair text-primary',
                                    default => 'slash-circle text-secondary'
                                };
                                // Explicación corta en lugar del código interno (GEOFENCE_VIOLATION…)
                                $codeHint = [
                                    'ONBOARDING_PENDING'      => 'Aún no completa su incorporación',
                                    'GEOFENCE_VIOLATION'      => 'Marcó fuera del radio de su sede',
                                    'MOCK_LOCATION_DETECTED'  => 'App de ubicación falsa (Fake GPS)',
                                    'GPS_ACCURACY_TOO_LOW'    => 'Señal GPS con poca precisión',
                                    'FACE_NOT_DETECTED'       => 'La selfie no muestra un rostro',
                                    'NO_BRANCH_ASSIGNED'      => 'No tiene una sede asignada',
                                    'BREAK_NOT_ALLOWED'       => 'Su horario no incluye descanso',
                                    'OFFLINE_NOT_ALLOWED'     => 'La empresa no permite marcar sin internet',
                                    'OFFLINE_TOO_OLD'         => 'Se envió pasado el plazo permitido',
                                    'OFFLINE_BEFORE_CLOCK_IN' => 'Marcó antes de registrar su entrada',
                                    'OFFLINE_OUT_OF_ORDER'    => 'Marcaciones en orden incorrecto',
                                    'PHOTO_UNREADABLE'        => 'No se pudo leer la foto',
                                    'OTHER'                   => 'Turno, selfie, sin sede y otros',
                                ][$val] ?? 'Motivo de rechazo';
                            @endphp
                            <button type="button" class="tenant-menu-item {{ request('code') === $val ? 'is-selected' : '' }}" 
                                    onclick="selectAttemptCode('{{ $val }}', '{{ addslashes($txt) }}', this)" data-search="{{ strtolower($txt) }}">
                                <span class="tenant-item-icon">
                                    <i class="bi bi-{{ $codeIcon }}"></i>
                                </span>
                                <div class="tenant-item-content">
                                    <span class="tenant-item-name">{{ $txt }}</span>
                                    <span class="tenant-item-sub">{{ $codeHint }}</span>
                                </div>
                                <i class="bi bi-check2 tenant-item-check {{ request('code') === $val ? '' : 'd-none' }}"></i>
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- 5. Combobox Moderno: Tipo de Marcación (Screenshot 3) --}}
            <div class="dropdown header-filter-dropdown" id="typeDropdownWrapper">
                <button type="button" class="header-filter-btn" data-bs-toggle="dropdown" aria-expanded="false" id="typeDropdownBtn" title="{{ $selectedTypeName }}">
                    <span class="header-filter-icon-box">
                        <i class="bi bi-clock"></i>
                    </span>
                    <span class="header-filter-text" id="typeBtnText">{{ $selectedTypeName }}</span>
                    <i class="bi bi-chevron-down header-filter-chevron"></i>
                </button>
                <div class="dropdown-menu dropdown-menu-start header-filter-menu shadow-lg" id="typeMenu" aria-labelledby="typeDropdownBtn">
                    <div class="tenant-menu-header">
                        <span class="tenant-menu-title"><i class="bi bi-clock-fill text-primary me-1"></i> TIPO MARCACIÓN</span>
                        <span class="tenant-menu-badge">{{ count($punchTypes) }} disp.</span>
                    </div>
                    <div class="tenant-options-list">
                        <button type="button" class="tenant-menu-item {{ empty(request('type')) ? 'is-selected' : '' }}" 
                                onclick="selectAttemptType('', 'Entrada, salida y descansos', this)" data-search="todos los tipos entrada salida descanso">
                            <span class="tenant-item-icon">
                                <i class="bi bi-clock-history"></i>
                            </span>
                            <div class="tenant-item-content">
                                <span class="tenant-item-name">Todos los tipos</span>
                                <span class="tenant-item-sub">Entrada, salida y descansos</span>
                            </div>
                            <i class="bi bi-check2 tenant-item-check {{ empty(request('type')) ? '' : 'd-none' }}"></i>
                        </button>
                        @foreach($punchTypes as $val => $txt)
                            @php
                                $typeIcon = match($val) {
                                    'clock_in' => 'box-arrow-in-right text-success',
                                    'clock_out' => 'box-arrow-left text-danger',
                                    'break_start' => 'cup-hot text-warning',
                                    'break_end' => 'play-circle text-info',
                                    default => 'clock text-secondary'
                                };
                            @endphp
                            <button type="button" class="tenant-menu-item {{ request('type') === $val ? 'is-selected' : '' }}" 
                                    onclick="selectAttemptType('{{ $val }}', '{{ addslashes($txt) }}', this)" data-search="{{ strtolower($txt) }}">
                                <span class="tenant-item-icon">
                                    <i class="bi bi-{{ $typeIcon }}"></i>
                                </span>
                                <div class="tenant-item-content">
                                    <span class="tenant-item-name">{{ $txt }}</span>
                                    <span class="tenant-item-sub">Tipo de registro</span>
                                </div>
                                <i class="bi bi-check2 tenant-item-check {{ request('type') === $val ? '' : 'd-none' }}"></i>
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- 6. Botones de Filtrar y Limpiar en la misma línea --}}
            <button type="submit" class="attempts-btn-filter" title="Ejecutar filtros">
                <i class="bi bi-funnel-fill"></i>
                <span>Filtrar</span>
            </button>
            @if(request()->hasAny(['search', 'branch_id', 'code', 'type', 'start_date', 'end_date']))
                <a href="{{ route('attendance.attempts') }}" class="attempts-btn-reset" title="Restablecer todos los filtros" aria-label="Limpiar filtros">
                    <i class="bi bi-arrow-counterclockwise"></i>
                </a>
            @endif
        </form>
    </section>

    {{-- 5. Mapa Geoespacial: Dónde intentaron marcar --}}
    @if($mapPoints->isNotEmpty())
    <section class="attempts-map-card">
        <div class="attempts-map-header">
            <div>
                <h2 style="font-size: 0.95rem; font-weight: 700; color: var(--att-text-main); margin-bottom: 2px;">
                    <i class="bi bi-geo-alt-fill text-danger me-1"></i>Dónde intentaron marcar
                </h2>
                <p style="font-size: 0.775rem; color: var(--att-text-subtle); margin: 0;">
                    Intentos de esta página y perímetro de cada sede (línea punteada: tolerancia del borde)
                </p>
            </div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <span class="attempts-map-pill tone-brand">Sedes y perímetro</span>
                <span class="attempts-map-pill tone-rose">GPS simulado</span>
                <span class="attempts-map-pill tone-amber">Fuera de radio</span>
                <button type="button" class="attempts-btn-reset" id="toggleMapBtn" style="height: 32px; width: auto; padding: 0 10px; font-size: 0.775rem;">
                    <i class="bi bi-arrows-angle-contract me-1" id="toggleMapIcon"></i>
                    <span id="toggleMapText">Contraer</span>
                </button>
            </div>
        </div>
        <div id="attemptsMapWrapper">
            <div id="attemptsMap" style="height: 330px;" class="w-100"></div>
        </div>
    </section>
    @endif

    {{-- 6. Tabla de Intentos Rechazados (Totalmente Adaptable, Ordenada y Profesional) --}}
    <section class="attempts-table-card">
        <div class="table-responsive">
            <table class="table attempts-table align-middle">
                <thead>
                    <tr>
                        <th style="width: 22%;">Colaborador</th>
                        <th style="width: 14%;">Fecha y hora</th>
                        <th style="width: 12%;">Marcación</th>
                        <th style="width: 24%;">Motivo</th>
                        <th style="width: 14%;">Ubicación</th>
                        <th style="width: 9%;">Teléfono</th>
                        <th class="text-end" style="width: 5%;">Auditoría</th>
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
                                'date_formatted' => $a->created_at->copy()->setTimezone($tz)->format('d/m/Y h:i:s a'),
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
                            <td>
                                <div class="d-flex align-items-center gap-3">
                                    @if(!empty($a->user?->avatar_path))
                                        <img src="{{ asset('storage/' . $a->user->avatar_path) }}" alt="{{ $userFullName }}" class="attempts-avatar-img">
                                    @else
                                        <span class="attempts-avatar-circle">{{ $userInitials }}</span>
                                    @endif
                                    <div class="min-w-0" style="max-width: 220px;">
                                        <div class="fw-bold text-truncate" style="font-size: 0.85rem; color: var(--att-text-main);" title="{{ $userFullName }}">
                                            {{ $userFullName }}
                                        </div>
                                        <div class="d-flex align-items-center gap-1 flex-wrap mt-0.5">
                                            @if($a->user?->employee_code)
                                                <span class="attempts-code-badge">{{ $a->user->employee_code }}</span>
                                            @endif
                                            <span class="text-truncate small" style="font-size: 0.735rem; color: var(--att-text-subtle); max-width: 140px;" title="{{ $a->branch->name ?? 'Sin sucursal' }}">
                                                {{ $a->branch->name ?? 'Sin sucursal' }}
                                            </span>
                                        </div>
                                        <div class="text-truncate small mt-0.5" style="font-size: 0.72rem; color: var(--att-text-subtle); max-width: 200px;" title="{{ $userPos }}">
                                            {{ $userPos }}
                                        </div>
                                        @include('partials.tenant-badge', ['tenantId' => $a->tenant_id])
                                    </div>
                                </div>
                            </td>

                            {{-- 2. Fecha y hora --}}
                            <td>
                                <div class="fw-bold" style="font-size: 0.835rem; color: var(--att-text-main);">
                                    {{ $a->created_at->copy()->setTimezone($tz)->format('d/m/Y') }}
                                </div>
                                <div class="small d-flex align-items-center mt-0.5" style="font-size: 0.75rem; color: var(--att-text-muted);">
                                    <i class="bi bi-clock me-1 text-muted"></i>
                                    <span>{{ $a->created_at->copy()->setTimezone($tz)->format('h:i:s a') }}</span>
                                </div>
                                <div class="d-flex align-items-center gap-1 mt-1 flex-wrap">
                                    @if($a->fromApp())
                                        <span class="attempts-pill bg-info-subtle text-info-emphasis border border-info-subtle" title="La app bloqueó la marcación antes de enviarla">
                                            <i class="bi bi-phone me-1"></i>
                                            <span>Desde la app</span>
                                        </span>
                                    @endif
                                    @if($a->offline)
                                        <span class="attempts-pill bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle" title="Hecha sin conexión el {{ $a->offline_recorded_at?->timezone(config('app.timezone'))->format('d/m/Y h:i a') }}">
                                            <i class="bi bi-wifi-off me-1"></i>
                                            <span>Sin conexión</span>
                                        </span>
                                    @endif
                                </div>
                            </td>

                            {{-- 3. Tipo de Marcación --}}
                            <td>
                                <x-status-badge type="punch_type" :value="$a->type" />
                            </td>

                            {{-- 4. Motivo del Rechazo (Organizado y con Caja Diagnóstica Elegante) --}}
                            <td>
                                <div class="attempts-reason-wrap">
                                    <x-status-badge type="punch_attempt_code" :value="$a->error_code" />
                                    @if($a->message)
                                        <div class="attempts-diag-box" title="{{ $a->message }}">
                                            <i class="bi bi-info-circle-fill attempts-diag-icon"></i>
                                            <span class="attempts-diag-text">{{ $a->message }}</span>
                                        </div>
                                    @endif
                                </div>
                            </td>

                            {{-- 5. Ubicación y Telemetría --}}
                            <td>
                                @if($a->distance_meters !== null)
                                    <div class="text-danger fw-semibold d-flex align-items-center" style="font-size: 0.78rem; white-space: nowrap;">
                                        <i class="bi bi-geo-alt-fill me-1"></i>
                                        <span>{{ $a->distance_meters }} m <small class="text-secondary fw-normal">de {{ $a->allowed_radius ?? '—' }} m permitidos</small></span>
                                    </div>
                                    @if($a->allowed_radius !== null && $a->distance_meters > $a->allowed_radius)
                                        <div class="small fw-semibold text-danger-emphasis mt-0.5" style="font-size: 0.72rem;" title="Distancia fuera del radio de la sede">
                                            Excedió por {{ $a->distance_meters - $a->allowed_radius }} m
                                        </div>
                                    @endif
                                @endif
                                @if($a->accuracy_meters !== null)
                                    <div class="small mt-0.5 d-flex align-items-center" style="font-size: 0.725rem; color: var(--att-text-subtle); white-space: nowrap;">
                                        <i class="bi bi-crosshair me-1"></i>
                                        <span>Precisión {{ rtrim(rtrim(number_format($a->accuracy_meters, 1, '.', ''), '0'), '.') }} m</span>
                                    </div>
                                @endif
                                @if($a->mapUrl())
                                    <div class="mt-1">
                                        <a href="{{ $a->mapUrl() }}" target="_blank" rel="noopener" class="link-primary text-decoration-none fw-semibold d-inline-flex align-items-center" style="font-size: 0.735rem;">
                                            <i class="bi bi-box-arrow-up-right me-1"></i>
                                            <span>Ver en el mapa</span>
                                        </a>
                                    </div>
                                @endif
                                @if($a->mock_location_detected)
                                    <div class="mt-1">
                                        <span class="attempts-pill bg-danger-subtle text-danger border border-danger-subtle fw-bold">
                                            <i class="bi bi-phone-vibrate me-1"></i>
                                            <span>GPS simulado</span>
                                        </span>
                                    </div>
                                @endif
                            </td>

                            {{-- 6. Teléfono & Dispositivo --}}
                            <td>
                                <div class="text-truncate fw-semibold d-flex align-items-center" style="font-size: 0.8rem; color: var(--att-text-main);">
                                    @if(str_contains(strtolower($a->device_os ?? ''), 'android'))
                                        <i class="bi bi-android2 text-success me-1.5" style="font-size: 0.95rem;"></i>
                                    @elseif(str_contains(strtolower($a->device_os ?? ''), 'ios') || str_contains(strtolower($a->device_os ?? ''), 'iphone'))
                                        <i class="bi bi-apple text-dark me-1.5" style="font-size: 0.95rem;"></i>
                                    @else
                                        <i class="bi bi-phone text-secondary me-1.5"></i>
                                    @endif
                                    <span>{{ $a->device_os ?? '—' }}</span>
                                </div>
                                <div class="small mt-0.5" style="font-size: 0.725rem; color: var(--att-text-subtle);">
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
                                    <div class="mt-0.5 font-monospace text-truncate" style="font-size: 0.7rem; color: var(--att-text-subtle); max-width: 120px;" title="{{ $a->ip }}">
                                        {{ $a->ip }}
                                    </div>
                                @endif
                            </td>

                            {{-- 7. Auditoría Forense --}}
                            <td class="text-end">
                                <button type="button" 
                                        class="attempts-btn-audit" 
                                        onclick="openAttemptAudit(@js($attemptJson))"
                                        title="Inspeccionar telemetría completa y auditoría forense">
                                    <i class="bi bi-shield-check"></i>
                                    <span>Auditar</span>
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr class="row-empty">
                            <td colspan="7">
                                <div class="dash-empty py-5 text-center">
                                    <span class="dash-empty-icon text-muted fs-1 mb-2 d-inline-block"><i class="bi bi-shield-check"></i></span>
                                    <p class="dash-empty-title fw-bold fs-6 mb-1 text-body">No hubo intentos rechazados en este rango.</p>
                                    <p class="dash-empty-text small text-muted mb-0">Todas las marcaciones del periodo cumplieron la geocerca, la precisión GPS y la seguridad del dispositivo.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Footer de Paginación --}}
        @if($attempts->total() > 0)
            <div class="attempts-pagination-footer">
                <div class="d-flex align-items-center gap-3 flex-wrap">
                    <span style="font-size: 0.8rem; color: var(--att-text-muted);">
                        Mostrando <strong>{{ $attempts->firstItem() }}</strong> a <strong>{{ $attempts->lastItem() }}</strong> de <strong>{{ $attempts->total() }}</strong> intentos
                    </span>
                    <div class="d-flex align-items-center gap-1.5">
                        <label for="attemptsPerPageSelect" class="small mb-0" style="font-size: 0.775rem; color: var(--att-text-subtle);">Mostrar:</label>
                        <select id="attemptsPerPageSelect" class="attempts-per-page-select" onchange="changeAttemptsPerPage(this.value)">
                            <option value="10" @selected($attempts->perPage() == 10)>10</option>
                            <option value="25" @selected($attempts->perPage() == 25)>25</option>
                            <option value="50" @selected($attempts->perPage() == 50)>50</option>
                            <option value="100" @selected($attempts->perPage() == 100)>100</option>
                        </select>
                    </div>
                </div>

                <div class="d-flex align-items-center gap-3">
                    @if($attempts->hasPages())
                        {{ $attempts->links() }}
                    @else
                        <nav aria-label="Navegación de páginas">
                            <ul class="pagination pagination-sm mb-0">
                                <li class="page-item disabled" aria-disabled="true">
                                    <span class="page-link">&lsaquo;</span>
                                </li>
                                <li class="page-item active" aria-current="page">
                                    <span class="page-link">1</span>
                                </li>
                                <li class="page-item disabled" aria-disabled="true">
                                    <span class="page-link">&rsaquo;</span>
                                </li>
                            </ul>
                        </nav>
                    @endif
                    <span style="font-size: 0.785rem; font-weight: 600; color: var(--att-text-subtle);">
                        Pág. {{ $attempts->currentPage() }} de {{ $attempts->lastPage() }}
                    </span>
                </div>
            </div>
        @endif
    </section>

</div>

{{-- Modal de Auditoría Forense Completa de Intento --}}
<div class="modal fade" id="attemptAuditModal" tabindex="-1" aria-labelledby="attemptAuditModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content attempts-modal-content">
            <div class="modal-header" style="border-bottom: 1px solid var(--att-border); padding: 1.15rem 1.35rem;">
                <div class="d-flex align-items-center gap-3">
                    <span class="d-inline-flex align-items-center justify-content-center bg-danger-subtle text-danger" style="width: 38px; height: 38px; border-radius: 8px;">
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

            <div class="modal-body" style="padding: 1.35rem;">
                {{-- Sección 1: Colaborador e Identidad --}}
                <div class="attempts-audit-section">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <span class="attempts-audit-label mb-0">
                            <i class="bi bi-person-badge me-1"></i>Colaborador e Identidad
                        </span>
                        <span id="auditPunchTypeBadge"></span>
                    </div>
                    <div class="d-flex align-items-center gap-3">
                        <div id="auditAvatarContainer"></div>
                        <div class="min-w-0 flex-grow-1">
                            <div class="fw-bold fs-6 text-body" id="auditFullNameText"></div>
                            <div class="text-body-secondary small mt-1 d-flex align-items-center gap-2 flex-wrap">
                                <span>Código: <strong id="auditEmployeeCodeText"></strong></span>
                                <span>·</span>
                                <span>DNI: <strong id="auditDocumentNumberText"></strong></span>
                                <span>·</span>
                                <span>Cargo: <strong id="auditPositionText"></strong></span>
                            </div>
                            <div class="text-body-secondary small mt-1">
                                <i class="bi bi-building text-primary me-1"></i>Sucursal objetivo: <strong id="auditBranchText"></strong>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Sección 2: Veredicto de Seguridad y Bloqueo --}}
                <div class="attempts-audit-section">
                    <span class="attempts-audit-label d-block mb-3">
                        <i class="bi bi-shield-exclamation me-1"></i>Veredicto del Sistema de Seguridad
                    </span>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="attempts-audit-label">Causa del rechazo</div>
                            <div id="auditReasonBadgeContainer" class="mb-1"></div>
                            <div class="small text-body-secondary mt-2" id="auditMessageText" style="line-height: 1.45;"></div>
                        </div>
                        <div class="col-md-6">
                            <div class="attempts-audit-label">Origen de la detección</div>
                            <div class="attempts-audit-value" id="auditSourceText"></div>
                            <div class="attempts-audit-label mt-3">Marcación sin conexión (Offline)</div>
                            <div class="attempts-audit-value" id="auditOfflineText"></div>
                        </div>
                    </div>
                </div>

                {{-- Sección 3: Telemetría Geoespacial y GPS --}}
                <div class="attempts-audit-section">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <span class="attempts-audit-label mb-0">
                            <i class="bi bi-geo-alt me-1"></i>Telemetría Satelital & Coordenadas
                        </span>
                        <div id="auditMockBadgeContainer"></div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-6 col-md-4">
                            <div class="attempts-audit-label">Distancia calculada</div>
                            <div class="attempts-audit-value" id="auditDistanceText"></div>
                        </div>
                        <div class="col-6 col-md-4">
                            <div class="attempts-audit-label">Radio permitido</div>
                            <div class="attempts-audit-value" id="auditAllowedRadiusText"></div>
                        </div>
                        <div class="col-12 col-md-4">
                            <div class="attempts-audit-label">Precisión GPS</div>
                            <div class="attempts-audit-value" id="auditAccuracyText"></div>
                        </div>
                    </div>

                    <div class="p-3 rounded-2" style="background: var(--att-bg); border: 1px solid var(--att-border);">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                            <div>
                                <div class="attempts-audit-label mb-1">Coordenadas exactas (Lat, Lng)</div>
                                <div class="font-monospace fw-bold text-body" style="font-size: 0.85rem;" id="auditCoordsText"></div>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <button type="button" class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center" id="copyCoordsBtn" onclick="copyAuditCoords()" style="border-radius: 6px;">
                                    <i class="bi bi-clipboard me-1"></i>
                                    <span id="copyCoordsBtnText">Copiar</span>
                                </button>
                                <a href="#" target="_blank" rel="noopener" class="btn btn-sm btn-primary d-inline-flex align-items-center" id="auditMapLinkBtn" style="border-radius: 6px;">
                                    <i class="bi bi-box-arrow-up-right me-1"></i>
                                    <span>Abrir en Google Maps</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Sección 4: Dispositivo, Hardware & Red --}}
                <div class="attempts-audit-section mb-0">
                    <span class="attempts-audit-label d-block mb-3">
                        <i class="bi bi-phone me-1"></i>Huella Digital del Dispositivo & Red
                    </span>
                    <div class="row g-3">
                        <div class="col-6 col-md-3">
                            <div class="attempts-audit-label">Sistema Operativo</div>
                            <div class="attempts-audit-value" id="auditDeviceOsText"></div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="attempts-audit-label">Versión de App</div>
                            <div class="attempts-audit-value" id="auditAppVersionText"></div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="attempts-audit-label">Dirección IP</div>
                            <div class="attempts-audit-value font-monospace" id="auditIpText"></div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="attempts-audit-label">Hora del Celular</div>
                            <div class="attempts-audit-value" id="auditDeviceTimeText"></div>
                        </div>
                        <div class="col-12">
                            <div class="attempts-audit-label">Banderas de Seguridad de Hardware (Root / Emulador / Debugger)</div>
                            <div id="auditFlagsContainer" class="d-flex flex-wrap gap-1 mt-1"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer" style="border-top: 1px solid var(--att-border); padding: 0.85rem 1.35rem;">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius: 7px; font-weight: 600; font-size: 0.825rem;">Cerrar</button>
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

{{-- =========================================================================
   MOTOR DEL CALENDARIO INTERACTIVO (SCREENSHOT 2)
   ========================================================================= --}}
(function() {
    let initialStart = @json($startDate);
    let initialEnd = @json($endDate);
    let calViewDate = new Date(initialStart ? initialStart + 'T00:00:00' : Date.now());
    let selectedCalStart = initialStart;
    let selectedCalEnd = initialEnd;
    let selectingEndStep = false;

    const monthNamesEs = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

    function formatToDMY(iso) {
        if (!iso) return '';
        const parts = iso.split('-');
        if (parts.length !== 3) return iso;
        return `${parts[2]}/${parts[1]}/${parts[0]}`;
    }

    function renderAttemptsCalendar() {
        const year = calViewDate.getFullYear();
        const month = calViewDate.getMonth();

        const monthTitle = document.getElementById('attemptsCalCurrentMonthTitle');
        if (monthTitle) {
            monthTitle.textContent = `${monthNamesEs[month]} De ${year}`;
        }

        const daysGrid = document.getElementById('attemptsCalDaysGrid');
        if (!daysGrid) return;
        daysGrid.innerHTML = '';

        const firstDayOfMonth = new Date(year, month, 1);
        let startingDayOfWeek = firstDayOfMonth.getDay();
        startingDayOfWeek = startingDayOfWeek === 0 ? 7 : startingDayOfWeek;

        const daysInCurrentMonth = new Date(year, month + 1, 0).getDate();
        const daysInPrevMonth = new Date(year, month, 0).getDate();

        {{-- Días anteriores --}}
        for (let i = startingDayOfWeek - 1; i > 0; i--) {
            const dayNum = daysInPrevMonth - i + 1;
            const cell = document.createElement('div');
            cell.className = 'calendar-day-cell is-outside-month';
            cell.textContent = dayNum;
            daysGrid.appendChild(cell);
        }

        {{-- Días del mes actual --}}
        for (let d = 1; d <= daysInCurrentMonth; d++) {
            const dStr = String(d).padStart(2, '0');
            const mStr = String(month + 1).padStart(2, '0');
            const iso = `${year}-${mStr}-${dStr}`;

            const cell = document.createElement('div');
            cell.className = 'calendar-day-cell';
            cell.textContent = d;
            cell.dataset.date = iso;

            const isStart = iso === selectedCalStart;
            const isEnd = iso === selectedCalEnd;
            const inRange = selectedCalStart && selectedCalEnd && iso > selectedCalStart && iso < selectedCalEnd;

            if (isStart) cell.classList.add('is-selected-start');
            if (isEnd) cell.classList.add('is-selected-end');
            if (inRange) cell.classList.add('is-in-range');

            cell.addEventListener('click', () => {
                if (!selectingEndStep || !selectedCalStart) {
                    selectedCalStart = iso;
                    selectedCalEnd = iso;
                    selectingEndStep = true;
                    document.getElementById('attemptsCalFromInputWrapper')?.classList.remove('is-active');
                    document.getElementById('attemptsCalToInputWrapper')?.classList.add('is-active');
                } else {
                    if (iso < selectedCalStart) {
                        selectedCalEnd = selectedCalStart;
                        selectedCalStart = iso;
                    } else {
                        selectedCalEnd = iso;
                    }
                    selectingEndStep = false;
                    document.getElementById('attemptsCalToInputWrapper')?.classList.remove('is-active');
                    document.getElementById('attemptsCalFromInputWrapper')?.classList.add('is-active');
                }

                const inpFrom = document.getElementById('attemptsCalInputFrom');
                const inpTo = document.getElementById('attemptsCalInputTo');
                if (inpFrom) inpFrom.value = formatToDMY(selectedCalStart);
                if (inpTo) inpTo.value = formatToDMY(selectedCalEnd);

                {{-- Desmarcar presets rápidos --}}
                document.querySelectorAll('#attemptsCalendarDropdown .calendar-preset-btn').forEach(b => {
                    b.classList.remove('is-active');
                    b.querySelector('.bi-check-lg')?.classList.add('d-none');
                });

                renderAttemptsCalendar();
            });

            daysGrid.appendChild(cell);
        }

        {{-- Días siguientes --}}
        const totalCells = (startingDayOfWeek - 1) + daysInCurrentMonth;
        const remaining = (7 - (totalCells % 7)) % 7;
        for (let nextD = 1; nextD <= remaining; nextD++) {
            const cell = document.createElement('div');
            cell.className = 'calendar-day-cell is-outside-month';
            cell.textContent = nextD;
            daysGrid.appendChild(cell);
        }
    }

    {{-- Navegación del mes --}}
    document.getElementById('attemptsCalPrevMonthBtn')?.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        calViewDate.setMonth(calViewDate.getMonth() - 1);
        renderAttemptsCalendar();
    });

    document.getElementById('attemptsCalNextMonthBtn')?.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        calViewDate.setMonth(calViewDate.getMonth() + 1);
        renderAttemptsCalendar();
    });

    {{-- Cancelar --}}
    document.getElementById('attemptsCalCancelBtn')?.addEventListener('click', () => {
        const toggleBtn = document.getElementById('attemptsDateFilterDropdownBtn');
        const dd = bootstrap.Dropdown.getInstance(toggleBtn);
        if (dd) dd.hide();
    });

    {{-- Aplicar rango y submit --}}
    document.getElementById('attemptsCalApplyBtn')?.addEventListener('click', () => {
        const toggleBtn = document.getElementById('attemptsDateFilterDropdownBtn');
        const dd = bootstrap.Dropdown.getInstance(toggleBtn);
        if (dd) dd.hide();

        document.getElementById('start_date').value = selectedCalStart;
        document.getElementById('end_date').value = selectedCalEnd;
        document.getElementById('attemptsCalTriggerFrom').textContent = formatToDMY(selectedCalStart);
        document.getElementById('attemptsCalTriggerTo').textContent = formatToDMY(selectedCalEnd);
        document.getElementById('attemptsCalTriggerLabel').textContent = 'Personalizado';

        document.getElementById('attemptsFilterForm')?.submit();
    });

    {{-- Presets rápidos (Columna Izquierda Screenshot 2) --}}
    document.querySelectorAll('#attemptsCalendarDropdown .calendar-preset-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            const p = btn.dataset.preset;
            if (!p) return;

            const today = new Date();
            const pad = (n) => String(n).padStart(2, '0');
            const toIso = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;

            let sIso = toIso(today);
            let eIso = sIso;
            let label = 'Personalizado';

            if (p === 'today') {
                label = 'Hoy';
            } else if (p === 'yesterday') {
                const yd = new Date(today);
                yd.setDate(yd.getDate() - 1);
                sIso = toIso(yd);
                eIso = sIso;
                label = 'Ayer';
            } else if (p === 'week') {
                const wd = new Date(today);
                wd.setDate(wd.getDate() - 6);
                sIso = toIso(wd);
                label = 'Últimos 7 días';
            } else if (p === 'last_30_days') {
                const md = new Date(today);
                md.setDate(md.getDate() - 29);
                sIso = toIso(md);
                label = 'Últimos 30 días';
            } else if (p === 'month') {
                const fd = new Date(today.getFullYear(), today.getMonth(), 1);
                sIso = toIso(fd);
                label = 'Este mes';
            } else if (p === 'prev_month') {
                const fd = new Date(today.getFullYear(), today.getMonth() - 1, 1);
                const ld = new Date(today.getFullYear(), today.getMonth(), 0);
                sIso = toIso(fd);
                eIso = toIso(ld);
                label = 'Mes anterior';
            }

            document.getElementById('start_date').value = sIso;
            document.getElementById('end_date').value = eIso;
            document.getElementById('attemptsCalTriggerFrom').textContent = formatToDMY(sIso);
            document.getElementById('attemptsCalTriggerTo').textContent = formatToDMY(eIso);
            document.getElementById('attemptsCalTriggerLabel').textContent = label;

            const toggleBtn = document.getElementById('attemptsDateFilterDropdownBtn');
            const dd = bootstrap.Dropdown.getInstance(toggleBtn);
            if (dd) dd.hide();

            document.getElementById('attemptsFilterForm')?.submit();
        });
    });

    renderAttemptsCalendar();
})();

{{-- =========================================================================
   FUNCIONES DE SELECCIÓN PARA COMBOBOXES MODERNOS (SCREENSHOT 3)
   ========================================================================= --}}
function selectAttemptBranch(id, name, btn) {
    document.getElementById('branch_id').value = id;
    document.getElementById('branchBtnText').textContent = name;
    document.querySelectorAll('#branchMenu .tenant-menu-item').forEach(b => {
        b.classList.remove('is-selected');
        b.querySelector('.tenant-item-check')?.classList.add('d-none');
    });
    btn.classList.add('is-selected');
    btn.querySelector('.tenant-item-check')?.classList.remove('d-none');

    const toggleBtn = document.getElementById('branchDropdownBtn');
    const dd = bootstrap.Dropdown.getInstance(toggleBtn);
    if (dd) dd.hide();

    document.getElementById('attemptsFilterForm')?.submit();
}

function selectAttemptCode(code, name, btn) {
    document.getElementById('code').value = code;
    document.getElementById('codeBtnText').textContent = name;
    document.querySelectorAll('#codeMenu .tenant-menu-item').forEach(b => {
        b.classList.remove('is-selected');
        b.querySelector('.tenant-item-check')?.classList.add('d-none');
    });
    btn.classList.add('is-selected');
    btn.querySelector('.tenant-item-check')?.classList.remove('d-none');

    const toggleBtn = document.getElementById('codeDropdownBtn');
    const dd = bootstrap.Dropdown.getInstance(toggleBtn);
    if (dd) dd.hide();

    document.getElementById('attemptsFilterForm')?.submit();
}

function selectAttemptType(type, name, btn) {
    document.getElementById('type').value = type;
    document.getElementById('typeBtnText').textContent = name;
    document.querySelectorAll('#typeMenu .tenant-menu-item').forEach(b => {
        b.classList.remove('is-selected');
        b.querySelector('.tenant-item-check')?.classList.add('d-none');
    });
    btn.classList.add('is-selected');
    btn.querySelector('.tenant-item-check')?.classList.remove('d-none');

    const toggleBtn = document.getElementById('typeDropdownBtn');
    const dd = bootstrap.Dropdown.getInstance(toggleBtn);
    if (dd) dd.hide();

    document.getElementById('attemptsFilterForm')?.submit();
}

{{-- =========================================================================
   AUDITORÍA FORENSE COMPLETA (MODAL)
   ========================================================================= --}}
function openAttemptAudit(data) {
    if (!data) return;

    document.getElementById('auditIdText').textContent = data.id || '';
    document.getElementById('auditTimestampText').textContent = data.date_formatted || '';
    document.getElementById('auditPunchTypeBadge').innerHTML = `<span class="attempts-pill bg-primary-subtle text-primary border border-primary-subtle">${Panel.esc(data.punch_type)}</span>`;

    const avatarContainer = document.getElementById('auditAvatarContainer');
    if (data.avatar_url) {
        avatarContainer.innerHTML = `<img src="${Panel.esc(data.avatar_url)}" alt="${Panel.esc(data.full_name)}" class="attempts-avatar-img" style="width: 46px; height: 46px; border-radius: 8px;">`;
    } else {
        const initials = (data.full_name || 'U').split(' ').map(n => n.charAt(0)).slice(0, 2).join('').toUpperCase();
        avatarContainer.innerHTML = `<span class="attempts-avatar-circle" style="width: 46px; height: 46px; border-radius: 8px; font-size: 0.95rem;">${Panel.esc(initials)}</span>`;
    }

    document.getElementById('auditFullNameText').textContent = data.full_name || 'N/A';
    document.getElementById('auditEmployeeCodeText').textContent = data.employee_code || '—';
    document.getElementById('auditDocumentNumberText').textContent = data.document_number || '—';
    document.getElementById('auditPositionText').textContent = data.position || 'Colaborador';
    document.getElementById('auditBranchText').textContent = data.branch_name || 'Sin sucursal asignada';

    const toneClass = data.mock_location ? 'danger' : 'warning';
    document.getElementById('auditReasonBadgeContainer').innerHTML = `<span class="attempts-pill bg-${toneClass}-subtle text-${toneClass}-emphasis border border-${toneClass}-subtle fw-bold">${Panel.esc(data.error_label)}</span>`;
    document.getElementById('auditMessageText').textContent = data.message || 'Sin mensaje de error disponible.';
    document.getElementById('auditSourceText').textContent = data.source || 'Servidor';
    document.getElementById('auditOfflineText').textContent = data.offline ? `Sí (Registrada: ${data.offline_recorded_at})` : 'No (Transmisión en tiempo real)';

    const mockBadgeContainer = document.getElementById('auditMockBadgeContainer');
    if (data.mock_location) {
        mockBadgeContainer.innerHTML = `<span class="attempts-pill bg-danger-subtle text-danger border border-danger-subtle fw-bold"><i class="bi bi-phone-vibrate me-1"></i>¡Alerta! Fake GPS detectado</span>`;
    } else {
        mockBadgeContainer.innerHTML = `<span class="attempts-pill bg-success-subtle text-success border border-success-subtle"><i class="bi bi-check-circle me-1"></i>GPS Auténtico</span>`;
    }

    const distanceEl = document.getElementById('auditDistanceText');
    distanceEl.textContent = data.distance_meters !== null ? `${data.distance_meters} metros` : 'No aplica';
    distanceEl.classList.toggle('text-danger', data.distance_meters !== null && data.allowed_radius !== null && data.distance_meters > data.allowed_radius);
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

    document.getElementById('auditDeviceOsText').textContent = data.device_os || '—';
    document.getElementById('auditAppVersionText').textContent = data.app_version ? `v${data.app_version}` : '—';
    document.getElementById('auditIpText').textContent = data.ip || '—';
    document.getElementById('auditDeviceTimeText').textContent = data.device_time || '—';

    const flagsContainer = document.getElementById('auditFlagsContainer');
    if (data.device_flags && data.device_flags.length > 0) {
        flagsContainer.innerHTML = data.device_flags.map(f => `<span class="attempts-pill bg-danger-subtle text-danger border border-danger-subtle"><i class="bi bi-shield-slash me-1"></i>${Panel.esc(f)}</span>`).join(' ');
    } else {
        flagsContainer.innerHTML = `<span class="attempts-pill bg-success-subtle text-success border border-success-subtle"><i class="bi bi-shield-check me-1"></i>Dispositivo íntegro (Sin anomalías)</span>`;
    }

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
