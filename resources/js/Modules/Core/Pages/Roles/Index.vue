<script setup>
import { ref, computed, watch } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import { useConfirm } from '@/Modules/Core/Composables/useConfirm';

const props = defineProps({
    roles: { type: Array, required: true },
    groups: { type: Array, required: true },
    effective: { type: Object, required: true },
    standard: { type: Object, required: true },
    company: { type: String, default: '' },
});

const { ask } = useConfirm();
const current = ref(props.roles[0]?.slug);
const search = ref('');
const form = useForm({ permissions: [] });

// Al cambiar de rol (o al volver del servidor) se cargan sus permisos actuales
watch([current, () => props.effective], () => {
    form.permissions = [...(props.effective[current.value] || [])];
    form.clearErrors();
}, { immediate: true });

const role = computed(() => props.roles.find((r) => r.slug === current.value));
const standardSet = computed(() => new Set(props.standard[current.value] || []));
const selected = computed(() => new Set(form.permissions));
const changed = computed(() => {
    const saved = new Set(props.effective[current.value] || []);
    return form.permissions.length !== saved.size || form.permissions.some((p) => !saved.has(p));
});
const differsFromStandard = (slug) => {
    const on = selected.value.has(slug);
    return on !== standardSet.value.has(slug) ? (on ? 'added' : 'removed') : null;
};
const customized = (slug) => {
    const eff = new Set(props.effective[slug] || []);
    const std = props.standard[slug] || [];
    return eff.size !== std.length || std.some((p) => !eff.has(p));
};

const term = computed(() => search.value.trim().toLowerCase());
const visibleGroups = computed(() => props.groups
    .map((g) => ({ ...g, permissions: g.permissions.filter((p) => !term.value || p.name.toLowerCase().includes(term.value) || p.slug.includes(term.value)) }))
    .filter((g) => g.permissions.length));

function toggle(slug) {
    form.permissions = selected.value.has(slug) ? form.permissions.filter((p) => p !== slug) : [...form.permissions, slug];
}
function toggleGroup(group, on) {
    const slugs = group.permissions.map((p) => p.slug);
    form.permissions = on ? [...new Set([...form.permissions, ...slugs])] : form.permissions.filter((p) => !slugs.includes(p));
}
const groupState = (group) => {
    const n = group.permissions.filter((p) => selected.value.has(p.slug)).length;
    return n === 0 ? 'none' : n === group.permissions.length ? 'all' : 'some';
};

function save() {
    form.put(`/settings/roles/${current.value}`, { preserveScroll: true });
}
async function reset() {
    const ok = await ask({
        title: `¿Volver al estándar?`,
        message: `${role.value.name} recupera los permisos de fábrica del sistema. Lo que personalizaste para este rol se pierde.`,
        confirmText: 'Sí, volver al estándar',
        variant: 'danger',
        icon: 'bi bi-arrow-counterclockwise',
    });
    if (ok) router.delete(`/settings/roles/${current.value}`, { preserveScroll: true });
}
</script>

<template>
    <AppLayout title="Roles y permisos">
        <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-3">
            <div>
                <h1 class="h4 fw-bold mb-1">Roles y permisos</h1>
                <p class="text-body-secondary small mb-0">
                    Qué puede hacer cada rol en <strong>{{ company }}</strong>: parte de los permisos estándar, lo que cambies aplica solo
                    a esta empresa y las actualizaciones del sistema no lo pisan. Solo se muestran los módulos activos.
                </p>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-12 col-lg-3">
                <div class="list-group shadow-sm" role="tablist" aria-label="Roles">
                    <button v-for="r in roles" :key="r.slug" type="button" role="tab" :aria-selected="current === r.slug"
                        class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
                        :class="{ active: current === r.slug }" @click="current = r.slug">
                        <span>{{ r.name }}</span>
                        <span v-if="customized(r.slug)" class="badge rounded-pill" :class="current === r.slug ? 'bg-light text-primary' : 'bg-warning-subtle text-warning-emphasis'">personalizado</span>
                    </button>
                </div>
                <p class="small text-body-secondary mt-2 mb-0">El Administrador de empresa tiene acceso total y no se edita.</p>
            </div>

            <div class="col-12 col-lg-9">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                            <div>
                                <h2 class="h6 fw-bold mb-1">{{ role?.name }}</h2>
                                <p class="small text-body-secondary mb-0">{{ role?.description }}</p>
                            </div>
                            <div class="d-flex flex-wrap gap-2">
                                <button v-if="customized(current)" type="button" class="btn btn-sm btn-outline-secondary" @click="reset">
                                    <i class="bi bi-arrow-counterclockwise me-1"></i>Volver al estándar
                                </button>
                                <button type="button" class="btn btn-sm btn-primary" :disabled="!changed || form.processing" @click="save">
                                    <span v-if="form.processing" class="spinner-border spinner-border-sm me-1"></span>Guardar cambios
                                </button>
                            </div>
                        </div>

                        <div class="input-group input-group-sm mb-3" style="max-width: 360px">
                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                            <input v-model="search" type="search" class="form-control" placeholder="Buscar permiso…" aria-label="Buscar permiso" />
                        </div>

                        <div v-if="changed" class="alert alert-info py-2 small">Tienes cambios sin guardar en {{ role?.name }}.</div>

                        <div v-for="g in visibleGroups" :key="g.key" class="border rounded p-3 mb-2">
                            <div class="form-check mb-2">
                                <input :id="`grp-${g.key}`" class="form-check-input" type="checkbox" :checked="groupState(g) === 'all'"
                                    :indeterminate="groupState(g) === 'some'" @change="toggleGroup(g, $event.target.checked)" />
                                <label class="form-check-label fw-semibold" :for="`grp-${g.key}`">{{ g.label }}</label>
                            </div>
                            <div class="row g-1 ps-4">
                                <div v-for="p in g.permissions" :key="p.slug" class="col-12 col-md-6">
                                    <div class="form-check">
                                        <input :id="`perm-${p.slug}`" class="form-check-input" type="checkbox" :checked="selected.has(p.slug)" @change="toggle(p.slug)" />
                                        <label class="form-check-label small" :for="`perm-${p.slug}`">
                                            {{ p.name }}
                                            <span v-if="differsFromStandard(p.slug) === 'added'" class="badge bg-success-subtle text-success ms-1">agregado</span>
                                            <span v-else-if="differsFromStandard(p.slug) === 'removed'" class="badge bg-danger-subtle text-danger ms-1">quitado</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <p v-if="!visibleGroups.length" class="text-body-secondary small text-center py-4 mb-0">No hay permisos que coincidan con la búsqueda.</p>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
