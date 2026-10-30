<script setup>
import { computed, reactive, ref, watch } from 'vue';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import AuditValue from '@/Modules/Core/Components/AuditValue.vue';
import TenantBadge from '@/Modules/Core/Components/TenantBadge.vue';
import ModernDatePicker from '@/Modules/Core/Components/ModernDatePicker.vue';

const props = defineProps({
    logs: Object,
    actions: Array,
    users: Array,
    filters: Object,
});

// Filtros reactivos internos (cero mutación de URL en la barra de navegación)
const searchQuery = ref('');
const filterAction = ref(props.filters?.action || '');
const filterUserId = ref(props.filters?.user_id ? String(props.filters.user_id) : '');
const filterFrom = ref(props.filters?.from || '');
const filterTo = ref(props.filters?.to || '');
const activeTone = ref('all');

const rawLogs = computed(() => props.logs?.data || []);

// Filtrado 100% interno e instantáneo en memoria
const filteredLogs = computed(() => {
    let list = rawLogs.value;

    if (searchQuery.value.trim()) {
        const q = searchQuery.value.toLowerCase().trim();
        list = list.filter((log) => {
            return (
                (log.actor_name && log.actor_name.toLowerCase().includes(q)) ||
                (log.actor_role && log.actor_role.toLowerCase().includes(q)) ||
                (log.action_label && log.action_label.toLowerCase().includes(q)) ||
                (log.subject_label && log.subject_label.toLowerCase().includes(q)) ||
                (log.summary && log.summary.toLowerCase().includes(q)) ||
                (log.ip && log.ip.includes(q)) ||
                (log.device && log.device.toLowerCase().includes(q)) ||
                (log.badge?.label && log.badge.label.toLowerCase().includes(q))
            );
        });
    }

    if (filterAction.value) {
        list = list.filter((log) => {
            return (
                log.action === filterAction.value ||
                log.action_label === filterAction.value ||
                (log.badge?.label && log.badge.label === filterAction.value)
            );
        });
    }

    if (filterUserId.value) {
        list = list.filter((log) => String(log.user_id || log.actor_id || '') === String(filterUserId.value) || (log.actor_name && props.users?.find(u => String(u.id) === String(filterUserId.value))?.name === log.actor_name));
    }

    if (filterFrom.value) {
        list = list.filter((log) => {
            const dateStr = log.created_at || log.date || '';
            // Formatos usuales: YYYY-MM-DD o DD/MM/YYYY
            if (dateStr.includes('/')) {
                const parts = dateStr.split(' ')[0].split('/');
                if (parts.length === 3) {
                    const iso = `${parts[2]}-${parts[1].padStart(2, '0')}-${parts[0].padStart(2, '0')}`;
                    return iso >= filterFrom.value;
                }
            }
            return dateStr >= filterFrom.value;
        });
    }

    if (filterTo.value) {
        list = list.filter((log) => {
            const dateStr = log.created_at || log.date || '';
            if (dateStr.includes('/')) {
                const parts = dateStr.split(' ')[0].split('/');
                if (parts.length === 3) {
                    const iso = `${parts[2]}-${parts[1].padStart(2, '0')}-${parts[0].padStart(2, '0')}`;
                    return iso <= filterTo.value;
                }
            }
            return dateStr.substring(0, 10) <= filterTo.value;
        });
    }

    if (activeTone.value !== 'all') {
        list = list.filter((log) => log.badge?.tone === activeTone.value);
    }

    return list;
});

// Paginación interna reactiva (15 por página)
const currentPage = ref(1);
const perPage = ref(15);

watch([searchQuery, filterAction, filterUserId, filterFrom, filterTo, activeTone], () => {
    currentPage.value = 1;
});

const totalCount = computed(() => filteredLogs.value.length);
const totalPages = computed(() => Math.max(1, Math.ceil(totalCount.value / perPage.value)));

const paginatedLogs = computed(() => {
    const start = (currentPage.value - 1) * perPage.value;
    return filteredLogs.value.slice(start, start + perPage.value);
});

const pageStart = computed(() => (totalCount.value === 0 ? 0 : (currentPage.value - 1) * perPage.value + 1));
const pageEnd = computed(() => Math.min(currentPage.value * perPage.value, totalCount.value));

function setPage(p) {
    if (p >= 1 && p <= totalPages.value) {
        currentPage.value = p;
    }
}

function clearFilters() {
    searchQuery.value = '';
    filterAction.value = '';
    filterUserId.value = '';
    filterFrom.value = '';
    filterTo.value = '';
    activeTone.value = 'all';
}

