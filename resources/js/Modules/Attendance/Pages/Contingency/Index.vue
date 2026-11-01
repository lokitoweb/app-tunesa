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
const status = (p) => (p.active ? ['Activo', 'is-active'] : p.ended_at ? ['Desactivado', 'is-ended'] : ['Vencido', 'is-expired']);
</script>

<template>
    <AppLayout title="Plan de contingencia">
        <!-- Cabecera -->
        <header class="cg-head">
            <span class="cg-head-icon" aria-hidden="true"><i class="bi bi-life-preserver"></i></span>
            <div class="cg-head-text">
                <h1 class="page-title mb-1">Plan de contingencia</h1>
                <p class="page-subtitle mb-0">
                    Si la app no funciona (caída, sin señal, falla del sistema), las personas cubiertas marcan sus 4 marcas desde su portal web,
                    con la hora del servidor y sin GPS. El plan se apaga solo al vencer y cada marca queda etiquetada.
                </p>
            </div>
            <span class="cg-state" :class="active.length ? 'is-on' : 'is-off'" role="status">
                <span class="cg-state-dot" aria-hidden="true"></span>
                {{ active.length ? `${active.length} plan(es) activo(s)` : 'Sin planes activos' }}
            </span>
        </header>

        <!-- Indicadores -->
        <section class="cg-stats" aria-label="Resumen">
            <div class="cg-stat">
                <span class="cg-stat-label">Planes activos</span>
                <span class="cg-stat-value" :class="{ 'text-warning-emphasis': active.length }">{{ active.length }}</span>
            </div>
            <div class="cg-stat">
                <span class="cg-stat-label">Planes registrados</span>
                <span class="cg-stat-value">{{ plans.length }}</span>
            </div>
            <div class="cg-stat">
                <span class="cg-stat-label">Justificaciones por revisar</span>
                <span class="cg-stat-value">{{ report ? report.pending_justifications : '—' }}</span>
            </div>
        </section>

        <div class="row g-4">
            <!-- Activar -->
            <div class="col-12 col-xl-5">
                <form class="cg-card" novalidate @submit.prevent="activate">
                    <div class="cg-card-head">
                        <h2 class="cg-card-title">Activar un plan</h2>
                        <span class="cg-card-hint">Queda en la bitácora</span>
                    </div>

                    <div class="cg-card-body">
                        <section class="cg-step">
                            <div class="cg-step-label"><span class="cg-step-num">1</span>¿Para quién?</div>
                            <div class="cg-segment" role="radiogroup" aria-label="Alcance">
                                <button v-for="(label, key) in options.scopes" :key="key" type="button" role="radio" :aria-checked="form.scope === key"
                                    class="cg-segment-btn" :class="{ active: form.scope === key }" @click="form.scope = key">
                                    <i class="bi" :class="scopeIcons[key]" aria-hidden="true"></i><span>{{ label }}</span>
                                </button>
                            </div>
                        </section>

                        <section class="cg-step">
                            <div class="cg-step-label">
                                <span class="cg-step-num">2</span>¿Cuáles?
                                <span v-if="form.scope !== 'all'" class="cg-count">{{ form.scope_ids.length }} de {{ targets.length }}</span>
                                <button v-if="form.scope !== 'all'" type="button" class="cg-link ms-auto" @click="toggleAll">{{ allVisibleChosen ? 'Quitar todos' : 'Elegir todos' }}</button>
                            </div>
                            <template v-if="form.scope !== 'all'">
                                <div class="cg-picker" :class="{ 'is-invalid': form.errors.scope_ids }">
                                    <label class="cg-picker-search">
                                        <i class="bi bi-search" aria-hidden="true"></i>
                                        <input v-model="search" type="search" placeholder="Buscar…" aria-label="Buscar" />
                                    </label>
                                    <div class="cg-picker-list">
                                        <label v-for="t in visible" :key="t.id" class="cg-picker-item" :class="{ checked: form.scope_ids.includes(t.id) }">
                                            <input v-model="form.scope_ids" type="checkbox" class="form-check-input mt-0" :value="t.id" />
                                            <span class="text-truncate">{{ t.name }}</span>
                                        </label>
                                        <p v-if="!visible.length" class="cg-empty-line">Sin resultados.</p>
                                    </div>
                                </div>
                                <div v-if="form.errors.scope_ids" class="small text-danger mt-1">{{ form.errors.scope_ids }}</div>
                            </template>
                            <p v-else class="cg-note"><i class="bi bi-info-circle" aria-hidden="true"></i>Cubre a todo el personal de la empresa.</p>
                        </section>

                        <section class="cg-step mb-0">
                            <div class="cg-step-label"><span class="cg-step-num">3</span>Motivo y duración</div>
                            <label class="cg-field-label" for="cg-reason">Motivo</label>
                            <input id="cg-reason" v-model="form.reason" class="form-control" :class="{ 'is-invalid': form.errors.reason }" maxlength="200" placeholder="Ej.: caída de la app en la sede" required />
                            <div class="invalid-feedback">{{ form.errors.reason }}</div>

                            <label class="cg-field-label mt-3" for="cg-hours">Duración</label>
                            <div class="cg-duration">
                                <div class="input-group cg-hours">
                                    <input id="cg-hours" v-model="form.hours" type="number" min="1" :max="options.max_hours" class="form-control" :class="{ 'is-invalid': form.errors.hours }" required />
                                    <span class="input-group-text">h</span>
                                </div>
                                <div class="cg-presets" role="group" aria-label="Duración rápida">
                                    <button v-for="h in presets" :key="h" type="button" class="cg-preset" :class="{ active: Number(form.hours) === h }" @click="form.hours = h">{{ h }} h</button>
                                </div>
                            </div>
                            <div class="cg-help">Máximo {{ options.max_hours }} h. Se desactiva solo al vencer.</div>
                            <div v-if="form.errors.hours" class="small text-danger mt-1">{{ form.errors.hours }}</div>
                        </section>
                    </div>

                    <div class="cg-card-foot">
                        <p class="cg-warning"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i>Las marcas se registran sin GPS: úsalo solo ante una falla real.</p>
                        <button type="submit" class="btn btn-warning cg-submit" :disabled="form.processing || !ready">
                            <span v-if="form.processing" class="spinner-border spinner-border-sm me-1"></span>
                            <i v-else class="bi bi-life-preserver me-1"></i>Activar plan
                        </button>
                    </div>
                </form>
            </div>

            <!-- Historial y cierre -->
            <div class="col-12 col-xl-7">
                <div class="cg-card mb-4">
                    <div class="cg-card-head">
                        <h2 class="cg-card-title">Historial de planes</h2>
                        <span class="cg-card-hint">Clic en un plan para ver su cierre</span>
                    </div>
                    <div v-if="!plans.length" class="cg-empty">
                        <i class="bi bi-shield-check" aria-hidden="true"></i>
                        <p class="mb-0">Aún no se ha activado ningún plan. Mientras la app funcione, no hace falta.</p>
                    </div>
                    <div v-else class="table-responsive">
                        <table class="table cg-table align-middle mb-0">
                            <thead>
                                <tr><th>Motivo</th><th>Alcance</th><th>Vigencia</th><th>Estado</th><th class="text-end"><span class="visually-hidden">Acciones</span></th></tr>
                            </thead>
                            <tbody>
                                <tr v-for="p in plans" :key="p.id" class="cg-row" :class="{ 'is-selected': report?.id === p.id }" tabindex="0"
                                    @click="show(p.id)" @keydown.enter="show(p.id)">
                                    <td>
                                        <div class="fw-semibold text-truncate cg-reason">{{ p.reason }}</div>
                                        <div class="cg-muted">por {{ p.by || '—' }}</div>
                                    </td>
                                    <td>
                                        <div>{{ p.scope }}</div>
                                        <div v-if="p.targets" class="cg-muted text-truncate cg-targets" :title="p.targets">{{ p.targets }}</div>
                                    </td>
                                    <td class="text-nowrap">
                                        <div>{{ p.starts_at }}</div>
                                        <div class="cg-muted">hasta {{ p.ended_at || p.ends_at }}</div>
                                    </td>
                                    <td>
                                        <span class="cg-badge" :class="status(p)[1]">{{ status(p)[0] }}</span>
                                        <div v-if="p.ended_by" class="cg-muted">por {{ p.ended_by }}</div>
                                    </td>
                                    <td class="text-end">
                                        <button v-if="p.active" type="button" class="btn btn-sm btn-outline-danger cg-stop" @click.stop="end(p)">
                                            <i class="bi bi-stop-circle me-1"></i>Desactivar
                                        </button>
                                        <i v-else class="bi bi-chevron-right cg-muted" aria-hidden="true"></i>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div v-if="report" class="cg-card">
                    <div class="cg-card-head">
                        <h2 class="cg-card-title">Cierre del plan</h2>
                        <a href="/approvals?tab=justifications" class="cg-link">{{ report.pending_justifications }} justificación(es) por revisar <i class="bi bi-arrow-right"></i></a>
                    </div>
                    <div class="cg-card-body">
                        <div class="cg-summary">
                            <div><span class="cg-summary-value">{{ report.covered }}</span><span class="cg-summary-label">Cubiertos</span></div>
                            <div><span class="cg-summary-value text-success">{{ report.marked }}</span><span class="cg-summary-label">Marcaron</span></div>
                            <div><span class="cg-summary-value" :class="{ 'text-danger': missing }">{{ missing }}</span><span class="cg-summary-label">No marcaron</span></div>
                            <div class="cg-summary-meter">
                                <div class="d-flex justify-content-between cg-muted mb-1"><span>Cumplimiento</span><span class="fw-semibold">{{ progress }}%</span></div>
                                <div class="dash-meter" role="progressbar" :aria-valuenow="progress" aria-valuemin="0" aria-valuemax="100">
                                    <span class="dash-meter-fill tone-emerald" :style="{ width: progress + '%' }"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table cg-table align-middle mb-0">
                            <thead><tr><th>Colaborador</th><th>Marcas registradas</th><th class="text-end">Estado</th></tr></thead>
                            <tbody>
                                <tr v-for="person in report.people" :key="person.document + person.name">
                                    <td>
                                        <div class="fw-semibold">{{ person.name }}</div>
                                        <div class="cg-muted">DNI {{ person.document }}</div>
                                    </td>
                                    <td>
                                        <div v-if="person.punches.length" class="cg-punches">
                                            <span v-for="(m, i) in person.punches" :key="i" class="cg-punch"><b>{{ labels[m.type] }}</b>{{ m.time }}</span>
                                        </div>
                                        <span v-else class="cg-muted">—</span>
                                    </td>
                                    <td class="text-end">
                                        <span class="cg-badge" :class="person.punches.length ? 'is-ok' : 'is-missing'">{{ person.punches.length ? 'Marcó' : 'No marcó' }}</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style>
