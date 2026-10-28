<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import Pagination from '@/Modules/Core/Components/Pagination.vue';
import { useConfirm } from '@/Modules/Core/Composables/useConfirm';
import { useTenantPicker } from '@/Modules/Core/Composables/useTenantPicker';

const props = defineProps({
    vehicles: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    types: { type: Array, required: true },
    branches: { type: Array, default: () => [] },
});

const { ask } = useConfirm();
const { ensureTenant } = useTenantPicker();
const search = ref(props.filters.search || '');
const showForm = ref(false);
const blank = () => ({ id: null, plate: '', brand: '', model: '', type: 'BUS', branch_id: '', is_active: true, soat_expires_at: '', technical_review_expires_at: '' });
const form = useForm(blank());

let timer = null;
function onSearch() {
    clearTimeout(timer);
    timer = setTimeout(() => router.get('/vehicles', { search: search.value || undefined }, { preserveState: true, replace: true }), 300);
}
function openCreate() {
    if (!ensureTenant({ message: 'El vehículo se registra en una empresa.' })) return;
    Object.assign(form, blank());
    form.clearErrors();
    showForm.value = true;
}
function openEdit(v) {
    Object.assign(form, { ...blank(), ...v, branch_id: v.branch_id ?? '', soat_expires_at: v.soat_expires_at || '', technical_review_expires_at: v.technical_review_expires_at || '' });
    form.clearErrors();
    showForm.value = true;
}
function submit() {
    const options = { preserveScroll: true, onSuccess: () => (showForm.value = false) };
    form.transform((d) => ({ ...d, branch_id: d.branch_id || null, soat_expires_at: d.soat_expires_at || null, technical_review_expires_at: d.technical_review_expires_at || null }));
    if (form.id) form.put(`/vehicles/${form.id}`, options);
    else form.post('/vehicles', options);
}
async function destroy(v) {
    const ok = await ask({ title: '¿Eliminar vehículo?', message: v.plate, confirmText: 'Sí, eliminar', variant: 'danger', icon: 'bi bi-trash3-fill' });
    if (ok) router.delete(`/vehicles/${v.id}`, { preserveScroll: true });
}
</script>

<template>
    <AppLayout title="Vehículos">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
            <div>
                <h1 class="h4 fw-bold mb-1">Vehículos</h1>
                <p class="text-body-secondary small mb-0">Flota de la empresa: se usa en requerimientos, almacén y combustible.</p>
            </div>
            <button type="button" class="btn btn-primary" @click="openCreate"><i class="bi bi-plus-lg me-1"></i>Registrar vehículo</button>
        </div>

        <div class="row g-3">
            <div :class="showForm ? 'col-xl-7' : 'col-12'">
                <div class="card shadow-sm">
                    <div class="card-body border-bottom">
                        <input v-model="search" type="search" class="form-control" placeholder="Buscar por placa, marca o modelo" aria-label="Buscar" @input="onSearch" />
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light"><tr><th>Placa</th><th>Tipo</th><th class="d-none d-md-table-cell">Marca / modelo</th><th class="d-none d-md-table-cell">Sede</th><th class="text-end">Acciones</th></tr></thead>
                            <tbody>
                                <tr v-for="v in vehicles.data" :key="v.id" :class="{ 'table-primary': showForm && form.id === v.id, 'text-body-secondary': !v.is_active }">
                                    <td><span class="font-monospace fw-semibold">{{ v.plate }}</span> <span v-if="!v.is_active" class="badge text-bg-secondary">inactivo</span></td>
                                    <td>{{ v.type }}</td>
                                    <td class="d-none d-md-table-cell">{{ [v.brand, v.model].filter(Boolean).join(' ') || '—' }}</td>
                                    <td class="d-none d-md-table-cell">{{ v.branch || '—' }}</td>
                                    <td class="text-end text-nowrap">
                                        <button type="button" class="btn-table-action btn-action-edit" :title="`Editar ${v.plate}`" @click="openEdit(v)"><i class="bi bi-pencil-fill"></i></button>
                                        <button type="button" class="btn-table-action btn-table-delete" :title="`Eliminar ${v.plate}`" @click="destroy(v)"><i class="bi bi-trash3-fill"></i></button>
                                    </td>
                                </tr>
                                <tr v-if="!vehicles.data.length"><td colspan="5" class="text-center text-body-secondary py-5"><i class="bi bi-bus-front fs-2 d-block mb-2"></i>No hay vehículos registrados.</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="card-footer bg-transparent d-flex justify-content-between align-items-center">
                        <span class="small text-body-secondary">{{ vehicles.total }} vehículo(s)</span>
                        <Pagination :paginator="vehicles" />
                    </div>
                </div>
            </div>

            <div v-if="showForm" class="col-xl-5">
                <form class="card shadow-sm" novalidate @submit.prevent="submit">
                    <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                        <h2 class="h6 fw-bold mb-0">{{ form.id ? 'Editar vehículo' : 'Registrar vehículo' }}</h2>
                        <button type="button" class="btn-close" aria-label="Cerrar" @click="showForm = false"></button>
                    </div>
                    <div class="card-body row g-3">
                        <div class="col-sm-6">
                            <label class="form-label small fw-semibold" for="v-plate">Placa *</label>
                            <input id="v-plate" v-model.trim="form.plate" class="form-control font-monospace text-uppercase" maxlength="15" :class="{ 'is-invalid': form.errors.plate }" placeholder="ABC-123" />
                            <div class="invalid-feedback">{{ form.errors.plate }}</div>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small fw-semibold" for="v-type">Tipo *</label>
                            <select id="v-type" v-model="form.type" class="form-select"><option v-for="t in types" :key="t" :value="t">{{ t }}</option></select>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small fw-semibold" for="v-brand">Marca</label>
                            <input id="v-brand" v-model="form.brand" class="form-control" maxlength="60" />
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small fw-semibold" for="v-model">Modelo</label>
                            <input id="v-model" v-model="form.model" class="form-control" maxlength="60" />
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold" for="v-branch">Sede base</label>
                            <select id="v-branch" v-model="form.branch_id" class="form-select"><option value="">Sin sede</option><option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option></select>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small fw-semibold" for="v-soat">Vence el SOAT</label>
                            <input id="v-soat" v-model="form.soat_expires_at" type="date" class="form-control" :class="{ 'is-invalid': form.errors.soat_expires_at }" />
                            <div class="invalid-feedback">{{ form.errors.soat_expires_at }}</div>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small fw-semibold" for="v-review">Vence la revisión técnica</label>
                            <input id="v-review" v-model="form.technical_review_expires_at" type="date" class="form-control" :class="{ 'is-invalid': form.errors.technical_review_expires_at }" />
                            <div class="invalid-feedback">{{ form.errors.technical_review_expires_at }}</div>
                        </div>
                        <div class="col-12 small text-body-secondary">Opcionales: con fecha, se avisan en Vencimientos.</div>
                        <div class="col-12 form-check form-switch ms-2">
                            <input id="v-active" v-model="form.is_active" class="form-check-input" type="checkbox" role="switch" />
                            <label class="form-check-label" for="v-active">Activo</label>
                        </div>
                    </div>
                    <div class="card-footer bg-transparent d-flex justify-content-end gap-2">
                        <button type="button" class="btn btn-light" @click="showForm = false">Cancelar</button>
                        <button type="submit" class="btn btn-primary" :disabled="form.processing">{{ form.id ? 'Guardar cambios' : 'Registrar' }}</button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
