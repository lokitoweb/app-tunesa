<script setup>
import { ref, computed, onMounted } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import TenantBadge from '@/Modules/Core/Components/TenantBadge.vue';
import AudiencePicker from '@/Modules/Core/Components/AudiencePicker.vue';
import { useConfirm } from '@/Modules/Core/Composables/useConfirm';
import { useTenantPicker } from '@/Modules/Core/Composables/useTenantPicker';

const props = defineProps({
    documents: {
        type: Object,
        required: true,
    },
    folders: {
        type: Array,
        default: () => [],
    },
    folder_icons: {
        type: Object,
        default: () => ({}),
    },
    audience: {
        type: Object,
        default: () => ({}),
    },
    statuses: {
        type: Object,
        default: () => ({}),
    },
    totals: {
        type: Object,
        default: () => ({ published: 0, draft: 0, archived: 0, total: 0 }),
    },
    filters: {
        type: Object,
        default: () => ({ status: '', folder: null, search: '' }),
    },
});

const page = usePage();
const { ask } = useConfirm();
const { ensureTenant, consumeIntent } = useTenantPicker();

// Tras elegir empresa en la vista global, reabre el formulario que se pidió
onMounted(() => {
    if (consumeIntent('documento')) openCreateDocumentModal();
    if (consumeIntent('carpetas')) openFoldersModal();
});

// Datos brutos de documentos del paginador inicial
const rawList = computed(() => props.documents?.data || []);

// Filtros 100% internos reactivos (sin mutar URL ni recargar página)
const selectedStatus = ref(props.filters.status || 'all');
const searchQuery = ref(props.filters.search || '');
const selectedFolder = ref(props.filters.folder ? String(props.filters.folder) : '');

// Paginación interna reactiva
const currentPage = ref(1);
const perPage = ref(10);

const STATUS_CONFIG = {
    published: {
        label: 'Publicado',
        badgeClass: 'bg-success-subtle text-success border border-success-subtle',
        icon: 'bi-check-circle-fill',
    },
    draft: {
        label: 'Borrador',
        badgeClass: 'bg-warning-subtle text-warning-emphasis border border-warning-subtle',
        icon: 'bi-pencil-square',
    },
    archived: {
        label: 'Archivado',
        badgeClass: 'bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle',
        icon: 'bi-archive-fill',
    },
};

// Conteos reactivos para los tabs superiores
const publishedCount = computed(() => rawList.value.filter((d) => d.status === 'published').length);
const draftCount = computed(() => rawList.value.filter((d) => d.status === 'draft').length);
const archivedCount = computed(() => rawList.value.filter((d) => d.status === 'archived').length);

// Filtrado 100% interno en memoria
const filteredDocuments = computed(() => {
    return rawList.value.filter((d) => {
        // Filtro por Estado
        if (selectedStatus.value && selectedStatus.value !== 'all') {
            if (d.status !== selectedStatus.value) return false;
        }

        // Filtro por Carpeta
        if (selectedFolder.value) {
            const fId = Number(selectedFolder.value);
            if (d.folder_id && d.folder_id !== fId) return false;
            const folderObj = props.folders.find((f) => f.id === fId);
            if (folderObj && d.folder && d.folder !== folderObj.name) return false;
        }

        // Filtro por Búsqueda Predictiva
        if (searchQuery.value.trim()) {
            const q = searchQuery.value.toLowerCase().trim();
            const matchesTitle = d.title?.toLowerCase().includes(q);
            const matchesFolder = d.folder?.toLowerCase().includes(q);
            const matchesVersion = d.version?.toLowerCase().includes(q);
            if (!matchesTitle && !matchesFolder && !matchesVersion) return false;
        }

        return true;
    });
});

// Paginación interna
const totalPages = computed(() => Math.max(1, Math.ceil(filteredDocuments.value.length / perPage.value)));

const paginatedDocuments = computed(() => {
    const start = (currentPage.value - 1) * perPage.value;
    return filteredDocuments.value.slice(start, start + perPage.value);
});

function setStatusFilter(key) {
    selectedStatus.value = key;
    currentPage.value = 1;
}

function handleSearchInput() {
    currentPage.value = 1;
}

function handleFolderChange() {
    currentPage.value = 1;
}

function resetFilters() {
    selectedStatus.value = 'all';
    searchQuery.value = '';
    selectedFolder.value = '';
    currentPage.value = 1;
}

function goToPage(page) {
    if (page >= 1 && page <= totalPages.value) {
        currentPage.value = page;
    }
}

async function setStatus(d, status) {
    const isPublishing = status === 'published';
    const isArchiving = status === 'archived';

    const confirmed = await ask({
        title: isPublishing ? '¿Publicar documento?' : (isArchiving ? '¿Archivar documento?' : '¿Cambiar estado?'),
        message: isPublishing
            ? `¿Deseas publicar "${d.title}"? Estará disponible de inmediato en la app móvil y se notificará a los colaboradores asignados.`
            : `¿Deseas archivar "${d.title}"? Dejará de mostrarse en la app móvil de los colaboradores.`,
        confirmText: isPublishing ? 'Sí, publicar' : (isArchiving ? 'Sí, archivar' : 'Confirmar'),
        variant: isPublishing ? 'primary' : 'warning',
        icon: isPublishing ? 'bi bi-send-check-fill' : 'bi bi-archive-fill',
    });

    if (confirmed) {
        router.post(`/company-documents/${d.id}/status`, { status }, { preserveScroll: true });
    }
}

async function destroyDocument(d) {
    const confirmed = await ask({
        title: '¿Eliminar documento?',
        message: `¿Estás seguro de eliminar permanentemente "${d.title}"? Se perderán también todos los registros de lectura y confirmaciones.`,
        confirmText: 'Sí, eliminar',
        variant: 'danger',
        icon: 'bi bi-trash3-fill',
    });

    if (confirmed) {
        router.delete(`/company-documents/${d.id}`, { preserveScroll: true });
    }
}

