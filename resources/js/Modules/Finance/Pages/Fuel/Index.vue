<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import AppModal from '@/Modules/Core/Components/AppModal.vue';
import { useConfirm } from '@/Modules/Core/Composables/useConfirm';
import { useTenantPicker } from '@/Modules/Core/Composables/useTenantPicker';
import { money } from '@/Modules/Core/format';

const props = defineProps({
    loads: { type: Array, default: () => [] },
    by_vehicle: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
    vehicles: { type: Array, default: () => [] },
    suppliers: { type: Array, default: () => [] },
    fuel_types: { type: Array, required: true },
});

const { ask } = useConfirm();
const { ensureTenant } = useTenantPicker();
const today = new Date().toLocaleDateString('en-CA') // fecha local (toISOString es UTC: después de las 19:00 en Lima daría mañana);

const num = (v, d = 3) => Number(v || 0).toLocaleString('es-PE', { maximumFractionDigits: d });

function go(params) {
    router.get('/fuel', { month: props.filters.month, vehicle: props.filters.vehicle || undefined, ...params }, { preserveState: true, replace: true });
}

const showForm = ref(false);
const blank = () => ({ id: null, vehicle_id: props.filters.vehicle || '', date: today, gallons: '', price_per_gallon: '', odometer: '', fuel_type: 'DIESEL', supplier_id: '', document_number: '', driver: '' });
const form = useForm(blank());
function open(l = null) {
    if (!l && !ensureTenant({ message: 'La carga se registra en una empresa.' })) return;
    Object.assign(form, l ? { ...blank(), ...Object.fromEntries(Object.entries(l).map(([k, v]) => [k, v ?? ''])) } : blank());
    form.clearErrors();
    showForm.value = true;
}
function submit() {
    const options = { preserveScroll: true, onSuccess: () => (showForm.value = false) };
    form.transform((d) => ({ ...d, supplier_id: d.supplier_id || null }));
    if (form.id) form.put(`/fuel/${form.id}`, options);
    else form.post('/fuel', options);
}
async function destroy(l) {
    const ok = await ask({ title: '¿Eliminar carga?', message: `${l.plate} · ${l.date_label} · ${money(l.total)}`, confirmText: 'Sí, eliminar', variant: 'danger', icon: 'bi bi-trash3-fill' });
    if (ok) router.delete(`/fuel/${l.id}`, { preserveScroll: true });
}
const total = () => Number(form.gallons || 0) * Number(form.price_per_gallon || 0);
</script>

