<script setup>
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import { useConfirm } from '@/Modules/Core/Composables/useConfirm';

const props = defineProps({
    period: { type: Object, required: true },
    rows: { type: Array, default: () => [] },
    totals: { type: Object, required: true },
    treasury: { type: Boolean, default: false },
    signatories: { type: Array, default: () => [] },
});

const { ask } = useConfirm();
const money = (v) => (v === null || v === undefined ? '—' : Number(v).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
const fmtDate = (iso) => iso.split('-').reverse().join('/');
const signatory = ref(props.signatories[0]?.id ?? '');
const pending = computed(() => props.rows.filter((r) => r.amount !== null && !r.deposit_id));
const unsent = computed(() => props.rows.filter((r) => r.deposit_id && !r.payment));
const years = computed(() => { const y = new Date().getFullYear(); return [y + 1, y, y - 1, y - 2]; });

function go(params) {
    router.get('/cts-deposits', { year: props.period.year, month: props.period.month, overtime: props.period.overtime ? 1 : 0, ...params }, { preserveScroll: true, replace: true });
}
const reg = useForm({});
async function register() {
    const ok = await ask({
        title: '¿Registrar los depósitos?',
        message: `Se registran ${pending.value.length} depósito(s) por ${money(pending.value.reduce((s, r) => s + r.amount, 0))} con el cálculo de hoy. Quedan congelados para la constancia y el pago.`,
        confirmText: 'Sí, registrar', icon: 'bi bi-piggy-bank',
    });
    if (ok) reg.transform(() => ({ year: props.period.year, month: props.period.month, overtime: props.period.overtime, signatory_id: signatory.value || null })).post('/cts-deposits', { preserveScroll: true });
}
async function remove(r) {
    const ok = await ask({ title: '¿Quitar el depósito?', message: `El de ${r.name} se vuelve a calcular la próxima vez que registres.`, confirmText: 'Sí, quitar', variant: 'danger', icon: 'bi bi-arrow-counterclockwise' });
    if (ok) router.delete(`/cts-deposits/${r.deposit_id}`, { preserveScroll: true });
}
async function sendToTreasury() {
    const ok = await ask({
        title: '¿Enviar a Tesorería?',
        message: `${unsent.value.length} depósito(s) a la cuenta CTS de cada legajo, con vencimiento el ${fmtDate(props.period.due)}. Los que no tienen cuenta CTS verificada se quedan sin enviar.`,
        confirmText: 'Sí, enviar', icon: 'bi bi-cash-coin',
    });
    if (ok) router.post('/cts-deposits/treasury', { year: props.period.year, month: props.period.month }, { preserveScroll: true });
}
</script>

<template>
    <AppLayout title="Depósitos de CTS">
        <header class="page-head">
            <div class="page-head-text">
                <h1 class="page-title">Depósitos de CTS</h1>
                <p class="page-subtitle">
                    Semestre del {{ fmtDate(period.start) }} al {{ fmtDate(period.end) }} · se deposita hasta el {{ fmtDate(period.due) }}.
                    Personal en planilla que sigue trabajando al cierre del semestre, con la base y los parámetros de su régimen (quien cesó antes cobra su CTS en la liquidación).
                </p>
            </div>
            <div class="page-actions">
                <a v-if="totals.registered" :href="`/cts-deposits/print?year=${period.year}&month=${period.month}`" target="_blank" rel="noopener" class="btn btn-light border"><i class="bi bi-printer me-1"></i>Constancias</a>
                <button v-if="treasury" type="button" class="btn btn-light border" :disabled="!unsent.length" @click="sendToTreasury"><i class="bi bi-cash-coin me-1"></i>Enviar {{ unsent.length }} a Tesorería</button>
                <button type="button" class="btn btn-primary" :disabled="!pending.length || reg.processing" @click="register"><i class="bi bi-check2-all me-1"></i>Registrar {{ pending.length }} depósito(s)</button>
            </div>
        </header>

        <section class="dash-kpis" aria-label="Resumen del semestre">
            <article class="dash-kpi-card">
                <div class="dash-kpi-top"><span class="dash-kpi-label">Personas</span><span class="dash-kpi-icon tone-brand"><i class="bi bi-people"></i></span></div>
                <div class="dash-kpi-value">{{ totals.people }}</div>
            </article>
            <article class="dash-kpi-card">
                <div class="dash-kpi-top"><span class="dash-kpi-label">Monto del semestre</span><span class="dash-kpi-icon tone-emerald"><i class="bi bi-piggy-bank"></i></span></div>
                <div class="dash-kpi-value kpi-money">S/ {{ money(totals.amount) }}</div>
            </article>
            <article class="dash-kpi-card">
                <div class="dash-kpi-top"><span class="dash-kpi-label">Registrados</span><span class="dash-kpi-icon tone-blue"><i class="bi bi-check2-all"></i></span></div>
                <div class="dash-kpi-value">{{ totals.registered }}</div>
            </article>
            <article v-if="treasury" class="dash-kpi-card">
                <div class="dash-kpi-top"><span class="dash-kpi-label">En Tesorería</span><span class="dash-kpi-icon tone-amber"><i class="bi bi-bank"></i></span></div>
                <div class="dash-kpi-value">{{ totals.sent }}</div>
            </article>
        </section>

        <section class="surface">
            <div class="filter-row">
                <div class="seg-tabs" role="tablist" aria-label="Depósito">
                    <button type="button" role="tab" class="seg-tab" :class="{ 'is-active': period.month === 5 }" :aria-selected="period.month === 5" @click="go({ month: 5 })">Mayo (nov – abr)</button>
                    <button type="button" role="tab" class="seg-tab" :class="{ 'is-active': period.month === 11 }" :aria-selected="period.month === 11" @click="go({ month: 11 })">Noviembre (may – oct)</button>
                </div>
                <select class="form-select w-auto" :value="period.year" aria-label="Año" @change="go({ year: Number($event.target.value) })">
                    <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
                </select>
                <div class="form-check form-switch mb-0">
                    <input id="cts-overtime" class="form-check-input" type="checkbox" role="switch" :checked="period.overtime" @change="go({ overtime: $event.target.checked ? 1 : 0 })" />
                    <label class="form-check-label small" for="cts-overtime">Incluir promedio de horas extras (aprobadas, si son regulares)</label>
                </div>
                <select v-model="signatory" class="form-select w-auto ms-auto" aria-label="Firma por la empresa">
                    <option value="">Sin firma registrada</option>
                    <option v-for="s in signatories" :key="s.id" :value="s.id">{{ s.name }}{{ s.position ? ` · ${s.position}` : '' }}</option>
                </select>
            </div>
            <div class="table-responsive">
                <table class="table data-table table-cards align-middle mb-0">
                    <thead>
                        <tr><th>Colaborador</th><th>Régimen</th><th>Tiempo</th><th class="text-end">Base</th><th class="text-end">Monto</th><th>Cuenta CTS</th><th>Estado</th><th><span class="visually-hidden">Acciones</span></th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in rows" :key="r.user_id" :class="{ 'is-muted': r.amount === null }">
                            <td data-label="Colaborador" class="cell-main"><div class="min-w-0"><div class="cell-title text-truncate">{{ r.name }}</div><div class="cell-sub">DNI {{ r.dni }}</div></div></td>
                            <td data-label="Régimen">{{ r.regime || '—' }}</td>
                            <td data-label="Tiempo" class="text-nowrap">{{ r.calc ? `${r.calc.months} m ${r.calc.days} d` : '—' }}</td>
                            <td data-label="Base" class="text-end tabular">{{ r.calc ? money(r.calc.base) : '—' }}</td>
                            <td data-label="Monto" class="text-end"><span class="cell-amount">{{ money(r.amount) }}</span></td>
                            <td data-label="Cuenta CTS">
                                <span v-if="r.cts_account" class="status-pill tone-emerald"><i class="bi bi-check-circle"></i>Sí</span>
                                <span v-else class="status-pill tone-amber"><i class="bi bi-exclamation-circle"></i>Falta</span>
                            </td>
                            <td data-label="Estado">
                                <span v-if="r.note" class="cell-sub">{{ r.note }}</span>
                                <span v-else-if="r.payment" class="status-pill tone-emerald">Tesorería {{ r.payment }}</span>
                                <span v-else-if="r.deposit_id" class="status-pill tone-brand">Registrado</span>
                                <span v-else class="status-pill tone-neutral">Por registrar</span>
                            </td>
                            <td class="cell-actions">
                                <div v-if="r.deposit_id" class="row-actions">
                                    <a :href="`/cts-deposits/print?id=${r.deposit_id}`" target="_blank" rel="noopener" class="icon-btn" :aria-label="`Constancia de ${r.name}`" title="Constancia"><i class="bi bi-printer"></i></a>
                                    <button v-if="!r.payment" type="button" class="icon-btn icon-btn-danger" :aria-label="`Quitar depósito de ${r.name}`" title="Quitar depósito" @click="remove(r)"><i class="bi bi-arrow-counterclockwise"></i></button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!rows.length" class="row-empty">
                            <td colspan="8">
                                <div class="dash-empty">
                                    <span class="dash-empty-icon"><i class="bi bi-piggy-bank"></i></span>
                                    <p class="dash-empty-title">No hay personal en planilla en este semestre</p>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </AppLayout>
</template>
