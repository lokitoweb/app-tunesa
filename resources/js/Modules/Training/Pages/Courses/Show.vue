<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import Pagination from '@/Modules/Core/Components/Pagination.vue';
import { useConfirm } from '@/Modules/Core/Composables/useConfirm';

const props = defineProps({
    course: {
        type: Object,
        required: true,
    },
    stats: {
        type: Object,
        required: true,
    },
    enrollments: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        default: () => ({ status: '', search: '' }),
    },
    canUnlock: {
        type: Boolean,
        default: false,
    },
});

const { ask } = useConfirm();

const search = ref(props.filters.search ?? '');
const openHistory = ref(null);

// Modal de Desbloqueo de Intentos
const showUnlockModal = ref(false);
const selectedEnrollment = ref(null);
const extraAttempts = ref(1);
const isUnlocking = ref(false);

const STATUS_CONFIG = {
    completed: {
        label: 'Completado',
        badgeClass: 'bg-success-subtle text-success border border-success-subtle',
        dotClass: 'bg-success',
        icon: 'bi-check-circle-fill',
    },
    in_progress: {
        label: 'En progreso',
        badgeClass: 'bg-primary-subtle text-primary border border-primary-subtle',
        dotClass: 'bg-primary',
        icon: 'bi-play-circle-fill',
    },
    pending: {
        label: 'Pendiente',
        badgeClass: 'bg-secondary-subtle text-secondary border border-secondary-subtle',
        dotClass: 'bg-secondary',
        icon: 'bi-clock-fill',
    },
    overdue: {
        label: 'Vencido',
        badgeClass: 'bg-danger-subtle text-danger border border-danger-subtle',
        dotClass: 'bg-danger',
        icon: 'bi-alarm-fill',
    },
    locked: {
        label: 'Bloqueado',
        badgeClass: 'bg-warning-subtle text-warning-emphasis border border-warning-subtle',
        dotClass: 'bg-warning',
        icon: 'bi-lock-fill',
    },
};

const FILTERS = {
    '': { label: 'Todos', icon: 'bi-grid-fill' },
    pending: { label: 'Pendientes', icon: 'bi-clock-fill' },
    locked: { label: 'Bloqueados', icon: 'bi-lock-fill' },
    overdue: { label: 'Vencidos', icon: 'bi-alarm-fill' },
    completed: { label: 'Completados', icon: 'bi-check-circle-fill' },
};

function apply(status = props.filters.status) {
    router.get(
        `/training/${props.course.id}`,
        { status: status || undefined, search: search.value || undefined },
        { preserveState: true, preserveScroll: true }
    );
}

function clearSearch() {
    search.value = '';
    apply();
}

function openUnlockDialog(enrollment) {
    selectedEnrollment.value = enrollment;
    extraAttempts.value = 1;
    showUnlockModal.value = true;
}

function closeUnlockDialog() {
    showUnlockModal.value = false;
    selectedEnrollment.value = null;
}

function submitUnlock() {
    if (!selectedEnrollment.value) return;
    isUnlocking.value = true;
    router.post(
        `/training/${props.course.id}/enrollments/${selectedEnrollment.value.id}/unlock`,
        { extra: extraAttempts.value },
        {
            preserveScroll: true,
            onFinish: () => {
                isUnlocking.value = false;
                closeUnlockDialog();
            },
        }
    );
}

async function handlePublish() {
    const ok = await ask({
        title: '¿Publicar capacitación?',
        message: `¿Deseas publicar "${props.course.title}"? El curso aparecerá en la app móvil de los colaboradores asignados y se les enviará una notificación inmediata.`,
        confirmText: 'Publicar ahora',
        variant: 'primary',
        icon: 'bi bi-send-fill',
    });
    if (ok) {
        router.post(`/training/${props.course.id}/status`, { status: 'published' }, { preserveScroll: true });
    }
}

async function handleArchive() {
    const ok = await ask({
        title: '¿Archivar capacitación?',
        message: `La capacitación "${props.course.title}" dejará de ser visible en la app móvil. Los avances quedarán guardados.`,
        confirmText: 'Sí, archivar',
        variant: 'warning',
        icon: 'bi bi-archive-fill',
    });
    if (ok) {
        router.post(`/training/${props.course.id}/status`, { status: 'archived' }, { preserveScroll: true });
    }
}

