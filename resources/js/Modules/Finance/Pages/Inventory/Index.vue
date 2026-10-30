<script setup>
import { ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import AppModal from '@/Modules/Core/Components/AppModal.vue';
import { useTenantPicker } from '@/Modules/Core/Composables/useTenantPicker';
import { money } from '@/Modules/Core/format';

const props = defineProps({
    stock: { type: Array, default: () => [] },
    kardex: { type: Array, default: null },
    filters: { type: Object, default: () => ({}) },
    branches: { type: Array, default: () => [] },
    products: { type: Array, default: () => [] },
    vehicles: { type: Array, default: () => [] },
    pending: { type: Array, default: () => [] },
});

const { ensureTenant } = useTenantPicker();
const today = new Date().toLocaleDateString('en-CA') // fecha local (toISOString es UTC: después de las 19:00 en Lima daría mañana);

const qty = (v) => Number(v || 0).toLocaleString('es-PE', { maximumFractionDigits: 3 });
const search = ref(props.filters.search || '');

function go(params) {
    router.get('/inventory', { branch: props.filters.branch || undefined, search: search.value || undefined, ...params }, { preserveState: true, replace: true });
}
let timer = null;
function onSearch() {
    clearTimeout(timer);
    timer = setTimeout(() => go({ product: undefined }), 300);
}
const kardexProduct = () => props.products.find((p) => p.id === props.filters.product)?.name;
const kardexBranch = () => props.branches.find((b) => b.id === props.filters.branch)?.name;

// Entrada / salida manual
const showForm = ref(false);
const form = useForm({ type: 'out', product_id: '', branch_id: props.filters.branch || '', date: today, quantity: '', unit_cost: '', vehicle_id: '', reference: '', notes: '' });
function open(type) {
    if (!ensureTenant({ message: 'El movimiento se registra en una empresa.' })) return;
    form.reset();
    form.type = type;
    form.branch_id = props.filters.branch || '';
    form.clearErrors();
    showForm.value = true;
}
function submit() {
    form.transform((d) => ({ ...d, vehicle_id: d.vehicle_id || null, unit_cost: d.type === 'in' ? d.unit_cost : null }))
        .post('/inventory/movements', { preserveScroll: true, onSuccess: () => (showForm.value = false) });
}
</script>

<template>
    <AppLayout title="Almacén y kardex">
        <header class="page-head">
            <div class="page-head-text">
                <h1 class="page-title">Almacén y kardex</h1>
                <p class="page-subtitle">Stock por sede, kardex valorizado (costo promedio ponderado) y entradas al recibir las OC.</p>
            </div>
            <div class="page-actions">
                <button type="button" class="btn btn-light border" @click="open('out')"><i class="bi bi-box-arrow-up me-1 text-warning"></i>Salida</button>
                <button type="button" class="btn btn-primary" @click="open('in')"><i class="bi bi-box-arrow-in-down me-1"></i>Entrada</button>
            </div>
        </header>

        <!-- OC por recibir: se reciben desde el requerimiento -->
        <section v-if="pending.length" class="surface mb-3">
            <header class="surface-head">
                <div>
                    <h2 class="surface-title"><i class="bi bi-truck me-1 text-info"></i>Órdenes de compra por recibir ({{ pending.length }})</h2>
                    <p class="surface-sub">Al recibirlas entran al almacén con su costo.</p>
                </div>
            </header>
            <ul class="list-plain">
                <li v-for="p in pending" :key="p.id" class="list-plain-item">
                    <span class="list-plain-icon"><i class="bi bi-box-seam"></i></span>
                    <div class="min-w-0 flex-grow-1">
                        <div class="cell-code">{{ p.order }}</div>
                        <div class="cell-sub text-truncate">{{ p.code }} · {{ p.detail }}</div>
                    </div>
                    <Link :href="`/requisitions/${p.id}#recepcion`" class="btn btn-sm btn-light border">Recibir</Link>
                </li>
            </ul>
        </section>

        <section class="surface mb-3">
            <div class="filter-row">
                <select class="form-select w-auto" :value="filters.branch || ''" aria-label="Almacén" @change="go({ branch: $event.target.value || undefined, product: undefined })">
                    <option value="">Todos los almacenes</option>
                    <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                </select>
                <div class="search-field">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input v-model="search" type="search" class="form-control" placeholder="Buscar producto" aria-label="Buscar producto" @input="onSearch" />
                </div>
            </div>
            <div class="table-responsive">
                <table class="table data-table table-cards align-middle mb-0">
                    <thead><tr><th>Producto</th><th>Almacén</th><th class="text-end">Stock</th><th class="text-end">Costo prom.</th><th class="text-end">Valor</th><th><span class="visually-hidden">Kardex</span></th></tr></thead>
                    <tbody>
                        <tr v-for="s in stock" :key="`${s.product_id}-${s.branch_id}`" :class="{ 'is-selected': filters.product === s.product_id && filters.branch === s.branch_id }">
                            <td data-label="Producto" class="cell-main"><div class="min-w-0"><div class="cell-title text-truncate">{{ s.product }}</div><div class="cell-sub">{{ s.unit }}</div></div></td>
                            <td data-label="Almacén">{{ s.branch }}</td>
                            <td data-label="Stock" class="text-end text-nowrap">
                                <span class="cell-amount" :class="{ 'text-danger': s.quantity <= 0 }">{{ qty(s.quantity) }}</span>
                                <span v-if="s.quantity <= 0" class="status-pill tone-rose ms-1">Sin stock</span>
                            </td>
                            <td data-label="Costo prom." class="text-end tabular">{{ money(s.unit_cost) }}</td>
                            <td data-label="Valor" class="text-end"><span class="cell-amount">{{ money(s.total) }}</span></td>
                            <td class="cell-actions"><div class="row-actions"><button type="button" class="btn btn-sm btn-light border" @click="go({ product: s.product_id, branch: s.branch_id })"><i class="bi bi-list-columns-reverse me-1"></i>Kardex</button></div></td>
                        </tr>
                        <tr v-if="!stock.length" class="row-empty">
                            <td colspan="6">
                                <div class="dash-empty">
                                    <span class="dash-empty-icon"><i class="bi bi-box-seam"></i></span>
                                    <p class="dash-empty-title">Sin movimientos de almacén</p>
                                    <p class="dash-empty-text">Las entradas llegan al recibir las OC o con «Entrada».</p>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <Transition name="reveal">
            <section v-if="kardex" class="surface">
                <header class="surface-head">
                    <div>
                        <h2 class="surface-title">Kardex · {{ kardexProduct() }}</h2>
                        <p class="surface-sub">{{ kardexBranch() }} · costo promedio ponderado</p>
                    </div>
                    <button type="button" class="btn-close" aria-label="Cerrar kardex" @click="go({ product: undefined })"></button>
                </header>
                <div class="table-responsive">
                    <table class="table data-table table-cards align-middle mb-0">
                        <thead>
                            <tr><th>Fecha</th><th>Movimiento</th><th class="text-end">Cant.</th><th class="text-end">C. unit.</th><th class="text-end">Total</th><th class="text-end">Saldo</th><th class="text-end">C. prom.</th><th class="text-end">Valor</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="m in kardex" :key="m.id">
                                <td data-label="Fecha" class="text-nowrap tabular">{{ m.date }}</td>
                                <td data-label="Movimiento">
                                    <div class="d-flex flex-wrap align-items-center gap-1">
                                        <span class="status-pill" :class="m.type === 'in' ? 'tone-emerald' : 'tone-amber'">{{ m.type === 'in' ? 'Entrada' : 'Salida' }}</span>
                                        <Link v-if="m.requisition_id" :href="`/requisitions/${m.requisition_id}`" class="cell-code">{{ m.order || m.reference }}</Link>
                                        <span v-else class="small">{{ m.reference }}</span>
                                        <span v-if="m.plate" class="status-pill tone-neutral font-monospace">{{ m.plate }}</span>
                                    </div>
                                    <div v-if="m.notes" class="cell-sub">{{ m.notes }}</div>
                                </td>
                                <td data-label="Cant." class="text-end tabular">{{ qty(m.quantity) }}</td>
                                <td data-label="C. unit." class="text-end tabular">{{ money(m.unit_cost) }}</td>
                                <td data-label="Total" class="text-end tabular">{{ money(m.total_cost) }}</td>
                                <td data-label="Saldo" class="text-end"><span class="cell-amount">{{ qty(m.balance_quantity) }}</span></td>
                                <td data-label="C. prom." class="text-end tabular">{{ money(m.balance_unit_cost) }}</td>
                                <td data-label="Valor" class="text-end tabular">{{ money(m.balance_total) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </Transition>

        <AppModal :show="showForm" :title="form.type === 'in' ? 'Entrada manual' : 'Salida (consumo)'" :subtitle="form.type === 'in' ? 'Suma al stock con su costo: el costo promedio se recalcula.' : 'Descuenta del stock al costo promedio vigente.'" :busy="form.processing" @close="showForm = false">
            <form id="inv-form" class="row g-3" novalidate @submit.prevent="submit">
                <div class="col-12">
                    <label class="form-label" for="m-product">Producto *</label>
                    <select id="m-product" v-model="form.product_id" class="form-select" :class="{ 'is-invalid': form.errors.product_id }">
                        <option value="">Elige…</option>
                        <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }}</option>
                    </select>
                    <div class="invalid-feedback">{{ form.errors.product_id }}</div>
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="m-branch">Almacén *</label>
                    <select id="m-branch" v-model="form.branch_id" class="form-select" :class="{ 'is-invalid': form.errors.branch_id }">
                        <option value="">Elige…</option>
                        <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                    </select>
                    <div class="invalid-feedback">{{ form.errors.branch_id }}</div>
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="m-date">Fecha *</label>
                    <input id="m-date" v-model="form.date" type="date" :max="today" class="form-control" />
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="m-qty">Cantidad *</label>
                    <input id="m-qty" v-model="form.quantity" type="number" min="0.001" step="0.001" class="form-control" :class="{ 'is-invalid': form.errors.quantity }" />
                    <div class="invalid-feedback">{{ form.errors.quantity }}</div>
                </div>
                <div v-if="form.type === 'in'" class="col-sm-6">
                    <label class="form-label" for="m-cost">Costo unitario (S/) *</label>
                    <input id="m-cost" v-model="form.unit_cost" type="number" min="0" step="0.0001" class="form-control" :class="{ 'is-invalid': form.errors.unit_cost }" />
                    <div class="invalid-feedback">{{ form.errors.unit_cost }}</div>
                </div>
                <div v-else class="col-sm-6">
                    <label class="form-label" for="m-vehicle">Vehículo (consumo)</label>
                    <select id="m-vehicle" v-model="form.vehicle_id" class="form-select"><option value="">—</option><option v-for="v in vehicles" :key="v.id" :value="v.id">{{ v.plate }}</option></select>
                </div>
                <div class="col-12">
                    <label class="form-label" for="m-ref">Referencia / motivo</label>
                    <input id="m-ref" v-model="form.reference" class="form-control" maxlength="100" placeholder="Vale de salida, ajuste de inventario…" />
                </div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-light border" @click="showForm = false">Cancelar</button>
                <button type="submit" form="inv-form" class="btn btn-primary" :disabled="form.processing">Registrar</button>
            </template>
        </AppModal>
    </AppLayout>
</template>
