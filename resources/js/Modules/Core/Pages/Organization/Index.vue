<script setup>
import { computed, reactive, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import TenantBadge from '@/Modules/Core/Components/TenantBadge.vue';

const props = defineProps({
    zones: Array,
    departments: Array,
    branches: Array,
    branchTypes: Object,
    suggestedDepartments: Array,
    canManage: Boolean,
    scopeLabel: String,
});

// --- Filtro en cascada: Zona → Sede → Área ---
const NO_ZONE = 'none';
const filter = reactive({ zone: '', branch: '', department: '' });
const zoneName = (id) => props.zones.find((z) => z.id === id)?.name ?? 'Sin zona';
const departmentName = (id) => props.departments.find((d) => d.id === id)?.name ?? 'Sin área';

const inZone = (branch) => filter.zone === '' || (filter.zone === NO_ZONE ? branch.zone_id === null : branch.zone_id === filter.zone);
const headcount = (branch) => branch.staff
    .filter((s) => filter.department === '' || s.department_id === filter.department)
    .reduce((sum, s) => sum + s.total, 0);

const zoneBranches = computed(() => props.branches.filter(inZone));
const visibleBranches = computed(() => zoneBranches.value
    .filter((b) => filter.branch === '' || b.id === filter.branch)
    .filter((b) => filter.department === '' || headcount(b) > 0));

// Al cambiar de zona, la sede elegida puede quedar fuera
function pickZone() {
    if (!zoneBranches.value.some((b) => b.id === filter.branch)) filter.branch = '';
}

// Árbol: zonas (y "Sin zona") con sus sedes filtradas
const tree = computed(() => {
    const groups = [...props.zones.map((z) => ({ key: z.id, zone: z })), { key: null, zone: null }];
    return groups
        .map((g) => ({ ...g, branches: visibleBranches.value.filter((b) => b.zone_id === g.key) }))
        .filter((g) => g.branches.length || (g.zone && filter.branch === '' && filter.department === '' && (filter.zone === '' || filter.zone === g.key)));
});

const totals = computed(() => ({
    branches: visibleBranches.value.length,
    people: visibleBranches.value.reduce((sum, b) => sum + headcount(b), 0),
}));

// Detalle de una sede: dotación por área y puesto
const open = reactive(new Set());
const staffByDepartment = (branch) => {
    const groups = new Map();
    for (const s of branch.staff) {
        if (filter.department !== '' && s.department_id !== filter.department) continue;
        const g = groups.get(s.department_id) ?? { id: s.department_id, total: 0, positions: [] };
        g.total += s.total;
        g.positions.push({ name: s.position || 'Sin puesto', total: s.total });
        groups.set(s.department_id, g);
    }
    return [...groups.values()].sort((a, b) => b.total - a.total);
};

// --- Catálogos: zonas y áreas (mismo formulario) ---
const CATALOGS = { zones: { one: 'zona', title: 'Zona' }, departments: { one: 'área', title: 'Área' } };
const editing = ref(null); // { catalog, id }
const form = useForm({ name: '', code: '', is_active: true });

function edit(catalog, row = null) {
    editing.value = { catalog, id: row?.id ?? null };
    form.clearErrors();
    form.name = row?.name ?? '';
    form.code = row?.code ?? '';
    form.is_active = row?.is_active ?? true;
}
function save() {
    const { catalog, id } = editing.value;
    const options = { preserveScroll: true, onSuccess: () => { editing.value = null; } };
    id ? form.put(`/organization/${catalog}/${id}`, options) : form.post(`/organization/${catalog}`, options);
}
function remove(catalog, row) {
    const effect = catalog === 'zones' ? 'Sus sedes quedarán sin zona.' : 'Sus colaboradores quedarán sin área.';
    if (confirm(`¿Eliminar ${CATALOGS[catalog].one} «${row.name}»? ${effect}`)) router.delete(`/organization/${catalog}/${row.id}`, { preserveScroll: true });
}
function addSuggested(name) {
    router.post('/organization/departments', { name, code: null, is_active: true }, { preserveScroll: true });
}
const missingSuggestions = computed(() => props.suggestedDepartments.filter((n) => !props.departments.some((d) => d.name === n)));

function updateBranch(branch, changes) {
    router.patch(`/organization/branches/${branch.id}`, { zone_id: branch.zone_id, branch_type: branch.type, ...changes }, { preserveScroll: true });
}
</script>

<template>
    <AppLayout title="Organización">
        <div class="page-header">
            <div>
                <h1>Organización</h1>
                <p>Zonas, sedes y áreas de la empresa, con la dotación activa de cada una.</p>
            </div>
            <a href="/branches/create" class="btn btn-primary" :class="{ disabled: !canManage }"><i class="bi bi-plus-lg me-1"></i>Nueva sede</a>
        </div>

        <div v-if="scopeLabel" class="alert alert-info d-flex align-items-start gap-2" role="status">
            <i class="bi bi-funnel"></i>
            <div>Tu alcance es <strong>{{ scopeLabel }}</strong>: ves solo ese territorio y no puedes cambiar la estructura.</div>
        </div>

        <!-- Filtros en cascada -->
        <div class="card border-0 mb-3">
            <div class="card-body">
                <div class="row g-2 align-items-end">
                    <div class="col-12 col-md-3">
                        <label for="f_zone" class="form-label small">Zona</label>
                        <select id="f_zone" v-model="filter.zone" class="form-select" @change="pickZone">
                            <option value="">Todas las zonas</option>
                            <option v-for="z in zones" :key="z.id" :value="z.id">{{ z.name }}</option>
                            <option :value="NO_ZONE">Sin zona</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-4">
                        <label for="f_branch" class="form-label small">Sede</label>
                        <select id="f_branch" v-model="filter.branch" class="form-select">
                            <option value="">Todas las sedes{{ filter.zone !== '' ? ' de la zona' : '' }}</option>
                            <option v-for="b in zoneBranches" :key="b.id" :value="b.id">{{ b.name }}</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-3">
                        <label for="f_department" class="form-label small">Área</label>
                        <select id="f_department" v-model="filter.department" class="form-select">
                            <option value="">Todas las áreas</option>
                            <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
                            <option :value="null">Sin área</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-2 text-md-end small text-body-secondary" aria-live="polite">
                        <strong class="text-body">{{ totals.branches }}</strong> sedes · <strong class="text-body">{{ totals.people }}</strong> personas
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3">
            <!-- Árbol Zona → Sede → Área / Puesto -->
            <div class="col-12 col-xl-8">
                <div v-for="group in tree" :key="group.key ?? 'none'" class="card border-0 mb-3">
                    <div class="card-header border-0 pt-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <h2 class="h6 fw-semibold mb-0">
                            <i class="bi bi-diagram-3 me-1 text-body-secondary"></i>{{ group.zone?.name ?? 'Sin zona' }}
                            <span v-if="group.zone?.code" class="text-body-secondary fw-normal small ms-1">{{ group.zone.code }}</span>
                            <span v-if="group.zone && !group.zone.is_active" class="badge text-bg-secondary ms-1">Inactiva</span>
                        </h2>
                        <span class="small text-body-secondary">{{ group.branches.length }} sedes · {{ group.branches.reduce((sum, b) => sum + headcount(b), 0) }} personas</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr><th>Sede</th><th>Tipo</th><th v-if="canManage">Zona</th><th class="text-end">Personas</th><th class="text-end">Geocerca</th><th></th></tr>
                            </thead>
                            <tbody>
                                <template v-for="b in group.branches" :key="b.id">
                                    <tr>
                                        <td class="branch-cell">
                                            <button type="button" class="btn btn-link p-0 text-start text-decoration-none fw-semibold" :aria-expanded="open.has(b.id)" @click="open.has(b.id) ? open.delete(b.id) : open.add(b.id)">
                                                <i class="bi me-1" :class="open.has(b.id) ? 'bi-chevron-down' : 'bi-chevron-right'"></i>{{ b.name }}
                                            </button>
                                            <span v-if="!b.is_active" class="badge text-bg-secondary ms-1">Inactiva</span>
                                            <TenantBadge :tenant-id="b.tenant_id" />
                                            <div class="small text-body-secondary">{{ [b.code, b.city, b.supervisor && `Supervisa: ${b.supervisor}`].filter(Boolean).join(' · ') }}</div>
                                        </td>
                                        <td>
                                            <select v-if="canManage" class="form-select form-select-sm w-auto" :value="b.type" :aria-label="`Tipo de ${b.name}`" @change="updateBranch(b, { branch_type: $event.target.value })">
                                                <option v-for="(label, value) in branchTypes" :key="value" :value="value">{{ label }}</option>
                                            </select>
                                            <template v-else>{{ branchTypes[b.type] ?? b.type }}</template>
                                        </td>
                                        <td v-if="canManage">
                                            <select class="form-select form-select-sm w-auto" :value="b.zone_id ?? ''" :aria-label="`Zona de ${b.name}`" @change="updateBranch(b, { zone_id: $event.target.value || null })">
                                                <option value="">Sin zona</option>
                                                <option v-for="z in zones" :key="z.id" :value="z.id">{{ z.name }}</option>
                                            </select>
                                        </td>
                                        <td class="text-end">{{ headcount(b) }}</td>
                                        <td class="text-end text-nowrap">{{ b.radius }} m</td>
                                        <td class="text-end text-nowrap">
                                            <a :href="`/branches/${b.id}/edit`" class="btn btn-sm btn-outline-secondary" :title="`Mapa y geocerca de ${b.name}`"><i class="bi bi-geo-alt"></i> Mapa</a>
                                        </td>
                                    </tr>
                                    <tr v-if="open.has(b.id)">
                                        <td :colspan="canManage ? 6 : 5" class="bg-body-tertiary">
                                            <div v-if="!staffByDepartment(b).length" class="small text-body-secondary">Sin personal activo asignado{{ filter.department !== '' ? ' en esta área' : '' }}.</div>
                                            <div v-else class="row g-2">
                                                <div v-for="dep in staffByDepartment(b)" :key="dep.id ?? 'none'" class="col-12 col-md-6">
                                                    <div class="small fw-semibold">{{ departmentName(dep.id) }} <span class="text-body-secondary fw-normal">· {{ dep.total }}</span></div>
                                                    <ul class="list-unstyled small text-body-secondary mb-0">
                                                        <li v-for="p in dep.positions" :key="p.name">{{ p.name }}: {{ p.total }}</li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                </template>
                                <tr v-if="!group.branches.length">
                                    <td :colspan="canManage ? 6 : 5" class="small text-body-secondary">Esta zona aún no tiene sedes. Asígnalas con el selector «Zona» de cada sede.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div v-if="!tree.length" class="card border-0"><div class="card-body"><div class="empty-state"><i class="bi bi-diagram-3"></i>No hay sedes con esos filtros.</div></div></div>
            </div>

            <!-- Catálogos -->
            <div class="col-12 col-xl-4">
                <div v-for="(meta, catalog) in CATALOGS" :key="catalog" class="card border-0 mb-3">
                    <div class="card-header border-0 pt-3 d-flex justify-content-between align-items-center">
                        <h2 class="h6 fw-semibold mb-0">{{ meta.title }}s</h2>
                        <button v-if="canManage" type="button" class="btn btn-sm btn-outline-primary" @click="edit(catalog)"><i class="bi bi-plus-lg"></i> Nueva</button>
                    </div>
                    <ul class="list-group list-group-flush">
                        <li v-for="row in (catalog === 'zones' ? zones : departments)" :key="row.id" class="list-group-item d-flex justify-content-between align-items-center gap-2">
                            <span class="min-w-0">
                                <span class="fw-medium">{{ row.name }}</span>
                                <span v-if="row.code" class="small text-body-secondary ms-1">{{ row.code }}</span>
                                <span v-if="!row.is_active" class="badge text-bg-secondary ms-1">Inactiva</span>
                                <span class="d-block small text-body-secondary">
                                    {{ catalog === 'zones' ? `${branches.filter((b) => b.zone_id === row.id).length} sedes` : `${row.users_count} personas` }}
                                </span>
                            </span>
                            <span v-if="canManage" class="text-nowrap">
                                <button type="button" class="btn btn-sm btn-outline-secondary me-1" :aria-label="`Editar ${row.name}`" @click="edit(catalog, row)"><i class="bi bi-pencil"></i></button>
                                <button type="button" class="btn btn-sm btn-outline-danger" :aria-label="`Eliminar ${row.name}`" @click="remove(catalog, row)"><i class="bi bi-trash"></i></button>
                            </span>
                        </li>
                        <li v-if="!(catalog === 'zones' ? zones : departments).length" class="list-group-item small text-body-secondary">
                            {{ catalog === 'zones' ? 'Aún no hay zonas. Crea Zona Norte, Centro, Sur…' : 'Aún no hay áreas.' }}
                        </li>
                    </ul>

                    <div v-if="catalog === 'departments' && canManage && missingSuggestions.length" class="card-body border-top">
                        <div class="small text-body-secondary mb-2">Áreas habituales (clic para agregar):</div>
                        <button v-for="name in missingSuggestions" :key="name" type="button" class="btn btn-sm btn-outline-secondary me-1 mb-1" @click="addSuggested(name)">
                            <i class="bi bi-plus"></i> {{ name }}
                        </button>
                    </div>

                    <form v-if="editing?.catalog === catalog" class="card-body border-top" @submit.prevent="save">
                        <div class="fw-semibold small mb-2">{{ editing.id ? `Editar ${meta.one}` : `Nueva ${meta.one}` }}</div>
                        <div class="mb-2">
                            <label :for="`${catalog}_name`" class="form-label small">Nombre</label>
                            <input :id="`${catalog}_name`" v-model="form.name" class="form-control" :class="{ 'is-invalid': form.errors.name }" maxlength="120" required>
                            <div v-if="form.errors.name" class="invalid-feedback">{{ form.errors.name }}</div>
                        </div>
                        <div class="mb-2">
                            <label :for="`${catalog}_code`" class="form-label small">Código (opcional)</label>
                            <input :id="`${catalog}_code`" v-model="form.code" class="form-control" :class="{ 'is-invalid': form.errors.code }" maxlength="30">
                            <div v-if="form.errors.code" class="invalid-feedback">{{ form.errors.code }}</div>
                        </div>
                        <div class="form-check form-switch mb-3">
                            <input :id="`${catalog}_active`" v-model="form.is_active" class="form-check-input" type="checkbox" role="switch">
                            <label class="form-check-label" :for="`${catalog}_active`">Activa</label>
                        </div>
                        <div class="d-flex justify-content-end gap-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary" @click="editing = null">Cancelar</button>
                            <button class="btn btn-sm btn-primary" :disabled="form.processing">Guardar</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.branch-cell { min-width: 220px; }
</style>