const hasActiveFilters = computed(() => {
    return Boolean(
        searchQuery.value ||
        filterAction.value ||
        filterUserId.value ||
        filterFrom.value ||
        filterTo.value ||
        activeTone.value !== 'all'
    );
});

// KPIs Ejecutivos
const kpiTotal = computed(() => rawLogs.value.length);
const kpiUsersCount = computed(() => new Set(rawLogs.value.map(l => l.actor_name).filter(Boolean)).size);
const kpiChangesCount = computed(() => rawLogs.value.filter(l => l.structured && l.changes?.length > 0).length);
const kpiExportCount = computed(() => rawLogs.value.filter(l => l.action?.includes('export') || l.badge?.label?.toLowerCase().includes('export')).length);

// Conteo reactivo por categoría para los chips de filtro
const countByTone = computed(() => {
    const counts = { all: rawLogs.value.length, success: 0, primary: 0, warning: 0, danger: 0 };
    for (const log of rawLogs.value) {
        const tone = log.badge?.tone;
        if (tone && counts[tone] !== undefined) {
            counts[tone]++;
        }
    }
    return counts;
});

// Registro seleccionado para el modal de cambios
const selected = ref(null);
const badgeClass = (tone) => `bg-${tone}-subtle text-${tone}-emphasis border border-${tone}-subtle`;
</script>

