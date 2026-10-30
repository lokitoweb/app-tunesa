<script setup>
import { ref, computed, onMounted } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import TenantBadge from '@/Modules/Core/Components/TenantBadge.vue';
import AudiencePicker from '@/Modules/Core/Components/AudiencePicker.vue';
import { useConfirm } from '@/Modules/Core/Composables/useConfirm';
import { useTenantPicker } from '@/Modules/Core/Composables/useTenantPicker';

const page = usePage();

const props = defineProps({
    announcements: {
        type: Object,
        required: true,
    },
    categories: {
        type: Object,
        default: () => ({}),
    },
    statuses: {
        type: Object,
        default: () => ({}),
    },
    totals: {
        type: Object,
        default: () => ({ sent_month: 0, scheduled: 0, draft: 0 }),
    },
    filters: {
        type: Object,
        default: () => ({ status: '', search: '' }),
    },
    audience: {
        type: Object,
        default: () => ({ types: {}, branches: [], departments: [], positions: [], employees: [] }),
    },
});

const { ask } = useConfirm();
const { ensureTenant, consumeIntent } = useTenantPicker();

// Tras elegir empresa en la vista global, reabre el formulario que se pidió
onMounted(() => {
    if (consumeIntent('comunicado')) openCreateModal();
});

// Datos brutos de comunicados (de paginator inicial)
const rawList = computed(() => props.announcements?.data || []);

// Filtros 100% internos reactivos (sin modificar URL ni recargar página)
const selectedStatus = ref(props.filters.status || 'all');
const searchQuery = ref(props.filters.search || '');
const selectedCategory = ref('');

// Paginador interno reactivo
const currentPage = ref(1);
const perPage = ref(10);

const STATUS_CONFIG = {
    sent: {
        label: 'Enviado',
        badgeClass: 'bg-success-subtle text-success border border-success-subtle',
        dotClass: 'bg-success',
        icon: 'bi-send-check-fill',
    },
    scheduled: {
        label: 'Programado',
        badgeClass: 'bg-primary-subtle text-primary border border-primary-subtle',
        dotClass: 'bg-primary',
        icon: 'bi-clock-fill',
    },
    draft: {
        label: 'Borrador',
        badgeClass: 'bg-warning-subtle text-warning-emphasis border border-warning-subtle',
        dotClass: 'bg-warning',
        icon: 'bi-pencil-square',
    },
};

// Conteos reactivos para los tabs
const sentCount = computed(() => rawList.value.filter((a) => a.status === 'sent').length);
const scheduledCount = computed(() => rawList.value.filter((a) => a.status === 'scheduled').length);
const draftCount = computed(() => rawList.value.filter((a) => a.status === 'draft').length);

// Filtrado 100% interno en memoria
const filteredAnnouncements = computed(() => {
    return rawList.value.filter((a) => {
        // Filtro por Estado
        if (selectedStatus.value && selectedStatus.value !== 'all') {
            if (a.status !== selectedStatus.value) return false;
        }

        // Filtro por Categoría
        if (selectedCategory.value && a.category !== selectedCategory.value) {
            return false;
        }

        // Filtro por Búsqueda Predictiva
        if (searchQuery.value.trim()) {
            const q = searchQuery.value.toLowerCase().trim();
            const matchesTitle = a.title?.toLowerCase().includes(q);
            const matchesCategory = a.category?.toLowerCase().includes(q);
            const matchesAudience = a.audience?.some((aud) => aud.toLowerCase().includes(q));
            if (!matchesTitle && !matchesCategory && !matchesAudience) return false;
        }

        return true;
    });
});

// Paginación interna
const totalPages = computed(() => Math.max(1, Math.ceil(filteredAnnouncements.value.length / perPage.value)));

const paginatedAnnouncements = computed(() => {
    const start = (currentPage.value - 1) * perPage.value;
    return filteredAnnouncements.value.slice(start, start + perPage.value);
});

function setStatusFilter(key) {
    selectedStatus.value = key;
    currentPage.value = 1;
}

function goToPage(page) {
    if (page >= 1 && page <= totalPages.value) {
        currentPage.value = page;
    }
}

const readRate = (a) => (a.recipients ? Math.round((a.reads / a.recipients) * 100) : 0);