async function handleDestroy() {
    const ok = await ask({
        title: '¿Eliminar capacitación?',
        message: `¿Estás seguro de eliminar "${props.course.title}"? Se perderán las lecciones, cuestionario y todo el avance registrado de los colaboradores.`,
        confirmText: 'Sí, eliminar',
        variant: 'danger',
        icon: 'bi bi-trash3-fill',
    });
    if (ok) {
        router.delete(`/training/${props.course.id}`);
    }
}

// Iniciales del colaborador para el avatar
function getInitials(name) {
    if (!name) return '??';
    const parts = name.trim().split(/\s+/);
    if (parts.length >= 2) {
        return (parts[0][0] + parts[1][0]).toUpperCase();
    }
    return name.slice(0, 2).toUpperCase();
}
</script>

<template>
    <AppLayout :title="course.title">
        <div class="training-page-wrapper">
            <!-- =========================================================================
                 1. CABECERA EJECUTIVA DEL CURSO CON NAVEGACIÓN
                 ========================================================================= -->
            <div class="training-header-card shadow-sm p-3 p-md-4 mb-4">
                <!-- Barra Superior: Botón Volver y Badges de Estado -->
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                    <Link href="/training" class="btn-executive-back">
                        <i class="bi bi-arrow-left" style="margin-right: 8px !important;"></i>
                        <span>Volver al catálogo</span>
                    </Link>
                    <div class="d-flex align-items-center gap-2">
                        <span 
                            class="badge rounded-pill px-3 py-1.5 fw-semibold d-inline-flex align-items-center"
                            :class="course.status === 'published' ? 'bg-success-subtle text-success border border-success-subtle' : (course.status === 'draft' ? 'bg-warning-subtle text-warning-emphasis border border-warning-subtle' : 'bg-secondary-subtle text-secondary border border-secondary-subtle')"
                        >
                            <span 
                                class="rounded-circle me-1.5" 
                                :class="course.status === 'published' ? 'bg-success' : (course.status === 'draft' ? 'bg-warning' : 'bg-secondary')"
                                style="width: 6px; height: 6px; display: inline-block;"
                            ></span>
                            {{ course.status === 'published' ? 'Publicada en App' : (course.status === 'draft' ? 'Borrador' : 'Archivada') }}
                        </span>
                        <span class="badge bg-light text-dark border px-2.5 py-1.5 fw-medium">
                            {{ course.category }}
                        </span>
                        <span v-if="course.is_mandatory" class="badge bg-danger-subtle text-danger border border-danger-subtle px-2.5 py-1.5 fw-semibold">
                            <i class="bi bi-exclamation-circle-fill me-1"></i>Obligatoria
                        </span>
                    </div>
                </div>

                <!-- Título y Acciones Principales -->
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                    <div class="d-flex align-items-center" style="gap: 16px !important;">
                        <div class="training-header-icon shadow-sm">
                            <i class="bi bi-mortarboard-fill"></i>
                        </div>
                        <div>
                            <h1 class="h4 fw-bold mb-1 text-body">{{ course.title }}</h1>
                            <!-- Parámetros Clave del Curso -->
                            <div class="d-flex align-items-center flex-wrap text-secondary small" style="gap: 12px !important;">
                                <span>
                                    <i class="bi bi-collection-play-fill text-primary" style="margin-right: 6px !important;"></i>
                                    {{ course.lessons }} lección{{ course.lessons === 1 ? '' : 'es' }}
                                </span>
                                <span v-if="course.questions">
                                    <i class="bi bi-patch-question-fill text-info" style="margin-right: 6px !important;"></i>
                                    Cuestionario de {{ course.questions }} preguntas (Aprueba con {{ course.passing_score }}% · {{ course.max_attempts }} intento{{ course.max_attempts === 1 ? '' : 's' }})
                                </span>
                                <span v-if="course.due_days">
                                    <i class="bi bi-calendar-event-fill text-warning" style="margin-right: 6px !important;"></i>
                                    Plazo de {{ course.due_days }} días
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Grupo de Botones de Acción -->
                    <div class="d-flex align-items-center flex-wrap" style="gap: 10px !important;">
                        <!-- Publicar / Archivar -->
                        <button 
                            v-if="course.status !== 'published'" 
                            type="button" 
                            class="btn btn-success d-inline-flex align-items-center shadow-sm"
                            style="height: 38px; border-radius: 6px; font-weight: 600; font-size: 0.85rem; padding: 0 1.25rem;"
                            @click="handlePublish"
                        >
                            <i class="bi bi-send-fill" style="margin-right: 8px !important;"></i>
                            <span>Publicar curso</span>
                        </button>
                        <button 
                            v-else 
                            type="button" 
                            class="btn btn-outline-secondary d-inline-flex align-items-center shadow-sm"
                            style="height: 38px; border-radius: 6px; font-weight: 600; font-size: 0.85rem; padding: 0 1.15rem;"
                            @click="handleArchive"
                        >
                            <i class="bi bi-archive-fill" style="margin-right: 8px !important;"></i>
                            <span>Archivar</span>
                        </button>

                        <!-- Editar Curso -->
                        <Link 
                            :href="`/training/${course.id}/edit`" 
                            class="btn btn-outline-primary d-inline-flex align-items-center shadow-sm"
                            style="height: 38px; border-radius: 6px; font-weight: 600; font-size: 0.85rem; padding: 0 1.15rem;"
                        >
                            <i class="bi bi-pencil-fill" style="margin-right: 8px !important;"></i>
                            <span>Editar</span>
                        </Link>

                        <!-- Exportar Avance CSV -->
                        <a 
                            :href="`/training/${course.id}/export`" 
                            class="btn btn-outline-secondary d-inline-flex align-items-center shadow-sm"
                            style="height: 38px; border-radius: 6px; font-weight: 600; font-size: 0.85rem; padding: 0 1.15rem;"
                        >
                            <i class="bi bi-download" style="margin-right: 8px !important;"></i>
                            <span>Exportar CSV</span>
                        </a>

                        <!-- Eliminar Curso -->
                        <button 
                            type="button" 
                            class="btn btn-outline-danger d-inline-flex align-items-center justify-content-center shadow-sm"
                            style="width: 38px; height: 38px; border-radius: 6px;"
                            title="Eliminar capacitación"
                            aria-label="Eliminar capacitación"
                            @click="handleDestroy"
                        >
                            <i class="bi bi-trash3-fill"></i>
                        </button>
                    </div>
                </div>
            </div>

            <!-- =========================================================================
                 ALERTA EJECUTIVA: COLABORADORES BLOQUEADOS POR INTENTOS
                 ========================================================================= -->
            <div 
                v-if="stats.locked" 
                class="alert alert-warning border border-warning-subtle shadow-sm p-3 mb-4 d-flex align-items-center justify-content-between flex-wrap gap-2"
                style="border-radius: 10px;"
            >
                <div class="d-flex align-items-center" style="gap: 12px !important;">
                    <div class="training-kpi-pill bg-warning-subtle text-warning-emphasis">
                        <i class="bi bi-lock-fill"></i>
                    </div>
                    <div>
                        <div class="fw-bold text-dark mb-0">
                            {{ stats.locked }} colaborador{{ stats.locked === 1 ? '' : 'es' }} agotó los intentos del cuestionario
                        </div>
                        <div class="small text-secondary">
                            Requiere que un supervisor o administrador autorice intentos adicionales para desbloquear su evaluación.
                        </div>
                    </div>
                </div>
                <button 
                    type="button" 
                    class="btn btn-sm btn-warning d-inline-flex align-items-center shadow-sm"
                    style="border-radius: 6px; font-weight: 600; padding: 6px 14px;"
                    @click="apply('locked')"
                >
                    <i class="bi bi-unlock-fill" style="margin-right: 6px !important;"></i>
                    <span>Ver colaboradores bloqueados</span>
                </button>
            </div>

            <!-- =========================================================================
                 2. TARJETAS DE MÉTRICAS KPI (RESUMEN EJECUTIVO DE AVANCE)
                 ========================================================================= -->
            <div class="row g-3 mb-4">
                <!-- Asignados -->
                <div class="col-6 col-lg-3">
                    <div class="training-kpi-card shadow-sm p-3 h-100">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="text-muted fw-semibold small text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.05em;">Asignados</span>
                            <div class="training-kpi-pill bg-primary-subtle text-primary">
                                <i class="bi bi-people-fill"></i>
                            </div>
                        </div>
                        <div class="d-flex align-items-baseline gap-2">
                            <span class="h3 fw-bold mb-0 text-body">{{ stats.assigned }}</span>
                            <span class="text-muted small">personas</span>
                        </div>
                        <div class="text-muted small mt-1" style="font-size: 0.75rem;">
                            En el público objetivo del curso
                        </div>
                    </div>
                </div>

                <!-- Completados -->
                <div class="col-6 col-lg-3">
                    <div class="training-kpi-card shadow-sm p-3 h-100">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="text-muted fw-semibold small text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.05em;">Completados</span>
                            <div class="training-kpi-pill bg-emerald-subtle text-emerald">
                                <i class="bi bi-patch-check-fill"></i>
                            </div>
                        </div>
                        <div class="d-flex align-items-baseline gap-2">
                            <span class="h3 fw-bold mb-0 text-body">{{ stats.completed }}</span>
                            <span class="badge bg-success-subtle text-success">
                                {{ stats.assigned > 0 ? Math.round((stats.completed / stats.assigned) * 100) : 0 }}% completado
                            </span>
                        </div>
                        <div class="text-muted small mt-1" style="font-size: 0.75rem;">
                            Lecciones vistas y test aprobado
                        </div>
                    </div>
                </div>

                <!-- Vencidos -->
                <div class="col-6 col-lg-3">
                    <div class="training-kpi-card shadow-sm p-3 h-100">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="text-muted fw-semibold small text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.05em;">Vencidos</span>
                            <div class="training-kpi-pill bg-danger-subtle text-danger">
                                <i class="bi bi-alarm-fill"></i>
                            </div>
                        </div>
                        <div class="d-flex align-items-baseline gap-2">
                            <span class="h3 fw-bold mb-0" :class="stats.overdue > 0 ? 'text-danger' : 'text-body'">{{ stats.overdue }}</span>
                            <span class="badge" :class="stats.overdue > 0 ? 'bg-danger-subtle text-danger' : 'bg-secondary-subtle text-secondary'">
                                {{ stats.overdue > 0 ? 'Plazo expirado' : 'Al día' }}
                            </span>
                        </div>
                        <div class="text-muted small mt-1" style="font-size: 0.75rem;">
                            Sin completar tras fecha límite
                        </div>
                    </div>
                </div>

                <!-- Nota Promedio -->
                <div class="col-6 col-lg-3">
                    <div class="training-kpi-card shadow-sm p-3 h-100">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="text-muted fw-semibold small text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.05em;">Nota Promedio</span>
                            <div class="training-kpi-pill bg-info-subtle text-info">
                                <i class="bi bi-award-fill"></i>
                            </div>
                        </div>
                        <div class="d-flex align-items-baseline gap-2">
                            <span class="h3 fw-bold mb-0 text-body font-monospace">{{ stats.avg_score ? stats.avg_score + '%' : '—' }}</span>
                            <span v-if="stats.avg_score" class="badge" :class="stats.avg_score >= course.passing_score ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger'">
                                {{ stats.avg_score >= course.passing_score ? 'Aprobatorio' : 'Bajo' }}
                            </span>
                        </div>
                        <div class="text-muted small mt-1" style="font-size: 0.75rem;">
                            Puntaje promedio en cuestionario
                        </div>
                    </div>
                </div>
            </div>

            <!-- =========================================================================
                 3. INFORMACIÓN DEL CURSO Y ALCANCE (AUDIENCE & DESCRIPTION)
                 ========================================================================= -->
            <div class="training-section-card shadow-sm p-3 p-md-4 mb-4">
                <div class="d-flex align-items-center mb-3" style="gap: 10px !important;">
                    <div class="training-kpi-pill bg-primary-subtle text-primary" style="width: 32px; height: 32px; font-size: 0.95rem;">
                        <i class="bi bi-bullseye"></i>
                    </div>
                    <h2 class="h6 fw-bold mb-0 text-body">Público Asignado y Alcance</h2>
                </div>

                <div v-if="course.audience.length" class="d-flex flex-wrap gap-2 mb-3">
                    <span 
                        v-for="a in course.audience" 
                        :key="a" 
                        class="badge rounded-2 bg-light text-dark border px-3 py-1.5 fw-medium"
                        style="font-size: 0.8rem;"
                    >
                        <i class="bi bi-check2-circle text-primary me-1"></i>{{ a }}
                    </span>
                </div>
                <div v-else class="text-muted small mb-3">
                    <i class="bi bi-info-circle me-1"></i>Nadie asignado todavía. Edita la capacitación para seleccionar sedes, áreas, cargos o colaboradores específicos.
                </div>

                <div v-if="course.description" class="pt-3 border-top">
                    <div class="small fw-semibold text-secondary mb-1">Descripción y objetivos formativos:</div>
                    <p class="small text-body mb-0" style="white-space: pre-line; line-height: 1.6;">
                        {{ course.description }}
                    </p>
                </div>
            </div>

            <!-- =========================================================================
                 4. TABLA DE AVANCE DE COLABORADORES CON FILTROS
                 ========================================================================= -->
            <div class="training-table-card shadow-sm mb-4">
                <!-- Barra de Filtros de la Tabla -->
                <div class="p-3 border-bottom d-flex flex-wrap align-items-center justify-content-between gap-3">
                    <!-- Tabs por Estado de Matrícula (Control Segmentado) -->
                    <div class="training-segmented-bar" role="tablist" aria-label="Filtrar por estado de avance">
                        <button 
                            v-for="(meta, key) in FILTERS" 
                            :key="key" 
                            type="button" 
                            class="training-segmented-item"
                            :class="{ 'is-active': (filters.status ?? '') === key }"
                            @click="apply(key)"
                        >
                            <i :class="meta.icon" class="me-1.5"></i>
                            <span>{{ meta.label }}</span>
                        </button>
                    </div>

                    <!-- Buscador Moderno de Colaboradores -->
                    <form class="d-flex align-items-center" style="gap: 8px;" @submit.prevent="apply()">
                        <div class="modern-input-wrapper" style="min-width: 240px; max-width: 300px;">
                            <i class="bi bi-search modern-input-icon"></i>
                            <input 
                                id="search" 
                                v-model="search" 
                                type="search" 
                                class="modern-input-control"
                                placeholder="Nombre o código..."
                                aria-label="Buscar colaborador"
                            >
                            <button 
                                v-if="search" 
                                type="button" 
                                class="btn btn-sm text-secondary p-0 me-2 border-0"
                                style="width: 22px; height: 22px;"
                                title="Limpiar búsqueda"
                                @click="clearSearch"
                            >
                                <i class="bi bi-x-circle-fill"></i>
                            </button>
                        </div>
                        <button 
                            type="submit" 
                            class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center"
                            style="height: 40px; border-radius: 8px; font-weight: 600; padding: 0 14px;"
                        >
                            <i class="bi bi-search" style="margin-right: 6px !important;"></i>
                            <span>Buscar</span>
                        </button>
                    </form>
                </div>

                <!-- Tabla de Matrículas -->
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3 py-3" style="width: 28%;">Colaborador</th>
                                <th class="py-3" style="width: 18%;">Sede / Área</th>
                                <th class="py-3" style="width: 13%;">Estado</th>
                                <th class="py-3" style="width: 15%;">Avance</th>
                                <th class="py-3" style="width: 14%;">Nota / Intentos</th>
                                <th class="py-3" style="width: 12%;">Fecha / Límite</th>
                                <th class="pe-3 py-3 text-end" style="width: 120px;">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="e in enrollments.data" :key="e.id">
                                <!-- Colaborador: Avatar + Nombre + Código + Cargo -->
                                <td class="ps-3 py-3">
                                    <div class="d-flex align-items-center" style="gap: 10px !important;">
                                        <div class="training-avatar bg-primary-subtle text-primary">
                                            {{ getInitials(e.employee) }}
                                        </div>
                                        <div class="min-w-0">
                                            <div class="fw-bold text-body text-truncate">{{ e.employee }}</div>
                                            <div class="small text-muted" style="font-size: 0.775rem;">
                                                <span v-if="e.code" class="font-monospace fw-semibold me-1">[{{ e.code }}]</span>
                                                <span>{{ e.position || 'Sin cargo especificado' }}</span>
                                            </div>
                                        </div>
                                    </div>
                                </td>

                                <!-- Sede / Área -->
                                <td class="py-3">
                                    <div class="small fw-medium text-body">
                                        <i class="bi bi-geo-alt me-1 text-secondary"></i>{{ e.branch || 'Sin sede' }}
                                    </div>
                                    <div v-if="e.department" class="small text-muted">
                                        <i class="bi bi-diagram-3 me-1 text-secondary"></i>{{ e.department }}
                                    </div>
                                </td>

                                <!-- Estado -->
                                <td class="py-3">
                                    <span 
                                        class="badge rounded-pill px-2.5 py-1 d-inline-flex align-items-center"
                                        :class="STATUS_CONFIG[e.status]?.badgeClass || 'bg-light text-secondary border'"
                                        style="font-size: 0.775rem;"
                                    >
                                        <span 
                                            class="rounded-circle me-1.5" 
                                            :class="STATUS_CONFIG[e.status]?.dotClass || 'bg-secondary'"
                                            style="width: 6px; height: 6px; display: inline-block;"
                                        ></span>
                                        {{ e.status_label }}
                                    </span>
                                </td>

                                <!-- Avance con Progress Bar -->
                                <td class="py-3" style="min-width: 130px;">
                                    <div class="d-flex justify-content-between small mb-1">
                                        <span class="text-secondary fw-semibold">{{ e.progress }}%</span>
                                    </div>
                                    <div 
                                        class="progress shadow-none" 
                                        style="height: 6px; border-radius: 999px; background-color: rgba(148, 163, 184, 0.2);"
                                        role="progressbar" 
                                        :aria-valuenow="e.progress" 
                                        aria-valuemin="0" 
                                        aria-valuemax="100" 
                                        :aria-label="`Avance de ${e.employee}`"
                                    >
                                        <div 
                                            class="progress-bar" 
                                            :class="e.status === 'completed' ? 'bg-success' : 'bg-primary'" 
                                            :style="{ width: `${e.progress}%`, borderRadius: '999px' }"
                                        ></div>
                                    </div>
                                </td>

                                <!-- Nota y Cuestionario (con Historial Desplegable) -->
                                <td class="py-3">
                                    <div class="small">
                                        <span class="fw-bold" :class="e.score !== null ? (e.score >= course.passing_score ? 'text-success' : 'text-danger') : 'text-muted'">
                                            {{ e.score !== null ? e.score + ' pts' : '—' }}
                                        </span>
                                        <span v-if="course.questions" class="text-muted ms-1" style="font-size: 0.75rem;">
                                            ({{ e.attempts }}/{{ e.max_attempts }} int.)
                                        </span>
                                    </div>

                                    <!-- Ver Historial de Intentos -->
                                    <div v-if="e.history && e.history.length" class="mt-1">
                                        <button 
                                            type="button" 
                                            class="btn btn-link btn-sm p-0 text-decoration-none small text-primary fw-medium"
                                            style="font-size: 0.75rem;"
                                            :aria-expanded="openHistory === e.id" 
                                            @click="openHistory = openHistory === e.id ? null : e.id"
                                        >
                                            <i class="bi" :class="openHistory === e.id ? 'bi-chevron-up' : 'bi-chevron-down'" style="margin-right: 4px !important;"></i>
                                            <span>{{ openHistory === e.id ? 'Ocultar intentos' : 'Ver intentos' }}</span>
                                        </button>
                                        <div v-if="openHistory === e.id" class="p-2 mt-1 rounded bg-light border small">
                                            <div 
                                                v-for="(a, i) in e.history" 
                                                :key="i" 
                                                class="d-flex align-items-center justify-content-between py-1 border-bottom last-border-0"
                                                style="font-size: 0.725rem;"
                                            >
                                                <div class="d-flex align-items-center">
                                                    <i 
                                                        class="bi" 
                                                        :class="a.passed ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger'"
                                                        style="margin-right: 6px !important;"
                                                    ></i>
                                                    <span class="fw-bold me-1">{{ a.score }} pts</span>
                                                    <span class="text-muted">({{ a.passed ? 'Aprobó' : 'Reprobó' }})</span>
                                                </div>
                                                <span class="text-secondary">{{ a.at }}</span>
                                            </div>
                                        </div>
                                    </div>
                                </td>

                                <!-- Fecha Límite / Completado -->
                                <td class="py-3">
                                    <div v-if="e.completed_at" class="small text-success fw-medium">
                                        <i class="bi bi-calendar-check me-1"></i>{{ e.completed_at }}
                                    </div>
                                    <div v-else-if="e.due_date" class="small" :class="e.status === 'overdue' ? 'text-danger fw-bold' : 'text-body'">
                                        <i class="bi bi-calendar-x me-1"></i>{{ e.due_date }}
                                    </div>
                                    <div v-else class="small text-muted">
                                        Sin plazo límite
                                    </div>
                                </td>

                                <!-- Acciones: Desbloquear / Ver Constancia -->
                                <td class="pe-3 py-3 text-end text-nowrap">
                                    <div class="d-inline-flex align-items-center justify-content-end" style="gap: 8px !important;">
                                        <!-- Botón Desbloquear (Modal) -->
                                        <button 
                                            v-if="e.locked && canUnlock" 
                                            type="button" 
                                            class="btn btn-sm btn-warning d-inline-flex align-items-center shadow-sm"
                                            style="height: 32px; border-radius: 6px; font-weight: 600; font-size: 0.775rem; padding: 0 10px;"
                                            title="Desbloquear intentos para este colaborador"
                                            @click="openUnlockDialog(e)"
                                        >
                                            <i class="bi bi-unlock-fill" style="margin-right: 6px !important;"></i>
                                            <span>Desbloquear</span>
                                        </button>

                                        <!-- Ver Constancia PDF (Aprobados) -->
                                        <a 
                                            v-if="e.status === 'completed'" 
                                            :href="`/training/${course.id}/certificates/${e.id}`" 
                                            target="_blank" 
                                            rel="noopener" 
                                            class="btn-table-action btn-action-view" 
                                            title="Descargar o ver constancia PDF oficial"
                                            aria-label="Ver constancia de capacitación"
                                        >
                                            <i class="bi bi-file-earmark-pdf-fill text-danger"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>

                            <!-- Sin Resultados -->
                            <tr v-if="!enrollments.data.length">
                                <td colspan="7" class="text-center py-5">
                                    <div class="d-flex flex-column align-items-center justify-content-center p-4">
                                        <div class="training-header-icon mb-3 opacity-75" style="width: 52px; height: 52px; font-size: 1.5rem;">
                                            <i class="bi bi-people"></i>
                                        </div>
                                        <h6 class="fw-bold text-body mb-1">
                                            {{ course.status === 'published' ? 'No se encontraron colaboradores' : 'Capacitación aún no asignada' }}
                                        </h6>
                                        <p class="text-secondary small mb-3" style="max-width: 400px;">
                                            {{ course.status === 'published' 
                                                ? 'No hay registros que coincidan con los filtros de estado o término de búsqueda ingresado.' 
                                                : 'Debes publicar la capacitación para que se matricule automáticamente al personal según las sedes, áreas o cargos seleccionados.' 
                                            }}
                                        </p>
                                        <button 
                                            v-if="course.status !== 'published'" 
                                            type="button" 
                                            class="btn btn-sm btn-primary d-inline-flex align-items-center"
                                            style="border-radius: 6px; font-weight: 600; padding: 6px 16px;"
                                            @click="handlePublish"
                                        >
                                            <i class="bi bi-send-fill" style="margin-right: 8px !important;"></i>
                                            <span>Publicar capacitación ahora</span>
                                        </button>
                                        <button 
                                            v-else-if="filters.status || filters.search" 
                                            type="button" 
                                            class="btn btn-sm btn-outline-secondary"
                                            @click="clearSearch"
                                        >
                                            <i class="bi bi-x-circle me-1"></i>Limpiar filtros
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Paginación -->
                <div v-if="enrollments.data.length" class="p-3 border-top bg-light-subtle d-flex justify-content-end">
                    <Pagination :paginator="enrollments" />
                </div>
            </div>

            <!-- =========================================================================
                 MODAL DIALOG: DESBLOQUEAR INTENTOS PARA COLABORADOR
                 ========================================================================= -->
            <div 
                v-if="showUnlockModal" 
                class="modal fade show d-block" 
                tabindex="-1" 
                style="background: rgba(15, 23, 42, 0.65); backdrop-filter: blur(4px);"
                role="dialog"
                aria-modal="true"
            >
                <div class="modal-dialog modal-dialog-centered" style="max-width: 480px;">
                    <div class="modal-content shadow-lg border" style="border-radius: 12px; overflow: hidden;">
                        <div class="modal-header border-bottom p-3 p-md-4">
                            <div class="d-flex align-items-center" style="gap: 12px !important;">
                                <div class="training-kpi-pill bg-warning-subtle text-warning-emphasis">
                                    <i class="bi bi-unlock-fill"></i>
                                </div>
                                <div>
                                    <h5 class="modal-title fw-bold text-dark mb-0">Desbloquear Cuestionario</h5>
                                    <span class="small text-secondary">Otorgar intentos adicionales</span>
                                </div>
                            </div>
                            <button type="button" class="btn-close" aria-label="Cerrar" @click="closeUnlockDialog"></button>
                        </div>
                        <div class="modal-body p-3 p-md-4">
                            <p class="small text-secondary mb-3">
                                El colaborador <strong>{{ selectedEnrollment?.employee }}</strong> agotó los intentos permitidos en el cuestionario evaluativo.
                            </p>

                            <div class="p-3 rounded mb-3 bg-light border">
                                <label for="modalExtraAttempts" class="form-label small fw-bold text-dark mb-2">
                                    ¿Cuántos intentos adicionales deseas conceder?
                                </label>
                                <div class="d-flex align-items-center gap-2">
                                    <button 
                                        v-for="n in [1, 2, 3, 5]" 
                                        :key="n"
                                        type="button" 
                                        class="btn btn-sm flex-fill"
                                        :class="extraAttempts === n ? 'btn-warning text-dark fw-bold' : 'btn-outline-secondary'"
                                        style="height: 38px; border-radius: 6px;"
                                        @click="extraAttempts = n"
                                    >
                                        +{{ n }} intento{{ n === 1 ? '' : 's' }}
                                    </button>
                                </div>
                            </div>

                            <div class="small text-muted d-flex align-items-start" style="gap: 8px;">
                                <i class="bi bi-info-circle-fill text-primary mt-0.5"></i>
                                <span>Al desbloquear, el colaborador recibirá una notificación push en la app móvil informándole que puede volver a rendir la evaluación.</span>
                            </div>
                        </div>
                        <div class="modal-footer border-top p-3 d-flex justify-content-end" style="gap: 10px;">
                            <button 
                                type="button" 
                                class="btn btn-outline-secondary" 
                                style="border-radius: 6px; font-weight: 500; font-size: 0.85rem;"
                                :disabled="isUnlocking"
                                @click="closeUnlockDialog"
                            >
                                Cancelar
                            </button>
                            <button 
                                type="button" 
                                class="btn btn-warning text-dark d-inline-flex align-items-center" 
                                style="border-radius: 6px; font-weight: 600; font-size: 0.85rem; gap: 6px !important;"
                                :disabled="isUnlocking"
                                @click="submitUnlock"
                            >
                                <span v-if="isUnlocking" class="spinner-border spinner-border-sm me-1" role="status"></span>
                                <i v-else class="bi bi-unlock-fill"></i>
                                <span>Confirmar y desbloquear</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
