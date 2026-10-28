<script setup>
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import { money as formatMoney } from '@/Modules/Core/format';

const props = defineProps({
    months: { type: Array, required: true },
    actual: { type: Array, default: () => [] },
    projected: { type: Array, default: () => [] },
    overdue: { type: Number, default: 0 },
    filters: { type: Object, default: () => ({}) },
    currencies: { type: Object, default: () => ({}) },
});

const c = computed(() => props.filters.currency);
const money = (v) => (v ? formatMoney(v, c.value) : '—'); // en el flujo, 0 también se ve como —

function go(params) {
    router.get('/cash-flow', { ...props.filters, ...params }, { preserveState: true, replace: true });
}
function shift(n) {
    const [y, m] = props.filters.from.split('-').map(Number);
    const d = new Date(y, m - 1 + n, 1);
    go({ from: `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}` });
}

const sumBy = (rows, key) => rows.reduce((a, r) => a + Number(r.months[key] || 0), 0);
const sections = computed(() => [
    { key: 'actual', title: 'Egresos reales (pagado)', icon: 'check2-circle', color: 'success', rows: props.actual },
    { key: 'projected', title: 'Egresos proyectados (por pagar)', icon: 'hourglass-split', color: 'warning', rows: props.projected },
]);
const grand = (key) => sumBy(props.actual, key) + sumBy(props.projected, key);
</script>

<template>
    <AppLayout title="Flujo de caja">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
            <div>
                <h1 class="h4 fw-bold mb-1">Flujo de caja</h1>
                <p class="text-body-secondary small mb-0">Egresos por mes: pagos registrados y lo que vence (proveedores, costos fijos y caja chica).</p>
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <div class="btn-group" role="group" aria-label="Agrupar por">
                    <button type="button" class="btn btn-sm" :class="filters.by !== 'flow' ? 'btn-primary' : 'btn-outline-secondary'" @click="go({ by: 'classification' })">Por clasificación</button>
                    <button type="button" class="btn btn-sm" :class="filters.by === 'flow' ? 'btn-primary' : 'btn-outline-secondary'" @click="go({ by: 'flow' })">Por flujo de efectivo</button>
                </div>
                <div class="btn-group" role="group" aria-label="Moneda">
                    <button v-for="(label, code) in currencies" :key="code" type="button" class="btn btn-sm" :class="c === code ? 'btn-primary' : 'btn-outline-secondary'" @click="go({ currency: code })">{{ label }}</button>
                </div>
                <div class="btn-group" role="group" aria-label="Periodo">
                    <button type="button" class="btn btn-sm btn-outline-secondary" title="Meses anteriores" @click="shift(-3)"><i class="bi bi-chevron-left"></i></button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" title="Meses siguientes" @click="shift(3)"><i class="bi bi-chevron-right"></i></button>
                </div>
            </div>
        </div>

        <div v-if="overdue > 0" class="alert alert-danger d-flex align-items-center gap-2">
            <i class="bi bi-exclamation-octagon-fill"></i>Hay {{ money(overdue) }} vencido sin pagar (cuotas de proveedores y costos fijos).
        </div>

        <div v-for="s in sections" :key="s.key" class="card shadow-sm mb-3">
            <div class="card-header bg-transparent fw-semibold"><i class="bi me-1" :class="`bi-${s.icon} text-${s.color}`"></i>{{ s.title }}</div>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0 small">
                    <thead class="table-light">
                        <tr>
                            <th style="min-width: 220px">Concepto</th>
                            <th v-for="m in months" :key="m.key" class="text-end text-nowrap" :class="{ 'table-primary': m.is_current }">{{ m.label }}</th>
                            <th class="text-end">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in s.rows" :key="r.concept">
                            <td>{{ r.concept }}</td>
                            <td v-for="m in months" :key="m.key" class="text-end text-nowrap" :class="{ 'table-primary': m.is_current }">{{ money(r.months[m.key]) }}</td>
                            <td class="text-end fw-semibold text-nowrap">{{ money(r.total) }}</td>
                        </tr>
                        <tr v-if="!s.rows.length"><td :colspan="months.length + 2" class="text-center text-body-secondary py-3">Sin movimientos en el periodo.</td></tr>
                    </tbody>
                    <tfoot v-if="s.rows.length" class="table-light fw-semibold">
                        <tr>
                            <td>Subtotal</td>
                            <td v-for="m in months" :key="m.key" class="text-end text-nowrap">{{ money(sumBy(s.rows, m.key)) }}</td>
                            <td class="text-end text-nowrap">{{ money(s.rows.reduce((a, r) => a + r.total, 0)) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <div class="card shadow-sm border-primary">
            <div class="table-responsive">
                <table class="table table-sm mb-0 small fw-bold">
                    <tbody>
                        <tr>
                            <td style="min-width: 220px">Total egresos (real + proyectado)</td>
                            <td v-for="m in months" :key="m.key" class="text-end text-nowrap" :class="{ 'table-primary': m.is_current }">{{ money(grand(m.key)) }}</td>
                            <td class="text-end text-nowrap">{{ money(months.reduce((a, m) => a + grand(m.key), 0)) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
