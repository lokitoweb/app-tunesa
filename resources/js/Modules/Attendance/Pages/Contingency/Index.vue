<script setup>
import { computed, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import { useConfirm } from '@/Modules/Core/Composables/useConfirm';

const props = defineProps({
    plans: { type: Array, default: () => [] },
    report: { type: Object, default: null },
    options: { type: Object, required: true },
});

const { ask } = useConfirm();
const form = useForm({ scope: 'branch', scope_ids: [], reason: '', hours: 8 });
const scopeIcons = { all: 'bi-buildings', branch: 'bi-geo-alt', department: 'bi-diagram-3', users: 'bi-people' };
const targets = computed(() => ({ branch: props.options.branches, department: props.options.departments, users: props.options.people }[form.scope] || []));
const search = ref('');
watch(() => form.scope, () => { form.scope_ids = []; search.value = ''; });
const visible = computed(() => {
    const term = search.value.trim().toLocaleLowerCase('es');
    return term ? targets.value.filter((t) => t.name.toLocaleLowerCase('es').includes(term)) : targets.value;
});
const allVisibleChosen = computed(() => visible.value.length > 0 && visible.value.every((t) => form.scope_ids.includes(t.id)));
function toggleAll() {
    const ids = visible.value.map((t) => t.id);
    form.scope_ids = allVisibleChosen.value ? form.scope_ids.filter((id) => !ids.includes(id)) : [...new Set([...form.scope_ids, ...ids])];
}
const labels = { clock_in: 'Entrada', break_start: 'Inicio refrigerio', break_end: 'Fin refrigerio', clock_out: 'Salida' };
const active = computed(() => props.plans.filter((p) => p.active));

async function activate() {
    const ok = await ask({
        title: '¿Activar el plan de contingencia?',
        message: `Las personas cubiertas podrán marcar desde su portal, con la hora del servidor y sin GPS, durante ${form.hours} h. Queda registrado en la bitácora.`,
        confirmText: 'Sí, activar', variant: 'danger', icon: 'bi bi-life-preserver',
    });
    if (ok) form.post('/attendance-contingency', { preserveScroll: true, onSuccess: () => form.reset() });
}
async function end(plan) {
    const ok = await ask({ title: '¿Desactivar el plan?', message: 'Desde ahora se marca solo con la app.', confirmText: 'Sí, desactivar', icon: 'bi bi-stop-circle' });
    if (ok) router.post(`/attendance-contingency/${plan.id}/end`, {}, { preserveScroll: true });
}
const show = (id) => router.get('/attendance-contingency', { plan: id }, { preserveScroll: true, preserveState: true });
const progress = computed(() => (props.report?.covered ? Math.round((100 * props.report.marked) / props.report.covered) : 0));
</script>

<template>
    <AppLayout title="Plan de contingencia">
        <div class="d-flex flex-wrap align-items-center gap-3 mb-4">
            <span class="cg-hero-icon"><i class="bi bi-life-preserver"></i></span>
            <div class="flex-grow-1">
                <h1 class="h4 fw-bold mb-1">Plan de contingencia</h1>
                <p class="text-body-secondary small mb-0">
                    Si la app no funciona (caída, sin señal, falla del sistema), las personas cubiertas marcan sus 4 marcas desde su portal web,
                    con la hora del servidor y sin GPS. Se apaga solo al vencer y cada marca queda etiquetada.
                </p>
            </div>
            <span v-if="active.length" class="badge rounded-pill text-bg-warning px-3 py-2"><i class="bi bi-broadcast me-1"></i>{{ active.length }} activo(s)</span>
        </div>

        <div class="row g-4">
            <div class="col-12 col-xl-5">
                <form class="card cg-card" @submit.prevent="activate">
                    <div class="card-body p-4">
                        <h2 class="h6 fw-bold mb-3">Activar un plan</h2>

                        <label class="form-label small fw-semibold">¿Para quién?</label>
                        <div class="cg-scopes mb-3" role="radiogroup" aria-label="Alcance">
                            <button v-for="(label, key) in options.scopes" :key="key" type="button" role="radio" :aria-checked="form.scope === key"
                                class="cg-scope" :class="{ active: form.scope === key }" @click="form.scope = key">
                                <i class="bi" :class="scopeIcons[key]"></i><span>{{ label }}</span>
                            </button>
                        </div>

                        <div v-if="form.scope !== 'all'" class="mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label class="form-label small fw-semibold mb-0" for="cg-search">¿Cuáles? <span class="text-body-secondary fw-normal">({{ form.scope_ids.length }} elegido(s))</span></label>
                                <button type="button" class="btn btn-link btn-sm p-0" @click="toggleAll">{{ allVisibleChosen ? 'Quitar todos' : 'Elegir todos' }}</button>
                            </div>
                            <div class="input-group input-group-sm mb-2">
                                <span class="input-group-text"><i class="bi bi-search"></i></span>
                                <input id="cg-search" v-model="search" type="search" class="form-control" placeholder="Buscar…" />
                            </div>
                            <div class="cg-list" :class="{ 'is-invalid': form.errors.scope_ids }">
                                <label v-for="t in visible" :key="t.id" class="cg-item">
                                    <input v-model="form.scope_ids" type="checkbox" class="form-check-input mt-0" :value="t.id" />
                                    <span>{{ t.name }}</span>
                                </label>
                                <p v-if="!visible.length" class="small text-body-secondary text-center py-3 mb-0">Sin resultados.</p>
                            </div>
                            <div v-if="form.errors.scope_ids" class="small text-danger mt-1">{{ form.errors.scope_ids }}</div>
                        </div>
                        <div v-else class="alert alert-light border small py-2 mb-3"><i class="bi bi-info-circle me-1"></i>Cubre a todo el personal de la empresa.</div>

                        <label class="form-label small fw-semibold" for="cg-reason">Motivo</label>
                        <input id="cg-reason" v-model="form.reason" class="form-control mb-1" :class="{ 'is-invalid': form.errors.reason }" maxlength="200" placeholder="Caída de la app en la sede" required />
                        <div class="invalid-feedback">{{ form.errors.reason }}</div>

                        <label class="form-label small fw-semibold mt-2" for="cg-hours">Duración</label>
                        <div class="input-group mb-4">
                            <input id="cg-hours" v-model="form.hours" type="number" min="1" :max="options.max_hours" class="form-control" :class="{ 'is-invalid': form.errors.hours }" required />
                            <span class="input-group-text">horas · máx. {{ options.max_hours }}</span>
                        </div>

                        <button type="submit" class="btn btn-warning w-100 fw-semibold py-2" :disabled="form.processing">
                            <i class="bi bi-life-preserver me-1"></i>Activar plan
                        </button>
                    </div>
                </form>
            </div>

            <div class="col-12 col-xl-7">
                <h2 class="h6 fw-bold mb-3">Planes</h2>
                <div v-if="!plans.length" class="card cg-card">
                    <div class="card-body text-center py-5 text-body-secondary">
                        <i class="bi bi-shield-check fs-1 d-block mb-2 opacity-50"></i>
                        Aún no se ha activado ningún plan. Mientras la app funcione, no hace falta.
                    </div>
                </div>
                <div v-for="p in plans" :key="p.id" class="card cg-card cg-plan mb-2" :class="{ 'is-selected': report?.id === p.id, 'is-active': p.active }" role="button" @click="show(p.id)">
                    <div class="card-body d-flex flex-wrap align-items-center gap-3 py-3">
                        <span class="cg-plan-dot"></span>
                        <div class="flex-grow-1 min-w-0">
                            <div class="fw-semibold text-truncate">{{ p.reason }}</div>
                            <div class="small text-body-secondary">
                                {{ p.scope }}<span v-if="p.targets"> · {{ p.targets }}</span> · {{ p.starts_at }} → {{ p.ended_at || p.ends_at }} · por {{ p.by || '—' }}
                            </div>
                        </div>
                        <span v-if="p.active" class="badge text-bg-warning">Activo</span>
                        <span v-else-if="p.ended_at" class="badge text-bg-light border">Desactivado<span v-if="p.ended_by"> por {{ p.ended_by }}</span></span>
                        <span v-else class="badge text-bg-light border">Vencido</span>
                        <button v-if="p.active" type="button" class="btn btn-sm btn-outline-secondary" @click.stop="end(p)"><i class="bi bi-stop-circle me-1"></i>Desactivar</button>
                    </div>
                </div>

                <div v-if="report" class="card cg-card mt-3">
                    <div class="card-body p-4">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                            <h2 class="h6 fw-bold mb-0">Cierre del plan</h2>
                            <a href="/approvals?tab=justifications" class="small">{{ report.pending_justifications }} justificación(es) por revisar</a>
                        </div>
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div class="progress flex-grow-1" style="height: 8px" role="progressbar" :aria-valuenow="progress" aria-valuemin="0" aria-valuemax="100">
                                <div class="progress-bar bg-success" :style="{ width: progress + '%' }"></div>
                            </div>
                            <span class="small fw-semibold text-nowrap">{{ report.marked }} de {{ report.covered }} marcaron</span>
                        </div>
                        <ul class="list-group list-group-flush small">
                            <li v-for="person in report.people" :key="person.document + person.name" class="list-group-item px-0 d-flex flex-wrap justify-content-between gap-2">
                                <span><span class="fw-semibold">{{ person.name }}</span> <span class="text-body-secondary">{{ person.document }}</span></span>
                                <span>
                                    <span v-for="(m, i) in person.punches" :key="i" class="badge text-bg-light border me-1">{{ labels[m.type] }} {{ m.time }}</span>
                                    <span v-if="!person.punches.length" class="badge text-bg-warning">No marcó</span>
                                </span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style>
.cg-hero-icon { width: 52px; height: 52px; border-radius: 14px; display: inline-flex; align-items: center; justify-content: center; font-size: 1.6rem;
    background: rgba(var(--bs-warning-rgb), .15); color: var(--bs-warning-text-emphasis); flex-shrink: 0; }
.cg-card { border-radius: 16px; border: 1px solid var(--bs-border-color-translucent); box-shadow: 0 1px 2px rgba(15, 23, 42, .04), 0 4px 16px rgba(15, 23, 42, .04); }
.cg-scopes { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: .5rem; }
.cg-scope { display: flex; flex-direction: column; align-items: center; gap: .3rem; padding: .7rem .25rem; border: 1px solid var(--bs-border-color); border-radius: 12px;
    background: var(--bs-body-bg); color: var(--bs-secondary-color); font-size: .8rem; font-weight: 500; transition: all .15s; }
.cg-scope i { font-size: 1.15rem; }
.cg-scope:hover { border-color: var(--brand); color: var(--bs-body-color); }
.cg-scope.active { border-color: var(--brand); background: rgba(var(--brand-rgb), .08); color: var(--brand); }
.cg-list { max-height: 260px; overflow-y: auto; border: 1px solid var(--bs-border-color); border-radius: 12px; padding: .25rem; }
.cg-list.is-invalid { border-color: var(--bs-danger); }
.cg-item { display: flex; align-items: center; gap: .6rem; padding: .5rem .65rem; border-radius: 8px; cursor: pointer; font-size: .875rem; }
.cg-item:hover { background: var(--bs-tertiary-bg); }
.cg-plan { cursor: pointer; transition: border-color .15s; }
.cg-plan:hover, .cg-plan.is-selected { border-color: var(--brand); }
.cg-plan-dot { width: 10px; height: 10px; border-radius: 50%; background: var(--bs-secondary-bg); flex-shrink: 0; }
.cg-plan.is-active .cg-plan-dot { background: var(--bs-warning); box-shadow: 0 0 0 4px rgba(var(--bs-warning-rgb), .25); }
@media (max-width: 575.98px) { .cg-scopes { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
</style>
