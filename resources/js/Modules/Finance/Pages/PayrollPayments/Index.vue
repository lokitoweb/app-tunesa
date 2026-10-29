<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
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

const badge = { 'Por programar': 'text-bg-secondary', Programado: 'text-bg-primary', Autorizado: 'text-bg-info', Amortizado: 'text-bg-warning', Pagado: 'text-bg-success' };

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
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
            <div>
                <h1 class="h4 fw-bold mb-1">Pagos de RRHH</h1>
                <p class="text-body-secondary small mb-0">Importa la planilla del periodo: cada colaborador (por DNI) pasa a Tesorería como una orden de pago, a la cuenta verificada de su legajo.</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <Link v-if="can.employees" href="/employees" class="btn btn-light border"><i class="bi bi-people me-1"></i>Colaboradores</Link>
                <button type="button" class="btn btn-primary" @click="open"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Planilla</button>
                <a v-if="can.employees" :href="`/finance-reports/employees?from=${today}&to=${today}&regime=all`" class="btn btn-light border" title="Listado de colaboradores en Excel (Crcolaboradores)"><i class="bi bi-printer me-1"></i>Reportes</a>
                <Link v-if="can.requisitions" href="/requisitions?search=RRHH" class="btn btn-light border"><i class="bi bi-journal-text me-1"></i>Requerimientos</Link>
            </div>
        </div>


        <div class="card shadow-sm mb-3">
            <div class="card-body d-flex flex-wrap align-items-end gap-3">
                <div>
                    <label class="form-label small fw-semibold mb-1" for="pp-month">Mes</label>
                    <select id="pp-month" class="form-select" :value="month" @change="setPeriod(year, $event.target.value)">
                        <option v-for="(m, i) in MONTHS" :key="m" :value="String(i + 1).padStart(2, '0')">{{ m }}</option>
                    </select>
                </div>
                <div>
                    <label class="form-label small fw-semibold mb-1" for="pp-year">Año</label>
                    <select id="pp-year" class="form-select" :value="year" @change="setPeriod($event.target.value, month)">
                        <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
                    </select>
                </div>
                <div class="d-flex flex-wrap gap-1">
                    <button v-for="p in periods" :key="p" type="button" class="btn btn-sm" :class="p === period ? 'btn-primary' : 'btn-light'" @click="go(p)">{{ p }}</button>
                </div>
                <Link v-if="can.treasury" href="/treasury" class="ms-auto small">Ir a Tesorería <i class="bi bi-arrow-right"></i></Link>
            </div>
            <div class="card-body border-top row g-2 text-center">
                <div class="col-sm-4"><div class="small text-body-secondary fw-semibold">NRO COLABORADORES</div><div class="fs-5 fw-bold">{{ summary.employees }}</div></div>
                <div class="col-sm-4"><div class="small text-body-secondary fw-semibold">SUELDO ACUMULADO</div><div class="fs-5 fw-bold text-success">{{ money(summary.accumulated) }}</div></div>
                <div class="col-sm-4"><div class="small text-body-secondary fw-semibold">SUELDOS A PAGAR</div><div class="fs-5 fw-bold text-danger">{{ money(summary.to_pay) }}</div></div>
            </div>
            <div v-if="Object.keys(totals).length" class="card-footer bg-transparent">
                <div class="row g-2 small">
                    <div v-for="(t, c) in totals" :key="c" class="col-sm-6 col-lg-3">
                        <div class="border rounded p-2">
                            <div class="fw-semibold">{{ concepts[c] }}</div>
                            <div>{{ t.count }} colaborador(es) · {{ money(t.total) }}</div>
                            <div class="text-body-secondary">Pagado: {{ money(t.paid) }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3">
            <div :class="showImport ? 'col-xl-7' : 'col-12'">
                <div class="card shadow-sm">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light"><tr><th># Operación</th><th>Colaborador</th><th>Orden Pago</th><th>F. Prog</th><th>F. Pago</th><th>Ciudad</th><th>Cargo</th><th class="text-end">Monto a depositar</th><th>Banco</th><th>CCI</th><th>Estado</th></tr></thead>
                            <tbody class="small">
                                <tr v-for="p in payments" :key="p.id">
                                    <td class="font-monospace text-nowrap">{{ p.code }}</td>
                                    <td><div class="fw-semibold">{{ p.employee }}</div><div class="text-body-secondary">DNI {{ p.dni }} · {{ concepts[p.concept] }}</div></td>
                                    <td class="font-monospace text-nowrap">
                                        <a v-if="p.order && can.treasury" :href="`/treasury/installments/${p.installment_id}/op.pdf`" target="_blank" rel="noopener">{{ p.order }}</a>
                                        <span v-else>{{ p.order || '—' }}</span>
                                    </td>
                                    <td class="text-nowrap">{{ p.scheduled_for || '—' }}</td>
                                    <td class="text-nowrap">{{ p.paid_at || '—' }}</td>
                                    <td>{{ p.city || '—' }}</td>
                                    <td>{{ p.position || '—' }}</td>
                                    <td class="text-end fw-semibold text-nowrap">{{ money(p.amount) }}<div v-if="p.paid && p.status !== 'Pagado'" class="fw-normal text-body-secondary">pagado {{ money(p.paid) }}</div></td>
                                    <td>{{ p.bank || '—' }}</td>
                                    <td class="font-monospace text-nowrap">{{ p.cci || '—' }} <span v-if="!p.verified" class="badge text-bg-warning">sin verificar</span></td>
                                    <td><span class="badge" :class="badge[p.status]">{{ p.status }}</span></td>
                                </tr>
                                <tr v-if="!payments.length"><td colspan="11" class="text-center text-body-secondary py-5"><i class="bi bi-people fs-2 d-block mb-2"></i>No hay pagos de RRHH en {{ period }}.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div v-if="missing.length" class="card border-warning shadow-sm mt-3">
                    <div class="card-header bg-transparent fw-semibold small"><i class="bi bi-person-dash me-1 text-warning"></i>Activos que no están en la planilla de haberes del periodo ({{ missing.length }})</div>
                    <div class="card-body small d-flex flex-wrap gap-2">
                        <span v-for="m in missing" :key="m.dni" class="badge text-bg-light border">{{ m.name }} · {{ m.dni }}</span>
                    </div>
                </div>
            </div>

            <div v-if="showImport" class="col-xl-5">
                <form class="card shadow-sm" novalidate @submit.prevent="submit">
                    <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                        <h2 class="h6 fw-bold mb-0">Importar planilla</h2>
                        <button type="button" class="btn-close" aria-label="Cerrar" @click="showImport = false"></button>
                    </div>
                    <div class="card-body row g-3">
                        <p class="small text-body-secondary mb-0">
                            Columnas: DNI, Colaborador y Monto neto a pagar. Todo o nada: si una fila falla no se importa ninguna.
                            <a href="/payroll-payments/template" class="text-nowrap"><i class="bi bi-download me-1"></i>Descargar plantilla</a>
                        </p>
                        <div class="col-sm-6">
                            <label class="form-label small fw-semibold" for="pp-period">Periodo *</label>
                            <input id="pp-period" v-model="form.period" type="month" class="form-control" />
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small fw-semibold" for="pp-concept">Concepto *</label>
                            <select id="pp-concept" v-model="form.concept" class="form-select"><option v-for="(label, key) in concepts" :key="key" :value="key">{{ label }}</option></select>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small fw-semibold" for="pp-due">Fecha de pago *</label>
                            <input id="pp-due" v-model="form.due_date" type="date" class="form-control" />
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold" for="pp-file">Archivo (.xlsx, .xls o .csv) *</label>
                            <input id="pp-file" ref="fileInput" type="file" accept=".xlsx,.xls,.csv" class="form-control" @input="form.file = $event.target.files[0]" />
                        </div>
                        <div v-if="errors.length" class="col-12">
                            <div class="alert alert-danger small mb-0 py-2">
                                <div class="fw-semibold mb-1">No se importó nada. Corrige y vuelve a subir:</div>
                                <ul class="mb-0 ps-3"><li v-for="(e, i) in errors" :key="i">{{ e }}</li></ul>
                            </div>
                        </div>
                    </div>
                    <div class="card-footer bg-transparent d-flex justify-content-end gap-2">
                        <button type="button" class="btn btn-light" @click="showImport = false">Cancelar</button>
                        <button type="submit" class="btn btn-primary" :disabled="form.processing || !form.file">
                            <span v-if="form.processing" class="spinner-border spinner-border-sm me-1"></span>Enviar a Tesorería
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
