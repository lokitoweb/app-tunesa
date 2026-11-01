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
// Atajos de duración (solo los que permite la empresa)
const presets = computed(() => [2, 4, 8, 12, 24].filter((h) => h <= props.options.max_hours));
const labels = { clock_in: 'Entrada', break_start: 'Inicio refrigerio', break_end: 'Fin refrigerio', clock_out: 'Salida' };
const active = computed(() => props.plans.filter((p) => p.active));
const ready = computed(() => form.reason.trim() !== '' && (form.scope === 'all' || form.scope_ids.length > 0));

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
const missing = computed(() => (props.report ? Math.max(props.report.covered - props.report.marked, 0) : 0));
const status = (p) => (p.active ? ['Activo', 'tone-amber'] : p.ended_at ? ['Desactivado', 'tone-neutral'] : ['Vencido', 'tone-neutral']);
</script>

<template>
    <AppLayout title="Plan de contingencia">
        <header class="page-head">
            <div class="page-head-text">
                <h1 class="page-title">Plan de contingencia</h1>
                <p class="page-subtitle">
                    Si la app no funciona (caída, sin señal, falla del sistema), las personas cubiertas marcan sus 4 marcas desde su portal web,
                    con la hora del servidor y sin GPS. El plan se apaga solo al vencer y cada marca queda etiquetada.
                </p>
            </div>
            <div class="page-actions">
                <span v-if="active.length" class="status-pill tone-amber"><span class="dash-live-dot" aria-hidden="true"></span>{{ active.length }} plan(es) activo(s)</span>
                <span v-else class="status-pill tone-neutral"><i class="bi bi-shield-check"></i>Sin planes activos</span>
            </div>
        </header>

        <section class="dash-kpis dash-kpis-3" aria-label="Resumen">
            <article class="dash-kpi-card">
                <div class="dash-kpi-top">
                    <span class="dash-kpi-label">Planes activos</span>
                    <span class="dash-kpi-icon tone-amber"><i class="bi bi-life-preserver"></i></span>
                </div>
                <div class="dash-kpi-value">{{ active.length }}</div>
                <div class="dash-kpi-meta">
                    <span v-if="active.length">Vence <b>{{ active[0].ends_at }}</b></span>
                    <span v-else>La app funciona con normalidad</span>
                </div>
            </article>
            <article class="dash-kpi-card">
                <div class="dash-kpi-top">
                    <span class="dash-kpi-label">Planes registrados</span>
                    <span class="dash-kpi-icon tone-brand"><i class="bi bi-clock-history"></i></span>
                </div>
                <div class="dash-kpi-value">{{ plans.length }}</div>
                <div class="dash-kpi-meta"><span>Historial con bitácora</span></div>
            </article>
            <article class="dash-kpi-card">
                <div class="dash-kpi-top">
                    <span class="dash-kpi-label">Justificaciones por revisar</span>
                    <span class="dash-kpi-icon tone-rose"><i class="bi bi-file-earmark-check"></i></span>
                </div>
                <div class="dash-kpi-value">{{ report ? report.pending_justifications : 0 }}</div>
                <div class="dash-kpi-meta"><a href="/approvals?tab=justifications">Ir a Aprobaciones</a></div>
            </article>
        </section>

        <div class="row g-3">
            <!-- Activar -->
            <div class="col-12 col-xl-5">
                <form class="surface" novalidate @submit.prevent="activate">
                    <header class="surface-head">
                        <div>
                            <h2 class="surface-title"><i class="bi bi-life-preserver me-1 text-warning"></i>Activar un plan</h2>
                            <p class="surface-sub">Elige a quiénes cubre, el motivo y cuántas horas dura.</p>
                        </div>
                    </header>

                    <div class="surface-pad">
                        <label class="form-label small fw-semibold">¿Para quién?</label>
                        <div class="seg-tabs w-100 mb-3" role="radiogroup" aria-label="Alcance">
                            <button v-for="(label, key) in options.scopes" :key="key" type="button" role="radio" :aria-checked="form.scope === key"
                                class="seg-tab flex-fill" :class="{ 'is-active': form.scope === key }" @click="form.scope = key">
                                <i class="bi me-1" :class="scopeIcons[key]"></i>{{ label }}
                            </button>
                        </div>

                        <template v-if="form.scope !== 'all'">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <label class="form-label small fw-semibold mb-0" for="cg-search">
                                    ¿Cuáles? <span class="text-body-secondary fw-normal">{{ form.scope_ids.length }} de {{ targets.length }} elegido(s)</span>
                                </label>
                                <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" @click="toggleAll">{{ allVisibleChosen ? 'Quitar todos' : 'Elegir todos' }}</button>
                            </div>
                            <div class="cg-picker mb-3" :class="{ 'is-invalid': form.errors.scope_ids }">
                                <div class="search-field cg-picker-search">
                                    <i class="bi bi-search"></i>
                                    <input id="cg-search" v-model="search" type="search" class="form-control" placeholder="Buscar…" />
                                </div>
                                <div class="cg-picker-list">
                                    <label v-for="t in visible" :key="t.id" class="cg-picker-item" :class="{ 'is-checked': form.scope_ids.includes(t.id) }">
                                        <input v-model="form.scope_ids" type="checkbox" class="form-check-input mt-0" :value="t.id" />
                                        <span class="text-truncate">{{ t.name }}</span>
                                    </label>
                                    <p v-if="!visible.length" class="cell-sub text-center py-3 mb-0">Sin resultados.</p>
                                </div>
                            </div>
                            <div v-if="form.errors.scope_ids" class="small text-danger mt-n2 mb-3">{{ form.errors.scope_ids }}</div>
                        </template>
                        <div v-else class="alert alert-light border small py-2 mb-3"><i class="bi bi-info-circle me-1"></i>Cubre a todo el personal de la empresa.</div>

                        <label class="form-label small fw-semibold" for="cg-reason">Motivo</label>
                        <input id="cg-reason" v-model="form.reason" class="form-control mb-1" :class="{ 'is-invalid': form.errors.reason }" maxlength="200" placeholder="Ej.: caída de la app en la sede" required />
                        <div class="invalid-feedback">{{ form.errors.reason }}</div>

                        <label class="form-label small fw-semibold mt-2" for="cg-hours">Duración</label>
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <div class="input-group cg-hours">
                                <input id="cg-hours" v-model="form.hours" type="number" min="1" :max="options.max_hours" class="form-control" :class="{ 'is-invalid': form.errors.hours }" required />
                                <span class="input-group-text">horas</span>
                            </div>
                            <div class="seg-tabs" role="group" aria-label="Duración rápida">
                                <button v-for="h in presets" :key="h" type="button" class="seg-tab" :class="{ 'is-active': Number(form.hours) === h }" @click="form.hours = h">{{ h }} h</button>
                            </div>
                        </div>
                        <div class="cell-sub mt-1">Máximo {{ options.max_hours }} h. Se desactiva solo al vencer.</div>
                        <div v-if="form.errors.hours" class="small text-danger mt-1">{{ form.errors.hours }}</div>
                    </div>

                    <footer class="surface-foot">
                        <span class="cell-sub"><i class="bi bi-exclamation-triangle text-warning me-1"></i>Marcas sin GPS: solo ante una falla real.</span>
                        <button type="submit" class="btn btn-warning fw-semibold" :disabled="form.processing || !ready">
                            <span v-if="form.processing" class="spinner-border spinner-border-sm me-1"></span>
                            <i v-else class="bi bi-life-preserver me-1"></i>Activar plan
                        </button>
                    </footer>
                </form>
            </div>

            <!-- Historial y cierre -->
            <div class="col-12 col-xl-7 d-flex flex-column gap-3">
                <section class="surface">
                    <header class="surface-head">
                        <div>
                            <h2 class="surface-title"><i class="bi bi-clock-history me-1"></i>Historial de planes</h2>
                            <p class="surface-sub">Elige un plan para ver quiénes marcaron.</p>
                        </div>
                    </header>
                    <div class="table-responsive">
                        <table class="table data-table align-middle mb-0">
                            <thead>
                                <tr><th>Motivo</th><th>Alcance</th><th>Vigencia</th><th>Estado</th><th class="text-end"><span class="visually-hidden">Acciones</span></th></tr>
                            </thead>
                            <tbody>
                                <tr v-for="p in plans" :key="p.id" class="is-clickable" :class="{ 'is-selected': report?.id === p.id }" tabindex="0"
                                    @click="show(p.id)" @keydown.enter="show(p.id)">
                                    <td>
                                        <div class="cell-title text-truncate cg-col">{{ p.reason }}</div>
                                        <div class="cell-sub">por {{ p.by || '—' }}</div>
                                    </td>
                                    <td>
                                        <div class="cell-title fw-normal">{{ p.scope }}</div>
                                        <div v-if="p.targets" class="cell-sub text-truncate cg-col" :title="p.targets">{{ p.targets }}</div>
                                    </td>
                                    <td class="text-nowrap">
                                        <div class="cell-title fw-normal">{{ p.starts_at }}</div>
                                        <div class="cell-sub">hasta {{ p.ended_at || p.ends_at }}</div>
                                    </td>
                                    <td>
                                        <span class="status-pill" :class="status(p)[1]">{{ status(p)[0] }}</span>
                                        <div v-if="p.ended_by" class="cell-sub mt-1">por {{ p.ended_by }}</div>
                                    </td>
                                    <td class="text-end">
                                        <button v-if="p.active" type="button" class="btn btn-sm btn-outline-danger" @click.stop="end(p)"><i class="bi bi-stop-circle me-1"></i>Desactivar</button>
                                        <i v-else class="bi bi-chevron-right text-body-tertiary"></i>
                                    </td>
                                </tr>
                                <tr v-if="!plans.length" class="row-empty">
                                    <td colspan="5" class="text-center">
                                        <i class="bi bi-shield-check fs-3 d-block mb-1 text-body-tertiary"></i>
                                        <span class="cell-sub">Aún no se ha activado ningún plan. Mientras la app funcione, no hace falta.</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section v-if="report" class="surface">
                    <header class="surface-head">
                        <div>
                            <h2 class="surface-title"><i class="bi bi-clipboard-check me-1"></i>Cierre del plan</h2>
                            <p class="surface-sub">{{ report.marked }} de {{ report.covered }} personas marcaron · {{ missing }} sin marcar</p>
                        </div>
                        <a href="/approvals?tab=justifications" class="btn btn-sm btn-light border text-nowrap">
                            {{ report.pending_justifications }} justificación(es)<i class="bi bi-arrow-right ms-1"></i>
                        </a>
                    </header>
                    <div class="surface-pad">
                        <div class="d-flex justify-content-between small mb-1">
                            <span class="text-body-secondary">Cumplimiento</span><span class="fw-semibold">{{ progress }}%</span>
                        </div>
                        <div class="dash-meter" role="progressbar" :aria-valuenow="progress" aria-valuemin="0" aria-valuemax="100">
                            <span class="dash-meter-fill tone-emerald" :style="{ width: progress + '%' }"></span>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table data-table align-middle mb-0">
                            <thead><tr><th>Colaborador</th><th>Marcas registradas</th><th class="text-end">Estado</th></tr></thead>
                            <tbody>
                                <tr v-for="person in report.people" :key="person.document + person.name">
                                    <td>
                                        <div class="cell-title">{{ person.name }}</div>
                                        <div class="cell-sub">DNI {{ person.document }}</div>
                                    </td>
                                    <td>
                                        <div v-if="person.punches.length" class="d-flex flex-wrap gap-1">
                                            <span v-for="(m, i) in person.punches" :key="i" class="status-pill tone-neutral">{{ labels[m.type] }} · {{ m.time }}</span>
                                        </div>
                                        <span v-else class="text-body-tertiary">—</span>
                                    </td>
                                    <td class="text-end">
                                        <span class="status-pill" :class="person.punches.length ? 'tone-emerald' : 'tone-rose'">{{ person.punches.length ? 'Marcó' : 'No marcó' }}</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
    </AppLayout>
</template>

<style>
/* Lista de elección del plan (el resto usa las clases compartidas del panel) */
.cg-picker { border: 1px solid var(--border-color); border-radius: var(--radius-md); overflow: hidden; }
.cg-picker.is-invalid { border-color: var(--bs-danger); }
.cg-picker-search { border-bottom: 1px solid var(--border-color); }
.cg-picker-search .form-control { border: 0; border-radius: 0; box-shadow: none; background: transparent; }
.cg-picker-list { max-height: 240px; overflow-y: auto; padding: 4px; }
.cg-picker-item { display: flex; align-items: center; gap: 10px; margin: 0; padding: 8px 10px; border-radius: 6px; cursor: pointer; font-size: 0.85rem; }
.cg-picker-item:hover { background: var(--bg-surface-elevated); }
.cg-picker-item.is-checked { background: rgba(var(--brand-rgb), 0.06); font-weight: 600; }
.cg-hours { width: 140px; }
.cg-col { max-width: 220px; }
</style>
