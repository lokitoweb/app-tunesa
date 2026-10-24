<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import { useTenantPicker } from '@/Modules/Core/Composables/useTenantPicker';

const props = defineProps({
    signatories: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
});

const { ensureTenant } = useTenantPicker();
const showForm = ref(false);
const fileInput = ref(null);
const blank = () => ({ id: null, user_id: '', position: '', can_authorize: false, is_active: true, signature: null });
const form = useForm(blank());

function open(s = null) {
    if (!s && !ensureTenant({ message: 'El apoderado pertenece a una empresa.' })) return;
    Object.assign(form, s ? { ...blank(), ...s, signature: null } : blank());
    form.clearErrors();
    if (fileInput.value) fileInput.value.value = '';
    showForm.value = true;
}
function submit() {
    form.transform((d) => {
        const out = { ...d, can_authorize: d.can_authorize ? 1 : 0, is_active: d.is_active ? 1 : 0 };
        if (d.id) delete out.user_id;
        if (!d.signature) delete out.signature;
        return out;
    }).post(form.id ? `/signatories/${form.id}` : '/signatories', { preserveScroll: true, forceFormData: true, onSuccess: () => (showForm.value = false) });
}
</script>

<template>
    <AppLayout title="Apoderados y firmas">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
            <div>
                <h1 class="h4 fw-bold mb-1">Apoderados y firmas</h1>
                <p class="text-body-secondary small mb-0">Su firma se estampa en la OC/OS que aprueban y en la OP que autorizan. Solo quien "autoriza pagos" da el visto bueno en Tesorería y en la app.</p>
            </div>
            <button type="button" class="btn btn-primary" @click="open()"><i class="bi bi-plus-lg me-1"></i>Designar apoderado</button>
        </div>

        <div class="row g-3">
            <div :class="showForm ? 'col-xl-7' : 'col-12'">
                <div class="card shadow-sm">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light"><tr><th>Persona</th><th>Cargo</th><th>Firma</th><th>Autoriza pagos</th><th class="text-end">Acciones</th></tr></thead>
                            <tbody>
                                <tr v-for="s in signatories" :key="s.id" :class="{ 'text-body-secondary': !s.is_active }">
                                    <td><div class="fw-semibold">{{ s.name }}</div><div class="small text-body-secondary">{{ s.dni || s.email }}</div></td>
                                    <td>{{ s.position }} <span v-if="!s.is_active" class="badge text-bg-secondary">inactivo</span></td>
                                    <td>
                                        <img v-if="s.has_signature" :src="`/signatories/${s.id}/signature`" alt="Firma" style="max-height: 36px; max-width: 120px" />
                                        <span v-else class="badge text-bg-warning">sin firma</span>
                                    </td>
                                    <td><i class="bi" :class="s.can_authorize ? 'bi-check-circle-fill text-success' : 'bi-dash text-body-secondary'"></i></td>
                                    <td class="text-end"><button type="button" class="btn-table-action btn-action-edit" :title="`Editar ${s.name}`" @click="open(s)"><i class="bi bi-pencil-fill"></i></button></td>
                                </tr>
                                <tr v-if="!signatories.length"><td colspan="5" class="text-center text-body-secondary py-5"><i class="bi bi-pen fs-2 d-block mb-2"></i>Aún no hay apoderados: designa quién firma y autoriza pagos.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div v-if="showForm" class="col-xl-5">
                <form class="card shadow-sm" novalidate @submit.prevent="submit">
                    <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                        <h2 class="h6 fw-bold mb-0">{{ form.id ? 'Editar apoderado' : 'Designar apoderado' }}</h2>
                        <button type="button" class="btn-close" aria-label="Cerrar" @click="showForm = false"></button>
                    </div>
                    <div class="card-body row g-3">
                        <div v-if="!form.id" class="col-12">
                            <label class="form-label small fw-semibold" for="s-user">Persona (usuario de la empresa) *</label>
                            <select id="s-user" v-model="form.user_id" class="form-select" :class="{ 'is-invalid': form.errors.user_id }">
                                <option value="">Elige…</option>
                                <option v-for="u in users" :key="u.id" :value="u.id">{{ u.label }}</option>
                            </select>
                            <div class="invalid-feedback">{{ form.errors.user_id }}</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold" for="s-pos">Cargo *</label>
                            <input id="s-pos" v-model="form.position" class="form-control" maxlength="100" placeholder="Gerente general" :class="{ 'is-invalid': form.errors.position }" />
                            <div class="invalid-feedback">{{ form.errors.position }}</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold" for="s-sig">Firma escaneada (PNG o JPG, máx. 2 MB){{ form.id ? ' — reemplaza la actual' : '' }}</label>
                            <input id="s-sig" ref="fileInput" type="file" accept="image/png,image/jpeg" class="form-control" :class="{ 'is-invalid': form.errors.signature }" @input="form.signature = $event.target.files[0]" />
                            <div class="invalid-feedback">{{ form.errors.signature }}</div>
                        </div>
                        <div class="col-12 form-check form-switch ms-2">
                            <input id="s-auth" v-model="form.can_authorize" class="form-check-input" type="checkbox" role="switch" />
                            <label class="form-check-label" for="s-auth">Autoriza órdenes de pago (persona designada)</label>
                        </div>
                        <div class="col-12 form-check form-switch ms-2">
                            <input id="s-active" v-model="form.is_active" class="form-check-input" type="checkbox" role="switch" />
                            <label class="form-check-label" for="s-active">Activo</label>
                        </div>
                    </div>
                    <div class="card-footer bg-transparent d-flex justify-content-end gap-2">
                        <button type="button" class="btn btn-light" @click="showForm = false">Cancelar</button>
                        <button type="submit" class="btn btn-primary" :disabled="form.processing">Guardar</button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
