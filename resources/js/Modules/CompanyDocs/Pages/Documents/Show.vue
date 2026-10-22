<script setup>
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import { useConfirm } from '@/Modules/Core/Composables/useConfirm';

const props = defineProps({
    document: {
        type: Object,
        required: true,
    },
    stats: {
        type: Object,
        default: () => ({ audience: 0, read: 0, acknowledged: 0 }),
    },
    statuses: {
        type: Object,
        default: () => ({}),
    },
    people: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        default: () => ({ filter: null, search: '' }),
    },
});

const { ask } = useConfirm();

// Datos brutos de personas
const rawPeople = computed(() => props.people?.data || []);

// Filtros 100% internos reactivos (sin mutar URL)
const selectedFilter = ref(props.filters.filter || 'all');
const searchQuery = ref(props.filters.search || '');

// Paginación interna
const currentPage = ref(1);
const perPage = ref(15);

const pct = (n) => (props.stats.audience ? Math.round((n * 100) / props.stats.audience) : 0);

const tabs = computed(() => [
    { key: 'all', label: 'Todos', count: rawPeople.value.length },
    {
        key: 'pending',
        label: props.document.requires_acknowledgement ? 'Sin confirmar' : 'Sin leer',
        count: rawPeople.value.filter((p) => props.document.requires_acknowledgement ? !p.acknowledged_at : !p.read_at).length,
    },
    {
        key: 'read',
        label: 'Leyeron',
        count: rawPeople.value.filter((p) => !!p.read_at).length,
    },
    ...(props.document.requires_acknowledgement ? [{
        key: 'acknowledged',
        label: 'Conformes',
        count: rawPeople.value.filter((p) => !!p.acknowledged_at).length,
    }] : []),
]);

const filteredPeople = computed(() => {
    return rawPeople.value.filter((p) => {
        if (selectedFilter.value === 'pending') {
            if (props.document.requires_acknowledgement ? !!p.acknowledged_at : !!p.read_at) return false;
        } else if (selectedFilter.value === 'read') {
            if (!p.read_at) return false;
        } else if (selectedFilter.value === 'acknowledged') {
            if (!p.acknowledged_at) return false;
        }

        if (searchQuery.value.trim()) {
            const q = searchQuery.value.toLowerCase().trim();
            const matchesName = p.name?.toLowerCase().includes(q);
            const matchesCode = p.code?.toLowerCase().includes(q);
            const matchesPosition = p.position?.toLowerCase().includes(q);
            if (!matchesName && !matchesCode && !matchesPosition) return false;
        }

        return true;
    });
});

const totalPages = computed(() => Math.max(1, Math.ceil(filteredPeople.value.length / perPage.value)));

const paginatedPeople = computed(() => {
    const start = (currentPage.value - 1) * perPage.value;
    return filteredPeople.value.slice(start, start + perPage.value);
});

function setFilter(key) {
    selectedFilter.value = key;
    currentPage.value = 1;
}

function handleSearchInput() {
    currentPage.value = 1;
}

function goToPage(page) {
    if (page >= 1 && page <= totalPages.value) {
        currentPage.value = page;
    }
}

async function setStatus(status) {
    const isPublishing = status === 'published';
    const confirmed = await ask({
        title: isPublishing ? '¿Publicar documento?' : '¿Archivar documento?',
        message: isPublishing
            ? `¿Deseas publicar "${props.document.title}"? Será visible en la app y se notificará al público asignado.`
            : `¿Deseas archivar "${props.document.title}"? Dejará de verse en la app.`,
        confirmText: isPublishing ? 'Sí, publicar' : 'Sí, archivar',
        variant: isPublishing ? 'primary' : 'warning',
        icon: isPublishing ? 'bi bi-send-check-fill' : 'bi bi-archive-fill',
    });

    if (confirmed) {
        router.post(`/company-documents/${props.document.id}/status`, { status }, { preserveScroll: true });
    }
}

async function destroy() {
    const confirmed = await ask({
        title: '¿Eliminar documento?',
        message: `¿Estás seguro de eliminar permanentemente "${props.document.title}"? Se perderá también todo el registro histórico de lecturas y conformidades.`,
        confirmText: 'Sí, eliminar',
        variant: 'danger',
        icon: 'bi bi-trash3-fill',
    });

    if (confirmed) {
        router.delete(`/company-documents/${props.document.id}`);
    }
}
</script>

