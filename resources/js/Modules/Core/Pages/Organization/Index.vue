<script setup>
import { computed, reactive, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import TenantBadge from '@/Modules/Core/Components/TenantBadge.vue';
import { useConfirm } from '@/Modules/Core/Composables/useConfirm';

const props = defineProps({
    zones: {
        type: Array,
        default: () => [],
    },
    departments: {
        type: Array,
        default: () => [],
    },
    positions: {
        type: Array,
        default: () => [],
    },
    branches: {
        type: Array,
        default: () => [],
    },
    supervisors: {
        type: Array,
        default: () => [],
    },
    defaultRadius: {
        type: Number,
        default: 100,
    },
    branchTypes: {
        type: Object,
        default: () => ({}),
    },
    suggestedDepartments: {
        type: Array,
        default: () => [],
    },
    suggestedPositions: {
        type: Array,
        default: () => [],
    },
    canManage: {
        type: Boolean,
        default: false,
    },
    scopeLabel: {
        type: String,
        default: null,
    },
});

const { ask } = useConfirm();

// =========================================================================
// PESTAÑAS PRINCIPALES DEL MÓDULO (SEDES & PLANTILLA vs CATÁLOGOS)
// =========================================================================
const activeMainTab = ref('sedes'); // 'sedes' | 'catalogs'

// =========================================================================
// FILTROS EN CASCADA Y BÚSQUEDA GENERAL
// =========================================================================
const NO_ZONE = 'none';
const filter = reactive({
    search: '',
    zone: '',
    branch: '',
    department: '',
    position: '',
    type: '',
});

const zoneName = (id) => props.zones.find((z) => z.id === id)?.name ?? 'Sin zona';
const departmentName = (id) => props.departments.find((d) => d.id === id)?.name ?? 'Sin área';
const positionById = computed(() => new Map(props.positions.map((p) => [p.id, p])));

const inZone = (branch) => filter.zone === '' || (filter.zone === NO_ZONE ? branch.zone_id === null : branch.zone_id === filter.zone);
const inType = (branch) => filter.type === '' || branch.type === filter.type;

const matchesSearch = (branch) => {
    const q = (filter.search || '').trim().toLowerCase();
    if (!q) return true;
    return (
        (branch.name && branch.name.toLowerCase().includes(q)) ||
        (branch.code && branch.code.toLowerCase().includes(q)) ||
        (branch.city && branch.city.toLowerCase().includes(q)) ||
        (branch.supervisor && branch.supervisor.toLowerCase().includes(q))
    );
};

const matchesStaff = (s) => (filter.department === '' || s.department_id === filter.department)
    && (filter.position === '' || s.position_id === filter.position);

const headcount = (branch) => branch.staff.filter(matchesStaff).reduce((sum, s) => sum + s.total, 0);
const plannedTotal = (branch) => (branch.plan || [])
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

const visibleBranches = computed(() => props.branches
    .filter(inZone)
    .filter(inType)
    .filter(matchesSearch)
    .filter((b) => filter.branch === '' || b.id === filter.branch)
    .filter((b) => !filtering.value || headcount(b) > 0 || plannedTotal(b) > 0));

function pickZone() {
    if (!zoneBranches.value.some((b) => b.id === filter.branch)) filter.branch = '';
}

function resetFilters() {
    filter.search = '';
    filter.zone = '';
    filter.branch = '';
    filter.department = '';
    filter.position = '';
    filter.type = '';
}

const hasActiveFilters = computed(() => Boolean(
    filter.search || filter.zone || filter.branch || filter.department || filter.position || filter.type
));

// Árbol: zonas (y "Sin zona") con sus sedes filtradas
const tree = computed(() => {
    const groups = [...props.zones.map((z) => ({ key: z.id, zone: z })), { key: null, zone: null }];
    return groups
        .map((g) => ({ ...g, branches: visibleBranches.value.filter((b) => b.zone_id === g.key) }))
        .filter((g) => g.branches.length || (g.zone && filter.branch === '' && !filtering.value && !filter.search && (filter.zone === '' || filter.zone === g.key)));
});

// KPIs y Métricas
const totals = computed(() => {
    const activeBranches = props.branches.filter((b) => b.is_active).length;
    const people = visibleBranches.value.reduce((sum, b) => sum + headcount(b), 0);
    const planned = visibleBranches.value.reduce((sum, b) => sum + plannedTotal(b), 0);
    const coverage = planned > 0 ? Math.round((people / planned) * 100) : 100;
    return {
        branches: visibleBranches.value.length,
        totalBranches: props.branches.length,
        activeBranches,
        people,
        planned,
        coverage,
    };
});

// Detalle colapsable de una sede en la tabla
const openBranches = reactive(new Set());
function toggleBranch(id) {
    if (openBranches.has(id)) {
        openBranches.delete(id);
    } else {
        openBranches.add(id);
    }
}

function expandAllBranches() {
    visibleBranches.value.forEach((b) => openBranches.add(b.id));
}

function collapseAllBranches() {
    openBranches.clear();
}

const staffByDepartment = (branch) => {
    const groups = new Map();
    for (const s of branch.staff) {
        if (!matchesStaff(s)) continue;
        const g = groups.get(s.department_id) ?? { id: s.department_id, total: 0, positions: [] };
        g.total += s.total;
        g.positions.push({ name: s.position || 'Sin cargo', total: s.total });
        groups.set(s.department_id, g);
    }
    return [...groups.values()].sort((a, b) => b.total - a.total);
};

// Plantilla prevista vs real
const actualByPosition = (branch) => {
    const counts = new Map();
    for (const s of branch.staff) {
        if (s.position_id) counts.set(s.position_id, (counts.get(s.position_id) ?? 0) + s.total);
    }
    return counts;
};

const plantilla = (branch) => {
    const plan = branch.plan || [];
    const actual = actualByPosition(branch);
    const rows = plan.map((p) => ({
        position_id: p.position_id,
        planned: p.planned,
        actual: actual.get(p.position_id) ?? 0,
        inPlan: true,
    }));
    for (const [id, total] of actual) {
        if (!plan.some((p) => p.position_id === id)) {
            rows.push({ position_id: id, planned: 0, actual: total, inPlan: false });
        }
    }
    return rows
        .filter((r) => filter.position === '' || r.position_id === filter.position)
        .filter((r) => filter.department === '' || positionById.value.get(r.position_id)?.department_id === filter.department)
        .sort((a, b) => (positionById.value.get(a.position_id)?.name ?? '').localeCompare(positionById.value.get(b.position_id)?.name ?? ''));
};

const uncatalogued = (branch) => branch.staff.filter((s) => !s.position_id && matchesStaff(s));

function gapBadge(row) {
    const gap = row.planned - row.actual;
    if (row.planned === 0 && row.actual === 0) {
        return { cls: 'badge bg-secondary-subtle text-secondary border border-secondary-subtle', text: 'Sin plazas' };
    }
    if (gap > 0) {
        return { cls: 'badge bg-warning-subtle text-warning-emphasis border border-warning-subtle', text: gap === 1 ? 'Falta 1 plaza' : `Faltan ${gap} plazas` };
    }
    if (gap < 0) {
        return { cls: 'badge bg-info-subtle text-info border border-info-subtle', text: `${-gap} sobre previsto` };
    }
    return { cls: 'badge bg-success-subtle text-success border border-success-subtle', text: 'Cubierto 100%' };
}

function updateBranchInline(branch, changes) {
    router.patch(`/organization/branches/${branch.id}`, {
        zone_id: branch.zone_id,
        branch_type: branch.type,
        ...changes,
    }, { preserveScroll: true });
}

// =========================================================================
// 1. MODAL DIALOG: NUEVA SEDE / EDITAR SEDE (CON GEOCERCA GPS Y SUPERVISOR)
// =========================================================================
const showBranchModal = ref(false);
const editingBranch = ref(null);

const branchForm = useForm({
    name: '',
    code: '',
    branch_type: 'agency',
    zone_id: null,
    supervisor_id: null,
    city: '',
    address: '',
    latitude: -12.046374,
    longitude: -77.042793,
    allowed_radius_meters: 100,
    is_active: true,
});

function openCreateBranchModal() {
    editingBranch.value = null;
    branchForm.clearErrors();
    branchForm.reset();
    branchForm.name = '';
    branchForm.code = '';
    branchForm.branch_type = Object.keys(props.branchTypes)[0] || 'agency';
    branchForm.zone_id = filter.zone && filter.zone !== NO_ZONE ? Number(filter.zone) : null;
    branchForm.supervisor_id = null;
    branchForm.city = '';
    branchForm.address = '';
    branchForm.latitude = -12.046374;
    branchForm.longitude = -77.042793;
    branchForm.allowed_radius_meters = props.defaultRadius || 100;
    branchForm.is_active = true;
    showBranchModal.value = true;
}

function openEditBranchModal(b) {
    editingBranch.value = b;
    branchForm.clearErrors();
    branchForm.name = b.name || '';
    branchForm.code = b.code || '';
    branchForm.branch_type = b.type || 'agency';
    branchForm.zone_id = b.zone_id ?? null;
    branchForm.supervisor_id = b.supervisor_id ?? null;
    branchForm.city = b.city || '';
    branchForm.address = b.address || '';
    branchForm.latitude = b.latitude ? Number(b.latitude) : -12.046374;
    branchForm.longitude = b.longitude ? Number(b.longitude) : -77.042793;
    branchForm.allowed_radius_meters = b.radius || props.defaultRadius || 100;
    branchForm.is_active = Boolean(b.is_active);
    showBranchModal.value = true;
}

function closeBranchModal() {
    showBranchModal.value = false;
    editingBranch.value = null;
    branchForm.clearErrors();
}

function setRadiusPreset(radius) {
    branchForm.allowed_radius_meters = radius;
}

// =========================================================================
// SISTEMA DE NOTIFICACIONES TOAST MODERNO (REEMPLAZO DE ALERTS NATIVOS)
// =========================================================================
const toast = ref({
    show: false,
    message: '',
    type: 'info', // 'success' | 'warning' | 'danger' | 'info'
});
const toastIcons = {
    success: 'bi-check-lg',
    warning: 'bi-exclamation-triangle-fill',
    danger: 'bi-x-circle-fill',
    info: 'bi-info-circle-fill',
};
const toastTitles = {
    success: 'Listo',
    warning: 'Atención',
    danger: 'Error',
    info: 'Aviso',
};
let toastTimer = null;
function notify(message, type = 'info') {
    if (toastTimer) clearTimeout(toastTimer);
    toast.value = { show: true, message, type };
    toastTimer = setTimeout(() => {
        toast.value.show = false;
    }, 4500);
}

const isDetectingGps = ref(false);
function detectCurrentLocation() {
    if (!navigator.geolocation) {
        notify('Tu navegador no soporta geolocalización.', 'warning');
        return;
    }
    isDetectingGps.value = true;
    navigator.geolocation.getCurrentPosition(
        (pos) => {
            isDetectingGps.value = false;
            branchForm.latitude = Number(pos.coords.latitude.toFixed(6));
            branchForm.longitude = Number(pos.coords.longitude.toFixed(6));
            notify('Coordenadas GPS obtenidas con éxito.', 'success');
        },
        () => {
            isDetectingGps.value = false;
            notify('No se pudo obtener la ubicación GPS actual. Revisa los permisos de ubicación en tu navegador.', 'warning');
        },
        { enableHighAccuracy: true, timeout: 10000 }
    );
}

function submitBranchModal() {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            closeBranchModal();
        },
    };

    if (editingBranch.value) {
        branchForm.put(`/branches/${editingBranch.value.id}`, options);
    } else {
        branchForm.post('/branches', options);
    }
}

async function confirmDeleteBranch(branch) {
    const ok = await ask({
        title: `¿Eliminar sede «${branch.name}»?`,
        message: branch.employees > 0
            ? `Esta sede tiene ${branch.employees} colaboradores asignados. Debes reasignarlos antes de poder eliminarla.`
            : `¿Estás seguro de eliminar permanentemente la sede «${branch.name}»? Esta acción no se puede deshacer.`,
        confirmText: 'Sí, eliminar',
        cancelText: 'Cancelar',
        variant: 'danger',
    });

    if (ok) {
        router.delete(`/branches/${branch.id}`, { preserveScroll: true });
    }
}

// =========================================================================
// 2. MODAL DIALOG: GESTIÓN DE PLANTILLA PREVISTA DE LA SEDE
// =========================================================================
const showStaffingModal = ref(false);
const activeStaffingBranch = ref(null);
const staffingDraft = ref([]);
const staffingErrors = ref('');
const isSavingStaffing = ref(false);

function openStaffingModal(branch) {
    activeStaffingBranch.value = branch;
    staffingDraft.value = (branch.plan || []).map((p) => ({ ...p }));
    staffingErrors.value = '';
    showStaffingModal.value = true;
}

function closeStaffingModal() {
    showStaffingModal.value = false;
    activeStaffingBranch.value = null;
    staffingDraft.value = [];
    staffingErrors.value = '';
}

const addablePositions = computed(() => {
    if (!activeStaffingBranch.value) return [];
    const inPlanIds = new Set(staffingDraft.value.map((r) => r.position_id));
    return props.positions.filter((p) => p.is_active && !inPlanIds.has(p.id));
});

function addPositionToStaffing(event) {
    const id = Number(event.target.value);
    event.target.value = '';
    if (!id) return;
    const existing = staffingDraft.value.find((p) => p.position_id === id);
    if (!existing) {
        staffingDraft.value.push({ position_id: id, planned: 1 });
    }
}

