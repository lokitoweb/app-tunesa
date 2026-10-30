<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import AppModal from '@/Modules/Core/Components/AppModal.vue';
import Pagination from '@/Modules/Core/Components/Pagination.vue';
import { useConfirm } from '@/Modules/Core/Composables/useConfirm';

const props = defineProps({
    products: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    options: { type: Object, required: true },
});

const { ask } = useConfirm();
const search = ref(props.filters.search || '');
const editing = ref(null);
const form = useForm({ name: '', unit: '', classification: '' });

let timer = null;
function onSearch() {
    clearTimeout(timer);
    timer = setTimeout(() => router.get('/products', { search: search.value || undefined }, { preserveState: true, replace: true }), 300);
}
function openEdit(p) {
    editing.value = p;
    Object.assign(form, { name: p.name, unit: p.unit || 'UNIDAD (BIENES)', classification: p.classification || '' });
    form.clearErrors();
}
function submit() {
    form.transform((d) => ({ ...d, classification: d.classification || null }))
        .put(`/products/${editing.value.id}`, { preserveScroll: true, onSuccess: () => (editing.value = null) });
}
async function destroy(p) {
    const ok = await ask({ title: '¿Eliminar producto?', message: p.name, confirmText: 'Sí, eliminar', variant: 'danger', icon: 'bi bi-trash3-fill' });
    if (ok) router.delete(`/products/${p.id}`, { preserveScroll: true });
}
</script>

<template>
    <AppLayout title="Productos">
        <header class="page-head">
            <div class="page-head-text">
                <h1 class="page-title">Productos y servicios</h1>
                <p class="page-subtitle">Se crean solos al pedirlos en un requerimiento; aquí se corrige su nombre, unidad o clasificación. Uno ya usado no se elimina.</p>
            </div>
        </header>

        <section class="surface">
            <div class="filter-row">
                <div class="search-field">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input v-model="search" type="search" class="form-control" placeholder="Buscar producto o servicio" aria-label="Buscar producto" @input="onSearch" />
                </div>
            </div>
            <div class="table-responsive">
                <table class="table data-table table-cards align-middle mb-0">
                    <thead><tr><th>Producto / servicio</th><th>Unidad</th><th>Clasificación</th><th class="text-end">Pedidos</th><th class="text-end"><span class="visually-hidden">Acciones</span></th></tr></thead>
                    <tbody>
                        <tr v-for="p in products.data" :key="p.id">
                            <td data-label="Producto" class="cell-main"><div class="min-w-0"><div class="cell-title text-truncate">{{ p.name }}</div></div></td>
                            <td data-label="Unidad" class="small">{{ p.unit || '—' }}</td>
                            <td data-label="Clasificación"><span v-if="p.classification" class="status-pill tone-neutral">{{ p.classification }}</span><span v-else class="cell-sub">—</span></td>
                            <td data-label="Pedidos" class="text-end tabular">{{ p.uses }}</td>
                            <td class="cell-actions">
                                <div class="row-actions">
                                    <button type="button" class="icon-btn" :title="`Editar ${p.name}`" :aria-label="`Editar ${p.name}`" @click="openEdit(p)"><i class="bi bi-pencil"></i></button>
                                    <button v-if="!p.uses" type="button" class="icon-btn icon-btn-danger" :title="`Eliminar ${p.name}`" :aria-label="`Eliminar ${p.name}`" @click="destroy(p)"><i class="bi bi-trash3"></i></button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!products.data.length" class="row-empty">
                            <td colspan="5">
                                <div class="dash-empty">
                                    <span class="dash-empty-icon"><i class="bi bi-box"></i></span>
                                    <p class="dash-empty-title">No hay productos{{ filters.search ? ' con esa búsqueda' : '' }}</p>
                                    <p class="dash-empty-text">Se agregan solos al pedirlos en un requerimiento.</p>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <footer class="surface-foot">
                <span class="small text-body-secondary">{{ products.total }} producto(s)</span>
                <Pagination :paginator="products" />
            </footer>
        </section>

        <AppModal :show="!!editing" title="Editar producto" :subtitle="editing ? `Pedido ${editing.uses} vez/veces` : ''" :busy="form.processing" @close="editing = null">
            <form v-if="editing" id="prod-form" class="row g-3" novalidate @submit.prevent="submit">
                <div class="col-12">
                    <label class="form-label" for="pr-name">Nombre *</label>
                    <input id="pr-name" v-model="form.name" maxlength="100" class="form-control" :class="{ 'is-invalid': form.errors.name }" />
                    <div class="invalid-feedback">{{ form.errors.name }}</div>
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="pr-unit">Unidad *</label>
                    <select id="pr-unit" v-model="form.unit" class="form-select" :class="{ 'is-invalid': form.errors.unit }"><option v-if="editing.unit && !options.units.includes(editing.unit)" :value="editing.unit">{{ editing.unit }}</option><option v-for="u in options.units" :key="u" :value="u">{{ u }}</option></select>
                    <div class="invalid-feedback">{{ form.errors.unit }}</div>
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="pr-class">Clasificación</label>
                    <select id="pr-class" v-model="form.classification" class="form-select"><option value="">—</option><option v-if="editing.classification && !options.classifications.includes(editing.classification)" :value="editing.classification">{{ editing.classification }}</option><option v-for="c in options.classifications" :key="c" :value="c">{{ c }}</option></select>
                </div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-light border" @click="editing = null">Cancelar</button>
                <button type="submit" form="prod-form" class="btn btn-primary" :disabled="form.processing">Guardar</button>
            </template>
        </AppModal>
    </AppLayout>
</template>
