<script setup>
import { ref, computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import TenantBadge from '@/Modules/Core/Components/TenantBadge.vue';
import { useConfirm } from '@/Modules/Core/Composables/useConfirm';

const props = defineProps({
    courses: {
        type: Array,
        default: () => [],
    },
    totals: {
        type: Object,
        default: () => ({ assigned: 0, completed: 0, overdue: 0 }),
    },
    statuses: {
        type: Object,
        default: () => ({ published: 'Publicada', draft: 'Borrador', archived: 'Archivada' }),
    },
    filter: {
        type: String,
        default: null,
    },
});

const { ask } = useConfirm();

// Filtros 100% internos reactivos (sin modificar URL ni recargar página)
const selectedStatus = ref(props.filter || 'all');
const searchQuery = ref('');
const selectedCategory = ref('');

// Configuración visual de Estados
const STATUS_CONFIG = {
    published: {
        label: 'Publicada',
        badgeClass: 'bg-success-subtle text-success border border-success-subtle',
        dotClass: 'bg-success',
        icon: 'bi-check-circle-fill',
    },
    draft: {
        label: 'Borrador',
        badgeClass: 'bg-warning-subtle text-warning-emphasis border border-warning-subtle',
        dotClass: 'bg-warning',
        icon: 'bi-pencil-square',
    },
    archived: {
        label: 'Archivada',
        badgeClass: 'bg-secondary-subtle text-secondary border border-secondary-subtle',
        dotClass: 'bg-secondary',
        icon: 'bi-archive-fill',
    },
};

// Conteos reactivos por estado directamente de los datos
const draftCount = computed(() => props.courses.filter((c) => c.status === 'draft').length);
const publishedCount = computed(() => props.courses.filter((c) => c.status === 'published').length);
const archivedCount = computed(() => props.courses.filter((c) => c.status === 'archived').length);

// Categorías únicas detectadas de los cursos
const availableCategories = computed(() => {
    const set = new Set();
    props.courses.forEach((c) => {
        if (c.category) set.add(c.category);
    });
    return Array.from(set);
});

// Filtrado 100% interno en memoria
const filteredCourses = computed(() => {
    return props.courses.filter((c) => {
        // Filtro por Estado
        if (selectedStatus.value && selectedStatus.value !== 'all') {
            if (c.status !== selectedStatus.value) return false;
        }

        // Filtro por Categoría
        if (selectedCategory.value && c.category !== selectedCategory.value) {
            return false;
        }

        // Filtro por Búsqueda Predictiva
        if (searchQuery.value.trim()) {
            const q = searchQuery.value.toLowerCase().trim();
            const matchesTitle = c.title?.toLowerCase().includes(q);
            const matchesCat = c.category?.toLowerCase().includes(q);
            if (!matchesTitle && !matchesCat) return false;
        }

        return true;
    });
});

const overallCompletionPercent = computed(() => {
    const total = Number(props.totals?.assigned || 0);
    const done = Number(props.totals?.completed || 0);
    return total > 0 ? Math.round((done / total) * 100) : 0;
});

// Cambio de estado 100% interno sin tocar la barra de URL del navegador
function setStatusFilter(key) {
    selectedStatus.value = key;
}

async function publishCourse(course) {
    const confirmed = await ask({
        title: '¿Publicar capacitación?',
        message: `¿Deseas publicar "${course.title}"? Estará disponible inmediatamente en la app móvil para el personal asignado y recibirán una notificación.`,
        confirmText: 'Publicar ahora',
        variant: 'primary',
        icon: 'bi bi-send-fill',
    });
    if (confirmed) {
        router.post(`/training/${course.id}/status`, { status: 'published' }, { preserveScroll: true });
    }
}

async function archiveCourse(course) {
    const confirmed = await ask({
        title: '¿Archivar capacitación?',
        message: `La capacitación "${course.title}" ya no se mostrará en la app móvil. Puedes volver a activarla cuando desees.`,
        confirmText: 'Sí, archivar',
        variant: 'warning',
        icon: 'bi bi-archive-fill',
    });
    if (confirmed) {
        router.post(`/training/${course.id}/status`, { status: 'archived' }, { preserveScroll: true });
    }
}

async function deleteCourse(course) {
    const confirmed = await ask({
        title: '¿Eliminar capacitación?',
        message: `¿Estás seguro de eliminar "${course.title}"? Se perderán las lecciones, cuestionario y registros de avance asociados.`,
        confirmText: 'Sí, eliminar',
        variant: 'danger',
        icon: 'bi bi-trash3-fill',
    });
    if (confirmed) {
        router.delete(`/training/${course.id}`, { preserveScroll: true });
    }
}
</script>

<template>
    <AppLayout title="Capacitaciones y Formación">
        <div class="training-page-wrapper">
            <!-- =========================================================================
                 1. CABECERA EJECUTIVA DEL MÓDULO
                 ========================================================================= -->
            <div class="training-header-card shadow-sm p-3 p-md-4 mb-4">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                    <div class="d-flex align-items-center" style="gap: 16px !important;">
                        <div class="training-header-icon shadow-sm">
                            <i class="bi bi-mortarboard-fill"></i>
                        </div>
                        <div>
                            <div class="d-flex align-items-center flex-wrap mb-1" style="gap: 10px !important;">
                                <h1 class="h4 fw-bold mb-0 text-body">Capacitaciones y Formación Continua</h1>
                                <span class="badge rounded-2 bg-primary-subtle text-primary border border-primary-subtle px-2.5 py-1 small fw-semibold">
                                    <i class="bi bi-award-fill" style="margin-right: 6px !important;"></i>LMS Integrado
                                </span>
                                <span class="badge rounded-2 bg-info-subtle text-info border border-info-subtle px-2.5 py-1 small fw-semibold">
                                    <i class="bi bi-file-earmark-check-fill" style="margin-right: 6px !important;"></i>Certificación Digital
                                </span>
                                <span v-if="publishedCount > 0" class="badge rounded-2 bg-success-subtle text-success border border-success-subtle px-2.5 py-1 small fw-semibold">
                                    <i class="bi bi-phone-fill" style="margin-right: 6px !important;"></i>{{ publishedCount }} activas en app
                                </span>
                            </div>
                            <p class="text-secondary small mb-0">
                                Diseño de cursos interactivos con lecciones multimedia, cuestionarios con certificación digital y supervisión de avance en tiempo real.
                            </p>
                        </div>
                    </div>

                    <div class="d-flex align-items-center flex-wrap" style="gap: 10px;">
                        <Link 
                            href="/training/create" 
                            class="btn btn-primary d-inline-flex align-items-center shadow-sm"
                            style="height: 38px; border-radius: 6px; font-weight: 600; font-size: 0.85rem; padding: 0 1.25rem;"
                        >
                            <i class="bi bi-plus-lg" style="margin-right: 8px !important;"></i>
                            <span>Nueva capacitación</span>
                        </Link>
                    </div>
                </div>
            </div>

            <!-- =========================================================================
                 2. TARJETAS DE MÉTRICAS KPI (RESUMEN EJECUTIVO)
                 ========================================================================= -->
            <div class="row g-3 mb-4">
                <!-- Asignaciones Totales -->
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="training-kpi-card shadow-sm p-3 h-100">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="text-muted fw-semibold small text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.05em;">Asignaciones</span>
                            <div class="training-kpi-pill bg-primary-subtle text-primary">
                                <i class="bi bi-people-fill"></i>
                            </div>
                        </div>
                        <div class="d-flex align-items-baseline gap-2">
                            <span class="h3 fw-bold mb-0 text-body">{{ totals.assigned }}</span>
                            <span class="text-muted small">colaboradores</span>
                        </div>
                        <div class="text-muted small mt-1" style="font-size: 0.75rem;">
                            Personal con cursos asignados
                        </div>
                    </div>
                </div>

                <!-- Completadas -->
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="training-kpi-card shadow-sm p-3 h-100">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="text-muted fw-semibold small text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.05em;">Completadas</span>
                            <div class="training-kpi-pill bg-emerald-subtle text-emerald">
                                <i class="bi bi-patch-check-fill"></i>
                            </div>
                        </div>
                        <div class="d-flex align-items-baseline gap-2">
                            <span class="h3 fw-bold mb-0 text-body">{{ totals.completed }}</span>
                            <span class="badge" :class="overallCompletionPercent > 0 ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary'">
                                {{ overallCompletionPercent }}% éxito
                            </span>
                        </div>
                        <div class="text-muted small mt-1" style="font-size: 0.75rem;">
                            Cursos aprobados con constancia
                        </div>
                    </div>
                </div>

                <!-- Vencidas -->
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="training-kpi-card shadow-sm p-3 h-100">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="text-muted fw-semibold small text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.05em;">Vencidas</span>
                            <div class="training-kpi-pill bg-danger-subtle text-danger">
                                <i class="bi bi-alarm-fill"></i>
                            </div>
                        </div>
                        <div class="d-flex align-items-baseline gap-2">
                            <span class="h3 fw-bold mb-0" :class="totals.overdue > 0 ? 'text-danger' : 'text-body'">{{ totals.overdue }}</span>
                            <span class="badge" :class="totals.overdue > 0 ? 'bg-danger-subtle text-danger' : 'bg-secondary-subtle text-secondary'">
                                {{ totals.overdue > 0 ? 'Requieren atención' : 'Al día' }}
                            </span>
                        </div>
                        <div class="text-muted small mt-1" style="font-size: 0.75rem;">
                            Fuera del plazo límite estipulado
                        </div>
                    </div>
                </div>

                <!-- Cursos en Catálogo -->
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="training-kpi-card shadow-sm p-3 h-100">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="text-muted fw-semibold small text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.05em;">Cursos en Catálogo</span>
                            <div class="training-kpi-pill bg-amber-subtle text-amber">
                                <i class="bi bi-journal-bookmark-fill"></i>
                            </div>
                        </div>
                        <div class="d-flex align-items-baseline gap-2">
                            <span class="h3 fw-bold mb-0 text-body">{{ courses.length }}</span>
                            <span class="text-muted small">cursos creados</span>
                        </div>
                        <div class="text-muted small mt-1" style="font-size: 0.75rem;">
                            {{ publishedCount }} publicados · {{ draftCount }} borradores
                        </div>
                    </div>
                </div>
            </div>

            <!-- =========================================================================
                 3. BARRA DE FILTROS SEGMENTADOS Y BÚSQUEDA INTERNA (SIN MODIFICAR URL)
                 ========================================================================= -->
            <div class="training-filter-card shadow-sm p-3 mb-4">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                    <!-- Control Segmentado Ejecutivo (Estilo Apple / Linear) -->
                    <div class="training-segmented-bar" role="tablist" aria-label="Filtro por estado de capacitación">
                        <button 
                            type="button" 
                            class="training-segmented-item"
                            :class="{ 'is-active': selectedStatus === 'all' }"
                            @click="setStatusFilter('all')"
                        >
                            <i class="bi bi-grid-fill me-1.5"></i>
                            <span>Todos</span>
                            <span class="training-segmented-pill">{{ courses.length }}</span>
                        </button>
                        <button 
                            type="button" 
                            class="training-segmented-item"
                            :class="{ 'is-active': selectedStatus === 'draft' }"
                            @click="setStatusFilter('draft')"
                        >
                            <i class="bi bi-pencil-square me-1.5 text-warning"></i>
                            <span>Borradores</span>
                            <span class="training-segmented-pill">{{ draftCount }}</span>
                        </button>
                        <button 
                            type="button" 
                            class="training-segmented-item"
                            :class="{ 'is-active': selectedStatus === 'published' }"
                            @click="setStatusFilter('published')"
                        >
                            <i class="bi bi-check-circle-fill me-1.5 text-success"></i>
                            <span>Publicadas</span>
                            <span class="training-segmented-pill">{{ publishedCount }}</span>
                        </button>
                        <button 
                            type="button" 
                            class="training-segmented-item"
                            :class="{ 'is-active': selectedStatus === 'archived' }"
                            @click="setStatusFilter('archived')"
                        >
                            <i class="bi bi-archive-fill me-1.5 text-secondary"></i>
                            <span>Archivadas</span>
                            <span class="training-segmented-pill">{{ archivedCount }}</span>
                        </button>
                    </div>

                    <!-- Buscador Predictivo y Filtro de Categoría Unificados -->
                    <div class="d-flex align-items-center flex-wrap" style="gap: 10px;">
                        <!-- Selector de Categoría -->
                        <div v-if="availableCategories.length > 1" style="min-width: 170px;">
                            <select 
                                v-model="selectedCategory" 
                                class="form-select form-select-sm"
                                style="height: 38px; border-radius: 8px; font-weight: 500; font-size: 0.85rem;"
                                aria-label="Filtrar por categoría"
                            >
                                <option value="">Todas las categorías</option>
                                <option v-for="cat in availableCategories" :key="cat" :value="cat">
                                    {{ cat }}
                                </option>
                            </select>
                        </div>

                        <!-- Input de Búsqueda Moderna Unificada -->
                        <div class="modern-input-wrapper" style="min-width: 260px; max-width: 320px;">
                            <i class="bi bi-search modern-input-icon"></i>
                            <input 
                                v-model="searchQuery" 
                                type="search" 
                                class="modern-input-control"
                                placeholder="Buscar curso o categoría..."
                                aria-label="Buscar capacitación"
                            >
                            <button 
                                v-if="searchQuery" 
                                type="button" 
                                class="btn btn-sm text-secondary p-0 me-2 border-0"
                                style="width: 22px; height: 22px;"
                                title="Limpiar búsqueda"
                                @click="searchQuery = ''"
                            >
                                <i class="bi bi-x-circle-fill"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- =========================================================================
                 4. TABLA PRINCIPAL DE CAPACITACIONES
                 ========================================================================= -->
            <div class="training-table-card shadow-sm mb-4">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3 py-3" style="width: 32%;">Capacitación</th>
                                <th class="py-3" style="width: 18%;">Categoría y Tipo</th>
                                <th class="py-3" style="width: 16%;">Contenido</th>
                                <th class="py-3" style="width: 20%;">Avance del Personal</th>
                                <th class="py-3" style="width: 14%;">Estado</th>
                                <th class="pe-3 py-3 text-end" style="width: 120px;">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="c in filteredCourses" :key="c.id">
                                <!-- Columna: Capacitación -->
                                <td class="ps-3 py-3">
                                    <div class="d-flex align-items-center" style="gap: 12px !important;">
                                        <div class="training-kpi-pill bg-primary-subtle text-primary">
                                            <i class="bi bi-book-half"></i>
                                        </div>
                                        <div class="min-w-0">
                                            <Link 
                                                :href="`/training/${c.id}`" 
                                                class="fw-bold text-decoration-none text-body d-block text-truncate"
                                                style="font-size: 0.925rem;"
                                                :title="c.title"
                                            >
                                                {{ c.title }}
                                            </Link>
                                            <div class="d-flex align-items-center flex-wrap gap-2 mt-1">
                                                <TenantBadge v-if="c.tenant_id" :tenant-id="c.tenant_id" />
                                                <span v-if="c.updated_at" class="small text-muted" style="font-size: 0.75rem;">
                                                    <i class="bi bi-clock-history me-1"></i>Act. {{ c.updated_at }}
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </td>

                                <!-- Columna: Categoría y Modalidad -->
                                <td class="py-3">
                                    <div class="d-flex flex-column align-items-start" style="gap: 4px !important;">
                                        <span class="badge rounded-2 bg-light text-dark border px-2 py-1 small fw-medium">
                                            {{ c.category }}
                                        </span>
                                        <span 
                                            v-if="c.is_mandatory" 
                                            class="badge rounded-2 bg-danger-subtle text-danger border border-danger-subtle px-2 py-0.5 small"
                                            style="font-size: 0.72rem;"
                                        >
                                            <i class="bi bi-exclamation-circle-fill me-1"></i>Obligatoria
                                        </span>
                                    </div>
                                </td>

                                <!-- Columna: Contenido -->
                                <td class="py-3">
                                    <div class="d-flex flex-column" style="gap: 2px !important;">
                                        <span class="small fw-semibold text-body">
                                            <i class="bi bi-collection-play me-1 text-primary"></i>{{ c.lessons }} lección{{ c.lessons === 1 ? '' : 'es' }}
                                        </span>
                                        <span v-if="c.questions" class="small text-muted">
                                            <i class="bi bi-patch-question me-1 text-info"></i>{{ c.questions }} preguntas eval.
                                        </span>
                                        <span v-else class="small text-muted">
                                            <i class="bi bi-check2 me-1 text-secondary"></i>Sin cuestionario
                                        </span>
                                    </div>
                                </td>

                                <!-- Columna: Avance del Personal -->
                                <td class="py-3" style="min-width: 180px;">
                                    <template v-if="c.assigned">
                                        <div class="d-flex justify-content-between small mb-1">
                                            <span class="text-secondary fw-medium">{{ c.completed }} de {{ c.assigned }} completados</span>
                                            <span class="fw-bold text-body">{{ c.completion }}%</span>
                                        </div>
                                        <div 
                                            class="progress shadow-none" 
                                            style="height: 7px; border-radius: 999px; background-color: rgba(148, 163, 184, 0.2);" 
                                            role="progressbar" 
                                            :aria-valuenow="c.completion" 
                                            aria-valuemin="0" 
                                            aria-valuemax="100" 
                                            :aria-label="`Avance de ${c.title}`"
                                        >
                                            <div 
                                                class="progress-bar" 
                                                :class="c.completion === 100 ? 'bg-success' : 'bg-primary'" 
                                                :style="{ width: `${c.completion}%`, borderRadius: '999px' }"
                                            ></div>
                                        </div>
                                        <div v-if="c.overdue" class="d-flex align-items-center small text-danger mt-1 fw-semibold" style="font-size: 0.75rem;">
                                            <i class="bi bi-alarm-fill" style="margin-right: 4px !important;"></i>
                                            <span>{{ c.overdue }} vencida(s)</span>
                                        </div>
                                    </template>
                                    <span v-else class="badge bg-light text-muted border px-2 py-1 small">
                                        Sin personal asignado
                                    </span>
                                </td>

                                <!-- Columna: Estado -->
                                <td class="py-3">
                                    <span 
                                        class="badge rounded-pill px-2.5 py-1 d-inline-flex align-items-center"
                                        :class="STATUS_CONFIG[c.status]?.badgeClass || 'bg-light text-secondary border'"
                                        style="font-size: 0.775rem;"
                                    >
                                        <span 
                                            class="rounded-circle me-1.5" 
                                            :class="STATUS_CONFIG[c.status]?.dotClass || 'bg-secondary'"
                                            style="width: 6px; height: 6px; display: inline-block;"
                                        ></span>
                                        {{ statuses[c.status] || c.status }}
                                    </span>
                                </td>

                                <!-- Columna: Acciones (Solo Iconos Elegantes con Tooltips) -->
                                <td class="pe-3 py-3 text-end text-nowrap">
                                    <div class="d-inline-flex align-items-center justify-content-end" style="gap: 8px !important;">
                                        <!-- Ver Detalle y Avance -->
                                        <Link 
                                            :href="`/training/${c.id}`" 
                                            class="btn-table-action btn-action-view" 
                                            title="Ver avance y detalle de colaboradores"
                                            aria-label="Ver avance y detalle de colaboradores"
                                        >
                                            <i class="bi bi-eye"></i>
                                        </Link>

                                        <!-- Editar Contenido -->
                                        <Link 
                                            :href="`/training/${c.id}/edit`" 
                                            class="btn-table-action btn-action-edit" 
                                            title="Editar lecciones, cuestionario y configuración"
                                            aria-label="Editar capacitación"
                                        >
                                            <i class="bi bi-pencil"></i>
                                        </Link>

                                        <!-- Publicar (si no está publicada) -->
                                        <button 
                                            v-if="c.status !== 'published'" 
                                            type="button" 
                                            class="btn-table-action text-success" 
                                            title="Publicar en app móvil"
                                            aria-label="Publicar capacitación"
                                            @click="publishCourse(c)"
                                        >
                                            <i class="bi bi-send-fill"></i>
                                        </button>

                                        <!-- Archivar (si está publicada) -->
                                        <button 
                                            v-if="c.status === 'published'" 
                                            type="button" 
                                            class="btn-table-action text-secondary" 
                                            title="Archivar capacitación (ocultar de la app)"
                                            aria-label="Archivar capacitación"
                                            @click="archiveCourse(c)"
                                        >
                                            <i class="bi bi-archive-fill"></i>
                                        </button>

                                        <!-- Eliminar -->
                                        <button 
                                            type="button" 
                                            class="btn-table-action btn-table-delete text-danger" 
                                            title="Eliminar capacitación"
                                            aria-label="Eliminar capacitación"
                                            @click="deleteCourse(c)"
                                        >
                                            <i class="bi bi-trash3"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>

                            <!-- Estado Vacío -->
                            <tr v-if="!filteredCourses.length">
                                <td colspan="6" class="text-center py-5">
                                    <div class="d-flex flex-column align-items-center justify-content-center p-4">
                                        <div 
                                            class="training-header-icon mb-3 opacity-75"
                                            style="width: 56px; height: 56px; font-size: 1.6rem;"
                                        >
                                            <i class="bi bi-mortarboard"></i>
                                        </div>
                                        <h6 class="fw-bold text-body mb-1">
                                            {{ searchQuery || selectedStatus !== 'all' ? 'No se encontraron capacitaciones' : 'Aún no hay capacitaciones registradas' }}
                                        </h6>
                                        <p class="text-secondary small mb-3" style="max-width: 420px;">
                                            {{ searchQuery || selectedStatus !== 'all'
                                                ? 'No hay cursos que coincidan con el estado o filtro seleccionado. Haz clic en "Todos" o limpia la búsqueda.' 
                                                : 'Crea tu primer curso corporativo: inducción laboral, seguridad y salud en el trabajo, atención al cliente o procedimientos internos.' 
                                            }}
                                        </p>
                                        <div class="d-flex align-items-center gap-2">
                                            <button 
                                                v-if="searchQuery || selectedStatus !== 'all' || selectedCategory" 
                                                type="button" 
                                                class="btn btn-sm btn-outline-secondary"
                                                @click="searchQuery = ''; selectedCategory = ''; selectedStatus = 'all';"
                                            >
                                                <i class="bi bi-x-circle me-1"></i>Ver todos los cursos
                                            </button>
                                            <Link 
                                                v-else
                                                href="/training/create" 
                                                class="btn btn-sm btn-primary d-inline-flex align-items-center"
                                                style="border-radius: 6px; font-weight: 600; padding: 6px 16px;"
                                            >
                                                <i class="bi bi-plus-lg" style="margin-right: 8px !important;"></i>
                                                <span>Crear primera capacitación</span>
                                            </Link>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
