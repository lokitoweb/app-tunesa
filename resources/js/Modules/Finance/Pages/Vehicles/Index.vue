<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import AppModal from '@/Modules/Core/Components/AppModal.vue';
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

// SOAT y revisión técnica en la lista: vencido en rojo, por vencer (30 días) en ámbar
const todayIso = new Date().toLocaleDateString('en-CA');
const soon = new Date(Date.now() + 30 * 86400000).toLocaleDateString('en-CA');
const expiryTone = (iso) => (!iso ? 'tone-neutral' : iso < todayIso ? 'tone-rose' : iso <= soon ? 'tone-amber' : 'tone-emerald');
const fmtDate = (iso) => (iso ? iso.split('-').reverse().join('/') : 'Sin fecha');
</script>

<template>
    <AppLayout title="Vehículos">
        <header class="page-head">
            <div class="page-head-text">
                <h1 class="page-title">Vehículos</h1>
                <p class="page-subtitle">Flota de la empresa: se usa en requerimientos, almacén y combustible.</p>
            </div>
            <div class="page-actions">
                <button type="button" class="btn btn-primary" @click="openCreate"><i class="bi bi-plus-lg me-1"></i>Registrar vehículo</button>
            </div>
        </header>

        <section class="surface">
            <div class="filter-row">
                <div class="search-field">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input v-model="search" type="search" class="form-control" placeholder="Buscar por placa, marca o modelo" aria-label="Buscar" @input="onSearch" />
                </div>
            </div>
            <div class="table-responsive">
                <table class="table data-table table-cards align-middle mb-0">
                    <thead><tr><th>Placa</th><th>Tipo</th><th>Marca / modelo</th><th>Sede</th><th>SOAT</th><th>Revisión técnica</th><th class="text-end"><span class="visually-hidden">Acciones</span></th></tr></thead>
                    <tbody>
                        <tr v-for="v in vehicles.data" :key="v.id" :class="{ 'is-muted': !v.is_active }">
                            <td data-label="Placa" class="cell-main">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="list-plain-icon"><i class="bi bi-bus-front"></i></span>
                                    <div class="min-w-0">
                                        <div class="cell-title font-monospace">{{ v.plate }}</div>
                                        <span v-if="!v.is_active" class="status-pill tone-neutral">Inactivo</span>
                                    </div>
                                </div>
                            </td>
                            <td data-label="Tipo">{{ v.type }}</td>
                            <td data-label="Marca / modelo">{{ [v.brand, v.model].filter(Boolean).join(' ') || '—' }}</td>
                            <td data-label="Sede">{{ v.branch || '—' }}</td>
                            <td data-label="SOAT"><span class="status-pill" :class="expiryTone(v.soat_expires_at)">{{ fmtDate(v.soat_expires_at) }}</span></td>
                            <td data-label="Revisión"><span class="status-pill" :class="expiryTone(v.technical_review_expires_at)">{{ fmtDate(v.technical_review_expires_at) }}</span></td>
                            <td class="cell-actions">
                                <div class="row-actions">
                                    <button type="button" class="icon-btn" :title="`Editar ${v.plate}`" :aria-label="`Editar ${v.plate}`" @click="openEdit(v)"><i class="bi bi-pencil"></i></button>
                                    <button type="button" class="icon-btn icon-btn-danger" :title="`Eliminar ${v.plate}`" :aria-label="`Eliminar ${v.plate}`" @click="destroy(v)"><i class="bi bi-trash3"></i></button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!vehicles.data.length" class="row-empty">
                            <td colspan="7">
                                <div class="dash-empty">
                                    <span class="dash-empty-icon"><i class="bi bi-bus-front"></i></span>
                                    <p class="dash-empty-title">{{ filters.search ? 'Ningún vehículo coincide con la búsqueda' : 'No hay vehículos registrados' }}</p>
                                    <p class="dash-empty-text">Registra la flota para usarla en requerimientos, almacén y combustible.</p>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <footer class="surface-foot">
                <span class="small text-body-secondary">{{ vehicles.total }} vehículo(s)</span>
                <Pagination :paginator="vehicles" />
            </footer>
        </section>

        <AppModal :show="showForm" :title="form.id ? 'Editar vehículo' : 'Registrar vehículo'" subtitle="SOAT y revisión técnica son opcionales: con fecha, se avisan en Vencimientos." :busy="form.processing" @close="showForm = false">
            <form id="veh-form" class="row g-3" novalidate @submit.prevent="submit">
                <div class="col-sm-6">
                    <label class="form-label" for="v-plate">Placa *</label>
                    <input id="v-plate" v-model.trim="form.plate" class="form-control font-monospace text-uppercase" maxlength="15" :class="{ 'is-invalid': form.errors.plate }" placeholder="ABC-123" />
                    <div class="invalid-feedback">{{ form.errors.plate }}</div>
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="v-type">Tipo *</label>
                    <select id="v-type" v-model="form.type" class="form-select"><option v-for="t in types" :key="t" :value="t">{{ t }}</option></select>
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="v-brand">Marca</label>
                    <input id="v-brand" v-model="form.brand" class="form-control" maxlength="60" />
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="v-model">Modelo</label>
                    <input id="v-model" v-model="form.model" class="form-control" maxlength="60" />
                </div>
                <div class="col-12">
                    <label class="form-label" for="v-branch">Sede base</label>
                    <select id="v-branch" v-model="form.branch_id" class="form-select"><option value="">Sin sede</option><option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option></select>
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="v-soat">Vence el SOAT</label>
                    <input id="v-soat" v-model="form.soat_expires_at" type="date" class="form-control" :class="{ 'is-invalid': form.errors.soat_expires_at }" />
                    <div class="invalid-feedback">{{ form.errors.soat_expires_at }}</div>
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="v-review">Vence la revisión técnica</label>
                    <input id="v-review" v-model="form.technical_review_expires_at" type="date" class="form-control" :class="{ 'is-invalid': form.errors.technical_review_expires_at }" />
                    <div class="invalid-feedback">{{ form.errors.technical_review_expires_at }}</div>
                </div>
                <div class="col-12">
                    <div class="form-check form-switch mb-0">
                        <input id="v-active" v-model="form.is_active" class="form-check-input" type="checkbox" role="switch" />
                        <label class="form-check-label small" for="v-active">Activo</label>
                    </div>
                </div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-light border" @click="showForm = false">Cancelar</button>
                <button type="submit" form="veh-form" class="btn btn-primary" :disabled="form.processing">{{ form.id ? 'Guardar cambios' : 'Registrar' }}</button>
            </template>
        </AppModal>
    </AppLayout>
</template>
