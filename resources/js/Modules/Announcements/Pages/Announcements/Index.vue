<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import Pagination from '@/Modules/Core/Components/Pagination.vue';
import TenantBadge from '@/Modules/Core/Components/TenantBadge.vue';

const props = defineProps({ announcements: Object, categories: Object, statuses: Object, totals: Object, filters: Object });

const search = ref(props.filters.search ?? '');
const STATUS_BADGE = {
    sent: 'bg-success-subtle text-success-emphasis border-success-subtle',
    scheduled: 'bg-primary-subtle text-primary-emphasis border-primary-subtle',
    draft: 'bg-warning-subtle text-warning-emphasis border-warning-subtle',
};
const kpis = [
    { key: 'sent_month', label: 'Enviados este mes', icon: 'send-check', tone: 'success' },
    { key: 'scheduled', label: 'Programados', icon: 'clock', tone: 'primary' },
    { key: 'draft', label: 'Borradores', icon: 'pencil-square', tone: 'warning' },
];
const readRate = (a) => (a.recipients ? Math.round((a.reads / a.recipients) * 100) : 0);

function apply(changes) {
    const params = { ...props.filters, search: search.value, ...changes };
    Object.keys(params).forEach((k) => (params[k] === null || params[k] === '' || params[k] === undefined) && delete params[k]);
    router.get('/announcements', params, { preserveState: true, preserveScroll: true });
}
function destroy(a) {
    const msg = a.status === 'sent' ? `¿Eliminar "${a.title}"? Dejará de verse en la app.` : `¿Eliminar "${a.title}"?`;
    if (confirm(msg)) router.delete(`/announcements/${a.id}`, { preserveScroll: true });
}
</script>

<template>
    <AppLayout title="Comunicados">
        <div class="page-header">
            <div>
                <h1>Comunicados</h1>
                <p>Avisos, reuniones y actualizaciones para toda la empresa, una sucursal, un área, un cargo o personas puntuales. Llegan por notificación y quedan en la app.</p>
            </div>
            <Link href="/announcements/create" class="btn btn-primary"><i class="bi bi-megaphone me-1"></i>Nuevo comunicado</Link>
        </div>

        <div class="row g-3 mb-4">
            <div v-for="k in kpis" :key="k.key" class="col-12 col-sm-4">
                <div class="card border-0 h-100 kpi-card">
                    <div class="card-body d-flex align-items-center gap-3">
                        <span class="kpi-icon" :class="`bg-${k.tone}-subtle text-${k.tone}-emphasis`"><i class="bi" :class="`bi-${k.icon}`"></i></span>
                        <div>
                            <div class="kpi-label">{{ k.label }}</div>
                            <div class="fs-4 fw-bold">{{ totals[k.key] }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 mb-3">
            <div class="card-body d-flex flex-wrap gap-2 align-items-center">
                <div class="btn-group flex-wrap" role="group" aria-label="Filtrar por estado">
                    <button type="button" class="btn btn-sm" :class="!filters.status ? 'btn-primary' : 'btn-outline-secondary'" @click="apply({ status: null, page: null })">Todos</button>
                    <button v-for="(label, key) in statuses" :key="key" type="button" class="btn btn-sm" :class="filters.status === key ? 'btn-primary' : 'btn-outline-secondary'" @click="apply({ status: key, page: null })">{{ label }}</button>
                </div>
                <form class="ms-auto d-flex gap-2" role="search" @submit.prevent="apply({ page: null })">
                    <input v-model="search" type="search" class="form-control form-control-sm" placeholder="Buscar por título" aria-label="Buscar comunicados">
                    <button class="btn btn-sm btn-outline-secondary" type="submit" aria-label="Buscar"><i class="bi bi-search"></i></button>
                </form>
            </div>
        </div>

        <div class="card border-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">Comunicado</th>
                            <th scope="col">Público</th>
                            <th scope="col">Estado</th>
                            <th scope="col" class="text-end">Lectura</th>
                            <th scope="col"><span class="visually-hidden">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="a in announcements.data" :key="a.id">
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <i class="bi" :class="[`bi-${categories[a.category]?.[1] ?? 'megaphone'}`, a.category === 'urgent' ? 'text-danger' : 'text-primary']"></i>
                                    <Link :href="a.status === 'sent' ? `/announcements/${a.id}` : `/announcements/${a.id}/edit`" class="fw-semibold text-decoration-none">{{ a.title }}</Link>
                                    <span v-if="a.is_important" class="badge text-bg-warning">Importante</span>
                                </div>
                                <div class="small text-body-secondary">
                                    {{ categories[a.category]?.[0] }}<span v-if="a.event_at"> · <i class="bi bi-calendar-event"></i> {{ a.event_at }}</span>
                                </div>
                                <TenantBadge :tenant-id="a.tenant_id" />
                            </td>
                            <td class="small">{{ a.audience.join(', ') || '—' }}</td>
                            <td>
                                <span class="badge rounded-pill border" :class="STATUS_BADGE[a.status]">{{ statuses[a.status] }}</span>
                                <div class="small text-body-secondary">{{ a.sent_at ?? a.scheduled_at ?? '' }}</div>
                            </td>
                            <td class="text-end" style="min-width: 140px">
                                <template v-if="a.status === 'sent'">
                                    <div class="small fw-semibold">{{ a.reads }} / {{ a.recipients }} ({{ readRate(a) }}%)</div>
                                    <div class="progress" style="height: 6px" role="progressbar" :aria-valuenow="readRate(a)" aria-valuemin="0" aria-valuemax="100" :aria-label="`Leído por el ${readRate(a)}%`">
                                        <div class="progress-bar bg-success" :style="{ width: `${readRate(a)}%` }"></div>
                                    </div>
                                </template>
                                <span v-else class="small text-body-secondary">—</span>
                            </td>
                            <td class="text-end text-nowrap">
                                <Link v-if="a.status === 'sent'" :href="`/announcements/${a.id}`" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i> Ver</Link>
                                <Link v-else :href="`/announcements/${a.id}/edit`" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i> Editar</Link>
                                <button type="button" class="btn btn-sm btn-outline-danger ms-1" :aria-label="`Eliminar ${a.title}`" @click="destroy(a)"><i class="bi bi-trash"></i></button>
                            </td>
                        </tr>
                        <tr v-if="!announcements.data.length">
                            <td colspan="5"><div class="empty-state"><i class="bi bi-megaphone"></i>Aún no hay comunicados. Envía el primero: una reunión, un cambio de horario, una actualización de la app…</div></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="mt-3"><Pagination :paginator="announcements" /></div>
    </AppLayout>
</template>