// =========================================================================
// MODAL 1: SUBIR / EDITAR DOCUMENTO INSTITUCIONAL (Modal Elegante y Escalable)
// =========================================================================
const showDocModal = ref(false);
const isEditingDoc = ref(false);
const loadingDocEdit = ref(false);

const docForm = useForm({
    id: null,
    title: '',
    description: '',
    version: '',
    folder_id: null,
    file: null,
    file_name: null,
    requires_acknowledgement: false,
    acknowledge_by: '',
    status: 'draft',
    notify: true,
    targets: [{ type: 'all', id: null, value: null }],
});

async function openCreateDocumentModal() {
    if (!ensureTenant({ intent: 'documento', message: 'Elige la empresa del documento.' })) {
        return;
    }

    isEditingDoc.value = false;
    docForm.reset();
    docForm.clearErrors();
    docForm.id = null;
    docForm.file = null;
    docForm.file_name = null;
    docForm.status = 'draft';
    docForm.notify = true;
    docForm.requires_acknowledgement = false;
    docForm.acknowledge_by = '';
    docForm.folder_id = props.folders.length ? props.folders[0].id : null;
    docForm.targets = [{ type: 'all', id: null, value: null }];
    showDocModal.value = true;
}

async function openEditDocumentModal(doc) {
    isEditingDoc.value = true;
    docForm.reset();
    docForm.clearErrors();
    docForm.id = doc.id;
    docForm.title = doc.title;
    docForm.description = doc.description || '';
    docForm.version = doc.version || '';
    docForm.folder_id = doc.folder_id;
    docForm.file = null;
    docForm.file_name = doc.file_name;
    docForm.requires_acknowledgement = !!doc.requires_acknowledgement;
    docForm.acknowledge_by = doc.acknowledge_by || '';
    docForm.status = doc.status;
    docForm.notify = true;
    docForm.targets = [{ type: 'all', id: null, value: null }];
    showDocModal.value = true;

    // Cargar targets de audiencia detallados vía AJAX
    loadingDocEdit.value = true;
    try {
        const res = await axios.get(`/company-documents/${doc.id}/edit`, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (res.data?.document) {
            const d = res.data.document;
            if (d.targets && Array.isArray(d.targets)) {
                docForm.targets = d.targets;
            }
            if (d.description) docForm.description = d.description;
            if (d.acknowledge_by) docForm.acknowledge_by = d.acknowledge_by;
        }
    } catch (e) {
        console.error('Error al cargar datos de edición del documento:', e);
    } finally {
        loadingDocEdit.value = false;
    }
}

function closeDocModal() {
    showDocModal.value = false;
    docForm.reset();
    docForm.clearErrors();
}

function submitDocument(targetStatus) {
    if (targetStatus) {
        docForm.status = targetStatus;
    }

    const options = {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            closeDocModal();
        },
    };

    if (isEditingDoc.value && docForm.id) {
        docForm.transform((data) => ({ ...data, _method: 'put' })).post(`/company-documents/${docForm.id}`, options);
    } else {
        docForm.post('/company-documents', options);
    }
}

// =========================================================================
// MODAL 2: GESTIÓN DE CARPETAS DE DOCUMENTOS (Modal Elegante y Escalable)
// =========================================================================
const showFoldersModal = ref(false);

const folderForm = useForm({
    id: null,
    name: '',
    description: '',
    icon: 'folder',
    color: '#0284c7',
    position: null,
});

const DEFAULT_FOLDER_ICONS = {
    folder: 'Carpeta',
    shield: 'Seguridad',
    'shield-check': 'Cumplimiento',
    'file-earmark-text': 'Documentos',
    book: 'Manuales',
    heart: 'Bienestar',
    briefcase: 'Laboral',
    building: 'Institucional',
    people: 'Personal',
    lock: 'Confidencial',
    award: 'Certificaciones',
    megaphone: 'Comunicados',
};

async function openFoldersModal() {
    if (!ensureTenant({ intent: 'carpetas', message: 'Elige la empresa cuyas carpetas quieres administrar.' })) {
        return;
    }

    resetFolderForm();
    showFoldersModal.value = true;
}

function closeFoldersModal() {
    showFoldersModal.value = false;
    resetFolderForm();
}

function editFolder(f) {
    folderForm.id = f.id;
    folderForm.name = f.name;
    folderForm.description = f.description ?? '';
    folderForm.icon = f.icon;
    folderForm.color = f.color;
    folderForm.position = f.position;
    folderForm.clearErrors();
}

function resetFolderForm() {
    folderForm.reset();
    folderForm.id = null;
    folderForm.clearErrors();
}

function submitFolder() {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            resetFolderForm();
        },
    };

    if (folderForm.id) {
        folderForm.put(`/company-documents/folders/${folderForm.id}`, options);
    } else {
        folderForm.post('/company-documents/folders', options);
    }
}

