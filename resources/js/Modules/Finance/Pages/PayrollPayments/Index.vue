<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import AppModal from '@/Modules/Core/Components/AppModal.vue';
import { useTenantPicker } from '@/Modules/Core/Composables/useTenantPicker';
import { money } from '@/Modules/Core/format';

const props = defineProps({
    period: { type: String, required: true },
    payments: { type: Array, default: () => [] },
    summary: { type: Object, default: () => ({ employees: 0, accumulated: 0, to_pay: 0 }) },
    can: { type: Object, default: () => ({}) },
    totals: { type: Object, default: () => ({}) },
    missing: { type: Array, default: () => [] },
    concepts: { type: Object, required: true },
    periods: { type: Array, default: () => [] },
    today: { type: String, required: true },
});

const { ensureTenant } = useTenantPicker();

// Estado de cada pago con los tonos del sistema
const tone = { 'Por programar': 'tone-neutral', Programado: 'tone-brand', Autorizado: 'tone-blue', Amortizado: 'tone-amber', Pagado: 'tone-emerald' };

const showImport = ref(false);
const fileInput = ref(null);
const form = useForm({ file: null, period: props.period, concept: 'HABERES', due_date: props.today });
const errors = computed(() => [form.errors.file, form.errors.period, form.errors.concept, form.errors.due_date].filter(Boolean).flatMap((e) => e.split('\n')));
function open() {
    if (!ensureTenant({ message: 'La planilla se importa en una empresa.' })) return;
    form.reset();
    form.period = props.period;
    form.clearErrors();
    if (fileInput.value) fileInput.value.value = '';
    showImport.value = true;
}
function submit() {
    form.post('/payroll-payments/import', { preserveScroll: true, forceFormData: true, onSuccess: () => (showImport.value = false) });
}
const go = (period) => router.get('/payroll-payments', { period }, { preserveState: true, replace: true });

// MES y AÑO como en sisgere FrmRegrrhh › Planilla
const MONTHS = ['ENERO', 'FEBRERO', 'MARZO', 'ABRIL', 'MAYO', 'JUNIO', 'JULIO', 'AGOSTO', 'SETIEMBRE', 'OCTUBRE', 'NOVIEMBRE', 'DICIEMBRE'];
const year = computed(() => Number(props.period.slice(0, 4)));
const month = computed(() => props.period.slice(5, 7));
const years = computed(() => {
    const now = Number(props.today.slice(0, 4));
    const list = new Set([...props.periods.map((p) => Number(p.slice(0, 4))), now - 1, now, now + 1, year.value]);
    return [...list].sort((a, b) => b - a);
});
const setPeriod = (y, m) => go(`${y}-${m}`);
</script>

