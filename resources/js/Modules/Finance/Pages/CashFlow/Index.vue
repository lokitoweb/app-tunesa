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
        <header class="page-head">
            <div class="page-head-text">
                <h1 class="page-title">Flujo de caja</h1>
                <p class="page-subtitle">Egresos por mes: pagos registrados y lo que vence (proveedores, costos fijos y caja chica).</p>
            </div>
        </header>

        <section class="surface mb-3">
            <div class="filter-row border-0">
                <div class="seg-tabs" role="tablist" aria-label="Agrupar por">
                    <button type="button" role="tab" class="seg-tab" :class="{ 'is-active': filters.by !== 'flow' }" :aria-selected="filters.by !== 'flow'" @click="go({ by: 'classification' })">Por clasificación</button>
                    <button type="button" role="tab" class="seg-tab" :class="{ 'is-active': filters.by === 'flow' }" :aria-selected="filters.by === 'flow'" @click="go({ by: 'flow' })">Por flujo de efectivo</button>
                </div>
                <div class="seg-tabs" role="tablist" aria-label="Moneda">
                    <button v-for="(label, code) in currencies" :key="code" type="button" role="tab" class="seg-tab" :class="{ 'is-active': c === code }" :aria-selected="c === code" @click="go({ currency: code })">{{ label }}</button>
                </div>
                <div class="d-flex align-items-center gap-1 ms-auto" role="group" aria-label="Periodo">
                    <button type="button" class="icon-btn border" title="Meses anteriores" aria-label="Meses anteriores" @click="shift(-3)"><i class="bi bi-chevron-left"></i></button>
                    <span class="small fw-semibold px-1">{{ months[0]?.label }} – {{ months[months.length - 1]?.label }}</span>
                    <button type="button" class="icon-btn border" title="Meses siguientes" aria-label="Meses siguientes" @click="shift(3)"><i class="bi bi-chevron-right"></i></button>
                </div>
            </div>
        </section>

        <div v-if="overdue > 0" class="dash-notice notice-danger">
            <i class="bi bi-exclamation-octagon"></i>
            <span>Hay <strong>{{ money(overdue) }}</strong> vencido sin pagar (cuotas de proveedores y costos fijos).</span>
        </div>

        <section v-for="s in sections" :key="s.key" class="surface mb-3">
            <header class="surface-head">
                <h2 class="surface-title"><i class="bi me-1" :class="`bi-${s.icon} text-${s.color}`"></i>{{ s.title }}</h2>
            </header>
            <div class="table-responsive">
                <table class="table data-table flow-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="flow-concept">Concepto</th>
                            <th v-for="m in months" :key="m.key" class="text-end text-nowrap" :class="{ 'is-current': m.is_current }">{{ m.label }}</th>
                            <th class="text-end">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in s.rows" :key="r.concept">
                            <td class="flow-concept">{{ r.concept }}</td>
                            <td v-for="m in months" :key="m.key" class="text-end text-nowrap tabular" :class="{ 'is-current': m.is_current, 'text-body-tertiary': !r.months[m.key] }">{{ money(r.months[m.key]) }}</td>
                            <td class="text-end text-nowrap"><span class="cell-amount">{{ money(r.total) }}</span></td>
                        </tr>
                        <tr v-if="!s.rows.length" class="row-empty"><td :colspan="months.length + 2" class="text-center cell-sub py-4">Sin movimientos en el periodo.</td></tr>
                    </tbody>
                    <tfoot v-if="s.rows.length">
                        <tr>
                            <td class="flow-concept">Subtotal</td>
                            <td v-for="m in months" :key="m.key" class="text-end text-nowrap tabular" :class="{ 'is-current': m.is_current }">{{ money(sumBy(s.rows, m.key)) }}</td>
                            <td class="text-end text-nowrap tabular">{{ money(s.rows.reduce((a, r) => a + r.total, 0)) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>

        <section class="surface flow-grand">
            <div class="table-responsive">
                <table class="table data-table flow-table mb-0">
                    <tbody>
                        <tr>
                            <td class="flow-concept">Total egresos (real + proyectado)</td>
                            <td v-for="m in months" :key="m.key" class="text-end text-nowrap tabular" :class="{ 'is-current': m.is_current }">{{ money(grand(m.key)) }}</td>
                            <td class="text-end text-nowrap tabular">{{ money(months.reduce((a, m) => a + grand(m.key), 0)) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </AppLayout>
</template>
