<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
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
        <div class="mb-4">
            <h1 class="h4 fw-bold mb-1">Productos y servicios</h1>
            <p class="text-body-secondary small mb-0">Se crean solos al pedirlos en un requerimiento; aquí se corrige su nombre, unidad o clasificación. Uno ya usado no se elimina.</p>
        </div>

        <div class="row g-3">
            <div :class="editing ? 'col-xl-7' : 'col-12'">
                <div class="card shadow-sm">
                    <div class="card-body border-bottom">
                        <input v-model="search" type="search" class="form-control" placeholder="Buscar producto o servicio" aria-label="Buscar producto" @input="onSearch" />
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light"><tr><th>Producto / servicio</th><th>Unidad</th><th class="d-none d-md-table-cell">Clasificación</th><th class="text-end">Pedidos</th><th class="text-end">Acciones</th></tr></thead>
                            <tbody>
                                <tr v-for="p in products.data" :key="p.id" :class="{ 'table-primary': editing?.id === p.id }">
                                    <td class="fw-semibold">{{ p.name }}</td>
                                    <td class="small">{{ p.unit || '—' }}</td>
                                    <td class="small d-none d-md-table-cell">{{ p.classification || '—' }}</td>
                                    <td class="text-end">{{ p.uses }}</td>
                                    <td class="text-end text-nowrap">
                                        <button type="button" class="btn-table-action btn-action-edit" :title="`Editar ${p.name}`" @click="openEdit(p)"><i class="bi bi-pencil-fill"></i></button>
                                        <button v-if="!p.uses" type="button" class="btn-table-action btn-table-delete" :title="`Eliminar ${p.name}`" @click="destroy(p)"><i class="bi bi-trash3-fill"></i></button>
                                    </td>
                                </tr>
                                <tr v-if="!products.data.length"><td colspan="5" class="text-center text-body-secondary py-5">No hay productos{{ filters.search ? ' con esa búsqueda' : '' }}.</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="card-body"><Pagination :links="products.links" /></div>
                </div>
            </div>

            <div v-if="editing" class="col-xl-5">
                <form class="card shadow-sm" novalidate @submit.prevent="submit">
                    <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                        <span class="fw-semibold">Editar producto</span>
                        <button type="button" class="btn-close" aria-label="Cerrar" @click="editing = null"></button>
                    </div>
                    <div class="card-body row g-3">
                        <div class="col-12">
                            <label class="form-label small fw-semibold" for="pr-name">Nombre *</label>
                            <input id="pr-name" v-model="form.name" maxlength="100" class="form-control" :class="{ 'is-invalid': form.errors.name }" />
                            <div class="invalid-feedback">{{ form.errors.name }}</div>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small fw-semibold" for="pr-unit">Unidad *</label>
                            <select id="pr-unit" v-model="form.unit" class="form-select" :class="{ 'is-invalid': form.errors.unit }"><option v-if="editing.unit && !options.units.includes(editing.unit)" :value="editing.unit">{{ editing.unit }}</option><option v-for="u in options.units" :key="u" :value="u">{{ u }}</option></select>
                            <div class="invalid-feedback">{{ form.errors.unit }}</div>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small fw-semibold" for="pr-class">Clasificación</label>
                            <select id="pr-class" v-model="form.classification" class="form-select"><option value="">—</option><option v-if="editing.classification && !options.classifications.includes(editing.classification)" :value="editing.classification">{{ editing.classification }}</option><option v-for="c in options.classifications" :key="c" :value="c">{{ c }}</option></select>
                        </div>
                    </div>
                    <div class="card-footer bg-transparent d-flex justify-content-end gap-2">
                        <button type="button" class="btn btn-light" @click="editing = null">Cancelar</button>
                        <button type="submit" class="btn btn-primary" :disabled="form.processing">Guardar</button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
