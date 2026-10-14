<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import Pagination from '@/Modules/Core/Components/Pagination.vue';
import TenantBadge from '@/Modules/Core/Components/TenantBadge.vue';

const props = defineProps({ benefits: Object, categories: Array, statuses: Object, totals: Object, filters: Object });

const search = ref(props.filters.search ?? '');
const STATUS_BADGE = {
    published: 'bg-success-subtle text-success-emphasis border-success-subtle',
    draft: 'bg-warning-subtle text-warning-emphasis border-warning-subtle',
    archived: 'bg-secondary-subtle text-secondary-emphasis border-secondary-subtle',
};
const kpis = [
    { key: 'published', label: 'Vigentes en la app', icon: 'gift', tone: 'success' },
    { key: 'draft', label: 'Borradores', icon: 'pencil-square', tone: 'warning' },
    { key: 'expired', label: 'Vencidos', icon: 'calendar-x', tone: 'danger' },
];

function apply(changes) {
    const params = { ...props.filters, search: search.value, ...changes };
    Object.keys(params).forEach((k) => (params[k] === null || params[k] === '' || params[k] === undefined) && delete params[k]);
    router.get('/benefits', params, { preserveState: true, preserveScroll: true });
}
function setStatus(b, status) {
    const ask = { published: `¿Publicar "${b.title}"? Se avisará por la app a su público.`, archived: `¿Archivar "${b.title}"? Dejará de verse en la app.` };
    if (!ask[status] || confirm(ask[status])) router.post(`/benefits/${b.id}/status`, { status }, { preserveScroll: true });
}
function destroy(b) {
    if (confirm(`¿Eliminar "${b.title}"? No se puede deshacer.`)) router.delete(`/benefits/${b.id}`, { preserveScroll: true });
}
</script>

<template>
    <AppLayout title="Beneficios">
        <div class="page-header">
            <div>
                <h1>Beneficios</h1>
                <p>Descuentos, convenios y promociones que el personal ve en la app, según su sucursal, área o cargo.</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <Link href="/benefits/categories" class="btn btn-outline-secondary"><i class="bi bi-tags me-1"></i>Categorías</Link>
                <Link href="/benefits/create" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Nuevo beneficio</Link>
            </div>
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
                    <button type="button" class="btn btn-sm" :class="filters.status === 'expired' ? 'btn-primary' : 'btn-outline-secondary'" @click="apply({ status: 'expired', page: null })">Vencidos</button>
                </div>
                <select class="form-select form-select-sm w-auto" aria-label="Categoría" :value="filters.category ?? ''" @change="apply({ category: $event.target.value, page: null })">
                    <option value="">Todas las categorías</option>
                    <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
                <form class="ms-auto d-flex gap-2" role="search" @submit.prevent="apply({ page: null })">
                    <input v-model="search" type="search" class="form-control form-control-sm" placeholder="Buscar por título o aliado" aria-label="Buscar beneficios">
                    <button class="btn btn-sm btn-outline-secondary" type="submit" aria-label="Buscar"><i class="bi bi-search"></i></button>
                </form>
            </div>
        </div>

        <div class="row g-3">
            <div v-for="b in benefits.data" :key="b.id" class="col-12 col-md-6 col-xl-4">
                <div class="card border-0 h-100 overflow-hidden">
                    <div class="position-relative" style="height: 140px" :style="{ background: b.category?.color ? `${b.category.color}1f` : 'var(--bs-tertiary-bg)' }">
                        <img v-if="b.image_url" :src="b.image_url" :alt="b.title" class="w-100 h-100" style="object-fit: cover">
                        <div v-else class="w-100 h-100 d-flex align-items-center justify-content-center fs-1" :style="{ color: b.category?.color ?? 'var(--bs-secondary-color)' }">
                            <i class="bi" :class="`bi-${b.category?.icon ?? 'gift'}`"></i>
                        </div>
                        <span v-if="b.highlight" class="badge text-bg-dark position-absolute top-0 start-0 m-2 fs-6">{{ b.highlight }}</span>
                        <span v-if="b.is_featured" class="badge text-bg-warning position-absolute top-0 end-0 m-2"><i class="bi bi-star-fill me-1"></i>Destacado</span>
                    </div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                            <Link :href="`/benefits/${b.id}/edit`" class="fw-semibold text-decoration-none">{{ b.title }}</Link>
                            <span class="badge rounded-pill border" :class="STATUS_BADGE[b.status]">{{ statuses[b.status] }}</span>
                        </div>
                        <div class="small text-body-secondary">
                            <span v-if="b.partner">{{ b.partner }} · </span>
                            <span v-if="b.category"><i class="bi me-1" :class="`bi-${b.category.icon}`"></i>{{ b.category.name }}</span>
                        </div>
                        <div class="small mt-1" :class="b.expired ? 'text-danger' : 'text-body-secondary'">
                            <i class="bi bi-calendar-event me-1"></i>{{ b.valid_until ? (b.expired ? `Venció el ${b.valid_until}` : `Vigente hasta el ${b.valid_until}`) : 'Sin fecha de vencimiento' }}
                        </div>
                        <TenantBadge :tenant-id="b.tenant_id" />
                    </div>
                    <div class="card-footer bg-transparent border-0 d-flex flex-wrap gap-1 justify-content-end">
                        <button v-if="b.status !== 'published'" type="button" class="btn btn-sm btn-outline-success" @click="setStatus(b, 'published')"><i class="bi bi-send"></i> Publicar</button>
                        <button v-else type="button" class="btn btn-sm btn-outline-secondary" @click="setStatus(b, 'archived')"><i class="bi bi-archive"></i> Archivar</button>
                        <Link :href="`/benefits/${b.id}/edit`" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i> Editar</Link>
                        <button type="button" class="btn btn-sm btn-outline-danger" :aria-label="`Eliminar ${b.title}`" @click="destroy(b)"><i class="bi bi-trash"></i></button>
                    </div>
                </div>
            </div>
            <div v-if="!benefits.data.length" class="col-12">
                <div class="card border-0"><div class="empty-state"><i class="bi bi-gift"></i>Aún no hay beneficios con estos filtros. Crea el primero: descuentos en gimnasios, convenios con clínicas, día libre por cumpleaños…</div></div>
            </div>
        </div>
        <div class="mt-3"><Pagination :paginator="benefits" /></div>
    </AppLayout>
</template>