function setPlannedInModal(positionId, value) {
    const planned = Math.max(0, Math.min(9999, parseInt(value, 10) || 0));
    const row = staffingDraft.value.find((p) => p.position_id === positionId);
    if (row) {
        row.planned = planned;
    } else {
        staffingDraft.value.push({ position_id: positionId, planned });
    }
}

function removePositionFromStaffing(positionId) {
    staffingDraft.value = staffingDraft.value.filter((p) => p.position_id !== positionId);
}

function saveStaffingModal() {
    if (!activeStaffingBranch.value) return;
    isSavingStaffing.value = true;
    staffingErrors.value = '';

    const payload = staffingDraft.value.map((p) => ({
        position_id: p.position_id,
        planned_headcount: p.planned,
    }));

    router.put(`/organization/branches/${activeStaffingBranch.value.id}/positions`, { positions: payload }, {
        preserveScroll: true,
        onSuccess: () => {
            isSavingStaffing.value = false;
            closeStaffingModal();
        },
        onError: (errors) => {
            isSavingStaffing.value = false;
            staffingErrors.value = Object.values(errors)[0] || 'Error al guardar plantilla.';
        },
    });
}

// =========================================================================
// 3. CATÁLOGOS ORGANIZACIONALES (ÁREAS, CARGOS, ZONAS)
// =========================================================================
const CATALOGS = {
    departments: { one: 'área', title: 'Áreas', icon: 'bi-diagram-2', isNew: 'Nueva Área', color: '#6366f1' },
    positions: { one: 'cargo', title: 'Cargos', icon: 'bi-person-badge', isNew: 'Nuevo Cargo', color: '#0284c7' },
    zones: { one: 'zona', title: 'Zonas', icon: 'bi-diagram-3', isNew: 'Nueva Zona', color: '#059669' },
};

const activeCatalogTab = ref('positions');
const catalogSearchQuery = ref('');
const catalogDepartmentFilter = ref('');

const rowsOf = (catalog) => ({
    zones: props.zones,
    departments: props.departments,
    positions: props.positions,
})[catalog] || [];

const filteredCatalogRows = computed(() => {
    let list = rowsOf(activeCatalogTab.value) || [];
    if (activeCatalogTab.value === 'positions' && catalogDepartmentFilter.value) {
        list = list.filter((r) => r.department_id === Number(catalogDepartmentFilter.value));
    }
    const q = (catalogSearchQuery.value || '').trim().toLowerCase();
    if (!q) return list;
    return list.filter((r) => {
        return (
            (r.name && r.name.toLowerCase().includes(q)) ||
            (r.code && r.code.toLowerCase().includes(q)) ||
            (r.description && r.description.toLowerCase().includes(q))
        );
    });
});

// MODAL DIALOG: CREAR O EDITAR CATÁLOGO
const showCatalogModal = ref(false);
const editingCatalog = ref(null); // 'zones' | 'departments' | 'positions'
const editingCatalogId = ref(null);

const catalogForm = useForm({
    name: '',
    code: '',
    is_active: true,
    department_id: null,
    head_id: null,
    description: '',
    is_roaming: false,
});

function openCreateCatalogModal(catalog) {
    editingCatalog.value = catalog;
    editingCatalogId.value = null;
    catalogForm.clearErrors();
    catalogForm.reset();
    catalogForm.name = '';
    catalogForm.code = '';
    catalogForm.is_active = true;
    catalogForm.department_id = null;
    catalogForm.head_id = null;
    catalogForm.description = '';
    catalogForm.is_roaming = false;
    showCatalogModal.value = true;
}

function openEditCatalogModal(catalog, row) {
    editingCatalog.value = catalog;
    editingCatalogId.value = row.id;
    catalogForm.clearErrors();
    catalogForm.name = row.name ?? '';
    catalogForm.code = row.code ?? '';
    catalogForm.is_active = Boolean(row.is_active);
    catalogForm.department_id = row.department_id ?? null;
    catalogForm.head_id = row.head_id ?? null;
    catalogForm.description = row.description ?? '';
    catalogForm.is_roaming = Boolean(row.is_roaming);
    showCatalogModal.value = true;
}

function quickCreatePositionFromUncatalogued(name) {
    openCreateCatalogModal('positions');
    catalogForm.name = name;
}

function closeCatalogModal() {
    showCatalogModal.value = false;
    editingCatalog.value = null;
    editingCatalogId.value = null;
    catalogForm.clearErrors();
}

function submitCatalogModal() {
    if (!editingCatalog.value) return;
    const cat = editingCatalog.value;
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            closeCatalogModal();
        },
    };

    if (editingCatalogId.value) {
        catalogForm.put(`/organization/${cat}/${editingCatalogId.value}`, options);
    } else {
        catalogForm.post(`/organization/${cat}`, options);
    }
}

async function removeCatalogItem(catalog, row) {
    const effect = {
        zones: 'Sus sedes quedarán sin zona asignada.',
        departments: 'Sus colaboradores quedarán sin área asignada.',
        positions: 'Saldrá de la plantilla de cada sede; sus colaboradores conservarán el nombre del cargo como texto libre.',
    }[catalog];

    const ok = await ask({
        title: `¿Eliminar ${CATALOGS[catalog].one}?`,
        message: `¿Estás seguro de eliminar «${row.name}»? ${effect}`,
        confirmText: 'Sí, eliminar',
        cancelText: 'Cancelar',
        variant: 'danger',
    });

    if (ok) {
        router.delete(`/organization/${catalog}/${row.id}`, { preserveScroll: true });
    }
}

function addSuggested(catalog, names) {
    if (catalog === 'positions') {
        router.post('/organization/positions/suggested', { names }, { preserveScroll: true });
    } else {
        router.post('/organization/departments', { name: names[0], code: null, is_active: true }, { preserveScroll: true });
    }
}

const sameName = (a, b) => (a || '').toLocaleLowerCase() === (b || '').toLocaleLowerCase();

const missingSuggestions = computed(() => ({
    departments: props.suggestedDepartments.filter((n) => !props.departments.some((d) => sameName(d.name, n))),
    positions: props.suggestedPositions.filter((n) => !props.positions.some((p) => sameName(p.name, n))),
}));

const plural = (n, one, many) => `${n} ${n === 1 ? one : many}`;

const catalogDetail = (catalog, row) => {
    if (catalog === 'zones') {
        return plural(props.branches.filter((b) => b.zone_id === row.id).length, 'sede', 'sedes');
    }
    const people = plural(row.users_count || 0, 'colaborador', 'colaboradores');
    if (catalog === 'departments') return [row.head ? `Jefe: ${row.head}` : 'Sin jefe de área', people].join(' · ');
    return [row.department_id ? departmentName(row.department_id) : null, people].filter(Boolean).join(' · ');
};
</script>