async function deleteAnnouncement(a) {
    const isSent = a.status === 'sent';
    const confirmed = await ask({
        title: '¿Eliminar comunicado?',
        message: isSent 
            ? `¿Estás seguro de eliminar "${a.title}"? Dejará de mostrarse en la app móvil del personal asignado.`
            : `¿Estás seguro de eliminar el borrador "${a.title}"?`,
        confirmText: 'Sí, eliminar',
        variant: 'danger',
        icon: 'bi bi-trash3-fill',
    });

    if (confirmed) {
        router.delete(`/announcements/${a.id}`, { preserveScroll: true });
    }
}

// =========================================================================
// MODAL DIALOG PARA CREAR NUEVO COMUNICADO
// =========================================================================
const showCreateModal = ref(false);

const createForm = useForm({
    title: '',
    body: '',
    category: 'general',
    is_important: false,
    event_at: '',
    location: '',
    url: '',
    scheduled_at: '',
    mode: 'send',
    targets: [{ type: 'all', id: null, value: null }],
});

async function openCreateModal() {
    if (!ensureTenant({ intent: 'comunicado', message: 'Elige la empresa que difundirá el comunicado.' })) {
        return;
    }

    createForm.reset();
    createForm.clearErrors();
    createForm.category = Object.keys(props.categories)[0] || 'general';
    createForm.mode = 'send';
    createForm.targets = [{ type: 'all', id: null, value: null }];
    showCreateModal.value = true;
}

function closeCreateModal() {
    showCreateModal.value = false;
}

async function submitCreate(mode) {
    if (mode === 'send') {
        const ok = await ask({
            title: '¿Enviar comunicado ahora?',
            message: 'El comunicado se difundirá inmediatamente a toda la audiencia asignada y cada persona recibirá una notificación push en la app móvil.',
            confirmText: 'Enviar ahora',
            variant: 'primary',
            icon: 'bi bi-send-fill',
        });
        if (!ok) return;
    }

    createForm.mode = mode;
    createForm.post('/announcements', {
        preserveScroll: true,
        onSuccess: () => {
            closeCreateModal();
            createForm.reset();
        },
    });
}
</script>

