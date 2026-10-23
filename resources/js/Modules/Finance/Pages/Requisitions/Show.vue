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
});

const { ask } = useConfirm();
const r = computed(() => props.requisition);
const stage = computed(() => props.stages[r.value.stage] || ['—', 'secondary']);
const today = new Date().toISOString().slice(0, 10);
const money = (v, c) => (v == null ? '—' : `${c === 'USD' ? 'US$' : c === 'EUR' ? '€' : 'S/'} ${Number(v).toLocaleString('es-PE', { minimumFractionDigits: 2 })}`);

// Pasos del flujo para la línea de tiempo
const steps = [
    ['requested', 'Solicitado'],
    ['approved', 'Aprobado'],
    ['quoting', 'Cotización'],
    ['ordered', 'Orden emitida'],
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
const quote = useForm({ supplier_id: '', number: '', amount: '', currency: 'PEN', quoted_at: today, expires_at: '', contact: '', phone: '', notes: '' });
const showQuote = ref(false);
function saveQuote() {
    quote.post(`/requisitions/${r.value.id}/quotations`, { preserveScroll: true, onSuccess: () => { quote.reset(); showQuote.value = false; } });
}
async function removeQuote(q) {
    const ok = await ask({ title: '¿Eliminar cotización?', message: `Se quitará la cotización de ${q.supplier?.name}.`, confirmText: 'Sí, eliminar', variant: 'danger', icon: 'bi bi-trash3-fill' });
    if (ok) router.delete(`/requisitions/${r.value.id}/quotations/${q.id}`, { preserveScroll: true });
}

// ---------------------------------------------------------------- orden
const order = useForm({
    quotation_id: '', type: 'purchase', payment_terms: 'CONTADO', payment_method: 'TRANSFERENCIA', payment_origin: 'TRANSFERENCIA',
    priority: r.value.priority || 'NORMAL', installments: 1, first_due_date: today, interval_days: 30, has_detraction: false,
});
const showOrder = ref(false);
function openOrder(q) {
    order.quotation_id = q.id;
    order.clearErrors();
    showOrder.value = true;
}
const chosen = computed(() => r.value.quotations.find((q) => q.id === order.quotation_id));
function emitOrder() {
    order.post(`/requisitions/${r.value.id}/order`, { preserveScroll: true, onSuccess: () => (showOrder.value = false) });
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
                        <i v-if="i < stepIndex || requisition.stage === 'ordered'" class="bi bi-check-lg"></i><span v-else>{{ i + 1 }}</span>
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
                            <thead class="table-light"><tr><th>Producto / servicio</th><th class="text-end">Cantidad</th><th>Unidad</th><th class="d-none d-md-table-cell">Justificación</th></tr></thead>
                            <tbody>
                                <tr v-for="item in requisition.items" :key="item.id">
                                    <td>{{ item.product }}</td>
                                    <td class="text-end">{{ item.quantity }}</td>
                                    <td class="small">{{ item.unit }}</td>
                                    <td class="small text-body-secondary d-none d-md-table-cell">{{ item.justification }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Orden emitida -->
                <div v-if="requisition.stage === 'ordered'" class="card border-success shadow-sm mb-3">
                    <div class="card-header bg-success-subtle fw-semibold">
                        <i class="bi bi-receipt me-1"></i>{{ requisition.purchase_order_number ? 'Orden de compra' : 'Orden de servicio' }}
                        <span class="font-monospace">{{ requisition.purchase_order_number || requisition.service_order_number }}</span>
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
                </div>
            </div>

            <!-- Cotizaciones -->
            <div class="col-lg-5">
                <div class="card shadow-sm">
                    <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                        <span class="fw-semibold">Cotizaciones <span class="badge bg-primary-subtle text-primary ms-1">{{ requisition.quotations.length }}</span></span>
                        <button v-if="can.purchase && ['approved', 'quoting'].includes(requisition.stage)" type="button" class="btn btn-sm btn-outline-primary" @click="showQuote = !showQuote">
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
