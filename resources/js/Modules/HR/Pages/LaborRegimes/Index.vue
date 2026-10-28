<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';

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
        <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-3">
            <div>
                <h1 class="h4 fw-bold mb-1">Regímenes laborales</h1>
                <p class="text-body-secondary small mb-0">
                    Parámetros con que se calculan las vacaciones y la liquidación al cese de cada régimen. Los de régimen general y MYPE
                    vienen con los valores de referencia de la ley: revísalos con tu contador. Un campo vacío significa que ese concepto no se calcula.
                </p>
            </div>
            <button type="button" class="btn btn-primary btn-sm" @click="open(null)"><i class="bi bi-plus-lg me-1"></i>Nuevo régimen</button>
        </div>

        <form v-if="editing !== null" class="card shadow-sm mb-3" @submit.prevent="save">
            <div class="card-body">
                <h2 class="h6 fw-bold mb-3">{{ editing ? 'Editar régimen' : 'Nuevo régimen' }}</h2>
                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <label class="form-label small fw-semibold" for="regime-name">Nombre</label>
                        <input id="regime-name" v-model="form.name" class="form-control form-control-sm" :class="{ 'is-invalid': form.errors.name }" maxlength="100" required />
                        <div class="invalid-feedback">{{ form.errors.name }}</div>
                    </div>
                    <div v-if="editing" class="col-12 col-md-6 d-flex align-items-end">
                        <div class="form-check form-switch">
                            <input id="regime-active" v-model="form.is_active" class="form-check-input" type="checkbox" />
                            <label class="form-check-label small" for="regime-active">Disponible para asignar</label>
                        </div>
                    </div>
                    <div v-for="f in fields" :key="f.key" class="col-6 col-md-4">
                        <label class="form-label small fw-semibold" :for="`regime-${f.key}`">{{ f.label }}</label>
                        <div class="input-group input-group-sm">
                            <input :id="`regime-${f.key}`" v-model="form[f.key]" type="number" min="0" :step="f.step" class="form-control"
                                :class="{ 'is-invalid': form.errors[f.key] }"
                                :placeholder="f.key === 'vacation_days' ? `${companyVacationDays} (de la empresa)` : 'No aplica'" />
                            <span class="input-group-text">{{ f.unit }}</span>
                            <div class="invalid-feedback">{{ form.errors[f.key] }}</div>
                        </div>
                    </div>
                </div>
                <div class="d-flex justify-content-end gap-2 mt-3">
                    <button type="button" class="btn btn-sm btn-light" @click="editing = null">Cancelar</button>
                    <button type="submit" class="btn btn-sm btn-primary" :disabled="form.processing">Guardar</button>
                </div>
            </div>
        </form>

        <div class="card shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead class="table-light">
                        <tr>
                            <th>Régimen</th>
                            <th v-for="f in fields" :key="f.key" class="text-end">{{ f.label }}</th>
                            <th class="text-end">Personas</th>
                            <th><span class="visually-hidden">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in regimes" :key="r.id" :class="{ 'opacity-50': !r.is_active }">
                            <td class="fw-semibold">{{ r.name }}<span v-if="!r.is_active" class="badge bg-secondary-subtle text-secondary ms-1">no disponible</span></td>
                            <td v-for="f in fields" :key="f.key" class="text-end">{{ show(r, f) }}</td>
                            <td class="text-end">{{ r.people }}</td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-light" :aria-label="`Editar ${r.name}`" @click="open(r)"><i class="bi bi-pencil"></i></button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="row g-3 mt-1">
            <div class="col-12 col-lg-4">
                <form class="card shadow-sm h-100" @submit.prevent="saveParams">
                    <div class="card-body">
                        <h2 class="h6 fw-bold mb-1">Asignación familiar</h2>
                        <p class="small text-body-secondary">Para quien la recibe (se marca en su ficha laboral).</p>
                        <label class="form-label small fw-semibold" for="p-rmv">Remuneración mínima vital</label>
                        <input id="p-rmv" v-model="params.minimum_wage" type="number" min="0" step="0.01" class="form-control form-control-sm mb-2" :class="{ 'is-invalid': params.errors.minimum_wage }" required />
                        <div class="invalid-feedback">{{ params.errors.minimum_wage }}</div>
                        <label class="form-label small fw-semibold" for="p-pct">Asignación familiar (% de la remuneración mínima)</label>
                        <input id="p-pct" v-model="params.family_allowance_pct" type="number" min="0" max="100" step="0.01" class="form-control form-control-sm" :class="{ 'is-invalid': params.errors.family_allowance_pct }" required />
                        <div class="invalid-feedback">{{ params.errors.family_allowance_pct }}</div>
                        <p class="small mt-2 mb-3">Monto: <strong>{{ familyAmount() }}</strong></p>
                        <button type="submit" class="btn btn-sm btn-primary" :disabled="params.processing">Guardar</button>
                    </div>
                </form>
            </div>
            <div class="col-12 col-lg-8">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <h2 class="h6 fw-bold mb-1">Aportes de pensión</h2>
                        <p class="small text-body-secondary">Se descuentan de las vacaciones en la liquidación. Actualízalos cuando cambien; con comisión sobre el flujo, escribe la de cada AFP (con comisión mixta queda en 0).</p>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle small mb-0">
                                <thead class="table-light"><tr><th>Sistema</th><th>Aporte %</th><th>Seguro %</th><th>Comisión %</th><th>Activo</th><th></th></tr></thead>
                                <tbody>
                                    <tr v-for="p in pensions" :key="p.id">
                                        <td class="fw-semibold">{{ p.name }}</td>
                                        <td v-for="f in ['contribution_pct', 'insurance_pct', 'commission_pct']" :key="f" style="min-width: 88px">
                                            <input v-model="pensionForms[p.id][f]" type="number" min="0" max="50" step="0.01" class="form-control form-control-sm"
                                                :class="{ 'is-invalid': pensionForms[p.id].errors[f] }" :aria-label="`${p.name} ${f}`" />
                                        </td>
                                        <td><input v-model="pensionForms[p.id].is_active" type="checkbox" class="form-check-input" :aria-label="`${p.name} activo`" /></td>
                                        <td class="text-end">
                                            <button type="button" class="btn btn-sm btn-light" :disabled="!pensionForms[p.id].isDirty || pensionForms[p.id].processing" @click="savePension(p)">Guardar</button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