/* Plan de contingencia: esquinas moderadas (8px tarjetas, 6px controles), claro y oscuro con variables del panel */
.cg-head { display: flex; align-items: flex-start; gap: 16px; margin-bottom: 20px; }
.cg-head-text { flex: 1; min-width: 0; max-width: 860px; }
.cg-head-icon { width: 44px; height: 44px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; font-size: 1.3rem;
    background: rgba(var(--bs-warning-rgb), .14); color: var(--bs-warning-text-emphasis); flex-shrink: 0; }
.cg-state { display: inline-flex; align-items: center; gap: 8px; padding: 6px 12px; border-radius: 6px; font-size: .8rem; font-weight: 600; white-space: nowrap;
    border: 1px solid var(--border-color); background: var(--bg-surface); color: var(--text-secondary); }
.cg-state-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--bs-secondary-color); }
.cg-state.is-on { border-color: rgba(var(--bs-warning-rgb), .5); background: rgba(var(--bs-warning-rgb), .1); color: var(--bs-warning-text-emphasis); }
.cg-state.is-on .cg-state-dot { background: var(--bs-warning); box-shadow: 0 0 0 3px rgba(var(--bs-warning-rgb), .25); }

.cg-stats { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin-bottom: 20px; }
.cg-stat { display: flex; flex-direction: column; gap: 2px; padding: 12px 16px; border: 1px solid var(--border-color); border-radius: 8px; background: var(--bg-surface); }
.cg-stat-label { font-size: .72rem; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: var(--text-secondary); }
.cg-stat-value { font-size: 1.4rem; font-weight: 700; line-height: 1.2; font-variant-numeric: tabular-nums; }

