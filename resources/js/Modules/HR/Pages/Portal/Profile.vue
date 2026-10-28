<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import PortalLayout from '@/Modules/HR/Layouts/PortalLayout.vue';

const props = defineProps({ data: { type: Object, required: true } });

const u = computed(() => props.data.user);
const p = computed(() => props.data.profile || {});
const docs = computed(() => props.data.documents || { items: [], missing: [] });
const docTypes = computed(() => props.data.catalogs?.document_types || {});
const fmt = (iso) => (iso ? iso.slice(0, 10).split('-').reverse().join('/') : '—');
const tone = { approved: 'success', pending: 'warning', rejected: 'danger' };

const pwd = useForm({ current_password: '', password: '', password_confirmation: '' });
const savePassword = () => pwd.put('/mi-espacio/perfil/password', { preserveScroll: true, onSuccess: () => pwd.reset() });
</script>

<template>
    <PortalLayout title="Mi perfil">
        <h1 class="portal-title">Mi perfil</h1>
        <div class="row g-3">
            <div class="col-12 col-lg-6">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <h2 class="h6 fw-bold">Mis datos</h2>
                        <dl class="row small mb-0">
                            <dt class="col-5 fw-normal text-body-secondary">Nombre</dt><dd class="col-7">{{ u.full_name }}</dd>
                            <dt class="col-5 fw-normal text-body-secondary">Documento</dt><dd class="col-7 text-uppercase">{{ u.document_type }} {{ u.document_number }}</dd>
                            <dt class="col-5 fw-normal text-body-secondary">Correo</dt><dd class="col-7 text-break">{{ u.email }}</dd>
                            <dt class="col-5 fw-normal text-body-secondary">Cargo</dt><dd class="col-7">{{ u.position || '—' }}</dd>
                            <dt class="col-5 fw-normal text-body-secondary">Área</dt><dd class="col-7">{{ u.department || '—' }}</dd>
                            <dt class="col-5 fw-normal text-body-secondary">Ingreso</dt><dd class="col-7">{{ fmt(u.hire_date) }}</dd>
                            <dt class="col-5 fw-normal text-body-secondary">Régimen laboral</dt><dd class="col-7">{{ p.employment?.labor_regime_label || '—' }}</dd>
                            <dt class="col-5 fw-normal text-body-secondary">Cuenta de pago</dt><dd class="col-7">{{ [p.payment?.bank_name, p.payment?.bank_account_number].filter(Boolean).join(' · ') || '—' }}</dd>
                            <dt class="col-5 fw-normal text-body-secondary">Cuenta CTS</dt><dd class="col-7">{{ [p.employment?.cts_bank_name, p.employment?.cts_account_number].filter(Boolean).join(' · ') || '—' }}</dd>
                            <dt class="col-5 fw-normal text-body-secondary">Pensión</dt><dd class="col-7 text-uppercase">{{ [p.pension?.pension_system, p.pension?.pension_fund].filter(Boolean).join(' · ') || '—' }}</dd>
                        </dl>
                        <p class="small text-body-secondary mt-3 mb-0">Para actualizar tus datos de pago o contacto usa la app; si algo está mal, avisa a RR. HH.</p>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-6">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <h2 class="h6 fw-bold">Mi legajo</h2>
                        <div v-if="docs.missing.length" class="alert alert-warning py-2 small">
                            Te falta: {{ docs.missing.map((t) => docTypes[t] || t).join(', ') }}. Súbelo desde la app.
                        </div>
                        <ul class="list-group list-group-flush small">
                            <li v-for="d in docs.items" :key="d.id" class="list-group-item px-0 d-flex justify-content-between gap-2">
                                <span>{{ d.type_label }}<span v-if="d.expires_at" class="text-body-secondary"> · vence {{ fmt(d.expires_at) }}</span></span>
                                <span class="badge" :class="`bg-${tone[d.status] || 'secondary'}-subtle text-${tone[d.status] || 'secondary'}-emphasis`">{{ d.status_label }}</span>
                            </li>
                            <li v-if="!docs.items.length" class="list-group-item px-0 text-body-secondary">Aún no hay documentos en tu legajo.</li>
                        </ul>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-6">
                <form class="card shadow-sm" @submit.prevent="savePassword">
                    <div class="card-body">
                        <h2 class="h6 fw-bold">Cambiar contraseña</h2>
                        <label class="form-label small" for="pw-current">Contraseña actual</label>
                        <input id="pw-current" v-model="pwd.current_password" type="password" class="form-control form-control-sm mb-2" :class="{ 'is-invalid': pwd.errors.current_password }" autocomplete="current-password" required />
                        <div class="invalid-feedback">{{ pwd.errors.current_password }}</div>
                        <label class="form-label small" for="pw-new">Nueva contraseña</label>
                        <input id="pw-new" v-model="pwd.password" type="password" class="form-control form-control-sm mb-2" :class="{ 'is-invalid': pwd.errors.password }" autocomplete="new-password" required />
                        <div class="invalid-feedback">{{ pwd.errors.password }}</div>
                        <label class="form-label small" for="pw-confirm">Repite la nueva contraseña</label>
                        <input id="pw-confirm" v-model="pwd.password_confirmation" type="password" class="form-control form-control-sm" autocomplete="new-password" required />
                        <div class="form-text small">Mínimo 8 caracteres, con mayúsculas, minúsculas y números. Se cerrará la sesión de la app en tus celulares.</div>
                        <button type="submit" class="btn btn-sm btn-primary mt-2" :disabled="pwd.processing">Cambiar contraseña</button>
                    </div>
                </form>
            </div>
        </div>
    </PortalLayout>
</template>
