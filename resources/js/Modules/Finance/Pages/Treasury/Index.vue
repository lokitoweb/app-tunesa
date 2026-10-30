<script setup>
import { ref, computed } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import AppModal from '@/Modules/Core/Components/AppModal.vue';
import Pagination from '@/Modules/Core/Components/Pagination.vue';
import { useConfirm } from '@/Modules/Core/Composables/useConfirm';
import { useTenantPicker } from '@/Modules/Core/Composables/useTenantPicker';
import { money } from '@/Modules/Core/format';

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
// Días al vencimiento (sisgere FrmReqProg columna "Dias": negativo = vencida), con la fecha de la empresa
const daysTo = (iso) => Math.round((new Date(`${iso}T00:00:00`) - new Date(`${props.today}T00:00:00`)) / 86400000);
const activeAccounts = computed(() => props.accounts.filter((a) => a.is_active));


// Filtros de FrmRegpagos: Desde / Hasta y "Mostrar Planilla ?"
const from = ref(props.filters.from || '');
const to = ref(props.filters.to || '');
const payroll = ref(props.filters.payroll !== false);
const query = () => ({ tab: props.filters.tab || 'to_schedule', ...(search.value ? { search: search.value } : {}), ...(from.value ? { from: from.value } : {}),
    ...(to.value ? { to: to.value } : {}), ...(payroll.value ? {} : { payroll: '0' }) });
// Excel de la pestaña actual con los mismos filtros
const exportUrl = computed(() => `/treasury/export?${new URLSearchParams(query())}`);