.cg-card { border: 1px solid var(--border-color); border-radius: 8px; background: var(--bg-surface); box-shadow: var(--shadow-xs); overflow: hidden; }
.cg-card-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 14px 20px; border-bottom: 1px solid var(--border-color); }
.cg-card-title { font-size: .95rem; font-weight: 700; margin: 0; }
.cg-card-hint { font-size: .75rem; color: var(--text-secondary); }
.cg-card-body { padding: 16px 20px; }
.cg-card-foot { padding: 14px 20px; border-top: 1px solid var(--border-color); background: var(--bs-tertiary-bg); }

.cg-step { margin-bottom: 20px; }
.cg-step-label { display: flex; align-items: center; gap: 8px; font-size: .82rem; font-weight: 700; margin-bottom: 10px; }
.cg-step-num { width: 20px; height: 20px; border-radius: 4px; display: inline-flex; align-items: center; justify-content: center; font-size: .7rem;
    background: rgba(var(--brand-rgb), .1); color: var(--brand); }
.cg-count { font-size: .72rem; font-weight: 600; padding: 2px 8px; border-radius: 4px; background: var(--bs-tertiary-bg); color: var(--text-secondary); }
.cg-link { background: none; border: 0; padding: 0; font-size: .78rem; font-weight: 600; color: var(--brand); text-decoration: none; }
.cg-link:hover { text-decoration: underline; }