<template>
    <AppLayout title="Comunicados de Empresa">
        <div class="announcements-page-wrapper">
            <!-- =========================================================================
                 1. CABECERA EJECUTIVA DEL MÓDULO
                 ========================================================================= -->
            <div class="announcements-header-card shadow-sm p-3 p-md-4 mb-4">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                    <div class="d-flex align-items-center" style="gap: 16px !important;">
                        <div class="announcements-header-icon shadow-sm">
                            <i class="bi bi-megaphone-fill"></i>
                        </div>
                        <div>
                            <div class="d-flex align-items-center flex-wrap mb-1" style="gap: 10px !important;">
                                <h1 class="h4 fw-bold mb-0 text-body">Comunicados y Avisos</h1>
                                <span class="badge rounded-2 bg-primary-subtle text-primary border border-primary-subtle px-2.5 py-1 small fw-semibold">
                                    <i class="bi bi-broadcast" style="margin-right: 8px !important;"></i>Difusión Móvil
                                </span>
                                <span class="badge rounded-2 bg-info-subtle text-info border border-info-subtle px-2.5 py-1 small fw-semibold">
                                    <i class="bi bi-check2-all" style="margin-right: 8px !important;"></i>Lectura Auditada
                                </span>
                                <span class="badge rounded-2 bg-success-subtle text-success border border-success-subtle px-2.5 py-1 small fw-semibold">
                                    <i class="bi bi-bell-fill" style="margin-right: 8px !important;"></i>Push Notification
                                </span>
                            </div>
                            <p class="text-secondary small mb-0">
                                Envío de comunicados oficiales, recordatorios y reuniones a toda la empresa o segmentados por sede, área y cargo.
                            </p>
                        </div>
                    </div>

                    <!-- Botón para Abrir Modal Dialog -->
                    <div class="d-flex align-items-center flex-wrap" style="gap: 10px;">
                        <button 
                            type="button" 
                            class="btn btn-primary d-inline-flex align-items-center shadow-sm"
                            style="height: 38px; border-radius: 6px; font-weight: 600; font-size: 0.85rem; padding: 0 1.25rem;"
                            @click="openCreateModal"
                        >
                            <i class="bi bi-plus-lg" style="margin-right: 8px !important;"></i>
                            <span>Nuevo comunicado</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- =========================================================================
                 2. TARJETAS DE MÉTRICAS KPI (RESUMEN EJECUTIVO)
                 ========================================================================= -->
            <div class="row g-3 mb-4">
                <!-- Enviados este mes -->
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="announcements-kpi-card shadow-sm p-3 h-100">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="text-muted fw-semibold small text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.05em;">Enviados este mes</span>
                            <div class="announcements-kpi-pill bg-emerald-subtle text-emerald">
                                <i class="bi bi-send-check-fill"></i>
                            </div>
                        </div>
                        <div class="d-flex align-items-baseline gap-2">
                            <span class="h3 fw-bold mb-0 text-body">{{ totals.sent_month }}</span>
                            <span class="text-muted small">publicados</span>
                        </div>
                        <div class="text-muted small mt-1" style="font-size: 0.75rem;">
                            Difundidos en la app móvil
                        </div>
                    </div>
                </div>

                <!-- Programados -->
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="announcements-kpi-card shadow-sm p-3 h-100">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="text-muted fw-semibold small text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.05em;">Programados</span>
                            <div class="announcements-kpi-pill bg-primary-subtle text-primary">
                                <i class="bi bi-clock-fill"></i>
                            </div>
                        </div>
                        <div class="d-flex align-items-baseline gap-2">
                            <span class="h3 fw-bold mb-0 text-body">{{ totals.scheduled }}</span>
                            <span class="badge" :class="totals.scheduled > 0 ? 'bg-primary-subtle text-primary' : 'bg-secondary-subtle text-secondary'">
                                {{ totals.scheduled > 0 ? 'En cola' : 'Sin pendientes' }}
                            </span>
                        </div>
                        <div class="text-muted small mt-1" style="font-size: 0.75rem;">
                            Envíos automáticos agendados
                        </div>
                    </div>
                </div>

                <!-- Borradores -->
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="announcements-kpi-card shadow-sm p-3 h-100">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="text-muted fw-semibold small text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.05em;">Borradores</span>
                            <div class="announcements-kpi-pill bg-amber-subtle text-amber">
                                <i class="bi bi-pencil-square"></i>
                            </div>
                        </div>
                        <div class="d-flex align-items-baseline gap-2">
                            <span class="h3 fw-bold mb-0 text-body">{{ totals.draft }}</span>
                            <span class="text-muted small">sin publicar</span>
                        </div>
                        <div class="text-muted small mt-1" style="font-size: 0.75rem;">
                            Pendientes de revisión o emisión
                        </div>
                    </div>
                </div>

                <!-- Total Emitidos -->
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="announcements-kpi-card shadow-sm p-3 h-100">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="text-muted fw-semibold small text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.05em;">Total Histórico</span>
                            <div class="announcements-kpi-pill bg-info-subtle text-info">
                                <i class="bi bi-megaphone-fill"></i>
                            </div>
                        </div>
                        <div class="d-flex align-items-baseline gap-2">
                            <span class="h3 fw-bold mb-0 text-body">{{ rawList.length }}</span>
                            <span class="text-muted small">registrados</span>
                        </div>
                        <div class="text-muted small mt-1" style="font-size: 0.75rem;">
                            {{ sentCount }} enviados · {{ scheduledCount }} programados
                        </div>
                    </div>
                </div>
            </div>

            <!-- =========================================================================
                 3. BARRA DE FILTROS SEGMENTADOS Y BÚSQUEDA EN UNA SOLA LÍNEA HORIZONTAL
                 ========================================================================= -->
            <div class="announcements-filter-card shadow-sm p-3 mb-4">
                <div class="d-flex flex-wrap flex-lg-nowrap align-items-center justify-content-between gap-3">
                    <!-- Control Segmentado Ejecutivo (Estilo Apple / Linear) con Espaciado Garantizado de 8px -->
                    <div class="training-segmented-bar flex-shrink-0" role="tablist" aria-label="Filtro por estado de comunicado">
                        <button 
                            type="button" 
                            class="training-segmented-item"
                            :class="{ 'is-active': selectedStatus === 'all' }"
                            @click="setStatusFilter('all')"
                        >
                            <i class="bi bi-grid-fill"></i>
                            <span>Todos</span>
                            <span class="training-segmented-pill">{{ rawList.length }}</span>
                        </button>
                        <button 
                            type="button" 
                            class="training-segmented-item"
                            :class="{ 'is-active': selectedStatus === 'sent' }"
                            @click="setStatusFilter('sent')"
                        >
                            <i class="bi bi-send-check-fill text-success"></i>
                            <span>Enviados</span>
                            <span class="training-segmented-pill">{{ sentCount }}</span>
                        </button>
                        <button 
                            type="button" 
                            class="training-segmented-item"
                            :class="{ 'is-active': selectedStatus === 'scheduled' }"
                            @click="setStatusFilter('scheduled')"
                        >
                            <i class="bi bi-clock-fill text-primary"></i>
                            <span>Programados</span>
                            <span class="training-segmented-pill">{{ scheduledCount }}</span>
                        </button>
                        <button 
                            type="button" 
                            class="training-segmented-item"
                            :class="{ 'is-active': selectedStatus === 'draft' }"
                            @click="setStatusFilter('draft')"
                        >
                            <i class="bi bi-pencil-square text-warning"></i>
                            <span>Borradores</span>
                            <span class="training-segmented-pill">{{ draftCount }}</span>
                        </button>
                    </div>

                    <!-- Buscador Predictivo y Filtro de Categoría EN UNA SOLA LÍNEA HORIZONTAL -->
                    <div class="d-flex align-items-center flex-nowrap" style="gap: 12px; min-width: 0;">
                        <!-- Selector de Categoría con Modern Input Wrapper -->
                        <div v-if="Object.keys(categories).length" class="modern-input-wrapper flex-shrink-0" style="width: 220px; min-width: 195px;">
                            <i class="bi bi-tag-fill modern-input-icon"></i>
                            <select 
                                v-model="selectedCategory" 
                                class="modern-input-control"
                                aria-label="Filtrar por tipo"
                                @change="currentPage = 1"
                            >
                                <option value="">Todos los tipos</option>
                                <option v-for="(c, key) in categories" :key="key" :value="key">
                                    {{ c[0] }}
                                </option>
                            </select>
                        </div>

                        <!-- Input de Búsqueda Moderna Unificada -->
                        <div class="modern-input-wrapper flex-shrink-0" style="width: 280px; min-width: 220px;">
                            <i class="bi bi-search modern-input-icon"></i>
                            <input 
                                v-model="searchQuery" 
                                type="search" 
                                class="modern-input-control"
                                placeholder="Buscar por título o público..."
                                aria-label="Buscar comunicados"
                                @input="currentPage = 1"
                            >
                            <button 
                                v-if="searchQuery" 
                                type="button" 
                                class="btn btn-sm text-secondary p-0 me-2 border-0"
                                style="width: 22px; height: 22px;"
                                title="Limpiar búsqueda"
                                @click="searchQuery = ''; currentPage = 1;"
                            >
                                <i class="bi bi-x-circle-fill"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- =========================================================================
                 4. TABLA PRINCIPAL DE COMUNICADOS
                 ========================================================================= -->
            <div class="announcements-table-card shadow-sm mb-4">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr>
                                <th class="ps-3 py-3" style="width: 35%;">Comunicado</th>
                                <th class="py-3" style="width: 25%;">Público Asignado</th>
                                <th class="py-3" style="width: 15%;">Estado / Emisión</th>
                                <th class="py-3 text-end" style="width: 15%;">Lectura Móvil</th>
                                <th class="pe-3 py-3 text-end" style="width: 10%;">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="a in paginatedAnnouncements" :key="a.id" class="announcements-table-row">
                                <!-- Columna: Comunicado -->
                                <td class="ps-3 py-3">
                                    <div class="d-flex align-items-center" style="gap: 12px !important;">
                                        <div 
                                            class="announcements-type-icon shadow-xs" 
                                            :class="a.is_important ? 'is-urgent' : ''"
                                            :title="categories[a.category]?.[0] || a.category"
                                        >
                                            <i class="bi" :class="`bi-${categories[a.category]?.[1] ?? 'megaphone'}`"></i>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="d-flex align-items-center flex-wrap" style="gap: 8px !important;">
                                                <Link 
                                                    :href="a.status === 'sent' ? `/announcements/${a.id}` : `/announcements/${a.id}/edit`" 
                                                    class="fw-bold text-decoration-none text-body text-truncate"
                                                    style="font-size: 0.925rem;"
                                                    :title="a.title"
                                                >
                                                    {{ a.title }}
                                                </Link>
                                                <span 
                                                    v-if="a.is_important" 
                                                    class="badge rounded-2 bg-warning-subtle text-warning-emphasis border border-warning-subtle px-2 py-0.5 small fw-semibold"
                                                    style="font-size: 0.72rem;"
                                                >
                                                    <i class="bi bi-exclamation-triangle-fill" style="margin-right: 6px !important;"></i>Importante
                                                </span>
                                            </div>
                                            <div class="d-flex align-items-center flex-wrap mt-1" style="gap: 8px !important;">
                                                <span class="badge bg-light text-secondary border small">
                                                    {{ categories[a.category]?.[0] || a.category }}
                                                </span>
                                                <span v-if="a.event_at" class="small text-muted" style="font-size: 0.775rem;">
                                                    <i class="bi bi-calendar-event text-primary" style="margin-right: 6px !important;"></i>Evento: {{ a.event_at }}
                                                </span>
                                                <TenantBadge v-if="a.tenant_id" :tenant-id="a.tenant_id" />
                                            </div>
                                        </div>
                                    </div>
                                </td>

                                <!-- Columna: Público -->
                                <td class="py-3">
                                    <div class="small fw-medium text-body">
                                        <i class="bi bi-people text-secondary" style="margin-right: 8px !important;"></i>
                                        {{ a.audience && a.audience.length ? a.audience.join(', ') : 'Todo el personal' }}
                                    </div>
                                </td>

                                <!-- Columna: Estado -->
                                <td class="py-3">
                                    <span 
                                        class="badge rounded-pill px-2.5 py-1 d-inline-flex align-items-center"
                                        :class="STATUS_CONFIG[a.status]?.badgeClass || 'bg-light text-secondary border'"
                                        style="font-size: 0.775rem;"
                                    >
                                        <span 
                                            :class="STATUS_CONFIG[a.status]?.dotClass || 'bg-secondary'"
                                            style="width: 6px; height: 6px; display: inline-block; border-radius: 999px; margin-right: 8px !important;"
                                        ></span>
                                        {{ statuses[a.status] || a.status }}
                                    </span>
                                    <div class="small text-muted mt-1" style="font-size: 0.75rem;">
                                        {{ a.sent_at ? `Enviado: ${a.sent_at}` : (a.scheduled_at ? `Prog.: ${a.scheduled_at}` : 'Sin programar') }}
                                    </div>
                                </td>

                                <!-- Columna: Lectura Móvil -->
                                <td class="py-3 text-end" style="min-width: 140px;">
                                    <template v-if="a.status === 'sent'">
                                        <div class="small fw-bold text-body mb-1">
                                            {{ a.reads }} / {{ a.recipients }} <span class="text-muted fw-normal">({{ readRate(a) }}%)</span>
                                        </div>
                                        <div 
                                            class="progress shadow-none ms-auto" 
                                            style="height: 6px; width: 110px; border-radius: 999px; background-color: rgba(148, 163, 184, 0.2);" 
                                            role="progressbar" 
                                            :aria-valuenow="readRate(a)" 
                                            aria-valuemin="0" 
                                            aria-valuemax="100" 
                                            :aria-label="`Leído por el ${readRate(a)}%`"
                                        >
                                            <div 
                                                class="progress-bar" 
                                                :class="readRate(a) >= 80 ? 'bg-success' : (readRate(a) >= 40 ? 'bg-primary' : 'bg-warning')" 
                                                :style="{ width: `${readRate(a)}%`, borderRadius: '999px' }"
                                            ></div>
                                        </div>
                                    </template>
                                    <span v-else class="text-muted small">—</span>
                                </td>

                                <!-- Columna: Acciones (Solo Iconos Elegantes con Tooltips) -->
                                <td class="pe-3 py-3 text-end text-nowrap">
                                    <div class="d-inline-flex align-items-center justify-content-end" style="gap: 8px !important;">
                                        <!-- Ver Detalle / Lecturas -->
                                        <Link 
                                            v-if="a.status === 'sent'" 
                                            :href="`/announcements/${a.id}`" 
                                            class="btn-table-action btn-action-view" 
                                            title="Ver auditoría de lecturas"
                                            aria-label="Ver auditoría de lecturas"
                                        >
                                            <i class="bi bi-eye"></i>
                                        </Link>

                                        <!-- Editar Borrador o Programado -->
                                        <Link 
                                            v-else 
                                            :href="`/announcements/${a.id}/edit`" 
                                            class="btn-table-action btn-action-edit" 
                                            title="Editar comunicado"
                                            aria-label="Editar comunicado"
                                        >
                                            <i class="bi bi-pencil"></i>
                                        </Link>

                                        <!-- Eliminar -->
                                        <button 
                                            type="button" 
                                            class="btn-table-action btn-table-delete text-danger" 
                                            title="Eliminar comunicado"
                                            aria-label="Eliminar comunicado"
                                            @click="deleteAnnouncement(a)"
                                        >
                                            <i class="bi bi-trash3"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>

                            <!-- Estado Vacío -->
                            <tr v-if="!filteredAnnouncements.length">
                                <td colspan="5" class="text-center py-5">
                                    <div class="d-flex flex-column align-items-center justify-content-center p-4">
                                        <div 
                                            class="announcements-header-icon mb-3 opacity-75"
                                            style="width: 56px; height: 56px; font-size: 1.6rem;"
                                        >
                                            <i class="bi bi-megaphone"></i>
                                        </div>
                                        <h6 class="fw-bold text-body mb-1">
                                            {{ searchQuery || selectedStatus !== 'all' ? 'No se encontraron comunicados' : 'Aún no hay comunicados registrados' }}
                                        </h6>
                                        <p class="text-secondary small mb-3" style="max-width: 420px;">
                                            {{ searchQuery || selectedStatus !== 'all'
                                                ? 'No hay registros que coincidan con el estado o término de búsqueda ingresado.'
                                                : 'Envía tu primer comunicado a toda la empresa, una sucursal, un área o cargos específicos.'
                                            }}
                                        </p>
                                        <div class="d-flex align-items-center gap-2">
                                            <button 
                                                v-if="searchQuery || selectedStatus !== 'all' || selectedCategory" 
                                                type="button" 
                                                class="btn btn-sm btn-outline-secondary"
                                                @click="searchQuery = ''; selectedCategory = ''; selectedStatus = 'all'; currentPage = 1;"
                                            >
                                                <i class="bi bi-x-circle" style="margin-right: 8px !important;"></i>Ver todos
                                            </button>
                                            <button 
                                                v-else
                                                type="button" 
                                                class="btn btn-sm btn-primary d-inline-flex align-items-center"
                                                style="border-radius: 6px; font-weight: 600; padding: 6px 16px;"
                                                @click="openCreateModal"
                                            >
                                                <i class="bi bi-plus-lg" style="margin-right: 8px !important;"></i>
                                                <span>Crear primer comunicado</span>
                                            </button>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Paginador Interno Reactivo (Sin mutar URL) -->
                <div v-if="filteredAnnouncements.length > perPage" class="p-3 border-top bg-light-subtle d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <span class="small text-secondary">
                        Mostrando <strong>{{ (currentPage - 1) * perPage + 1 }}</strong> - <strong>{{ Math.min(currentPage * perPage, filteredAnnouncements.length) }}</strong> de <strong>{{ filteredAnnouncements.length }}</strong> comunicados
                    </span>
                    <div class="d-flex align-items-center" style="gap: 4px;">
                        <button 
                            type="button" 
                            class="btn btn-sm btn-outline-secondary"
                            style="width: 32px; height: 32px; padding: 0; border-radius: 6px;"
                            :disabled="currentPage === 1"
                            title="Página anterior"
                            @click="goToPage(currentPage - 1)"
                        >
                            <i class="bi bi-chevron-left"></i>
                        </button>
                        <button 
                            v-for="p in totalPages" 
                            :key="p"
                            type="button" 
                            class="btn btn-sm"
                            :class="currentPage === p ? 'btn-primary' : 'btn-outline-secondary'"
                            style="width: 32px; height: 32px; padding: 0; border-radius: 6px; font-weight: 600; font-size: 0.8rem;"
                            @click="goToPage(p)"
                        >
                            {{ p }}
                        </button>
                        <button 
                            type="button" 
                            class="btn btn-sm btn-outline-secondary"
                            style="width: 32px; height: 32px; padding: 0; border-radius: 6px;"
                            :disabled="currentPage === totalPages"
                            title="Página siguiente"
                            @click="goToPage(currentPage + 1)"
                        >
                            <i class="bi bi-chevron-right"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- =========================================================================
             MODAL DIALOG PARA CREAR NUEVO COMUNICADO (Elegante y Escalable)
             ========================================================================= -->
        <div 
            v-if="showCreateModal" 
            class="modal fade show d-block" 
            tabindex="-1" 
            role="dialog" 
            aria-modal="true" 
            style="background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px); z-index: 1055;"
            @click.self="closeCreateModal"
        >
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content border-0 shadow-lg" style="border-radius: 14px; overflow: hidden;">
                    <!-- Modal Header -->
                    <div class="modal-header border-bottom p-3 p-md-4" style="background: var(--bg-surface);">
                        <div class="d-flex align-items-center" style="gap: 14px !important;">
                            <div style="width: 44px; height: 44px; border-radius: 12px; background: linear-gradient(135deg, var(--brand), #0369a1); display: flex; align-items: center; justify-content: center; color: #fff; font-size: 1.35rem; box-shadow: 0 4px 12px rgba(var(--brand-rgb), 0.25);">
                                <i class="bi bi-megaphone-fill"></i>
                            </div>
                            <div>
                                <h2 class="h5 fw-bold mb-0 text-body">Nuevo Comunicado</h2>
                                <span class="small text-secondary">Redacta el mensaje oficial y define el público objetivo</span>
                            </div>
                        </div>
                        <button type="button" class="btn-close" aria-label="Cerrar" @click="closeCreateModal"></button>
                    </div>

                    <!-- Modal Body -->
                    <div class="modal-body p-3 p-md-4" style="background: var(--bg-surface);">
                        <form @submit.prevent="submitCreate(createForm.scheduled_at ? 'schedule' : 'send')">
                            <!-- Título -->
                            <div class="mb-3">
                                <label for="create_title" class="form-label small fw-semibold text-body">
                                    Título del comunicado <span class="text-danger">*</span>
                                </label>
                                <div class="modern-input-wrapper">
                                    <i class="bi bi-type-h1 modern-input-icon" style="margin-right: 8px !important;"></i>
                                    <input 
                                        id="create_title" 
                                        v-model="createForm.title" 
                                        type="text" 
                                        maxlength="150" 
                                        required
                                        class="modern-input-control" 
                                        :class="{ 'is-invalid': createForm.errors.title }"
                                        placeholder="Ej.: Actualización de horarios de atención"
                                    >
                                </div>
                                <div v-if="createForm.errors.title" class="text-danger small mt-1">{{ createForm.errors.title }}</div>
                            </div>

                            <div class="row g-3 mb-3">
                                <!-- Categoría -->
                                <div class="col-12 col-md-7">
                                    <label for="create_category" class="form-label small fw-semibold text-body">
                                        Categoría / Tipo <span class="text-danger">*</span>
                                    </label>
                                    <div class="modern-input-wrapper">
                                        <i class="bi bi-tag-fill modern-input-icon"></i>
                                        <select 
                                            id="create_category" 
                                            v-model="createForm.category" 
                                            class="modern-input-control"
                                            required
                                        >
                                            <option v-for="(c, key) in categories" :key="key" :value="key">
                                                {{ c[0] }}
                                            </option>
                                        </select>
                                    </div>
                                </div>

                                <!-- Importante -->
                                <div class="col-12 col-md-5 d-flex align-items-end">
                                    <div class="form-check form-switch p-2 border rounded w-100 d-flex align-items-center justify-content-between" style="min-height: 42px;">
                                        <label class="form-check-label small fw-semibold text-body mb-0 cursor-pointer" for="create_important">
                                            <i class="bi bi-exclamation-triangle-fill text-warning" style="margin-right: 6px !important;"></i>
                                            Marcar como importante
                                        </label>
                                        <input 
                                            id="create_important" 
                                            v-model="createForm.is_important" 
                                            class="form-check-input ms-0" 
                                            type="checkbox" 
                                            role="switch"
                                            style="cursor: pointer;"
                                        >
                                    </div>
                                </div>
                            </div>

                            <!-- Mensaje / Cuerpo -->
                            <div class="mb-3">
                                <label for="create_body" class="form-label small fw-semibold text-body">
                                    Mensaje del comunicado <span class="text-danger">*</span>
                                </label>
                                <textarea 
                                    id="create_body" 
                                    v-model="createForm.body" 
                                    rows="4" 
                                    maxlength="5000" 
                                    required
                                    class="form-control"
                                    :class="{ 'is-invalid': createForm.errors.body }"
                                    placeholder="Escribe el mensaje completo que verá el personal..."
                                    style="border-radius: 8px; font-size: 0.875rem;"
                                ></textarea>
                                <div v-if="createForm.errors.body" class="text-danger small mt-1">{{ createForm.errors.body }}</div>
                            </div>

                            <!-- Público Objetivo / Audiencia -->
                            <div class="mb-3 p-3 border rounded-3 bg-light-subtle">
                                <label class="form-label small fw-bold text-body mb-2 d-flex align-items-center">
                                    <i class="bi bi-people-fill text-primary" style="margin-right: 8px !important;"></i>
                                    Público destinatario
                                </label>
                                <AudiencePicker 
                                    v-model="createForm.targets" 
                                    :options="audience" 
                                    :errors="createForm.errors" 
                                />
                            </div>

                            <!-- Detalles opcionales -->
                            <div class="row g-3">
                                <div class="col-12 col-md-6">
                                    <label for="create_event_at" class="form-label small fw-semibold text-secondary">
                                        Fecha y hora del evento (opcional)
                                    </label>
                                    <div class="modern-input-wrapper">
                                        <i class="bi bi-calendar-event modern-input-icon" style="margin-right: 8px !important;"></i>
                                        <input 
                                            id="create_event_at" 
                                            v-model="createForm.event_at" 
                                            type="datetime-local" 
                                            class="modern-input-control"
                                        >
                                    </div>
                                </div>

                                <div class="col-12 col-md-6">
                                    <label for="create_location" class="form-label small fw-semibold text-secondary">
                                        Lugar o sala (opcional)
                                    </label>
                                    <div class="modern-input-wrapper">
                                        <i class="bi bi-geo-alt modern-input-icon" style="margin-right: 8px !important;"></i>
                                        <input 
                                            id="create_location" 
                                            v-model="createForm.location" 
                                            type="text" 
                                            maxlength="200" 
                                            class="modern-input-control"
                                            placeholder="Ej.: Sala de reuniones A"
                                        >
                                    </div>
                                </div>

                                <div class="col-12 col-md-6">
                                    <label for="create_url" class="form-label small fw-semibold text-secondary">
                                        Enlace web externo (opcional)
                                    </label>
                                    <div class="modern-input-wrapper">
                                        <i class="bi bi-link-45deg modern-input-icon" style="margin-right: 8px !important;"></i>
                                        <input 
                                            id="create_url" 
                                            v-model="createForm.url" 
                                            type="url" 
                                            maxlength="500" 
                                            class="modern-input-control"
                                            placeholder="https://..."
                                        >
                                    </div>
                                </div>

                                <div class="col-12 col-md-6">
                                    <label for="create_scheduled_at" class="form-label small fw-semibold text-secondary">
                                        Programar envío para más tarde (opcional)
                                    </label>
                                    <div class="modern-input-wrapper">
                                        <i class="bi bi-clock modern-input-icon" style="margin-right: 8px !important;"></i>
                                        <input 
                                            id="create_scheduled_at" 
                                            v-model="createForm.scheduled_at" 
                                            type="datetime-local" 
                                            class="modern-input-control"
                                        >
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- Modal Footer -->
                    <div class="modal-footer border-top p-3 px-md-4 d-flex justify-content-between align-items-center" style="background: var(--bg-surface);">
                        <button 
                            type="button" 
                            class="btn btn-outline-secondary d-inline-flex align-items-center"
                            @click="closeCreateModal"
                        >
                            Cancelar
                        </button>
                        <div class="d-flex align-items-center" style="gap: 10px !important;">
                            <button 
                                type="button" 
                                class="btn btn-outline-primary d-inline-flex align-items-center"
                                :disabled="createForm.processing"
                                @click="submitCreate('draft')"
                            >
                                <span v-if="createForm.processing" class="spinner-border spinner-border-sm" style="margin-right: 8px !important;"></span>
                                <i v-else class="bi bi-file-earmark-check" style="margin-right: 8px !important;"></i>
                                <span>Guardar borrador</span>
                            </button>
                            <button 
                                v-if="createForm.scheduled_at" 
                                type="button" 
                                class="btn btn-primary d-inline-flex align-items-center shadow-sm"
                                :disabled="createForm.processing"
                                @click="submitCreate('schedule')"
                            >
                                <span v-if="createForm.processing" class="spinner-border spinner-border-sm" style="margin-right: 8px !important;"></span>
                                <i v-else class="bi bi-clock-fill" style="margin-right: 8px !important;"></i>
                                <span>Programar envío</span>
                            </button>
                            <button 
                                v-else 
                                type="button" 
                                class="btn btn-primary d-inline-flex align-items-center shadow-sm"
                                :disabled="createForm.processing"
                                @click="submitCreate('send')"
                            >
                                <span v-if="createForm.processing" class="spinner-border spinner-border-sm" style="margin-right: 8px !important;"></span>
                                <i v-else class="bi bi-send-fill" style="margin-right: 8px !important;"></i>
                                <span>Enviar ahora</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.announcements-type-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.15rem;
    flex-shrink: 0;
    background: rgba(var(--brand-rgb), 0.08);
    color: var(--brand);
}

.announcements-type-icon.is-urgent {
    background: rgba(245, 158, 11, 0.12);
    color: #f59e0b;
}

.announcements-table-row {
    transition: background-color 0.15s ease;
}

.announcements-table-row:hover {
    background-color: rgba(var(--brand-rgb), 0.02);
}
</style>