<template>
    <AppLayout title="Pagos de RRHH">
        <header class="page-head">
            <div class="page-head-text">
                <h1 class="page-title">Pagos de RRHH</h1>
                <p class="page-subtitle">Importa la planilla del periodo: cada colaborador (por DNI) pasa a Tesorería como una orden de pago, a la cuenta verificada de su legajo.</p>
            </div>
            <div class="page-actions">
                <Link v-if="can.employees" href="/employees" class="btn btn-light border"><i class="bi bi-people me-1"></i>Colaboradores</Link>
                <a v-if="can.employees" :href="`/finance-reports/employees?from=${today}&to=${today}&regime=all`" class="btn btn-light border" title="Listado de colaboradores en Excel (Crcolaboradores)"><i class="bi bi-printer me-1"></i>Reportes</a>
                <Link v-if="can.requisitions" href="/requisitions?search=RRHH" class="btn btn-light border"><i class="bi bi-journal-text me-1"></i>Requerimientos</Link>
                <button type="button" class="btn btn-primary" @click="open"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Planilla</button>
            </div>
        </header>

        <!-- Periodo: MES y AÑO como en sisgere FrmRegrrhh › Planilla, más los periodos con datos -->
        <section class="surface mb-3">
            <div class="filter-row border-0">
                <div class="period-field">
                    <label class="form-label mb-1" for="pp-month">Mes</label>
                    <select id="pp-month" class="form-select" :value="month" @change="setPeriod(year, $event.target.value)">
                        <option v-for="(m, i) in MONTHS" :key="m" :value="String(i + 1).padStart(2, '0')">{{ m }}</option>
                    </select>
                </div>
                <div class="period-field">
                    <label class="form-label mb-1" for="pp-year">Año</label>
                    <select id="pp-year" class="form-select" :value="year" @change="setPeriod($event.target.value, month)">
                        <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
                    </select>
                </div>
                <div v-if="periods.length" class="seg-tabs align-self-end" role="tablist" aria-label="Periodos con planilla">
                    <button v-for="p in periods" :key="p" type="button" role="tab" class="seg-tab" :class="{ 'is-active': p === period }" :aria-selected="p === period" @click="go(p)">{{ p }}</button>
                </div>
                <Link v-if="can.treasury" href="/treasury" class="btn btn-sm btn-link ms-auto align-self-end">Ir a Tesorería <i class="bi bi-arrow-right"></i></Link>
            </div>
        </section>

        <section class="dash-kpis dash-kpis-3" aria-label="Resumen del periodo">
            <article class="dash-kpi-card">
                <div class="dash-kpi-top"><span class="dash-kpi-label">NRO COLABORADORES</span><span class="dash-kpi-icon tone-brand"><i class="bi bi-people"></i></span></div>
                <div class="dash-kpi-value">{{ summary.employees }}</div>
            </article>
            <article class="dash-kpi-card">
                <div class="dash-kpi-top"><span class="dash-kpi-label">SUELDO ACUMULADO</span><span class="dash-kpi-icon tone-emerald"><i class="bi bi-wallet2"></i></span></div>
                <div class="dash-kpi-value kpi-money">{{ money(summary.accumulated) }}</div>
            </article>
            <article class="dash-kpi-card">
                <div class="dash-kpi-top"><span class="dash-kpi-label">SUELDOS A PAGAR</span><span class="dash-kpi-icon tone-rose"><i class="bi bi-hourglass-split"></i></span></div>
                <div class="dash-kpi-value kpi-money">{{ money(summary.to_pay) }}</div>
            </article>
        </section>

        <!-- Totales por concepto -->
        <div v-if="Object.keys(totals).length" class="concept-grid mb-3">
            <div v-for="(t, c) in totals" :key="c" class="concept-card">
                <div class="concept-card-title">{{ concepts[c] }}</div>
                <div class="concept-card-value">{{ money(t.total) }}</div>
                <div class="cell-sub">{{ t.count }} colaborador(es) · pagado {{ money(t.paid) }}</div>
                <div class="dash-meter mt-2" role="presentation"><span class="dash-meter-fill tone-emerald" :style="{ width: `${t.total > 0 ? Math.min(100, (t.paid / t.total) * 100) : 0}%` }"></span></div>
            </div>
        </div>

                <section class="surface">
                    <div class="table-responsive">
                        <table class="table data-table table-cards align-middle mb-0">
                            <thead><tr><th># Operación</th><th>Colaborador</th><th>Orden Pago</th><th>F. Prog</th><th>F. Pago</th><th>Ciudad</th><th>Cargo</th><th class="text-end">Monto a depositar</th><th>Banco</th><th>CCI</th><th>Estado</th></tr></thead>
                            <tbody>
                                <tr v-for="p in payments" :key="p.id">
                                    <td data-label="# Operación" class="text-nowrap"><span class="cell-code">{{ p.code }}</span></td>
                                    <td data-label="Colaborador" class="cell-main">
                                        <div class="min-w-0">
                                            <div class="cell-title">{{ p.employee }}</div>
                                            <div class="cell-sub">DNI {{ p.dni }} · {{ concepts[p.concept] }}</div>
                                        </div>
                                    </td>
                                    <td data-label="Orden Pago" class="text-nowrap">
                                        <a v-if="p.order && can.treasury" :href="`/treasury/installments/${p.installment_id}/op.pdf`" target="_blank" rel="noopener" class="cell-code">{{ p.order }}</a>
                                        <span v-else class="cell-sub font-monospace">{{ p.order || '—' }}</span>
                                    </td>
                                    <td data-label="F. Prog" class="text-nowrap tabular">{{ p.scheduled_for || '—' }}</td>
                                    <td data-label="F. Pago" class="text-nowrap tabular">{{ p.paid_at || '—' }}</td>
                                    <td data-label="Ciudad">{{ p.city || '—' }}</td>
                                    <td data-label="Cargo">{{ p.position || '—' }}</td>
                                    <td data-label="Monto" class="text-end text-nowrap">
                                        <div class="cell-amount">{{ money(p.amount) }}</div>
                                        <div v-if="p.paid && p.status !== 'Pagado'" class="cell-sub">pagado {{ money(p.paid) }}</div>
                                    </td>
                                    <td data-label="Banco">{{ p.bank || '—' }}</td>
                                    <td data-label="CCI" class="text-nowrap">
                                        <span class="font-monospace small">{{ p.cci || '—' }}</span>
                                        <span v-if="!p.verified" class="status-pill tone-amber ms-1">Sin verificar</span>
                                    </td>
                                    <td data-label="Estado"><span class="status-pill" :class="tone[p.status] || 'tone-neutral'">{{ p.status }}</span></td>
                                </tr>
                                <tr v-if="!payments.length" class="row-empty">
                                    <td colspan="11">
                                        <div class="dash-empty">
                                            <span class="dash-empty-icon"><i class="bi bi-people"></i></span>
                                            <p class="dash-empty-title">No hay pagos de RRHH en {{ period }}</p>
                                            <p class="dash-empty-text">Importa la planilla del periodo con el botón «Planilla».</p>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section v-if="missing.length" class="surface mt-3">
                    <header class="surface-head">
                        <div>
                            <h2 class="surface-title"><i class="bi bi-person-dash me-1 text-warning"></i>Activos que no están en la planilla de haberes ({{ missing.length }})</h2>
                            <p class="surface-sub">Revisa si falta incluirlos en el Excel del periodo.</p>
                        </div>
                    </header>
                    <div class="surface-pad d-flex flex-wrap gap-2">
                        <span v-for="m in missing" :key="m.dni" class="status-pill tone-neutral">{{ m.name }} · {{ m.dni }}</span>
                    </div>
                </section>

            <AppModal :show="showImport" title="Importar planilla" :busy="form.processing" @close="showImport = false">
                <template #subtitle>Columnas: DNI, Colaborador y Monto neto a pagar. Todo o nada: si una fila falla no se importa ninguna.
                    <a href="/payroll-payments/template" class="text-nowrap"><i class="bi bi-download me-1"></i>Descargar plantilla</a></template>
                    <form id="pp-import-form" class="row g-3" novalidate @submit.prevent="submit">
                            <div class="col-sm-6">
                                <label class="form-label" for="pp-period">Periodo *</label>
                                <input id="pp-period" v-model="form.period" type="month" class="form-control" />
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label" for="pp-concept">Concepto *</label>
                                <select id="pp-concept" v-model="form.concept" class="form-select"><option v-for="(label, key) in concepts" :key="key" :value="key">{{ label }}</option></select>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label" for="pp-due">Fecha de pago *</label>
                                <input id="pp-due" v-model="form.due_date" type="date" class="form-control" />
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="pp-file">Archivo (.xlsx, .xls o .csv) *</label>
                                <input id="pp-file" ref="fileInput" type="file" accept=".xlsx,.xls,.csv" class="form-control" @input="form.file = $event.target.files[0]" />
                            </div>
                            <div v-if="errors.length" class="col-12">
                                <div class="alert alert-danger small mb-0 py-2">
                                    <div class="fw-semibold mb-1">No se importó nada. Corrige y vuelve a subir:</div>
                                    <ul class="mb-0 ps-3"><li v-for="(e, i) in errors" :key="i">{{ e }}</li></ul>
                                </div>
                            </div>
                    </form>
                <template #footer>
                    <button type="button" class="btn btn-light border" @click="showImport = false">Cancelar</button>
                    <button type="submit" form="pp-import-form" class="btn btn-primary" :disabled="form.processing || !form.file">
                        <span v-if="form.processing" class="spinner-border spinner-border-sm me-1"></span>Enviar a Tesorería
                    </button>
                </template>
            </AppModal>
    </AppLayout>
</template>