.cg-segment { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); padding: 3px; gap: 3px; border: 1px solid var(--border-color); border-radius: 6px; background: var(--bs-tertiary-bg); }
.cg-segment-btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; height: 34px; padding: 0 8px; border: 0; border-radius: 4px;
    background: transparent; color: var(--text-secondary); font-size: .78rem; font-weight: 600; white-space: nowrap; transition: background-color .15s, color .15s; }
.cg-segment-btn:hover { color: var(--bs-body-color); }
.cg-segment-btn:focus-visible { outline: 2px solid var(--brand); outline-offset: 1px; }
.cg-segment-btn.active { background: var(--bg-surface); color: var(--brand); box-shadow: 0 1px 2px rgba(15, 23, 42, .12); }

.cg-picker { border: 1px solid var(--border-color); border-radius: 6px; overflow: hidden; }
.cg-picker.is-invalid { border-color: var(--bs-danger); }
.cg-picker-search { display: flex; align-items: center; gap: 8px; margin: 0; padding: 0 12px; height: 36px; border-bottom: 1px solid var(--border-color); color: var(--text-secondary); }
.cg-picker-search input { flex: 1; border: 0; outline: 0; background: transparent; color: var(--bs-body-color); font-size: .85rem; }
.cg-picker-list { max-height: 232px; overflow-y: auto; padding: 4px; }
.cg-picker-item { display: flex; align-items: center; gap: 10px; padding: 7px 10px; border-radius: 4px; cursor: pointer; font-size: .85rem; margin: 0; }
.cg-picker-item:hover { background: var(--bs-tertiary-bg); }
.cg-picker-item.checked { background: rgba(var(--brand-rgb), .07); font-weight: 600; }
.cg-empty-line { font-size: .8rem; color: var(--text-secondary); text-align: center; padding: 12px 0; margin: 0; }
.cg-note { display: flex; align-items: center; gap: 8px; margin: 0; padding: 10px 12px; border-radius: 6px; font-size: .82rem; background: var(--bs-tertiary-bg); color: var(--text-secondary); }

