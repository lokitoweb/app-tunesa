<script setup>
import { ref, computed } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import AppModal from '@/Modules/Core/Components/AppModal.vue';
import { useConfirm } from '@/Modules/Core/Composables/useConfirm';
import { useTenantPicker } from '@/Modules/Core/Composables/useTenantPicker';
import { money } from '@/Modules/Core/format';

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

// ---------------------------------------------------------------- ajuste de una cuota
const editing = ref(null);
const edit = useForm({ amount: '', date: '', detail: '' });
function openEdit(c) {
    editing.value = c;
    Object.assign(edit, { amount: c.amount, date: c.date, detail: c.detail || '' });
    edit.clearErrors();
}
function saveEdit() {
    edit.put(`/fixed-costs/${editing.value.id}`, { preserveScroll: true, onSuccess: () => (editing.value = null) });
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

// Enviar a Tesorería: se paga por la cola normal (programar → autorizar → pagar con voucher)
const sendTarget = ref(null);
const send = useForm({ supplier_id: '', due_date: '', payment_method: '', cash_flow_concept: '', file: null });
function openSend(c) {
    sendTarget.value = c;
    Object.assign(send, { supplier_id: c.supplier_id || '', due_date: c.date, payment_method: props.options.payment_methods?.[0] || '', cash_flow_concept: '', file: null });
    send.clearErrors();
}
function submitSend() {
    send.post(`/fixed-costs/${sendTarget.value.id}/send`, { preserveScroll: true, forceFormData: true, onSuccess: () => (sendTarget.value = null) });
}

const isOverdue = (c) => c.is_active && !c.paid_at && c.date < props.today;
</script>

<template>
    <AppLayout title="Costos fijos">
        <header class="page-head">
            <div class="page-head-text">
                <h1 class="page-title">Costos fijos</h1>
                <p class="page-subtitle">Arrendamientos, leasing, seguros y préstamos con sus cuotas mensuales.</p>
            </div>
            <div class="page-actions">
                <a :href="exportUrl" class="btn btn-light border"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Exportar a Excel</a>
                <button type="button" class="btn btn-primary" @click="openCreate"><i class="bi bi-plus-lg me-1"></i>Nuevo costo fijo</button>
            </div>
        </header>

        <section class="surface mb-3">
            <div class="filter-row border-0">
                <input type="month" class="form-control w-auto" :value="filters.month" aria-label="Mes" @change="go({ month: $event.target.value })" />
                <select class="form-select w-auto" :value="filters.classification || ''" aria-label="Clasificación" @change="go({ classification: $event.target.value || undefined })">
                    <option value="">Todas las clasificaciones</option>
                    <option v-for="c in options.classifications" :key="c" :value="c">{{ c }}</option>
                </select>
            </div>
        </section>

        <!-- Totales del mes por moneda -->
        <section class="dash-kpis dash-kpis-3" aria-label="Totales del mes">
            <article v-for="k in [['all', 'Total del mes', 'brand', 'calendar2-range'], ['paid', 'Pagado', 'emerald', 'check2-circle'], ['pending', 'Pendiente', 'amber', 'hourglass-split']]" :key="k[0]" class="dash-kpi-card">
                <div class="dash-kpi-top"><span class="dash-kpi-label">{{ k[1] }}</span><span class="dash-kpi-icon" :class="`tone-${k[2]}`"><i class="bi" :class="`bi-${k[3]}`"></i></span></div>
                <div v-for="(v, cur, i) in (Object.keys(totals[k[0]] || {}).length ? totals[k[0]] : { PEN: 0 })" :key="cur" :class="i === 0 ? 'dash-kpi-value kpi-money' : 'kpi-money-extra'">{{ money(v, cur) }}</div>
            </article>
        </section>

        <section class="surface">
            <div class="table-responsive">
                <table class="table data-table table-cards align-middle mb-0">
                    <thead>
                        <tr><th>Vence</th><th>Costo</th><th>Cuota</th><th class="text-end">Monto</th><th>Estado</th><th class="text-end"><span class="visually-hidden">Acciones</span></th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in costs" :key="c.id" :class="{ 'is-overdue': isOverdue(c), 'is-muted': !c.is_active }">
                            <td data-label="Vence" class="text-nowrap tabular">{{ fmt(c.date) }}</td>
                            <td data-label="Costo" class="cell-main">
                                <div class="min-w-0">
                                    <div class="cell-title text-truncate">{{ c.supplier_name }}</div>
                                    <div class="cell-sub text-truncate">{{ c.classification }} · {{ c.detail }}</div>
                                </div>
                            </td>
                            <td data-label="Cuota" class="text-nowrap">{{ c.installment }} de {{ c.of }}</td>
                            <td data-label="Monto" class="text-end text-nowrap"><span class="cell-amount" :class="{ 'text-decoration-line-through': !c.is_active }">{{ money(c.amount, c.currency) }}</span></td>
                            <td data-label="Estado">
                                <span v-if="!c.is_active" class="status-pill tone-neutral">Anulada</span>
                                <template v-else-if="c.paid_at"><span class="status-pill tone-emerald"><i class="bi bi-check2-circle"></i>Pagada {{ c.paid_at }}</span><div class="cell-sub font-monospace mt-1">{{ c.operation_number }}</div></template>
                                <template v-else-if="c.payment"><span class="status-pill tone-blue"><i class="bi bi-bank"></i>En Tesorería</span><div class="cell-sub font-monospace mt-1">{{ c.payment.code }}</div></template>
                                <span v-else-if="isOverdue(c)" class="status-pill tone-rose">Vencida</span>
                                <span v-else class="status-pill tone-amber">Pendiente</span>
                            </td>
                            <td class="cell-actions">
                                <div class="row-actions">
                                    <a v-if="c.payment && !c.paid_at" :href="`/treasury?search=${encodeURIComponent(c.payment.code)}`" class="btn btn-sm btn-light border"><i class="bi bi-bank me-1"></i>Ver en Tesorería</a>
                                    <template v-else-if="c.is_active && !c.paid_at">
                                        <button type="button" class="btn btn-sm btn-success" @click="openSend(c)"><i class="bi bi-send me-1"></i>Enviar a Tesorería</button>
                                        <button type="button" class="icon-btn" title="Ajustar cuota" aria-label="Ajustar cuota" @click="openEdit(c)"><i class="bi bi-pencil"></i></button>
                                        <button type="button" class="icon-btn icon-btn-danger" title="Anular cuota" aria-label="Anular cuota" @click="cancel(c)"><i class="bi bi-calendar2-x"></i></button>
                                    </template>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!costs.length" class="row-empty">
                            <td colspan="6">
                                <div class="dash-empty">
                                    <span class="dash-empty-icon"><i class="bi bi-calendar2-range"></i></span>
                                    <p class="dash-empty-title">No hay costos fijos en este mes</p>
                                    <p class="dash-empty-text">Registra uno con «Nuevo costo fijo»: se generan sus cuotas mes a mes.</p>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Nuevo costo fijo -->
        <AppModal :show="showForm" title="Nuevo costo fijo" subtitle="Se generan las cuotas mensuales desde la primera." size="lg" :busy="form.processing" @close="showForm = false">
            <form id="fc-form" class="row g-3" novalidate @submit.prevent="submit">
                <div class="col-sm-6">
                    <label class="form-label" for="fc-class">Clasificación *</label>
                    <select id="fc-class" v-model="form.classification" class="form-select"><option v-for="c in options.classifications" :key="c" :value="c">{{ c }}</option></select>
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="fc-supplier">Proveedor</label>
                    <select id="fc-supplier" v-model="form.supplier_id" class="form-select" :class="{ 'is-invalid': form.errors.supplier_id }">
                        <option value="">Otro (escribir a quién se paga)</option>
                        <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                    </select>
                </div>
                <div v-if="!form.supplier_id" class="col-12">
                    <label class="form-label" for="fc-name">A quién se paga *</label>
                    <input id="fc-name" v-model="form.supplier_name" class="form-control" :class="{ 'is-invalid': form.errors.supplier_name }" maxlength="250" />
                    <div class="invalid-feedback">{{ form.errors.supplier_name }}</div>
                </div>
                <div class="col-12">
                    <label class="form-label" for="fc-detail">Detalle *</label>
                    <input id="fc-detail" v-model="form.detail" class="form-control" :class="{ 'is-invalid': form.errors.detail }" placeholder="Ej.: Alquiler oficina Plaza Norte" maxlength="250" />
                    <div class="invalid-feedback">{{ form.errors.detail }}</div>
                </div>
                <div class="col-sm-4">
                    <label class="form-label" for="fc-date">Primera cuota *</label>
                    <input id="fc-date" v-model="form.date" type="date" class="form-control" :class="{ 'is-invalid': form.errors.date }" />
                    <div class="invalid-feedback">{{ form.errors.date }}</div>
                </div>
                <div class="col-sm-5">
                    <label class="form-label" for="fc-amount">Monto por cuota *</label>
                    <div class="input-group has-validation">
                        <input id="fc-amount" v-model="form.amount" type="number" min="0" step="0.01" class="form-control" :class="{ 'is-invalid': form.errors.amount }" />
                        <select v-model="form.currency" class="form-select flex-grow-0 w-auto" aria-label="Moneda"><option v-for="(l, c) in options.currencies" :key="c" :value="c">{{ c }}</option></select>
                        <div class="invalid-feedback">{{ form.errors.amount }}</div>
                    </div>
                </div>
                <div class="col-sm-3">
                    <label class="form-label" for="fc-inst">Cuotas *</label>
                    <input id="fc-inst" v-model="form.installments" type="number" min="1" max="60" class="form-control" :class="{ 'is-invalid': form.errors.installments }" />
                    <div class="invalid-feedback">{{ form.errors.installments }}</div>
                </div>
                <div class="col-sm-5">
                    <label class="form-label" for="fc-bank">Banco destino</label>
                    <input id="fc-bank" v-model="form.bank" class="form-control" maxlength="50" />
                </div>
                <div class="col-sm-7">
                    <label class="form-label" for="fc-acc">Cuenta destino</label>
                    <input id="fc-acc" v-model="form.account_number" class="form-control font-monospace" maxlength="100" />
                </div>
                <div v-if="Number(form.installments) > 1 && Number(form.amount) > 0" class="col-12">
                    <div class="dash-notice mb-0">
                        <i class="bi bi-calendar2-range"></i>
                        <span>Se generarán {{ form.installments }} cuotas mensuales de {{ money(form.amount, form.currency) }} desde {{ form.date ? fmt(form.date) : '—' }} · total {{ money(form.amount * form.installments, form.currency) }}.</span>
                    </div>
                </div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-light border" @click="showForm = false">Cancelar</button>
                <button type="submit" form="fc-form" class="btn btn-primary" :disabled="form.processing">
                    <span v-if="form.processing" class="spinner-border spinner-border-sm me-1"></span>Registrar
                </button>
            </template>
        </AppModal>

        <!-- Ajustar una cuota -->
        <AppModal :show="!!editing" title="Ajustar cuota" :subtitle="editing ? `${editing.supplier_name} · cuota ${editing.installment} de ${editing.of}` : ''" :busy="edit.processing" @close="editing = null">
            <form id="fc-edit-form" class="row g-3" novalidate @submit.prevent="saveEdit">
                <div class="col-sm-6">
                    <label class="form-label" for="fce-date">Vence *</label>
                    <input id="fce-date" v-model="edit.date" type="date" class="form-control" :class="{ 'is-invalid': edit.errors.date }" />
                    <div class="invalid-feedback">{{ edit.errors.date }}</div>
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="fce-amount">Monto ({{ editing?.currency }}) *</label>
                    <input id="fce-amount" v-model="edit.amount" type="number" min="0" step="0.01" class="form-control text-end" :class="{ 'is-invalid': edit.errors.amount }" />
                    <div class="invalid-feedback">{{ edit.errors.amount }}</div>
                </div>
                <div class="col-12">
                    <label class="form-label" for="fce-detail">Detalle</label>
                    <input id="fce-detail" v-model="edit.detail" class="form-control" :class="{ 'is-invalid': edit.errors.detail }" maxlength="250" />
                    <div class="invalid-feedback">{{ edit.errors.detail }}</div>
                </div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-light border" @click="editing = null">Cancelar</button>
                <button type="submit" form="fc-edit-form" class="btn btn-primary" :disabled="edit.processing">Guardar</button>
            </template>
        </AppModal>

        <!-- Enviar a Tesorería -->
        <AppModal :show="!!sendTarget" :title="sendTarget ? `Enviar a Tesorería · ${money(sendTarget.amount, sendTarget.currency)}` : ''" :busy="send.processing" @close="sendTarget = null">
            <template v-if="sendTarget" #subtitle>
                Cuota {{ sendTarget.installment }} de {{ sendTarget.of }} · {{ sendTarget.detail }}<span v-if="sendTarget.account_number"> · destino {{ sendTarget.bank }} {{ sendTarget.account_number }}</span>.
                Se crea un pago directo con su orden de pago; en Tesorería se programa, lo autoriza la persona designada y otra lo paga con el voucher.
            </template>
            <form v-if="sendTarget" id="fc-send-form" class="row g-3" novalidate @submit.prevent="submitSend">
                <div class="col-12">
                    <label class="form-label" for="fcsend-sup">Proveedor *</label>
                    <select id="fcsend-sup" v-model="send.supplier_id" class="form-select" :class="{ 'is-invalid': send.errors.supplier_id }">
                        <option value="" disabled>Elige…</option>
                        <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                    </select>
                    <div class="invalid-feedback">{{ send.errors.supplier_id }}</div>
                    <div v-if="!sendTarget.supplier_id" class="form-text">Se registró como «{{ sendTarget.supplier_name }}»: elige el proveedor (o créalo en Proveedores).</div>
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="fcsend-due">Vencimiento *</label>
                    <input id="fcsend-due" v-model="send.due_date" type="date" class="form-control" :class="{ 'is-invalid': send.errors.due_date }" />
                    <div class="invalid-feedback">{{ send.errors.due_date }}</div>
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="fcsend-method">Forma de pago *</label>
                    <select id="fcsend-method" v-model="send.payment_method" class="form-select" :class="{ 'is-invalid': send.errors.payment_method }">
                        <option v-for="m in options.payment_methods" :key="m" :value="m">{{ m }}</option>
                    </select>
                    <div class="invalid-feedback">{{ send.errors.payment_method }}</div>
                </div>
                <div class="col-12">
                    <label class="form-label" for="fcsend-flow">Flujo de efectivo</label>
                    <select id="fcsend-flow" v-model="send.cash_flow_concept" class="form-select" :class="{ 'is-invalid': send.errors.cash_flow_concept }">
                        <option value="">Según la clasificación (automático)</option>
                        <optgroup v-for="(concepts, activity) in options.cash_flow_concepts" :key="activity" :label="activity">
                            <option v-for="cc in concepts" :key="cc" :value="cc">{{ cc }}</option>
                        </optgroup>
                    </select>
                    <div class="invalid-feedback">{{ send.errors.cash_flow_concept }}</div>
                </div>
                <div class="col-12">
                    <label class="form-label" for="fcsend-file">Recibo o factura (PDF)</label>
                    <input id="fcsend-file" type="file" accept="application/pdf" class="form-control" :class="{ 'is-invalid': send.errors.file }" @change="send.file = $event.target.files[0] || null" />
                    <div class="invalid-feedback">{{ send.errors.file }}</div>
                    <div class="form-text">Puede adjuntarse después en Tesorería; sin él no se puede pagar.</div>
                </div>
                <div v-if="send.errors.fixed_cost" class="col-12"><div class="alert alert-warning py-2 small mb-0">{{ send.errors.fixed_cost }}</div></div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-light border" @click="sendTarget = null">Cancelar</button>
                <button type="submit" form="fc-send-form" class="btn btn-success" :disabled="send.processing"><i class="bi bi-send me-1"></i>Enviar a Tesorería</button>
            </template>
        </AppModal>
    </AppLayout>
</template>
