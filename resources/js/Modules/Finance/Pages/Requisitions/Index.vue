<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import Pagination from '@/Modules/Core/Components/Pagination.vue';
import { useTenantPicker } from '@/Modules/Core/Composables/useTenantPicker';

const props = defineProps({
    requisitions: { type: Object, required: true },
    stages: { type: Object, required: true },
    totals: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
    can_create: { type: Boolean, default: false },
});

const { ensureTenant } = useTenantPicker();
const search = ref(props.filters.search || '');

let timer = null;
function go(params) {
    router.get('/requisitions', { search: search.value || undefined, stage: props.filters.stage || undefined, ...params }, { preserveState: true, replace: true });
}
function onSearch() {
    clearTimeout(timer);
    timer = setTimeout(() => go({}), 300);
}

function create() {
    if (ensureTenant({ message: 'El requerimiento se registra en una empresa.' })) router.get('/requisitions/create');
}

const money = (v, c) => (v == null ? '—' : `${c === 'USD' ? 'US$' : c === 'EUR' ? '€' : 'S/'} ${Number(v).toLocaleString('es-PE', { minimumFractionDigits: 2 })}`);
</script>

<template>
    <AppLayout title="Requerimientos y compras">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
            <div>
                <h1 class="h4 fw-bold mb-1">Requerimientos y compras</h1>
                <p class="text-body-secondary small mb-0">Solicitud, aprobación, cotización y orden de compra o servicio.</p>
            </div>
            <button v-if="can_create" type="button" class="btn btn-primary" @click="create">
                <i class="bi bi-plus-lg me-1"></i>Nuevo requerimiento
            </button>
        </div>

        <div class="d-flex flex-wrap gap-2 mb-3" role="tablist" aria-label="Etapas">
            <button type="button" class="btn btn-sm" :class="!filters.stage ? 'btn-primary' : 'btn-outline-secondary'" @click="go({ stage: undefined })">
                Todos <span class="badge bg-light text-dark ms-1">{{ Object.values(totals).reduce((a, b) => a + b, 0) }}</span>
            </button>
            <button
                v-for="(s, key) in stages"
                :key="key"
                type="button"
                class="btn btn-sm"
                :class="filters.stage === key ? `btn-${s[1]}` : 'btn-outline-secondary'"
                @click="go({ stage: key })"
            >
                {{ s[0] }} <span class="badge bg-light text-dark ms-1">{{ totals[key] || 0 }}</span>
            </button>
        </div>

        <div class="card shadow-sm">
            <div class="card-body border-bottom">
                <div class="input-group" style="max-width: 460px">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input v-model="search" type="search" class="form-control" placeholder="Buscar por N.º, detalle, área, solicitante u orden" aria-label="Buscar requerimiento" @input="onSearch" />
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>N.º</th>
                            <th>Detalle</th>
                            <th class="d-none d-md-table-cell">Solicita</th>
                            <th class="d-none d-lg-table-cell">Proveedor / Orden</th>
                            <th class="text-end d-none d-lg-table-cell">Monto</th>
                            <th>Etapa</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in requisitions.data" :key="r.id" role="button" @click="router.get(`/requisitions/${r.id}`)">
                            <td class="text-nowrap">
                                <Link :href="`/requisitions/${r.id}`" class="fw-semibold font-monospace text-decoration-none" @click.stop>{{ r.code }}</Link>
                                <div class="small text-body-secondary">{{ r.requested_at }}</div>
                            </td>
                            <td style="max-width: 320px">
                                <div class="text-truncate">{{ r.detail }}</div>
                                <div class="small text-body-secondary">{{ r.classification }} · {{ r.items_count }} ítem(s)</div>
                            </td>
                            <td class="d-none d-md-table-cell small">
                                <div>{{ r.requested_by }}</div>
                                <div class="text-body-secondary">{{ r.area }}</div>
                            </td>
                            <td class="d-none d-lg-table-cell small">
                                <div>{{ r.supplier || '—' }}</div>
                                <div class="font-monospace text-body-secondary">{{ r.order_number }}</div>
                            </td>
                            <td class="text-end d-none d-lg-table-cell small text-nowrap">{{ r.amount ? money(r.amount, r.currency) : '—' }}</td>
                            <td>
                                <span class="badge" :class="`bg-${stages[r.stage]?.[1]}-subtle text-${stages[r.stage]?.[1]}-emphasis`">{{ stages[r.stage]?.[0] }}</span>
                            </td>
                        </tr>
                        <tr v-if="!requisitions.data.length">
                            <td colspan="6" class="text-center text-body-secondary py-5">
                                <i class="bi bi-cart3 fs-2 d-block mb-2"></i>
                                {{ filters.search || filters.stage ? 'Ningún requerimiento coincide con el filtro.' : 'Aún no hay requerimientos.' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="card-footer bg-transparent d-flex justify-content-between align-items-center">
                <span class="small text-body-secondary">{{ requisitions.total }} requerimiento(s)</span>
                <Pagination :paginator="requisitions" />
            </div>
        </div>
    </AppLayout>
</template>
