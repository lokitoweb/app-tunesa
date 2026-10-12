<script setup>
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import TenantBadge from '@/Modules/Core/Components/TenantBadge.vue';

const props = defineProps({ courses: Array, totals: Object, statuses: Object, filter: String });

const STATUS_BADGE = {
    published: 'bg-success-subtle text-success-emphasis border-success-subtle',
    draft: 'bg-warning-subtle text-warning-emphasis border-warning-subtle',
    archived: 'bg-secondary-subtle text-secondary-emphasis border-secondary-subtle',
};

const kpis = [
    { key: 'assigned', label: 'Asignaciones', icon: 'people', tone: 'primary' },
    { key: 'completed', label: 'Completadas', icon: 'patch-check', tone: 'success' },
    { key: 'overdue', label: 'Vencidas', icon: 'alarm', tone: 'danger' },
];

function filterBy(status) {
    router.get('/training', status ? { status } : {}, { preserveState: true, preserveScroll: true });
}

function setStatus(course, status) {
    const ask = { published: `¿Publicar "${course.title}"? Se avisará por la app a las personas asignadas.`, archived: `¿Archivar "${course.title}"? Dejará de verse en la app.` };
    if (!ask[status] || confirm(ask[status])) router.post(`/training/${course.id}/status`, { status }, { preserveScroll: true });
}
</script>

<template>
    <AppLayout title="Capacitaciones">
        <div class="page-header">
            <div>
                <h1>Capacitaciones</h1>
                <p>Cursos con lecciones y cuestionario que el personal completa desde la app, con constancia al aprobar.</p>
            </div>
            <Link href="/training/create" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Nueva capacitación</Link>
        </div>

        <div class="row g-3 mb-4">
            <div v-for="k in kpis" :key="k.key" class="col-12 col-sm-4">
                <div class="card border-0 h-100 kpi-card">
                    <div class="card-body d-flex align-items-center gap-3">
                        <span class="kpi-icon" :class="`bg-${k.tone}-subtle text-${k.tone}-emphasis`"><i class="bi" :class="`bi-${k.icon}`"></i></span>
                        <div>
                            <div class="kpi-label">{{ k.label }}</div>
                            <div class="fs-4 fw-bold">{{ totals[k.key] }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2 mb-3" role="group" aria-label="Filtrar por estado">
            <button type="button" class="btn btn-sm" :class="!filter ? 'btn-primary' : 'btn-outline-secondary'" @click="filterBy(null)">Todas</button>
            <button v-for="(label, key) in statuses" :key="key" type="button" class="btn btn-sm" :class="filter === key ? 'btn-primary' : 'btn-outline-secondary'" @click="filterBy(key)">{{ label }}</button>
        </div>

        <div class="card border-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Capacitación</th>
                            <th>Contenido</th>
                            <th>Avance del personal</th>
                            <th>Estado</th>
                            <th class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in courses" :key="c.id">
                            <td>
                                <Link :href="`/training/${c.id}`" class="fw-semibold text-decoration-none">{{ c.title }}</Link>
                                <div class="small text-body-secondary">{{ c.category }}<span v-if="c.is_mandatory"> · Obligatoria</span></div>
                                <TenantBadge :tenant-id="c.tenant_id" />
                            </td>
                            <td class="small text-nowrap">{{ c.lessons }} lecciones<span v-if="c.questions"> · {{ c.questions }} preguntas</span></td>
                            <td style="min-width: 200px">
                                <template v-if="c.assigned">
                                    <div class="d-flex justify-content-between small mb-1">
                                        <span>{{ c.completed }} de {{ c.assigned }}</span>
                                        <span class="fw-semibold">{{ c.completion }}%</span>
                                    </div>
                                    <div class="progress" style="height: 6px" role="progressbar" :aria-valuenow="c.completion" aria-valuemin="0" aria-valuemax="100" :aria-label="`Avance de ${c.title}`">
                                        <div class="progress-bar bg-success" :style="{ width: `${c.completion}%` }"></div>
                                    </div>
                                    <div v-if="c.overdue" class="small text-danger mt-1"><i class="bi bi-alarm me-1"></i>{{ c.overdue }} vencida(s)</div>
                                </template>
                                <span v-else class="small text-body-secondary">Sin asignar</span>
                            </td>
                            <td><span class="badge rounded-pill border" :class="STATUS_BADGE[c.status]">{{ statuses[c.status] }}</span></td>
                            <td class="text-end text-nowrap">
                                <button v-if="c.status !== 'published'" type="button" class="btn btn-sm btn-outline-success me-1" @click="setStatus(c, 'published')"><i class="bi bi-send"></i> Publicar</button>
                                <Link :href="`/training/${c.id}/edit`" class="btn btn-sm btn-outline-secondary me-1"><i class="bi bi-pencil"></i> Editar</Link>
                                <button v-if="c.status === 'published'" type="button" class="btn btn-sm btn-outline-secondary" :aria-label="`Archivar ${c.title}`" @click="setStatus(c, 'archived')"><i class="bi bi-archive"></i></button>
                            </td>
                        </tr>
                        <tr v-if="!courses.length">
                            <td colspan="5">
                                <div class="empty-state"><i class="bi bi-mortarboard"></i>Aún no hay capacitaciones. Crea la primera: inducción, seguridad, atención al cliente…</div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