async function destroyFolder(f) {
    const confirmed = await ask({
        title: '¿Eliminar carpeta?',
        message: `¿Estás seguro de eliminar permanentemente la carpeta "${f.name}"?`,
        confirmText: 'Sí, eliminar',
        variant: 'danger',
        icon: 'bi bi-trash3-fill',
    });

    if (confirmed) {
        router.delete(`/company-documents/folders/${f.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                if (folderForm.id === f.id) {
                    resetFolderForm();
                }
            },
        });
    }
}
</script>

<template>
    <AppLayout title="Documentos de la Empresa">
        <div class="company-docs-page-wrapper">
            <!-- =========================================================================
                 1. CABECERA EJECUTIVA DEL MÓDULO
                 ========================================================================= -->
            <div class="company-docs-header-card p-4 mb-4 shadow-sm">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3">
                    <div class="d-flex align-items-center gap-3">
                        <div class="company-docs-header-icon shadow-sm">
                            <i class="bi bi-file-earmark-text-fill"></i>
                        </div>
                        <div>
                            <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                                <h1 class="h4 fw-bold mb-0 text-body">Documentos de la Empresa</h1>
                                <span class="badge rounded-pill bg-primary-subtle text-primary border border-primary-subtle px-2 py-1 small">
                                    <i class="bi bi-shield-check" style="margin-right: 6px !important;"></i>Políticas & Normas
                                </span>
                            </div>
                            <p class="text-body-secondary small mb-0">
                                Políticas, reglamentos internos, manuales y directivas para lectura y confirmación en la app móvil.
                            </p>
                        </div>
                    </div>
                    <div class="d-flex flex-wrap gap-2 w-100 w-md-auto justify-content-md-end">
                        <button
                            type="button"
                            class="btn btn-outline-secondary px-3 py-2 fw-semibold d-inline-flex align-items-center"
                            style="height: 38px; border-radius: 8px; font-weight: 600; font-size: 0.85rem;"
                            @click="openFoldersModal"
                        >
                            <i class="bi bi-folder-fill text-warning" style="margin-right: 8px !important;"></i>
                            <span>Carpetas</span>
                        </button>
                        <button
                            type="button"
                            class="btn btn-primary px-3 py-2 fw-semibold d-inline-flex align-items-center shadow-sm"
                            style="height: 38px; border-radius: 8px; font-weight: 600; font-size: 0.85rem;"
                            @click="openCreateDocumentModal"
                        >
                            <i class="bi bi-cloud-arrow-up-fill" style="margin-right: 8px !important;"></i>
                            <span>Subir documento</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- =========================================================================
                 2. TARJETAS DE INDICADORES (KPIS EJECUTIVOS)
                 ========================================================================= -->
            <div class="row g-3 mb-4">
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="company-docs-kpi-card p-3 shadow-sm h-100">
                        <div class="d-flex align-items-center gap-3">
                            <div class="company-docs-kpi-pill bg-success-subtle text-success">
                                <i class="bi bi-file-earmark-check-fill"></i>
                            </div>
                            <div>
                                <div class="text-body-secondary small fw-medium text-uppercase" style="letter-spacing: 0.05em; font-size: 0.72rem;">
                                    Publicados en App
                                </div>
                                <div class="fs-4 fw-bold text-body lh-1 mt-1">
                                    {{ totals.published ?? publishedCount }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="company-docs-kpi-card p-3 shadow-sm h-100">
                        <div class="d-flex align-items-center gap-3">
                            <div class="company-docs-kpi-pill bg-warning-subtle text-warning-emphasis">
                                <i class="bi bi-pencil-square"></i>
                            </div>
                            <div>
                                <div class="text-body-secondary small fw-medium text-uppercase" style="letter-spacing: 0.05em; font-size: 0.72rem;">
                                    Borradores
                                </div>
                                <div class="fs-4 fw-bold text-body lh-1 mt-1">
                                    {{ totals.draft ?? draftCount }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="company-docs-kpi-card p-3 shadow-sm h-100">
                        <div class="d-flex align-items-center gap-3">
                            <div class="company-docs-kpi-pill bg-secondary-subtle text-secondary-emphasis">
                                <i class="bi bi-archive-fill"></i>
                            </div>
                            <div>
                                <div class="text-body-secondary small fw-medium text-uppercase" style="letter-spacing: 0.05em; font-size: 0.72rem;">
                                    Archivados
                                </div>
                                <div class="fs-4 fw-bold text-body lh-1 mt-1">
                                    {{ totals.archived ?? archivedCount }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="company-docs-kpi-card p-3 shadow-sm h-100">
                        <div class="d-flex align-items-center gap-3">
                            <div class="company-docs-kpi-pill bg-primary-subtle text-primary">
                                <i class="bi bi-collection-fill"></i>
                            </div>
                            <div>
                                <div class="text-body-secondary small fw-medium text-uppercase" style="letter-spacing: 0.05em; font-size: 0.72rem;">
                                    Total Documentos
                                </div>
                                <div class="fs-4 fw-bold text-body lh-1 mt-1">
                                    {{ totals.total ?? rawList.length }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- =========================================================================
                 3. BARRA DE FILTROS 100% INTERNA EN UNA SOLA LÍNEA HORIZONTAL
                 ========================================================================= -->
            <div class="company-docs-filter-card p-3 mb-4 shadow-sm">
                <div class="d-flex flex-wrap flex-xl-nowrap align-items-center justify-content-between gap-3">
                    <!-- Segmented Controls por Estado -->
                    <div class="training-segmented-bar flex-shrink-0" role="tablist">
                        <button
                            type="button"
                            class="training-segmented-item"
                            :class="{ 'is-active': selectedStatus === 'all' }"
                            @click="setStatusFilter('all')"
                        >
                            <i class="bi bi-grid-fill" style="margin-right: 6px !important;"></i>
                            <span>Todos</span>
                            <span class="training-segmented-pill">{{ rawList.length }}</span>
                        </button>
                        <button
                            type="button"
                            class="training-segmented-item"
                            :class="{ 'is-active': selectedStatus === 'published' }"
                            @click="setStatusFilter('published')"
                        >
                            <i class="bi bi-check-circle-fill text-success" style="margin-right: 6px !important;"></i>
                            <span>Publicados</span>
                            <span class="training-segmented-pill">{{ publishedCount }}</span>
                        </button>
                        <button
                            type="button"
                            class="training-segmented-item"
                            :class="{ 'is-active': selectedStatus === 'draft' }"
                            @click="setStatusFilter('draft')"
                        >
                            <i class="bi bi-pencil-square text-warning" style="margin-right: 6px !important;"></i>
                            <span>Borradores</span>
                            <span class="training-segmented-pill">{{ draftCount }}</span>
                        </button>
                        <button
                            type="button"
                            class="training-segmented-item"
                            :class="{ 'is-active': selectedStatus === 'archived' }"
                            @click="setStatusFilter('archived')"
                        >
                            <i class="bi bi-archive-fill text-secondary" style="margin-right: 6px !important;"></i>
                            <span>Archivados</span>
                            <span class="training-segmented-pill">{{ archivedCount }}</span>
                        </button>
                    </div>

                    <!-- Filtros Complementarios: Carpeta, Búsqueda y Limpiar EN UNA SOLA LÍNEA HORIZONTAL -->
                    <div class="d-flex align-items-center flex-nowrap" style="gap: 12px; min-width: 0;">
                        <!-- Selector de Carpeta -->
                        <div class="modern-input-wrapper flex-shrink-0" style="width: 210px; min-width: 175px;">
                            <i class="bi bi-folder-fill modern-input-icon text-warning"></i>
                            <select
                                v-model="selectedFolder"
                                class="modern-input-control"
                                aria-label="Filtrar por carpeta"
                                @change="handleFolderChange"
                            >
                                <option value="">Todas las carpetas</option>
                                <option v-for="f in folders" :key="f.id" :value="String(f.id)">{{ f.name }}</option>
                            </select>
                        </div>

                        <!-- Buscador Predictivo -->
                        <div class="modern-input-wrapper flex-shrink-0" style="width: 270px; min-width: 210px;">
                            <i class="bi bi-search modern-input-icon"></i>
                            <input
                                v-model="searchQuery"
                                type="search"
                                class="modern-input-control"
                                placeholder="Buscar por título o versión..."
                                aria-label="Buscar documentos"
                                @input="handleSearchInput"
                            >
                            <button
                                v-if="searchQuery"
                                type="button"
                                class="btn btn-sm text-secondary p-0 me-2 border-0"
                                style="width: 22px; height: 22px;"
                                title="Limpiar búsqueda"
                                @click="searchQuery = ''; handleSearchInput();"
                            >
                                <i class="bi bi-x-circle-fill"></i>
                            </button>
                        </div>

                        <!-- Botón Limpiar Filtros -->
                        <button
                            v-if="selectedStatus !== 'all' || searchQuery || selectedFolder"
                            type="button"
                            class="btn btn-outline-secondary px-3 py-1 fw-medium d-inline-flex align-items-center flex-shrink-0 text-nowrap"
                            style="border-radius: 8px; height: 38px;"
                            title="Restablecer filtros"
                            @click="resetFilters"
                        >
                            <i class="bi bi-arrow-counterclockwise" style="margin-right: 6px !important;"></i>
                            <span>Limpiar</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- =========================================================================
                 4. TABLA DE DOCUMENTOS
                 ========================================================================= -->
            <div class="company-docs-table-card shadow-sm mb-4">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-surface-elevated text-body-secondary small text-uppercase">
                            <tr>
                                <th class="ps-3 py-3">Documento</th>
                                <th class="py-3">Carpeta</th>
                                <th class="py-3">Lectura y Conforme</th>
                                <th class="py-3">Estado</th>
                                <th class="pe-3 py-3 text-end">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="d in paginatedDocuments" :key="d.id">
                                <td class="ps-3">
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="company-docs-pdf-icon shadow-sm flex-shrink-0">
                                            <i class="bi bi-file-earmark-pdf-fill"></i>
                                        </div>
                                        <div>
                                            <Link
                                                :href="`/company-documents/${d.id}`"
                                                class="fw-bold text-body text-decoration-none company-docs-doc-title d-block mb-1"
                                                :title="d.title"
                                            >
                                                {{ d.title }}
                                            </Link>
                                            <div class="small text-body-secondary d-flex align-items-center gap-2 flex-wrap">
                                                <span class="badge bg-secondary-subtle text-secondary border px-2 py-0">
                                                    {{ d.size }}
                                                </span>
                                                <span v-if="d.version" class="text-body fw-medium">
                                                    v{{ d.version }}
                                                </span>
                                                <span>•</span>
                                                <span>Actualizado: {{ d.updated_at }}</span>
                                                <TenantBadge :tenant-id="d.tenant_id" />
                                            </div>
                                        </div>
                                    </div>
                                </td>

                                <td class="small">
                                    <span v-if="d.folder" class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1">
                                        <i class="bi bi-folder-fill text-warning" style="margin-right: 4px !important;"></i>{{ d.folder }}
                                    </span>
                                    <span v-else class="text-body-secondary">Sin carpeta</span>
                                </td>

                                <td class="small text-nowrap">
                                    <div class="d-flex flex-column gap-1">
                                        <div class="d-inline-flex align-items-center gap-1 text-body">
                                            <i class="bi bi-eye-fill text-primary" style="margin-right: 4px !important;"></i>
                                            <span class="fw-semibold">{{ d.read_count }}</span> leídos
                                        </div>
                                        <div v-if="d.requires_acknowledgement" class="d-inline-flex align-items-center gap-1 text-success">
                                            <i class="bi bi-patch-check-fill" style="margin-right: 4px !important;"></i>
                                            <span class="fw-bold">{{ d.ack_count }}</span> conformes
                                        </div>
                                    </div>
                                </td>

                                <td>
                                    <span class="badge rounded-pill" :class="STATUS_CONFIG[d.status]?.badgeClass || 'bg-secondary-subtle text-secondary'">
                                        <i class="bi" :class="STATUS_CONFIG[d.status]?.icon" style="margin-right: 4px !important;"></i>
                                        {{ STATUS_CONFIG[d.status]?.label || d.status }}
                                    </span>
                                </td>

                                <!-- Botones de Acción (Pure Icon Buttons) -->
                                <td class="pe-3 text-end text-nowrap">
                                    <div class="d-inline-flex align-items-center gap-2">
                                        <!-- Ver Reporte / Detalle -->
                                        <Link
                                            :href="`/company-documents/${d.id}`"
                                            class="btn-table-action btn-action-view"
                                            title="Ver detalle y reporte de lectura"
                                        >
                                            <i class="bi bi-eye-fill"></i>
                                        </Link>

                                        <!-- Publicar -->
                                        <button
                                            v-if="d.status !== 'published'"
                                            type="button"
                                            class="btn-table-action btn-action-publish"
                                            title="Publicar en la app móvil"
                                            @click="setStatus(d, 'published')"
                                        >
                                            <i class="bi bi-send-fill text-success"></i>
                                        </button>

                                        <!-- Archivar -->
                                        <button
                                            v-else
                                            type="button"
                                            class="btn-table-action"
                                            title="Archivar documento"
                                            @click="setStatus(d, 'archived')"
                                        >
                                            <i class="bi bi-archive-fill"></i>
                                        </button>

                                        <!-- Editar en Modal -->
                                        <button
                                            type="button"
                                            class="btn-table-action btn-action-edit"
                                            title="Editar documento institucional"
                                            @click="openEditDocumentModal(d)"
                                        >
                                            <i class="bi bi-pencil-fill"></i>
                                        </button>

                                        <!-- Eliminar -->
                                        <button
                                            type="button"
                                            class="btn-table-action btn-table-delete"
                                            title="Eliminar documento"
                                            @click="destroyDocument(d)"
                                        >
                                            <i class="bi bi-trash3-fill"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>

                            <!-- Estado Vacío -->
                            <tr v-if="filteredDocuments.length === 0">
                                <td colspan="5" class="p-5 text-center">
                                    <div class="d-inline-flex align-items-center justify-content-center p-3 rounded-circle bg-primary-subtle text-primary mb-3" style="width: 64px; height: 64px;">
                                        <i class="bi bi-folder2-open fs-2"></i>
                                    </div>
                                    <h6 class="fw-bold text-body mb-2">No se encontraron documentos</h6>
                                    <p class="text-body-secondary small mb-3 mx-auto" style="max-width: 420px;">
                                        No hay documentos que coincidan con los filtros aplicados. Puedes restablecer los filtros o subir un nuevo PDF institucional.
                                    </p>
                                    <div class="d-flex justify-content-center gap-2">
                                        <button
                                            v-if="selectedStatus !== 'all' || searchQuery || selectedFolder"
                                            type="button"
                                            class="btn btn-outline-secondary px-3 py-2 fw-medium"
                                            @click="resetFilters"
                                        >
                                            <i class="bi bi-arrow-counterclockwise" style="margin-right: 6px !important;"></i>Restablecer filtros
                                        </button>
                                        <button
                                            type="button"
                                            class="btn btn-primary px-3 py-2 fw-semibold"
                                            @click="openCreateDocumentModal"
                                        >
                                            <i class="bi bi-cloud-arrow-up-fill" style="margin-right: 6px !important;"></i>Subir documento
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- =========================================================================
                     5. PAGINACIÓN 100% INTERNA (SIN MODIFICAR URL)
                     ========================================================================= -->
                <div v-if="filteredDocuments.length > 0" class="d-flex flex-column flex-sm-row justify-content-between align-items-center gap-3 p-3 border-top">
                    <div class="text-body-secondary small">
                        Mostrando
                        <span class="fw-bold text-body">{{ ((currentPage - 1) * perPage) + 1 }}</span>
                        -
                        <span class="fw-bold text-body">{{ Math.min(currentPage * perPage, filteredDocuments.length) }}</span>
                        de
                        <span class="fw-bold text-body">{{ filteredDocuments.length }}</span> documentos
                    </div>

                    <div v-if="totalPages > 1" class="d-flex align-items-center gap-1">
                        <button
                            type="button"
                            class="btn btn-sm btn-outline-secondary px-2 py-1"
                            style="border-radius: 6px;"
                            :disabled="currentPage === 1"
                            @click="goToPage(currentPage - 1)"
                        >
                            <i class="bi bi-chevron-left" style="margin-right: 4px !important;"></i>Anterior
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
                            Siguiente<i class="bi bi-chevron-right" style="margin-left: 4px !important;"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- =========================================================================
             MODAL 1: SUBIR / EDITAR DOCUMENTO INSTITUCIONAL (Modal Elegante)
             ========================================================================= -->
        <div
            v-if="showDocModal"
            class="modal fade show d-block"
            tabindex="-1"
            role="dialog"
            aria-modal="true"
            style="background: rgba(15, 23, 42, 0.65); backdrop-filter: blur(4px); z-index: 1055;"
            @click.self="closeDocModal"
        >
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content border-0 shadow-lg" style="border-radius: 14px; overflow: hidden;">
                    <!-- Modal Header -->
                    <div class="modal-header border-bottom p-3 p-md-4" style="background: var(--bg-surface);">
                        <div class="d-flex align-items-center" style="gap: 14px !important;">
                            <div style="width: 44px; height: 44px; border-radius: 12px; background: linear-gradient(135deg, #0284c7, #0369a1); display: flex; align-items: center; justify-content: center; color: #fff; font-size: 1.35rem; box-shadow: 0 4px 12px rgba(2, 132, 199, 0.25);">
                                <i class="bi" :class="isEditingDoc ? 'bi-file-earmark-check-fill' : 'bi-cloud-arrow-up-fill'"></i>
                            </div>
                            <div>
                                <h2 class="h5 fw-bold mb-0 text-body">
                                    {{ isEditingDoc ? `Editar: ${docForm.title}` : 'Subir Documento Institucional' }}
                                </h2>
                                <span class="small text-secondary">
                                    {{ isEditingDoc ? 'Actualiza los datos, versión o alcance del documento' : 'Sube el archivo PDF, define su carpeta, alcance y requisitos de confirmación' }}
                                </span>
                            </div>
                        </div>
                        <button type="button" class="btn-close" aria-label="Cerrar" @click="closeDocModal"></button>
                    </div>

                    <!-- Modal Body -->
                    <div class="modal-body p-3 p-md-4" style="background: var(--bg-surface);">
                        <div v-if="loadingDocEdit" class="text-center py-4">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Cargando...</span>
                            </div>
                            <div class="small text-secondary mt-2">Cargando datos del documento...</div>
                        </div>

                        <form v-else @submit.prevent="submitDocument(isEditingDoc ? docForm.status : 'published')">
                            <!-- Sección 1: Información del Documento -->
                            <div class="p-3 mb-3 border rounded-3 bg-light-subtle">
                                <div class="d-flex align-items-center gap-2 mb-3 pb-2 border-bottom">
                                    <span class="badge bg-primary text-white rounded-circle p-2" style="width: 24px; height: 24px; display: inline-flex; align-items: center; justify-content: center; font-size: 0.75rem;">
                                        1
                                    </span>
                                    <span class="small fw-bold text-body text-uppercase" style="letter-spacing: 0.05em;">Información Principal</span>
                                </div>

                                <div class="row g-3">
                                    <!-- Título -->
                                    <div class="col-12 col-md-8">
                                        <label for="modal_doc_title" class="form-label small fw-semibold text-body">
                                            Título del documento <span class="text-danger">*</span>
                                        </label>
                                        <div class="modern-input-wrapper">
                                            <i class="bi bi-file-earmark-text-fill modern-input-icon"></i>
                                            <input
                                                id="modal_doc_title"
                                                v-model="docForm.title"
                                                type="text"
                                                maxlength="200"
                                                required
                                                class="modern-input-control"
                                                :class="{ 'is-invalid': docForm.errors.title }"
                                                placeholder="Ej.: Reglamento Interno de Seguridad y Salud en el Trabajo"
                                            >
                                        </div>
                                        <div v-if="docForm.errors.title" class="text-danger small mt-1">{{ docForm.errors.title }}</div>
                                    </div>

                                    <!-- Versión / Año -->
                                    <div class="col-12 col-md-4">
                                        <label for="modal_doc_version" class="form-label small fw-semibold text-body">
                                            Versión / Año
                                        </label>
                                        <div class="modern-input-wrapper">
                                            <i class="bi bi-tag-fill modern-input-icon"></i>
                                            <input
                                                id="modal_doc_version"
                                                v-model="docForm.version"
                                                type="text"
                                                maxlength="30"
                                                class="modern-input-control"
                                                placeholder="Ej.: v2.0 - 2026"
                                            >
                                        </div>
                                    </div>

                                    <!-- Carpeta Destino -->
                                    <div class="col-12 col-md-6">
                                        <label for="modal_doc_folder" class="form-label small fw-semibold text-body">
                                            Carpeta destino <span class="text-danger">*</span>
                                        </label>
                                        <div class="modern-input-wrapper">
                                            <i class="bi bi-folder-fill modern-input-icon text-warning"></i>
                                            <select
                                                id="modal_doc_folder"
                                                v-model="docForm.folder_id"
                                                class="modern-input-control"
                                                :class="{ 'is-invalid': docForm.errors.folder_id }"
                                                required
                                            >
                                                <option :value="null" disabled>Elige una carpeta</option>
                                                <option v-for="f in folders" :key="f.id" :value="f.id">{{ f.name }}</option>
                                            </select>
                                        </div>
                                        <div v-if="docForm.errors.folder_id" class="text-danger small mt-1">{{ docForm.errors.folder_id }}</div>
                                    </div>

                                    <!-- Archivo PDF -->
                                    <div class="col-12 col-md-6">
                                        <label for="modal_doc_file" class="form-label small fw-semibold text-body">
                                            {{ docForm.file_name ? 'Reemplazar archivo PDF' : 'Archivo PDF' }}
                                            <span v-if="!isEditingDoc" class="text-danger">*</span>
                                        </label>
                                        <input
                                            id="modal_doc_file"
                                            type="file"
                                            accept="application/pdf"
                                            class="form-control"
                                            :class="{ 'is-invalid': docForm.errors.file }"
                                            style="border-radius: 8px; font-size: 0.85rem;"
                                            @input="docForm.file = $event.target.files[0]"
                                        >
                                        <div v-if="docForm.errors.file" class="text-danger small mt-1">{{ docForm.errors.file }}</div>
                                        <div class="text-secondary small mt-1" style="font-size: 0.72rem;">
                                            Formato PDF (máximo 20 MB).
                                            <span v-if="docForm.file_name">
                                                Actual: <a :href="`/company-documents/${docForm.id}/file`" target="_blank" rel="noopener" class="text-primary fw-semibold">{{ docForm.file_name }}</a>
                                            </span>
                                        </div>
                                    </div>

                                    <!-- Descripción / Alcance -->
                                    <div class="col-12">
                                        <label for="modal_doc_desc" class="form-label small fw-semibold text-body">
                                            Descripción / Alcance (opcional)
                                        </label>
                                        <textarea
                                            id="modal_doc_desc"
                                            v-model="docForm.description"
                                            rows="6"
                                            maxlength="5000"
                                            class="form-control"
                                            style="border-radius: 8px; font-size: 0.875rem; min-height: 150px; padding: 12px 14px; line-height: 1.55; resize: vertical;"
                                            placeholder="Breve resumen del objetivo del documento institucional y a quién aplica..."
                                        ></textarea>
                                    </div>
                                </div>
                            </div>

                            <!-- Sección 2: Público Asignado en la App -->
                            <div class="p-3 mb-3 border rounded-3 bg-light-subtle">
                                <div class="d-flex align-items-center gap-2 mb-3 pb-2 border-bottom">
                                    <span class="badge bg-primary text-white rounded-circle p-2" style="width: 24px; height: 24px; display: inline-flex; align-items: center; justify-content: center; font-size: 0.75rem;">
                                        2
                                    </span>
                                    <span class="small fw-bold text-body text-uppercase" style="letter-spacing: 0.05em;">Público Destinatario en la App</span>
                                </div>
                                <AudiencePicker v-model="docForm.targets" :options="audience" :errors="docForm.errors" id-prefix="modal-doc" />
                            </div>

                            <!-- Sección 3: Conformidad y Auditoría -->
                            <div class="p-3 mb-3 border rounded-3 bg-light-subtle">
                                <div class="d-flex align-items-center gap-2 mb-3 pb-2 border-bottom">
                                    <span class="badge bg-primary text-white rounded-circle p-2" style="width: 24px; height: 24px; display: inline-flex; align-items: center; justify-content: center; font-size: 0.75rem;">
                                        3
                                    </span>
                                    <span class="small fw-bold text-body text-uppercase" style="letter-spacing: 0.05em;">Conformidad & Avisos Push</span>
                                </div>

                                <div class="row g-3">
                                    <div class="col-12 col-md-6">
                                        <div class="form-check form-switch p-2 border rounded d-flex align-items-center justify-content-between h-100 bg-surface">
                                            <label class="form-check-label small fw-semibold text-body mb-0 cursor-pointer" for="modal_doc_ack">
                                                <i class="bi bi-shield-check text-success" style="margin-right: 6px !important;"></i>
                                                Pedir "Leído y Conforme"
                                                <div class="text-secondary small fw-normal" style="font-size: 0.72rem;">Exige aceptación formal en la app.</div>
                                            </label>
                                            <input
                                                id="modal_doc_ack"
                                                v-model="docForm.requires_acknowledgement"
                                                class="form-check-input ms-0"
                                                type="checkbox"
                                                role="switch"
                                                style="cursor: pointer;"
                                            >
                                        </div>
                                    </div>

                                    <div class="col-12 col-md-6">
                                        <div class="form-check form-switch p-2 border rounded d-flex align-items-center justify-content-between h-100 bg-surface">
                                            <label class="form-check-label small fw-semibold text-body mb-0 cursor-pointer" for="modal_doc_notify">
                                                <i class="bi bi-bell-fill text-primary" style="margin-right: 6px !important;"></i>
                                                Avisar por push al publicar
                                                <div class="text-secondary small fw-normal" style="font-size: 0.72rem;">Notifica de inmediato a los colaboradores.</div>
                                            </label>
                                            <input
                                                id="modal_doc_notify"
                                                v-model="docForm.notify"
                                                class="form-check-input ms-0"
                                                type="checkbox"
                                                role="switch"
                                                style="cursor: pointer;"
                                            >
                                        </div>
                                    </div>

                                    <!-- Fecha Límite de Confirmación -->
                                    <div v-if="docForm.requires_acknowledgement" class="col-12">
                                        <label for="modal_doc_ack_by" class="form-label small fw-semibold text-body">
                                            Fecha límite de confirmación (sugerida)
                                        </label>
                                        <div class="modern-input-wrapper" style="max-width: 280px;">
                                            <i class="bi bi-calendar-check modern-input-icon"></i>
                                            <input
                                                id="modal_doc_ack_by"
                                                v-model="docForm.acknowledge_by"
                                                type="date"
                                                class="modern-input-control"
                                                :class="{ 'is-invalid': docForm.errors.acknowledge_by }"
                                            >
                                        </div>
                                        <div v-if="docForm.errors.acknowledge_by" class="text-danger small mt-1">{{ docForm.errors.acknowledge_by }}</div>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- Modal Footer -->
                    <div class="modal-footer border-top p-3" style="background: var(--bg-surface);">
                        <button type="button" class="btn btn-outline-secondary px-3 py-2 fw-medium" @click="closeDocModal">
                            Cancelar
                        </button>

                        <button
                            type="button"
                            class="btn btn-outline-primary px-3 py-2 fw-semibold"
                            :disabled="docForm.processing || loadingDocEdit"
                            @click="submitDocument(isEditingDoc && docForm.status === 'published' ? 'published' : 'draft')"
                        >
                            <i class="bi bi-save" style="margin-right: 6px !important;"></i>
                            {{ isEditingDoc && docForm.status === 'published' ? 'Guardar cambios' : 'Guardar borrador' }}
                        </button>

                        <button
                            v-if="!isEditingDoc || docForm.status !== 'published'"
                            type="button"
                            class="btn btn-primary px-4 py-2 fw-semibold shadow-sm"
                            :disabled="docForm.processing || loadingDocEdit"
                            @click="submitDocument('published')"
                        >
                            <i class="bi bi-cloud-arrow-up-fill" style="margin-right: 6px !important;"></i>
                            Guardar y publicar
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- =========================================================================
             MODAL 2: GESTIÓN DE CARPETAS DE DOCUMENTOS (Modal Elegante)
             ========================================================================= -->
        <div
            v-if="showFoldersModal"
            class="modal fade show d-block"
            tabindex="-1"
            role="dialog"
            aria-modal="true"
            style="background: rgba(15, 23, 42, 0.65); backdrop-filter: blur(4px); z-index: 1055;"
            @click.self="closeFoldersModal"
        >
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content border-0 shadow-lg" style="border-radius: 14px; overflow: hidden;">
                    <!-- Modal Header -->
                    <div class="modal-header border-bottom p-3 p-md-4" style="background: var(--bg-surface);">
                        <div class="d-flex align-items-center" style="gap: 14px !important;">
                            <div style="width: 44px; height: 44px; border-radius: 12px; background: linear-gradient(135deg, #f59e0b, #d97706); display: flex; align-items: center; justify-content: center; color: #fff; font-size: 1.35rem; box-shadow: 0 4px 12px rgba(245, 158, 11, 0.25);">
                                <i class="bi bi-folder-fill"></i>
                            </div>
                            <div>
                                <h2 class="h5 fw-bold mb-0 text-body">Administrar Carpetas</h2>
                                <span class="small text-secondary">Organiza los documentos institucionales en categorías lógicas (Políticas, Reglamentos, SST)</span>
                            </div>
                        </div>
                        <button type="button" class="btn-close" aria-label="Cerrar" @click="closeFoldersModal"></button>
                    </div>

                    <!-- Modal Body -->
                    <div class="modal-body p-3 p-md-4" style="background: var(--bg-surface);">
                        <div class="row g-4">
                            <!-- Lista de Carpetas Registradas -->
                            <div class="col-12 col-md-6">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <span class="small fw-bold text-uppercase text-secondary" style="font-size: 0.75rem; letter-spacing: 0.05em;">Carpetas Registradas</span>
                                    <span class="badge bg-primary-subtle text-primary rounded-pill">{{ folders.length }}</span>
                                </div>
                                <div class="list-group border rounded-3 overflow-hidden shadow-xs" style="max-height: 400px; overflow-y: auto;">
                                    <div
                                        v-for="f in folders"
                                        :key="f.id"
                                        class="list-group-item d-flex align-items-center justify-content-between p-2.5 border-bottom"
                                        :class="{ 'bg-primary-subtle': folderForm.id === f.id }"
                                    >
                                        <div class="d-flex align-items-center gap-2 min-w-0">
                                            <div
                                                class="d-flex align-items-center justify-content-center flex-shrink-0"
                                                :style="{ background: `${f.color}22`, color: f.color, width: '36px', height: '36px', borderRadius: '8px' }"
                                            >
                                                <i class="bi fs-5" :class="`bi-${f.icon}`"></i>
                                            </div>
                                            <div class="min-w-0">
                                                <div class="fw-semibold text-body small text-truncate">{{ f.name }}</div>
                                                <div class="text-secondary" style="font-size: 0.725rem;">
                                                    {{ f.documents_count ?? 0 }} documento(s) · Pos. {{ f.position }}
                                                </div>
                                            </div>
                                        </div>
                                        <div class="d-flex align-items-center gap-1 flex-shrink-0">
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-outline-secondary p-0 d-inline-flex align-items-center justify-content-center"
                                                style="width: 28px; height: 28px; border-radius: 6px;"
                                                title="Editar carpeta"
                                                @click="editFolder(f)"
                                            >
                                                <i class="bi bi-pencil-fill" style="font-size: 0.75rem;"></i>
                                            </button>
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-outline-danger p-0 d-inline-flex align-items-center justify-content-center"
                                                style="width: 28px; height: 28px; border-radius: 6px;"
                                                :disabled="(f.documents_count ?? 0) > 0"
                                                :title="(f.documents_count ?? 0) > 0 ? 'No se puede eliminar porque contiene documentos' : 'Eliminar carpeta'"
                                                @click="destroyFolder(f)"
                                            >
                                                <i class="bi bi-trash3-fill" style="font-size: 0.75rem;"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <div v-if="!folders.length" class="p-4 text-center text-secondary small">
                                        <i class="bi bi-folder2-open fs-2 d-block mb-1 text-muted"></i>
                                        Sin carpetas registradas.
                                    </div>
                                </div>
                            </div>

                            <!-- Formulario Crear / Editar Carpeta -->
                            <div class="col-12 col-md-6">
                                <div class="p-3 border rounded-3 bg-light-subtle h-100 d-flex flex-column justify-content-between">
                                    <form @submit.prevent="submitFolder">
                                        <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
                                            <span class="small fw-bold text-body">
                                                {{ folderForm.id ? `Editar: ${folderForm.name}` : 'Nueva Carpeta' }}
                                            </span>
                                            <button
                                                v-if="folderForm.id"
                                                type="button"
                                                class="btn btn-link btn-sm text-secondary p-0 text-decoration-none"
                                                @click="resetFolderForm"
                                            >
                                                Cancelar edición
                                            </button>
                                        </div>

                                        <div class="mb-3">
                                            <label for="cat_folder_name" class="form-label small fw-semibold text-body">
                                                Nombre de carpeta <span class="text-danger">*</span>
                                            </label>
                                            <input
                                                id="cat_folder_name"
                                                v-model="folderForm.name"
                                                type="text"
                                                maxlength="100"
                                                required
                                                class="form-control"
                                                :class="{ 'is-invalid': folderForm.errors.name }"
                                                placeholder="Ej.: Políticas y Reglamentos"
                                                style="border-radius: 8px; font-size: 0.85rem;"
                                            >
                                            <div v-if="folderForm.errors.name" class="text-danger small mt-1">{{ folderForm.errors.name }}</div>
                                        </div>

                                        <div class="mb-3">
                                            <label for="cat_folder_desc" class="form-label small fw-semibold text-body">
                                                Descripción (opcional)
                                            </label>
                                            <input
                                                id="cat_folder_desc"
                                                v-model="folderForm.description"
                                                type="text"
                                                maxlength="255"
                                                class="form-control"
                                                placeholder="Finalidad de los documentos que contendrá..."
                                                style="border-radius: 8px; font-size: 0.85rem;"
                                            >
                                        </div>

                                        <div class="row g-2 mb-3">
                                            <div class="col-7">
                                                <label for="cat_folder_icon" class="form-label small fw-semibold text-body">Ícono</label>
                                                <select
                                                    id="cat_folder_icon"
                                                    v-model="folderForm.icon"
                                                    class="form-select form-select-sm"
                                                    style="border-radius: 8px; font-size: 0.85rem; height: 38px;"
                                                >
                                                    <option v-for="(label, key) in (folder_icons && Object.keys(folder_icons).length ? folder_icons : DEFAULT_FOLDER_ICONS)" :key="key" :value="key">
                                                        {{ label }} ({{ key }})
                                                    </option>
                                                </select>
                                            </div>
                                            <div class="col-5">
                                                <label for="cat_folder_pos" class="form-label small fw-semibold text-body">Posición</label>
                                                <input
                                                    id="cat_folder_pos"
                                                    v-model="folderForm.position"
                                                    type="number"
                                                    min="0"
                                                    class="form-control form-control-sm"
                                                    style="border-radius: 8px; font-size: 0.85rem; height: 38px;"
                                                    placeholder="Ej.: 1"
                                                >
                                            </div>
                                        </div>

                                        <div class="mb-3">
                                            <label for="cat_folder_color" class="form-label small fw-semibold text-body">Color distintivo</label>
                                            <input
                                                id="cat_folder_color"
                                                v-model="folderForm.color"
                                                type="color"
                                                class="form-control form-control-color w-100"
                                                style="height: 38px; border-radius: 8px; cursor: pointer;"
                                            >
                                        </div>

                                        <div class="d-flex justify-content-end gap-2 pt-2 border-top">
                                            <button
                                                type="submit"
                                                class="btn btn-primary px-3 py-1.5 fw-semibold w-100"
                                                style="height: 38px; border-radius: 8px; font-size: 0.85rem;"
                                                :disabled="folderForm.processing"
                                            >
                                                <i class="bi" :class="folderForm.id ? 'bi-check-lg' : 'bi-plus-lg'" style="margin-right: 6px !important;"></i>
                                                {{ folderForm.id ? 'Guardar Cambios' : 'Crear Carpeta' }}
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Modal Footer -->
                    <div class="modal-footer border-top p-3" style="background: var(--bg-surface);">
                        <button type="button" class="btn btn-outline-secondary px-3 py-1.5 fw-medium" @click="closeFoldersModal">
                            Cerrar
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
