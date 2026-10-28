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
        <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-3">
            <div>
                <h1 class="h4 fw-bold mb-1">Depósitos de CTS</h1>
                <p class="text-body-secondary small mb-0">
                    Semestre del {{ fmtDate(period.start) }} al {{ fmtDate(period.end) }} · se deposita hasta el {{ fmtDate(period.due) }}.
                    Personal en planilla que sigue trabajando al cierre del semestre, con la base y los parámetros de su régimen (quien cesó antes cobra su CTS en la liquidación).
                </p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <select class="form-select form-select-sm w-auto" :value="period.month" aria-label="Depósito" @change="go({ month: Number($event.target.value) })">
                    <option :value="5">Mayo (nov – abr)</option>
                    <option :value="11">Noviembre (may – oct)</option>
                </select>
                <select class="form-select form-select-sm w-auto" :value="period.year" aria-label="Año" @change="go({ year: Number($event.target.value) })">
                    <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
                </select>
            </div>
        </div>

        <div class="card shadow-sm mb-3">
            <div class="card-body d-flex flex-wrap align-items-center gap-3">
                <div class="small"><strong>{{ totals.people }}</strong> persona(s) · <strong>S/ {{ money(totals.amount) }}</strong> · {{ totals.registered }} registrado(s)<span v-if="treasury"> · {{ totals.sent }} en Tesorería</span></div>
                <div class="form-check mb-0">
                    <input id="cts-overtime" class="form-check-input" type="checkbox" :checked="period.overtime" @change="go({ overtime: $event.target.checked ? 1 : 0 })" />
                    <label class="form-check-label small" for="cts-overtime">Incluir promedio de horas extras (aprobadas, si son regulares)</label>
                </div>
                <select v-model="signatory" class="form-select form-select-sm w-auto" aria-label="Firma por la empresa">
                    <option value="">Sin firma registrada</option>
                    <option v-for="s in signatories" :key="s.id" :value="s.id">{{ s.name }}{{ s.position ? ` · ${s.position}` : '' }}</option>
                </select>
                <div class="ms-auto d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-sm btn-primary" :disabled="!pending.length || reg.processing" @click="register">
                        <i class="bi bi-check2-all me-1"></i>Registrar {{ pending.length }} depósito(s)
                    </button>
                    <a v-if="totals.registered" :href="`/cts-deposits/print?year=${period.year}&month=${period.month}`" target="_blank" rel="noopener" class="btn btn-sm btn-light">
                        <i class="bi bi-printer me-1"></i>Constancias
                    </a>
                    <button v-if="treasury" type="button" class="btn btn-sm btn-outline-primary" :disabled="!unsent.length" @click="sendToTreasury">
                        <i class="bi bi-cash-coin me-1"></i>Enviar {{ unsent.length }} a Tesorería
                    </button>
                </div>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover align-middle small mb-0">
                    <thead class="table-light">
                        <tr><th>Colaborador</th><th>Régimen</th><th>Tiempo</th><th class="text-end">Base</th><th class="text-end">Monto</th><th>Cuenta CTS</th><th>Estado</th><th><span class="visually-hidden">Acciones</span></th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in rows" :key="r.user_id" :class="{ 'text-body-secondary': r.amount === null }">
                            <td><div class="fw-semibold">{{ r.name }}</div><div class="text-body-secondary">DNI {{ r.dni }}</div></td>
                            <td>{{ r.regime || '—' }}</td>
                            <td>{{ r.calc ? `${r.calc.months} m ${r.calc.days} d` : '—' }}</td>
                            <td class="text-end">{{ r.calc ? money(r.calc.base) : '—' }}</td>
                            <td class="text-end fw-semibold">{{ money(r.amount) }}</td>
                            <td>
                                <span v-if="r.cts_account" class="text-success"><i class="bi bi-check-circle"></i> Sí</span>
                                <span v-else class="text-warning-emphasis"><i class="bi bi-exclamation-circle"></i> Falta</span>
                            </td>
                            <td>
                                <span v-if="r.note">{{ r.note }}</span>
                                <span v-else-if="r.payment" class="badge bg-success-subtle text-success">Tesorería {{ r.payment }}</span>
                                <span v-else-if="r.deposit_id" class="badge bg-primary-subtle text-primary">Registrado</span>
                                <span v-else class="badge bg-secondary-subtle text-secondary">Por registrar</span>
                            </td>
                            <td class="text-end text-nowrap">
                                <template v-if="r.deposit_id">
                                    <a :href="`/cts-deposits/print?id=${r.deposit_id}`" target="_blank" rel="noopener" class="btn btn-sm btn-light" :aria-label="`Constancia de ${r.name}`"><i class="bi bi-printer"></i></a>
                                    <button v-if="!r.payment" type="button" class="btn btn-sm btn-light" :aria-label="`Quitar depósito de ${r.name}`" @click="remove(r)"><i class="bi bi-arrow-counterclockwise"></i></button>
                                </template>
                            </td>
                        </tr>
                        <tr v-if="!rows.length"><td colspan="8" class="text-center text-body-secondary py-4">No hay personal en planilla en este semestre.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
