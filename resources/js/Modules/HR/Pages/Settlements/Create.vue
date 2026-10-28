<script setup>
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import { useConfirm } from '@/Modules/Core/Composables/useConfirm';

const props = defineProps({
    employee: { type: Object, required: true },
    regime: { type: Object, default: null },
    input: { type: Object, required: true },
    result: { type: Object, default: null },
    reasons: { type: Object, required: true },
    history: { type: Array, default: () => [] },
});

const { ask } = useConfirm();
const form = useForm({ ...props.input, notes: '' });
const money = (v) => (v === null || v === undefined ? '—' : Number(v).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
const fmtDate = (iso) => (iso ? iso.split('-').reverse().join('/') : '—');

function recalc() {
    const { notes, ...query } = form.data();
    router.get(`/employees/${props.employee.id}/settlement`, { ...query, indemnity: query.indemnity ? 1 : 0 }, { preserveState: true, preserveScroll: true, replace: true });
}
function onReason() {
    form.indemnity = form.reason === 'arbitrary_dismissal';
    recalc();
}
async function register() {
    const ok = await ask({
        title: '¿Registrar el cese?',
        message: `${props.employee.name} cesa el ${fmtDate(form.termination_date)} con una liquidación de ${money(props.result?.total)}. Desde esa fecha deja de entrar al sistema y a la app.`,
        confirmText: 'Sí, registrar cese',
        variant: 'danger',
        icon: 'bi bi-person-x',
    });
    if (ok) form.transform((d) => ({ ...d, indemnity: !!d.indemnity })).post(`/employees/${props.employee.id}/settlement`);
}
</script>

<template>
    <AppLayout title="Cese y liquidación">
        <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-3">
            <div>
                <h1 class="h4 fw-bold mb-1">Cese y liquidación</h1>
                <p class="text-body-secondary small mb-0">
                    {{ employee.name }} · DNI {{ employee.document }} · {{ employee.position || 'Sin cargo' }} · ingresó el {{ fmtDate(employee.hire_date) }}
                </p>
            </div>
            <a :href="`/employees/${employee.id}/file`" class="btn btn-sm btn-light"><i class="bi bi-folder2-open me-1"></i>Ver legajo</a>
        </div>

        <div v-if="!employee.hire_date" class="alert alert-warning">Registra la fecha de ingreso del colaborador para calcular la liquidación.</div>

        <div class="row g-3">
            <div class="col-12 col-lg-4">
                <form class="card shadow-sm" @submit.prevent="recalc">
                    <div class="card-body">
                        <p class="small mb-3">
                            Régimen: <strong>{{ regime?.name || 'sin régimen en la ficha' }}</strong>
                            <a href="/labor-regimes" class="ms-1 small">parámetros</a>
                        </p>
                        <label class="form-label small fw-semibold" for="s-date">Fecha de cese</label>
                        <input id="s-date" v-model="form.termination_date" type="date" class="form-control form-control-sm mb-2" :class="{ 'is-invalid': form.errors.termination_date }" :min="employee.hire_date" required @change="recalc" />
                        <div class="invalid-feedback">{{ form.errors.termination_date }}</div>
                        <label class="form-label small fw-semibold" for="s-reason">Motivo</label>
                        <select id="s-reason" v-model="form.reason" class="form-select form-select-sm mb-2" @change="onReason">
                            <option v-for="(label, key) in reasons" :key="key" :value="key">{{ label }}</option>
                        </select>
                        <label class="form-label small fw-semibold" for="s-salary">Remuneración computable</label>
                        <input id="s-salary" v-model="form.salary" type="number" min="0" step="0.01" class="form-control form-control-sm" :class="{ 'is-invalid': form.errors.salary }" required @change="recalc" />
                        <div class="form-text small mb-2">Sueldo básico de su ficha; suma la asignación familiar u otros conceptos fijos si corresponde.</div>
                        <label class="form-label small fw-semibold" for="s-pending">Días de vacaciones pendientes</label>
                        <input id="s-pending" v-model="form.pending_days" type="number" min="0" step="1" class="form-control form-control-sm" required @change="recalc" />
                        <div class="form-text small mb-2">Ganados por años cumplidos y no gozados (del saldo de vacaciones).</div>
                        <div class="form-check mb-3">
                            <input id="s-indemnity" v-model="form.indemnity" class="form-check-input" type="checkbox" @change="recalc" />
                            <label class="form-check-label small" for="s-indemnity">Incluir indemnización por despido</label>
                        </div>
                        <label class="form-label small fw-semibold" for="s-notes">Observaciones <span class="fw-normal text-body-secondary">(opcional)</span></label>
                        <textarea id="s-notes" v-model="form.notes" class="form-control form-control-sm" rows="2" maxlength="1000"></textarea>
                    </div>
                </form>
            </div>

            <div class="col-12 col-lg-8">
                <div v-if="result" class="card shadow-sm">
                    <div class="card-body">
                        <div v-for="w in result.warnings" :key="w" class="alert alert-warning py-2 small">{{ w }}</div>
                        <p class="small text-body-secondary mb-2">Tiempo de servicio: {{ result.service }}</p>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle small mb-2">
                                <thead class="table-light"><tr><th>Concepto</th><th>Cálculo</th><th class="text-end">Monto</th></tr></thead>
                                <tbody>
                                    <tr v-for="l in result.lines" :key="l.key">
                                        <td class="fw-semibold">{{ l.label }}</td>
                                        <td :class="l.amount === null ? 'text-warning-emphasis' : 'text-body-secondary'">{{ l.basis }}</td>
                                        <td class="text-end">{{ money(l.amount) }}</td>
                                    </tr>
                                </tbody>
                                <tfoot><tr class="fw-bold"><td colspan="2">Total bruto</td><td class="text-end">{{ money(result.total) }}</td></tr></tfoot>
                            </table>
                        </div>
                        <p class="small text-body-secondary">Montos brutos: no descuentan pensión ni renta de quinta. El pago se programa en Tesorería con el concepto «Liquidación de beneficios sociales».</p>
                        <div class="d-flex justify-content-end">
                            <button type="button" class="btn btn-danger btn-sm" :disabled="form.processing" @click="register">
                                <i class="bi bi-person-x me-1"></i>Registrar cese
                            </button>
                        </div>
                    </div>
                </div>

                <div v-if="history.length" class="card shadow-sm mt-3">
                    <div class="card-body">
                        <h2 class="h6 fw-bold mb-2">Liquidaciones registradas</h2>
                        <ul class="list-unstyled small mb-0">
                            <li v-for="h in history" :key="h.id" class="d-flex justify-content-between border-bottom py-1">
                                <span>{{ h.date }} · {{ h.reason }} · {{ money(h.total) }}</span>
                                <a :href="`/settlements/${h.id}/print`" target="_blank" rel="noopener">Imprimir</a>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
