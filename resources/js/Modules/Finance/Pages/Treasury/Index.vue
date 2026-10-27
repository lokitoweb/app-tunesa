<script setup>
import { ref, computed } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import Pagination from '@/Modules/Core/Components/Pagination.vue';
import { useConfirm } from '@/Modules/Core/Composables/useConfirm';
import { useTenantPicker } from '@/Modules/Core/Composables/useTenantPicker';

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
    direct: { type: Object, default: null },
});

const { ask } = useConfirm();
const { ensureTenant } = useTenantPicker();
const search = ref(props.filters.search || '');
const expanded = ref(null);
const activeAccounts = computed(() => props.accounts.filter((a) => a.is_active));
const money = (v, c = 'PEN') => `${c === 'USD' ? 'US$' : c === 'EUR' ? '€' : 'S/'} ${Number(v || 0).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

// Excel de la pestaña actual con la misma búsqueda
const exportUrl = computed(() => `/treasury/export?${new URLSearchParams({ tab: props.filters.tab || 'to_schedule', ...(search.value ? { search: search.value } : {}) })}`);

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
    const ok = await ask({ title: '¿Autorizar el pago?', message: `${p.supplier?.name} · cuota ${p.installment} · ${money(p.balance, p.requisition?.currency)}`, confirmText: 'Sí, autorizar', variant: 'primary', icon: 'bi bi-check2-circle' });
    if (ok) router.post(`/treasury/installments/${p.id}/authorize`, {}, { preserveScroll: true });
}

// Varias a la vez (FrmRegpagos: cuotas marcadas)
const canAuthorizeRow = (p) => p.status === 'pending' && p.scheduled_for && !p.authorized && props.can.authorize;
const markable = computed(() => props.installments.data.filter(canAuthorizeRow));
const marked = ref([]);
async function authorizeMarked() {
    const total = markable.value.filter((p) => marked.value.includes(p.id)).reduce((s, p) => s + Number(p.balance), 0);
    const ok = await ask({ title: `¿Autorizar ${marked.value.length} pagos?`, message: `Total ${money(total)}`, confirmText: 'Sí, autorizar', variant: 'primary', icon: 'bi bi-check2-all' });
    if (ok) router.post('/treasury/installments/authorize', { ids: marked.value }, { preserveScroll: true, onSuccess: () => (marked.value = []) });
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

// ---------------------------------------------------------------- pago directo (FrmReqProg › Registrar)
const showDirect = ref(false);
const blankDirect = () => ({ supplier_id: '', detail: '', document_type: 'FACT', document_number: '', document_date: props.today, file: null, currency: 'PEN',
    amount: '', due_date: props.today, payment_method: 'TRANSFERENCIA', has_detraction: false, detraction_reason: '', is_advance: false, cash_flow_concept: '' });
const directForm = useForm(blankDirect());
function openDirect() {
    if (!ensureTenant({ message: 'El pago directo se registra en una empresa.' })) return;
    Object.assign(directForm, blankDirect());
    directForm.clearErrors();
    showDirect.value = true;
}
const directReason = computed(() => props.direct?.detraction_reasons.find((d) => d.code === directForm.detraction_reason));
const directDetraction = computed(() => (directForm.has_detraction && directReason.value ? Math.round(Math.round(Number(directForm.amount || 0) * directReason.value.percent * 10 / 100) / 10) : 0));
function submitDirect() {
    directForm.transform((d) => ({ ...d, file: d.file || undefined, detraction_reason: d.has_detraction ? d.detraction_reason : null, cash_flow_concept: d.cash_flow_concept || null }))
        .post('/treasury/direct', { preserveScroll: true, forceFormData: true, onSuccess: () => (showDirect.value = false) });
}

// ---------------------------------------------------------------- correcciones
const correctTarget = ref(null);
const correctForm = useForm({ company_bank_account_id: '', operation_number: '', voucher: null });
function openCorrect(p, pay) {
    correctTarget.value = { p, pay };
    Object.assign(correctForm, { company_bank_account_id: pay.account_id || '', operation_number: pay.operation || '', voucher: null });
    correctForm.clearErrors();
}
function submitCorrect() {
    correctForm.transform((d) => ({ ...d, voucher: d.voucher || undefined }))
        .post(`/treasury/payments/${correctTarget.value.pay.id}/correct`, { preserveScroll: true, forceFormData: true, onSuccess: () => (correctTarget.value = null) });
}
const editDirect = ref(null);
const editDirectForm = useForm({ detail: '', currency: 'PEN', amount: '' });
function openEditDirect(p) {
    editDirect.value = p;
    Object.assign(editDirectForm, { detail: p.requisition.detail, currency: p.requisition.currency, amount: p.requisition.amount });
    editDirectForm.clearErrors();
}
function submitEditDirect() {
    editDirectForm.put(`/treasury/direct/${editDirect.value.requisition.id}`, { preserveScroll: true, onSuccess: () => (editDirect.value = null) });
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
            <div class="d-flex flex-wrap gap-2">
                <button v-if="direct" type="button" class="btn btn-primary" @click="openDirect"><i class="bi bi-plus-lg me-1"></i>Pago directo</button>
                <button v-if="can.pay" type="button" class="btn btn-outline-primary" @click="showAccounts = !showAccounts">
                    <i class="bi bi-bank me-1"></i>Cuentas de origen <span class="badge bg-primary-subtle text-primary ms-1">{{ activeAccounts.length }}</span>
                </button>
            </div>
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
                <div class="d-flex flex-wrap gap-2 justify-content-between">
                    <div class="input-group" style="max-width: 460px">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input v-model="search" type="search" class="form-control" placeholder="Proveedor, RUC, N.º de orden o detalle" aria-label="Buscar cuota" @input="onSearch" />
                    </div>
                    <a :href="exportUrl" class="btn btn-outline-success"><i class="bi bi-file-earmark-excel me-1"></i>Exportar a Excel</a>
                </div>
                <div v-if="markable.length" class="d-flex flex-wrap align-items-center gap-2 mt-2 small">
                    <button type="button" class="btn btn-sm btn-link px-0" @click="marked = marked.length === markable.length ? [] : markable.map((p) => p.id)">{{ marked.length === markable.length ? 'Desmarcar todas' : 'Marcar todas' }}</button>
                    <button type="button" class="btn btn-sm btn-success" :disabled="!marked.length" @click="authorizeMarked"><i class="bi bi-check2-all me-1"></i>Autorizar marcadas ({{ marked.length }})</button>
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
                                    <div class="fw-semibold text-truncate">
                                        <input v-if="canAuthorizeRow(p)" v-model="marked" type="checkbox" class="form-check-input me-2" :value="p.id" :aria-label="`Marcar para autorizar: ${p.supplier?.name}`" />{{ p.supplier?.name || '—' }}
                                    </div>
                                    <div class="small text-body-secondary text-truncate">
                                        <Link :href="`/requisitions/${p.requisition?.id}`" class="font-monospace">{{ p.requisition?.order || p.requisition?.code }}</Link> · {{ p.requisition?.detail }}
                                    </div>
                                    <span v-if="p.is_advance" class="badge mt-1 me-1 bg-info-subtle text-info-emphasis">Anticipo</span>
                                    <span v-if="p.requisition?.has_detraction" class="badge mt-1" :class="p.requisition.detraction.detraction_number ? 'bg-info-subtle text-info-emphasis' : 'bg-warning-subtle text-warning-emphasis'">
                                        Detracción {{ p.requisition.detraction.detraction_number ? `${p.requisition.detraction.detraction_percent}% · ${money(p.requisition.detraction.detraction_amount, p.requisition.currency)}` : 'pendiente' }}
                                    </span>
                                </td>
                                <td class="small text-nowrap">{{ p.installment }} de {{ p.of }}<div class="text-body-secondary">{{ p.payment_method }}</div></td>
                                <td class="small text-nowrap">
                                    {{ p.due_date }}
                                    <div v-if="p.scheduled_for" class="text-primary"><i class="bi bi-calendar-check me-1"></i>{{ p.scheduled_for.split('-').reverse().join('/') }}</div>
                                    <div v-if="p.authorized" class="text-success"><i class="bi bi-check2-circle me-1"></i>Autorizada</div>
                                    <div v-if="!p.requisition?.has_invoice && p.status === 'pending'" class="text-warning-emphasis"><i class="bi bi-receipt-cutoff me-1"></i>Sin factura</div>
                                    <div v-if="p.paid_amount > 0 && p.status === 'pending'" class="text-info-emphasis"><i class="bi bi-arrow-repeat me-1"></i>Saldo por amortizar</div>
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
                                        <a v-if="p.scheduled_for" :href="`/treasury/installments/${p.id}/op.pdf`" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary" title="Orden de pago (PDF firmado)"><i class="bi bi-filetype-pdf"></i> OP</a>
                                        <button v-if="p.status === 'pending' && p.authorized && can.pay" type="button" class="btn btn-sm btn-success" :disabled="!p.requisition?.has_invoice" :title="p.requisition?.has_invoice ? '' : 'Registra la factura en el requerimiento'" @click="openPay(p)"><i class="bi bi-cash-coin me-1"></i>Pagar</button>
                                        <button v-if="p.requisition?.is_direct && p.status === 'pending' && p.paid_amount == 0 && can.pay" type="button" class="btn btn-sm btn-outline-secondary" title="Corregir pago directo" @click="openEditDirect(p)"><i class="bi bi-pencil-square"></i></button>
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
                                                <td class="text-end text-nowrap">
                                                    <a v-if="pay.has_voucher" :href="`/treasury/payments/${pay.id}/voucher`" target="_blank" rel="noopener" class="btn btn-sm btn-link p-0"><i class="bi bi-file-earmark-arrow-down me-1"></i>Voucher</a>
                                                    <button v-if="can.pay" type="button" class="btn btn-sm btn-link p-0 ms-2" @click="openCorrect(p, pay)"><i class="bi bi-pencil-square me-1"></i>Corregir</button>
                                                </td>
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
                            <div v-if="payTarget.supplier?.accounts?.length" class="text-body-secondary">{{ payTarget.requisition?.is_payroll ? 'Cuenta del legajo' : 'Cuentas del proveedor' }}: {{ payTarget.supplier.accounts.join(' · ') }}</div>
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
                            <div v-if="Number(pay.amount) > 0 && Number(pay.amount) < payTarget.balance" class="form-text text-warning-emphasis">Pago amortizado: el saldo vuelve a "Por programar" para una nueva orden de pago.</div>
                            <div v-else class="form-text">Pago completo: la cuota queda cancelada.</div>
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
                            <label class="form-label small fw-semibold" for="pay-voucher">Voucher del depósito (PDF o imagen, máx. 5 MB) *</label>
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
                                <input id="detr-pct" v-model="detr.detraction_percent" type="number" min="1" max="100" class="form-control" :class="{ 'is-invalid': detr.errors.detraction_percent }" :readonly="!!detrTarget.requisition.detraction.detraction_reason" @input="recalcDetraction" />
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                        <div class="col-7">
                            <label class="form-label small fw-semibold" for="detr-amount">Monto *</label>
                            <input id="detr-amount" v-model="detr.detraction_amount" type="number" min="0" step="0.01" class="form-control" :class="{ 'is-invalid': detr.errors.detraction_amount }" :readonly="!!detrTarget.requisition.detraction.detraction_reason" />
                            <div class="invalid-feedback">{{ detr.errors.detraction_amount }}</div>
                        </div>
                        <div v-if="detrTarget.requisition.detraction.detraction_reason" class="col-12 small text-body-secondary">
                            El % y el monto se fijaron al emitir la orden (motivo {{ detrTarget.requisition.detraction.detraction_reason }}); las cuotas del proveedor ya descuentan la detracción.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" @click="detrTarget = null">Cancelar</button>
                        <button type="submit" class="btn btn-primary" :disabled="detr.processing">Guardar detracción</button>
                    </div>
                </form>
            </div>
        </div>
        <!-- Corregir un pago registrado -->
        <div v-if="correctTarget" class="modal d-block" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="corr-title" style="background: rgba(0,0,0,.45)" @click.self="correctTarget = null">
            <div class="modal-dialog modal-dialog-centered">
                <form class="modal-content" novalidate @submit.prevent="submitCorrect">
                    <div class="modal-header">
                        <h2 id="corr-title" class="modal-title h5">Corregir pago · {{ correctTarget.p.requisition?.order }}</h2>
                        <button type="button" class="btn-close" aria-label="Cerrar" @click="correctTarget = null"></button>
                    </div>
                    <div class="modal-body row g-3">
                        <div class="col-12 small text-body-secondary">{{ correctTarget.pay.paid_at }} · {{ money(correctTarget.pay.amount, correctTarget.p.requisition?.currency) }}. El monto no se corrige aquí.</div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold" for="corr-acc">Cuenta de origen *</label>
                            <select id="corr-acc" v-model="correctForm.company_bank_account_id" class="form-select" :class="{ 'is-invalid': correctForm.errors.company_bank_account_id }">
                                <option value="" disabled>Elige…</option>
                                <option v-for="a in activeAccounts" :key="a.id" :value="a.id">{{ a.label }}</option>
                            </select>
                            <div class="invalid-feedback">{{ correctForm.errors.company_bank_account_id }}</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold" for="corr-op">N.º de operación *</label>
                            <input id="corr-op" v-model.trim="correctForm.operation_number" maxlength="50" class="form-control font-monospace" :class="{ 'is-invalid': correctForm.errors.operation_number }" />
                            <div class="invalid-feedback">{{ correctForm.errors.operation_number }}</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold" for="corr-v">Reemplazar voucher <span class="fw-normal text-body-secondary">(opcional)</span></label>
                            <input id="corr-v" type="file" accept="application/pdf,image/*" class="form-control" :class="{ 'is-invalid': correctForm.errors.voucher }" @change="correctForm.voucher = $event.target.files[0] || null" />
                            <div class="invalid-feedback">{{ correctForm.errors.voucher }}</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" @click="correctTarget = null">Cancelar</button>
                        <button type="submit" class="btn btn-primary" :disabled="correctForm.processing">Guardar corrección</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Corregir un pago directo sin pagos -->
        <div v-if="editDirect" class="modal d-block" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="ed-title" style="background: rgba(0,0,0,.45)" @click.self="editDirect = null">
            <div class="modal-dialog modal-dialog-centered">
                <form class="modal-content" novalidate @submit.prevent="submitEditDirect">
                    <div class="modal-header">
                        <h2 id="ed-title" class="modal-title h5">Corregir pago directo · {{ editDirect.requisition.order }}</h2>
                        <button type="button" class="btn-close" aria-label="Cerrar" @click="editDirect = null"></button>
                    </div>
                    <div class="modal-body row g-3">
                        <div class="col-12">
                            <label class="form-label small fw-semibold" for="ed-detail">Detalle *</label>
                            <textarea id="ed-detail" v-model.trim="editDirectForm.detail" rows="2" maxlength="1000" class="form-control" :class="{ 'is-invalid': editDirectForm.errors.detail }"></textarea>
                            <div class="invalid-feedback">{{ editDirectForm.errors.detail }}</div>
                        </div>
                        <div class="col-5">
                            <label class="form-label small fw-semibold" for="ed-cur">Moneda</label>
                            <select id="ed-cur" v-model="editDirectForm.currency" class="form-select"><option v-for="(label, code) in currencies" :key="code" :value="code">{{ label }}</option></select>
                        </div>
                        <div class="col-7">
                            <label class="form-label small fw-semibold" for="ed-amount">Monto total *</label>
                            <input id="ed-amount" v-model="editDirectForm.amount" type="number" min="0.01" step="0.01" class="form-control" :class="{ 'is-invalid': editDirectForm.errors.amount }" />
                            <div class="invalid-feedback">{{ editDirectForm.errors.amount }}</div>
                        </div>
                        <div class="col-12 small text-body-secondary">Si cambia el monto o la moneda, el pago vuelve a necesitar autorización.</div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" @click="editDirect = null">Cancelar</button>
                        <button type="submit" class="btn btn-primary" :disabled="editDirectForm.processing">Guardar corrección</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Pago directo de Tesorería -->
        <div v-if="showDirect && direct" class="modal d-block" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="direct-title" style="background: rgba(0,0,0,.45)" @click.self="showDirect = false">
            <div class="modal-dialog modal-dialog-scrollable modal-fullscreen-sm-down modal-dialog-centered modal-lg">
                <form class="modal-content" novalidate @submit.prevent="submitDirect">
                    <div class="modal-header">
                        <h2 id="direct-title" class="modal-title h5">Pago directo</h2>
                        <button type="button" class="btn-close" aria-label="Cerrar" @click="showDirect = false"></button>
                    </div>
                    <div class="modal-body row g-3">
                        <div class="col-12 small text-body-secondary">Pagos que no pasan por logística (servicios, alquileres, devoluciones…). Quedan por programar y siguen la misma autorización.</div>
                        <div class="col-md-7">
                            <label class="form-label small fw-semibold" for="dp-supplier">Proveedor *</label>
                            <select id="dp-supplier" v-model="directForm.supplier_id" class="form-select" :class="{ 'is-invalid': directForm.errors.supplier_id }">
                                <option value="" disabled>Elige…</option>
                                <option v-for="s in direct.suppliers" :key="s.id" :value="s.id">{{ s.name }} · {{ s.ruc }}</option>
                            </select>
                            <div class="invalid-feedback">{{ directForm.errors.supplier_id }}</div>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small fw-semibold" for="dp-method">Medio de pago</label>
                            <select id="dp-method" v-model="directForm.payment_method" class="form-select"><option v-for="m in direct.payment_methods" :key="m" :value="m">{{ m }}</option></select>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold" for="dp-detail">Detalle *</label>
                            <textarea id="dp-detail" v-model.trim="directForm.detail" rows="2" maxlength="1000" class="form-control" :class="{ 'is-invalid': directForm.errors.detail }"></textarea>
                            <div class="invalid-feedback">{{ directForm.errors.detail }}</div>
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label small fw-semibold" for="dp-doctype">Documento *</label>
                            <select id="dp-doctype" v-model="directForm.document_type" class="form-select"><option v-for="(label, code) in direct.document_types" :key="code" :value="code">{{ label }}</option></select>
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label small fw-semibold" for="dp-docnum">Serie y número{{ directForm.document_type === 'ORDP' ? '' : ' *' }}</label>
                            <input id="dp-docnum" v-model.trim="directForm.document_number" class="form-control font-monospace" maxlength="40" :placeholder="directForm.document_type === 'ORDP' ? 'Vacío: correlativo OP automático' : 'F001-000123'" :class="{ 'is-invalid': directForm.errors.document_number }" />
                            <div class="invalid-feedback">{{ directForm.errors.document_number }}</div>
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label small fw-semibold" for="dp-docdate">Fecha del documento *</label>
                            <input id="dp-docdate" v-model="directForm.document_date" type="date" :max="today" class="form-control" :class="{ 'is-invalid': directForm.errors.document_date }" />
                            <div class="invalid-feedback">{{ directForm.errors.document_date }}</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold" for="dp-file">PDF del documento <span class="fw-normal text-body-secondary">(sin él no se puede pagar)</span></label>
                            <input id="dp-file" type="file" accept="application/pdf" class="form-control" :class="{ 'is-invalid': directForm.errors.file }" @change="directForm.file = $event.target.files[0] || null" />
                            <div class="invalid-feedback">{{ directForm.errors.file }}</div>
                        </div>
                        <div class="col-sm-3">
                            <label class="form-label small fw-semibold" for="dp-currency">Moneda</label>
                            <select id="dp-currency" v-model="directForm.currency" class="form-select"><option v-for="(label, code) in currencies" :key="code" :value="code">{{ label }}</option></select>
                        </div>
                        <div class="col-sm-5">
                            <label class="form-label small fw-semibold" for="dp-amount">Monto total *</label>
                            <input id="dp-amount" v-model="directForm.amount" type="number" min="0.01" step="0.01" class="form-control" :class="{ 'is-invalid': directForm.errors.amount }" />
                            <div class="invalid-feedback">{{ directForm.errors.amount }}</div>
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label small fw-semibold" for="dp-due">Vence *</label>
                            <input id="dp-due" v-model="directForm.due_date" type="date" class="form-control" :class="{ 'is-invalid': directForm.errors.due_date }" />
                            <div class="invalid-feedback">{{ directForm.errors.due_date }}</div>
                        </div>
                        <div class="col-sm-4 d-flex align-items-end">
                            <div class="form-check mb-2">
                                <input id="dp-detr" v-model="directForm.has_detraction" class="form-check-input" type="checkbox" />
                                <label class="form-check-label small" for="dp-detr">Afecta a detracción</label>
                            </div>
                        </div>
                        <div v-if="directForm.has_detraction" class="col-sm-8">
                            <label class="form-label small fw-semibold" for="dp-reason">Motivo de detracción *</label>
                            <select id="dp-reason" v-model="directForm.detraction_reason" class="form-select" :class="{ 'is-invalid': directForm.errors.detraction_reason }">
                                <option value="" disabled>Elige el motivo…</option>
                                <option v-for="d in direct.detraction_reasons" :key="d.code" :value="d.code">{{ d.label }} ({{ d.percent }} %)</option>
                            </select>
                            <div class="invalid-feedback">{{ directForm.errors.detraction_reason }}</div>
                        </div>
                        <div v-if="directForm.has_detraction && directReason" class="col-12">
                            <div class="alert alert-info py-2 small mb-0">Detracción {{ directReason.percent }} %: <strong>{{ money(directDetraction, directForm.currency) }}</strong> · neto a pagar: <strong>{{ money(Number(directForm.amount || 0) - directDetraction, directForm.currency) }}</strong></div>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold" for="dp-flow">Concepto del flujo de efectivo</label>
                            <select id="dp-flow" v-model="directForm.cash_flow_concept" class="form-select">
                                <option value="">{{ directForm.is_advance ? 'Anticipos concedidos a terceros (por ser anticipo)' : '— Sin asignar —' }}</option>
                                <optgroup v-for="(concepts, activity) in direct.cash_flow_concepts" :key="activity" :label="activity">
                                    <option v-for="cf in concepts" :key="cf" :value="cf">{{ cf }}</option>
                                </optgroup>
                            </select>
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input id="dp-adv" v-model="directForm.is_advance" class="form-check-input" type="checkbox" />
                                <label class="form-check-label small" for="dp-adv">Es un anticipo: una vez pagado, se descontará de la siguiente orden de este proveedor</label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" @click="showDirect = false">Cancelar</button>
                        <button type="submit" class="btn btn-primary" :disabled="directForm.processing">
                            <span v-if="directForm.processing" class="spinner-border spinner-border-sm me-1"></span>Registrar pago
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