<template>
    <AppLayout title="Combustible">
        <header class="page-head">
            <div class="page-head-text">
                <h1 class="page-title">Control de combustible</h1>
                <p class="page-subtitle">Cargas por placa con kilometraje: rendimiento en km por galón.</p>
            </div>
            <div class="page-actions">
                <button type="button" class="btn btn-primary" @click="open()"><i class="bi bi-plus-lg me-1"></i>Registrar carga</button>
            </div>
        </header>

        <section class="surface mb-3">
            <div class="filter-row border-0">
                <input type="month" class="form-control w-auto" :value="filters.month" aria-label="Mes" @change="go({ month: $event.target.value })" />
                <select class="form-select w-auto" :value="filters.vehicle || ''" aria-label="Vehículo" @change="go({ vehicle: $event.target.value || undefined })">
                    <option value="">Todos los vehículos</option>
                    <option v-for="v in vehicles" :key="v.id" :value="v.id">{{ v.plate }}</option>
                </select>
            </div>
        </section>

        <!-- Resumen del mes por vehículo -->
        <div v-if="Object.keys(by_vehicle).length" class="concept-grid mb-3">
            <div v-for="(s, plate) in by_vehicle" :key="plate" class="concept-card">
                <div class="d-flex justify-content-between align-items-center gap-2">
                    <span class="concept-card-title font-monospace">{{ plate }}</span>
                    <span class="status-pill" :class="s.km_per_gallon ? 'tone-emerald' : 'tone-neutral'">{{ s.km_per_gallon ? `${num(s.km_per_gallon, 2)} km/gal` : 'sin rendimiento' }}</span>
                </div>
                <div class="concept-card-value">{{ money(s.total) }}</div>
                <div class="cell-sub">{{ num(s.gallons) }} gal · {{ s.km ? `${num(s.km, 0)} km` : '—' }}</div>
            </div>
        </div>

        <section class="surface">
            <div class="table-responsive">
                <table class="table data-table table-cards align-middle mb-0">
                    <thead><tr><th>Fecha</th><th>Placa</th><th class="text-end">Galones</th><th class="text-end">Total</th><th class="text-end">Km</th><th class="text-end">Km/gal</th><th class="text-end"><span class="visually-hidden">Acciones</span></th></tr></thead>
                    <tbody>
                        <tr v-for="l in loads" :key="l.id">
                            <td data-label="Fecha" class="text-nowrap"><span class="tabular">{{ l.date_label }}</span><div class="cell-sub">{{ l.fuel_type }}</div></td>
                            <td data-label="Placa"><span class="cell-title font-monospace">{{ l.plate }}</span><div class="cell-sub">{{ l.driver || l.supplier || '' }}</div></td>
                            <td data-label="Galones" class="text-end text-nowrap tabular">{{ num(l.gallons) }}<div class="cell-sub">{{ money(l.price_per_gallon) }}/gal</div></td>
                            <td data-label="Total" class="text-end text-nowrap"><span class="cell-amount">{{ money(l.total) }}</span></td>
                            <td data-label="Km" class="text-end text-nowrap tabular">{{ num(l.odometer, 0) }}<div v-if="l.km !== null" class="cell-sub">+{{ num(l.km, 0) }}</div></td>
                            <td data-label="Km/gal" class="text-end tabular">{{ l.km_per_gallon !== null ? num(l.km_per_gallon, 2) : '—' }}</td>
                            <td class="cell-actions">
                                <div class="row-actions">
                                    <button type="button" class="icon-btn" title="Editar carga" aria-label="Editar carga" @click="open(l)"><i class="bi bi-pencil"></i></button>
                                    <button type="button" class="icon-btn icon-btn-danger" title="Eliminar carga" aria-label="Eliminar carga" @click="destroy(l)"><i class="bi bi-trash3"></i></button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!loads.length" class="row-empty">
                            <td colspan="7">
                                <div class="dash-empty">
                                    <span class="dash-empty-icon"><i class="bi bi-fuel-pump"></i></span>
                                    <p class="dash-empty-title">No hay cargas en este mes</p>
                                    <p class="dash-empty-text">Registra cada carga con su kilometraje para ver el rendimiento por vehículo.</p>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <AppModal :show="showForm" :title="form.id ? 'Editar carga' : 'Registrar carga'" subtitle="El rendimiento se calcula con el kilometraje de la carga anterior del mismo vehículo." :busy="form.processing" @close="showForm = false">
            <form id="fuel-form" class="row g-3" novalidate @submit.prevent="submit">
                <div class="col-sm-6">
                    <label class="form-label" for="f-vehicle">Vehículo *</label>
                    <select id="f-vehicle" v-model="form.vehicle_id" class="form-select" :class="{ 'is-invalid': form.errors.vehicle_id }">
                        <option value="">Elige…</option>
                        <option v-for="v in vehicles" :key="v.id" :value="v.id">{{ v.plate }} · {{ v.type }}</option>
                    </select>
                    <div class="invalid-feedback">{{ form.errors.vehicle_id }}</div>
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="f-date">Fecha *</label>
                    <input id="f-date" v-model="form.date" type="date" :max="today" class="form-control" :class="{ 'is-invalid': form.errors.date }" />
                </div>
                <div class="col-sm-4">
                    <label class="form-label" for="f-gal">Galones *</label>
                    <input id="f-gal" v-model="form.gallons" type="number" min="0.001" step="0.001" class="form-control" :class="{ 'is-invalid': form.errors.gallons }" />
                    <div class="invalid-feedback">{{ form.errors.gallons }}</div>
                </div>
                <div class="col-sm-4">
                    <label class="form-label" for="f-price">S/ por galón *</label>
                    <input id="f-price" v-model="form.price_per_gallon" type="number" min="0.01" step="0.0001" class="form-control" :class="{ 'is-invalid': form.errors.price_per_gallon }" />
                    <div class="invalid-feedback">{{ form.errors.price_per_gallon }}</div>
                </div>
                <div class="col-sm-4">
                    <label class="form-label" for="f-km">Kilometraje *</label>
                    <input id="f-km" v-model="form.odometer" type="number" min="0" class="form-control" :class="{ 'is-invalid': form.errors.odometer }" />
                    <div class="invalid-feedback">{{ form.errors.odometer }}</div>
                </div>
                <div class="col-12">
                    <div class="dash-notice mb-0"><i class="bi bi-calculator"></i><span>Total de la carga: <strong class="text-body">{{ money(total()) }}</strong></span></div>
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="f-type">Combustible</label>
                    <select id="f-type" v-model="form.fuel_type" class="form-select"><option v-for="t in fuel_types" :key="t" :value="t">{{ t }}</option></select>
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="f-doc">N.º comprobante</label>
                    <input id="f-doc" v-model="form.document_number" class="form-control font-monospace" maxlength="50" />
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="f-sup">Grifo (proveedor)</label>
                    <select id="f-sup" v-model="form.supplier_id" class="form-select"><option value="">—</option><option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option></select>
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="f-driver">Conductor</label>
                    <input id="f-driver" v-model="form.driver" class="form-control" maxlength="150" />
                </div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-light border" @click="showForm = false">Cancelar</button>
                <button type="submit" form="fuel-form" class="btn btn-primary" :disabled="form.processing">{{ form.id ? 'Guardar cambios' : 'Registrar' }}</button>
            </template>
        </AppModal>
    </AppLayout>
</template>
