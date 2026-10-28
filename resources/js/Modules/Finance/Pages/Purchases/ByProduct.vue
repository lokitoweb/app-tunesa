<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import { money } from '@/Modules/Core/format';

const props = defineProps({
    products: { type: Array, default: () => [] },
    product: { type: Object, default: null },
    rows: { type: Array, default: () => [] },
    summary: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
});

const search = ref(props.filters.search || '');

const qty = (v) => Number(v || 0).toLocaleString('es-PE', { maximumFractionDigits: 3 });

let timer = null;
function onSearch() {
    clearTimeout(timer);
    timer = setTimeout(() => router.get('/purchases/by-product', { search: search.value || undefined, product: props.filters.product || undefined }, { preserveState: true, replace: true }), 300);
}
const pick = (p) => router.get('/purchases/by-product', { search: search.value || undefined, product: p.id }, { preserveState: true, replace: true });
</script>

<template>
    <AppLayout title="Compras por producto">
        <div class="mb-4">
            <h1 class="h4 fw-bold mb-1">Compras por producto</h1>
            <p class="text-body-secondary small mb-0">Qué se compró de cada producto, a quién y a qué costo (real del kardex si ya se recibió; si no, el precio de la OC o, sin precio, prorrateado de la orden).</p>
        </div>

        <div class="row g-3">
            <div class="col-lg-4">
                <div class="card shadow-sm">
                    <div class="card-body border-bottom">
                        <input v-model="search" type="search" class="form-control" placeholder="Buscar producto" aria-label="Buscar producto" @input="onSearch" />
                    </div>
                    <div class="list-group list-group-flush" style="max-height: 60vh; overflow: auto">
                        <button v-for="p in products" :key="p.id" type="button" class="list-group-item list-group-item-action" :class="{ active: product?.id === p.id }" @click="pick(p)">
                            <div class="fw-semibold">{{ p.name }}</div>
                            <div class="small" :class="product?.id === p.id ? '' : 'text-body-secondary'">{{ p.unit }}<span v-if="p.classification"> · {{ p.classification }}</span></div>
                        </button>
                        <div v-if="!products.length" class="list-group-item text-center text-body-secondary small py-4">Sin productos.</div>
                    </div>
                </div>
            </div>

            <div class="col-lg-8">
                <div v-if="!product" class="card shadow-sm"><div class="card-body text-center text-body-secondary py-5"><i class="bi bi-search fs-2 d-block mb-2"></i>Elige un producto para ver sus compras.</div></div>
                <div v-else class="card shadow-sm">
                    <div class="card-header bg-transparent">
                        <div class="d-flex flex-wrap justify-content-between gap-2">
                            <div class="fw-semibold">{{ product.name }} <span class="small text-body-secondary">{{ product.unit }}</span></div>
                            <a v-if="rows.length" :href="`/purchases/by-product?product=${product.id}&export=1`" class="btn btn-sm btn-outline-success"><i class="bi bi-file-earmark-excel me-1"></i>Exportar a Excel</a>
                        </div>
                        <div v-if="rows.length" class="small text-body-secondary">
                            {{ rows.length }} compra(s) · {{ qty(summary.quantity) }} {{ product.unit }} · {{ money(summary.total) }} ·
                            costo unitario {{ money(summary.min) }} – {{ money(summary.max) }}
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light"><tr><th>Fecha</th><th>Orden</th><th>Proveedor</th><th class="text-end">Cant.</th><th class="text-end">C. unit.</th><th class="text-end">Total</th></tr></thead>
                            <tbody>
                                <tr v-for="r in rows" :key="r.requisition_id">
                                    <td class="text-nowrap small">{{ r.date }}</td>
                                    <td><Link :href="`/requisitions/${r.requisition_id}`" class="font-monospace small">{{ r.order }}</Link><div v-if="r.plate" class="small text-body-secondary">Placa {{ r.plate }}</div></td>
                                    <td class="small">{{ r.supplier || '—' }}<div class="font-monospace text-body-secondary">{{ r.ruc }}</div></td>
                                    <td class="text-end">{{ qty(r.quantity) }}</td>
                                    <td class="text-end">{{ money(r.unit_cost, r.currency) }}<div class="small text-body-secondary">{{ r.source === 'kardex' ? 'kardex' : r.source === 'precio' ? 'precio de la OC' : 'estimado' }}</div></td>
                                    <td class="text-end fw-semibold">{{ money(r.total, r.currency) }}</td>
                                </tr>
                                <tr v-if="!rows.length"><td colspan="6" class="text-center text-body-secondary py-5">Aún no hay órdenes emitidas con este producto.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
