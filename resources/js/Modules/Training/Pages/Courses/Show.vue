<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import Pagination from '@/Modules/Core/Components/Pagination.vue';

const props = defineProps({ course: Object, stats: Object, enrollments: Object, filters: Object });

const search = ref(props.filters.search ?? '');
const STATUS_BADGE = {
    completed: 'bg-success-subtle text-success-emphasis border-success-subtle',
    in_progress: 'bg-primary-subtle text-primary-emphasis border-primary-subtle',
    pending: 'bg-secondary-subtle text-secondary-emphasis border-secondary-subtle',
    overdue: 'bg-danger-subtle text-danger-emphasis border-danger-subtle',
};
const FILTERS = { '': 'Todos', pending: 'Pendientes', overdue: 'Vencidos', completed: 'Completados' };

function apply(status = props.filters.status) {
    router.get(`/training/${props.course.id}`, { status: status || undefined, search: search.value || undefined }, { preserveState: true, preserveScroll: true });
}

function setStatus(status) {
    router.post(`/training/${props.course.id}/status`, { status }, { preserveScroll: true });
}

function destroy() {
    if (confirm(`¿Eliminar "${props.course.title}"? Se pierde el avance registrado.`)) router.delete(`/training/${props.course.id}`);
}
</script>

<template>
    <AppLayout :title="course.title">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb small mb-2">
                <li class="breadcrumb-item"><Link href="/training">Capacitaciones</Link></li>
                <li class="breadcrumb-item active" aria-current="page">{{ course.title }}</li>
            </ol>
        </nav>
        <div class="page-header">
            <div>
                <h1>{{ course.title }}</h1>
                <p>{{ course.category }} · {{ course.lessons }} lecciones<span v-if="course.questions"> · cuestionario de {{ course.questions }} preguntas (aprueba con {{ course.passing_score }})</span><span v-if="course.due_days"> · plazo de {{ course.due_days }} días</span></p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <button v-if="course.status !== 'published'" type="button" class="btn btn-success" @click="setStatus('published')"><i class="bi bi-send me-1"></i>Publicar</button>
                <button v-else type="button" class="btn btn-outline-secondary" @click="setStatus('archived')"><i class="bi bi-archive me-1"></i>Archivar</button>
                <Link :href="`/training/${course.id}/edit`" class="btn btn-outline-primary"><i class="bi bi-pencil me-1"></i>Editar</Link>
                <a :href="`/training/${course.id}/export`" class="btn btn-outline-secondary"><i class="bi bi-download me-1"></i>Exportar avance</a>
                <button type="button" class="btn btn-outline-danger" aria-label="Eliminar capacitación" @click="destroy"><i class="bi bi-trash"></i></button>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-6 col-lg-3" v-for="k in [
                { label: 'Asignados', value: stats.assigned, icon: 'people', tone: 'primary' },
                { label: 'Completados', value: stats.completed, icon: 'patch-check', tone: 'success' },
                { label: 'Vencidos', value: stats.overdue, icon: 'alarm', tone: 'danger' },
                { label: 'Nota promedio', value: stats.avg_score || '—', icon: 'award', tone: 'info' },
            ]" :key="k.label">
                <div class="card border-0 h-100 kpi-card">
                    <div class="card-body d-flex align-items-center gap-3">
                        <span class="kpi-icon" :class="`bg-${k.tone}-subtle text-${k.tone}-emphasis`"><i class="bi" :class="`bi-${k.icon}`"></i></span>
                        <div><div class="kpi-label">{{ k.label }}</div><div class="fs-4 fw-bold">{{ k.value }}</div></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 mb-4">
            <div class="card-body">
                <h2 class="h6 fw-semibold">Asignada a</h2>
                <div v-if="course.audience.length" class="d-flex flex-wrap gap-2">
                    <span v-for="a in course.audience" :key="a" class="badge text-bg-light border fw-normal">{{ a }}</span>
                </div>
                <div v-else class="text-body-secondary small">Nadie todavía. Edita la capacitación para elegir sucursales, áreas, cargos o personas.</div>
                <p v-if="course.description" class="mt-3 mb-0 small text-body-secondary" style="white-space: pre-line">{{ course.description }}</p>
            </div>
        </div>

        <div class="card border-0">
            <div class="card-header border-0 pt-3 d-flex flex-wrap gap-2 align-items-center justify-content-between">
                <div class="d-flex flex-wrap gap-2" role="group" aria-label="Filtrar por estado">
                    <button v-for="(label, key) in FILTERS" :key="key" type="button" class="btn btn-sm" :class="(filters.status ?? '') === key ? 'btn-primary' : 'btn-outline-secondary'" @click="apply(key)">{{ label }}</button>
                </div>
                <form class="d-flex gap-2" role="search" @submit.prevent="apply()">
                    <label for="search" class="visually-hidden">Buscar colaborador</label>
                    <input id="search" v-model="search" type="search" class="form-control form-control-sm" placeholder="Nombre o código">
                    <button class="btn btn-sm btn-outline-secondary" aria-label="Buscar"><i class="bi bi-search"></i></button>
                </form>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Colaborador</th>
                            <th>Sucursal / área</th>
                            <th>Estado</th>
                            <th>Avance</th>
                            <th>Nota</th>
                            <th>Fecha límite</th>
                            <th class="text-end">Constancia</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="e in enrollments.data" :key="e.id">
                            <td>
                                <div class="fw-semibold">{{ e.employee }}</div>
                                <div class="small text-body-secondary">{{ [e.code, e.position].filter(Boolean).join(' · ') }}</div>
                            </td>
                            <td class="small">{{ e.branch || '—' }}<div v-if="e.department" class="text-body-secondary">{{ e.department }}</div></td>
                            <td><span class="badge rounded-pill border" :class="STATUS_BADGE[e.status]">{{ e.status_label }}</span></td>
                            <td style="min-width: 120px">
                                <div class="progress" style="height: 6px" role="progressbar" :aria-valuenow="e.progress" aria-valuemin="0" aria-valuemax="100" :aria-label="`Avance de ${e.employee}`">
                                    <div class="progress-bar" :class="e.status === 'completed' ? 'bg-success' : ''" :style="{ width: `${e.progress}%` }"></div>
                                </div>
                                <div class="small text-body-secondary mt-1">{{ e.progress }}%</div>
                            </td>
                            <td class="small">{{ e.score ?? '—' }}<span v-if="e.attempts" class="text-body-secondary"> ({{ e.attempts }} intento{{ e.attempts === 1 ? '' : 's' }})</span></td>
                            <td class="small text-nowrap">{{ e.completed_at ? `Completó ${e.completed_at}` : (e.due_date ?? 'Sin plazo') }}</td>
                            <td class="text-end">
                                <a v-if="e.status === 'completed'" :href="`/training/${course.id}/certificates/${e.id}`" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary"><i class="bi bi-file-earmark-pdf"></i> Ver</a>
                            </td>
                        </tr>
                        <tr v-if="!enrollments.data.length">
                            <td colspan="7"><div class="empty-state"><i class="bi bi-people"></i>{{ course.status === 'published' ? 'No hay personas en este filtro.' : 'Publica la capacitación para asignarla.' }}</div></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="card-footer bg-transparent"><Pagination :paginator="enrollments" /></div>
        </div>
    </AppLayout>
</template>
