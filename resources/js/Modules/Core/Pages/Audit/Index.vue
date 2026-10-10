<script setup>
import { computed, reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import AuditValue from '@/Modules/Core/Components/AuditValue.vue';
import Pagination from '@/Modules/Core/Components/Pagination.vue';
import TenantBadge from '@/Modules/Core/Components/TenantBadge.vue';

const props = defineProps({ logs: Object, actions: Array, users: Array, filters: Object });

const filters = reactive({ ...props.filters });
const filtered = computed(() => Object.values(props.filters).some((v) => v !== '' && v !== null));
const emptyText = computed(() => `No hay registros de auditoría${filtered.value ? ' con esos filtros' : ''}.`);

function applyFilters() {
    // Solo los filtros con valor, para no ensuciar la URL
    router.get('/audit', Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== '' && v !== null)), { preserveState: true });
}

// Registro cuyo cuadro de cambios muestra el modal
const selected = ref(null);
const badgeClass = (tone) => `bg-${tone}-subtle text-${tone}-emphasis border border-${tone}-subtle`;
</script>

<template>
    <AppLayout title="Bitácora de auditoría">
        <div class="page-header">
            <div>
                <h1>Bitácora de auditoría</h1>
                <p>Acciones sensibles: documentos abiertos, cuentas bancarias reveladas, planillas exportadas y cambios de configuración.</p>
            </div>
        </div>

        <div class="card border-0 mb-4">
            <div class="card-body">
                <form class="row g-2 align-items-end" @submit.prevent="applyFilters">
                    <div class="col-6 col-md-2">
                        <label for="from" class="form-label small">Desde</label>
                        <input id="from" v-model="filters.from" type="date" class="form-control">
                    </div>
                    <div class="col-6 col-md-2">
                        <label for="to" class="form-label small">Hasta</label>
                        <input id="to" v-model="filters.to" type="date" class="form-control">
                    </div>
                    <div class="col-12 col-md-3">
                        <label for="action" class="form-label small">Acción</label>
                        <select id="action" v-model="filters.action" class="form-select">
                            <option value="">Todas</option>
                            <option v-for="a in actions" :key="a.value" :value="a.value">{{ a.label }}</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-3">
                        <label for="user_id" class="form-label small">Usuario</label>
                        <select id="user_id" v-model="filters.user_id" class="form-select">
                            <option value="">Todos</option>
                            <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-2 d-grid">
                        <button type="submit" class="btn btn-outline-primary"><i class="bi bi-funnel me-1"></i>Filtrar</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Tabla en escritorio; en móvil cada fila se apila como tarjeta (CSS) -->
        <div class="card border-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 audit-table">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Usuario</th>
                            <th>Acción</th>
                            <th>Registro</th>
                            <th>Detalle</th>
                            <th>Origen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="log in logs.data" :key="log.id">
                            <td class="small text-nowrap">{{ log.date }}</td>
                            <td>
                                <div class="fw-semibold small">{{ log.actor_name }}</div>
                                <div v-if="log.actor_role" class="small text-body-secondary">{{ log.actor_role }}</div>
                                <TenantBadge :tenant-id="log.tenant_id" />
                            </td>
                            <td>
                                <span class="badge rounded-pill text-wrap text-start" :class="badgeClass(log.badge.tone)">
                                    <i class="bi me-1" :class="`bi-${log.badge.icon}`" aria-hidden="true"></i>{{ log.badge.label }}
                                </span>
                            </td>
                            <td class="small text-body-secondary">{{ log.subject_label }}</td>
                            <td class="small audit-detail">
                                <template v-if="log.structured">
                                    <div>{{ log.summary }}</div>
                                    <button v-if="log.changes.length" type="button" class="btn btn-sm btn-outline-primary mt-1" data-bs-toggle="modal" data-bs-target="#auditChanges" @click="selected = log">
                                        <i class="bi bi-list-columns-reverse me-1" aria-hidden="true"></i>Ver cambios
                                    </button>
                                </template>
                                <dl v-else-if="log.pairs.length" class="kv small mb-0">
                                    <template v-for="pair in log.pairs" :key="pair.label">
                                        <dt>{{ pair.label }}</dt>
                                        <dd><AuditValue :value="pair" /></dd>
                                    </template>
                                </dl>
                                <span v-else class="text-body-secondary">—</span>
                            </td>
                            <td class="small text-body-secondary">
                                <div>{{ log.device }}</div>
                                <div class="font-monospace" :title="log.full_ip ? 'Dirección IP' : 'Dirección IP parcial'">{{ log.ip }}</div>
                            </td>
                        </tr>
                        <tr v-if="!logs.data.length">
                            <td colspan="6"><div class="empty-state"><i class="bi bi-journal-text"></i>{{ emptyText }}</div></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-if="logs.last_page > 1" class="card-footer bg-transparent"><Pagination :paginator="logs" /></div>
        </div>

        <!-- Cuadro de cambios (Campo | Valor previo | Valor nuevo) del registro elegido -->
        <div id="auditChanges" class="modal fade" tabindex="-1" aria-labelledby="auditChangesTitle" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down">
                <div v-if="selected" class="modal-content">
                    <div class="modal-header">
                        <div>
                            <h2 id="auditChangesTitle" class="modal-title fs-5">{{ selected.action_label }}</h2>
                            <div class="small text-body-secondary">{{ selected.actor_name }} · {{ selected.date }}</div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                    </div>
                    <div class="modal-body">
                        <div class="table-responsive">
                            <table class="table table-sm audit-diff mb-0">
                                <thead>
                                    <tr><th scope="col">Campo</th><th scope="col">Valor previo</th><th scope="col">Valor nuevo</th></tr>
                                </thead>
                                <tbody>
                                    <tr v-for="change in selected.changes" :key="change.label">
                                        <th scope="row" class="fw-medium">{{ change.label }}</th>
                                        <td class="changed-old"><AuditValue :value="change.old" /></td>
                                        <td class="changed-new"><AuditValue :value="change.new" /></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <dl v-if="selected.pairs.length" class="kv small mt-3 mb-0">
                            <template v-for="pair in selected.pairs" :key="pair.label">
                                <dt>{{ pair.label }}</dt>
                                <dd><AuditValue :value="pair" /></dd>
                            </template>
                        </dl>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.audit-detail { min-width: 260px; }
.audit-diff td { vertical-align: top; word-break: break-word; }
.audit-diff td.changed-old { background: var(--bs-danger-bg-subtle); }
.audit-diff td.changed-new { background: var(--bs-success-bg-subtle); font-weight: 500; }

/* Móvil: cada registro como tarjeta en vez de una tabla con scroll lateral */
@media (max-width: 767.98px) {
    .audit-table thead { display: none; }
    .audit-table tr { display: block; padding: .75rem; border-bottom: 1px solid var(--bs-border-color); }
    .audit-table td { display: block; border: 0; padding: .15rem 0; }
    .audit-detail { min-width: 0; }
}
</style>