let timer = null;
function go(params) {
    router.get('/treasury', { ...query(), ...params }, { preserveState: true, replace: true });
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
const showImport = ref(false);
const importForm = useForm({ currency: 'PEN', file: null });
function sendImport() {
    importForm.post('/treasury/direct/import', { preserveScroll: true, forceFormData: true, onSuccess: () => { showImport.value = false; importForm.reset('file'); } });
}
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
// Concepto del flujo de efectivo (sisgere flujo_requerimiento) y corrección de monto de una OC/OS sin pagos
const flowTarget = ref(null);
const flowForm = useForm({ cash_flow_concept: '' });
function openFlow(p) {
    flowTarget.value = p;
    flowForm.cash_flow_concept = p.requisition.cash_flow_concept || '';
    flowForm.clearErrors();
}
function submitFlow() {
    flowForm.put(`/treasury/orders/${flowTarget.value.requisition.id}/cash-flow`, { preserveScroll: true, onSuccess: () => (flowTarget.value = null) });
}
const amountTarget = ref(null);
const amountForm = useForm({ amount: '', reason: '' });
function openAmount(p) {
    amountTarget.value = p;
    Object.assign(amountForm, { amount: p.requisition.amount, reason: '' });
    amountForm.clearErrors();
}
function submitAmount() {
    amountForm.put(`/treasury/orders/${amountTarget.value.requisition.id}/amount`, { preserveScroll: true, onSuccess: () => (amountTarget.value = null) });
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
        <header class="page-head">
            <div class="page-head-text">
                <h1 class="page-title">Tesorería y pagos</h1>
                <p class="page-subtitle">Programa las cuotas de las órdenes, autoriza y registra los pagos con su voucher.</p>
            </div>
            <div class="page-actions">
                <button v-if="can.pay" type="button" class="btn btn-light border" :aria-expanded="showAccounts" @click="showAccounts = !showAccounts">
                    <i class="bi bi-bank me-1"></i>Cuentas de origen <span class="count-pill ms-1">{{ activeAccounts.length }}</span>
                </button>
                <button v-if="direct" type="button" class="btn btn-light border" :aria-expanded="showImport" @click="showImport = !showImport"><i class="bi bi-file-earmark-arrow-up me-1"></i>Cargar gastos</button>
                <button v-if="direct" type="button" class="btn btn-primary" @click="openDirect"><i class="bi bi-plus-lg me-1"></i>Pago directo</button>
            </div>
        </header>

        <!-- Carga de gastos desde Excel (sisgere Tesorería › Bancos): cada fila queda como pago directo por programar -->
        <AppModal :show="showImport" title="Cargar gastos desde Excel" :busy="importForm.processing" @close="showImport = false"
            subtitle="Columnas: Fecha, Medio, Tipo, Serie, Número, RUC, Glosa, Monto. El proveedor debe estar registrado con su RUC. Si una fila tiene error no se carga ninguna.">
            <form id="tr-import-form" class="row g-3" novalidate @submit.prevent="sendImport">
                    <div class="col-sm-4">
                        <label class="form-label" for="imp-cur">Moneda</label>
                        <select id="imp-cur" v-model="importForm.currency" class="form-select"><option v-for="(label, code) in currencies" :key="code" :value="code">{{ label }}</option></select>
                    </div>
                    <div class="col-sm-8">
                        <label class="form-label" for="imp-file">Archivo Excel</label>
                        <input id="imp-file" type="file" accept=".xlsx,.xls,.csv" class="form-control" :class="{ 'is-invalid': importForm.errors.file }" @change="importForm.file = $event.target.files[0]" />
                        <div class="invalid-feedback">{{ importForm.errors.file }}</div>
                    </div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-light border" @click="showImport = false">Cancelar</button>
                <button type="submit" form="tr-import-form" class="btn btn-primary" :disabled="importForm.processing || !importForm.file"><i class="bi bi-upload me-1"></i>Procesar carga</button>
            </template>
        </AppModal>

        <!-- Indicadores: un total por moneda -->
        <section class="dash-kpis" aria-label="Resumen de pagos">
            <article v-for="k in [['overdue', 'Vencido', 'rose', 'exclamation-octagon'], ['week', 'Vence en 7 días', 'amber', 'calendar-week'], ['pending', 'Total por pagar', 'brand', 'hourglass-split'], ['paid_month', 'Pagado este mes', 'emerald', 'check2-circle']]" :key="k[0]" class="dash-kpi-card">
                <div class="dash-kpi-top">
                    <span class="dash-kpi-label">{{ k[1] }}</span>
                    <span class="dash-kpi-icon" :class="`tone-${k[2]}`"><i class="bi" :class="`bi-${k[3]}`"></i></span>
                </div>
                <div v-for="(v, cur, i) in (Object.keys(kpis[k[0]] || {}).length ? kpis[k[0]] : { PEN: 0 })" :key="cur" :class="i === 0 ? 'dash-kpi-value kpi-money' : 'kpi-money-extra'">{{ money(v, cur) }}</div>
            </article>
        </section>

        <!-- Cuentas de origen -->
        <AppModal :show="showAccounts" title="Cuentas de la empresa" subtitle="Origen de los pagos: se elige una al pagar cada cuota." size="lg" :busy="account.processing" @close="showAccounts = false">
                <div class="row g-0 app-modal-flush">
                    <div class="col-lg-7 border-end-lg">
                        <ul class="list-plain">
                            <li v-for="a in accounts" :key="a.id" class="list-plain-item" :class="{ 'is-muted': !a.is_active }">
                                <span class="list-plain-icon"><i class="bi bi-bank"></i></span>
                                <div class="min-w-0 flex-grow-1">
                                    <div class="fw-semibold text-truncate">{{ a.alias || a.bank }} <span v-if="!a.is_active" class="status-pill tone-neutral ms-1">Inactiva</span></div>
                                    <div class="small text-body-secondary text-truncate">{{ a.bank }} · <span class="font-monospace">{{ a.account_number }}</span> · {{ a.currency }}</div>
                                </div>
                                <button type="button" class="icon-btn" title="Editar cuenta" aria-label="Editar cuenta" @click="editAccount(a)"><i class="bi bi-pencil"></i></button>
                            </li>
                            <li v-if="!accounts.length" class="empty-inline">Registra al menos una cuenta para poder pagar.</li>
                        </ul>
                    </div>
                    <form class="col-lg-5 surface-pad row g-2 m-0" novalidate @submit.prevent="saveAccount">
                        <div class="col-12 fw-semibold small">{{ account.id ? 'Editar cuenta' : 'Nueva cuenta' }}</div>
                        <div class="col-6"><input v-model="account.bank" class="form-control" :class="{ 'is-invalid': account.errors.bank }" placeholder="Banco" aria-label="Banco" maxlength="50" /><div class="invalid-feedback">{{ account.errors.bank }}</div></div>
                        <div class="col-6"><input v-model="account.alias" class="form-control" placeholder="Alias (opcional)" aria-label="Alias" maxlength="60" /></div>
                        <div class="col-8"><input v-model.trim="account.account_number" class="form-control font-monospace" :class="{ 'is-invalid': account.errors.account_number }" placeholder="N.º de cuenta" aria-label="Número de cuenta" maxlength="100" /><div class="invalid-feedback">{{ account.errors.account_number }}</div></div>
                        <div class="col-4"><select v-model="account.currency" class="form-select" aria-label="Moneda"><option v-for="(l, c) in currencies" :key="c" :value="c">{{ c }}</option></select></div>
                        <div class="col-12 d-flex justify-content-between align-items-center pt-1">
                            <div class="form-check form-switch mb-0"><input id="acc-active" v-model="account.is_active" class="form-check-input" type="checkbox" /><label class="form-check-label small" for="acc-active">Activa</label></div>
                            <button type="submit" class="btn btn-primary btn-sm" :disabled="account.processing">{{ account.id ? 'Guardar' : 'Agregar cuenta' }}</button>
                        </div>
                    </form>
                </div>
        </AppModal>

        <!-- Cuotas: pestañas, filtros y tabla en una sola superficie -->
        <section class="surface">
            <div class="surface-toolbar">
                <div class="seg-tabs" role="tablist" aria-label="Estado de las cuotas">
                    <button v-for="(label, key) in tabs" :key="key" type="button" role="tab" class="seg-tab" :class="{ 'is-active': filters.tab === key }"
                        :aria-selected="filters.tab === key" @click="go({ tab: key })">
                        {{ label }}
                        <span class="count-pill" :class="{ 'tone-rose': key === 'overdue' && counts[key] > 0 }">{{ counts[key] || 0 }}</span>
                    </button>
                </div>
                <a :href="exportUrl" class="btn btn-light border btn-sm ms-auto"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Exportar</a>
            </div>

            <div class="filter-row">
                <div class="search-field">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input v-model="search" type="search" class="form-control" placeholder="Proveedor, RUC, colaborador, N.º de orden o detalle" aria-label="Buscar cuota" @input="onSearch" />
                </div>
                <div class="range-field" role="group" aria-label="Rango de fechas">
                    <label class="visually-hidden" for="tr-from">Desde</label>
                    <input id="tr-from" v-model="from" type="date" class="form-control" title="Desde" @change="go({})" />
                    <span class="range-sep" aria-hidden="true">–</span>
                    <label class="visually-hidden" for="tr-to">Hasta</label>
                    <input id="tr-to" v-model="to" type="date" class="form-control" title="Hasta" :min="from || undefined" @change="go({})" />
                </div>
                <div class="form-check form-switch mb-0">
                    <input id="tr-payroll" v-model="payroll" type="checkbox" class="form-check-input" role="switch" @change="go({})" />
                    <label class="form-check-label small" for="tr-payroll">Mostrar planilla</label>
                </div>
            </div>

            <!-- Barra de selección: aparece solo con cuotas para autorizar -->
            <Transition name="reveal">
                <div v-if="markable.length" class="selection-bar">
                    <button type="button" class="btn btn-sm btn-link px-0" @click="marked = marked.length === markable.length ? [] : markable.map((p) => p.id)">{{ marked.length === markable.length ? 'Desmarcar todas' : 'Marcar todas' }}</button>
                    <span class="small text-body-secondary">{{ marked.length }} de {{ markable.length }} marcadas</span>
                    <button type="button" class="btn btn-sm btn-success ms-auto" :disabled="!marked.length" @click="authorizeMarked"><i class="bi bi-check2-all me-1"></i>Autorizar marcadas</button>
                </div>
            </Transition>

            <div class="table-responsive">
                <table class="table data-table table-cards align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Cliente / Proveedor · Documento relac.</th>
                            <th>Cuota</th>
                            <th>Fecha Venc · Fecha Progr.</th>
                            <th class="text-end">Monto (saldo)</th>
                            <th class="text-end"><span class="visually-hidden">Acción</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="p in installments.data" :key="p.id">
                            <tr :class="{ 'is-overdue': isOverdue(p), 'is-paid': p.status === 'paid' }">
                                <td data-label="Proveedor" class="cell-main">
                                    <div class="d-flex align-items-start gap-2">
                                        <input v-if="canAuthorizeRow(p)" v-model="marked" type="checkbox" class="form-check-input mt-1 flex-shrink-0" :value="p.id" :aria-label="`Marcar para autorizar: ${p.supplier?.name}`" />
                                        <div class="min-w-0">
                                            <div class="cell-title text-truncate">{{ p.supplier?.name || '—' }}</div>
                                            <div class="cell-sub text-truncate">
                                                <Link :href="`/requisitions/${p.requisition?.id}`" class="font-monospace">{{ p.requisition?.order || p.requisition?.code }}</Link> · {{ p.requisition?.detail }}
                                            </div>
                                            <div v-if="p.requisition?.invoice_number" class="cell-sub"><i class="bi bi-receipt me-1"></i>Doc Asoc.: <span class="font-monospace">{{ p.requisition.invoice_number }}</span></div>
                                            <div v-if="p.is_advance || p.requisition?.has_detraction" class="d-flex flex-wrap gap-1 mt-1">
                                                <span v-if="p.is_advance" class="status-pill tone-blue">Anticipo</span>
                                                <span v-if="p.requisition?.has_detraction" class="status-pill" :class="p.requisition.detraction.detraction_number ? 'tone-blue' : 'tone-amber'">
                                                    Detracción {{ p.requisition.detraction.detraction_number ? `${p.requisition.detraction.detraction_percent}% · ${money(p.requisition.detraction.detraction_amount, p.requisition.currency)}` : 'pendiente' }}
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td data-label="Cuota" class="text-nowrap">
                                    <div class="cell-title fw-medium">{{ p.installment }} de {{ p.of }}</div>
                                    <div class="cell-sub">{{ p.payment_method }}</div>
                                </td>
                                <td data-label="Fechas" class="text-nowrap">
                                    <div class="tabular">{{ p.due_date }}</div>
                                    <div class="d-flex flex-wrap gap-1 mt-1">
                                        <span v-if="p.status === 'pending' && p.due_iso" class="status-pill" :class="daysTo(p.due_iso) < 0 ? 'tone-rose' : daysTo(p.due_iso) <= 2 ? 'tone-amber' : 'tone-neutral'">
                                            {{ daysTo(p.due_iso) < 0 ? `Vencida hace ${-daysTo(p.due_iso)} d` : daysTo(p.due_iso) === 0 ? 'Vence hoy' : `Faltan ${daysTo(p.due_iso)} d` }}
                                        </span>
                                        <span v-if="p.scheduled_for" class="status-pill tone-brand"><i class="bi bi-calendar-check"></i>{{ p.scheduled_for.split('-').reverse().join('/') }}</span>
                                        <span v-if="p.authorized" class="status-pill tone-emerald"><i class="bi bi-check2-circle"></i>Autorizada</span>
                                        <span v-if="p.paid_amount > 0 && p.status === 'pending'" class="status-pill tone-blue"><i class="bi bi-arrow-repeat"></i>Saldo por amortizar</span>
                                    </div>
                                    <Link v-if="!p.requisition?.has_invoice && p.status === 'pending'" :href="`/requisitions/${p.requisition?.id}#factura`" class="cell-link-warn" title="Registra la factura o el documento (PDF) en la orden: sin él no se paga"><i class="bi bi-receipt-cutoff me-1"></i>Sin factura · registrar</Link>
                                </td>
                                <td data-label="Monto" class="text-end text-nowrap">
                                    <div class="cell-amount">{{ money(p.status === 'paid' ? p.amount : p.balance, p.requisition?.currency) }}</div>
                                    <div v-if="p.paid_amount > 0 && p.status !== 'paid'" class="cell-sub">de {{ money(p.amount, p.requisition?.currency) }}</div>
                                </td>
                                <td class="cell-actions">
                                    <div class="row-actions">
                                        <template v-if="p.status === 'pending' && can.pay && !p.authorized">
                                            <input v-model="scheduleDates[p.id]" type="date" class="form-control form-control-sm row-date" :placeholder="p.due_iso" :aria-label="`Fecha de pago cuota ${p.installment}`" />
                                            <button type="button" class="btn btn-sm btn-light border" @click="schedule(p)">{{ p.scheduled_for ? 'Reprogramar' : 'Programar' }}</button>
                                        </template>
                                        <button v-if="p.status === 'pending' && p.scheduled_for && !p.authorized && can.authorize" type="button" class="btn btn-sm btn-success" @click="authorize(p)">Autorizar</button>
                                        <button v-if="p.status === 'pending' && p.authorized && can.pay" type="button" class="btn btn-sm btn-success" :disabled="!p.requisition?.has_invoice" :title="p.requisition?.has_invoice ? '' : 'Registra la factura en el requerimiento'" @click="openPay(p)"><i class="bi bi-cash-coin me-1"></i>Pagar</button>
                                        <button v-if="p.payments.length" type="button" class="btn btn-sm btn-light border" :aria-expanded="expanded === p.id" :title="expanded === p.id ? 'Ocultar pagos' : 'Ver pagos'" @click="expanded = expanded === p.id ? null : p.id">
                                            <i class="bi" :class="expanded === p.id ? 'bi-chevron-up' : 'bi-receipt'"></i> {{ p.payments.length }}
                                        </button>

                                        <!-- Acciones secundarias en un menú: la fila muestra solo la acción que toca -->
                                        <div v-if="p.scheduled_for || (can.pay && (p.requisition?.is_direct || p.requisition?.has_detraction || p.requisition?.order || p.requisition?.is_order))" class="dropdown">
                                            <button type="button" class="icon-btn" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}' aria-expanded="false" :aria-label="`Más acciones: ${p.supplier?.name}`"><i class="bi bi-three-dots"></i></button>
                                            <ul class="dropdown-menu dropdown-menu-end">
                                                <li v-if="p.scheduled_for"><a :href="`/treasury/installments/${p.id}/op.pdf`" target="_blank" rel="noopener" class="dropdown-item"><i class="bi bi-filetype-pdf me-2"></i>Orden de pago (PDF)</a></li>
                                                <li v-if="p.requisition?.is_direct && p.status === 'pending' && p.paid_amount == 0 && can.pay"><button type="button" class="dropdown-item" @click="openEditDirect(p)"><i class="bi bi-pencil-square me-2"></i>Corregir pago directo</button></li>
                                                <li v-if="p.requisition?.has_detraction && can.pay"><button type="button" class="dropdown-item" @click="openDetraction(p)"><i class="bi bi-percent me-2"></i>Detracción</button></li>
                                                <li v-if="p.requisition?.order && can.pay"><button type="button" class="dropdown-item" @click="openFlow(p)"><i class="bi bi-diagram-3 me-2" :class="{ 'text-warning': !p.requisition.cash_flow_concept }"></i>Flujo de efectivo<span v-if="!p.requisition.cash_flow_concept" class="small text-warning-emphasis ms-1">· sin concepto</span></button></li>
                                                <li v-if="p.requisition?.is_order && p.status === 'pending' && p.paid_amount == 0 && can.pay"><button type="button" class="dropdown-item" @click="openAmount(p)"><i class="bi bi-calculator me-2"></i>Corregir monto de la orden</button></li>
                                            </ul>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="expanded === p.id" class="row-detail">
                                <td colspan="5">
                                    <table class="table table-sm mb-0 small">
                                        <thead><tr><th>Fecha</th><th>Cuenta de origen</th><th>N.º operación</th><th class="text-end">Monto</th><th>Registró</th><th></th></tr></thead>
                                        <tbody>
                                            <tr v-for="pay in p.payments" :key="pay.id">
                                                <td data-label="Fecha">{{ pay.paid_at }}</td>
                                                <td data-label="Cuenta">{{ pay.account }}</td>
                                                <td data-label="Operación" class="font-monospace">{{ pay.operation }}</td>
                                                <td data-label="Monto" class="text-end">{{ money(pay.amount, p.requisition?.currency) }}</td>
                                                <td data-label="Registró">{{ pay.registered_by }}</td>
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
                        <tr v-if="!installments.data.length" class="row-empty">
                            <td colspan="5">
                                <div class="dash-empty">
                                    <span class="dash-empty-icon"><i class="bi bi-inbox"></i></span>
                                    <p class="dash-empty-title">No hay cuotas en «{{ tabs[filters.tab] || 'esta pestaña' }}»</p>
                                    <p class="dash-empty-text">{{ search || from || to ? 'Prueba quitando la búsqueda o las fechas.' : 'Cuando haya cuotas en este estado aparecerán aquí.' }}</p>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <footer class="surface-foot">
                <span class="small text-body-secondary">{{ installments.total }} cuota(s)</span>
                <Pagination :paginator="installments" />
            </footer>
        </section>

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

        <!-- Concepto del flujo de efectivo -->
        <div v-if="flowTarget" class="modal d-block" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="flow-title" style="background: rgba(0,0,0,.45)" @click.self="flowTarget = null">
            <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
                <form class="modal-content" novalidate @submit.prevent="submitFlow">
                    <div class="modal-header">
                        <h2 id="flow-title" class="modal-title h5">Flujo de efectivo · {{ flowTarget.requisition.order }}</h2>
                        <button type="button" class="btn-close" aria-label="Cerrar" @click="flowTarget = null"></button>
                    </div>
                    <div class="modal-body">
                        <label class="form-label small fw-semibold" for="flow-concept">Concepto *</label>
                        <select id="flow-concept" v-model="flowForm.cash_flow_concept" class="form-select" :class="{ 'is-invalid': flowForm.errors.cash_flow_concept }">
                            <option value="" disabled>Elige…</option>
                            <optgroup v-for="(concepts, activity) in (direct?.cash_flow_concepts || {})" :key="activity" :label="activity">
                                <option v-for="cc in concepts" :key="cc" :value="cc">{{ cc }}</option>
                            </optgroup>
                        </select>
                        <div class="invalid-feedback">{{ flowForm.errors.cash_flow_concept }}</div>
                        <div class="form-text">Solo clasifica el pago en el flujo de efectivo; no cambia montos.</div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" @click="flowTarget = null">Cancelar</button>
                        <button type="submit" class="btn btn-primary" :disabled="flowForm.processing">Guardar</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Corregir el monto de una OC/OS sin pagos -->
        <div v-if="amountTarget" class="modal d-block" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="amt-title" style="background: rgba(0,0,0,.45)" @click.self="amountTarget = null">
            <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
                <form class="modal-content" novalidate @submit.prevent="submitAmount">
                    <div class="modal-header">
                        <h2 id="amt-title" class="modal-title h5">Corregir monto · {{ amountTarget.requisition.order }}</h2>
                        <button type="button" class="btn-close" aria-label="Cerrar" @click="amountTarget = null"></button>
                    </div>
                    <div class="modal-body row g-3">
                        <div class="col-12 small text-body-secondary">Se recalculan la detracción y el neto, se rehacen las cuotas (mismo número) y vuelven a necesitar programación y autorización. Solo si la orden no tiene pagos.</div>
                        <div class="col-sm-5">
                            <label class="form-label small fw-semibold" for="amt-amount">Nuevo total ({{ amountTarget.requisition.currency }}) *</label>
                            <input id="amt-amount" v-model="amountForm.amount" type="number" min="0.01" step="0.01" class="form-control" :class="{ 'is-invalid': amountForm.errors.amount }" />
                            <div class="invalid-feedback">{{ amountForm.errors.amount }}</div>
                        </div>
                        <div class="col-sm-7">
                            <label class="form-label small fw-semibold" for="amt-reason">Motivo *</label>
                            <input id="amt-reason" v-model.trim="amountForm.reason" maxlength="500" class="form-control" :class="{ 'is-invalid': amountForm.errors.reason }" placeholder="Ej. la factura incluye flete" />
                            <div class="invalid-feedback">{{ amountForm.errors.reason }}</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" @click="amountTarget = null">Cancelar</button>
                        <button type="submit" class="btn btn-primary" :disabled="amountForm.processing">Corregir monto</button>
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
