<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import Pagination from '@/Modules/Core/Components/Pagination.vue';
import TenantBadge from '@/Modules/Core/Components/TenantBadge.vue';

const props = defineProps({ documents: Object, folders: Array, statuses: Object, filters: Object });

const search = ref(props.filters.search ?? '');
const STATUS_BADGE = {
    published: 'bg-success-subtle text-success-emphasis border-success-subtle',
    draft: 'bg-warning-subtle text-warning-emphasis border-warning-subtle',
    archived: 'bg-secondary-subtle text-secondary-emphasis border-secondary-subtle',
};

function apply(changes) {
    const params = { ...props.filters, search: search.value, ...changes };
    Object.keys(params).forEach((k) => (params[k] === null || params[k] === '' || params[k] === undefined) && delete params[k]);
    router.get('/company-documents', params, { preserveState: true, preserveScroll: true });
}
function setStatus(d, status) {
    const ask = { published: `¿Publicar "${d.title}"? Se avisará por la app a su público.`, archived: `¿Archivar "${d.title}"? Dejará de verse en la app.` };
    if (!ask[status] || confirm(ask[status])) router.post(`/company-documents/${d.id}/status`, { status }, { preserveScroll: true });
}
</script>

<template>
    <AppLayout title="Documentos de la empresa">
        <div class="page-header">
            <div>
                <h1>Documentos de la empresa</h1>
                <p>Políticas, reglamentos, manuales y planes que el personal lee en la app. Puedes pedir que confirmen "Leído y conforme".</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <Link href="/company-documents/folders" class="btn btn-outline-secondary"><i class="bi bi-folder me-1"></i>Carpetas</Link>
                <Link :href="filters.folder ? `/company-documents/create?folder=${filters.folder}` : '/company-documents/create'" class="btn btn-primary"><i class="bi bi-upload me-1"></i>Subir documento</Link>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-3">
                <div class="card border-0">
                    <div class="list-group list-group-flush" role="navigation" aria-label="Carpetas">
                        <button type="button" class="list-group-item list-group-item-action d-flex align-items-center gap-2" :class="{ active: !filters.folder }" @click="apply({ folder: null, page: null })">
                            <i class="bi bi-collection"></i><span class="flex-grow-1 text-start">Todas</span>
                        </button>
                        <button v-for="f in folders" :key="f.id" type="button" class="list-group-item list-group-item-action d-flex align-items-center gap-2" :class="{ active: filters.folder === f.id }" @click="apply({ folder: f.id, page: null })">
                            <i class="bi" :class="`bi-${f.icon}`" :style="filters.folder === f.id ? null : { color: f.color }"></i>
                            <span class="flex-grow-1 text-start">{{ f.name }}</span>
                            <span class="badge rounded-pill text-bg-light border">{{ f.documents_count }}</span>
                        </button>
                    </div>
                </div>
            </div>
            <div class="col-lg-9">
                <div class="card border-0 mb-3">
                    <div class="card-body d-flex flex-wrap gap-2 align-items-center">
                        <div class="btn-group flex-wrap" role="group" aria-label="Filtrar por estado">
                            <button type="button" class="btn btn-sm" :class="!filters.status ? 'btn-primary' : 'btn-outline-secondary'" @click="apply({ status: null, page: null })">Todos</button>
                            <button v-for="(label, key) in statuses" :key="key" type="button" class="btn btn-sm" :class="filters.status === key ? 'btn-primary' : 'btn-outline-secondary'" @click="apply({ status: key, page: null })">{{ label }}</button>
                        </div>
                        <form class="ms-auto d-flex gap-2" role="search" @submit.prevent="apply({ page: null })">
                            <input v-model="search" type="search" class="form-control form-control-sm" placeholder="Buscar documento" aria-label="Buscar documento">
                            <button class="btn btn-sm btn-outline-secondary" type="submit" aria-label="Buscar"><i class="bi bi-search"></i></button>
                        </form>
                    </div>
                </div>
                <div class="card border-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr><th>Documento</th><th>Carpeta</th><th>Lectura</th><th>Estado</th><th class="text-end">Acciones</th></tr>
                            </thead>
                            <tbody>
                                <tr v-for="d in documents.data" :key="d.id">
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <i class="bi bi-file-earmark-pdf fs-4 text-danger"></i>
                                            <div>
                                                <Link :href="`/company-documents/${d.id}`" class="fw-semibold text-decoration-none">{{ d.title }}</Link>
                                                <div class="small text-body-secondary">{{ d.size }}<span v-if="d.version"> · {{ d.version }}</span> · {{ d.updated_at }}</div>
                                                <TenantBadge :tenant-id="d.tenant_id" />
                                            </div>
                                        </div>
                                    </td>
                                    <td class="small">{{ d.folder ?? '—' }}</td>
                                    <td class="small text-nowrap">
                                        <div><i class="bi bi-eye me-1"></i>{{ d.read_count }} leído(s)</div>
                                        <div v-if="d.requires_acknowledgement" class="text-success"><i class="bi bi-check2-circle me-1"></i>{{ d.ack_count }} conforme(s)</div>
                                    </td>
                                    <td><span class="badge rounded-pill border" :class="STATUS_BADGE[d.status]">{{ statuses[d.status] }}</span></td>
                                    <td class="text-end text-nowrap">
                                        <button v-if="d.status !== 'published'" type="button" class="btn btn-sm btn-outline-success me-1" @click="setStatus(d, 'published')"><i class="bi bi-send"></i> Publicar</button>
                                        <Link :href="`/company-documents/${d.id}/edit`" class="btn btn-sm btn-outline-secondary me-1"><i class="bi bi-pencil"></i> Editar</Link>
                                        <button v-if="d.status === 'published'" type="button" class="btn btn-sm btn-outline-secondary" :aria-label="`Archivar ${d.title}`" @click="setStatus(d, 'archived')"><i class="bi bi-archive"></i></button>
                                    </td>
                                </tr>
                                <tr v-if="!documents.data.length">
                                    <td colspan="5"><div class="empty-state"><i class="bi bi-folder2-open"></i>No hay documentos aquí. Sube el Reglamento Interno, la Política de Calidad, el Plan de SST…</div></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="mt-3"><Pagination :paginator="documents" /></div>
            </div>
        </div>
    </AppLayout>
</template>
