<script setup>
import { ref, computed } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import { useConfirm } from '@/Modules/Core/Composables/useConfirm';

const props = defineProps({
    requisition: { type: Object, required: true },
    stages: { type: Object, required: true },
    suppliers: { type: Array, default: () => [] },
    options: { type: Object, required: true },
    can: { type: Object, required: true },
    branches: { type: Array, default: () => [] },
});

const { ask } = useConfirm();
const r = computed(() => props.requisition);
const stage = computed(() => props.stages[r.value.stage] || ['—', 'secondary']);
const today = new Date().toLocaleDateString('en-CA') // fecha local (toISOString es UTC: después de las 19:00 en Lima daría mañana);
const money = (v, c) => (v == null ? '—' : `${c === 'USD' ? 'US$' : c === 'EUR' ? '€' : 'S/'} ${Number(v).toLocaleString('es-PE', { minimumFractionDigits: 2 })}`);

// Pasos del flujo para la línea de tiempo
const steps = [
    ['requested', 'Solicitado'],
    ['approved', 'Aprobado'],
    ['quoting', 'Cotización'],
    ['ordered', 'Orden emitida'],
    ['paid', 'Pagado'],
];
const stepIndex = computed(() => steps.findIndex(([k]) => k === r.value.stage));

// ---------------------------------------------------------------- revisión
const review = useForm({ decision: 'approved', notes: '' });
const showReject = ref(false);
function approve() {
    review.decision = 'approved';
    review.post(`/requisitions/${r.value.id}/review`, { preserveScroll: true });
}
function reject() {
    review.decision = 'rejected';
    review.post(`/requisitions/${r.value.id}/review`, { preserveScroll: true, onSuccess: () => (showReject.value = false) });
}

async function cancel() {
    const ok = await ask({ title: '¿Anular requerimiento?', message: `${r.value.code} quedará anulado y no se podrá cotizar.`, confirmText: 'Sí, anular', variant: 'danger', icon: 'bi bi-x-octagon' });
    if (ok) router.post(`/requisitions/${r.value.id}/cancel`, {}, { preserveScroll: true });
}

// ---------------------------------------------------------------- cotizaciones
const blankQuote = () => ({ supplier_id: '', number: '', amount: '', currency: 'PEN', quoted_at: today, expires_at: '', contact: '', phone: '', notes: '', file: null });
const quote = useForm(blankQuote());
const showQuote = ref(false);
function saveQuote() {
    // Sin form.reset(): Inertia toma como defaults lo último enviado con éxito
    quote.transform((d) => (d.file ? d : { ...d, file: undefined }))
        .post(`/requisitions/${r.value.id}/quotations`, { preserveScroll: true, forceFormData: true, onSuccess: () => { Object.assign(quote, blankQuote()); showQuote.value = false; } });
}

// ---------------------------------------------------------------- factura (sin factura no se paga)
const invoice = useForm({ invoice_number: props.requisition.invoice?.number || '', invoice_date: today, file: null });
const showInvoice = ref(false);
function saveInvoice() {
    invoice.transform((d) => (d.file ? d : { ...d, file: undefined }))
        .post(`/requisitions/${r.value.id}/invoice`, { preserveScroll: true, forceFormData: true, onSuccess: () => (showInvoice.value = false) });
}

// ---------------------------------------------------------------- recepción en almacén
const pendingItems = computed(() => r.value.items.filter((i) => i.quantity - i.received_quantity > 0.0005));
const unitCost = () => {
    const total = r.value.items.reduce((s, i) => s + Number(i.quantity), 0);
    return total ? Number((Number(r.value.amount) / total).toFixed(4)) : 0;
};
const receipt = useForm({ branch_id: '', date: today, items: [] });
const showReceive = ref(false);
function openReceive() {
    receipt.items = pendingItems.value.map((i) => ({ id: i.id, product: i.product, quantity: Number((i.quantity - i.received_quantity).toFixed(3)), unit_cost: unitCost() }));
    receipt.clearErrors();
    showReceive.value = true;
}
function saveReceive() {
    receipt.transform((d) => ({ ...d, items: d.items.map(({ id, quantity, unit_cost }) => ({ id, quantity, unit_cost })) }))
        .post(`/inventory/receive/${r.value.id}`, { preserveScroll: true, onSuccess: () => (showReceive.value = false) });
}
async function removeQuote(q) {
    const ok = await ask({ title: '¿Eliminar cotización?', message: `Se quitará la cotización de ${q.supplier?.name}.`, confirmText: 'Sí, eliminar', variant: 'danger', icon: 'bi bi-trash3-fill' });
    if (ok) router.delete(`/requisitions/${r.value.id}/quotations/${q.id}`, { preserveScroll: true });
}

