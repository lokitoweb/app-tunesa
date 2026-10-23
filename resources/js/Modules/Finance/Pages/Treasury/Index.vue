<script setup>
import { ref, computed } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import Pagination from '@/Modules/Core/Components/Pagination.vue';
import { useConfirm } from '@/Modules/Core/Composables/useConfirm';

const props = defineProps({
    installments: { type: Object, required: true },
    tabs: { type: Object, required: true },
    counts: { type: Object, default: () => ({}) },
    kpis: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
    accounts: { type: Array, default: () => [] },
    currencies: { type: Object, default: () => ({}) },
    can: { type: Object, required: true },
    today: { type: String, required: true },
});

const { ask } = useConfirm();
const search = ref(props.filters.search || '');
const expanded = ref(null);
const activeAccounts = computed(() => props.accounts.filter((a) => a.is_active));
const money = (v, c = 'PEN') => `${c === 'USD' ? 'US$' : c === 'EUR' ? '€' : 'S/'} ${Number(v || 0).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

let timer = null;
function go(params) {
    router.get('/treasury', { tab: props.filters.tab, search: search.value || undefined, ...params }, { preserveState: true, replace: true });
}
function onSearch() {
    clearTimeout(timer);
    timer = setTimeout(() => go({}), 300);
}

// ---------------------------------------------------------------- programar / autorizar
const scheduleDates = ref({});
function schedule(p) {
    router.post(`/treasury/installments/${p.id}/schedule`, { scheduled_for: scheduleDates.value[p.id] || p.scheduled_for || p.due_iso }, { preserveScroll: true });
}
async function authorize(p) {
    const ok = await ask({ title: '¿Autorizar el pago?', message: `${p.supplier?.name} · cuota ${p.installment} · ${money(p.balance, p.requisition?.currency)}`, confirmText: 'Sí, autorizar', icon: 'bi bi-check2-circle' });
    if (ok) router.post(`/treasury/installments/${p.id}/authorize`, {}, { preserveScroll: true });
}

// ---------------------------------------------------------------- pagar
const payTarget = ref(null);
const pay = useForm({ paid_at: props.today, amount: '', company_bank_account_id: '', operation_number: '', voucher: null });
function openPay(p) {
    payTarget.value = p;
    // Sin form.reset(): Inertia toma como defaults lo último enviado con éxito
    Object.assign(pay, { paid_at: props.today, amount: p.balance, company_bank_account_id: activeAccounts.value[0]?.id || '', operation_number: '', voucher: null });
    pay.clearErrors();
}
function submitPay() {
    pay.post(`/treasury/installments/${payTarget.value.id}/pay`, { preserveScroll: true, forceFormData: true, onSuccess: () => (payTarget.value = null) });
}

// ---------------------------------------------------------------- detracción
const detrTarget = ref(null);
const detr = useForm({ detraction_number: '', detraction_percent: 12, detraction_amount: '' });
function openDetraction(p) {
    detrTarget.value = p;
    const d = p.requisition.detraction;
    detr.clearErrors();
    detr.detraction_number = d.detraction_number || '';
    detr.detraction_percent = d.detraction_percent || 12;
    detr.detraction_amount = d.detraction_amount || (p.requisition.amount * 0.12).toFixed(2);
}
function recalcDetraction() {
    detr.detraction_amount = ((detrTarget.value.requisition.amount * Number(detr.detraction_percent || 0)) / 100).toFixed(2);
}
function submitDetraction() {
    detr.post(`/treasury/requisitions/${detrTarget.value.requisition.id}/detraction`, { preserveScroll: true, onSuccess: () => (detrTarget.value = null) });
}

// ---------------------------------------------------------------- cuentas de origen
const showAccounts = ref(false);
const account = useForm({ id: null, bank: '', account_number: '', currency: 'PEN', alias: '', is_active: true });
function editAccount(a) {
    Object.assign(account, { id: a.id, bank: a.bank, account_number: a.account_number, currency: a.currency, alias: a.alias || '', is_active: a.is_active });
    account.clearErrors();
}
function saveAccount() {
    const options = { preserveScroll: true, onSuccess: () => Object.assign(account, { id: null, bank: '', account_number: '', currency: 'PEN', alias: '', is_active: true }) };
    if (account.id) account.put(`/treasury/accounts/${account.id}`, options);
    else account.post('/treasury/accounts', options);
}

const isOverdue = (p) => p.status === 'pending' && p.due_iso < props.today;
</script>

<template>
    <AppLayout title="Tesorería y pagos">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
            <div>
                <h1 class="h4 fw-bold mb-1">Tesorería y pagos</h1>
                <p class="text-body-secondary small mb-0">Programa las cuotas de las órdenes, autoriza y registra los pagos con su voucher.</p>
            </div>
            <button v-if="can.pay" type="button" class="btn btn-outline-primary" @click="showAccounts = !showAccounts">
                <i class="bi bi-bank me-1"></i>Cuentas de origen <span class="badge bg-primary-subtle text-primary ms-1">{{ activeAccounts.length }}</span>
            </button>
        </div>

        <!-- KPIs -->
        <div class="row g-3 mb-3">
            <div v-for="k in [['overdue', 'Vencido', 'danger', 'exclamation-octagon'], ['week', 'Vence en 7 días', 'warning', 'calendar-week'], ['pending', 'Total por pagar', 'primary', 'hourglass-split'], ['paid_month', 'Pagado este mes', 'success', 'cash-coin']]" :key="k[0]" class="col-6 col-xl-3">
                <div class="card shadow-sm h-100">
                    <div class="card-body d-flex align-items-center gap-3">
                        <span class="rounded-3 d-inline-flex align-items-center justify-content-center flex-shrink-0" :class="`bg-${k[2]}-subtle text-${k[2]}`" style="width: 42px; height: 42px"><i class="bi fs-5" :class="`bi-${k[3]}`"></i></span>
                        <div class="min-w-0">
                            <div class="small text-body-secondary">{{ k[1] }}</div>
                            <div class="fw-bold text-truncate">{{ money(kpis[k[0]]) }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Cuentas de origen -->
        <div v-if="showAccounts" class="card shadow-sm mb-3">
            <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                <span class="fw-semibold">Cuentas de la empresa (origen de los pagos)</span>
                <button type="button" class="btn-close" aria-label="Cerrar" @click="showAccounts = false"></button>
            </div>
            <div class="card-body row g-3">
                <div class="col-lg-7">
                    <ul class="list-group">
                        <li v-for="a in accounts" :key="a.id" class="list-group-item d-flex justify-content-between align-items-center" :class="{ 'text-body-secondary': !a.is_active }">
                            <span><span class="fw-semibold">{{ a.alias || a.bank }}</span> · {{ a.bank }} <span class="font-monospace">{{ a.account_number }}</span> · {{ a.currency }}
                                <span v-if="!a.is_active" class="badge bg-secondary-subtle text-secondary ms-1">Inactiva</span></span>
                            <button type="button" class="btn-table-action btn-action-edit" title="Editar cuenta" @click="editAccount(a)"><i class="bi bi-pencil-fill"></i></button>
                        </li>
                        <li v-if="!accounts.length" class="list-group-item text-body-secondary small">Registra al menos una cuenta para poder pagar.</li>
                    </ul>
                </div>
                <form class="col-lg-5 row g-2" novalidate @submit.prevent="saveAccount">
                    <div class="col-6"><input v-model="account.bank" class="form-control form-control-sm" :class="{ 'is-invalid': account.errors.bank }" placeholder="Banco" aria-label="Banco" maxlength="50" /><div class="invalid-feedback">{{ account.errors.bank }}</div></div>
                    <div class="col-6"><input v-model="account.alias" class="form-control form-control-sm" placeholder="Alias (opcional)" aria-label="Alias" maxlength="60" /></div>
                    <div class="col-8"><input v-model.trim="account.account_number" class="form-control form-control-sm font-monospace" :class="{ 'is-invalid': account.errors.account_number }" placeholder="N.º de cuenta" aria-label="Número de cuenta" maxlength="100" /><div class="invalid-feedback">{{ account.errors.account_number }}</div></div>
                    <div class="col-4"><select v-model="account.currency" class="form-select form-select-sm" aria-label="Moneda"><option v-for="(l, c) in currencies" :key="c" :value="c">{{ c }}</option></select></div>
                    <div class="col-12 d-flex justify-content-between align-items-center">
                        <div class="form-check form-switch"><input id="acc-active" v-model="account.is_active" class="form-check-input" type="checkbox" /><label class="form-check-label small" for="acc-active">Activa</label></div>
                        <button type="submit" class="btn btn-sm btn-primary" :disabled="account.processing">{{ account.id ? 'Guardar' : 'Agregar cuenta' }}</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Pestañas -->
        <div class="d-flex flex-wrap gap-2 mb-3" aria-label="Estado de las cuotas">
            <button v-for="(label, key) in tabs" :key="key" type="button" class="btn btn-sm"
                :class="filters.tab === key ? (key === 'overdue' ? 'btn-danger' : 'btn-primary') : 'btn-outline-secondary'" @click="go({ tab: key })">
                {{ label }} <span class="badge bg-light text-dark ms-1">{{ counts[key] || 0 }}</span>
            </button>
        </div>

        <div class="card shadow-sm">
            <div class="card-body border-bottom">
                <div class="input-group" style="max-width: 460px">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input v-model="search" type="search" class="form-control" placeholder="Proveedor, RUC, N.º de orden o detalle" aria-label="Buscar cuota" @input="onSearch" />
                </div>
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Proveedor / orden</th>
                            <th>Cuota</th>
                            <th>Vence</th>
                            <th class="text-end">Saldo</th>
                            <th class="text-end">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="p in installments.data" :key="p.id">
                            <tr :class="{ 'table-danger': isOverdue(p) }">
                                <td style="max-width: 280px">
                                    <div class="fw-semibold text-truncate">{{ p.supplier?.name || '—' }}</div>
                                    <div class="small text-body-secondary text-truncate">
                                        <Link :href="`/requisitions/${p.requisition?.id}`" class="font-monospace">{{ p.requisition?.order || p.requisition?.code }}</Link> · {{ p.requisition?.detail }}
                                    </div>
                                    <span v-if="p.requisition?.has_detraction" class="badge mt-1" :class="p.requisition.detraction.detraction_number ? 'bg-info-subtle text-info-emphasis' : 'bg-warning-subtle text-warning-emphasis'">
                                        Detracción {{ p.requisition.detraction.detraction_number ? `${p.requisition.detraction.detraction_percent}% · ${money(p.requisition.detraction.detraction_amount, p.requisition.currency)}` : 'pendiente' }}
                                    </span>
                                </td>
                                <td class="small text-nowrap">{{ p.installment }} de {{ p.of }}<div class="text-body-secondary">{{ p.payment_method }}</div></td>
                                <td class="small text-nowrap">
                                    {{ p.due_date }}
                                    <div v-if="p.scheduled_for" class="text-primary"><i class="bi bi-calendar-check me-1"></i>{{ p.scheduled_for.split('-').reverse().join('/') }}</div>
                                    <div v-if="p.authorized" class="text-success"><i class="bi bi-check2-circle me-1"></i>Autorizada</div>
                                </td>
                                <td class="text-end text-nowrap">
                                    <div class="fw-semibold">{{ money(p.status === 'paid' ? p.amount : p.balance, p.requisition?.currency) }}</div>
                                    <div v-if="p.paid_amount > 0 && p.status !== 'paid'" class="small text-body-secondary">de {{ money(p.amount, p.requisition?.currency) }}</div>
                                </td>
                                <td class="text-end">
                                    <div class="d-flex flex-wrap gap-1 justify-content-end align-items-center">
                                        <template v-if="p.status === 'pending' && can.pay && !p.authorized">
                                            <input v-model="scheduleDates[p.id]" type="date" class="form-control form-control-sm" style="width: 140px" :placeholder="p.due_iso" :aria-label="`Fecha de pago cuota ${p.installment}`" />
                                            <button type="button" class="btn btn-sm btn-outline-primary" @click="schedule(p)">{{ p.scheduled_for ? 'Reprogramar' : 'Programar' }}</button>
                                        </template>
                                        <button v-if="p.status === 'pending' && p.scheduled_for && !p.authorized && can.authorize" type="button" class="btn btn-sm btn-success" @click="authorize(p)">Autorizar</button>
                                        <button v-if="p.status === 'pending' && p.authorized && can.pay" type="button" class="btn btn-sm btn-success" @click="openPay(p)"><i class="bi bi-cash-coin me-1"></i>Pagar</button>
                                        <button v-if="p.requisition?.has_detraction && can.pay" type="button" class="btn btn-sm btn-outline-secondary" title="Detracción" @click="openDetraction(p)"><i class="bi bi-percent"></i></button>
                                        <button v-if="p.payments.length" type="button" class="btn btn-sm btn-light" :title="expanded === p.id ? 'Ocultar pagos' : 'Ver pagos'" @click="expanded = expanded === p.id ? null : p.id">
                                            <i class="bi" :class="expanded === p.id ? 'bi-chevron-up' : 'bi-receipt'"></i> {{ p.payments.length }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="expanded === p.id">
                                <td colspan="5" class="bg-body-tertiary">
                                    <table class="table table-sm mb-0 small">
                                        <thead><tr><th>Fecha</th><th>Cuenta de origen</th><th>N.º operación</th><th class="text-end">Monto</th><th>Registró</th><th></th></tr></thead>
                                        <tbody>
                                            <tr v-for="pay in p.payments" :key="pay.id">
                                                <td>{{ pay.paid_at }}</td>
                                                <td>{{ pay.account }}</td>
                                                <td class="font-monospace">{{ pay.operation }}</td>
                                                <td class="text-end">{{ money(pay.amount, p.requisition?.currency) }}</td>
                                                <td>{{ pay.registered_by }}</td>
                                                <td class="text-end"><a v-if="pay.has_voucher" :href="`/treasury/payments/${pay.id}/voucher`" target="_blank" rel="noopener" class="btn btn-sm btn-link p-0"><i class="bi bi-file-earmark-arrow-down me-1"></i>Voucher</a></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="!installments.data.length">
                            <td colspan="5" class="text-center text-body-secondary py-5"><i class="bi bi-bank fs-2 d-block mb-2"></i>No hay cuotas en esta pestaña.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="card-footer bg-transparent d-flex justify-content-between align-items-center">
                <span class="small text-body-secondary">{{ installments.total }} cuota(s)</span>
                <Pagination :paginator="installments" />
            </div>
        </div>

        <!-- Modal pagar -->
        <div v-if="payTarget" class="modal d-block" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="pay-title" style="background: rgba(0,0,0,.45)" @click.self="payTarget = null">
            <div class="modal-dialog modal-dialog-centered">
                <form class="modal-content" novalidate @submit.prevent="submitPay">
                    <div class="modal-header">
                        <h2 id="pay-title" class="modal-title h5">Registrar pago · cuota {{ payTarget.installment }}</h2>
                        <button type="button" class="btn-close" aria-label="Cerrar" @click="payTarget = null"></button>
                    </div>
                    <div class="modal-body row g-3">
                        <div class="col-12 small">
                            <div class="fw-semibold">{{ payTarget.supplier?.name }} <span class="font-monospace text-body-secondary">{{ payTarget.supplier?.ruc }}</span></div>
                            <div class="text-body-secondary">Saldo: {{ money(payTarget.balance, payTarget.requisition?.currency) }}</div>
                            <div v-if="payTarget.supplier?.accounts?.length" class="text-body-secondary">Cuentas del proveedor: {{ payTarget.supplier.accounts.join(' · ') }}</div>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small fw-semibold" for="pay-date">Fecha de pago *</label>
                            <input id="pay-date" v-model="pay.paid_at" type="date" :max="today" class="form-control" :class="{ 'is-invalid': pay.errors.paid_at }" />
                            <div class="invalid-feedback">{{ pay.errors.paid_at }}</div>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small fw-semibold" for="pay-amount">Monto *</label>
                            <input id="pay-amount" v-model="pay.amount" type="number" min="0" step="0.01" :max="payTarget.balance" class="form-control" :class="{ 'is-invalid': pay.errors.amount }" />
                            <div class="invalid-feedback">{{ pay.errors.amount }}</div>
                            <div v-if="Number(pay.amount) > 0 && Number(pay.amount) < payTarget.balance" class="form-text text-warning-emphasis">Amortización parcial: la cuota seguirá con saldo.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold" for="pay-account">Cuenta de origen *</label>
                            <select id="pay-account" v-model="pay.company_bank_account_id" class="form-select" :class="{ 'is-invalid': pay.errors.company_bank_account_id }">
                                <option value="" disabled>Elige…</option>
                                <option v-for="a in activeAccounts" :key="a.id" :value="a.id">{{ a.label }}</option>
                            </select>
                            <div class="invalid-feedback">{{ pay.errors.company_bank_account_id }}</div>
                            <div v-if="!activeAccounts.length" class="form-text text-danger">No hay cuentas de origen activas: regístralas en «Cuentas de origen».</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold" for="pay-op">N.º de operación *</label>
                            <input id="pay-op" v-model.trim="pay.operation_number" class="form-control font-monospace" :class="{ 'is-invalid': pay.errors.operation_number }" maxlength="50" />
                            <div class="invalid-feedback">{{ pay.errors.operation_number }}</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold" for="pay-voucher">Voucher (PDF o imagen, máx. 5 MB)</label>
                            <input id="pay-voucher" type="file" accept=".pdf,image/*" class="form-control" :class="{ 'is-invalid': pay.errors.voucher }" @input="pay.voucher = $event.target.files[0]" />
                            <div class="invalid-feedback">{{ pay.errors.voucher }}</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" @click="payTarget = null">Cancelar</button>
                        <button type="submit" class="btn btn-success" :disabled="pay.processing">
                            <span v-if="pay.processing" class="spinner-border spinner-border-sm me-1"></span>Registrar pago
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Modal detracción -->
        <div v-if="detrTarget" class="modal d-block" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="detr-title" style="background: rgba(0,0,0,.45)" @click.self="detrTarget = null">
            <div class="modal-dialog modal-dialog-centered">
                <form class="modal-content" novalidate @submit.prevent="submitDetraction">
                    <div class="modal-header">
                        <h2 id="detr-title" class="modal-title h5">Detracción · {{ detrTarget.requisition.order }}</h2>
                        <button type="button" class="btn-close" aria-label="Cerrar" @click="detrTarget = null"></button>
                    </div>
                    <div class="modal-body row g-3">
                        <div class="col-12 small text-body-secondary">Monto de la orden: {{ money(detrTarget.requisition.amount, detrTarget.requisition.currency) }}</div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold" for="detr-number">N.º de constancia *</label>
                            <input id="detr-number" v-model.trim="detr.detraction_number" class="form-control font-monospace" :class="{ 'is-invalid': detr.errors.detraction_number }" maxlength="100" />
                            <div class="invalid-feedback">{{ detr.errors.detraction_number }}</div>
                        </div>
                        <div class="col-5">
                            <label class="form-label small fw-semibold" for="detr-pct">Porcentaje *</label>
                            <div class="input-group">
                                <input id="detr-pct" v-model="detr.detraction_percent" type="number" min="1" max="100" class="form-control" :class="{ 'is-invalid': detr.errors.detraction_percent }" @input="recalcDetraction" />
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                        <div class="col-7">
                            <label class="form-label small fw-semibold" for="detr-amount">Monto *</label>
                            <input id="detr-amount" v-model="detr.detraction_amount" type="number" min="0" step="0.01" class="form-control" :class="{ 'is-invalid': detr.errors.detraction_amount }" />
                            <div class="invalid-feedback">{{ detr.errors.detraction_amount }}</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" @click="detrTarget = null">Cancelar</button>
                        <button type="submit" class="btn btn-primary" :disabled="detr.processing">Guardar detracción</button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
