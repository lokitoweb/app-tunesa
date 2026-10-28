<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
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
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
            <div>
                <h1 class="h4 fw-bold mb-1">Control de combustible</h1>
                <p class="text-body-secondary small mb-0">Cargas por placa con kilometraje: rendimiento en km por galón.</p>
            </div>
            <button type="button" class="btn btn-primary" @click="open()"><i class="bi bi-plus-lg me-1"></i>Registrar carga</button>
        </div>

        <div class="card shadow-sm mb-3">
            <div class="card-body d-flex flex-wrap gap-2">
                <input type="month" class="form-control w-auto" :value="filters.month" aria-label="Mes" @change="go({ month: $event.target.value })" />
                <select class="form-select w-auto" :value="filters.vehicle || ''" aria-label="Vehículo" @change="go({ vehicle: $event.target.value || undefined })">
                    <option value="">Todos los vehículos</option>
                    <option v-for="v in vehicles" :key="v.id" :value="v.id">{{ v.plate }}</option>
                </select>
            </div>
            <div v-if="Object.keys(by_vehicle).length" class="card-footer bg-transparent">
                <div class="row g-2 small">
                    <div v-for="(s, plate) in by_vehicle" :key="plate" class="col-sm-6 col-lg-3">
                        <div class="border rounded p-2">
                            <div class="font-monospace fw-semibold">{{ plate }}</div>
                            <div>{{ num(s.gallons) }} gal · {{ money(s.total) }}</div>
                            <div class="text-body-secondary">{{ s.km ? `${num(s.km, 0)} km` : '—' }} · <span class="fw-semibold text-body">{{ s.km_per_gallon ? `${num(s.km_per_gallon, 2)} km/gal` : 'sin rendimiento' }}</span></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3">
            <div :class="showForm ? 'col-xl-7' : 'col-12'">
                <div class="card shadow-sm">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light"><tr><th>Fecha</th><th>Placa</th><th class="text-end">Galones</th><th class="text-end">Total</th><th class="text-end">Km</th><th class="text-end">Km/gal</th><th class="text-end">Acciones</th></tr></thead>
                            <tbody>
                                <tr v-for="l in loads" :key="l.id">
                                    <td class="text-nowrap">{{ l.date_label }}<div class="small text-body-secondary">{{ l.fuel_type }}</div></td>
                                    <td><span class="font-monospace fw-semibold">{{ l.plate }}</span><div class="small text-body-secondary">{{ l.driver || l.supplier || '' }}</div></td>
                                    <td class="text-end">{{ num(l.gallons) }}<div class="small text-body-secondary">{{ money(l.price_per_gallon) }}/gal</div></td>
                                    <td class="text-end fw-semibold">{{ money(l.total) }}</td>
                                    <td class="text-end">{{ num(l.odometer, 0) }}<div v-if="l.km !== null" class="small text-body-secondary">+{{ num(l.km, 0) }}</div></td>
                                    <td class="text-end">{{ l.km_per_gallon !== null ? num(l.km_per_gallon, 2) : '—' }}</td>
                                    <td class="text-end text-nowrap">
                                        <button type="button" class="btn-table-action btn-action-edit" title="Editar carga" @click="open(l)"><i class="bi bi-pencil-fill"></i></button>
                                        <button type="button" class="btn-table-action btn-table-delete" title="Eliminar carga" @click="destroy(l)"><i class="bi bi-trash3-fill"></i></button>
                                    </td>
                                </tr>
                                <tr v-if="!loads.length"><td colspan="7" class="text-center text-body-secondary py-5"><i class="bi bi-fuel-pump fs-2 d-block mb-2"></i>No hay cargas en este mes.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div v-if="showForm" class="col-xl-5">
                <form class="card shadow-sm" novalidate @submit.prevent="submit">
                    <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                        <h2 class="h6 fw-bold mb-0">{{ form.id ? 'Editar carga' : 'Registrar carga' }}</h2>
                        <button type="button" class="btn-close" aria-label="Cerrar" @click="showForm = false"></button>
                    </div>
                    <div class="card-body row g-3">
                        <div class="col-sm-6">
                            <label class="form-label small fw-semibold" for="f-vehicle">Vehículo *</label>
                            <select id="f-vehicle" v-model="form.vehicle_id" class="form-select" :class="{ 'is-invalid': form.errors.vehicle_id }">
                                <option value="">Elige…</option>
                                <option v-for="v in vehicles" :key="v.id" :value="v.id">{{ v.plate }} · {{ v.type }}</option>
                            </select>
                            <div class="invalid-feedback">{{ form.errors.vehicle_id }}</div>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small fw-semibold" for="f-date">Fecha *</label>
                            <input id="f-date" v-model="form.date" type="date" :max="today" class="form-control" :class="{ 'is-invalid': form.errors.date }" />
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label small fw-semibold" for="f-gal">Galones *</label>
                            <input id="f-gal" v-model="form.gallons" type="number" min="0.001" step="0.001" class="form-control" :class="{ 'is-invalid': form.errors.gallons }" />
                            <div class="invalid-feedback">{{ form.errors.gallons }}</div>
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label small fw-semibold" for="f-price">S/ por galón *</label>
                            <input id="f-price" v-model="form.price_per_gallon" type="number" min="0.01" step="0.0001" class="form-control" :class="{ 'is-invalid': form.errors.price_per_gallon }" />
                            <div class="invalid-feedback">{{ form.errors.price_per_gallon }}</div>
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label small fw-semibold" for="f-km">Kilometraje *</label>
                            <input id="f-km" v-model="form.odometer" type="number" min="0" class="form-control" :class="{ 'is-invalid': form.errors.odometer }" />
                            <div class="invalid-feedback">{{ form.errors.odometer }}</div>
                        </div>
                        <div class="col-12 small text-body-secondary">Total: <span class="fw-semibold text-body">{{ money(total()) }}</span></div>
                        <div class="col-sm-6">
                            <label class="form-label small fw-semibold" for="f-type">Combustible</label>
                            <select id="f-type" v-model="form.fuel_type" class="form-select"><option v-for="t in fuel_types" :key="t" :value="t">{{ t }}</option></select>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small fw-semibold" for="f-doc">N.º comprobante</label>
                            <input id="f-doc" v-model="form.document_number" class="form-control font-monospace" maxlength="50" />
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small fw-semibold" for="f-sup">Grifo (proveedor)</label>
                            <select id="f-sup" v-model="form.supplier_id" class="form-select"><option value="">—</option><option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option></select>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small fw-semibold" for="f-driver">Conductor</label>
                            <input id="f-driver" v-model="form.driver" class="form-control" maxlength="150" />
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
