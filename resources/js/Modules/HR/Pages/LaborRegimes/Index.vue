<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import AppModal from '@/Modules/Core/Components/AppModal.vue';

const props = defineProps({
    regimes: { type: Array, required: true },
    companyVacationDays: { type: Number, default: 30 },
    parameters: { type: Object, required: true },
    pensions: { type: Array, default: () => [] },
});

const params = useForm({ ...props.parameters });
const saveParams = () => params.put('/labor-regimes/parameters', { preserveScroll: true, onSuccess: () => params.defaults() });
const familyAmount = () => ((Number(params.minimum_wage) || 0) * (Number(params.family_allowance_pct) || 0) / 100).toFixed(2);

const pensionForms = Object.fromEntries(props.pensions.map((p) => [p.id, useForm({
    contribution_pct: p.contribution_pct, insurance_pct: p.insurance_pct, commission_pct: p.commission_pct, is_active: p.is_active,
})]));
const savePension = (p) => pensionForms[p.id].put(`/labor-regimes/pensions/${p.id}`, { preserveScroll: true, onSuccess: () => pensionForms[p.id].defaults() });

const fields = [
    { key: 'vacation_days', label: 'Vacaciones', unit: 'días por año', step: 1 },
    { key: 'gratifications_per_year', label: 'Gratificación', unit: 'sueldos al año', step: 0.5 },
    { key: 'gratification_bonus_pct', label: 'Bonificación extraordinaria', unit: '% de la gratificación', step: 0.01 },
    { key: 'cts_per_year', label: 'CTS', unit: 'sueldos al año', step: 0.5 },
    { key: 'indemnity_days_per_year', label: 'Indemnización por despido', unit: 'días por año de servicio', step: 0.5 },
    { key: 'indemnity_cap_days', label: 'Tope de indemnización', unit: 'días', step: 1 },
];

const editing = ref(null); // null = cerrado, 0 = nuevo, id = editar
const blank = () => ({ name: '', is_active: true, ...Object.fromEntries(fields.map((f) => [f.key, ''])) });
const form = useForm(blank());

function open(regime) {
    editing.value = regime ? regime.id : 0;
    form.defaults(regime ? { name: regime.name, is_active: regime.is_active, ...Object.fromEntries(fields.map((f) => [f.key, regime[f.key] ?? ''])) } : blank());
    form.reset();
    form.clearErrors();
}
function save() {
    const data = (d) => ({ ...d, ...Object.fromEntries(fields.map((f) => [f.key, d[f.key] === '' ? null : d[f.key]])) });
    const opts = { preserveScroll: true, onSuccess: () => { editing.value = null; } };
    editing.value ? form.transform(data).put(`/labor-regimes/${editing.value}`, opts) : form.transform(data).post('/labor-regimes', opts);
}
const show = (r, f) => (r[f.key] === null || r[f.key] === undefined
    ? (f.key === 'vacation_days' && r.code === 'general' ? `${props.companyVacationDays} (empresa)` : '—')
    : r[f.key]);
</script>