<template>
    <AppLayout :title="document.title">
        <div class="company-docs-page-wrapper">
            <!-- Cabecera Ejecutiva -->
            <div class="company-docs-header-card p-4 mb-4 shadow-sm">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3">
                    <div class="d-flex align-items-center gap-3">
                        <Link href="/company-documents" class="btn-executive-back" title="Volver a documentos">
                            <i class="bi bi-arrow-left"></i>
                        </Link>
                        <div class="company-docs-header-icon shadow-sm">
                            <i class="bi bi-file-earmark-pdf-fill"></i>
                        </div>
                        <div>
                            <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                                <h1 class="h4 fw-bold mb-0 text-body">{{ document.title }}</h1>
                                <span class="badge rounded-pill bg-primary-subtle text-primary border border-primary-subtle">
                                    {{ document.folder ?? 'General' }}
                                </span>
                                <span v-if="document.version" class="badge rounded-pill bg-secondary-subtle text-secondary border">
                                    v{{ document.version }}
                                </span>
                            </div>
                            <p class="text-body-secondary small mb-0">
                                {{ statuses[document.status] }}
                                <span v-if="document.published_at"> • Publicado el {{ document.published_at }}</span>
                                <span> • {{ document.size }}</span>
                            </p>
                        </div>
                    </div>

                    <div class="d-flex flex-wrap gap-2 w-100 w-md-auto justify-content-md-end">
                        <a :href="`/company-documents/${document.id}/file`" target="_blank" rel="noopener" class="btn btn-outline-secondary px-3 py-2 fw-semibold d-inline-flex align-items-center">
                            <i class="bi bi-file-earmark-pdf me-2 text-danger"></i>Ver PDF
                        </a>
                        <a :href="`/company-documents/${document.id}/export`" class="btn btn-outline-secondary px-3 py-2 fw-semibold d-inline-flex align-items-center">
                            <i class="bi bi-download me-2 text-primary"></i>Exportar CSV
                        </a>
                        <Link :href="`/company-documents/${document.id}/edit`" class="btn btn-outline-primary px-3 py-2 fw-semibold d-inline-flex align-items-center">
                            <i class="bi bi-pencil me-2"></i>Editar
                        </Link>
                        <button v-if="document.status !== 'published'" type="button" class="btn btn-success px-3 py-2 fw-semibold d-inline-flex align-items-center shadow-sm" @click="setStatus('published')">
                            <i class="bi bi-send-fill me-2"></i>Publicar
                        </button>
                        <button v-else type="button" class="btn btn-outline-secondary px-3 py-2 fw-semibold d-inline-flex align-items-center" @click="setStatus('archived')">
                            <i class="bi bi-archive me-2"></i>Archivar
                        </button>
                        <button type="button" class="btn btn-outline-danger px-3 py-2 fw-semibold d-inline-flex align-items-center" title="Eliminar documento" @click="destroy">
                            <i class="bi bi-trash3-fill"></i>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Métricas de Lectura / Avance -->
            <div class="row g-3 mb-4">
                <div class="col-12 col-md-4">
                    <div class="company-docs-kpi-card p-3 shadow-sm h-100">
                        <div class="d-flex align-items-center gap-3">
                            <div class="company-docs-kpi-pill bg-primary-subtle text-primary">
                                <i class="bi bi-people-fill"></i>
                            </div>
                            <div>
                                <div class="text-body-secondary small fw-medium text-uppercase" style="letter-spacing: 0.05em; font-size: 0.72rem;">
                                    Público Asignado
                                </div>
                                <div class="fs-4 fw-bold text-body lh-1 mt-1">{{ stats.audience }}</div>
                                <div class="small text-body-secondary text-truncate mt-1" style="max-width: 250px;">
                                    {{ document.audience?.join(' · ') || 'Todo el personal' }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-md-4">
                    <div class="company-docs-kpi-card p-3 shadow-sm h-100">
                        <div class="d-flex align-items-center gap-3">
                            <div class="company-docs-kpi-pill bg-info-subtle text-info">
                                <i class="bi bi-eye-fill"></i>
                            </div>
                            <div class="flex-grow-1">
                                <div class="d-flex justify-content-between align-items-baseline">
                                    <div class="text-body-secondary small fw-medium text-uppercase" style="letter-spacing: 0.05em; font-size: 0.72rem;">
                                        Avance de Lectura
                                    </div>
                                    <span class="fw-bold text-info">{{ pct(stats.read) }}%</span>
                                </div>
                                <div class="fs-4 fw-bold text-body lh-1 mt-1">{{ stats.read }} leídos</div>
                                <div class="progress mt-2" style="height: 6px;">
                                    <div class="progress-bar bg-info" :style="{ width: `${pct(stats.read)}%` }"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="document.requires_acknowledgement" class="col-12 col-md-4">
                    <div class="company-docs-kpi-card p-3 shadow-sm h-100">
                        <div class="d-flex align-items-center gap-3">
                            <div class="company-docs-kpi-pill bg-success-subtle text-success">
                                <i class="bi bi-patch-check-fill"></i>
                            </div>
                            <div class="flex-grow-1">
                                <div class="d-flex justify-content-between align-items-baseline">
                                    <div class="text-body-secondary small fw-medium text-uppercase" style="letter-spacing: 0.05em; font-size: 0.72rem;">
                                        Leído y Conforme
                                    </div>
                                    <span class="fw-bold text-success">{{ pct(stats.acknowledged) }}%</span>
                                </div>
                                <div class="fs-4 fw-bold text-body lh-1 mt-1">{{ stats.acknowledged }} confirmados</div>
                                <div class="progress mt-2" style="height: 6px;">
                                    <div class="progress-bar bg-success" :style="{ width: `${pct(stats.acknowledged)}%` }"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tabla de Lecturas con Filtros Internos -->
            <div class="company-docs-table-card shadow-sm mb-4">
                <div class="p-3 border-bottom d-flex flex-column flex-md-row align-items-stretch align-items-md-center justify-content-between gap-3">
                    <!-- Segmented Controls Internos -->
                    <div class="training-segmented-bar" role="tablist">
                        <button
                            v-for="t in tabs"
                            :key="t.key"
                            type="button"
                            class="training-segmented-item"
                            :class="{ 'is-active': selectedFilter === t.key }"
                            @click="setFilter(t.key)"
                        >
                            <span>{{ t.label }}</span>
                            <span class="training-segmented-pill">{{ t.count }}</span>
                        </button>
                    </div>

                    <!-- Buscador predictivo -->
                    <div class="modern-input-wrapper" style="min-width: 250px;">
                        <span class="modern-input-icon"><i class="bi bi-search"></i></span>
                        <input
                            v-model="searchQuery"
                            type="text"
                            class="modern-input-control"
                            placeholder="Buscar persona o cargo..."
                            @input="handleSearchInput"
                        >
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-surface-elevated text-body-secondary small text-uppercase">
                            <tr>
                                <th class="ps-3 py-3">Colaborador</th>
                                <th class="py-3">Cargo</th>
                                <th class="py-3">Lectura</th>
                                <th v-if="document.requires_acknowledgement" class="pe-3 py-3">Conformidad</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="p in paginatedPeople" :key="p.id">
                                <td class="ps-3">
                                    <div class="fw-bold text-body">{{ p.name }}</div>
                                    <div class="small text-body-secondary font-monospace">{{ p.code }}</div>
                                </td>
                                <td class="small">{{ p.position ?? '—' }}</td>
                                <td class="small">
                                    <span v-if="p.read_at" class="badge bg-info-subtle text-info border border-info-subtle px-2 py-1">
                                        <i class="bi bi-eye-fill me-1"></i>{{ p.read_at }}
                                    </span>
                                    <span v-else class="text-body-secondary">Sin leer</span>
                                </td>
                                <td v-if="document.requires_acknowledgement" class="pe-3 small">
                                    <span v-if="p.acknowledged_at" class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">
                                        <i class="bi bi-check2-circle me-1"></i>{{ p.acknowledged_at }}
                                    </span>
                                    <span v-else class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-2 py-1">
                                        <i class="bi bi-clock me-1"></i>Pendiente
                                    </span>
                                </td>
                            </tr>
                            <tr v-if="filteredPeople.length === 0">
                                <td :colspan="document.requires_acknowledgement ? 4 : 3" class="p-5 text-center">
                                    <i class="bi bi-people fs-1 text-body-secondary d-block mb-2 opacity-50"></i>
                                    <div class="fw-bold text-body">Sin registros</div>
                                    <span class="text-body-secondary small">Ningún colaborador coincide con el filtro seleccionado.</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Paginación Interna -->
                <div v-if="filteredPeople.length > 0" class="d-flex flex-column flex-sm-row justify-content-between align-items-center gap-3 p-3 border-top">
                    <div class="text-body-secondary small">
                        Mostrando
                        <span class="fw-bold text-body">{{ ((currentPage - 1) * perPage) + 1 }}</span>
                        -
                        <span class="fw-bold text-body">{{ Math.min(currentPage * perPage, filteredPeople.length) }}</span>
                        de
                        <span class="fw-bold text-body">{{ filteredPeople.length }}</span> colaboradores
                    </div>

                    <div v-if="totalPages > 1" class="d-flex align-items-center gap-1">
                        <button
                            type="button"
                            class="btn btn-sm btn-outline-secondary px-2 py-1"
                            style="border-radius: 6px;"
                            :disabled="currentPage === 1"
                            @click="goToPage(currentPage - 1)"
                        >
                            <i class="bi bi-chevron-left me-1"></i>Anterior
                        </button>

                        <button
                            v-for="page in totalPages"
                            :key="page"
                            type="button"
                            class="btn btn-sm"
                            :class="currentPage === page ? 'btn-primary fw-bold' : 'btn-outline-secondary'"
                            style="width: 32px; height: 32px; border-radius: 6px; padding: 0;"
                            @click="goToPage(page)"
                        >
                            {{ page }}
                        </button>

                        <button
                            type="button"
                            class="btn btn-sm btn-outline-secondary px-2 py-1"
                            style="border-radius: 6px;"
                            :disabled="currentPage === totalPages"
                            @click="goToPage(currentPage + 1)"
                        >
                            Siguiente<i class="bi bi-chevron-right ms-1"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
