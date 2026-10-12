<script setup>
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';

const props = defineProps({
    course: Object,
    categories: Object,
    lessonTypes: Object,
    branches: Array,
    departments: Array,
    positions: Array,
    employees: Array,
});

const editing = props.course.id !== null;
const form = useForm({ ...props.course });

const TARGET_TYPES = { all: 'Todo el personal', branch: 'Sucursal', department: 'Área', position: 'Cargo', user: 'Persona' };
const LESSON_ICONS = { text: 'file-text', video: 'play-btn', pdf: 'file-earmark-pdf', link: 'link-45deg' };
const hasAll = computed(() => form.targets.some((t) => t.type === 'all'));

function addLesson(type = 'text') {
    form.lessons.push({ id: null, title: '', type, content: '', url: '', duration_minutes: null, file_name: null, file: null });
}
function moveLesson(i, delta) {
    const j = i + delta;
    if (j < 0 || j >= form.lessons.length) return;
    const [l] = form.lessons.splice(i, 1);
    form.lessons.splice(j, 0, l);
}
function addQuestion() {
    form.questions.push({ question: '', options: ['', '', ''], correct_option: 0 });
}
function addTarget() {
    form.targets.push({ type: 'branch', id: props.branches[0]?.id ?? null, value: null });
}
function onTargetType(t) {
    t.id = t.type === 'branch' ? props.branches[0]?.id ?? null : t.type === 'department' ? props.departments[0]?.id ?? null : t.type === 'user' ? props.employees[0]?.id ?? null : null;
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
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb small mb-2">
                <li class="breadcrumb-item"><Link href="/training">Capacitaciones</Link></li>
                <li class="breadcrumb-item active" aria-current="page">{{ editing ? 'Editar' : 'Nueva' }}</li>
            </ol>
        </nav>
        <div class="page-header">
            <div>
                <h1>{{ editing ? `Editar: ${course.title}` : 'Nueva capacitación' }}</h1>
                <p>Arma las lecciones, agrega un cuestionario si quieres evaluar y elige a quién se asigna. Al publicarla, cada persona recibe un aviso en la app.</p>
            </div>
        </div>

        <form @submit.prevent="submit()">
            <div class="card border-0 mb-4">
                <div class="card-header border-0 pt-3"><h2 class="h6 fw-semibold mb-0">1. Datos generales</h2></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label for="title" class="form-label">Título</label>
                            <input id="title" v-model="form.title" class="form-control" :class="{ 'is-invalid': err('title') }" maxlength="200" required placeholder="Ej.: Inducción para nuevos colaboradores">
                            <div class="invalid-feedback">{{ err('title') }}</div>
                        </div>
                        <div class="col-md-4">
                            <label for="category" class="form-label">Categoría</label>
                            <select id="category" v-model="form.category" class="form-select">
                                <option v-for="(label, key) in categories" :key="key" :value="key">{{ label }}</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label for="description" class="form-label">Descripción</label>
                            <textarea id="description" v-model="form.description" class="form-control" rows="3" maxlength="5000" placeholder="Qué aprenderá la persona y por qué es importante"></textarea>
                        </div>
                        <div class="col-6 col-md-3">
                            <label for="estimated_minutes" class="form-label">Duración (min)</label>
                            <input id="estimated_minutes" v-model="form.estimated_minutes" type="number" min="1" max="2000" class="form-control" :class="{ 'is-invalid': err('estimated_minutes') }">
                        </div>
                        <div class="col-6 col-md-3">
                            <label for="due_days" class="form-label">Plazo (días)</label>
                            <input id="due_days" v-model="form.due_days" type="number" min="1" max="365" class="form-control" :class="{ 'is-invalid': err('due_days') }" aria-describedby="due_days_help">
                            <div id="due_days_help" class="form-text">Vacío: sin fecha límite.</div>
                        </div>
                        <div class="col-6 col-md-3">
                            <label for="passing_score" class="form-label">Nota para aprobar</label>
                            <input id="passing_score" v-model="form.passing_score" type="number" min="1" max="100" class="form-control" :class="{ 'is-invalid': err('passing_score') }" required>
                        </div>
                        <div class="col-6 col-md-3 d-flex align-items-end">
                            <div class="form-check form-switch mb-2">
                                <input id="is_mandatory" v-model="form.is_mandatory" class="form-check-input" type="checkbox" role="switch">
                                <label class="form-check-label" for="is_mandatory">Obligatoria</label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card border-0 mb-4">
                <div class="card-header border-0 pt-3 d-flex flex-wrap gap-2 justify-content-between align-items-center">
                    <h2 class="h6 fw-semibold mb-0">2. Lecciones</h2>
                    <div class="d-flex flex-wrap gap-2">
                        <button v-for="(label, type) in lessonTypes" :key="type" type="button" class="btn btn-sm btn-outline-primary" @click="addLesson(type)">
                            <i class="bi me-1" :class="`bi-${LESSON_ICONS[type]}`"></i>{{ label }}
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div v-if="err('lessons')" class="alert alert-danger py-2 small">{{ err('lessons') }}</div>
                    <div v-for="(lesson, i) in form.lessons" :key="i" class="border rounded-3 p-3 mb-3">
                        <div class="d-flex gap-2 align-items-start mb-2">
                            <span class="badge text-bg-light border mt-2">{{ i + 1 }}</span>
                            <div class="flex-grow-1">
                                <label :for="`lesson_title_${i}`" class="visually-hidden">Título de la lección {{ i + 1 }}</label>
                                <input :id="`lesson_title_${i}`" v-model="lesson.title" class="form-control" :class="{ 'is-invalid': err(`lessons.${i}.title`) }" placeholder="Título de la lección" maxlength="200" required>
                            </div>
                            <select v-model="lesson.type" class="form-select w-auto" :aria-label="`Tipo de la lección ${i + 1}`">
                                <option v-for="(label, key) in lessonTypes" :key="key" :value="key">{{ label }}</option>
                            </select>
                            <div class="btn-group">
                                <button type="button" class="btn btn-outline-secondary" :disabled="i === 0" aria-label="Subir" @click="moveLesson(i, -1)"><i class="bi bi-arrow-up"></i></button>
                                <button type="button" class="btn btn-outline-secondary" :disabled="i === form.lessons.length - 1" aria-label="Bajar" @click="moveLesson(i, 1)"><i class="bi bi-arrow-down"></i></button>
                                <button type="button" class="btn btn-outline-danger" aria-label="Quitar lección" @click="form.lessons.splice(i, 1)"><i class="bi bi-trash"></i></button>
                            </div>
                        </div>
                        <div v-if="lesson.type === 'text'">
                            <label :for="`lesson_content_${i}`" class="visually-hidden">Contenido</label>
                            <textarea :id="`lesson_content_${i}`" v-model="lesson.content" class="form-control" :class="{ 'is-invalid': err(`lessons.${i}.content`) }" rows="5" placeholder="Contenido de la lección"></textarea>
                            <div class="invalid-feedback">{{ err(`lessons.${i}.content`) }}</div>
                        </div>
                        <div v-else-if="lesson.type === 'pdf'">
                            <label :for="`lesson_file_${i}`" class="form-label small mb-1">{{ lesson.file_name ? `Archivo actual: ${lesson.file_name}. Sube otro para reemplazarlo.` : 'Documento PDF (máx. 20 MB)' }}</label>
                            <input :id="`lesson_file_${i}`" type="file" accept="application/pdf" class="form-control" :class="{ 'is-invalid': err(`lessons.${i}.file`) }" @input="lesson.file = $event.target.files[0]">
                            <div class="invalid-feedback">{{ err(`lessons.${i}.file`) }}</div>
                            <a v-if="lesson.id && lesson.file_name" :href="`/training/${course.id}/lessons/${lesson.id}/file`" target="_blank" rel="noopener" class="small d-inline-block mt-1">Ver PDF actual</a>
                        </div>
                        <div v-else>
                            <label :for="`lesson_url_${i}`" class="visually-hidden">Enlace</label>
                            <input :id="`lesson_url_${i}`" v-model="lesson.url" type="url" class="form-control" :class="{ 'is-invalid': err(`lessons.${i}.url`) }" :placeholder="lesson.type === 'video' ? 'https://www.youtube.com/watch?v=…' : 'https://…'">
                            <div class="invalid-feedback">{{ err(`lessons.${i}.url`) }}</div>
                        </div>
                        <div class="mt-2" style="max-width: 180px">
                            <label :for="`lesson_min_${i}`" class="form-label small mb-1">Duración (min, opcional)</label>
                            <input :id="`lesson_min_${i}`" v-model="lesson.duration_minutes" type="number" min="1" max="600" class="form-control form-control-sm">
                        </div>
                    </div>
                    <div v-if="!form.lessons.length" class="empty-state"><i class="bi bi-collection-play"></i>Agrega la primera lección con los botones de arriba.</div>
                </div>
            </div>

            <div class="card border-0 mb-4">
                <div class="card-header border-0 pt-3 d-flex justify-content-between align-items-center">
                    <h2 class="h6 fw-semibold mb-0">3. Cuestionario <span class="fw-normal text-body-secondary small">(opcional)</span></h2>
                    <button type="button" class="btn btn-sm btn-outline-primary" @click="addQuestion"><i class="bi bi-plus-lg me-1"></i>Pregunta</button>
                </div>
                <div class="card-body">
                    <div v-for="(q, i) in form.questions" :key="i" class="border rounded-3 p-3 mb-3">
                        <div class="d-flex gap-2 mb-2">
                            <label :for="`q_${i}`" class="visually-hidden">Pregunta {{ i + 1 }}</label>
                            <input :id="`q_${i}`" v-model="q.question" class="form-control" :class="{ 'is-invalid': err(`questions.${i}.question`) }" :placeholder="`Pregunta ${i + 1}`" maxlength="500" required>
                            <button type="button" class="btn btn-outline-danger" aria-label="Quitar pregunta" @click="form.questions.splice(i, 1)"><i class="bi bi-trash"></i></button>
                        </div>
                        <fieldset>
                            <legend class="form-label small mb-1">Opciones (marca la correcta)</legend>
                            <div v-for="(opt, j) in q.options" :key="j" class="input-group input-group-sm mb-1">
                                <div class="input-group-text">
                                    <input v-model="q.correct_option" class="form-check-input mt-0" type="radio" :name="`correct_${i}`" :value="j" :aria-label="`Opción ${j + 1} es la correcta`">
                                </div>
                                <input v-model="q.options[j]" class="form-control" :placeholder="`Opción ${j + 1}`" maxlength="300" :aria-label="`Opción ${j + 1}`">
                                <button v-if="q.options.length > 2" type="button" class="btn btn-outline-secondary" aria-label="Quitar opción" @click="q.options.splice(j, 1); if (q.correct_option >= q.options.length) q.correct_option = 0"><i class="bi bi-x"></i></button>
                            </div>
                            <button v-if="q.options.length < 6" type="button" class="btn btn-link btn-sm px-0" @click="q.options.push('')">+ Agregar opción</button>
                            <div v-if="err(`questions.${i}.options`) || err(`questions.${i}.correct_option`)" class="text-danger small">{{ err(`questions.${i}.options`) || err(`questions.${i}.correct_option`) }}</div>
                        </fieldset>
                    </div>
                    <p v-if="!form.questions.length" class="text-body-secondary small mb-0">Sin cuestionario, la capacitación se completa al ver todas las lecciones.</p>
                </div>
            </div>

            <div class="card border-0 mb-4">
                <div class="card-header border-0 pt-3 d-flex justify-content-between align-items-center">
                    <h2 class="h6 fw-semibold mb-0">4. ¿A quién se asigna?</h2>
                    <button v-if="!hasAll" type="button" class="btn btn-sm btn-outline-primary" @click="addTarget"><i class="bi bi-plus-lg me-1"></i>Agregar</button>
                </div>
                <div class="card-body">
                    <div v-if="err('targets')" class="alert alert-danger py-2 small">{{ err('targets') }}</div>
                    <div v-for="(t, i) in form.targets" :key="i" class="d-flex flex-wrap gap-2 mb-2">
                        <select v-model="t.type" class="form-select w-auto" :aria-label="`Tipo de asignación ${i + 1}`" @change="onTargetType(t)">
                            <option v-for="(label, key) in TARGET_TYPES" :key="key" :value="key">{{ label }}</option>
                        </select>
                        <select v-if="t.type === 'branch'" v-model="t.id" class="form-select flex-grow-1 w-auto" :class="{ 'is-invalid': err(`targets.${i}.id`) }" aria-label="Sucursal">
                            <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                        </select>
                        <select v-else-if="t.type === 'department'" v-model="t.id" class="form-select flex-grow-1 w-auto" :class="{ 'is-invalid': err(`targets.${i}.id`) }" aria-label="Área">
                            <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
                        </select>
                        <template v-else-if="t.type === 'position'">
                            <input v-model="t.value" class="form-control flex-grow-1 w-auto" list="positions" placeholder="Ej.: Chofer, Almacenero, Atención al cliente" maxlength="150" aria-label="Cargo">
                        </template>
                        <select v-else-if="t.type === 'user'" v-model="t.id" class="form-select flex-grow-1 w-auto" :class="{ 'is-invalid': err(`targets.${i}.id`) }" aria-label="Persona">
                            <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.last_name }}, {{ e.first_name }}{{ e.employee_code ? ` · ${e.employee_code}` : '' }}</option>
                        </select>
                        <span v-else class="form-control-plaintext flex-grow-1 w-auto text-body-secondary small">Todo el personal activo, incluidas las nuevas contrataciones.</span>
                        <button type="button" class="btn btn-outline-danger" aria-label="Quitar asignación" @click="form.targets.splice(i, 1)"><i class="bi bi-x-lg"></i></button>
                    </div>
                    <datalist id="positions"><option v-for="p in positions" :key="p" :value="p" /></datalist>
                    <p class="form-text mb-0">Quien entre después a una sucursal, área o cargo elegido recibe la capacitación automáticamente.</p>
                </div>
            </div>

            <div class="d-flex flex-wrap justify-content-end gap-2 mb-5">
                <Link href="/training" class="btn btn-outline-secondary">Cancelar</Link>
                <button type="button" class="btn btn-outline-primary" :disabled="form.processing" @click="submit(course.status === 'published' ? 'published' : 'draft')">
                    {{ course.status === 'published' ? 'Guardar cambios' : 'Guardar borrador' }}
                </button>
                <button v-if="course.status !== 'published'" type="button" class="btn btn-primary" :disabled="form.processing" @click="submit('published')"><i class="bi bi-send me-1"></i>Guardar y publicar</button>
            </div>
        </form>
    </AppLayout>
</template>
