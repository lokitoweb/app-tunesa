<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import AppModal from '@/Modules/Core/Components/AppModal.vue';
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
        <header class="page-head">
            <div class="page-head-text">
                <h1 class="page-title">Apoderados y firmas</h1>
                <p class="page-subtitle">Su firma se estampa en la OC/OS que aprueban y en la OP que autorizan. Solo quien "autoriza pagos" da el visto bueno en Tesorería y en la app.</p>
            </div>
            <div class="page-actions">
                <button type="button" class="btn btn-primary" @click="open()"><i class="bi bi-plus-lg me-1"></i>Designar apoderado</button>
            </div>
        </header>

        <section class="surface">
            <div class="table-responsive">
                <table class="table data-table table-cards align-middle mb-0">
                    <thead><tr><th>Persona</th><th>Cargo</th><th>Firma</th><th>Autoriza pagos</th><th class="text-end"><span class="visually-hidden">Acciones</span></th></tr></thead>
                    <tbody>
                        <tr v-for="s in signatories" :key="s.id" :class="{ 'is-muted': !s.is_active }">
                            <td data-label="Persona" class="cell-main">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="topbar-avatar">{{ (s.name || '?').split(' ').map((w) => w[0]).slice(0, 2).join('') }}</span>
                                    <div class="min-w-0"><div class="cell-title text-truncate">{{ s.name }}</div><div class="cell-sub text-truncate">{{ s.dni || s.email }}</div></div>
                                </div>
                            </td>
                            <td data-label="Cargo">{{ s.position }} <span v-if="!s.is_active" class="status-pill tone-neutral ms-1">Inactivo</span></td>
                            <td data-label="Firma">
                                <span v-if="s.has_signature" class="signature-box"><img :src="`/signatories/${s.id}/signature`" alt="Firma" /></span>
                                <span v-else class="status-pill tone-amber">Sin firma</span>
                            </td>
                            <td data-label="Autoriza pagos">
                                <span v-if="s.can_authorize" class="status-pill tone-emerald"><i class="bi bi-check2-circle"></i>Autoriza</span>
                                <span v-else class="cell-sub">No</span>
                            </td>
                            <td class="cell-actions"><div class="row-actions"><button type="button" class="icon-btn" :title="`Editar ${s.name}`" :aria-label="`Editar ${s.name}`" @click="open(s)"><i class="bi bi-pencil"></i></button></div></td>
                        </tr>
                        <tr v-if="!signatories.length" class="row-empty">
                            <td colspan="5">
                                <div class="dash-empty">
                                    <span class="dash-empty-icon"><i class="bi bi-pen"></i></span>
                                    <p class="dash-empty-title">Aún no hay apoderados</p>
                                    <p class="dash-empty-text">Designa quién firma las órdenes y quién autoriza los pagos.</p>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <AppModal :show="showForm" :title="form.id ? 'Editar apoderado' : 'Designar apoderado'" subtitle="La firma se estampa en las OC/OS y OP que firma o autoriza." :busy="form.processing" @close="showForm = false">
            <form id="sig-form" class="row g-3" novalidate @submit.prevent="submit">
                <div v-if="!form.id" class="col-12">
                    <label class="form-label" for="s-user">Persona (usuario de la empresa) *</label>
                    <select id="s-user" v-model="form.user_id" class="form-select" :class="{ 'is-invalid': form.errors.user_id }">
                        <option value="">Elige…</option>
                        <option v-for="u in users" :key="u.id" :value="u.id">{{ u.label }}</option>
                    </select>
                    <div class="invalid-feedback">{{ form.errors.user_id }}</div>
                </div>
                <div class="col-12">
                    <label class="form-label" for="s-pos">Cargo *</label>
                    <input id="s-pos" v-model="form.position" class="form-control" maxlength="100" placeholder="Gerente general" :class="{ 'is-invalid': form.errors.position }" />
                    <div class="invalid-feedback">{{ form.errors.position }}</div>
                </div>
                <div class="col-12">
                    <label class="form-label" for="s-sig">Firma escaneada (PNG o JPG, máx. 2 MB){{ form.id ? ' — reemplaza la actual' : '' }}</label>
                    <input id="s-sig" ref="fileInput" type="file" accept="image/png,image/jpeg" class="form-control" :class="{ 'is-invalid': form.errors.signature }" @input="form.signature = $event.target.files[0]" />
                    <div class="invalid-feedback">{{ form.errors.signature }}</div>
                </div>
                <div class="col-12">
                    <div class="form-check form-switch mb-2">
                        <input id="s-auth" v-model="form.can_authorize" class="form-check-input" type="checkbox" role="switch" />
                        <label class="form-check-label small" for="s-auth">Autoriza órdenes de pago (persona designada)</label>
                    </div>
                    <div class="form-check form-switch mb-0">
                        <input id="s-active" v-model="form.is_active" class="form-check-input" type="checkbox" role="switch" />
                        <label class="form-check-label small" for="s-active">Activo</label>
                    </div>
                </div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-light border" @click="showForm = false">Cancelar</button>
                <button type="submit" form="sig-form" class="btn btn-primary" :disabled="form.processing">Guardar</button>
            </template>
        </AppModal>
    </AppLayout>
</template>