<template>
    <AppLayout title="Organización">
        <!-- =========================================================================
             1. HEADER PRINCIPAL ELEGANTE CON ACCIONES RÁPIDAS EN MODALES
             ========================================================================= -->
        <div class="org-header-card mb-4 p-4 shadow-sm">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="org-header-icon shadow-sm">
                        <i class="bi bi-diagram-3-fill"></i>
                    </div>
                    <div>
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <h1 class="h3 fw-bold text-dark mb-0">Organización y Plantilla</h1>
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1" style="font-size: 0.75rem; border-radius: 6px;">
                                Estructura Oficial
                            </span>
                        </div>
                        <p class="text-secondary mb-0 mt-1" style="font-size: 0.875rem;">
                            Jerarquía de zonas, sedes operativas, áreas y cargos con control de plantilla prevista vs real.
                        </p>
                    </div>
                </div>

                <!-- Botones de Acción: ¡Todos abren Modal Dialog sin recargar! -->
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <button 
                        v-if="canManage"
                        type="button" 
                        class="btn btn-primary d-inline-flex align-items-center shadow-sm org-action-btn"
                        style="gap: 8px !important;"
                        @click="openCreateBranchModal"
                    >
                        <i class="bi bi-plus-lg fs-6"></i>
                        <span>Nueva sede</span>
                    </button>

                    <div v-if="canManage" class="btn-group shadow-sm">
                        <button 
                            type="button" 
                            class="btn btn-outline-secondary d-inline-flex align-items-center dropdown-toggle org-action-btn" 
                            style="gap: 8px !important;"
                            data-bs-toggle="dropdown" 
                            aria-expanded="false"
                        >
                            <i class="bi bi-folder-plus text-secondary fs-6"></i>
                            <span>Nuevo catálogo</span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow border-0" style="border-radius: 10px; font-size: 0.85rem;">
                            <li>
                                <button type="button" class="dropdown-item d-flex align-items-center py-2" style="gap: 10px !important;" @click="openCreateCatalogModal('positions')">
                                    <i class="bi bi-person-badge text-primary fs-6"></i>
                                    <span>Nuevo Cargo</span>
                                </button>
                            </li>
                            <li>
                                <button type="button" class="dropdown-item d-flex align-items-center py-2" style="gap: 10px !important;" @click="openCreateCatalogModal('departments')">
                                    <i class="bi bi-diagram-2 text-indigo fs-6"></i>
                                    <span>Nueva Área</span>
                                </button>
                            </li>
                            <li>
                                <button type="button" class="dropdown-item d-flex align-items-center py-2" style="gap: 10px !important;" @click="openCreateCatalogModal('zones')">
                                    <i class="bi bi-diagram-3 text-emerald fs-6"></i>
                                    <span>Nueva Zona</span>
                                </button>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Banner Informativo de Alcance Restringido -->
            <div v-if="scopeLabel" class="alert alert-info d-flex align-items-center shadow-sm mt-3 mb-0 border-0" style="border-radius: 10px; gap: 10px !important;" role="status">
                <i class="bi bi-shield-lock-fill fs-5"></i>
                <div class="small">
                    Tu alcance está restringido a <strong>{{ scopeLabel }}</strong>: solo puedes consultar este territorio y no modificar la estructura corporativa.
                </div>
            </div>
        </div>

        <!-- =========================================================================
             2. FRANJA DE KPIs / MÉTRICAS CORPORATIVAS RESUMIDAS
             ========================================================================= -->
        <div class="row g-3 mb-4">
            <!-- Sedes -->
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="kpi-metric-card shadow-sm p-3">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="text-muted fw-semibold small text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.05em;">Sedes Operativas</span>
                        <div class="kpi-icon-pill bg-indigo-subtle text-indigo">
                            <i class="bi bi-buildings"></i>
                        </div>
                    </div>
                    <div class="d-flex align-items-baseline gap-2">
                        <span class="h3 fw-bold mb-0 text-dark">{{ totals.totalBranches }}</span>
                        <span class="text-success small fw-semibold d-inline-flex align-items-center" style="gap: 4px;">
                            <i class="bi bi-check-circle-fill"></i>
                            <span>{{ totals.activeBranches }} activas</span>
                        </span>
                    </div>
                    <div class="text-muted small mt-1" style="font-size: 0.75rem;">
                        Distribuidas en {{ zones.length }} zonas
                    </div>
                </div>
            </div>

            <!-- Dotación Real Activa -->
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="kpi-metric-card shadow-sm p-3">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="text-muted fw-semibold small text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.05em;">Dotación Activa</span>
                        <div class="kpi-icon-pill bg-emerald-subtle text-emerald">
                            <i class="bi bi-people-fill"></i>
                        </div>
                    </div>
                    <div class="d-flex align-items-baseline gap-2">
                        <span class="h3 fw-bold mb-0 text-dark">{{ totals.people }}</span>
                        <span class="text-muted small">colaboradores</span>
                    </div>
                    <div class="text-muted small mt-1" style="font-size: 0.75rem;">
                        Asignados en sedes actuales
                    </div>
                </div>
            </div>

            <!-- Plazas Previstas (Plantilla) -->
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="kpi-metric-card shadow-sm p-3">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="text-muted fw-semibold small text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.05em;">Plantilla Prevista</span>
                        <div class="kpi-icon-pill bg-primary-subtle text-primary">
                            <i class="bi bi-clipboard-check-fill"></i>
                        </div>
                    </div>
                    <div class="d-flex align-items-baseline gap-2">
                        <span class="h3 fw-bold mb-0 text-dark">{{ totals.planned }}</span>
                        <span class="badge" :class="totals.coverage >= 100 ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning-emphasis'">
                            {{ totals.coverage }}% cobertura
                        </span>
                    </div>
                    <div class="text-muted small mt-1" style="font-size: 0.75rem;">
                        Presupuesto de plazas totales
                    </div>
                </div>
            </div>

            <!-- Catálogos Corporativos -->
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="kpi-metric-card shadow-sm p-3">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="text-muted fw-semibold small text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.05em;">Catálogos Maestros</span>
                        <div class="kpi-icon-pill bg-amber-subtle text-amber">
                            <i class="bi bi-collection-fill"></i>
                        </div>
                    </div>
                    <div class="d-flex align-items-baseline gap-2">
                        <span class="h3 fw-bold mb-0 text-dark">{{ positions.length }}</span>
                        <span class="text-muted small">cargos catalogados</span>
                    </div>
                    <div class="text-muted small mt-1" style="font-size: 0.75rem;">
                        {{ departments.length }} áreas funcionales · {{ zones.length }} zonas
                    </div>
                </div>
            </div>
        </div>

        <!-- =========================================================================
             3. NAVEGACIÓN PRINCIPAL: PESTAÑAS EJECUTIVAS
             ========================================================================= -->
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-3 border-bottom pb-3">
            <div class="nav-segment-group shadow-sm">
                <button 
                    type="button" 
                    class="nav-segment-btn" 
                    :class="{ active: activeMainTab === 'sedes' }"
                    style="gap: 8px !important;"
                    @click="activeMainTab = 'sedes'"
                >
                    <i class="bi bi-buildings-fill fs-6"></i>
                    <span>Sedes y Plantilla Operativa</span>
                    <span class="badge ms-2" :class="activeMainTab === 'sedes' ? 'bg-white text-primary' : 'bg-secondary-subtle text-secondary'">
                        {{ visibleBranches.length }}
                    </span>
                </button>
                <button 
                    type="button" 
                    class="nav-segment-btn" 
                    :class="{ active: activeMainTab === 'catalogs' }"
                    style="gap: 8px !important;"
                    @click="activeMainTab = 'catalogs'"
                >
                    <i class="bi bi-collection-fill fs-6"></i>
                    <span>Catálogos Organizacionales</span>
                    <span class="badge ms-2" :class="activeMainTab === 'catalogs' ? 'bg-white text-primary' : 'bg-secondary-subtle text-secondary'">
                        {{ positions.length + departments.length + zones.length }}
                    </span>
                </button>
            </div>

            <!-- Acciones de control rápido cuando se está en sedes -->
            <div v-if="activeMainTab === 'sedes'" class="d-flex align-items-center gap-2">
                <button 
                    type="button" 
                    class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center"
                    style="border-radius: 6px; font-size: 0.785rem; gap: 6px !important;"
                    @click="expandAllBranches"
                >
                    <i class="bi bi-arrows-expand"></i>
                    <span>Expandir todas</span>
                </button>
                <button 
                    type="button" 
                    class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center"
                    style="border-radius: 6px; font-size: 0.785rem; gap: 6px !important;"
                    @click="collapseAllBranches"
                >
                    <i class="bi bi-arrows-collapse"></i>
                    <span>Colapsar</span>
                </button>
            </div>
        </div>

        <!-- =========================================================================
             VISTA 1: SEDES Y PLANTILLA OPERATIVA (ESPACIOSA, SIN CORTE DE COLUMNAS)
             ========================================================================= -->
        <div v-if="activeMainTab === 'sedes'">
            <!-- BARRA DE FILTROS EN CASCADA CON ICONOS INTEGRADOS Y SIN TÍTULOS REDUNDANTES -->
            <div class="org-filter-card shadow-sm p-3 mb-4">
                <div class="row g-3 align-items-center">
                    <!-- Búsqueda Rápida con Icono Integrado y Espacio Limpio -->
                    <div class="col-12 col-md-4 col-xl-3">
                        <div class="position-relative">
                            <i 
                                class="bi bi-search text-muted" 
                                style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%); pointer-events: none; z-index: 5; font-size: 0.9rem;"
                            ></i>
                            <input 
                                id="f_search" 
                                v-model="filter.search" 
                                type="text" 
                                class="form-control form-control-sm" 
                                placeholder="Buscar por sede, ciudad o supervisor..."
                                style="height: 40px; border-radius: 8px; font-size: 0.85rem; padding-left: 42px !important; padding-right: 14px;"
                            >
                        </div>
                    </div>

                    <!-- Filtro Zona con Icono Integrado -->
                    <div class="col-12 col-sm-6 col-md-4 col-xl-2">
                        <div class="position-relative">
                            <i 
                                class="bi bi-diagram-3 text-emerald" 
                                style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%); pointer-events: none; z-index: 5; font-size: 0.95rem;"
                            ></i>
                            <select 
                                id="f_zone" 
                                v-model="filter.zone" 
                                class="form-select form-select-sm" 
                                style="height: 40px; border-radius: 8px; font-size: 0.85rem; padding-left: 40px !important;" 
                                @change="pickZone"
                            >
                                <option value="">Todas las zonas</option>
                                <option v-for="z in zones" :key="z.id" :value="z.id">{{ z.name }}</option>
                                <option :value="NO_ZONE">Sin zona asignada</option>
                            </select>
                        </div>
                    </div>

                    <!-- Filtro Sede con Icono Integrado -->
                    <div class="col-12 col-sm-6 col-md-4 col-xl-2">
                        <div class="position-relative">
                            <i 
                                class="bi bi-geo-alt text-danger" 
                                style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%); pointer-events: none; z-index: 5; font-size: 0.95rem;"
                            ></i>
                            <select 
                                id="f_branch" 
                                v-model="filter.branch" 
                                class="form-select form-select-sm" 
                                style="height: 40px; border-radius: 8px; font-size: 0.85rem; padding-left: 40px !important;"
                            >
                                <option value="">Todas las sedes{{ filter.zone !== '' ? ' de la zona' : '' }}</option>
                                <option v-for="b in zoneBranches" :key="b.id" :value="b.id">{{ b.name }}</option>
                            </select>
                        </div>
                    </div>

                    <!-- Filtro Área con Icono Integrado -->
                    <div class="col-12 col-sm-6 col-md-4 col-xl-2">
                        <div class="position-relative">
                            <i 
                                class="bi bi-diagram-2 text-indigo" 
                                style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%); pointer-events: none; z-index: 5; font-size: 0.95rem;"
                            ></i>
                            <select 
                                id="f_department" 
                                v-model="filter.department" 
                                class="form-select form-select-sm" 
                                style="height: 40px; border-radius: 8px; font-size: 0.85rem; padding-left: 40px !important;" 
                                @change="pickDepartment"
                            >
                                <option value="">Todas las áreas</option>
                                <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
                                <option :value="null">Sin área asignada</option>
                            </select>
                        </div>
                    </div>

                    <!-- Filtro Cargo con Icono Integrado -->
                    <div class="col-12 col-sm-6 col-md-4 col-xl-2">
                        <div class="position-relative">
                            <i 
                                class="bi bi-person-badge text-info" 
                                style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%); pointer-events: none; z-index: 5; font-size: 0.95rem;"
                            ></i>
                            <select 
                                id="f_position" 
                                v-model="filter.position" 
                                class="form-select form-select-sm" 
                                style="height: 40px; border-radius: 8px; font-size: 0.85rem; padding-left: 40px !important;" 
                                :disabled="!positions.length"
                            >
                                <option value="">Todos los cargos</option>
                                <option v-for="p in filterPositions" :key="p.id" :value="p.id">{{ p.name }}</option>
                            </select>
                        </div>
                    </div>

                    <!-- Botón Restablecer Filtros Funcional y Elegante -->
                    <div class="col-12 col-md-4 col-xl-1 text-end">
                        <button 
                            type="button" 
                            class="btn btn-outline-secondary btn-sm w-100 d-inline-flex align-items-center justify-content-center"
                            style="height: 40px; border-radius: 8px; font-weight: 600; font-size: 0.825rem; gap: 6px !important;"
                            title="Restablecer todos los filtros"
                            @click="resetFilters"
                        >
                            <i class="bi bi-arrow-clockwise fs-6"></i>
                            <span>Limpiar</span>
                        </button>
                    </div>
                </div>

                <!-- Chips de Filtros Activos -->
                <div v-if="hasActiveFilters" class="d-flex align-items-center flex-wrap gap-2 pt-3 mt-3 border-top">
                    <span class="small text-muted fw-semibold me-1" style="font-size: 0.75rem;">Filtros activos:</span>
                    <span v-if="filter.search" class="badge bg-body-secondary text-secondary border px-2 py-1 d-inline-flex align-items-center" style="border-radius: 6px; gap: 6px;">
                        Texto: «{{ filter.search }}»
                        <i class="bi bi-x cursor-pointer ms-1 fs-6" @click="filter.search = ''"></i>
                    </span>
                    <span v-if="filter.zone" class="badge bg-body-secondary text-secondary border px-2 py-1 d-inline-flex align-items-center" style="border-radius: 6px; gap: 6px;">
                        Zona: {{ filter.zone === NO_ZONE ? 'Sin zona' : zoneName(filter.zone) }}
                        <i class="bi bi-x cursor-pointer ms-1 fs-6" @click="filter.zone = ''"></i>
                    </span>
                    <span v-if="filter.branch" class="badge bg-body-secondary text-secondary border px-2 py-1 d-inline-flex align-items-center" style="border-radius: 6px; gap: 6px;">
                        Sede: {{ props.branches.find(b => b.id === filter.branch)?.name }}
                        <i class="bi bi-x cursor-pointer ms-1 fs-6" @click="filter.branch = ''"></i>
                    </span>
                    <span v-if="filter.department" class="badge bg-body-secondary text-secondary border px-2 py-1 d-inline-flex align-items-center" style="border-radius: 6px; gap: 6px;">
                        Área: {{ departmentName(filter.department) }}
                        <i class="bi bi-x cursor-pointer ms-1 fs-6" @click="filter.department = ''; pickDepartment()"></i>
                    </span>
                    <span v-if="filter.position" class="badge bg-body-secondary text-secondary border px-2 py-1 d-inline-flex align-items-center" style="border-radius: 6px; gap: 6px;">
                        Cargo: {{ positionById.get(filter.position)?.name }}
                        <i class="bi bi-x cursor-pointer ms-1 fs-6" @click="filter.position = ''"></i>
                    </span>
                    <button type="button" class="btn btn-link btn-sm p-0 text-muted small text-decoration-none" @click="resetFilters">
                        Limpiar todos
                    </button>
                </div>
            </div>

            <!-- JERARQUÍA DE ZONAS Y SEDES (TABLA FULL WIDTH ESPACIOSA) -->
            <div v-for="group in tree" :key="group.key ?? 'none'" class="org-tree-card shadow-sm mb-4">
                <!-- Cabecera de Zona -->
                <div class="org-zone-header p-3 px-4 d-flex flex-wrap justify-content-between align-items-center gap-2 border-bottom">
                    <div class="d-flex align-items-center" style="gap: 12px !important;">
                        <div class="org-zone-icon">
                            <i class="bi bi-diagram-3-fill"></i>
                        </div>
                        <div>
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <h2 class="h5 fw-bold mb-0 text-dark">
                                    {{ group.zone?.name ?? 'Sedes sin zona asignada' }}
                                </h2>
                                <span v-if="group.zone?.code" class="badge bg-body-secondary text-secondary border" style="border-radius: 4px; font-size: 0.72rem;">
                                    {{ group.zone.code }}
                                </span>
                                <span v-if="group.zone && !group.zone.is_active" class="badge bg-danger-subtle text-danger" style="font-size: 0.72rem;">
                                    Inactiva
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-3">
                        <span class="badge bg-body-secondary text-secondary border px-3 py-1 fw-semibold" style="border-radius: 20px; font-size: 0.8rem;">
                            {{ group.branches.length }} {{ group.branches.length === 1 ? 'sede' : 'sedes' }} · {{ group.branches.reduce((sum, b) => sum + headcount(b), 0) }} colaboradores
                        </span>
                    </div>
                </div>

                <!-- Tabla de Sedes de la Zona (Ancho Completo, Columnas Limpias) -->
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 org-table">
                        <thead>
                            <tr class="org-table-header">
                                <th class="ps-4" style="min-width: 280px; white-space: nowrap !important;">Sede Operativa</th>
                                <th style="min-width: 170px; white-space: nowrap !important;">Tipo de Sede</th>
                                <th v-if="canManage" style="min-width: 170px; white-space: nowrap !important;">Zona Asignada</th>
                                <th class="text-center" style="min-width: 130px; white-space: nowrap !important;">Dotación</th>
                                <th class="text-center" style="min-width: 130px; white-space: nowrap !important;">Geocerca GPS</th>
                                <th class="pe-4 text-end" style="min-width: 180px; white-space: nowrap !important;">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="b in group.branches" :key="b.id">
                                <tr class="org-row cursor-pointer" :class="{ 'org-row-active': openBranches.has(b.id) }" @click="toggleBranch(b.id)">
                                    <!-- Sede y Datos Principales -->
                                    <td class="ps-4 py-3">
                                        <div class="d-flex align-items-start gap-3">
                                            <div class="org-chevron-pill mt-1" :class="{ 'expanded': openBranches.has(b.id) }">
                                                <i class="bi bi-chevron-right"></i>
                                            </div>
                                            <div>
                                                <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                                                    <span class="fw-bold text-dark fs-6">{{ b.name }}</span>
                                                    <span v-if="!b.is_active" class="badge bg-secondary-subtle text-secondary" style="font-size: 0.68rem;">Inactiva</span>
                                                    <TenantBadge :tenant-id="b.tenant_id" />
                                                </div>
                                                <div class="d-flex align-items-center flex-wrap gap-2 text-muted small" style="font-size: 0.775rem;">
                                                    <span v-if="b.code" class="badge bg-body-secondary text-secondary border px-2 py-1" style="border-radius: 4px;">
                                                        {{ b.code }}
                                                    </span>
                                                    <span v-if="b.city" class="d-inline-flex align-items-center" style="gap: 4px;">
                                                        <i class="bi bi-geo-alt text-danger"></i>
                                                        <span>{{ b.city }}</span>
                                                    </span>
                                                    <span v-if="b.supervisor" class="text-truncate d-inline-flex align-items-center" style="max-width: 240px; gap: 4px;" :title="`Supervisor: ${b.supervisor}`">
                                                        <i class="bi bi-person-badge text-primary"></i>
                                                        <span>{{ b.supervisor }}</span>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </td>

                                    <!-- Tipo de Sede -->
                                    <td class="py-3" @click.stop>
                                        <select 
                                            v-if="canManage" 
                                            class="form-select form-select-sm" 
                                            style="border-radius: 6px; font-size: 0.8rem; height: 36px;" 
                                            :value="b.type" 
                                            :aria-label="`Tipo de ${b.name}`" 
                                            @change="updateBranchInline(b, { branch_type: $event.target.value })"
                                        >
                                            <option v-for="(label, value) in branchTypes" :key="value" :value="value">{{ label }}</option>
                                        </select>
                                        <span v-else class="badge bg-body-secondary text-secondary border">
                                            {{ branchTypes[b.type] ?? b.type }}
                                        </span>
                                    </td>

                                    <!-- Zona -->
                                    <td v-if="canManage" class="py-3" @click.stop>
                                        <select 
                                            class="form-select form-select-sm" 
                                            style="border-radius: 6px; font-size: 0.8rem; height: 36px;" 
                                            :value="b.zone_id ?? ''" 
                                            :aria-label="`Zona de ${b.name}`" 
                                            @change="updateBranchInline(b, { zone_id: $event.target.value || null })"
                                        >
                                            <option value="">Sin zona asignada</option>
                                            <option v-for="z in zones" :key="z.id" :value="z.id">{{ z.name }}</option>
                                        </select>
                                    </td>

                                    <!-- Dotación: Reales vs Previstos -->
                                    <td class="py-3 text-center" @click.stop>
                                        <div class="d-inline-flex flex-column align-items-center">
                                            <div class="d-flex align-items-center gap-1">
                                                <span class="fw-bold text-dark fs-6">{{ headcount(b) }}</span>
                                                <span class="text-muted small">/ {{ plannedTotal(b) || '0' }}</span>
                                            </div>
                                            <span 
                                                class="badge mt-1" 
                                                :class="headcount(b) >= (plannedTotal(b) || 0) && plannedTotal(b) > 0 ? 'bg-success-subtle text-success' : 'bg-primary-subtle text-primary'"
                                                style="font-size: 0.68rem; border-radius: 10px;"
                                            >
                                                {{ plannedTotal(b) ? Math.round((headcount(b) / plannedTotal(b)) * 100) + '% plazas' : 'Sin plan' }}
                                            </span>
                                        </div>
                                    </td>

                                    <!-- Geocerca GPS -->
                                    <td class="py-3 text-center" @click.stop>
                                        <span class="badge bg-body-secondary text-secondary border px-2 py-1 d-inline-flex align-items-center" style="font-size: 0.775rem; border-radius: 6px; gap: 4px;">
                                            <i class="bi bi-geo-alt text-danger"></i>
                                            <span>{{ b.radius }} m</span>
                                        </span>
                                    </td>

                                    <!-- Acciones: SOLO ICONOS CON ESPACIADO ELEGANTE -->
                                    <td class="pe-4 text-end py-3" @click.stop>
                                        <div class="d-flex align-items-center justify-content-end" style="gap: 8px !important;">
                                            <!-- Botón Plantilla: Abre Modal -->
                                            <button 
                                                v-if="canManage"
                                                type="button" 
                                                class="btn btn-sm btn-outline-primary p-0 d-inline-flex align-items-center justify-content-center" 
                                                style="width: 34px; height: 34px; border-radius: 8px;"
                                                title="Configurar plantilla prevista de cargos"
                                                @click="openStaffingModal(b)"
                                            >
                                                <i class="bi bi-sliders fs-6"></i>
                                            </button>

                                            <!-- Botón Editar Sede: Abre Modal Dialog -->
                                            <button 
                                                v-if="canManage"
                                                type="button" 
                                                class="btn btn-sm btn-outline-secondary p-0 d-inline-flex align-items-center justify-content-center" 
                                                style="width: 34px; height: 34px; border-radius: 8px;"
                                                title="Editar datos, coordenadas y supervisor de la sede"
                                                @click="openEditBranchModal(b)"
                                            >
                                                <i class="bi bi-pencil-square fs-6"></i>
                                            </button>

                                            <!-- Ver Mapa GPS -->
                                            <a 
                                                :href="`/branches/${b.id}/edit`" 
                                                class="btn btn-sm btn-outline-secondary p-0 d-inline-flex align-items-center justify-content-center" 
                                                style="width: 34px; height: 34px; border-radius: 8px;"
                                                :title="`Ver mapa interactivo y geocerca de ${b.name}`"
                                            >
                                                <i class="bi bi-geo-alt fs-6"></i>
                                            </a>

                                            <!-- Eliminar Sede -->
                                            <button 
                                                v-if="canManage"
                                                type="button" 
                                                class="btn btn-sm btn-outline-danger p-0 d-inline-flex align-items-center justify-content-center" 
                                                style="width: 34px; height: 34px; border-radius: 8px;"
                                                :title="`Eliminar sede ${b.name}`"
                                                @click="confirmDeleteBranch(b)"
                                            >
                                                <i class="bi bi-trash fs-6"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>

                                <!-- =========================================================
                                     DRAWER / EXPANDABLE DE LA SEDE (DISEÑO CORPORATIVO LIMPIO)
                                     ========================================================= -->
                                <tr v-if="openBranches.has(b.id)" class="org-drawer-row">
                                    <td :colspan="canManage ? 6 : 5" class="p-4 org-drawer-cell border-bottom">
                                        <div class="org-drawer-panel p-4 rounded-3 border shadow-sm">
                                            <!-- Cabecera del Drawer con Espaciado Amplio -->
                                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4 pb-3 border-bottom">
                                                <div class="d-flex align-items-center" style="gap: 14px !important;">
                                                    <div class="kpi-icon-pill bg-primary-subtle text-primary" style="width: 42px; height: 42px; font-size: 1.25rem; flex-shrink: 0;">
                                                        <i class="bi bi-clipboard2-data-fill"></i>
                                                    </div>
                                                    <div>
                                                        <h3 class="h5 fw-bold mb-0 text-dark">
                                                            Plantilla Prevista vs Real · {{ b.name }}
                                                        </h3>
                                                        <span class="text-secondary small" style="font-size: 0.8rem;">
                                                            Comparativa de plazas presupuestadas contra colaboradores activos ocupando cada posición.
                                                        </span>
                                                    </div>
                                                </div>

                                                <div class="d-flex align-items-center gap-2">
                                                    <button 
                                                        v-if="canManage" 
                                                        type="button" 
                                                        class="btn btn-primary btn-sm d-inline-flex align-items-center px-3 py-2 shadow-sm" 
                                                        style="border-radius: 6px; font-size: 0.825rem; font-weight: 600; gap: 8px !important;"
                                                        @click="openStaffingModal(b)"
                                                    >
                                                        <i class="bi bi-pencil-square"></i>
                                                        <span>Modificar plantilla</span>
                                                    </button>
                                                </div>
                                            </div>

                                            <!-- TABLA COMPARATIVA DE PLAZAS: TITULOS ESTRICTAMENTE EN UNA SOLA LÍNEA -->
                                            <div v-if="plantilla(b).length" class="table-responsive mb-4 border rounded-3 overflow-hidden">
                                                <table class="table table-hover align-middle mb-0">
                                                    <thead class="org-drawer-table-head">
                                                        <tr style="font-size: 0.775rem;" class="text-uppercase text-muted">
                                                            <th class="ps-4 py-2.5" style="white-space: nowrap !important; min-width: 220px;">CARGO OFICIAL</th>
                                                            <th class="py-2.5" style="white-space: nowrap !important; min-width: 180px;">ÁREA FUNCIONAL</th>
                                                            <th class="text-end py-2.5" style="white-space: nowrap !important; min-width: 160px;">PLAZAS PREVISTAS</th>
                                                            <th class="text-end py-2.5" style="white-space: nowrap !important; min-width: 170px;">OCUPADAS (REALES)</th>
                                                            <th class="pe-4 py-2.5 text-center" style="white-space: nowrap !important; min-width: 150px;">ESTADO</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <tr v-for="row in plantilla(b)" :key="row.position_id">
                                                            <td class="ps-4 py-2.5">
                                                                <div class="d-flex align-items-center" style="gap: 8px !important;">
                                                                    <i class="bi bi-person-badge text-primary small"></i>
                                                                    <span class="fw-semibold text-dark">{{ positionById.get(row.position_id)?.name ?? 'Cargo no catalogado' }}</span>
                                                                    <span v-if="positionById.get(row.position_id)?.is_roaming" class="badge bg-info-subtle text-info border border-info-subtle d-inline-flex align-items-center" style="font-size: 0.65rem; gap: 3px;">
                                                                        <i class="bi bi-truck"></i>
                                                                        <span>Itinerante</span>
                                                                    </span>
                                                                </div>
                                                            </td>
                                                            <td class="py-2 text-secondary small">
                                                                <span class="badge bg-body-secondary text-secondary border px-2 py-1">
                                                                    {{ departmentName(positionById.get(row.position_id)?.department_id ?? null) }}
                                                                </span>
                                                            </td>
                                                            <td class="text-end py-2 fw-bold text-secondary">{{ row.planned }}</td>
                                                            <td class="text-end py-2 fw-bold text-primary">{{ row.actual }}</td>
                                                            <td class="pe-4 py-2 text-center">
                                                                <span :class="gapBadge(row).cls" style="border-radius: 6px; font-size: 0.75rem; padding: 0.35rem 0.65rem; font-weight: 600;">
                                                                    {{ gapBadge(row).text }}
                                                                </span>
                                                            </td>
                                                        </tr>
                                                    </tbody>
                                                </table>
                                            </div>
                                            <div v-else class="text-center py-4 org-drawer-empty rounded-3 mb-4 border text-muted small">
                                                <i class="bi bi-clipboard-x fs-2 d-block mb-2 text-secondary opacity-50"></i>
                                                Esta sede aún no tiene cargos configurados en su plantilla prevista.
                                                <div v-if="canManage" class="mt-2">
                                                    <button type="button" class="btn btn-sm btn-outline-primary" @click="openStaffingModal(b)">
                                                        Configurar plantilla de cargos
                                                    </button>
                                                </div>
                                            </div>

                                            <!-- =====================================================
                                                 SECCIÓN: AVISO EJECUTIVO DE CARGOS NO CATALOGADOS
                                                 (Sin esquinas redondas excesivas, texto 100% legible)
                                                 ===================================================== -->
                                            <div v-if="uncatalogued(b).length" class="org-advisory-card mb-4 p-4 rounded-3">
                                                <div class="d-flex align-items-start" style="gap: 16px !important;">
                                                    <div class="org-advisory-icon flex-shrink-0">
                                                        <i class="bi bi-exclamation-triangle-fill"></i>
                                                    </div>
                                                    <div class="flex-grow-1 min-w-0">
                                                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                                                            <h4 class="h6 fw-bold mb-0 text-dark">
                                                                Cargos no catalogados detectados ({{ uncatalogued(b).reduce((acc, c) => acc + c.total, 0) }} colaboradores)
                                                            </h4>
                                                            <span class="badge org-advisory-badge fw-bold px-2.5 py-1" style="border-radius: 6px; font-size: 0.75rem;">
                                                                <i class="bi bi-pencil-fill me-1 small"></i> Escrito libre en ficha
                                                            </span>
                                                        </div>
                                                        <p class="text-secondary small mb-3" style="font-size: 0.825rem;">
                                                            Estos colaboradores tienen un cargo redactado libremente en su ficha y no están vinculados a un cargo del catálogo corporativo. Puedes homologarlos o agregarlos al catálogo oficial:
                                                        </p>

                                                        <!-- Items Elegantes con Esquinas Modernas (NO redondas tipo píldora) -->
                                                        <div class="d-flex flex-wrap" style="gap: 10px !important;">
                                                            <div 
                                                                v-for="item in uncatalogued(b)" 
                                                                :key="item.position" 
                                                                class="org-uncatalogued-item"
                                                            >
                                                                <div class="d-flex align-items-center" style="gap: 8px !important;">
                                                                    <i class="bi bi-person-fill text-muted"></i>
                                                                    <span class="fw-semibold text-dark">{{ item.position || 'Sin cargo asignado' }}</span>
                                                                    <span class="badge bg-body-secondary text-secondary border px-2 py-1" style="border-radius: 4px; font-size: 0.75rem;">
                                                                        {{ item.total }} {{ item.total === 1 ? 'persona' : 'personas' }}
                                                                    </span>
                                                                    <button 
                                                                        v-if="canManage && item.position" 
                                                                        type="button" 
                                                                        class="btn btn-xs btn-primary d-inline-flex align-items-center ms-1" 
                                                                        style="border-radius: 4px; font-size: 0.72rem; font-weight: 600; padding: 3px 8px; gap: 4px !important;"
                                                                        title="Crear como cargo oficial en el catálogo"
                                                                        @click="quickCreatePositionFromUncatalogued(item.position)"
                                                                    >
                                                                        <i class="bi bi-plus-lg"></i>
                                                                        <span>Catalogar</span>
                                                                    </button>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- DESGLOSE POR ÁREA FUNCIONAL CON ESPACIOS RESPETADOS -->
                                            <details v-if="staffByDepartment(b).length" class="org-details-accordion mt-3">
                                                <summary class="d-flex align-items-center justify-content-between p-3 rounded-2 org-breakdown-summary border cursor-pointer fw-semibold small text-secondary">
                                                    <div class="d-flex align-items-center" style="gap: 10px !important;">
                                                        <i class="bi bi-diagram-2 text-primary fs-6"></i>
                                                        <span>Ver desglose de dotación activa por área ({{ staffByDepartment(b).length }} áreas)</span>
                                                    </div>
                                                    <i class="bi bi-chevron-down text-muted"></i>
                                                </summary>
                                                <div class="row g-3 mt-2">
                                                    <div v-for="dep in staffByDepartment(b)" :key="dep.id ?? 'none'" class="col-12 col-md-6 col-xl-4">
                                                        <div class="p-3 border rounded-3 org-breakdown-card h-100">
                                                            <div class="d-flex align-items-center justify-content-between mb-2 pb-1.5 border-bottom">
                                                                <span class="fw-bold text-dark small">{{ departmentName(dep.id) }}</span>
                                                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-0.5" style="border-radius: 10px; font-size: 0.72rem;">
                                                                    {{ dep.total }} {{ dep.total === 1 ? 'colaborador' : 'colaboradores' }}
                                                                </span>
                                                            </div>
                                                            <ul class="list-unstyled mb-0 small text-muted">
                                                                <li v-for="p in dep.positions" :key="p.name" class="d-flex align-items-center justify-content-between py-1">
                                                                    <span>· {{ p.name }}</span>
                                                                    <strong class="text-dark">{{ p.total }}</strong>
                                                                </li>
                                                            </ul>
                                                        </div>
                                                    </div>
                                                </div>
                                            </details>
                                        </div>
                                    </td>
                                </tr>
                            </template>

                            <!-- Estado Vacío cuando una Zona no tiene Sedes -->
                            <tr v-if="!group.branches.length">
                                <td :colspan="canManage ? 6 : 5" class="py-4 text-center text-muted small">
                                    <i class="bi bi-info-circle me-1"></i>No hay sedes asignadas a esta zona.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Estado Vacío General del Árbol -->
            <div v-if="!tree.length" class="org-tree-card shadow-sm p-5 text-center text-muted">
                <i class="bi bi-buildings fs-1 opacity-50 mb-2 d-block text-primary"></i>
                <h3 class="h5 fw-bold text-dark">No se encontraron sedes</h3>
                <p class="small text-muted mb-3">No existen sedes que coincidan con los filtros seleccionados.</p>
                <button type="button" class="btn btn-sm btn-outline-primary" @click="resetFilters">
                    Limpiar filtros de búsqueda
                </button>
            </div>
        </div>

        <!-- =========================================================================
             VISTA 2: CATÁLOGOS ORGANIZACIONALES (ÁREAS, CARGOS, ZONAS)
             ========================================================================= -->
        <div v-else-if="activeMainTab === 'catalogs'" class="org-catalogs-view">
            <div class="card shadow-sm border-0" style="border-radius: 12px; overflow: hidden;">
                <!-- Sub-navegación de Catálogos (Áreas, Cargos, Zonas) -->
                <div class="card-header border-bottom" style="padding: 1.25rem 1.5rem !important;">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
                        <div class="nav-segment-group shadow-sm">
                            <button 
                                v-for="(meta, catKey) in CATALOGS" 
                                :key="catKey"
                                type="button" 
                                class="nav-segment-btn" 
                                :class="{ active: activeCatalogTab === catKey }"
                                style="gap: 8px !important;"
                                @click="activeCatalogTab = catKey"
                            >
                                <i :class="meta.icon"></i>
                                <span>{{ meta.title }}</span>
                                <span class="badge ms-2" :class="activeCatalogTab === catKey ? 'bg-primary text-white' : 'bg-body-secondary text-secondary'">
                                    {{ rowsOf(catKey).length }}
                                </span>
                            </button>
                        </div>

                        <!-- Botón Crear Nuevo Ítem en el Catálogo Activo -->
                        <div v-if="canManage">
                            <button 
                                type="button" 
                                class="btn btn-primary btn-sm d-inline-flex align-items-center shadow-sm px-3 py-2" 
                                style="border-radius: 8px; font-weight: 600; gap: 8px !important; height: 38px;"
                                @click="openCreateCatalogModal(activeCatalogTab)"
                            >
                                <i class="bi bi-plus-lg"></i>
                                <span>{{ CATALOGS[activeCatalogTab].isNew }}</span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Barra de Búsqueda y Filtros Internos de Catálogo -->
                <div class="org-catalog-filter-bar border-bottom" style="padding: 1.25rem 1.5rem !important;">
                    <div class="row g-3 align-items-center">
                        <div class="col-12 col-md-6 col-lg-5">
                            <div class="position-relative">
                                <i 
                                    class="bi bi-search text-muted" 
                                    style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%); pointer-events: none; z-index: 5; font-size: 0.85rem;"
                                ></i>
                                <input 
                                    v-model="catalogSearchQuery" 
                                    type="text" 
                                    class="form-control form-control-sm" 
                                    :placeholder="`Buscar en ${CATALOGS[activeCatalogTab].title.toLowerCase()} por nombre o código...`"
                                    style="border-radius: 8px; height: 40px; font-size: 0.85rem; padding-left: 40px !important;"
                                >
                            </div>
                        </div>

                        <!-- Filtro de Área cuando se ven Cargos -->
                        <div v-if="activeCatalogTab === 'positions'" class="col-12 col-md-6 col-lg-4">
                            <select 
                                v-model="catalogDepartmentFilter" 
                                class="form-select form-select-sm" 
                                style="border-radius: 8px; height: 40px; font-size: 0.85rem;"
                            >
                                <option value="">Todas las áreas funcionales</option>
                                <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
                            </select>
                        </div>

                        <div class="col-12 col-lg-3 text-lg-end text-muted small ms-auto">
                            Mostrando <strong>{{ filteredCatalogRows.length }}</strong> de {{ rowsOf(activeCatalogTab).length }} registros
                        </div>
                    </div>
                </div>

                <!-- Sugerencias Habituales (Transporte y Logística) con Separación Estructural y Márgenes Definidos -->
                <div 
                    v-if="activeCatalogTab !== 'zones' && canManage && missingSuggestions[activeCatalogTab].length" 
                    class="org-catalog-suggestions-box"
                >
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                        <div class="d-flex align-items-center small fw-semibold" style="gap: 8px !important;">
                            <div class="d-flex align-items-center justify-content-center bg-warning-subtle text-warning rounded-circle" style="width: 28px; height: 28px; flex-shrink: 0;">
                                <i class="bi bi-lightbulb-fill" style="font-size: 0.85rem;"></i>
                            </div>
                            <span class="org-suggestions-title">Sugerencias habituales para empresas de transporte y servicios:</span>
                        </div>
                        <button 
                            v-if="activeCatalogTab === 'positions' && missingSuggestions.positions.length > 1" 
                            type="button" 
                            class="btn btn-sm btn-link p-0 text-decoration-none fw-bold text-primary small text-nowrap" 
                            @click="addSuggested('positions', missingSuggestions.positions)"
                        >
                            + Agregar todas las sugerencias
                        </button>
                    </div>
                    <div class="d-flex flex-wrap" style="gap: 10px !important;">
                        <button 
                            v-for="name in missingSuggestions[activeCatalogTab]" 
                            :key="name" 
                            type="button" 
                            class="org-suggestion-pill-btn d-inline-flex align-items-center" 
                            style="gap: 6px !important;"
                            @click="addSuggested(activeCatalogTab, [name])"
                        >
                            <i class="bi bi-plus-lg text-primary"></i>
                            <span>{{ name }}</span>
                        </button>
                    </div>
                </div>

                <!-- Tabla de Registros del Catálogo -->
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 org-table">
                        <thead>
                            <tr class="org-table-header">
                                <th class="ps-4 py-3" style="white-space: nowrap !important;">Nombre</th>
                                <th class="py-3" style="white-space: nowrap !important;">Código</th>
                                <th v-if="activeCatalogTab === 'positions'" class="py-3" style="white-space: nowrap !important;">Área Asociada</th>
                                <th class="py-3" style="white-space: nowrap !important;">Colaboradores</th>
                                <th class="py-3 text-center" style="white-space: nowrap !important;">Estado</th>
                                <th class="pe-4 py-3 text-end" style="white-space: nowrap !important;">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in filteredCatalogRows" :key="row.id" class="org-row">
                                <!-- Nombre y Descripción -->
                                <td class="ps-4 py-3">
                                    <div class="d-flex align-items-center gap-2 flex-wrap">
                                        <span class="fw-bold text-dark fs-6">{{ row.name }}</span>
                                        <span v-if="row.is_roaming" class="badge bg-info-subtle text-info border border-info-subtle d-inline-flex align-items-center" style="font-size: 0.68rem; gap: 4px;" title="Permite marcar en ruta o en cualquier sede">
                                            <i class="bi bi-truck"></i>
                                            <span>Itinerante</span>
                                        </span>
                                    </div>
                                    <div v-if="row.description" class="text-muted small mt-0.5 text-truncate" style="max-width: 380px;">
                                        {{ row.description }}
                                    </div>
                                </td>

                                <!-- Código -->
                                <td class="py-3">
                                    <span v-if="row.code" class="badge bg-body-secondary text-secondary border px-2 py-1" style="font-size: 0.75rem; border-radius: 4px;">
                                        {{ row.code }}
                                    </span>
                                    <span v-else class="text-muted small">—</span>
                                </td>

                                <!-- Área (si es cargo) -->
                                <td v-if="activeCatalogTab === 'positions'" class="py-3">
                                    <span v-if="row.department_id" class="badge bg-body-secondary text-secondary border px-2 py-1">
                                        {{ departmentName(row.department_id) }}
                                    </span>
                                    <span v-else class="text-muted small">Sin área asignada</span>
                                </td>

                                <!-- Colaboradores Asignados -->
                                <td class="py-3">
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2.5 py-1 d-inline-flex align-items-center" style="font-size: 0.775rem; border-radius: 6px; gap: 6px;">
                                        <i class="bi bi-people"></i>
                                        <span>{{ catalogDetail(activeCatalogTab, row) }}</span>
                                    </span>
                                </td>

                                <!-- Estado -->
                                <td class="py-3 text-center">
                                    <span class="badge" :class="row.is_active ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger'" style="font-size: 0.72rem; border-radius: 6px; padding: 4px 8px;">
                                        {{ row.is_active ? 'Activo' : 'Inactivo' }}
                                    </span>
                                </td>

                                <!-- Acciones: Solo Iconos -->
                                <td class="pe-4 py-3 text-end">
                                    <div v-if="canManage" class="d-flex align-items-center justify-content-end" style="gap: 8px !important;">
                                        <button 
                                            type="button" 
                                            class="btn btn-sm btn-outline-secondary p-0 d-inline-flex align-items-center justify-content-center" 
                                            style="width: 32px; height: 32px; border-radius: 6px;"
                                            :aria-label="`Editar ${row.name}`" 
                                            title="Editar registro"
                                            @click="openEditCatalogModal(activeCatalogTab, row)"
                                        >
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button 
                                            type="button" 
                                            class="btn btn-sm btn-outline-danger p-0 d-inline-flex align-items-center justify-content-center" 
                                            style="width: 32px; height: 32px; border-radius: 6px;"
                                            :aria-label="`Eliminar ${row.name}`" 
                                            title="Eliminar registro"
                                            @click="removeCatalogItem(activeCatalogTab, row)"
                                        >
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>

                            <!-- Estado Vacío del Catálogo -->
                            <tr v-if="!filteredCatalogRows.length">
                                <td :colspan="activeCatalogTab === 'positions' ? 6 : 5" class="py-5 text-center text-muted">
                                    <i class="bi bi-inbox fs-2 d-block mb-2 opacity-50"></i>
                                    No se encontraron registros de {{ CATALOGS[activeCatalogTab].title.toLowerCase() }} que coincidan.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- =========================================================================
             MODAL DIALOG 1: NUEVA SEDE / EDITAR SEDE (CENTRALIZADO Y PROFESIONAL)
             ========================================================================= -->
        <div v-if="showBranchModal" class="modal-backdrop fade show" style="z-index: 1050;"></div>
        <div 
            v-if="showBranchModal" 
            class="modal fade show d-block" 
            tabindex="-1" 
            role="dialog" 
            style="z-index: 1055;"
            @click.self="closeBranchModal"
        >
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content border-0 shadow-lg" style="border-radius: 12px; overflow: hidden;">
                    <!-- Cabecera -->
                    <div class="modal-header py-3 px-4 border-bottom">
                        <div class="d-flex align-items-center" style="gap: 14px !important;">
                            <div class="kpi-icon-pill bg-primary-subtle text-primary" style="width: 42px; height: 42px; font-size: 1.25rem; flex-shrink: 0;">
                                <i class="bi bi-buildings-fill"></i>
                            </div>
                            <div>
                                <h2 class="h6 fw-bold mb-0 text-dark">
                                    {{ editingBranch ? `Editar Sede · ${editingBranch.name}` : 'Crear Nueva Sede Operativa' }}
                                </h2>
                                <span class="small text-muted" style="font-size: 0.775rem;">
                                    Define los datos corporativos, geocerca GPS y responsable de la sucursal.
                                </span>
                            </div>
                        </div>
                        <button type="button" class="btn-close" aria-label="Cerrar" @click="closeBranchModal"></button>
                    </div>

                    <!-- Formulario -->
                    <form @submit.prevent="submitBranchModal">
                        <div class="modal-body p-4" style="max-height: calc(85vh - 120px); overflow-y: auto;">
                            <div class="row g-3">
                                <!-- Nombre de la Sede -->
                                <div class="col-12 col-md-8">
                                    <label for="bm_name" class="form-label fw-semibold small text-dark mb-1">
                                        Nombre de la Sede <span class="text-danger">*</span>
                                    </label>
                                    <input 
                                        id="bm_name" 
                                        v-model="branchForm.name" 
                                        type="text" 
                                        class="form-control" 
                                        :class="{ 'is-invalid': branchForm.errors.name }" 
                                        placeholder="Ej.: Sede Principal - Trujillo, Terminal Terrestre..."
                                        style="height: 40px; border-radius: 8px; font-size: 0.85rem;"
                                        required
                                    >
                                    <div v-if="branchForm.errors.name" class="invalid-feedback d-block mt-1">
                                        {{ branchForm.errors.name }}
                                    </div>
                                </div>

                                <!-- Código Interno -->
                                <div class="col-12 col-md-4">
                                    <label for="bm_code" class="form-label fw-semibold small text-dark mb-1">
                                        Código Interno <span class="text-muted fw-normal">(Opcional)</span>
                                    </label>
                                    <input 
                                        id="bm_code" 
                                        v-model="branchForm.code" 
                                        type="text" 
                                        class="form-control" 
                                        :class="{ 'is-invalid': branchForm.errors.code }" 
                                        placeholder="Ej.: SED-01, TRU-AG"
                                        style="height: 40px; border-radius: 8px; font-size: 0.85rem;"
                                    >
                                    <div v-if="branchForm.errors.code" class="invalid-feedback d-block mt-1">
                                        {{ branchForm.errors.code }}
                                    </div>
                                </div>

                                <!-- Tipo de Sede -->
                                <div class="col-12 col-md-4">
                                    <label for="bm_type" class="form-label fw-semibold small text-dark mb-1">
                                        Tipo de Sede <span class="text-danger">*</span>
                                    </label>
                                    <select 
                                        id="bm_type" 
                                        v-model="branchForm.branch_type" 
                                        class="form-select" 
                                        :class="{ 'is-invalid': branchForm.errors.branch_type }"
                                        style="height: 40px; border-radius: 8px; font-size: 0.85rem;"
                                    >
                                        <option v-for="(label, value) in branchTypes" :key="value" :value="value">{{ label }}</option>
                                    </select>
                                    <div v-if="branchForm.errors.branch_type" class="invalid-feedback d-block mt-1">
                                        {{ branchForm.errors.branch_type }}
                                    </div>
                                </div>

                                <!-- Zona Territorial -->
                                <div class="col-12 col-md-4">
                                    <label for="bm_zone" class="form-label fw-semibold small text-dark mb-1">
                                        Zona Territorial <span class="text-muted fw-normal">(Opcional)</span>
                                    </label>
                                    <select 
                                        id="bm_zone" 
                                        v-model="branchForm.zone_id" 
                                        class="form-select" 
                                        :class="{ 'is-invalid': branchForm.errors.zone_id }"
                                        style="height: 40px; border-radius: 8px; font-size: 0.85rem;"
                                    >
                                        <option :value="null">Sin zona territorial</option>
                                        <option v-for="z in zones" :key="z.id" :value="z.id">{{ z.name }}</option>
                                    </select>
                                    <div v-if="branchForm.errors.zone_id" class="invalid-feedback d-block mt-1">
                                        {{ branchForm.errors.zone_id }}
                                    </div>
                                </div>

                                <!-- Supervisor / Responsable -->
                                <div class="col-12 col-md-4">
                                    <label for="bm_supervisor" class="form-label fw-semibold small text-dark mb-1">
                                        Supervisor / Encargado <span class="text-muted fw-normal">(Opcional)</span>
                                    </label>
                                    <select 
                                        id="bm_supervisor" 
                                        v-model="branchForm.supervisor_id" 
                                        class="form-select" 
                                        :class="{ 'is-invalid': branchForm.errors.supervisor_id }"
                                        style="height: 40px; border-radius: 8px; font-size: 0.85rem;"
                                    >
                                        <option :value="null">Sin supervisor asignado</option>
                                        <option v-for="s in supervisors" :key="s.id" :value="s.id">{{ s.name }}</option>
                                    </select>
                                    <div v-if="branchForm.errors.supervisor_id" class="invalid-feedback d-block mt-1">
                                        {{ branchForm.errors.supervisor_id }}
                                    </div>
                                </div>

                                <!-- Ciudad -->
                                <div class="col-12 col-md-4">
                                    <label for="bm_city" class="form-label fw-semibold small text-dark mb-1">
                                        Ciudad / Región
                                    </label>
                                    <input 
                                        id="bm_city" 
                                        v-model="branchForm.city" 
                                        type="text" 
                                        class="form-control" 
                                        :class="{ 'is-invalid': branchForm.errors.city }" 
                                        placeholder="Ej.: Trujillo, Lima, Arequipa..."
                                        style="height: 40px; border-radius: 8px; font-size: 0.85rem;"
                                    >
                                    <div v-if="branchForm.errors.city" class="invalid-feedback d-block mt-1">
                                        {{ branchForm.errors.city }}
                                    </div>
                                </div>

                                <!-- Dirección Completa -->
                                <div class="col-12 col-md-8">
                                    <label for="bm_address" class="form-label fw-semibold small text-dark mb-1">
                                        Dirección Física <span class="text-danger">*</span>
                                    </label>
                                    <input 
                                        id="bm_address" 
                                        v-model="branchForm.address" 
                                        type="text" 
                                        class="form-control" 
                                        :class="{ 'is-invalid': branchForm.errors.address }" 
                                        placeholder="Ej.: Av. España 1234, Oficina 201"
                                        style="height: 40px; border-radius: 8px; font-size: 0.85rem;"
                                        required
                                    >
                                    <div v-if="branchForm.errors.address" class="invalid-feedback d-block mt-1">
                                        {{ branchForm.errors.address }}
                                    </div>
                                </div>

                                <!-- SECCIÓN GEOCERCA GPS -->
                                <div class="col-12">
                                    <div class="org-modal-section-card">
                                        <div class="row g-3">
                                            <!-- Cabecera de la Geocerca -->
                                            <div class="col-12">
                                                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 pb-2 border-bottom">
                                                    <div class="d-flex align-items-center" style="gap: 12px !important;">
                                                        <div class="d-flex align-items-center justify-content-center bg-danger-subtle text-danger rounded-circle" style="width: 34px; height: 34px; flex-shrink: 0;">
                                                            <i class="bi bi-geo-alt-fill fs-6"></i>
                                                        </div>
                                                        <div>
                                                            <span class="fw-bold text-dark small d-block">Geocerca GPS y Marcación de Asistencia</span>
                                                            <span class="text-muted" style="font-size: 0.735rem;">Coordenadas y radio para validar la presencia del personal</span>
                                                        </div>
                                                    </div>
                                                    <button 
                                                        type="button" 
                                                        class="btn btn-sm btn-outline-primary d-inline-flex align-items-center" 
                                                        style="border-radius: 6px; font-size: 0.785rem; padding: 6px 14px; gap: 6px !important; font-weight: 500;"
                                                        :disabled="isDetectingGps"
                                                        @click="detectCurrentLocation"
                                                    >
                                                        <span v-if="isDetectingGps" class="spinner-border spinner-border-sm"></span>
                                                        <i v-else class="bi bi-crosshair"></i>
                                                        <span>{{ isDetectingGps ? 'Obteniendo GPS...' : 'Usar mi ubicación actual' }}</span>
                                                    </button>
                                                </div>
                                            </div>

                                            <!-- Coordenadas y Radio -->
                                            <div class="col-12 col-md-4">
                                                <label for="bm_lat" class="form-label small fw-semibold text-secondary mb-1">
                                                    Latitud GPS <span class="text-danger">*</span>
                                                </label>
                                                <input 
                                                    id="bm_lat" 
                                                    v-model.number="branchForm.latitude" 
                                                    type="number" 
                                                    step="0.000001" 
                                                    class="form-control" 
                                                    :class="{ 'is-invalid': branchForm.errors.latitude }"
                                                    style="height: 38px; border-radius: 6px; font-size: 0.85rem;"
                                                    required
                                                >
                                            </div>
                                            <div class="col-12 col-md-4">
                                                <label for="bm_lng" class="form-label small fw-semibold text-secondary mb-1">
                                                    Longitud GPS <span class="text-danger">*</span>
                                                </label>
                                                <input 
                                                    id="bm_lng" 
                                                    v-model.number="branchForm.longitude" 
                                                    type="number" 
                                                    step="0.000001" 
                                                    class="form-control" 
                                                    :class="{ 'is-invalid': branchForm.errors.longitude }"
                                                    style="height: 38px; border-radius: 6px; font-size: 0.85rem;"
                                                    required
                                                >
                                            </div>
                                            <div class="col-12 col-md-4">
                                                <label for="bm_radius" class="form-label small fw-semibold text-secondary mb-1">
                                                    Radio permitido (metros) <span class="text-danger">*</span>
                                                </label>
                                                <input 
                                                    id="bm_radius" 
                                                    v-model.number="branchForm.allowed_radius_meters" 
                                                    type="number" 
                                                    min="10" 
                                                    max="5000" 
                                                    class="form-control" 
                                                    :class="{ 'is-invalid': branchForm.errors.allowed_radius_meters }"
                                                    style="height: 38px; border-radius: 6px; font-size: 0.85rem; font-weight: 600;"
                                                    required
                                                >
                                            </div>

                                            <!-- Distancias sugeridas para marcar con botones de esquinas normales -->
                                            <div class="col-12">
                                                <div class="pt-1">
                                                    <div class="d-flex align-items-center justify-content-between mb-2">
                                                        <span class="small fw-semibold text-secondary" style="font-size: 0.785rem;">
                                                            Distancias sugeridas para marcar:
                                                        </span>
                                                        <span class="badge bg-body-secondary text-secondary border fw-medium" style="font-size: 0.725rem;">
                                                            Seleccionado: {{ branchForm.allowed_radius_meters ?? 0 }} m
                                                        </span>
                                                    </div>
                                                    <div class="d-flex align-items-center flex-wrap" style="gap: 8px !important;">
                                                        <button 
                                                            v-for="r in [50, 100, 200, 500, 1000]" 
                                                            :key="r" 
                                                            type="button" 
                                                            class="btn btn-sm d-inline-flex align-items-center justify-content-center"
                                                            :class="branchForm.allowed_radius_meters === r ? 'btn-primary shadow-sm' : 'btn-outline-secondary'"
                                                            style="border-radius: 6px !important; font-size: 0.8rem; font-weight: 600; padding: 6px 14px !important;"
                                                            @click="setRadiusPreset(r)"
                                                        >
                                                            {{ r }} metros
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Switch Sede Activa -->
                                <div class="col-12">
                                    <div class="org-modal-section-card d-flex align-items-center justify-content-between">
                                        <div class="d-flex align-items-center" style="gap: 12px !important;">
                                            <div class="d-flex align-items-center justify-content-center bg-success-subtle text-success rounded-circle" style="width: 32px; height: 32px; flex-shrink: 0;">
                                                <i class="bi bi-power fs-6"></i>
                                            </div>
                                            <div>
                                                <div class="fw-semibold text-dark small">Sede Activa</div>
                                                <div class="text-muted" style="font-size: 0.75rem;">Las sedes inactivas no permiten marcación ni aparecen en turnos laborales.</div>
                                            </div>
                                        </div>
                                        <div class="form-check form-switch m-0 ps-3">
                                            <input id="bm_active" v-model="branchForm.is_active" class="form-check-input" type="checkbox" role="switch" style="cursor: pointer; width: 42px; height: 22px;">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Pie del Modal con Botón de Ancho Adecuado -->
                        <div class="modal-footer py-3 px-4 border-top d-flex justify-content-end" style="gap: 10px !important;">
                            <button type="button" class="btn btn-outline-secondary btn-sm px-3" style="height: 38px; border-radius: 6px; font-weight: 600;" @click="closeBranchModal">
                                Cancelar
                            </button>
                            <button 
                                type="submit" 
                                class="btn btn-primary btn-sm d-inline-flex align-items-center justify-content-center px-4" 
                                style="height: 38px; border-radius: 6px; font-weight: 600; white-space: nowrap !important; min-width: 140px; gap: 8px !important;" 
                                :disabled="branchForm.processing"
                            >
                                <span v-if="branchForm.processing" class="spinner-border spinner-border-sm"></span>
                                <span>{{ editingBranch ? 'Guardar Cambios' : 'Crear Sede' }}</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- =========================================================================
             MODAL DIALOG 2: GESTIÓN DE PLANTILLA PREVISTA (STAFFING)
             ========================================================================= -->
        <div v-if="showStaffingModal" class="modal-backdrop fade show" style="z-index: 1050;"></div>
        <div 
            v-if="showStaffingModal" 
            class="modal fade show d-block" 
            tabindex="-1" 
            role="dialog" 
            style="z-index: 1055;"
            @click.self="closeStaffingModal"
        >
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content border-0 shadow-lg" style="border-radius: 12px; overflow: hidden;">
                    <!-- Cabecera -->
                    <div class="modal-header py-3 px-4 border-bottom">
                        <div class="d-flex align-items-center" style="gap: 14px !important;">
                            <div class="kpi-icon-pill bg-primary-subtle text-primary" style="width: 42px; height: 42px; font-size: 1.25rem; flex-shrink: 0;">
                                <i class="bi bi-sliders"></i>
                            </div>
                            <div>
                                <h2 class="h6 fw-bold mb-0 text-dark">
                                    Plantilla Prevista · {{ activeStaffingBranch?.name }}
                                </h2>
                                <span class="small text-muted" style="font-size: 0.775rem;">
                                    Define los cargos requeridos y la cantidad de plazas presupuestadas para esta sede.
                                </span>
                            </div>
                        </div>
                        <button type="button" class="btn-close" aria-label="Cerrar" @click="closeStaffingModal"></button>
                    </div>

                    <!-- Cuerpo -->
                    <div class="modal-body p-4" style="max-height: calc(85vh - 120px); overflow-y: auto;">
                        <div v-if="staffingErrors" class="alert alert-danger py-2 px-3 small mb-3 border-0" role="alert">
                            <i class="bi bi-exclamation-triangle-fill me-1"></i> {{ staffingErrors }}
                        </div>

                        <!-- Selector para Agregar Cargo con Icono y Espaciado Amplio -->
                        <div v-if="addablePositions.length" class="mb-4 org-modal-section-card d-flex align-items-center justify-content-between flex-wrap gap-2">
                            <div class="small fw-semibold text-dark d-flex align-items-center" style="gap: 8px !important;">
                                <i class="bi bi-plus-circle-fill text-primary fs-6"></i>
                                <span>Incorporar cargo a la plantilla:</span>
                            </div>
                            <select class="form-select form-select-sm w-auto" style="min-width: 250px; border-radius: 6px; height: 38px; font-size: 0.85rem;" @change="addPositionToStaffing">
                                <option value="">+ Seleccionar cargo del catálogo...</option>
                                <option v-for="p in addablePositions" :key="p.id" :value="p.id">{{ p.name }}</option>
                            </select>
                        </div>

                        <!-- Tabla de Plantilla en el Modal -->
                        <div v-if="staffingDraft.length" class="table-responsive border rounded-3 overflow-hidden">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="org-drawer-table-head">
                                    <tr style="font-size: 0.75rem;" class="text-uppercase text-muted">
                                        <th class="ps-3 py-2" style="white-space: nowrap !important;">Cargo</th>
                                        <th class="py-2" style="white-space: nowrap !important;">Área</th>
                                        <th class="text-end py-2" style="width: 140px; white-space: nowrap !important;">Plazas Previstas</th>
                                        <th class="pe-3 text-end py-2" style="width: 80px; white-space: nowrap !important;">Acción</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="p in staffingDraft" :key="p.position_id">
                                        <td class="ps-3 py-2">
                                            <span class="fw-bold text-dark" style="font-size: 0.875rem;">
                                                {{ positionById.get(p.position_id)?.name ?? 'Cargo no catalogado' }}
                                            </span>
                                            <i v-if="positionById.get(p.position_id)?.is_roaming" class="bi bi-truck text-info ms-1" title="Cargo itinerante"></i>
                                        </td>
                                        <td class="small text-muted py-2">
                                            <span class="badge bg-body-secondary text-secondary border">
                                                {{ departmentName(positionById.get(p.position_id)?.department_id ?? null) }}
                                            </span>
                                        </td>
                                        <td class="text-end py-2">
                                            <input 
                                                type="number" 
                                                min="0" 
                                                max="9999" 
                                                class="form-control form-control-sm text-end fw-bold ms-auto" 
                                                style="width: 85px; border-radius: 6px; height: 32px;" 
                                                :value="p.planned" 
                                                @input="setPlannedInModal(p.position_id, $event.target.value)"
                                            >
                                        </td>
                                        <td class="pe-3 text-end py-2">
                                            <button 
                                                type="button" 
                                                class="btn btn-sm btn-outline-danger p-0 d-inline-flex align-items-center justify-content-center" 
                                                style="border-radius: 6px; width: 32px; height: 32px;"
                                                title="Quitar de la plantilla"
                                                @click="removePositionFromStaffing(p.position_id)"
                                            >
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-else class="text-center py-5 text-muted small">
                            <i class="bi bi-sliders fs-1 d-block mb-3 opacity-50 text-primary"></i>
                            <span>No hay cargos en la plantilla de esta sede. Agrega uno con el selector superior.</span>
                        </div>
                    </div>

                    <!-- Pie del Modal -->
                    <div class="modal-footer py-3 px-4 border-top d-flex justify-content-end" style="gap: 10px !important;">
                        <button type="button" class="btn btn-outline-secondary btn-sm" style="height: 38px; border-radius: 6px; font-weight: 600;" @click="closeStaffingModal">
                            Cancelar
                        </button>
                        <button 
                            type="button" 
                            class="btn btn-primary btn-sm d-inline-flex align-items-center justify-content-center px-4" 
                            style="height: 38px; border-radius: 6px; font-weight: 600; min-width: 140px; gap: 8px !important;" 
                            :disabled="isSavingStaffing"
                            @click="saveStaffingModal"
                        >
                            <span v-if="isSavingStaffing" class="spinner-border spinner-border-sm"></span>
                            <span>Guardar plantilla</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- =========================================================================
             MODAL DIALOG 3: CREAR / EDITAR ÍTEM DE CATÁLOGO (ÁREA, CARGO, ZONA)
             ========================================================================= -->
        <div v-if="showCatalogModal" class="modal-backdrop fade show" style="z-index: 1050;"></div>
        <div 
            v-if="showCatalogModal" 
            class="modal fade show d-block" 
            tabindex="-1" 
            role="dialog" 
            style="z-index: 1055;"
            @click.self="closeCatalogModal"
        >
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0 shadow-lg" style="border-radius: 12px; overflow: hidden;">
                    <!-- Cabecera -->
                    <div class="modal-header py-3 px-4 border-bottom">
                        <div class="d-flex align-items-center" style="gap: 14px !important;">
                            <div class="kpi-icon-pill bg-primary-subtle text-primary" style="width: 40px; height: 40px; flex-shrink: 0;">
                                <i :class="CATALOGS[editingCatalog]?.icon" class="fs-5"></i>
                            </div>
                            <h2 class="h6 fw-bold mb-0 text-dark">
                                {{ editingCatalogId ? `Editar ${CATALOGS[editingCatalog]?.one}` : `${CATALOGS[editingCatalog]?.isNew}` }}
                            </h2>
                        </div>
                        <button type="button" class="btn-close" aria-label="Cerrar" @click="closeCatalogModal"></button>
                    </div>

                    <!-- Formulario -->
                    <form @submit.prevent="submitCatalogModal">
                        <div class="modal-body p-4" style="max-height: calc(85vh - 120px); overflow-y: auto;">
                            <!-- Nombre -->
                            <div class="mb-3">
                                <label :for="`${editingCatalog}_name`" class="form-label fw-semibold small text-dark mb-1">
                                    Nombre <span class="text-danger">*</span>
                                </label>
                                <input 
                                    :id="`${editingCatalog}_name`" 
                                    v-model="catalogForm.name" 
                                    class="form-control" 
                                    :class="{ 'is-invalid': catalogForm.errors.name }" 
                                    :maxlength="editingCatalog === 'positions' ? 100 : 120" 
                                    placeholder="Ej.: Operaciones, Conductor, Zona Norte..."
                                    style="height: 40px; border-radius: 8px; font-size: 0.85rem;"
                                    required
                                >
                                <div v-if="catalogForm.errors.name" class="invalid-feedback d-block mt-1">
                                    {{ catalogForm.errors.name }}
                                </div>
                            </div>

                            <!-- Código -->
                            <div class="mb-3">
                                <label :for="`${editingCatalog}_code`" class="form-label fw-semibold small text-dark mb-1">
                                    Código <span class="text-muted fw-normal">(Opcional)</span>
                                </label>
                                <input 
                                    :id="`${editingCatalog}_code`" 
                                    v-model="catalogForm.code" 
                                    class="form-control" 
                                    :class="{ 'is-invalid': catalogForm.errors.code }" 
                                    maxlength="30"
                                    placeholder="Ej.: OP-01, ZN-01, CHF..."
                                    style="height: 40px; border-radius: 8px; font-size: 0.85rem;"
                                >
                                <div v-if="catalogForm.errors.code" class="invalid-feedback d-block mt-1">
                                    {{ catalogForm.errors.code }}
                                </div>
                            </div>

                            <!-- Jefe de área: jefe inmediato de su personal -->
                            <div v-if="editingCatalog === 'departments'" class="mb-3">
                                <label for="dep_head" class="form-label fw-semibold small text-dark mb-1">
                                    Jefe de área <span class="text-muted fw-normal">(Opcional)</span>
                                </label>
                                <select id="dep_head" v-model="catalogForm.head_id" class="form-select" :class="{ 'is-invalid': catalogForm.errors.head_id }" style="height: 40px; border-radius: 8px; font-size: 0.85rem;">
                                    <option :value="null">Sin jefe de área</option>
                                    <option v-for="s in supervisors" :key="s.id" :value="s.id">{{ s.name }}</option>
                                </select>
                                <div class="form-text small">Es el jefe inmediato del personal del área: aprueba sus vacaciones y permisos (junto con RR. HH.) y recibe el aviso de cada solicitud.</div>
                                <div v-if="catalogForm.errors.head_id" class="invalid-feedback d-block mt-1">{{ catalogForm.errors.head_id }}</div>
                            </div>

                            <!-- Campos Específicos para Cargos -->
                            <template v-if="editingCatalog === 'positions'">
                                <!-- Área / Departamento -->
                                <div class="mb-3">
                                    <label for="pos_department" class="form-label fw-semibold small text-dark mb-1">
                                        Área asociada <span class="text-muted fw-normal">(Opcional)</span>
                                    </label>
                                    <select 
                                        id="pos_department" 
                                        v-model="catalogForm.department_id" 
                                        class="form-select" 
                                        :class="{ 'is-invalid': catalogForm.errors.department_id }"
                                        style="height: 40px; border-radius: 8px; font-size: 0.85rem;"
                                    >
                                        <option :value="null">Sin área asignada</option>
                                        <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
                                    </select>
                                    <div v-if="catalogForm.errors.department_id" class="invalid-feedback d-block mt-1">
                                        {{ catalogForm.errors.department_id }}
                                    </div>
                                </div>

                                <!-- Funciones / Descripción -->
                                <div class="mb-3">
                                    <label for="pos_desc" class="form-label fw-semibold small text-dark mb-1">
                                        Funciones y descripción <span class="text-muted fw-normal">(Opcional)</span>
                                    </label>
                                    <textarea 
                                        id="pos_desc" 
                                        v-model="catalogForm.description" 
                                        class="form-control" 
                                        :class="{ 'is-invalid': catalogForm.errors.description }" 
                                        rows="2" 
                                        maxlength="255"
                                        placeholder="Breve descripción de las funciones del rol..."
                                        style="border-radius: 8px; font-size: 0.85rem;"
                                    ></textarea>
                                    <div v-if="catalogForm.errors.description" class="invalid-feedback d-block mt-1">
                                        {{ catalogForm.errors.description }}
                                    </div>
                                </div>

                                <!-- Switch Itinerante -->
                                <div class="org-modal-section-card mb-3 d-flex align-items-center justify-content-between">
                                    <div class="d-flex align-items-center" style="gap: 12px !important;">
                                        <div class="d-flex align-items-center justify-content-center bg-info-subtle text-info rounded-circle" style="width: 32px; height: 32px; flex-shrink: 0;">
                                            <i class="bi bi-truck fs-6"></i>
                                        </div>
                                        <div>
                                            <div class="fw-semibold text-dark small">Cargo Itinerante</div>
                                            <div class="text-muted" style="font-size: 0.75rem;">Para choferes y despacho: permite marcar asistencia en ruta o en cualquier sede.</div>
                                        </div>
                                    </div>
                                    <div class="form-check form-switch m-0 ps-3">
                                        <input id="pos_roaming" v-model="catalogForm.is_roaming" class="form-check-input" type="checkbox" role="switch" style="cursor: pointer; width: 42px; height: 22px;">
                                    </div>
                                </div>
                            </template>

                            <!-- Switch Activo / Inactivo -->
                            <div class="org-modal-section-card d-flex align-items-center justify-content-between">
                                <div class="d-flex align-items-center" style="gap: 12px !important;">
                                    <div class="d-flex align-items-center justify-content-center bg-success-subtle text-success rounded-circle" style="width: 32px; height: 32px; flex-shrink: 0;">
                                        <i class="bi bi-power fs-6"></i>
                                    </div>
                                    <div>
                                        <div class="fw-semibold text-dark small">Estado Activo</div>
                                        <div class="text-muted" style="font-size: 0.75rem;">Los registros inactivos no aparecen en nuevas asignaciones.</div>
                                    </div>
                                </div>
                                <div class="form-check form-switch m-0 ps-3">
                                    <input id="cat_active" v-model="catalogForm.is_active" class="form-check-input" type="checkbox" role="switch" style="cursor: pointer; width: 42px; height: 22px;">
                                </div>
                            </div>
                        </div>

                        <!-- Pie del Modal -->
                        <div class="modal-footer py-3 px-4 border-top d-flex justify-content-end" style="gap: 10px !important;">
                            <button type="button" class="btn btn-outline-secondary btn-sm px-3" style="height: 38px; border-radius: 6px; font-weight: 600;" @click="closeCatalogModal">
                                Cancelar
                            </button>
                            <button 
                                type="submit" 
                                class="btn btn-primary btn-sm d-inline-flex align-items-center justify-content-center px-4" 
                                style="height: 38px; border-radius: 6px; font-weight: 600; min-width: 140px; gap: 8px !important;" 
                                :disabled="catalogForm.processing"
                            >
                                <span v-if="catalogForm.processing" class="spinner-border spinner-border-sm"></span>
                                <span>{{ editingCatalogId ? 'Guardar Cambios' : 'Crear Registro' }}</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <!-- =========================================================================
             TOAST FLOTANTE MODERNO (REEMPLAZA ALERTS NATIVOS DEL NAVEGADOR)
             ========================================================================= -->
        <teleport to="body">
            <div v-if="toast.show" class="toast-stack position-fixed bottom-0 end-0 p-3" style="z-index: 1090;">
                <div class="alert toast-item shadow-lg" :class="`toast-${toast.type}`" role="alert">
                    <div class="toast-icon-badge" :class="`toast-badge-${toast.type}`">
                        <i class="bi" :class="toastIcons[toast.type]"></i>
                    </div>
                    <div class="toast-content">
                        <strong class="toast-title">{{ toastTitles[toast.type] }}</strong>
                        <span class="toast-text">{{ toast.message }}</span>
                    </div>
                    <button type="button" class="toast-close-btn" aria-label="Cerrar" @click="toast.show = false">
                        <i class="bi bi-x-lg" style="font-size: 0.75rem;"></i>
                    </button>
                </div>
            </div>
        </teleport>
    </AppLayout>
