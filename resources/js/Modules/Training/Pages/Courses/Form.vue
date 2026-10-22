<script setup>
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';

const props = defineProps({
    course: {
        type: Object,
        required: true,
    },
    categories: {
        type: Object,
        required: true,
    },
    lessonTypes: {
        type: Object,
        required: true,
    },
    branches: {
        type: Array,
        default: () => [],
    },
    departments: {
        type: Array,
        default: () => [],
    },
    positions: {
        type: Array,
        default: () => [],
    },
    employees: {
        type: Array,
        default: () => [],
    },
});

const editing = props.course.id !== null;
const form = useForm({ ...props.course });

const TARGET_TYPES = {
    all: 'Todo el personal',
    branch: 'Por sucursal',
    department: 'Por área / departamento',
    position: 'Por cargo',
    user: 'Colaborador individual',
};

const LESSON_ICONS = {
    text: 'bi-file-text-fill text-primary',
    video: 'bi-play-btn-fill text-danger',
    pdf: 'bi-file-earmark-pdf-fill text-warning',
    link: 'bi-link-45deg text-info',
};

const hasAll = computed(() => form.targets.some((t) => t.type === 'all'));

function addLesson(type = 'text') {
    form.lessons.push({
        id: null,
        title: '',
        type,
        content: '',
        url: '',
        duration_minutes: null,
        file_name: null,
        file: null,
    });
}

function moveLesson(i, delta) {
    const j = i + delta;
    if (j < 0 || j >= form.lessons.length) return;
    const [l] = form.lessons.splice(i, 1);
    form.lessons.splice(j, 0, l);
}

function addQuestion() {
    form.questions.push({
        question: '',
        options: ['', '', ''],
        correct_option: 0,
    });
}

function addTarget() {
    form.targets.push({
        type: 'branch',
        id: props.branches[0]?.id ?? null,
        value: null,
    });
}

function onTargetType(t) {
    t.id = t.type === 'branch'
        ? props.branches[0]?.id ?? null
        : t.type === 'department'
            ? props.departments[0]?.id ?? null
            : t.type === 'user'
                ? props.employees[0]?.id ?? null
                : null;
    t.value = t.type === 'position' ? props.positions[0] ?? '' : null;
}

function err(key) {
    return form.errors[key];
}

function submit(status) {
    if (status) form.status = status;
    const options = { forceFormData: true, preserveScroll: true };
    if (editing) {
        form.transform((data) => ({ ...data, _method: 'put' })).post(`/training/${props.course.id}`, options);
    } else {
        form.post('/training', options);
    }
}
</script>

