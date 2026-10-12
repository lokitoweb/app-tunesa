<script setup>
import { computed, reactive, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import TenantBadge from '@/Modules/Core/Components/TenantBadge.vue';

const props = defineProps({
    zones: Array,
    departments: Array,
    positions: Array,
    branches: Array,
    branchTypes: Object,
    suggestedDepartments: Array,
    suggestedPositions: Array,
    canManage: Boolean,
    scopeLabel: String,
});

// --- Filtro en cascada: Zona → Sede → Área → Cargo ---
const NO_ZONE = 'none';
const filter = reactive({ zone: '', branch: '', department: '', position: '' });
const zoneName = (id) => props.zones.find((z) => z.id === id)?.name ?? 'Sin zona';
const departmentName = (id) => props.departments.find((d) => d.id === id)?.name ?? 'Sin área';
const positionById = computed(() => new Map(props.positions.map((p) => [p.id, p])));

const inZone = (branch) => filter.zone === '' || (filter.zone === NO_ZONE ? branch.zone_id === null : branch.zone_id === filter.zone);
const matchesStaff = (s) => (filter.department === '' || s.department_id === filter.department)
    && (filter.position === '' || s.position_id === filter.position);
const headcount = (branch) => branch.staff.filter(matchesStaff).reduce((sum, s) => sum + s.total, 0);
const plannedTotal = (branch) => branch.plan
    .filter((p) => filter.position === '' || p.position_id === filter.position)
    .filter((p) => filter.department === '' || positionById.value.get(p.position_id)?.department_id === filter.department)
    .reduce((sum, p) => sum + p.planned, 0);

// Al elegir un área, el filtro de cargo muestra solo los cargos de esa área
const filterPositions = computed(() => props.positions.filter((p) => filter.department === '' || p.department_id === filter.department));
function pickDepartment() {
    if (!filterPositions.value.some((p) => p.id === filter.position)) filter.position = '';
}

const zoneBranches = computed(() => props.branches.filter(inZone));
const filtering = computed(() => filter.department !== '' || filter.position !== '');
const visibleBranches = computed(() => zoneBranches.value
    .filter((b) => filter.branch === '' || b.id === filter.branch)
    .filter((b) => !filtering.value || headcount(b) > 0 || plannedTotal(b) > 0));

// Al cambiar de zona, la sede elegida puede quedar fuera
function pickZone() {
    if (!zoneBranches.value.some((b) => b.id === filter.branch)) filter.branch = '';
}

// Árbol: zonas (y "Sin zona") con sus sedes filtradas
const tree = computed(() => {
    const groups = [...props.zones.map((z) => ({ key: z.id, zone: z })), { key: null, zone: null }];
    return groups
        .map((g) => ({ ...g, branches: visibleBranches.value.filter((b) => b.zone_id === g.key) }))
        .filter((g) => g.branches.length || (g.zone && filter.branch === '' && !filtering.value && (filter.zone === '' || filter.zone === g.key)));
});

const totals = computed(() => ({
    branches: visibleBranches.value.length,
    people: visibleBranches.value.reduce((sum, b) => sum + headcount(b), 0),
    planned: visibleBranches.value.reduce((sum, b) => sum + plannedTotal(b), 0),
}));

// Detalle de una sede: dotación por área y puesto
const open = reactive(new Set());
const staffByDepartment = (branch) => {
    const groups = new Map();
    for (const s of branch.staff) {
        if (!matchesStaff(s)) continue;
        const g = groups.get(s.department_id) ?? { id: s.department_id, total: 0, positions: [] };
        g.total += s.total;
        g.positions.push({ name: s.position || 'Sin puesto', total: s.total });
        groups.set(s.department_id, g);
    }
    return [...groups.values()].sort((a, b) => b.total - a.total);
};

// --- Plantilla de la sede: cargos y plazas previstas frente a las reales ---
// Borrador por sede mientras se edita: [{ position_id, planned }]
const drafts = reactive({});
const staffingErrors = reactive({});
const actualByPosition = (branch) => {
    const counts = new Map();
    for (const s of branch.staff) if (s.position_id) counts.set(s.position_id, (counts.get(s.position_id) ?? 0) + s.total);
    return counts;
};
const plantilla = (branch) => {
    const plan = drafts[branch.id] ?? branch.plan;
    const actual = actualByPosition(branch);
    const rows = plan.map((p) => ({ position_id: p.position_id, planned: p.planned, actual: actual.get(p.position_id) ?? 0, inPlan: true }));
    for (const [id, total] of actual) if (!plan.some((p) => p.position_id === id)) rows.push({ position_id: id, planned: 0, actual: total, inPlan: false });
    return rows
        .filter((r) => filter.position === '' || r.position_id === filter.position)
        .filter((r) => filter.department === '' || positionById.value.get(r.position_id)?.department_id === filter.department)
        .sort((a, b) => (positionById.value.get(a.position_id)?.name ?? '').localeCompare(positionById.value.get(b.position_id)?.name ?? ''));
};
// Personal cuyo cargo aún no está en el catálogo (escrito a mano)
const uncatalogued = (branch) => branch.staff.filter((s) => !s.position_id && matchesStaff(s));
function gapBadge(row) {
    const gap = row.planned - row.actual;
    if (row.planned === 0 && row.actual === 0) return { cls: 'text-bg-light', text: 'Sin plazas' };
    if (gap > 0) return { cls: 'text-bg-warning', text: gap === 1 ? 'Falta 1' : `Faltan ${gap}` };
    if (gap < 0) return { cls: 'text-bg-info', text: `${-gap} sobre lo previsto` };
    return { cls: 'text-bg-success', text: 'Completo' };
}
function startDraft(branch) {
    if (!drafts[branch.id]) drafts[branch.id] = branch.plan.map((p) => ({ ...p }));
    return drafts[branch.id];
}
function setPlanned(branch, positionId, value) {
    const draft = startDraft(branch);
    const planned = Math.max(0, Math.min(9999, parseInt(value, 10) || 0));
    const row = draft.find((p) => p.position_id === positionId);
    row ? (row.planned = planned) : draft.push({ position_id: positionId, planned });
}
function removeFromPlan(branch, positionId) {
    drafts[branch.id] = startDraft(branch).filter((p) => p.position_id !== positionId);
}
const addable = (branch) => {
    const plan = drafts[branch.id] ?? branch.plan;
    return props.positions.filter((p) => p.is_active && !plan.some((r) => r.position_id === p.id));
};
function addToPlan(branch, event) {
    const id = Number(event.target.value);
    event.target.value = '';
    if (id) setPlanned(branch, id, 1);
}
function saveStaffing(branch) {
    const positions = drafts[branch.id].map((p) => ({ position_id: p.position_id, planned_headcount: p.planned }));
    router.put(`/organization/branches/${branch.id}/positions`, { positions }, {
        preserveScroll: true,
        onSuccess: () => { delete drafts[branch.id]; delete staffingErrors[branch.id]; },
        onError: (errors) => { staffingErrors[branch.id] = Object.values(errors)[0]; },
    });
}
function cancelStaffing(branch) {
    delete drafts[branch.id];
    delete staffingErrors[branch.id];
}

// --- Catálogos: zonas, áreas y cargos (mismo formulario; los cargos llevan área e itinerancia) ---
const CATALOGS = {
    zones: { one: 'zona', title: 'Zonas', icon: 'bi-diagram-3', isNew: 'Nueva' },
    departments: { one: 'área', title: 'Áreas', icon: 'bi-diagram-2', isNew: 'Nueva' },
    positions: { one: 'cargo', title: 'Cargos', icon: 'bi-person-badge', isNew: 'Nuevo' },
};
const rowsOf = (catalog) => ({ zones: props.zones, departments: props.departments, positions: props.positions })[catalog];
const editing = ref(null); // { catalog, id }
const form = useForm({ name: '', code: '', is_active: true, department_id: null, description: '', is_roaming: false });

function edit(catalog, row = null) {
    editing.value = { catalog, id: row?.id ?? null };
    form.clearErrors();
    form.name = row?.name ?? '';
    form.code = row?.code ?? '';
    form.is_active = row?.is_active ?? true;
    form.department_id = row?.department_id ?? null;
    form.description = row?.description ?? '';
    form.is_roaming = row?.is_roaming ?? false;
}
function save() {
    const { catalog, id } = editing.value;
    const options = { preserveScroll: true, onSuccess: () => { editing.value = null; } };
    id ? form.put(`/organization/${catalog}/${id}`, options) : form.post(`/organization/${catalog}`, options);
}
function remove(catalog, row) {
    const effect = {
        zones: 'Sus sedes quedarán sin zona.',
        departments: 'Sus colaboradores quedarán sin área.',
        positions: 'Sale de la plantilla de cada sede; sus colaboradores conservan el nombre del cargo como texto.',
    }[catalog];
    if (confirm(`¿Eliminar ${CATALOGS[catalog].one} «${row.name}»? ${effect}`)) router.delete(`/organization/${catalog}/${row.id}`, { preserveScroll: true });
}
function addSuggested(catalog, names) {
    if (catalog === 'positions') {
        router.post('/organization/positions/suggested', { names }, { preserveScroll: true });
    } else {
        router.post('/organization/departments', { name: names[0], code: null, is_active: true }, { preserveScroll: true });
    }
}
const sameName = (a, b) => a.toLocaleLowerCase() === b.toLocaleLowerCase();
const missingSuggestions = computed(() => ({
    departments: props.suggestedDepartments.filter((n) => !props.departments.some((d) => sameName(d.name, n))),
    positions: props.suggestedPositions.filter((n) => !props.positions.some((p) => sameName(p.name, n))),
}));
const plural = (n, one, many) => `${n} ${n === 1 ? one : many}`;
const catalogDetail = (catalog, row) => {
    if (catalog === 'zones') return plural(props.branches.filter((b) => b.zone_id === row.id).length, 'sede', 'sedes');
    const people = plural(row.users_count, 'persona', 'personas');
    if (catalog === 'departments') return people;
    return [row.department_id ? departmentName(row.department_id) : null, people].filter(Boolean).join(' · ');
};

function updateBranch(branch, changes) {
    router.patch(`/organization/branches/${branch.id}`, { zone_id: branch.zone_id, branch_type: branch.type, ...changes }, { preserveScroll: true });
}
</script>

<template>
    <AppLayout title="Organización">
        <div class="page-header">
            <div>
                <h1>Organización</h1>
                <p>Zonas, sedes, áreas y cargos de la empresa, con la plantilla prevista y la dotación activa de cada sede.</p>
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
                    <div class="col-12 col-sm-6 col-lg-2">
                        <label for="f_zone" class="form-label small">Zona</label>
                        <select id="f_zone" v-model="filter.zone" class="form-select" @change="pickZone">
                            <option value="">Todas las zonas</option>
                            <option v-for="z in zones" :key="z.id" :value="z.id">{{ z.name }}</option>
                            <option :value="NO_ZONE">Sin zona</option>
                        </select>
                    </div>
                    <div class="col-12 col-sm-6 col-lg-3">
                        <label for="f_branch" class="form-label small">Sede</label>
                        <select id="f_branch" v-model="filter.branch" class="form-select">
                            <option value="">Todas las sedes{{ filter.zone !== '' ? ' de la zona' : '' }}</option>
                            <option v-for="b in zoneBranches" :key="b.id" :value="b.id">{{ b.name }}</option>
                        </select>
                    </div>
                    <div class="col-12 col-sm-6 col-lg-2">
                        <label for="f_department" class="form-label small">Área</label>
                        <select id="f_department" v-model="filter.department" class="form-select" @change="pickDepartment">
                            <option value="">Todas las áreas</option>
                            <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
                            <option :value="null">Sin área</option>
                        </select>
                    </div>
                    <div class="col-12 col-sm-6 col-lg-3">
                        <label for="f_position" class="form-label small">Cargo</label>
                        <select id="f_position" v-model="filter.position" class="form-select" :disabled="!positions.length">
                            <option value="">Todos los cargos</option>
                            <option v-for="p in filterPositions" :key="p.id" :value="p.id">{{ p.name }}</option>
                        </select>
                    </div>
                    <div class="col-12 col-lg-2 text-lg-end small text-body-secondary" aria-live="polite">
                        <strong class="text-body">{{ totals.branches }}</strong> sedes · <strong class="text-body">{{ totals.people }}</strong> personas
                        <span v-if="totals.planned" class="d-block">de <strong class="text-body">{{ totals.planned }}</strong> plazas previstas</span>
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
                    <div class="table-responsive org-table">
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
                                        <td class="text-end text-nowrap">
                                            {{ headcount(b) }}<span v-if="plannedTotal(b)" class="text-body-secondary small"> / {{ plannedTotal(b) }}</span>
                                        </td>
                                        <td class="text-end text-nowrap">{{ b.radius }} m</td>
                                        <td class="text-end text-nowrap">
                                            <a :href="`/branches/${b.id}/edit`" class="btn btn-sm btn-outline-secondary" :title="`Mapa y geocerca de ${b.name}`"><i class="bi bi-geo-alt"></i> Mapa</a>
                                        </td>
                                    </tr>
                                    <tr v-if="open.has(b.id)">
                                        <td :colspan="canManage ? 6 : 5" class="bg-body-tertiary p-3">
                                            <div class="staffing-panel">
                                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                                                <div class="small fw-semibold"><i class="bi bi-people me-1"></i>Plantilla por cargo</div>
                                                <select v-if="canManage && addable(b).length" class="form-select form-select-sm w-auto mw-100" :aria-label="`Agregar cargo a ${b.name}`" @change="addToPlan(b, $event)">
                                                    <option value="">+ Agregar cargo a la sede</option>
                                                    <option v-for="p in addable(b)" :key="p.id" :value="p.id">{{ p.name }}</option>
                                                </select>
                                            </div>

                                            <div v-if="plantilla(b).length" class="table-responsive">
                                                <table class="table table-sm align-middle mb-2">
                                                    <thead>
                                                        <tr><th>Cargo</th><th class="d-none d-xl-table-cell">Área</th><th class="text-end">Previstas</th><th class="text-end">Actuales</th><th>Estado</th><th v-if="canManage"></th></tr>
                                                    </thead>
                                                    <tbody>
                                                        <tr v-for="row in plantilla(b)" :key="row.position_id">
                                                            <td>
                                                                {{ positionById.get(row.position_id)?.name ?? 'Cargo eliminado' }}
                                                                <i v-if="positionById.get(row.position_id)?.is_roaming" class="bi bi-truck text-body-secondary ms-1" title="Itinerante: marca en ruta o en cualquier sede"></i>
                                                            </td>
                                                            <td class="d-none d-xl-table-cell small text-body-secondary">{{ departmentName(positionById.get(row.position_id)?.department_id ?? null) }}</td>
                                                            <td class="text-end">
                                                                <input v-if="canManage" type="number" min="0" max="9999" class="form-control form-control-sm text-end planned-input ms-auto" :value="row.planned" :aria-label="`Plazas previstas de ${positionById.get(row.position_id)?.name} en ${b.name}`" @input="setPlanned(b, row.position_id, $event.target.value)">
                                                                <template v-else>{{ row.planned }}</template>
                                                            </td>
                                                            <td class="text-end">{{ row.actual }}</td>
                                                            <td><span class="badge" :class="gapBadge(row).cls">{{ gapBadge(row).text }}</span></td>
                                                            <td v-if="canManage" class="text-end">
                                                                <button v-if="row.inPlan" type="button" class="btn btn-sm btn-link text-danger p-0" :aria-label="`Quitar ${positionById.get(row.position_id)?.name} de la plantilla`" @click="removeFromPlan(b, row.position_id)"><i class="bi bi-x-lg"></i></button>
                                                            </td>
                                                        </tr>
                                                    </tbody>
                                                </table>
                                            </div>
                                            <div v-else class="small text-body-secondary mb-2">
                                                {{ positions.length ? 'Esta sede aún no tiene cargos en su plantilla.' : 'Primero crea el catálogo de cargos en el panel de la derecha.' }}
                                            </div>

                                            <div v-if="drafts[b.id]" class="d-flex flex-wrap justify-content-end align-items-center gap-2 mb-2">
                                                <span v-if="staffingErrors[b.id]" class="small text-danger me-auto">{{ staffingErrors[b.id] }}</span>
                                                <button type="button" class="btn btn-sm btn-outline-secondary" @click="cancelStaffing(b)">Descartar</button>
                                                <button type="button" class="btn btn-sm btn-primary" @click="saveStaffing(b)">Guardar plantilla</button>
                                            </div>

                                            <div v-if="uncatalogued(b).length" class="small text-body-secondary border-top pt-2">
                                                <i class="bi bi-info-circle me-1"></i>Con cargo escrito a mano (asígnales uno del catálogo en su ficha):
                                                {{ uncatalogued(b).map((s) => `${s.position || 'Sin cargo'} (${s.total})`).join(', ') }}
                                            </div>
                                            <details v-if="staffByDepartment(b).length" class="small mt-2">
                                                <summary class="text-body-secondary">Dotación por área</summary>
                                                <div class="row g-2 mt-1">
                                                    <div v-for="dep in staffByDepartment(b)" :key="dep.id ?? 'none'" class="col-12 col-md-6">
                                                        <div class="fw-semibold">{{ departmentName(dep.id) }} <span class="text-body-secondary fw-normal">· {{ dep.total }}</span></div>
                                                        <ul class="list-unstyled text-body-secondary mb-0">
                                                            <li v-for="p in dep.positions" :key="p.name">{{ p.name }}: {{ p.total }}</li>
                                                        </ul>
                                                    </div>
                                                </div>
                                            </details>
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
                        <h2 class="h6 fw-semibold mb-0"><i class="bi me-1 text-body-secondary" :class="meta.icon"></i>{{ meta.title }}</h2>
                        <button v-if="canManage" type="button" class="btn btn-sm btn-outline-primary" @click="edit(catalog)"><i class="bi bi-plus-lg"></i> {{ meta.isNew }}</button>
                    </div>
                    <ul class="list-group list-group-flush">
                        <li v-for="row in rowsOf(catalog)" :key="row.id" class="list-group-item d-flex justify-content-between align-items-center gap-2">
                            <span class="min-w-0">
                                <span class="fw-medium">{{ row.name }}</span>
                                <span v-if="row.code" class="small text-body-secondary ms-1">{{ row.code }}</span>
                                <span v-if="!row.is_active" class="badge text-bg-secondary ms-1">{{ catalog === 'positions' ? 'Inactivo' : 'Inactiva' }}</span>
                                <span v-if="row.is_roaming" class="badge text-bg-light ms-1" title="Marca en ruta o en cualquier sede"><i class="bi bi-truck"></i> Itinerante</span>
                                <span class="d-block small text-body-secondary">{{ catalogDetail(catalog, row) }}</span>
                            </span>
                            <span v-if="canManage" class="text-nowrap">
                                <button type="button" class="btn btn-sm btn-outline-secondary me-1" :aria-label="`Editar ${row.name}`" @click="edit(catalog, row)"><i class="bi bi-pencil"></i></button>
                                <button type="button" class="btn btn-sm btn-outline-danger" :aria-label="`Eliminar ${row.name}`" @click="remove(catalog, row)"><i class="bi bi-trash"></i></button>
                            </span>
                        </li>
                        <li v-if="!rowsOf(catalog).length" class="list-group-item small text-body-secondary">
                            {{ { zones: 'Aún no hay zonas. Crea Zona Norte, Centro, Sur…', departments: 'Aún no hay áreas.', positions: 'Aún no hay cargos. Agrega los habituales de abajo o crea los tuyos.' }[catalog] }}
                        </li>
                    </ul>

                    <div v-if="catalog !== 'zones' && canManage && missingSuggestions[catalog].length" class="card-body border-top">
                        <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
                            <span class="small text-body-secondary">{{ catalog === 'positions' ? 'Cargos habituales' : 'Áreas habituales' }} (clic para agregar):</span>
                            <button v-if="catalog === 'positions' && missingSuggestions.positions.length > 1" type="button" class="btn btn-sm btn-link p-0 text-nowrap" @click="addSuggested('positions', missingSuggestions.positions)">Agregar todos</button>
                        </div>
                        <button v-for="name in missingSuggestions[catalog]" :key="name" type="button" class="btn btn-sm btn-outline-secondary me-1 mb-1" @click="addSuggested(catalog, [name])">
                            <i class="bi bi-plus"></i> {{ name }}
                        </button>
                    </div>

                    <form v-if="editing?.catalog === catalog" class="card-body border-top" @submit.prevent="save">
                        <div class="fw-semibold small mb-2">{{ editing.id ? `Editar ${meta.one}` : `${meta.isNew} ${meta.one}` }}</div>
                        <div class="mb-2">
                            <label :for="`${catalog}_name`" class="form-label small">Nombre</label>
                            <input :id="`${catalog}_name`" v-model="form.name" class="form-control" :class="{ 'is-invalid': form.errors.name }" :maxlength="catalog === 'positions' ? 100 : 120" required>
                            <div v-if="form.errors.name" class="invalid-feedback">{{ form.errors.name }}</div>
                        </div>
                        <div class="mb-2">
                            <label :for="`${catalog}_code`" class="form-label small">Código (opcional)</label>
                            <input :id="`${catalog}_code`" v-model="form.code" class="form-control" :class="{ 'is-invalid': form.errors.code }" maxlength="30">
                            <div v-if="form.errors.code" class="invalid-feedback">{{ form.errors.code }}</div>
                        </div>
                        <template v-if="catalog === 'positions'">
                            <div class="mb-2">
                                <label for="positions_department" class="form-label small">Área</label>
                                <select id="positions_department" v-model="form.department_id" class="form-select" :class="{ 'is-invalid': form.errors.department_id }">
                                    <option :value="null">Sin área</option>
                                    <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
                                </select>
                                <div v-if="form.errors.department_id" class="invalid-feedback">{{ form.errors.department_id }}</div>
                            </div>
                            <div class="mb-2">
                                <label for="positions_description" class="form-label small">Funciones (opcional)</label>
                                <textarea id="positions_description" v-model="form.description" class="form-control" :class="{ 'is-invalid': form.errors.description }" rows="2" maxlength="255"></textarea>
                                <div v-if="form.errors.description" class="invalid-feedback">{{ form.errors.description }}</div>
                            </div>
                            <div class="form-check form-switch mb-2">
                                <input id="positions_roaming" v-model="form.is_roaming" class="form-check-input" type="checkbox" role="switch">
                                <label class="form-check-label" for="positions_roaming">Itinerante</label>
                                <div class="form-text">Choferes y reparto: al asignar el cargo se sugiere la marcación en ruta.</div>
                            </div>
                        </template>
                        <div class="form-check form-switch mb-3">
                            <input :id="`${catalog}_active`" v-model="form.is_active" class="form-check-input" type="checkbox" role="switch">
                            <label class="form-check-label" :for="`${catalog}_active`">{{ catalog === 'positions' ? 'Activo' : 'Activa' }}</label>
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
.planned-input { width: 5rem; }
/* El detalle de la sede ocupa el ancho visible aunque la tabla de sedes se desplace en horizontal */
.org-table { container-type: inline-size; }
.staffing-panel { position: sticky; left: 1rem; width: calc(100cqw - 2rem); }
</style>