// ---------------------------------------------------------------- orden
const order = useForm({
    quotation_id: '', type: 'purchase', payment_terms: 'CONTADO', payment_method: 'TRANSFERENCIA', payment_origin: 'TRANSFERENCIA',
    priority: r.value.priority || 'NORMAL', installments: 1, first_due_date: today, interval_days: 30, has_detraction: false,
    // Precios por ítem (opcionales, como en sisgere): vacíos = total de la cotización
    prices_include_igv: true,
    items: [],
});
const showOrder = ref(false);
function openOrder(q) {
    order.quotation_id = q.id;
    order.items = r.value.items.map((i) => ({ id: i.id, unit_price: i.unit_price ?? '', discount_percent: i.discount_percent || 0 }));
    order.clearErrors();
    showOrder.value = true;
}
const chosen = computed(() => r.value.quotations.find((q) => q.id === order.quotation_id));

// Mismo cálculo que OrderPricing (servidor): subtotal = cant × precio × (1 − dscto%)
const round2 = (n) => Math.round((Number(n) + Number.EPSILON) * 100) / 100;
const lineSubtotal = (qty, price, disc) => round2(Number(qty) * Number(price || 0) * (1 - Math.min(100, Math.max(0, Number(disc) || 0)) / 100));
const priced = computed(() => order.items.some((l) => l.unit_price !== '' && l.unit_price !== null));
const orderTotals = computed(() => {
    const rate = Number(r.value.igv_rate) || 0.18;
    const sum = round2(order.items.reduce((s, l) => {
        const item = r.value.items.find((i) => i.id === l.id);
        return s + lineSubtotal(item?.quantity, l.unit_price, l.discount_percent);
    }, 0));
    if (order.prices_include_igv) {
        const taxable = round2(sum / (1 + rate));
        return { taxable, igv: round2(sum - taxable), total: sum, rate };
    }
    const igv = round2(sum * rate);
    return { taxable: sum, igv, total: round2(sum + igv), rate };
});
const differsFromQuote = computed(() => priced.value && chosen.value && Math.abs(orderTotals.value.total - Number(chosen.value.amount)) > 0.009);
const itemFor = (id) => r.value.items.find((i) => i.id === id);

function emitOrder() {
    order.transform((d) => ({ ...d, items: priced.value ? d.items : [] }))
        .post(`/requisitions/${r.value.id}/order`, { preserveScroll: true, onSuccess: () => (showOrder.value = false) });
}
</script>

