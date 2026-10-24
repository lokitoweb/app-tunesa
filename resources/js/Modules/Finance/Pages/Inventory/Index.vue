<script setup>
import { ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import { useTenantPicker } from '@/Modules/Core/Composables/useTenantPicker';

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
const money = (v) => `S/ ${Number(v || 0).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
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
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
            <div>
                <h1 class="h4 fw-bold mb-1">Almacén y kardex</h1>
                <p class="text-body-secondary small mb-0">Stock por sede, kardex valorizado (costo promedio ponderado) y entradas al recibir las OC.</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-outline-success" @click="open('in')"><i class="bi bi-box-arrow-in-down me-1"></i>Entrada</button>
                <button type="button" class="btn btn-outline-warning" @click="open('out')"><i class="bi bi-box-arrow-up me-1"></i>Salida</button>
            </div>
        </div>

        <!-- OC por recibir: se reciben desde el requerimiento -->
        <div v-if="pending.length" class="card border-info shadow-sm mb-3">
            <div class="card-header bg-transparent fw-semibold"><i class="bi bi-truck me-1 text-info"></i>Órdenes de compra por recibir ({{ pending.length }})</div>
            <ul class="list-group list-group-flush">
                <li v-for="p in pending" :key="p.id" class="list-group-item d-flex justify-content-between align-items-center gap-2">
                    <span><span class="font-monospace fw-semibold">{{ p.order }}</span> <span class="small text-body-secondary">· {{ p.code }} · {{ p.detail }}</span></span>
                    <Link :href="`/requisitions/${p.id}#recepcion`" class="btn btn-sm btn-outline-primary">Recibir</Link>
                </li>
            </ul>
        </div>

        <div class="row g-3">
            <div :class="showForm ? 'col-xl-7' : 'col-12'">
                <div class="card shadow-sm mb-3">
                    <div class="card-body d-flex flex-wrap gap-2 border-bottom">
                        <select class="form-select w-auto" :value="filters.branch || ''" aria-label="Almacén" @change="go({ branch: $event.target.value || undefined, product: undefined })">
                            <option value="">Todos los almacenes</option>
                            <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                        </select>
                        <input v-model="search" type="search" class="form-control flex-grow-1" style="max-width: 320px" placeholder="Buscar producto" aria-label="Buscar producto" @input="onSearch" />
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light"><tr><th>Producto</th><th>Almacén</th><th class="text-end">Stock</th><th class="text-end d-none d-md-table-cell">Costo prom.</th><th class="text-end">Valor</th><th></th></tr></thead>
                            <tbody>
                                <tr v-for="s in stock" :key="`${s.product_id}-${s.branch_id}`" :class="{ 'table-primary': filters.product === s.product_id && filters.branch === s.branch_id }">
                                    <td>{{ s.product }} <span class="small text-body-secondary">{{ s.unit }}</span></td>
                                    <td>{{ s.branch }}</td>
                                    <td class="text-end fw-semibold" :class="{ 'text-danger': s.quantity <= 0 }">{{ qty(s.quantity) }}</td>
                                    <td class="text-end d-none d-md-table-cell">{{ money(s.unit_cost) }}</td>
                                    <td class="text-end">{{ money(s.total) }}</td>
                                    <td class="text-end"><button type="button" class="btn btn-sm btn-link" @click="go({ product: s.product_id, branch: s.branch_id })">Kardex</button></td>
                                </tr>
                                <tr v-if="!stock.length"><td colspan="6" class="text-center text-body-secondary py-5"><i class="bi bi-box-seam fs-2 d-block mb-2"></i>Sin movimientos de almacén.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div v-if="kardex" class="card shadow-sm">
                    <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                        <span class="fw-semibold">Kardex · {{ kardexProduct() }} · {{ kardexBranch() }}</span>
                        <button type="button" class="btn-close" aria-label="Cerrar kardex" @click="go({ product: undefined })"></button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0 small">
                            <thead class="table-light">
                                <tr><th>Fecha</th><th>Movimiento</th><th class="text-end">Cant.</th><th class="text-end">C. unit.</th><th class="text-end">Total</th><th class="text-end">Saldo</th><th class="text-end">C. prom.</th><th class="text-end">Valor</th></tr>
                            </thead>
                            <tbody>
                                <tr v-for="m in kardex" :key="m.id">
                                    <td class="text-nowrap">{{ m.date }}</td>
                                    <td>
                                        <span class="badge" :class="m.type === 'in' ? 'text-bg-success' : 'text-bg-warning'">{{ m.type === 'in' ? 'Entrada' : 'Salida' }}</span>
                                        <Link v-if="m.requisition_id" :href="`/requisitions/${m.requisition_id}`" class="ms-1 font-monospace">{{ m.order || m.reference }}</Link>
                                        <span v-else class="ms-1">{{ m.reference }}</span>
                                        <span v-if="m.plate" class="ms-1 badge text-bg-light border font-monospace">{{ m.plate }}</span>
                                        <div v-if="m.notes" class="text-body-secondary">{{ m.notes }}</div>
                                    </td>
                                    <td class="text-end">{{ qty(m.quantity) }}</td>
                                    <td class="text-end">{{ money(m.unit_cost) }}</td>
                                    <td class="text-end">{{ money(m.total_cost) }}</td>
                                    <td class="text-end fw-semibold">{{ qty(m.balance_quantity) }}</td>
                                    <td class="text-end">{{ money(m.balance_unit_cost) }}</td>
                                    <td class="text-end">{{ money(m.balance_total) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div v-if="showForm" class="col-xl-5">
                <form class="card shadow-sm" novalidate @submit.prevent="submit">
                    <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                        <h2 class="h6 fw-bold mb-0">{{ form.type === 'in' ? 'Entrada manual' : 'Salida (consumo)' }}</h2>
                        <button type="button" class="btn-close" aria-label="Cerrar" @click="showForm = false"></button>
                    </div>
                    <div class="card-body row g-3">
                        <div class="col-12">
                            <label class="form-label small fw-semibold" for="m-product">Producto *</label>
                            <select id="m-product" v-model="form.product_id" class="form-select" :class="{ 'is-invalid': form.errors.product_id }">
                                <option value="">Elige…</option>
                                <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }}</option>
                            </select>
                            <div class="invalid-feedback">{{ form.errors.product_id }}</div>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small fw-semibold" for="m-branch">Almacén *</label>
                            <select id="m-branch" v-model="form.branch_id" class="form-select" :class="{ 'is-invalid': form.errors.branch_id }">
                                <option value="">Elige…</option>
                                <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                            </select>
                            <div class="invalid-feedback">{{ form.errors.branch_id }}</div>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small fw-semibold" for="m-date">Fecha *</label>
                            <input id="m-date" v-model="form.date" type="date" :max="today" class="form-control" />
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small fw-semibold" for="m-qty">Cantidad *</label>
                            <input id="m-qty" v-model="form.quantity" type="number" min="0.001" step="0.001" class="form-control" :class="{ 'is-invalid': form.errors.quantity }" />
                            <div class="invalid-feedback">{{ form.errors.quantity }}</div>
                        </div>
                        <div v-if="form.type === 'in'" class="col-sm-6">
                            <label class="form-label small fw-semibold" for="m-cost">Costo unitario (S/) *</label>
                            <input id="m-cost" v-model="form.unit_cost" type="number" min="0" step="0.0001" class="form-control" :class="{ 'is-invalid': form.errors.unit_cost }" />
                            <div class="invalid-feedback">{{ form.errors.unit_cost }}</div>
                        </div>
                        <div v-else class="col-sm-6">
                            <label class="form-label small fw-semibold" for="m-vehicle">Vehículo (consumo)</label>
                            <select id="m-vehicle" v-model="form.vehicle_id" class="form-select"><option value="">—</option><option v-for="v in vehicles" :key="v.id" :value="v.id">{{ v.plate }}</option></select>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold" for="m-ref">Referencia / motivo</label>
                            <input id="m-ref" v-model="form.reference" class="form-control" maxlength="100" placeholder="Vale de salida, ajuste de inventario…" />
                        </div>
                    </div>
                    <div class="card-footer bg-transparent d-flex justify-content-end gap-2">
                        <button type="button" class="btn btn-light" @click="showForm = false">Cancelar</button>
                        <button type="submit" class="btn btn-primary" :disabled="form.processing">Registrar</button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
