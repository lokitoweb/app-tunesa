<script setup>
import { useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import { useConfirm } from '@/Modules/Core/Composables/useConfirm';

const props = defineProps({
    employee: { type: Object, required: true },
    regime: { type: Object, default: null },
    input: { type: Object, required: true },
    result: { type: Object, default: null },
    reasons: { type: Object, required: true },
    pensions: { type: Array, default: () => [] },
    signatories: { type: Array, default: () => [] },
    familyAmount: { type: Number, default: 0 },
    history: { type: Array, default: () => [] },
});

const { ask } = useConfirm();
const { ot_months: _months, family_allowance: _family, ...initial } = props.input;
const form = useForm({ ...initial, pension_rate_id: initial.pension_rate_id ?? '', signatory_id: initial.signatory_id ?? '', notes: '' });
const money = (v) => (v === null || v === undefined ? '—' : Number(v).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
const fmtDate = (iso) => (iso ? iso.split('-').reverse().join('/') : '—');

function payload() {
    const { notes, ...d } = form.data();
    return { ...d, family: d.family ? 1 : 0, overtime: d.overtime ? 1 : 0, indemnity: d.indemnity ? 1 : 0 };
}
function recalc() {
    router.get(`/employees/${props.employee.id}/settlement`, payload(), {
        preserveState: true, preserveScroll: true, replace: true,
        // Lo que el servidor completó (promedios al activar horas extras) vuelve al formulario
        onSuccess: (page) => ['ot_cts', 'ot_vacation', 'ot_gratification', 'pending_days'].forEach((k) => { form[k] = page.props.input[k]; }),
    });
}
function toggleOvertime() {
    ['ot_cts', 'ot_vacation', 'ot_gratification'].forEach((k) => { form[k] = ''; });
    recalc();
}
function onReason() {
    form.indemnity = form.reason === 'arbitrary_dismissal';
    recalc();
}
const addLine = (list) => form[list].push({ label: '', amount: '' });
function removeLine(list, i) {
    form[list].splice(i, 1);
    recalc();
}
async function register() {
    const ok = await ask({
        title: '¿Registrar el cese?',
        message: `${props.employee.name} cesa el ${fmtDate(form.termination_date)} con un neto de ${money(props.result?.total)}. Desde esa fecha deja de entrar al sistema y a la app.`,
        confirmText: 'Sí, registrar cese',
        variant: 'danger',
        icon: 'bi bi-person-x',
    });
    if (ok) form.transform(() => ({ ...payload(), notes: form.notes })).post(`/employees/${props.employee.id}/settlement`);
}
</script>

<template>
    <AppLayout title="Cese y liquidación">
        <header class="page-head">
            <div class="page-head-text">
                <h1 class="page-title">Cese y liquidación</h1>
                <p class="page-subtitle">
                    {{ employee.name }} · DNI {{ employee.document }} · {{ employee.position || 'Sin cargo' }} · ingresó el {{ fmtDate(employee.hire_date) }}
                </p>
            </div>
            <div class="page-actions">
                <a :href="`/employees/${employee.id}/file`" class="btn btn-light border"><i class="bi bi-folder2-open me-1"></i>Ver legajo</a>
            </div>
        </header>

        <div v-if="!employee.hire_date" class="dash-notice notice-warning"><i class="bi bi-exclamation-triangle"></i><span>Registra la fecha de ingreso del colaborador para calcular la liquidación.</span></div>

        <div class="row g-3">
            <div class="col-12 col-lg-4">
                <form class="surface" @submit.prevent="recalc">
                    <header class="surface-head">
                        <div>
                            <h2 class="surface-title">Datos del cese</h2>
                            <p class="surface-sub">Régimen: <strong class="text-body">{{ regime?.name || 'sin régimen en la ficha' }}</strong> · <a href="/labor-regimes">parámetros</a></p>
                        </div>
                    </header>
                    <div class="surface-pad">
                        <label class="form-label small fw-semibold" for="s-date">Fecha de cese</label>
                        <input id="s-date" v-model="form.termination_date" type="date" class="form-control form-control-sm mb-2" :class="{ 'is-invalid': form.errors.termination_date }" :min="employee.hire_date" required @change="recalc" />
                        <div class="invalid-feedback">{{ form.errors.termination_date }}</div>
                        <label class="form-label small fw-semibold" for="s-reason">Motivo</label>
                        <select id="s-reason" v-model="form.reason" class="form-select form-select-sm mb-3" @change="onReason">
                            <option v-for="(label, key) in reasons" :key="key" :value="key">{{ label }}</option>
                        </select>

                        <h3 class="st-section">Remuneración</h3>
                        <label class="form-label small fw-semibold" for="s-salary">Remuneración básica</label>
                        <input id="s-salary" v-model="form.salary" type="number" min="0" step="0.01" class="form-control form-control-sm mb-2" :class="{ 'is-invalid': form.errors.salary }" required @change="recalc" />
                        <div class="form-check">
                            <input id="s-family" v-model="form.family" class="form-check-input" type="checkbox" @change="recalc" />
                            <label class="form-check-label small" for="s-family">Asignación familiar ({{ money(familyAmount) }})</label>
                        </div>
                        <div class="form-check">
                            <input id="s-overtime" v-model="form.overtime" class="form-check-input" type="checkbox" @change="toggleOvertime" />
                            <label class="form-check-label small" for="s-overtime">Promedio de horas extras (de la asistencia)</label>
                        </div>
                        <div v-if="form.overtime" class="row g-2 mt-1 mb-1">
                            <div v-for="[k, label] in [['ot_cts', 'CTS'], ['ot_vacation', 'Vacaciones'], ['ot_gratification', 'Gratificación']]" :key="k" class="col-4">
                                <label class="form-label small mb-0" :for="`s-${k}`">{{ label }}</label>
                                <input :id="`s-${k}`" v-model="form[k]" type="number" min="0" step="0.01" class="form-control form-control-sm" @change="recalc" />
                            </div>
                            <div class="col-12 form-text small mt-0">Horas extra aprobadas de los últimos 6 meses (2 primeras al 25 %, el resto al 35 %); cuentan si hubo en 3 meses o más.</div>
                        </div>
                        <label class="form-label small fw-semibold mt-2" for="s-other">Otros conceptos fijos <span class="fw-normal text-body-secondary">(opcional)</span></label>
                        <input id="s-other" v-model="form.other_computable" type="number" min="0" step="0.01" class="form-control form-control-sm" @change="recalc" />
                        <div class="form-text small mb-2">Montos fijos que cuentan para los beneficios, p. ej. movilidad de libre disposición.</div>

                        <label class="form-label small fw-semibold" for="s-pending">Días de vacaciones pendientes</label>
                        <input id="s-pending" v-model="form.pending_days" type="number" min="0" step="1" class="form-control form-control-sm" required @change="recalc" />
                        <div class="form-text small mb-2">Ganados por años cumplidos y no gozados (del saldo de vacaciones).</div>
                        <div class="form-check mb-3">
                            <input id="s-indemnity" v-model="form.indemnity" class="form-check-input" type="checkbox" @change="recalc" />
                            <label class="form-check-label small" for="s-indemnity">Incluir indemnización por despido</label>
                        </div>

                        <h3 class="st-section">Descuentos y otros conceptos</h3>
                        <label class="form-label small fw-semibold" for="s-pension">Aporte de pensión</label>
                        <select id="s-pension" v-model="form.pension_rate_id" class="form-select form-select-sm mb-3" @change="recalc">
                            <option value="">Sin aporte</option>
                            <option v-for="p in pensions" :key="p.id" :value="p.id">{{ p.name }}</option>
                        </select>

                        <div v-for="[list, title] in [['other_incomes', 'Otros ingresos'], ['other_discounts', 'Otros descuentos']]" :key="list" class="mb-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="small fw-semibold">{{ title }}</span>
                                <button type="button" class="btn btn-sm btn-link p-0" @click="addLine(list)"><i class="bi bi-plus-lg"></i> Agregar</button>
                            </div>
                            <div v-for="(o, i) in form[list]" :key="i" class="input-group input-group-sm mt-1">
                                <input v-model="o.label" class="form-control" placeholder="Concepto" maxlength="80" :aria-label="`${title}: concepto`" @change="recalc" />
                                <input v-model="o.amount" type="number" min="0" step="0.01" class="form-control" style="max-width: 110px" placeholder="Monto" :aria-label="`${title}: monto`" @change="recalc" />
                                <button type="button" class="btn btn-outline-secondary" :aria-label="`Quitar ${title}`" @click="removeLine(list, i)"><i class="bi bi-x"></i></button>
                            </div>
                        </div>

                        <h3 class="st-section">Documentos</h3>
                        <label class="form-label small fw-semibold" for="s-signatory">Firma por la empresa</label>
                        <select id="s-signatory" v-model="form.signatory_id" class="form-select form-select-sm mb-1">
                            <option value="">— Sin firma registrada —</option>
                            <option v-for="s in signatories" :key="s.id" :value="s.id">{{ s.name }}{{ s.position ? ` · ${s.position}` : '' }}</option>
                        </select>
                        <div class="form-text small mb-2">Apoderados de Compras y tesorería › Apoderados.</div>
                        <label class="form-label small fw-semibold" for="s-notes">Observaciones <span class="fw-normal text-body-secondary">(opcional)</span></label>
                        <textarea id="s-notes" v-model="form.notes" class="form-control form-control-sm" rows="2" maxlength="1000"></textarea>
                    </div>
                </form>
            </div>

            <div class="col-12 col-lg-8">
                <section v-if="result" class="surface">
                    <header class="surface-head">
                        <div>
                            <h2 class="surface-title">Liquidación calculada</h2>
                            <p class="surface-sub">Tiempo de servicio: {{ result.service }}</p>
                        </div>
                        <div class="text-end">
                            <div class="dash-kpi-label">Neto a pagar</div>
                            <div class="cell-amount fs-5">{{ money(result.total) }}</div>
                        </div>
                    </header>
                    <div class="surface-pad">
                        <div v-for="w in result.warnings" :key="w" class="dash-notice notice-warning"><i class="bi bi-exclamation-triangle"></i><span>{{ w }}</span></div>

                        <div class="row g-2 mb-3">
                            <div v-for="b in result.bases" :key="b.key" class="col-12 col-md-4">
                                <div class="concept-card h-100 small">
                                    <div class="concept-card-title mb-1">Base {{ b.label }}</div>
                                    <div v-for="c in b.components" :key="c.label" class="d-flex justify-content-between text-body-secondary">
                                        <span>{{ c.label }}</span><span>{{ money(c.amount) }}</span>
                                    </div>
                                    <div class="d-flex justify-content-between fw-bold border-top mt-1 pt-1"><span>Total</span><span>{{ money(b.total) }}</span></div>
                                </div>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table data-table align-middle mb-2 st-table">
                                <thead><tr><th>Concepto</th><th>Cálculo</th><th class="text-end">Monto</th></tr></thead>
                                <tbody>
                                    <tr v-for="l in result.lines" :key="l.key">
                                        <td class="fw-semibold">{{ l.label }}</td>
                                        <td :class="l.amount === null ? 'text-warning-emphasis' : 'text-body-secondary'">{{ l.basis }}</td>
                                        <td class="text-end tabular">{{ money(l.amount) }}</td>
                                    </tr>
                                    <tr class="st-subtotal"><td colspan="2">Total liquidación</td><td class="text-end tabular">{{ money(result.gross) }}</td></tr>
                                    <tr v-for="d in result.deductions" :key="d.key" class="text-danger-emphasis">
                                        <td>{{ d.label }}</td><td class="text-body-secondary">{{ d.basis }}</td><td class="text-end">− {{ money(d.amount) }}</td>
                                    </tr>
                                </tbody>
                                <tfoot><tr class="st-total"><td colspan="2">Neto a pagar</td><td class="text-end tabular">{{ money(result.total) }}</td></tr></tfoot>
                            </table>
                        </div>
                        <p class="small text-body-secondary">No descuenta renta de quinta categoría: si corresponde, agrégala en «Otros descuentos».</p>
                    </div>
                    <footer class="surface-foot justify-content-end">
                        <button type="button" class="btn btn-danger" :disabled="form.processing" @click="register">
                            <i class="bi bi-person-x me-1"></i>Registrar cese
                        </button>
                    </footer>
                </section>

                <section v-if="history.length" class="surface mt-3">
                    <header class="surface-head"><h2 class="surface-title">Liquidaciones registradas</h2></header>
                    <div>
                        <ul class="list-plain small mb-0">
                            <li v-for="h in history" :key="h.id" class="list-plain-item d-block">
                                <div class="d-flex flex-wrap justify-content-between gap-2">
                                    <span class="fw-semibold">N.° {{ h.id }} · {{ h.date }} · {{ h.reason }} · neto {{ money(h.total) }}</span>
                                    <button v-if="h.can_pay" type="button" class="btn btn-link btn-sm p-0" @click="router.post(`/settlements/${h.id}/treasury`, {}, { preserveScroll: true })">Enviar a Tesorería</button>
                                    <span v-else-if="h.payment" class="text-body-secondary">{{ h.payment }}</span>
                                </div>
                                <div class="d-flex flex-wrap gap-3 mt-1">
                                    <a v-for="(label, key) in h.documents" :key="key" :href="`/settlements/${h.id}/print?doc=${key}`" target="_blank" rel="noopener">
                                        <i class="bi bi-printer me-1"></i>{{ label }}
                                    </a>
                                    <a :href="`/settlements/${h.id}/print?doc=all`" target="_blank" rel="noopener" class="fw-semibold"><i class="bi bi-files me-1"></i>Todo junto</a>
                                    <a v-if="h.payslip" :href="h.payslip" target="_blank" rel="noopener"><i class="bi bi-file-earmark-text me-1"></i>Última boleta</a>
                                </div>
                            </li>
                        </ul>
                    </div>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
