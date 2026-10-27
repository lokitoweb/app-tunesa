<script setup>
import { ref, computed } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import { useConfirm } from '@/Modules/Core/Composables/useConfirm';
import { useTenantPicker } from '@/Modules/Core/Composables/useTenantPicker';

const props = defineProps({
    costs: { type: Array, default: () => [] },
    totals: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
    options: { type: Object, required: true },
    suppliers: { type: Array, default: () => [] },
    accounts: { type: Array, default: () => [] },
    today: { type: String, required: true },
});

const { ask } = useConfirm();
const { ensureTenant } = useTenantPicker();
const money = (v, c = 'PEN') => `${c === 'USD' ? 'US$' : c === 'EUR' ? '€' : 'S/'} ${Number(v || 0).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const fmt = (iso) => iso.split('-').reverse().join('/');

// Excel del mes y clasificación que se ven
const exportUrl = computed(() => `/fixed-costs?${new URLSearchParams({ month: props.filters.month, ...(props.filters.classification ? { classification: props.filters.classification } : {}), export: 1 })}`);

function go(params) {
    router.get('/fixed-costs', { month: props.filters.month, classification: props.filters.classification || undefined, ...params }, { preserveState: true, replace: true });
}

// ---------------------------------------------------------------- alta (genera cuotas)
const showForm = ref(false);
const blank = () => ({ classification: 'ARRENDAMIENTO', supplier_id: '', supplier_name: '', bank: '', account_number: '', detail: '', date: `${props.filters.month}-01`, amount: '', currency: 'PEN', installments: 12 });
const form = useForm(blank());
function openCreate() {
    if (!ensureTenant({ message: 'El costo fijo se registra en una empresa.' })) return;
    Object.assign(form, blank());
    form.clearErrors();
    showForm.value = true;
}
function submit() {
    form.post('/fixed-costs', { preserveScroll: true, onSuccess: () => (showForm.value = false) });
}

// ---------------------------------------------------------------- edición de una cuota
const editing = ref(null);
const edit = useForm({ amount: '', date: '', detail: '' });
function openEdit(c) {
    editing.value = c.id;
    Object.assign(edit, { amount: c.amount, date: c.date, detail: c.detail || '' });
    edit.clearErrors();
}
function saveEdit(c) {
    edit.put(`/fixed-costs/${c.id}`, { preserveScroll: true, onSuccess: () => (editing.value = null) });
}

// ---------------------------------------------------------------- anular / pagar
async function cancel(c) {
    const rest = c.installment < c.of;
    const ok = await ask({
        title: '¿Anular cuota?',
        message: rest ? `Cuota ${c.installment} de ${c.of}: se anularán esta y las siguientes sin pagar del mismo costo.` : `Cuota ${c.installment} de ${c.of} de ${c.supplier_name}.`,
        confirmText: rest ? 'Anular esta y las siguientes' : 'Sí, anular',
        variant: 'danger',
        icon: 'bi bi-calendar2-x',
    });
    if (ok) router.post(`/fixed-costs/${c.id}/cancel`, { rest }, { preserveScroll: true });
}

const payTarget = ref(null);
const pay = useForm({ paid_at: props.today, company_bank_account_id: '', operation_number: '' });
function openPay(c) {
    payTarget.value = c;
    Object.assign(pay, { paid_at: props.today, company_bank_account_id: props.accounts[0]?.id || '', operation_number: '' });
    pay.clearErrors();
}
function submitPay() {
    pay.post(`/fixed-costs/${payTarget.value.id}/pay`, { preserveScroll: true, onSuccess: () => (payTarget.value = null) });
}

const isOverdue = (c) => c.is_active && !c.paid_at && c.date < props.today;
</script>

<template>
    <AppLayout title="Costos fijos">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
            <div>
                <h1 class="h4 fw-bold mb-1">Costos fijos</h1>
                <p class="text-body-secondary small mb-0">Arrendamientos, leasing, seguros y préstamos con sus cuotas mensuales.</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a :href="exportUrl" class="btn btn-outline-success"><i class="bi bi-file-earmark-excel me-1"></i>Exportar a Excel</a>
                <button type="button" class="btn btn-primary" @click="openCreate"><i class="bi bi-plus-lg me-1"></i>Nuevo costo fijo</button>
            </div>
        </div>

        <div class="card shadow-sm mb-3">
            <div class="card-body d-flex flex-wrap align-items-center gap-3">
                <input type="month" class="form-control w-auto" :value="filters.month" aria-label="Mes" @change="go({ month: $event.target.value })" />
                <select class="form-select w-auto" :value="filters.classification || ''" aria-label="Clasificación" @change="go({ classification: $event.target.value || undefined })">
                    <option value="">Todas las clasificaciones</option>
                    <option v-for="c in options.classifications" :key="c" :value="c">{{ c }}</option>
                </select>
                <div class="ms-auto d-flex flex-wrap gap-4 text-end">
                    <div v-for="k in [['all', 'Total del mes', ''], ['paid', 'Pagado', 'text-success'], ['pending', 'Pendiente', 'text-warning-emphasis']]" :key="k[0]">
                        <div class="small text-body-secondary">{{ k[1] }}</div>
                        <div class="fw-bold" :class="k[2]">
                            <span v-for="(v, cur) in totals[k[0]]" :key="cur" class="d-block">{{ money(v, cur) }}</span>
                            <span v-if="!Object.keys(totals[k[0]] || {}).length">{{ money(0) }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Alta -->
        <form v-if="showForm" class="card shadow-sm mb-3" novalidate @submit.prevent="submit">
            <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                <h2 class="h6 fw-bold mb-0">Nuevo costo fijo</h2>
                <button type="button" class="btn-close" aria-label="Cerrar" @click="showForm = false"></button>
            </div>
            <div class="card-body row g-3">
                <div class="col-sm-6 col-lg-3">
                    <label class="form-label small fw-semibold" for="fc-class">Clasificación *</label>
                    <select id="fc-class" v-model="form.classification" class="form-select"><option v-for="c in options.classifications" :key="c" :value="c">{{ c }}</option></select>
                </div>
                <div class="col-sm-6 col-lg-5">
                    <label class="form-label small fw-semibold" for="fc-supplier">Proveedor</label>
                    <select id="fc-supplier" v-model="form.supplier_id" class="form-select" :class="{ 'is-invalid': form.errors.supplier_id }">
                        <option value="">Otro (escribir a quién se paga)</option>
                        <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                    </select>
                </div>
                <div v-if="!form.supplier_id" class="col-lg-4">
                    <label class="form-label small fw-semibold" for="fc-name">A quién se paga *</label>
                    <input id="fc-name" v-model="form.supplier_name" class="form-control" :class="{ 'is-invalid': form.errors.supplier_name }" maxlength="250" />
                    <div class="invalid-feedback">{{ form.errors.supplier_name }}</div>
                </div>
                <div class="col-12">
                    <label class="form-label small fw-semibold" for="fc-detail">Detalle *</label>
                    <input id="fc-detail" v-model="form.detail" class="form-control" :class="{ 'is-invalid': form.errors.detail }" placeholder="Ej.: Alquiler oficina Plaza Norte" maxlength="250" />
                    <div class="invalid-feedback">{{ form.errors.detail }}</div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <label class="form-label small fw-semibold" for="fc-date">Primera cuota *</label>
                    <input id="fc-date" v-model="form.date" type="date" class="form-control" :class="{ 'is-invalid': form.errors.date }" />
                    <div class="invalid-feedback">{{ form.errors.date }}</div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <label class="form-label small fw-semibold" for="fc-amount">Monto por cuota *</label>
                    <div class="input-group">
                        <input id="fc-amount" v-model="form.amount" type="number" min="0" step="0.01" class="form-control" :class="{ 'is-invalid': form.errors.amount }" />
                        <select v-model="form.currency" class="form-select flex-grow-0 w-auto" aria-label="Moneda"><option v-for="(l, c) in options.currencies" :key="c" :value="c">{{ c }}</option></select>
                        <div class="invalid-feedback">{{ form.errors.amount }}</div>
                    </div>
                </div>
                <div class="col-sm-4 col-lg-2">
                    <label class="form-label small fw-semibold" for="fc-inst">Cuotas *</label>
                    <input id="fc-inst" v-model="form.installments" type="number" min="1" max="60" class="form-control" :class="{ 'is-invalid': form.errors.installments }" />
                    <div class="invalid-feedback">{{ form.errors.installments }}</div>
                </div>
                <div class="col-sm-4 col-lg-2">
                    <label class="form-label small fw-semibold" for="fc-bank">Banco destino</label>
                    <input id="fc-bank" v-model="form.bank" class="form-control" maxlength="50" />
                </div>
                <div class="col-sm-4 col-lg-2">
                    <label class="form-label small fw-semibold" for="fc-acc">Cuenta destino</label>
                    <input id="fc-acc" v-model="form.account_number" class="form-control font-monospace" maxlength="100" />
                </div>
                <div v-if="Number(form.installments) > 1 && Number(form.amount) > 0" class="col-12 small text-body-secondary">
                    Se generarán {{ form.installments }} cuotas mensuales de {{ money(form.amount, form.currency) }} desde {{ form.date ? fmt(form.date) : '—' }} · total {{ money(form.amount * form.installments, form.currency) }}.
                </div>
            </div>
            <div class="card-footer bg-transparent d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-light" @click="showForm = false">Cancelar</button>
                <button type="submit" class="btn btn-primary" :disabled="form.processing">
                    <span v-if="form.processing" class="spinner-border spinner-border-sm me-1"></span>Registrar
                </button>
            </div>
        </form>

        <div class="card shadow-sm">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr><th>Vence</th><th>Costo</th><th>Cuota</th><th class="text-end">Monto</th><th>Estado</th><th class="text-end">Acciones</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in costs" :key="c.id" :class="{ 'table-danger': isOverdue(c), 'text-body-secondary': !c.is_active }">
                            <td class="text-nowrap small">
                                <input v-if="editing === c.id" v-model="edit.date" type="date" class="form-control form-control-sm" :class="{ 'is-invalid': edit.errors.date }" aria-label="Fecha" />
                                <span v-else>{{ fmt(c.date) }}</span>
                            </td>
                            <td style="max-width: 300px">
                                <div class="fw-semibold text-truncate">{{ c.supplier_name }}</div>
                                <input v-if="editing === c.id" v-model="edit.detail" class="form-control form-control-sm mt-1" :class="{ 'is-invalid': edit.errors.detail }" aria-label="Detalle" maxlength="250" />
                                <div v-else class="small text-body-secondary text-truncate">{{ c.classification }} · {{ c.detail }}</div>
                            </td>
                            <td class="small text-nowrap">{{ c.installment }} de {{ c.of }}</td>
                            <td class="text-end text-nowrap" style="min-width: 120px">
                                <input v-if="editing === c.id" v-model="edit.amount" type="number" min="0" step="0.01" class="form-control form-control-sm text-end" :class="{ 'is-invalid': edit.errors.amount }" aria-label="Monto" />
                                <span v-else class="fw-semibold" :class="{ 'text-decoration-line-through': !c.is_active }">{{ money(c.amount, c.currency) }}</span>
                            </td>
                            <td class="small">
                                <span v-if="!c.is_active" class="badge bg-secondary-subtle text-secondary">Anulada</span>
                                <template v-else-if="c.paid_at"><span class="badge bg-success-subtle text-success">Pagada {{ c.paid_at }}</span><div class="text-body-secondary font-monospace">{{ c.operation_number }}</div></template>
                                <span v-else-if="isOverdue(c)" class="badge bg-danger-subtle text-danger">Vencida</span>
                                <span v-else class="badge bg-warning-subtle text-warning-emphasis">Pendiente</span>
                            </td>
                            <td class="text-end text-nowrap">
                                <template v-if="c.is_active && !c.paid_at">
                                    <template v-if="editing === c.id">
                                        <button type="button" class="btn btn-sm btn-primary" :disabled="edit.processing" @click="saveEdit(c)">Guardar</button>
                                        <button type="button" class="btn btn-sm btn-light" @click="editing = null">Cancelar</button>
                                    </template>
                                    <template v-else>
                                        <button type="button" class="btn btn-sm btn-success" @click="openPay(c)"><i class="bi bi-cash-coin me-1"></i>Pagar</button>
                                        <button type="button" class="btn-table-action btn-action-edit" title="Ajustar cuota" @click="openEdit(c)"><i class="bi bi-pencil-fill"></i></button>
                                        <button type="button" class="btn-table-action btn-table-delete" title="Anular cuota" @click="cancel(c)"><i class="bi bi-calendar2-x"></i></button>
                                    </template>
                                </template>
                            </td>
                        </tr>
                        <tr v-if="!costs.length"><td colspan="6" class="text-center text-body-secondary py-5"><i class="bi bi-calendar2-range fs-2 d-block mb-2"></i>No hay costos fijos en este mes.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Modal pagar -->
        <div v-if="payTarget" class="modal d-block" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="fcpay-title" style="background: rgba(0,0,0,.45)" @click.self="payTarget = null">
            <div class="modal-dialog modal-dialog-centered">
                <form class="modal-content" novalidate @submit.prevent="submitPay">
                    <div class="modal-header">
                        <h2 id="fcpay-title" class="modal-title h5">Pagar · {{ payTarget.supplier_name }} · {{ money(payTarget.amount, payTarget.currency) }}</h2>
                        <button type="button" class="btn-close" aria-label="Cerrar" @click="payTarget = null"></button>
                    </div>
                    <div class="modal-body row g-3">
                        <div class="col-12 small text-body-secondary">Cuota {{ payTarget.installment }} de {{ payTarget.of }} · {{ payTarget.detail }}<span v-if="payTarget.account_number"> · destino {{ payTarget.bank }} {{ payTarget.account_number }}</span></div>
                        <div class="col-sm-5">
                            <label class="form-label small fw-semibold" for="fcpay-date">Fecha *</label>
                            <input id="fcpay-date" v-model="pay.paid_at" type="date" :max="today" class="form-control" :class="{ 'is-invalid': pay.errors.paid_at }" />
                            <div class="invalid-feedback">{{ pay.errors.paid_at }}</div>
                        </div>
                        <div class="col-sm-7">
                            <label class="form-label small fw-semibold" for="fcpay-op">N.º de operación *</label>
                            <input id="fcpay-op" v-model.trim="pay.operation_number" class="form-control font-monospace" :class="{ 'is-invalid': pay.errors.operation_number }" maxlength="50" />
                            <div class="invalid-feedback">{{ pay.errors.operation_number }}</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold" for="fcpay-acc">Cuenta de origen *</label>
                            <select id="fcpay-acc" v-model="pay.company_bank_account_id" class="form-select" :class="{ 'is-invalid': pay.errors.company_bank_account_id }">
                                <option value="" disabled>Elige…</option>
                                <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.label }}</option>
                            </select>
                            <div class="invalid-feedback">{{ pay.errors.company_bank_account_id }}</div>
                            <div v-if="!accounts.length" class="form-text text-danger">Registra las cuentas de origen en Tesorería.</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" @click="payTarget = null">Cancelar</button>
                        <button type="submit" class="btn btn-success" :disabled="pay.processing">Registrar pago</button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