</template>

<style scoped>
/* Card de Cabecera y Estilo Corporativo */
.org-header-card {
    background: var(--bg-surface, #ffffff);
    border: 1px solid rgba(0, 0, 0, 0.08);
    border-radius: 12px;
}

[data-bs-theme="dark"] .org-header-card {
    background: var(--bg-surface, #111622) !important;
    border-color: var(--border-color, #1a2234) !important;
}

.org-header-icon {
    width: 48px;
    height: 48px;
    background: linear-gradient(135deg, #0284c7 0%, #2563eb 100%);
    color: #ffffff;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.35rem;
}

.org-action-btn {
    height: 38px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 0.85rem;
    padding: 0 1rem;
}

/* Tarjetas de Métricas KPI */
.kpi-metric-card {
    background: var(--bg-surface, #ffffff);
    border: 1px solid rgba(0, 0, 0, 0.07);
    border-radius: 12px;
    transition: transform 0.15s ease, box-shadow 0.15s ease;
}

.kpi-metric-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 14px rgba(0, 0, 0, 0.06) !important;
}

[data-bs-theme="dark"] .kpi-metric-card {
    background: var(--bg-surface, #111622) !important;
    border-color: var(--border-color, #1a2234) !important;
}

.kpi-icon-pill {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
}

/* Navegación Segmentada (Pills Elegantes) */
.nav-segment-group {
    display: inline-flex;
    background: #f1f5f9;
    padding: 4px;
    border-radius: 10px;
    gap: 4px;
}

.nav-segment-btn {
    border: none;
    background: transparent;
    padding: 8px 18px;
    font-size: 0.85rem;
    font-weight: 600;
    color: #64748b;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
}

.nav-segment-btn:hover {
    color: #1e293b;
}

.nav-segment-btn.active {
    background: #ffffff;
    color: #0284c7;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
}

[data-bs-theme="dark"] .nav-segment-group {
    background: #0d121c;
    border: 1px solid #1a2234;
}

[data-bs-theme="dark"] .nav-segment-btn {
    color: #94a3b8;
}

[data-bs-theme="dark"] .nav-segment-btn:hover {
    color: #f8fafc;
}

[data-bs-theme="dark"] .nav-segment-btn.active {
    background: #1e293b;
    color: #38bdf8;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.35);
}

/* Tarjeta de Filtros */
.org-filter-card {
    background: var(--bg-surface, #ffffff);
    border: 1px solid rgba(0, 0, 0, 0.08);
    border-radius: 12px;
}

[data-bs-theme="dark"] .org-filter-card {
    background: var(--bg-surface, #111622) !important;
    border-color: var(--border-color, #1a2234) !important;
}

/* Árbol y Tabla de Sedes */
.org-tree-card {
    background: var(--bg-surface, #ffffff);
    border: 1px solid rgba(0, 0, 0, 0.08);
    border-radius: 12px;
    overflow: hidden;
}

[data-bs-theme="dark"] .org-tree-card {
    background: var(--bg-surface, #111622) !important;
    border-color: var(--border-color, #1a2234) !important;
}

.org-zone-header {
    background: #f8fafc;
}

[data-bs-theme="dark"] .org-zone-header {
    background: #0d121c !important;
    border-color: #1a2234 !important;
}

.org-zone-icon {
    width: 34px;
    height: 34px;
    background: rgba(5, 150, 105, 0.12);
    color: #059669;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
}

.org-table-header th {
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #64748b;
    font-weight: 600;
    padding-top: 0.85rem;
    padding-bottom: 0.85rem;
    border-bottom: 1.5px solid #e2e8f0;
}

[data-bs-theme="dark"] .org-table-header th {
    background: #161f30 !important;
    color: #94a3b8 !important;
    border-bottom-color: #1a2234 !important;
}

.org-table {
    --bs-table-bg: transparent;
}

[data-bs-theme="dark"] .org-table {
    --bs-table-bg: transparent;
    --bs-table-hover-bg: rgba(255, 255, 255, 0.03);
    color: #f8fafc;
}

.org-row {
    transition: background-color 0.15s ease;
}

.org-row:hover {
    background-color: #f8fafc !important;
}

[data-bs-theme="dark"] .org-row:hover {
    background-color: rgba(255, 255, 255, 0.04) !important;
}

/* Fila activa/expandida con contraste perfecto tanto en Light como en Dark Mode */
.org-row-active {
    background-color: #f0f9ff !important;
}

[data-bs-theme="dark"] .org-row-active {
    background-color: rgba(2, 132, 199, 0.18) !important;
}

[data-bs-theme="dark"] .org-row-active td {
    background-color: transparent !important;
    color: #f8fafc !important;
}

[data-bs-theme="dark"] .org-row-active .text-dark {
    color: #f8fafc !important;
}

.org-chevron-pill {
    width: 24px;
    height: 24px;
    border-radius: 6px;
    background: #e2e8f0;
    color: #475569;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.75rem;
    transition: transform 0.2s ease, background 0.2s ease;
}

.org-chevron-pill.expanded {
    transform: rotate(90deg);
    background: #0284c7;
    color: #ffffff;
}

[data-bs-theme="dark"] .org-chevron-pill {
    background: #1e293b;
    color: #94a3b8;
}

[data-bs-theme="dark"] .org-chevron-pill.expanded {
    background: #0284c7;
    color: #ffffff;
}

/* Drawer / Panel expandible */
.org-drawer-cell {
    background-color: #f8fafc;
}

.org-drawer-panel {
    background: #ffffff;
    border: 1px solid #e2e8f0;
}

[data-bs-theme="dark"] .org-drawer-cell {
    background-color: #0b0f17 !important;
    border-color: #1a2234 !important;
}

[data-bs-theme="dark"] .org-drawer-panel {
    background: #111622 !important;
    border-color: #1a2234 !important;
}

[data-bs-theme="dark"] .org-drawer-table-head th {
    background-color: #161f30 !important;
    color: #94a3b8 !important;
    border-color: #243048 !important;
}

[data-bs-theme="dark"] .org-drawer-panel table {
    --bs-table-bg: transparent;
    --bs-table-hover-bg: rgba(255, 255, 255, 0.03);
    color: #f8fafc;
    border-color: #243048 !important;
}

[data-bs-theme="dark"] .org-drawer-panel table tbody tr td {
    border-color: #1a2234 !important;
    color: #f8fafc !important;
}

.org-drawer-empty {
    background: #f8fafc;
    border-color: #e2e8f0;
}

[data-bs-theme="dark"] .org-drawer-empty {
    background: #0d121c !important;
    border-color: #1a2234 !important;
    color: #94a3b8 !important;
}

/* Tarjeta de Advertencia para Cargos no Homologados */
.org-advisory-card {
    background: #fffbeb;
    border: 1.5px solid #fde68a;
    transition: all 0.2s ease;
}

.org-advisory-icon {
    width: 42px;
    height: 42px;
    border-radius: 8px;
    background: rgba(245, 158, 11, 0.18);
    color: #d97706;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
}

.org-advisory-badge {
    background: #fef3c7;
    color: #92400e;
    border: 1px solid #fde68a;
}

[data-bs-theme="dark"] .org-advisory-card {
    background: rgba(245, 158, 11, 0.09) !important;
    border-color: rgba(245, 158, 11, 0.3) !important;
}

[data-bs-theme="dark"] .org-advisory-card h4,
[data-bs-theme="dark"] .org-advisory-card .text-dark {
    color: #fef3c7 !important;
}

[data-bs-theme="dark"] .org-advisory-card p,
[data-bs-theme="dark"] .org-advisory-card .text-secondary {
    color: #fde68a !important;
}

[data-bs-theme="dark"] .org-advisory-badge {
    background: rgba(245, 158, 11, 0.22) !important;
    color: #fde68a !important;
    border: 1px solid rgba(245, 158, 11, 0.45) !important;
}

/* Items Modernos con esquinas sutiles (NO redondas tipo píldora) */
.org-uncatalogued-item {
    display: inline-flex;
    align-items: center;
    background: #ffffff;
    border: 1.5px solid #e2e8f0;
    border-radius: 6px;
    padding: 6px 12px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
    transition: all 0.15s ease;
}

.org-uncatalogued-item:hover {
    border-color: #cbd5e1;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
}

[data-bs-theme="dark"] .org-uncatalogued-item {
    background: #161d2d !important;
    border-color: #243048 !important;
    color: #f8fafc !important;
}

[data-bs-theme="dark"] .org-uncatalogued-item:hover {
    border-color: #38bdf8 !important;
    background: #1a2336 !important;
}

[data-bs-theme="dark"] .org-uncatalogued-item .text-dark {
    color: #f8fafc !important;
}

[data-bs-theme="dark"] .org-uncatalogued-item .badge {
    background: #0d121c !important;
    color: #94a3b8 !important;
    border-color: #243048 !important;
}

.bg-amber-subtle-soft {
    background-color: rgba(245, 158, 11, 0.08);
}

/* Colores de ayuda */
.text-indigo { color: #6366f1 !important; }
.bg-indigo-subtle { background-color: rgba(99, 102, 241, 0.12) !important; }
.text-emerald { color: #059669 !important; }
.bg-emerald-subtle { background-color: rgba(5, 150, 105, 0.12) !important; }
.text-amber { color: #d97706 !important; }
.bg-amber-subtle { background-color: rgba(245, 158, 11, 0.14) !important; }

/* Acordeón de detalles */
.org-details-accordion summary {
    list-style: none;
}

.org-breakdown-summary {
    background: #f8fafc;
    border-color: #e2e8f0;
}

[data-bs-theme="dark"] .org-breakdown-summary {
    background: #161f30 !important;
    border-color: #1a2234 !important;
    color: #cbd5e1 !important;
}

.org-breakdown-card {
    background: #f8fafc;
    border-color: #e2e8f0;
}

[data-bs-theme="dark"] .org-breakdown-card {
    background: #161f30 !important;
    border-color: #1a2234 !important;
}

/* VISTA DE CATÁLOGOS ORGANIZACIONALES (ESPACIOSO Y ELEGANTE) */
[data-bs-theme="dark"] .org-catalogs-view .card {
    background: var(--bg-surface, #111622) !important;
    border-color: var(--border-color, #1a2234) !important;
}

[data-bs-theme="dark"] .org-catalogs-view .card-header {
    background: var(--bg-surface, #111622) !important;
    border-color: var(--border-color, #1a2234) !important;
}

[data-bs-theme="dark"] .org-catalog-filter-bar {
    background: #0d121c !important;
    border-color: var(--border-color, #1a2234) !important;
}

.org-catalog-suggestions-box {
    margin: 1.5rem !important;
    padding: 1.25rem 1.5rem !important;
    background: #fffbeb;
    border: 1px solid #fde68a;
    border-radius: 10px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
}

[data-bs-theme="dark"] .org-catalog-suggestions-box {
    background: rgba(245, 158, 11, 0.08) !important;
    border-color: rgba(245, 158, 11, 0.25) !important;
}

.org-suggestions-title {
    color: #92400e;
}

[data-bs-theme="dark"] .org-suggestions-title {
    color: #fef3c7 !important;
}

.org-suggestion-pill-btn {
    background: #ffffff;
    border: 1px solid #cbd5e1;
    color: #1e293b;
    border-radius: 8px;
    font-size: 0.825rem;
    font-weight: 500;
    padding: 7px 14px;
    line-height: 1.4;
    transition: all 0.15s ease;
    cursor: pointer;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
}

.org-suggestion-pill-btn:hover {
    border-color: #0284c7;
    background: #f0f9ff;
    color: #0284c7;
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(2, 132, 199, 0.12);
}

[data-bs-theme="dark"] .org-suggestion-pill-btn {
    background: #1e293b !important;
    border-color: #334155 !important;
    color: #f8fafc !important;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
}

[data-bs-theme="dark"] .org-suggestion-pill-btn:hover {
    background: #243048 !important;
    border-color: #38bdf8 !important;
    color: #38bdf8 !important;
}

/* Modales y Secciones de Formulario */
.org-modal-section-card {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 1.25rem 1.5rem;
    transition: border-color 0.15s ease;
}

.org-modal-section-card:hover {
    border-color: #cbd5e1;
}

[data-bs-theme="dark"] .modal-content {
    background-color: #111622 !important;
    border-color: #1a2234 !important;
    color: #f8fafc !important;
}

[data-bs-theme="dark"] .modal-header,
[data-bs-theme="dark"] .modal-footer {
    background-color: #161f30 !important;
    border-color: #1a2234 !important;
}

[data-bs-theme="dark"] .org-modal-section-card,
.dark .org-modal-section-card {
    background: #161f30 !important;
    border-color: #243048 !important;
}

[data-bs-theme="dark"] .org-modal-section-card:hover {
    border-color: #38bdf8 !important;
}
</style>