<template>
    <AppLayout title="Bitácora de auditoría">
        <!-- Header Ejecutivo -->
        <header class="page-head">
            <div class="page-head-text">
                <h1 class="page-title">Bitácora de auditoría</h1>
                <p class="page-subtitle">Trazabilidad completa: accesos a información confidencial, cambios de configuración y exportaciones.</p>
            </div>
            <div class="page-actions">
                <span class="status-pill tone-brand"><i class="bi bi-shield-lock"></i>Registros inmutables</span>
            </div>
        </header>

        <section class="dash-kpis" aria-label="Resumen de la bitácora">
            <article v-for="k in [[kpiTotal, 'Eventos auditados', 'brand', 'journal-text'], [kpiUsersCount, 'Usuarios activos', 'emerald', 'people'], [kpiChangesCount, 'Cambios de valores', 'amber', 'pencil-square'], [kpiExportCount, 'Exportaciones', 'blue', 'file-earmark-spreadsheet']]" :key="k[1]" class="dash-kpi-card">
                <div class="dash-kpi-top"><span class="dash-kpi-label">{{ k[1] }}</span><span class="dash-kpi-icon" :class="`tone-${k[2]}`"><i class="bi" :class="`bi-${k[3]}`"></i></span></div>
                <div class="dash-kpi-value">{{ k[0] }}</div>
            </article>
        </section>

        <!-- Barra de Filtros Interna y Reactiva (CERO mutación de URL) -->
        <section class="surface">
        <div class="audit-filters">
            <div class="row g-3 align-items-center">
                <!-- Buscador de texto instantáneo -->
                <div class="col-12 col-lg-4">
                    <div class="modern-input-wrapper">
                        <span class="modern-input-icon"><i class="bi bi-search"></i></span>
                        <input v-model="searchQuery" type="text" class="modern-input-control" placeholder="Buscar por usuario, acción, detalle, IP…">
                    </div>
                </div>

                <!-- Filtro por Acción -->
                <div class="col-6 col-md-3 col-lg-2">
                    <div class="modern-input-wrapper">
                        <span class="modern-input-icon"><i class="bi bi-activity"></i></span>
                        <select v-model="filterAction" class="modern-input-control">
                            <option value="">Todas las acciones</option>
                            <option v-for="a in actions" :key="a.value" :value="a.value">{{ a.label }}</option>
                        </select>
                    </div>
                </div>

                <!-- Filtro por Usuario -->
                <div class="col-6 col-md-3 col-lg-2">
                    <div class="modern-input-wrapper">
                        <span class="modern-input-icon"><i class="bi bi-person"></i></span>
                        <select v-model="filterUserId" class="modern-input-control">
                            <option value="">Todos los usuarios</option>
                            <option v-for="u in users" :key="u.id" :value="String(u.id)">{{ u.name }}</option>
                        </select>
                    </div>
                </div>

                <!-- Fecha Desde -->
                <div class="col-6 col-md-3 col-lg-2">
                    <ModernDatePicker
                        id="audit_filter_from"
                        v-model="filterFrom"
                        placeholder="Fecha desde"
                        icon="bi bi-calendar-event"
                        :allow-clear="true"
                        clear-label="Quitar fecha"
                        align="left"
                    />
                </div>

                <!-- Fecha Hasta -->
                <div class="col-6 col-md-3 col-lg-2">
                    <ModernDatePicker
                        id="audit_filter_to"
                        v-model="filterTo"
                        placeholder="Fecha hasta"
                        icon="bi bi-calendar-check"
                        :allow-clear="true"
                        clear-label="Quitar fecha"
                        align="right"
                    />
                </div>
            </div>

            <!-- Chips de filtro rápido y Limpiar (Esquinas sutiles 8px, sin píldoras) -->
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-3">
                <div class="seg-tabs" role="tablist" aria-label="Tipo de evento">
                    <button v-for="t in [['all', 'Todos', ''], ['success', 'Aprobados / Éxito', 'tone-emerald'], ['primary', 'Modificaciones', 'tone-brand'], ['warning', 'Accesos / Sensibles', 'tone-amber'], ['danger', 'Eliminaciones', 'tone-rose']]" :key="t[0]"
                        type="button" role="tab" class="seg-tab" :class="{ 'is-active': activeTone === t[0] }" :aria-selected="activeTone === t[0]" @click="activeTone = t[0]">
                        {{ t[1] }} <span class="count-pill" :class="activeTone === t[0] ? t[2] : ''">{{ countByTone[t[0]] }}</span>
                    </button>
                </div>
                <button v-if="hasActiveFilters" type="button" class="btn btn-sm btn-link text-decoration-none" @click="clearFilters"><i class="bi bi-x-circle me-1"></i>Limpiar filtros</button>
            </div>
        </div>

        <!-- Tabla de Registros -->
            <div class="table-responsive">
                <table class="table data-table align-middle mb-0 audit-table">
                    <thead>
                        <tr>
                            <th class="ps-3" style="width: 140px;">Fecha y hora</th>
                            <th style="width: 200px;">Usuario / Rol</th>
                            <th style="width: 220px;">Acción realizada</th>
                            <th>Entidad afectada</th>
                            <th>Detalle de la operación</th>
                            <th class="pe-3" style="width: 180px;">Dispositivo / IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="log in paginatedLogs" :key="log.id">
                            <td class="ps-3 small text-nowrap text-secondary font-monospace">
                                <i class="bi bi-clock me-1"></i>{{ log.date }}
                            </td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="topbar-avatar" style="border-radius: 8px;">
                                        {{ (log.actor_name || 'S').charAt(0).toUpperCase() }}
                                    </div>
                                    <div>
                                        <div class="cell-title">{{ log.actor_name }}</div>
                                        <div v-if="log.actor_role" class="small text-secondary" style="font-size: 0.75rem;">{{ log.actor_role }}</div>
                                        <TenantBadge v-if="log.tenant_id" :tenant-id="log.tenant_id" />
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="badge rounded-2 fw-semibold text-wrap text-start d-inline-flex align-items-center gap-1 py-1 px-2" :class="badgeClass(log.badge?.tone || 'secondary')">
                                    <i class="bi" :class="`bi-${log.badge?.icon || 'activity'}`" aria-hidden="true"></i>
                                    <span>{{ log.badge?.label || log.action_label }}</span>
                                </span>
                            </td>
                            <td class="small fw-medium text-secondary">
                                {{ log.subject_label }}
                            </td>
                            <td class="small audit-detail">
                                <template v-if="log.structured">
                                    <div class="text-secondary">{{ log.summary }}</div>
                                    <button v-if="log.changes?.length" type="button" class="btn btn-sm btn-outline-primary d-inline-flex align-items-center mt-1 py-0 px-2" data-bs-toggle="modal" data-bs-target="#auditChanges" @click="selected = log" style="font-size: 0.75rem;">
                                        <i class="bi bi-list-columns-reverse me-1" aria-hidden="true"></i> Ver cambios ({{ log.changes.length }})
                                    </button>
                                </template>
                                <dl v-else-if="log.pairs?.length" class="kv small mb-0">
                                    <template v-for="pair in log.pairs" :key="pair.label">
                                        <dt class="text-secondary">{{ pair.label }}:</dt>
                                        <dd class="fw-medium mb-0"><AuditValue :value="pair" /></dd>
                                    </template>
                                </dl>
                                <span v-else class="text-secondary">—</span>
                            </td>
                            <td class="small text-secondary pe-3">
                                <div class="d-flex align-items-center gap-1">
                                    <i class="bi bi-laptop"></i>
                                    <span>{{ log.device }}</span>
                                </div>
                                <div class="font-monospace text-secondary" style="font-size: 0.775rem;" :title="log.full_ip ? 'Dirección IP pública' : 'Dirección IP enmascarada'">
                                    <i class="bi bi-hdd-network me-1"></i>{{ log.ip }}
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!paginatedLogs.length">
                            <td colspan="6" class="text-center py-5 text-secondary">
                                <div class="empty-state">
                                    <i class="bi bi-journal-x fs-1 d-block mb-2"></i>
                                    <p class="mb-0 fw-medium">No se encontraron eventos de auditoría con los filtros seleccionados.</p>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Paginador Interno Reactivo -->
            <footer class="surface-foot">
                <div class="small text-body-secondary">
                    Mostrando <strong class="text-body">{{ pageStart }}</strong> a <strong class="text-body">{{ pageEnd }}</strong> de <strong class="text-body">{{ totalCount }}</strong> registros
                </div>

                <div v-if="totalPages > 1" class="d-flex align-items-center gap-1">
                    <button type="button" class="btn btn-sm btn-outline-secondary" :disabled="currentPage <= 1" aria-label="Página anterior" title="Página anterior" @click="setPage(currentPage - 1)">
                        <i class="bi bi-chevron-left"></i>
                    </button>
                    <template v-for="p in totalPages" :key="p">
                        <button v-if="p === 1 || p === totalPages || (p >= currentPage - 2 && p <= currentPage + 2)"
                                type="button" class="btn btn-sm"
                                :class="p === currentPage ? 'btn-primary' : 'btn-outline-secondary'"
                                @click="setPage(p)">
                            {{ p }}
                        </button>
                        <span v-else-if="p === currentPage - 3 || p === currentPage + 3" class="px-1 text-secondary">…</span>
                    </template>
                    <button type="button" class="btn btn-sm btn-outline-secondary" :disabled="currentPage >= totalPages" aria-label="Página siguiente" title="Página siguiente" @click="setPage(currentPage + 1)">
                        <i class="bi bi-chevron-right"></i>
                    </button>
                </div>
            </footer>
        </section>

        <!-- Modal de Cuadro de Cambios -->
        <div id="auditChanges" class="modal fade" tabindex="-1" aria-labelledby="auditChangesTitle" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div v-if="selected" class="modal-content border-0 shadow">
                    <div class="modal-header border-bottom">
                        <div>
                            <h2 id="auditChangesTitle" class="modal-title fs-5 fw-bold text-primary-emphasis">{{ selected.action_label }}</h2>
                            <div class="small text-secondary">{{ selected.actor_name }} · {{ selected.date }}</div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="table-responsive rounded border">
                            <table class="table table-sm audit-diff mb-0 align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th scope="col" class="ps-3 py-2">Campo</th>
                                        <th scope="col" class="py-2">Valor anterior</th>
                                        <th scope="col" class="py-2">Valor nuevo</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="change in selected.changes" :key="change.label">
                                        <th scope="row" class="fw-semibold small ps-3">{{ change.label }}</th>
                                        <td class="changed-old small p-2"><AuditValue :value="change.old" /></td>
                                        <td class="changed-new small p-2"><AuditValue :value="change.new" /></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <dl v-if="selected.pairs?.length" class="kv small mt-3 mb-0 p-3 rounded bg-body-tertiary">
                            <template v-for="pair in selected.pairs" :key="pair.label">
                                <dt class="text-secondary">{{ pair.label }}</dt>
                                <dd class="fw-medium mb-1"><AuditValue :value="pair" /></dd>
                            </template>
                        </dl>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.audit-detail { min-width: 240px; }
.audit-filters { padding: 1rem; border-bottom: 1px solid var(--border-color); }
.audit-diff td { vertical-align: top; word-break: break-word; }
.audit-diff td.changed-old { background: rgba(239, 68, 68, 0.1); color: #dc2626; }
.audit-diff td.changed-new { background: rgba(16, 185, 129, 0.12); color: #059669; font-weight: 500; }

@media (max-width: 767.98px) {
    .audit-table thead { display: none; }
    .audit-table tr { display: block; padding: .75rem; border-bottom: 1px solid var(--bs-border-color); }
    .audit-table td { display: block; border: 0; padding: .2rem 0; }
    .audit-detail { min-width: 0; }
}
</style>