<template>
    <AppLayout :title="`Requerimiento ${requisition.code}`">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
            <div class="d-flex align-items-center gap-3">
                <Link href="/requisitions" class="btn btn-light" title="Volver al listado"><i class="bi bi-arrow-left"></i></Link>
                <div>
                    <h1 class="h4 fw-bold mb-0 d-flex align-items-center gap-2">
                        <span class="font-monospace">{{ requisition.code }}</span>
                        <span class="badge fs-6" :class="`bg-${stage[1]}-subtle text-${stage[1]}-emphasis`">{{ stage[0] }}</span>
                        <span v-if="requisition.is_express" class="badge fs-6 text-bg-warning" title="Gasto menor: un solo proveedor, valida la jefatura o contabilidad"><i class="bi bi-lightning-charge-fill"></i> Express</span>
                    </h1>
                    <p class="text-body-secondary small mb-0">{{ requisition.requested_at }} · {{ requisition.requested_by }}<span v-if="requisition.area"> · {{ requisition.area }}</span></p>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <Link v-if="can.edit" :href="`/requisitions/${requisition.id}/edit`" class="btn btn-outline-primary"><i class="bi bi-pencil me-1"></i>Editar</Link>
                <button v-if="requisition.is_open && (requisition.is_mine || can.approve || can.purchase)" type="button" class="btn btn-outline-danger" @click="cancel">
                    <i class="bi bi-x-octagon me-1"></i>Anular
                </button>
            </div>
        </div>

        <!-- Línea de tiempo -->
        <div v-if="!['rejected', 'cancelled'].includes(requisition.stage)" class="card shadow-sm mb-3">
            <div class="card-body d-flex flex-wrap gap-2 justify-content-between">
                <div v-for="([key, label], i) in steps" :key="key" class="d-flex align-items-center gap-2 flex-grow-1">
                    <span class="rounded-circle d-inline-flex align-items-center justify-content-center fw-bold"
                        :class="i <= stepIndex ? 'bg-success text-white' : 'bg-body-secondary text-body-secondary'" style="width: 30px; height: 30px">
                        <i v-if="i < stepIndex || requisition.stage === 'paid'" class="bi bi-check-lg"></i><span v-else>{{ i + 1 }}</span>
                    </span>
                    <span class="small" :class="i <= stepIndex ? 'fw-semibold' : 'text-body-secondary'">{{ label }}</span>
                </div>
            </div>
        </div>
        <div v-else class="alert" :class="requisition.stage === 'rejected' ? 'alert-danger' : 'alert-secondary'">
            <i class="bi bi-info-circle me-1"></i>
            {{ requisition.stage === 'rejected' ? `Rechazado por ${requisition.reviewed_by} el ${requisition.reviewed_at}` : 'Requerimiento anulado.' }}
            <span v-if="requisition.notes">— {{ requisition.notes }}</span>
        </div>

        <!-- Aprobación -->
        <div v-if="can.approve && requisition.stage === 'requested'" class="card border-warning shadow-sm mb-3">
            <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-2">
                <span><i class="bi bi-hourglass-split text-warning me-1"></i>Este requerimiento espera tu revisión.</span>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-danger" @click="showReject = !showReject">Rechazar</button>
                    <button type="button" class="btn btn-success" :disabled="review.processing" @click="approve"><i class="bi bi-check-lg me-1"></i>Aprobar</button>
                </div>
            </div>
            <div v-if="showReject" class="card-footer bg-transparent">
                <label class="form-label small fw-semibold" for="rq-reject">Motivo del rechazo *</label>
                <div class="input-group">
                    <input id="rq-reject" v-model="review.notes" class="form-control" :class="{ 'is-invalid': review.errors.notes }" maxlength="100" />
                    <button type="button" class="btn btn-danger" :disabled="review.processing" @click="reject">Confirmar rechazo</button>
                    <div class="invalid-feedback">{{ review.errors.notes }}</div>
                </div>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-lg-7">
                <div class="card shadow-sm mb-3">
                    <div class="card-header bg-transparent fw-semibold">Detalle</div>
                    <div class="card-body">
                        <p class="mb-3" style="white-space: pre-line">{{ requisition.detail }}</p>
                        <dl class="row small mb-0">
                            <dt class="col-sm-4 text-body-secondary fw-normal">Clasificación</dt><dd class="col-sm-8">{{ requisition.classification }}</dd>
                            <dt class="col-sm-4 text-body-secondary fw-normal">Prioridad</dt><dd class="col-sm-8">{{ requisition.priority }}</dd>
                            <template v-if="requisition.plate"><dt class="col-sm-4 text-body-secondary fw-normal">Placa / km</dt><dd class="col-sm-8">{{ requisition.plate }} · {{ requisition.km || '—' }}</dd></template>
                            <template v-if="requisition.site"><dt class="col-sm-4 text-body-secondary fw-normal">Sede</dt><dd class="col-sm-8">{{ requisition.site }}</dd></template>
                            <template v-if="requisition.delivery_place"><dt class="col-sm-4 text-body-secondary fw-normal">Entrega</dt><dd class="col-sm-8">{{ requisition.delivery_place }}</dd></template>
                            <template v-if="requisition.reviewed_at && requisition.stage !== 'rejected'"><dt class="col-sm-4 text-body-secondary fw-normal">Aprobado por</dt><dd class="col-sm-8">{{ requisition.reviewed_by }} · {{ requisition.reviewed_at }}</dd></template>
                        </dl>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light"><tr>
                                <th>Producto / servicio</th><th class="text-end">Cantidad</th><th>Unidad</th>
                                <template v-if="requisition.pricing"><th class="text-end">P. unitario</th><th class="text-end">Dscto.</th><th class="text-end">Importe</th></template>
                                <th v-else class="d-none d-md-table-cell">Justificación</th>
                            </tr></thead>
                            <tbody>
                                <tr v-for="item in requisition.items" :key="item.id">
                                    <td>{{ item.product }}</td>
                                    <td class="text-end">{{ item.quantity }}</td>
                                    <td class="small">{{ item.unit }}</td>
                                    <template v-if="requisition.pricing">
                                        <td class="text-end small font-monospace">{{ money(item.unit_price, requisition.currency) }}</td>
                                        <td class="text-end small">{{ item.discount_percent ? `${item.discount_percent} %` : '—' }}</td>
                                        <td class="text-end small font-monospace">{{ money(item.subtotal, requisition.currency) }}</td>
                                    </template>
                                    <td v-else class="small text-body-secondary d-none d-md-table-cell">{{ item.justification }}</td>
                                </tr>
                            </tbody>
                            <tfoot v-if="requisition.pricing" class="small">
                                <tr><td colspan="5" class="text-end text-body-secondary border-0">Valor venta</td><td class="text-end font-monospace border-0">{{ money(requisition.pricing.taxable, requisition.currency) }}</td></tr>
                                <tr><td colspan="5" class="text-end text-body-secondary border-0">IGV ({{ Math.round(requisition.pricing.rate * 10000) / 100 }} %)</td><td class="text-end font-monospace border-0">{{ money(requisition.pricing.igv, requisition.currency) }}</td></tr>
                                <tr><td colspan="5" class="text-end fw-semibold border-0">Total</td><td class="text-end fw-bold font-monospace border-0">{{ money(requisition.pricing.total, requisition.currency) }}</td></tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <!-- Orden emitida -->
                <div v-if="['ordered', 'paid'].includes(requisition.stage)" class="card border-success shadow-sm mb-3">
                    <div class="card-header bg-success-subtle fw-semibold d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <span>
                            <i class="bi bi-receipt me-1"></i>{{ requisition.purchase_order_number ? 'Orden de compra' : 'Orden de servicio' }}
                            <span class="font-monospace">{{ requisition.purchase_order_number || requisition.service_order_number }}</span>
                        </span>
                        <a :href="`/requisitions/${requisition.id}/order.pdf`" target="_blank" rel="noopener" class="btn btn-sm btn-outline-success"><i class="bi bi-filetype-pdf me-1"></i>PDF firmado</a>
                    </div>
                    <div class="card-body">
                        <dl class="row small mb-3">
                            <dt class="col-sm-4 text-body-secondary fw-normal">Proveedor</dt><dd class="col-sm-8">{{ requisition.supplier?.name }} <span class="font-monospace text-body-secondary">{{ requisition.supplier?.ruc }}</span></dd>
                            <dt class="col-sm-4 text-body-secondary fw-normal">Monto</dt><dd class="col-sm-8 fw-semibold">{{ money(requisition.amount, requisition.currency) }}</dd>
                            <dt class="col-sm-4 text-body-secondary fw-normal">Pago</dt><dd class="col-sm-8">{{ requisition.payment_terms }} · {{ requisition.payment_method }} · {{ requisition.payment_origin }}</dd>
                            <dt class="col-sm-4 text-body-secondary fw-normal">Emitida</dt><dd class="col-sm-8">{{ requisition.ordered_at }}</dd>
                        </dl>
                        <table class="table table-sm mb-0">
                            <thead class="table-light"><tr><th>Cuota</th><th>Vence</th><th class="text-end">Monto</th><th>Estado</th></tr></thead>
                            <tbody>
                                <tr v-for="p in requisition.installments_list" :key="p.installment">
                                    <td>{{ p.installment }}</td>
                                    <td>{{ p.due_date }}</td>
                                    <td class="text-end">{{ money(p.amount, requisition.currency) }}</td>
                                    <td><span class="badge" :class="p.status === 'paid' ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning-emphasis'">{{ p.status === 'paid' ? 'Pagada' : 'Pendiente' }}</span></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Traza: factura → almacén → pagos -->
                    <ul class="list-group list-group-flush small">
                        <li class="list-group-item d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <span>
                                <i class="bi bi-receipt-cutoff me-1"></i>Factura:
                                <template v-if="requisition.invoice.number">
                                    <span class="fw-semibold">{{ requisition.invoice.number }}</span> · {{ requisition.invoice.date }}
                                    <a v-if="requisition.invoice.has_file" :href="`/requisitions/${requisition.id}/invoice/file`" target="_blank" rel="noopener" class="ms-1">PDF</a>
                                </template>
                                <span v-else class="text-warning-emphasis">pendiente (sin factura no se paga)</span>
                            </span>
                            <button v-if="can.invoice" type="button" class="btn btn-sm btn-outline-primary" @click="showInvoice = !showInvoice">{{ requisition.invoice.number ? 'Corregir' : 'Registrar factura' }}</button>
                        </li>
                        <li v-if="showInvoice" class="list-group-item">
                            <form class="row g-2" novalidate @submit.prevent="saveInvoice">
                                <div class="col-sm-4"><input v-model="invoice.invoice_number" class="form-control form-control-sm" placeholder="F001-123" aria-label="N.º de factura" :class="{ 'is-invalid': invoice.errors.invoice_number }" /><div class="invalid-feedback">{{ invoice.errors.invoice_number }}</div></div>
                                <div class="col-sm-3"><input v-model="invoice.invoice_date" type="date" :max="today" class="form-control form-control-sm" aria-label="Fecha de la factura" :class="{ 'is-invalid': invoice.errors.invoice_date }" /><div class="invalid-feedback">{{ invoice.errors.invoice_date }}</div></div>
                                <div class="col-sm-5"><input type="file" accept="application/pdf" class="form-control form-control-sm" aria-label="PDF de la factura" :class="{ 'is-invalid': invoice.errors.file }" @input="invoice.file = $event.target.files[0]" /><div class="invalid-feedback">{{ invoice.errors.file }}</div></div>
                                <div class="col-12 text-end"><button class="btn btn-sm btn-primary" :disabled="invoice.processing">Guardar factura</button></div>
                            </form>
                        </li>
                        <li v-if="requisition.purchase_order_number" id="recepcion" class="list-group-item d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <span>
                                <i class="bi bi-box-seam me-1"></i>Almacén:
                                <span v-if="requisition.received_at" class="text-success fw-semibold">recibida completa ({{ requisition.received_at }})</span>
                                <span v-else-if="Object.keys(requisition.receipts).length">recepción parcial</span>
                                <span v-else class="text-body-secondary">por recibir</span>
                                <span v-for="(n, k) in requisition.receipts" :key="k" class="ms-1 badge text-bg-light border">{{ k }}</span>
                            </span>
                            <button v-if="can.receive" type="button" class="btn btn-sm btn-outline-primary" @click="openReceive">Recibir en almacén</button>
                        </li>
                        <li v-if="showReceive" class="list-group-item">
                            <form novalidate @submit.prevent="saveReceive">
                                <div class="row g-2 mb-2">
                                    <div class="col-sm-7">
                                        <select v-model="receipt.branch_id" class="form-select form-select-sm" aria-label="Almacén" :class="{ 'is-invalid': receipt.errors.branch_id }">
                                            <option value="">Almacén (sede)…</option>
                                            <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                                        </select>
                                        <div class="invalid-feedback">{{ receipt.errors.branch_id }}</div>
                                    </div>
                                    <div class="col-sm-5"><input v-model="receipt.date" type="date" :max="today" class="form-control form-control-sm" aria-label="Fecha de recepción" /></div>
                                </div>
                                <table class="table table-sm mb-2">
                                    <thead><tr><th>Producto</th><th style="width: 110px">Recibido</th><th style="width: 130px">Costo unit.</th></tr></thead>
                                    <tbody>
                                        <tr v-for="(line, i) in receipt.items" :key="line.id">
                                            <td>{{ line.product }}</td>
                                            <td><input v-model="line.quantity" type="number" min="0" step="0.001" class="form-control form-control-sm" :aria-label="`Cantidad recibida de ${line.product}`" :class="{ 'is-invalid': receipt.errors[`items.${i}.quantity`] }" /></td>
                                            <td><input v-model="line.unit_cost" type="number" min="0" step="0.0001" class="form-control form-control-sm" :aria-label="`Costo unitario de ${line.product}`" /></td>
                                        </tr>
                                    </tbody>
                                </table>
                                <div class="d-flex justify-content-end gap-2">
                                    <button type="button" class="btn btn-sm btn-light" @click="showReceive = false">Cancelar</button>
                                    <button class="btn btn-sm btn-primary" :disabled="receipt.processing">Registrar entrada</button>
                                </div>
                            </form>
                        </li>
                        <li v-for="pay in requisition.payments" :key="pay.id" class="list-group-item d-flex justify-content-between align-items-center gap-2">
                            <span>
                                <span class="badge" :class="pay.status === 'Pago cancelado' ? 'text-bg-success' : 'text-bg-info'">{{ pay.status }}</span>
                                {{ pay.paid_at }} · op. {{ pay.operation }}
                            </span>
                            <span class="text-nowrap">
                                <span class="fw-semibold">{{ money(pay.amount, requisition.currency) }}</span>
                                <a v-if="pay.has_voucher" :href="`/treasury/payments/${pay.id}/voucher`" target="_blank" rel="noopener" class="ms-1" title="Voucher"><i class="bi bi-file-earmark-arrow-down"></i></a>
                            </span>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Cotizaciones -->
            <div class="col-lg-5">
                <div class="card shadow-sm">
                    <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                        <span class="fw-semibold">Cotizaciones <span class="badge bg-primary-subtle text-primary ms-1">{{ requisition.quotations.length }}</span></span>
                        <button v-if="can.purchase && ['approved', 'quoting'].includes(requisition.stage) && !(requisition.is_express && requisition.quotations.length)" type="button" class="btn btn-sm btn-outline-primary" @click="showQuote = !showQuote">
                            <i class="bi bi-plus-lg me-1"></i>Agregar
                        </button>
                    </div>

                    <form v-if="showQuote" class="card-body border-bottom row g-2" novalidate @submit.prevent="saveQuote">
                        <div class="col-12">
                            <label class="form-label small fw-semibold" for="q-supplier">Proveedor *</label>
                            <select id="q-supplier" v-model="quote.supplier_id" class="form-select form-select-sm" :class="{ 'is-invalid': quote.errors.supplier_id }">
                                <option value="" disabled>Elige…</option>
                                <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}{{ s.ruc ? ` · ${s.ruc}` : '' }}</option>
                            </select>
                            <div class="invalid-feedback">{{ quote.errors.supplier_id }}</div>
                            <div v-if="!suppliers.length" class="small text-body-secondary mt-1">No hay proveedores activos. <Link href="/suppliers">Regístralos aquí</Link>.</div>
                        </div>
                        <div class="col-7">
                            <label class="form-label small fw-semibold" for="q-amount">Monto *</label>
                            <input id="q-amount" v-model="quote.amount" type="number" min="0" step="0.01" class="form-control form-control-sm" :class="{ 'is-invalid': quote.errors.amount }" />
                            <div class="invalid-feedback">{{ quote.errors.amount }}</div>
                        </div>
                        <div class="col-5">
                            <label class="form-label small fw-semibold" for="q-currency">Moneda</label>
                            <select id="q-currency" v-model="quote.currency" class="form-select form-select-sm">
                                <option v-for="(label, code) in options.currencies" :key="code" :value="code">{{ label }}</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold" for="q-number">N.º cotización</label>
                            <input id="q-number" v-model="quote.number" class="form-control form-control-sm" maxlength="50" />
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold" for="q-expires">Válida hasta</label>
                            <input id="q-expires" v-model="quote.expires_at" type="date" class="form-control form-control-sm" :class="{ 'is-invalid': quote.errors.expires_at }" />
                            <div class="invalid-feedback">{{ quote.errors.expires_at }}</div>
                        </div>
                        <div class="col-12">
                            <input v-model="quote.notes" class="form-control form-control-sm" placeholder="Observaciones (plazo de entrega, garantía…)" aria-label="Observaciones" maxlength="250" />
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold" for="q-file">PDF de la cotización {{ requisition.is_express ? '(opcional en Express)' : '*' }}</label>
                            <input id="q-file" type="file" accept="application/pdf" class="form-control form-control-sm" :class="{ 'is-invalid': quote.errors.file }" @input="quote.file = $event.target.files[0]" />
                            <div class="invalid-feedback">{{ quote.errors.file }}</div>
                        </div>
                        <div class="col-12 d-flex justify-content-end gap-2">
                            <button type="button" class="btn btn-sm btn-light" @click="showQuote = false">Cancelar</button>
                            <button type="submit" class="btn btn-sm btn-primary" :disabled="quote.processing">Guardar cotización</button>
                        </div>
                    </form>

                    <ul class="list-group list-group-flush">
                        <li v-for="q in requisition.quotations" :key="q.id" class="list-group-item" :class="{ 'bg-success-subtle': q.is_selected }">
                            <div class="d-flex justify-content-between gap-2">
                                <div class="min-w-0">
                                    <div class="fw-semibold text-truncate">
                                        <i v-if="q.is_selected" class="bi bi-trophy-fill text-success me-1" title="Elegida para la orden"></i>{{ q.supplier?.name }}
                                    </div>
                                    <div class="small text-body-secondary">
                                        <span v-if="q.number">N.º {{ q.number }} · </span>{{ q.quoted_at }}<span v-if="q.expires_at"> · válida hasta {{ q.expires_at }}</span>
                                        <a v-if="q.has_file" :href="`/requisitions/${requisition.id}/quotations/${q.id}/file`" target="_blank" rel="noopener" class="ms-1"><i class="bi bi-filetype-pdf"></i> PDF</a>
                                    </div>
                                    <div v-if="q.notes" class="small">{{ q.notes }}</div>
                                </div>
                                <div class="text-end text-nowrap">
                                    <div class="fw-bold">{{ money(q.amount, q.currency) }}</div>
                                    <div v-if="can.purchase && requisition.stage === 'quoting'" class="mt-1 d-flex gap-1 justify-content-end">
                                        <button type="button" class="btn btn-sm btn-success" title="Emitir orden con esta cotización" @click="openOrder(q)">
                                            <i class="bi bi-receipt me-1"></i>Emitir orden
                                        </button>
                                        <button type="button" class="btn-table-action btn-table-delete" title="Eliminar cotización" @click="removeQuote(q)"><i class="bi bi-trash3-fill"></i></button>
                                    </div>
                                </div>
                            </div>
                        </li>
                        <li v-if="!requisition.quotations.length" class="list-group-item text-center text-body-secondary small py-4">
                            {{ requisition.stage === 'requested' ? 'Se cotiza cuando el requerimiento esté aprobado.' : 'Aún no hay cotizaciones.' }}
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Emitir orden -->
        <div v-if="showOrder" class="modal d-block" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="order-title" style="background: rgba(0,0,0,.45)" @click.self="showOrder = false">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <form class="modal-content" novalidate @submit.prevent="emitOrder">
                    <div class="modal-header">
                        <h2 id="order-title" class="modal-title h5">Emitir orden · {{ chosen?.supplier?.name }} · {{ money(chosen?.amount, chosen?.currency) }}</h2>
                        <button type="button" class="btn-close" aria-label="Cerrar" @click="showOrder = false"></button>
                    </div>
                    <div class="modal-body row g-3">
                        <div class="col-12">
                            <div class="btn-group w-100" role="group" aria-label="Tipo de orden">
                                <input id="ot-p" v-model="order.type" type="radio" class="btn-check" value="purchase" />
                                <label class="btn btn-outline-primary" for="ot-p"><i class="bi bi-box-seam me-1"></i>Orden de compra (OC)</label>
                                <input id="ot-s" v-model="order.type" type="radio" class="btn-check" value="service" />
                                <label class="btn btn-outline-primary" for="ot-s"><i class="bi bi-tools me-1"></i>Orden de servicio (OS)</label>
                            </div>
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label small fw-semibold" for="o-terms">Condición</label>
                            <select id="o-terms" v-model="order.payment_terms" class="form-select"><option v-for="t in options.payment_terms" :key="t" :value="t">{{ t }}</option></select>
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label small fw-semibold" for="o-method">Medio de pago</label>
                            <select id="o-method" v-model="order.payment_method" class="form-select"><option v-for="t in options.payment_methods" :key="t" :value="t">{{ t }}</option></select>
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label small fw-semibold" for="o-origin">Origen del pago</label>
                            <select id="o-origin" v-model="order.payment_origin" class="form-select"><option v-for="t in options.payment_origins" :key="t" :value="t">{{ t }}</option></select>
                        </div>
                        <div class="col-sm-3">
                            <label class="form-label small fw-semibold" for="o-inst">Cuotas</label>
                            <input id="o-inst" v-model="order.installments" type="number" min="1" max="36" class="form-control" :class="{ 'is-invalid': order.errors.installments }" />
                            <div class="invalid-feedback">{{ order.errors.installments }}</div>
                        </div>
                        <div class="col-sm-3">
                            <label class="form-label small fw-semibold" for="o-due">Primer vencimiento</label>
                            <input id="o-due" v-model="order.first_due_date" type="date" class="form-control" :class="{ 'is-invalid': order.errors.first_due_date }" />
                            <div class="invalid-feedback">{{ order.errors.first_due_date }}</div>
                        </div>
                        <div class="col-sm-3">
                            <label class="form-label small fw-semibold" for="o-int">Cada (días)</label>
                            <input id="o-int" v-model="order.interval_days" type="number" min="1" class="form-control" :disabled="Number(order.installments) === 1" />
                        </div>
                        <div class="col-sm-3">
                            <label class="form-label small fw-semibold" for="o-prio">Prioridad</label>
                            <select id="o-prio" v-model="order.priority" class="form-select"><option v-for="t in options.priorities" :key="t" :value="t">{{ t }}</option></select>
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input id="o-detr" v-model="order.has_detraction" class="form-check-input" type="checkbox" />
                                <label class="form-check-label small" for="o-detr">Afecta a detracción</label>
                            </div>
                        </div>

                        <!-- Precios por ítem e IGV (sisgere FrmReq: Igv / S/Igv). Opcional: vacío = total de la cotización -->
                        <div class="col-12">
                            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                                <span class="small fw-semibold">Precios por ítem <span class="text-body-secondary fw-normal">(opcional)</span></span>
                                <div class="btn-group btn-group-sm" role="group" aria-label="IGV de los precios">
                                    <input id="o-igv-in" v-model="order.prices_include_igv" type="radio" class="btn-check" :value="true" />
                                    <label class="btn btn-outline-primary" for="o-igv-in">Precios con IGV</label>
                                    <input id="o-igv-out" v-model="order.prices_include_igv" type="radio" class="btn-check" :value="false" />
                                    <label class="btn btn-outline-primary" for="o-igv-out">Precios sin IGV</label>
                                </div>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead class="table-light">
                                        <tr><th>Producto / servicio</th><th class="text-end">Cant.</th><th style="width: 130px;">P. unitario</th><th style="width: 100px;">Dscto. %</th><th class="text-end">Importe</th></tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="(line, i) in order.items" :key="line.id">
                                            <td class="small">{{ itemFor(line.id)?.product }} <span class="text-body-secondary">{{ itemFor(line.id)?.unit }}</span></td>
                                            <td class="text-end small">{{ itemFor(line.id)?.quantity }}</td>
                                            <td><input v-model="line.unit_price" type="number" min="0" step="0.01" class="form-control form-control-sm text-end" :aria-label="`Precio unitario de ${itemFor(line.id)?.product}`" :class="{ 'is-invalid': order.errors[`items.${i}.unit_price`] }" /></td>
                                            <td><input v-model="line.discount_percent" type="number" min="0" max="100" step="0.01" class="form-control form-control-sm text-end" :aria-label="`Descuento de ${itemFor(line.id)?.product}`" :class="{ 'is-invalid': order.errors[`items.${i}.discount_percent`] }" /></td>
                                            <td class="text-end small font-monospace">{{ line.unit_price === '' ? '—' : money(lineSubtotal(itemFor(line.id)?.quantity, line.unit_price, line.discount_percent), chosen?.currency) }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div v-if="priced" class="d-flex justify-content-end mt-2">
                                <dl class="row small mb-0 text-end" style="min-width: 260px;">
                                    <dt class="col-6 fw-normal text-body-secondary">Valor venta</dt><dd class="col-6 mb-1 font-monospace">{{ money(orderTotals.taxable, chosen?.currency) }}</dd>
                                    <dt class="col-6 fw-normal text-body-secondary">IGV ({{ Math.round(orderTotals.rate * 10000) / 100 }} %)</dt><dd class="col-6 mb-1 font-monospace">{{ money(orderTotals.igv, chosen?.currency) }}</dd>
                                    <dt class="col-6">Total de la orden</dt><dd class="col-6 mb-0 fw-bold font-monospace">{{ money(orderTotals.total, chosen?.currency) }}</dd>
                                </dl>
                            </div>
                            <div v-if="differsFromQuote" class="alert alert-warning py-2 small mt-2 mb-0">
                                <i class="bi bi-exclamation-triangle me-1"></i>El total con precios ({{ money(orderTotals.total, chosen?.currency) }}) es distinto de la cotización ({{ money(chosen?.amount, chosen?.currency) }}). La orden y sus cuotas usarán el total con precios.
                            </div>
                            <div v-else-if="!priced" class="small text-body-secondary mt-2">Sin precios por ítem, la orden usa el total de la cotización: {{ money(chosen?.amount, chosen?.currency) }}.</div>
                        </div>
                        <div v-if="Object.keys(order.errors).length" class="col-12"><div class="alert alert-danger py-2 small mb-0">{{ Object.values(order.errors)[0] }}</div></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" @click="showOrder = false">Cancelar</button>
                        <button type="submit" class="btn btn-success" :disabled="order.processing">
                            <span v-if="order.processing" class="spinner-border spinner-border-sm me-1"></span>Emitir {{ order.type === 'purchase' ? 'OC' : 'OS' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
