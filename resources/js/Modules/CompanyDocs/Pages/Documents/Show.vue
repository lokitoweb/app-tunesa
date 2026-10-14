<script setup>
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import Pagination from '@/Modules/Core/Components/Pagination.vue';

const props = defineProps({ document: Object, stats: Object, statuses: Object, people: Object, filters: Object });

const search = ref(props.filters.search ?? '');
const pct = (n) => (props.stats.audience ? Math.round((n * 100) / props.stats.audience) : 0);
const tabs = computed(() => [
    { key: null, label: 'Todos' },
    { key: 'pending', label: props.document.requires_acknowledgement ? 'Sin confirmar' : 'Sin leer' },
    { key: 'read', label: 'Leyeron' },
    ...(props.document.requires_acknowledgement ? [{ key: 'acknowledged', label: 'Conformes' }] : []),
]);

function apply(changes) {
    const params = { ...props.filters, search: search.value, ...changes };
    Object.keys(params).forEach((k) => (params[k] === null || params[k] === '' || params[k] === undefined) && delete params[k]);
    router.get(`/company-documents/${props.document.id}`, params, { preserveState: true, preserveScroll: true });
}
function setStatus(status) {
    router.post(`/company-documents/${props.document.id}/status`, { status }, { preserveScroll: true });
}
function destroy() {
    if (confirm(`¿Eliminar "${props.document.title}"? Se pierde también el registro de lectura.`)) router.delete(`/company-documents/${props.document.id}`);
}
</script>

<template>
    <AppLayout :title="document.title">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb small mb-2">
                <li class="breadcrumb-item"><Link href="/company-documents">Documentos de la empresa</Link></li>
                <li class="breadcrumb-item active" aria-current="page">{{ document.title }}</li>
            </ol>
        </nav>
        <div class="page-header">
            <div>
                <h1>{{ document.title }}</h1>
                <p>
                    {{ document.folder ?? 'Sin carpeta' }}<span v-if="document.version"> · {{ document.version }}</span> · {{ statuses[document.status] }}
                    <span v-if="document.published_at"> · publicado el {{ document.published_at }}</span>
                </p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a :href="`/company-documents/${document.id}/file`" target="_blank" rel="noopener" class="btn btn-outline-secondary"><i class="bi bi-file-earmark-pdf me-1"></i>Ver PDF</a>
                <a :href="`/company-documents/${document.id}/export`" class="btn btn-outline-secondary"><i class="bi bi-download me-1"></i>Exportar lectura</a>
                <Link :href="`/company-documents/${document.id}/edit`" class="btn btn-outline-primary"><i class="bi bi-pencil me-1"></i>Editar</Link>
                <button v-if="document.status !== 'published'" type="button" class="btn btn-success" @click="setStatus('published')"><i class="bi bi-send me-1"></i>Publicar</button>
                <button v-else type="button" class="btn btn-outline-secondary" @click="setStatus('archived')"><i class="bi bi-archive me-1"></i>Archivar</button>
                <button type="button" class="btn btn-outline-danger" aria-label="Eliminar documento" @click="destroy"><i class="bi bi-trash"></i></button>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-12 col-md-4">
                <div class="card border-0 h-100 kpi-card"><div class="card-body">
                    <div class="kpi-label">Público</div>
                    <div class="fs-4 fw-bold">{{ stats.audience }}</div>
                    <div class="small text-body-secondary">{{ document.audience.join(' · ') || 'Sin público: no se ve en la app' }}</div>
                </div></div>
            </div>
            <div class="col-6 col-md-4">
                <div class="card border-0 h-100 kpi-card"><div class="card-body">
                    <div class="kpi-label">Leyeron</div>
                    <div class="fs-4 fw-bold">{{ stats.read }} <span class="fs-6 text-body-secondary">({{ pct(stats.read) }}%)</span></div>
                    <div class="progress mt-2" style="height: 6px" role="progressbar" :aria-valuenow="pct(stats.read)" aria-valuemin="0" aria-valuemax="100" aria-label="Avance de lectura"><div class="progress-bar" :style="{ width: `${pct(stats.read)}%` }"></div></div>
                </div></div>
            </div>
            <div v-if="document.requires_acknowledgement" class="col-6 col-md-4">
                <div class="card border-0 h-100 kpi-card"><div class="card-body">
                    <div class="kpi-label">Leído y conforme<span v-if="document.acknowledge_by"> · hasta {{ document.acknowledge_by }}</span></div>
                    <div class="fs-4 fw-bold">{{ stats.acknowledged }} <span class="fs-6 text-body-secondary">({{ pct(stats.acknowledged) }}%)</span></div>
                    <div class="progress mt-2" style="height: 6px" role="progressbar" :aria-valuenow="pct(stats.acknowledged)" aria-valuemin="0" aria-valuemax="100" aria-label="Avance de confirmación"><div class="progress-bar bg-success" :style="{ width: `${pct(stats.acknowledged)}%` }"></div></div>
                </div></div>
            </div>
        </div>

        <p v-if="document.description" class="mb-4">{{ document.description }}</p>

        <div class="card border-0">
            <div class="card-body d-flex flex-wrap gap-2 align-items-center">
                <div class="btn-group flex-wrap" role="group" aria-label="Filtrar personas">
                    <button v-for="t in tabs" :key="t.key ?? 'all'" type="button" class="btn btn-sm" :class="filters.filter === t.key || (!filters.filter && !t.key) ? 'btn-primary' : 'btn-outline-secondary'" @click="apply({ filter: t.key, page: null })">{{ t.label }}</button>
                </div>
                <form class="ms-auto d-flex gap-2" role="search" @submit.prevent="apply({ page: null })">
                    <input v-model="search" type="search" class="form-control form-control-sm" placeholder="Buscar persona" aria-label="Buscar persona">
                    <button class="btn btn-sm btn-outline-secondary" type="submit" aria-label="Buscar"><i class="bi bi-search"></i></button>
                </form>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Colaborador</th><th>Cargo</th><th>Leído</th><th v-if="document.requires_acknowledgement">Leído y conforme</th></tr></thead>
                    <tbody>
                        <tr v-for="p in people.data" :key="p.id">
                            <td><div class="fw-semibold">{{ p.name }}</div><div class="small text-body-secondary">{{ p.code }}</div></td>
                            <td class="small">{{ p.position ?? '—' }}</td>
                            <td class="small"><span v-if="p.read_at"><i class="bi bi-eye me-1"></i>{{ p.read_at }}</span><span v-else class="text-body-secondary">Sin leer</span></td>
                            <td v-if="document.requires_acknowledgement" class="small">
                                <span v-if="p.acknowledged_at" class="text-success"><i class="bi bi-check2-circle me-1"></i>{{ p.acknowledged_at }}</span>
                                <span v-else class="text-warning-emphasis">Pendiente</span>
                            </td>
                        </tr>
                        <tr v-if="!people.data.length"><td colspan="4"><div class="empty-state"><i class="bi bi-people"></i>Nadie con este filtro.</div></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="mt-3"><Pagination :paginator="people" /></div>
    </AppLayout>
</template>