<template>
    <AppLayout :title="editing ? 'Editar capacitación' : 'Nueva capacitación'">
        <div class="training-page-wrapper">
            <!-- =========================================================================
                 1. CABECERA EJECUTIVA DEL CREADOR / EDITOR (LIMPIA, SIN BADGES REDUNDANTES)
                 ========================================================================= -->
            <div class="training-header-card shadow-sm p-3 p-md-4 mb-4">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                    <Link href="/training" class="btn-executive-back">
                        <i class="bi bi-arrow-left" style="margin-right: 8px !important;"></i>
                        <span>Volver al catálogo</span>
                    </Link>
                    <span v-if="editing && form.status" class="badge rounded-pill px-2.5 py-1 text-secondary bg-light border">
                        Estado: {{ form.status }}
                    </span>
                </div>

                <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                    <div class="d-flex align-items-center" style="gap: 16px !important;">
                        <div class="training-header-icon shadow-sm">
                            <i :class="editing ? 'bi bi-pencil-square' : 'bi bi-mortarboard-fill'"></i>
                        </div>
                        <div>
                            <h1 class="h4 fw-bold mb-1 text-body">
                                {{ editing ? `Editar: ${course.title}` : 'Crear Nueva Capacitación' }}
                            </h1>
                            <p class="text-secondary small mb-0">
                                Configura el contenido didáctico, lecciones multimedia, cuestionario evaluativo y público objetivo asignado.
                            </p>
                        </div>
                    </div>

                    <!-- Botones de Acción Superiores -->
                    <div class="d-flex align-items-center flex-wrap" style="gap: 10px !important;">
                        <Link 
                            href="/training" 
                            class="btn btn-outline-secondary d-inline-flex align-items-center"
                            style="height: 40px; border-radius: 8px; font-weight: 500; font-size: 0.85rem; padding: 0 1rem;"
                        >
                            Cancelar
                        </Link>
                        <button 
                            type="button" 
                            class="btn btn-outline-primary d-inline-flex align-items-center"
                            style="height: 40px; border-radius: 8px; font-weight: 600; font-size: 0.85rem; padding: 0 1.15rem;"
                            :disabled="form.processing"
                            @click="submit(course.status === 'published' ? 'published' : 'draft')"
                        >
                            <span v-if="form.processing" class="spinner-border spinner-border-sm me-2" role="status"></span>
                            <i v-else class="bi bi-file-earmark-check" style="margin-right: 8px !important;"></i>
                            <span>{{ course.status === 'published' ? 'Guardar cambios' : 'Guardar borrador' }}</span>
                        </button>
                        <button 
                            v-if="course.status !== 'published'" 
                            type="button" 
                            class="btn btn-primary d-inline-flex align-items-center shadow-sm"
                            style="height: 40px; border-radius: 8px; font-weight: 600; font-size: 0.85rem; padding: 0 1.25rem;"
                            :disabled="form.processing"
                            @click="submit('published')"
                        >
                            <span v-if="form.processing" class="spinner-border spinner-border-sm me-2" role="status"></span>
                            <i v-else class="bi bi-send-fill" style="margin-right: 8px !important;"></i>
                            <span>Guardar y publicar</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- =========================================================================
                 FORMULARIO PRINCIPAL
                 ========================================================================= -->
            <form @submit.prevent="submit()">
                <!-- SECCIÓN 1: DATOS GENERALES Y PARÁMETROS -->
                <div class="training-section-card shadow-sm p-3 p-md-4 mb-4">
                    <div class="d-flex align-items-center mb-3" style="gap: 12px !important;">
                        <span class="badge rounded-circle bg-primary text-white d-flex align-items-center justify-content-center" style="width: 28px; height: 28px; font-size: 0.85rem;">
                            1
                        </span>
                        <div>
                            <h2 class="h6 fw-bold mb-0 text-body">Datos Generales y Configuración</h2>
                            <span class="small text-secondary">Título, categoría y parámetros de aprobación</span>
                        </div>
                    </div>

                    <div class="row g-3">
                        <!-- Título -->
                        <div class="col-md-8">
                            <label for="title" class="modern-field-label">
                                Título de la capacitación <span class="required-star">*</span>
                            </label>
                            <div class="modern-input-wrapper">
                                <i class="bi bi-journal-text modern-input-icon"></i>
                                <input 
                                    id="title" 
                                    v-model="form.title" 
                                    class="modern-input-control" 
                                    :class="{ 'is-invalid': err('title') }" 
                                    maxlength="200" 
                                    required 
                                    placeholder="Ej.: Inducción y Políticas de Seguridad 2026"
                                >
                            </div>
                            <div v-if="err('title')" class="invalid-feedback d-block">{{ err('title') }}</div>
                        </div>

                        <!-- Categoría -->
                        <div class="col-md-4">
                            <label for="category" class="modern-field-label">
                                Categoría <span class="required-star">*</span>
                            </label>
                            <select 
                                id="category" 
                                v-model="form.category" 
                                class="form-select"
                                style="height: 40px; border-radius: 8px; font-weight: 500; font-size: 0.875rem;"
                            >
                                <option v-for="(label, key) in categories" :key="key" :value="key">
                                    {{ label }}
                                </option>
                            </select>
                        </div>

                        <!-- Descripción -->
                        <div class="col-12">
                            <label for="description" class="modern-field-label">
                                Descripción y Objetivos Formativos
                            </label>
                            <textarea 
                                id="description" 
                                v-model="form.description" 
                                class="form-control" 
                                rows="3" 
                                maxlength="5000" 
                                style="border-radius: 8px; font-size: 0.875rem;"
                                placeholder="Indica brevemente de qué trata este curso, por qué es importante y qué competencias desarrollará el colaborador..."
                            ></textarea>
                        </div>

                        <!-- 4 CAMPOS MODERNOS UNIFICADOS (SIN INPUT-GROUPS CLUNKY) -->
                        <!-- Duración estimada -->
                        <div class="col-6 col-md-3">
                            <label for="estimated_minutes" class="modern-field-label">
                                Duración estimada
                            </label>
                            <div class="modern-input-wrapper">
                                <i class="bi bi-clock-history modern-input-icon"></i>
                                <input 
                                    id="estimated_minutes" 
                                    v-model="form.estimated_minutes" 
                                    type="number" 
                                    min="1" 
                                    max="2000" 
                                    class="modern-input-control" 
                                    :class="{ 'is-invalid': err('estimated_minutes') }"
                                    placeholder="Ej.: 45"
                                >
                                <span class="modern-input-suffix">min</span>
                            </div>
                            <div v-if="err('estimated_minutes')" class="invalid-feedback d-block">{{ err('estimated_minutes') }}</div>
                        </div>

                        <!-- Plazo de días -->
                        <div class="col-6 col-md-3">
                            <label for="due_days" class="modern-field-label">
                                Plazo de días
                            </label>
                            <div class="modern-input-wrapper">
                                <i class="bi bi-calendar-event modern-input-icon"></i>
                                <input 
                                    id="due_days" 
                                    v-model="form.due_days" 
                                    type="number" 
                                    min="1" 
                                    max="365" 
                                    class="modern-input-control" 
                                    :class="{ 'is-invalid': err('due_days') }" 
                                    placeholder="Ej.: 15"
                                >
                                <span class="modern-input-suffix">días</span>
                            </div>
                            <div class="form-text small" style="font-size: 0.725rem; margin-top: 3px;">Vacío = sin vencimiento.</div>
                            <div v-if="err('due_days')" class="invalid-feedback d-block">{{ err('due_days') }}</div>
                        </div>

                        <!-- Nota aprobatoria -->
                        <div class="col-6 col-md-3">
                            <label for="passing_score" class="modern-field-label">
                                Nota aprobatoria <span class="required-star">*</span>
                            </label>
                            <div class="modern-input-wrapper">
                                <i class="bi bi-award-fill modern-input-icon"></i>
                                <input 
                                    id="passing_score" 
                                    v-model="form.passing_score" 
                                    type="number" 
                                    min="1" 
                                    max="100" 
                                    class="modern-input-control" 
                                    :class="{ 'is-invalid': err('passing_score') }" 
                                    required
                                    placeholder="70"
                                >
                                <span class="modern-input-suffix">% min</span>
                            </div>
                            <div v-if="err('passing_score')" class="invalid-feedback d-block">{{ err('passing_score') }}</div>
                        </div>

                        <!-- Intentos del cuestionario -->
                        <div class="col-6 col-md-3">
                            <label for="max_attempts" class="modern-field-label">
                                Intentos permitidos <span class="required-star">*</span>
                            </label>
                            <div class="modern-input-wrapper">
                                <i class="bi bi-arrow-repeat modern-input-icon"></i>
                                <input 
                                    id="max_attempts" 
                                    v-model="form.max_attempts" 
                                    type="number" 
                                    min="1" 
                                    max="10" 
                                    class="modern-input-control" 
                                    :class="{ 'is-invalid': err('max_attempts') }" 
                                    required
                                    placeholder="3"
                                >
                                <span class="modern-input-suffix">int.</span>
                            </div>
                            <div class="form-text small" style="font-size: 0.725rem; margin-top: 3px;">Al agotarse se bloquea.</div>
                            <div v-if="err('max_attempts')" class="invalid-feedback d-block">{{ err('max_attempts') }}</div>
                        </div>

                        <!-- Switch de Obligatoria -->
                        <div class="col-12 mt-2">
                            <div 
                                class="payslip-executive-switch-card p-3 d-flex align-items-center justify-content-between"
                                :class="{ 'is-active': form.is_mandatory }"
                                style="max-width: 520px; border-radius: 8px;"
                            >
                                <div class="d-flex align-items-center" style="gap: 12px !important;">
                                    <div class="training-kpi-pill" :class="form.is_mandatory ? 'bg-danger-subtle text-danger' : 'bg-secondary-subtle text-secondary'">
                                        <i class="bi bi-exclamation-circle-fill"></i>
                                    </div>
                                    <div>
                                        <label for="is_mandatory" class="fw-bold text-dark mb-0 cursor-pointer" style="font-size: 0.875rem;">
                                            Capacitación de Cumplimiento Obligatorio
                                        </label>
                                        <div class="small text-secondary" style="font-size: 0.775rem;">
                                            Resaltará en la app del colaborador como requisito prioritario de la empresa.
                                        </div>
                                    </div>
                                </div>
                                <div class="form-check form-switch m-0 ps-0">
                                    <input 
                                        id="is_mandatory" 
                                        v-model="form.is_mandatory" 
                                        class="form-check-input custom-executive-switch" 
                                        type="checkbox" 
                                        role="switch"
                                    >
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- SECCIÓN 2: LECCIONES Y CONTENIDO -->
                <div class="training-section-card shadow-sm p-3 p-md-4 mb-4">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
                        <div class="d-flex align-items-center" style="gap: 12px !important;">
                            <span class="badge rounded-circle bg-primary text-white d-flex align-items-center justify-content-center" style="width: 28px; height: 28px; font-size: 0.85rem;">
                                2
                            </span>
                            <div>
                                <h2 class="h6 fw-bold mb-0 text-body">Lecciones y Contenido</h2>
                                <span class="small text-secondary">Agrega textos, videos de YouTube/Vimeo, documentos PDF o enlaces</span>
                            </div>
                        </div>

                        <!-- Botones para agregar según tipo de lección con espaciado garantizado -->
                        <div class="d-flex flex-wrap gap-2">
                            <button 
                                v-for="(label, type) in lessonTypes" 
                                :key="type" 
                                type="button" 
                                class="btn btn-sm btn-outline-primary d-inline-flex align-items-center shadow-sm"
                                style="border-radius: 8px; font-weight: 600; padding: 6px 14px;"
                                @click="addLesson(type)"
                            >
                                <i class="bi" :class="LESSON_ICONS[type]" style="margin-right: 8px !important;"></i>
                                <span>+ {{ label }}</span>
                            </button>
                        </div>
                    </div>

                    <div v-if="err('lessons')" class="alert alert-danger py-2 small d-flex align-items-center mb-3">
                        <i class="bi bi-exclamation-triangle-fill" style="margin-right: 8px !important;"></i>
                        <span>{{ err('lessons') }}</span>
                    </div>

                    <!-- Lista de Lecciones -->
                    <div 
                        v-for="(lesson, i) in form.lessons" 
                        :key="i" 
                        class="training-item-box p-3 mb-3"
                    >
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                            <div class="d-flex align-items-center" style="gap: 8px !important;">
                                <span class="badge rounded-2 bg-dark text-white px-2 py-1 small fw-bold">
                                    Lección {{ i + 1 }}
                                </span>
                                <span class="badge bg-light text-secondary border small">
                                    <i class="bi" :class="LESSON_ICONS[lesson.type]" style="margin-right: 4px !important;"></i>
                                    {{ lessonTypes[lesson.type] }}
                                </span>
                            </div>

                            <div class="d-flex align-items-center" style="gap: 6px !important;">
                                <button 
                                    type="button" 
                                    class="btn-table-action" 
                                    :disabled="i === 0" 
                                    title="Subir lección"
                                    aria-label="Subir lección"
                                    @click="moveLesson(i, -1)"
                                >
                                    <i class="bi bi-arrow-up"></i>
                                </button>
                                <button 
                                    type="button" 
                                    class="btn-table-action" 
                                    :disabled="i === form.lessons.length - 1" 
                                    title="Bajar lección"
                                    aria-label="Bajar lección"
                                    @click="moveLesson(i, 1)"
                                >
                                    <i class="bi bi-arrow-down"></i>
                                </button>
                                <button 
                                    type="button" 
                                    class="btn-table-action btn-table-delete text-danger" 
                                    title="Quitar lección"
                                    aria-label="Quitar lección"
                                    @click="form.lessons.splice(i, 1)"
                                >
                                    <i class="bi bi-trash3"></i>
                                </button>
                            </div>
                        </div>

                        <div class="row g-2 mb-2">
                            <div class="col-md-9">
                                <label :for="`lesson_title_${i}`" class="modern-field-label">
                                    Título de la lección <span class="required-star">*</span>
                                </label>
                                <div class="modern-input-wrapper">
                                    <i class="bi bi-play-circle modern-input-icon"></i>
                                    <input 
                                        :id="`lesson_title_${i}`" 
                                        v-model="lesson.title" 
                                        class="modern-input-control" 
                                        :class="{ 'is-invalid': err(`lessons.${i}.title`) }" 
                                        placeholder="Ej.: Introducción al protocolo de evacuación" 
                                        maxlength="200" 
                                        required
                                    >
                                </div>
                            </div>
                            <div class="col-md-3">
                                <label :for="`lesson_min_${i}`" class="modern-field-label">
                                    Minutos (opcional)
                                </label>
                                <div class="modern-input-wrapper">
                                    <i class="bi bi-clock modern-input-icon"></i>
                                    <input 
                                        :id="`lesson_min_${i}`" 
                                        v-model="lesson.duration_minutes" 
                                        type="number" 
                                        min="1" 
                                        max="600" 
                                        class="modern-input-control"
                                        placeholder="10"
                                    >
                                    <span class="modern-input-suffix">min</span>
                                </div>
                            </div>
                        </div>

                        <!-- Contenido Específico según Tipo -->
                        <!-- Texto -->
                        <div v-if="lesson.type === 'text'">
                            <label :for="`lesson_content_${i}`" class="modern-field-label">
                                Contenido didáctico <span class="required-star">*</span>
                            </label>
                            <textarea 
                                :id="`lesson_content_${i}`" 
                                v-model="lesson.content" 
                                class="form-control" 
                                :class="{ 'is-invalid': err(`lessons.${i}.content`) }" 
                                rows="4" 
                                style="border-radius: 8px; font-size: 0.875rem;"
                                placeholder="Escribe el texto de la lección para que el colaborador lo lea..."
                            ></textarea>
                            <div class="invalid-feedback">{{ err(`lessons.${i}.content`) }}</div>
                        </div>

                        <!-- PDF -->
                        <div v-else-if="lesson.type === 'pdf'">
                            <label :for="`lesson_file_${i}`" class="modern-field-label">
                                {{ lesson.file_name ? `Archivo actual: "${lesson.file_name}". Sube otro para reemplazarlo:` : 'Documento PDF (máximo 20 MB) *' }}
                            </label>
                            <input 
                                :id="`lesson_file_${i}`" 
                                type="file" 
                                accept="application/pdf" 
                                class="form-control" 
                                style="border-radius: 8px;"
                                :class="{ 'is-invalid': err(`lessons.${i}.file`) }" 
                                @input="lesson.file = $event.target.files[0]"
                            >
                            <div class="invalid-feedback">{{ err(`lessons.${i}.file`) }}</div>
                            <a 
                                v-if="lesson.id && lesson.file_name" 
                                :href="`/training/${course.id}/lessons/${lesson.id}/file`" 
                                target="_blank" 
                                rel="noopener" 
                                class="small d-inline-flex align-items-center mt-1 text-primary fw-medium"
                            >
                                <i class="bi bi-file-earmark-pdf-fill me-1 text-danger"></i>
                                <span>Ver PDF actual cargado</span>
                            </a>
                        </div>

                        <!-- Video o Enlace -->
                        <div v-else>
                            <label :for="`lesson_url_${i}`" class="modern-field-label">
                                {{ lesson.type === 'video' ? 'Enlace del video (YouTube, Vimeo, etc.) *' : 'Enlace web externo *' }}
                            </label>
                            <div class="modern-input-wrapper">
                                <i class="bi" :class="lesson.type === 'video' ? 'bi-youtube text-danger' : 'bi-link-45deg text-info'" style="position: absolute; left: 12px; font-size: 1rem;"></i>
                                <input 
                                    :id="`lesson_url_${i}`" 
                                    v-model="lesson.url" 
                                    type="url" 
                                    class="modern-input-control" 
                                    :class="{ 'is-invalid': err(`lessons.${i}.url`) }" 
                                    :placeholder="lesson.type === 'video' ? 'https://www.youtube.com/watch?v=...' : 'https://ejemplo.com/material'"
                                >
                            </div>
                            <div class="invalid-feedback d-block">{{ err(`lessons.${i}.url`) }}</div>
                        </div>
                    </div>

                    <!-- Si no hay lecciones aún -->
                    <div v-if="!form.lessons.length" class="text-center py-4 bg-light rounded border">
                        <i class="bi bi-collection-play fs-2 d-block mb-1 text-secondary opacity-50"></i>
                        <div class="fw-semibold text-body">Aún no hay lecciones agregadas</div>
                        <p class="small text-muted mb-0">Usa los botones superiores para incorporar módulos de lectura, videos o documentos PDF.</p>
                    </div>
                </div>

                <!-- SECCIÓN 3: CUESTIONARIO EVALUATIVO -->
                <div class="training-section-card shadow-sm p-3 p-md-4 mb-4">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
                        <div class="d-flex align-items-center" style="gap: 12px !important;">
                            <span class="badge rounded-circle bg-primary text-white d-flex align-items-center justify-content-center" style="width: 28px; height: 28px; font-size: 0.85rem;">
                                3
                            </span>
                            <div>
                                <h2 class="h6 fw-bold mb-0 text-body">
                                    Cuestionario Evaluativo 
                                    <span class="fw-normal text-secondary small">(Opcional)</span>
                                </h2>
                                <span class="small text-secondary">Preguntas de opción múltiple para evaluar la asimilación del curso</span>
                            </div>
                        </div>

                        <button 
                            type="button" 
                            class="btn btn-sm btn-outline-primary d-inline-flex align-items-center shadow-sm"
                            style="border-radius: 8px; font-weight: 600; padding: 6px 14px;"
                            @click="addQuestion"
                        >
                            <i class="bi bi-plus-lg" style="margin-right: 8px !important;"></i>
                            <span>Agregar pregunta</span>
                        </button>
                    </div>

                    <!-- Lista de Preguntas -->
                    <div 
                        v-for="(q, i) in form.questions" 
                        :key="i" 
                        class="training-item-box p-3 mb-3"
                    >
                        <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                            <span class="badge rounded-2 bg-info-subtle text-info border border-info-subtle px-2 py-1 small fw-bold">
                                Pregunta {{ i + 1 }}
                            </span>
                            <button 
                                type="button" 
                                class="btn-table-action btn-table-delete text-danger" 
                                title="Quitar pregunta"
                                aria-label="Quitar pregunta"
                                @click="form.questions.splice(i, 1)"
                            >
                                <i class="bi bi-trash3"></i>
                            </button>
                        </div>

                        <div class="mb-3">
                            <div class="modern-input-wrapper">
                                <i class="bi bi-patch-question modern-input-icon"></i>
                                <input 
                                    :id="`q_${i}`" 
                                    v-model="q.question" 
                                    class="modern-input-control fw-medium" 
                                    :class="{ 'is-invalid': err(`questions.${i}.question`) }" 
                                    :placeholder="`Escribe la pregunta #${i + 1} aquí...`" 
                                    maxlength="500" 
                                    required
                                >
                            </div>
                        </div>

                        <fieldset>
                            <legend class="modern-field-label mb-2">
                                Alternativas (marca el botón circular de la opción correcta):
                            </legend>
                            <div 
                                v-for="(opt, j) in q.options" 
                                :key="j" 
                                class="d-flex align-items-center gap-2 mb-2"
                            >
                                <div class="p-2 rounded bg-light border d-flex align-items-center">
                                    <input 
                                        v-model="q.correct_option" 
                                        class="form-check-input mt-0 cursor-pointer" 
                                        type="radio" 
                                        :name="`correct_${i}`" 
                                        :value="j" 
                                        :aria-label="`Opción ${j + 1} es la correcta`"
                                        style="width: 18px; height: 18px;"
                                    >
                                </div>
                                <div class="flex-grow-1">
                                    <input 
                                        v-model="q.options[j]" 
                                        class="form-control" 
                                        style="height: 38px; border-radius: 8px; font-size: 0.875rem;"
                                        :placeholder="`Alternativa ${j + 1}`" 
                                        maxlength="300" 
                                        :aria-label="`Opción ${j + 1}`"
                                        required
                                    >
                                </div>
                                <button 
                                    v-if="q.options.length > 2" 
                                    type="button" 
                                    class="btn-table-action btn-table-delete text-danger" 
                                    aria-label="Quitar opción" 
                                    title="Quitar opción"
                                    @click="q.options.splice(j, 1); if (q.correct_option >= q.options.length) q.correct_option = 0;"
                                >
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </div>
                            <div class="d-flex align-items-center justify-content-between mt-2">
                                <button 
                                    v-if="q.options.length < 6" 
                                    type="button" 
                                    class="btn btn-link btn-sm px-0 text-decoration-none fw-medium"
                                    @click="q.options.push('')"
                                >
                                    <i class="bi bi-plus-circle me-1"></i>+ Agregar otra alternativa
                                </button>
                                <div v-if="err(`questions.${i}.options`) || err(`questions.${i}.correct_option`)" class="text-danger small fw-semibold">
                                    {{ err(`questions.${i}.options`) || err(`questions.${i}.correct_option`) }}
                                </div>
                            </div>
                        </fieldset>
                    </div>

                    <!-- Si no hay cuestionario -->
                    <div v-if="!form.questions.length" class="p-3 bg-light rounded border text-muted small">
                        <i class="bi bi-info-circle me-1 text-primary"></i>
                        Sin cuestionario evaluativo: la capacitación se marcará como <strong>aprobada y completada</strong> cuando el colaborador visualice todas las lecciones.
                    </div>
                </div>

                <!-- SECCIÓN 4: ASIGNACIÓN Y PÚBLICO OBJETIVO -->
                <div class="training-section-card shadow-sm p-3 p-md-4 mb-4">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
                        <div class="d-flex align-items-center" style="gap: 12px !important;">
                            <span class="badge rounded-circle bg-primary text-white d-flex align-items-center justify-content-center" style="width: 28px; height: 28px; font-size: 0.85rem;">
                                4
                            </span>
                            <div>
                                <h2 class="h6 fw-bold mb-0 text-body">Público Objetivo y Asignación</h2>
                                <span class="small text-secondary">Elige quiénes deben completar esta capacitación en la app</span>
                            </div>
                        </div>

                        <button 
                            v-if="!hasAll" 
                            type="button" 
                            class="btn btn-sm btn-outline-primary d-inline-flex align-items-center shadow-sm"
                            style="border-radius: 8px; font-weight: 600; padding: 6px 14px;"
                            @click="addTarget"
                        >
                            <i class="bi bi-plus-lg" style="margin-right: 8px !important;"></i>
                            <span>Agregar grupo de asignación</span>
                        </button>
                    </div>

                    <div v-if="err('targets')" class="alert alert-danger py-2 small d-flex align-items-center mb-3">
                        <i class="bi bi-exclamation-triangle-fill" style="margin-right: 8px !important;"></i>
                        <span>{{ err('targets') }}</span>
                    </div>

                    <!-- Filas de Destinatarios -->
                    <div 
                        v-for="(t, i) in form.targets" 
                        :key="i" 
                        class="d-flex flex-wrap align-items-center gap-2 mb-2 p-2 rounded bg-light border"
                    >
                        <!-- Selector de Criterio -->
                        <div style="min-width: 180px;">
                            <select 
                                v-model="t.type" 
                                class="form-select form-select-sm fw-semibold"
                                style="height: 38px; border-radius: 8px;"
                                :aria-label="`Tipo de asignación ${i + 1}`" 
                                @change="onTargetType(t)"
                            >
                                <option v-for="(label, key) in TARGET_TYPES" :key="key" :value="key">
                                    {{ label }}
                                </option>
                            </select>
                        </div>

                        <!-- Selector por Sucursal -->
                        <div v-if="t.type === 'branch'" class="flex-grow-1" style="min-width: 220px;">
                            <select 
                                v-model="t.id" 
                                class="form-select form-select-sm" 
                                style="height: 38px; border-radius: 8px;"
                                :class="{ 'is-invalid': err(`targets.${i}.id`) }" 
                                aria-label="Sucursal"
                            >
                                <option v-for="b in branches" :key="b.id" :value="b.id">
                                    {{ b.name }}
                                </option>
                            </select>
                        </div>

                        <!-- Selector por Departamento / Área -->
                        <div v-else-if="t.type === 'department'" class="flex-grow-1" style="min-width: 220px;">
                            <select 
                                v-model="t.id" 
                                class="form-select form-select-sm" 
                                style="height: 38px; border-radius: 8px;"
                                :class="{ 'is-invalid': err(`targets.${i}.id`) }" 
                                aria-label="Área"
                            >
                                <option v-for="d in departments" :key="d.id" :value="d.id">
                                    {{ d.name }}
                                </option>
                            </select>
                        </div>

                        <!-- Input por Cargo -->
                        <div v-else-if="t.type === 'position'" class="flex-grow-1" style="min-width: 220px;">
                            <input 
                                v-model="t.value" 
                                class="form-control form-control-sm" 
                                style="height: 38px; border-radius: 8px;"
                                list="positions" 
                                placeholder="Ej.: Chofer, Almacenero, Prevencionista SST..." 
                                maxlength="150" 
                                aria-label="Cargo"
                            >
                        </div>

                        <!-- Selector por Persona -->
                        <div v-else-if="t.type === 'user'" class="flex-grow-1" style="min-width: 220px;">
                            <select 
                                v-model="t.id" 
                                class="form-select form-select-sm" 
                                style="height: 38px; border-radius: 8px;"
                                :class="{ 'is-invalid': err(`targets.${i}.id`) }" 
                                aria-label="Persona"
                            >
                                <option v-for="e in employees" :key="e.id" :value="e.id">
                                    {{ e.last_name }}, {{ e.first_name }}{{ e.employee_code ? ` · [${e.employee_code}]` : '' }}
                                </option>
                            </select>
                        </div>

                        <!-- Todo el personal -->
                        <div v-else class="flex-grow-1 small text-secondary py-1 px-2">
                            <i class="bi bi-people-fill me-1 text-primary"></i>
                            Aplica a todo el personal activo de la empresa, incluyendo nuevas incorporaciones.
                        </div>

                        <!-- Botón para eliminar criterio -->
                        <button 
                            type="button" 
                            class="btn-table-action btn-table-delete text-danger" 
                            aria-label="Quitar asignación" 
                            title="Quitar asignación"
                            @click="form.targets.splice(i, 1)"
                        >
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>

                    <datalist id="positions">
                        <option v-for="p in positions" :key="p" :value="p" />
                    </datalist>

                    <p class="form-text text-muted small mt-2 mb-0">
                        <i class="bi bi-magic me-1 text-primary"></i>
                        <strong>Automatización inteligente:</strong> Quien ingrese posteriormente o sea promovido al área o cargo elegido será matriculado automáticamente.
                    </p>
                </div>

                <!-- =========================================================================
                     PIE DE PÁGINA: ACCIONES DE GUARDADO
                     ========================================================================= -->
                <div class="d-flex flex-wrap align-items-center justify-content-end gap-2 mb-5">
                    <Link href="/training" class="btn btn-outline-secondary" style="height: 42px; border-radius: 8px; font-weight: 500;">
                        Cancelar y volver
                    </Link>
                    <button 
                        type="button" 
                        class="btn btn-outline-primary d-inline-flex align-items-center" 
                        style="height: 42px; border-radius: 8px; font-weight: 600;"
                        :disabled="form.processing" 
                        @click="submit(course.status === 'published' ? 'published' : 'draft')"
                    >
                        <span v-if="form.processing" class="spinner-border spinner-border-sm me-2" role="status"></span>
                        <i v-else class="bi bi-file-earmark-check" style="margin-right: 8px !important;"></i>
                        <span>{{ course.status === 'published' ? 'Guardar cambios' : 'Guardar borrador' }}</span>
                    </button>
                    <button 
                        v-if="course.status !== 'published'" 
                        type="button" 
                        class="btn btn-primary d-inline-flex align-items-center shadow-sm" 
                        style="height: 42px; border-radius: 8px; font-weight: 600; padding: 0 1.5rem;"
                        :disabled="form.processing" 
                        @click="submit('published')"
                    >
                        <span v-if="form.processing" class="spinner-border spinner-border-sm me-2" role="status"></span>
                        <i v-else class="bi bi-send-fill" style="margin-right: 8px !important;"></i>
                        <span>Guardar y publicar en app</span>
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