.cg-field-label { display: block; font-size: .78rem; font-weight: 600; margin-bottom: 6px; color: var(--text-secondary); }
.cg-duration { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
.cg-hours { width: 110px; }
.cg-presets { display: flex; flex-wrap: wrap; gap: 6px; }
.cg-preset { height: 32px; padding: 0 10px; border: 1px solid var(--border-color); border-radius: 6px; background: var(--bg-surface); color: var(--text-secondary); font-size: .78rem; font-weight: 600; }
.cg-preset:hover { border-color: var(--brand); color: var(--bs-body-color); }
.cg-preset.active { border-color: var(--brand); background: rgba(var(--brand-rgb), .08); color: var(--brand); }
.cg-help { font-size: .75rem; color: var(--text-secondary); margin-top: 6px; }

.cg-warning { display: flex; align-items: flex-start; gap: 8px; font-size: .78rem; color: var(--text-secondary); margin: 0 0 10px; }
.cg-warning i { color: var(--bs-warning-text-emphasis); margin-top: 1px; }
.cg-submit { width: 100%; height: 40px; border-radius: 6px; font-weight: 600; }

.cg-table { font-size: .84rem; }
.cg-table thead th { font-size: .7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--text-secondary); background: var(--bs-tertiary-bg);
    border-bottom: 1px solid var(--border-color); padding: 10px 20px; white-space: nowrap; }
.cg-table tbody td { padding: 12px 20px; border-color: var(--border-color); background: transparent; }
.cg-row { cursor: pointer; }
.cg-row:hover td { background: var(--bs-tertiary-bg); }
.cg-row:focus-visible { outline: 2px solid var(--brand); outline-offset: -2px; }
.cg-row.is-selected td { background: rgba(var(--brand-rgb), .06); }
.cg-row.is-selected td:first-child { box-shadow: inset 3px 0 0 var(--brand); }
.cg-reason { max-width: 220px; }
.cg-targets { max-width: 200px; }
.cg-muted { font-size: .75rem; color: var(--text-secondary); }
.cg-stop { border-radius: 6px; font-size: .75rem; white-space: nowrap; }

.cg-badge { display: inline-flex; align-items: center; padding: 3px 8px; border-radius: 4px; font-size: .72rem; font-weight: 600; white-space: nowrap; border: 1px solid transparent; }
.cg-badge.is-active { background: rgba(var(--bs-warning-rgb), .14); color: var(--bs-warning-text-emphasis); border-color: rgba(var(--bs-warning-rgb), .35); }
.cg-badge.is-ended, .cg-badge.is-expired { background: var(--bs-tertiary-bg); color: var(--text-secondary); border-color: var(--border-color); }
.cg-badge.is-ok { background: rgba(var(--bs-success-rgb), .12); color: var(--bs-success-text-emphasis); border-color: rgba(var(--bs-success-rgb), .3); }
.cg-badge.is-missing { background: rgba(var(--bs-danger-rgb), .1); color: var(--bs-danger-text-emphasis); border-color: rgba(var(--bs-danger-rgb), .3); }

.cg-summary { display: grid; grid-template-columns: repeat(3, auto) minmax(160px, 1fr); align-items: center; gap: 24px; }
.cg-summary > div:not(.cg-summary-meter) { display: flex; flex-direction: column; }
.cg-summary-value { font-size: 1.35rem; font-weight: 700; line-height: 1.2; font-variant-numeric: tabular-nums; }
.cg-summary-label { font-size: .72rem; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; letter-spacing: .04em; }
.cg-punches { display: flex; flex-wrap: wrap; gap: 6px; }
.cg-punch { display: inline-flex; gap: 6px; padding: 3px 8px; border-radius: 4px; font-size: .74rem; border: 1px solid var(--border-color); background: var(--bs-tertiary-bg); font-variant-numeric: tabular-nums; }
.cg-punch b { font-weight: 600; }

.cg-empty { display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 40px 20px; text-align: center; color: var(--text-secondary); font-size: .85rem; }
.cg-empty i { font-size: 1.8rem; opacity: .5; }

@media (max-width: 767.98px) {
    .cg-head { flex-wrap: wrap; }
    .cg-stats { gap: 8px; }
    .cg-stat { padding: 10px 12px; }
    .cg-stat-label { font-size: .62rem; }
    .cg-stat-value { font-size: 1.15rem; }
    .cg-segment { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .cg-summary { grid-template-columns: repeat(3, 1fr); }
    .cg-summary-meter { grid-column: 1 / -1; }
}
</style>