<template>
    <AppLayout title="Regímenes laborales">
        <header class="page-head">
            <div class="page-head-text">
                <h1 class="page-title">Regímenes laborales</h1>
                <p class="page-subtitle">
                    Parámetros con que se calculan las vacaciones y la liquidación al cese de cada régimen. Los de régimen general y MYPE
                    vienen con los valores de referencia de la ley: revísalos con tu contador. Un campo vacío significa que ese concepto no se calcula.
                </p>
            </div>
            <div class="page-actions">
                <button type="button" class="btn btn-primary" @click="open(null)"><i class="bi bi-plus-lg me-1"></i>Nuevo régimen</button>
            </div>
        </header>

        <section class="surface mb-3">
            <div class="table-responsive">
                <table class="table data-table table-cards align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Régimen</th>
                            <th v-for="f in fields" :key="f.key" class="text-end">{{ f.label }}</th>
                            <th class="text-end">Personas</th>
                            <th><span class="visually-hidden">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in regimes" :key="r.id" :class="{ 'is-muted': !r.is_active }">
                            <td data-label="Régimen" class="cell-main">
                                <div class="min-w-0">
                                    <div class="cell-title">{{ r.name }}</div>
                                    <span v-if="!r.is_active" class="status-pill tone-neutral">No disponible</span>
                                </div>
                            </td>
                            <td v-for="f in fields" :key="f.key" :data-label="f.label" class="text-end tabular">{{ show(r, f) }}</td>
                            <td data-label="Personas" class="text-end"><span class="cell-amount">{{ r.people }}</span></td>
                            <td class="cell-actions"><div class="row-actions"><button type="button" class="icon-btn" :aria-label="`Editar ${r.name}`" :title="`Editar ${r.name}`" @click="open(r)"><i class="bi bi-pencil"></i></button></div></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <div class="row g-3">
            <div class="col-12 col-lg-4">
                <form class="surface h-100" @submit.prevent="saveParams">
                    <header class="surface-head">
                        <div>
                            <h2 class="surface-title">Asignación familiar</h2>
                            <p class="surface-sub">Para quien la recibe (se marca en su ficha laboral).</p>
                        </div>
                    </header>
                    <div class="surface-pad">
                        <label class="form-label" for="p-rmv">Remuneración mínima vital</label>
                        <input id="p-rmv" v-model="params.minimum_wage" type="number" min="0" step="0.01" class="form-control mb-3" :class="{ 'is-invalid': params.errors.minimum_wage }" required />
                        <div class="invalid-feedback">{{ params.errors.minimum_wage }}</div>
                        <label class="form-label" for="p-pct">Asignación familiar (% de la remuneración mínima)</label>
                        <input id="p-pct" v-model="params.family_allowance_pct" type="number" min="0" max="100" step="0.01" class="form-control" :class="{ 'is-invalid': params.errors.family_allowance_pct }" required />
                        <div class="invalid-feedback">{{ params.errors.family_allowance_pct }}</div>
                        <div class="dash-notice my-3"><i class="bi bi-calculator"></i><span>Monto: <strong class="text-body">{{ familyAmount() }}</strong></span></div>
                        <button type="submit" class="btn btn-primary btn-sm" :disabled="params.processing">Guardar</button>
                    </div>
                </form>
            </div>
            <div class="col-12 col-lg-8">
                <section class="surface h-100">
                    <header class="surface-head">
                        <div>
                            <h2 class="surface-title">Aportes de pensión</h2>
                            <p class="surface-sub">Se descuentan de las vacaciones en la liquidación. Actualízalos cuando cambien; con comisión sobre el flujo, escribe la de cada AFP (con comisión mixta queda en 0).</p>
                        </div>
                    </header>
                    <div class="table-responsive">
                        <table class="table data-table align-middle mb-0">
                            <thead><tr><th>Sistema</th><th>Aporte %</th><th>Seguro %</th><th>Comisión %</th><th>Activo</th><th></th></tr></thead>
                            <tbody>
                                <tr v-for="p in pensions" :key="p.id">
                                    <td class="cell-title">{{ p.name }}</td>
                                    <td v-for="f in ['contribution_pct', 'insurance_pct', 'commission_pct']" :key="f" style="min-width: 88px">
                                        <input v-model="pensionForms[p.id][f]" type="number" min="0" max="50" step="0.01" class="form-control form-control-sm"
                                            :class="{ 'is-invalid': pensionForms[p.id].errors[f] }" :aria-label="`${p.name} ${f}`" />
                                    </td>
                                    <td><div class="form-check form-switch mb-0"><input v-model="pensionForms[p.id].is_active" type="checkbox" role="switch" class="form-check-input" :aria-label="`${p.name} activo`" /></div></td>
                                    <td class="text-end">
                                        <button type="button" class="btn btn-sm" :class="pensionForms[p.id].isDirty ? 'btn-primary' : 'btn-light border'" :disabled="!pensionForms[p.id].isDirty || pensionForms[p.id].processing" @click="savePension(p)">Guardar</button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>

        <AppModal :show="editing !== null" :title="editing ? 'Editar régimen' : 'Nuevo régimen'" subtitle="Un campo vacío significa que ese concepto no se calcula." size="lg" :busy="form.processing" @close="editing = null">
            <form id="regime-form" class="row g-3" @submit.prevent="save">
                <div class="col-12 col-md-6">
                    <label class="form-label" for="regime-name">Nombre</label>
                    <input id="regime-name" v-model="form.name" class="form-control" :class="{ 'is-invalid': form.errors.name }" maxlength="100" required />
                    <div class="invalid-feedback">{{ form.errors.name }}</div>
                </div>
                <div v-if="editing" class="col-12 col-md-6 d-flex align-items-end">
                    <div class="form-check form-switch mb-2">
                        <input id="regime-active" v-model="form.is_active" class="form-check-input" type="checkbox" role="switch" />
                        <label class="form-check-label small" for="regime-active">Disponible para asignar</label>
                    </div>
                </div>
                <div v-for="f in fields" :key="f.key" class="col-6 col-md-4">
                    <label class="form-label" :for="`regime-${f.key}`">{{ f.label }}</label>
                    <div class="input-group has-validation">
                        <input :id="`regime-${f.key}`" v-model="form[f.key]" type="number" min="0" :step="f.step" class="form-control"
                            :class="{ 'is-invalid': form.errors[f.key] }"
                            :placeholder="f.key === 'vacation_days' ? `${companyVacationDays} (de la empresa)` : 'No aplica'" />
                        <span class="input-group-text">{{ f.unit }}</span>
                        <div class="invalid-feedback">{{ form.errors[f.key] }}</div>
                    </div>
                </div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-light border" @click="editing = null">Cancelar</button>
                <button type="submit" form="regime-form" class="btn btn-primary" :disabled="form.processing">Guardar</button>
            </template>
        </AppModal>
    </AppLayout>
</template>
