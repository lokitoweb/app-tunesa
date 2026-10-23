<script setup>
import { computed, ref, onMounted } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { Modal } from 'bootstrap';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import Pagination from '@/Modules/Core/Components/Pagination.vue';
import TenantBadge from '@/Modules/Core/Components/TenantBadge.vue';
import DateRangePicker from '@/Modules/Core/Components/DateRangePicker.vue';
import { useConfirm } from '@/Modules/Core/Composables/useConfirm';
import { useTenantPicker } from '@/Modules/Core/Composables/useTenantPicker';

const { ask } = useConfirm();
const { ensureTenant, consumeIntent, withTenant } = useTenantPicker();
const editingTenantId = ref(null);

const props = defineProps({
    shifts: Array,
    assignments: Object,
    employees: Array,
    branches: Array,
    today: String,
    canAssign: Boolean,
});

// Conversión amigable de 24h a 12h ("08:00 am")
const hora = (t) => {
    if (!t) return '';
    const [h, m] = t.split(':').map(Number);
    return `${String(h % 12 || 12).padStart(2, '0')}:${String(m).padStart(2, '0')} ${h < 12 ? 'am' : 'pm'}`;
};

const DAYS = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
const ORDERED_DAYS = [
    { index: 1, label: 'Lunes' },
    { index: 2, label: 'Martes' },
    { index: 3, label: 'Miércoles' },
    { index: 4, label: 'Jueves' },
    { index: 5, label: 'Viernes' },
    { index: 6, label: 'Sábado' },
    { index: 0, label: 'Domingo' },
];

const PRESET_COLORS = [
    '#0284C7', // Azul Corporativo
    '#2563EB', // Azul Real
    '#059669', // Esmeralda
    '#D97706', // Ámbar
    '#7C3AED', // Púrpura
    '#DC2626', // Rojo
    '#0D9488', // Verde Azulado
    '#475569', // Pizarra
];

const activeShifts = computed(() => props.shifts.filter((s) => s.is_active));

// =========================================================================
// 1. MODAL DIALOG: CREAR Y EDITAR TURNO (IN-PLACE)
// =========================================================================
const shiftModalElement = ref(null);
let shiftModalInstance = null;
const modalEditing = ref(false);
const editingShiftId = ref(null);
const modalTab = ref('general'); // 'general' | 'schedule' | 'policies'

const defaultShiftData = () => ({
    name: '',
    code: '',
    type: 'fixed',
    start_time: '08:00',
    end_time: '17:00',
    grace_period_minutes: 10,
    daily_hours: 8,
    break_allowed: true,
    break_duration_minutes: 60,
    working_days: [1, 2, 3, 4, 5],
    rotation_work_days: 4,
    rotation_rest_days: 2,
    color: '#0284C7',
    is_active: true,
    description: '',
});

const shiftForm = useForm(defaultShiftData());

// =========================================================================
// 2. MODAL DIALOG: ASIGNAR TURNO A COLABORADORES
// =========================================================================
const assignModalElement = ref(null);
let assignModalInstance = null;

const assign = useForm({
    shift_id: activeShifts.value[0]?.id ?? null,
    branch_id: props.branches[0]?.id ?? null,
    user_ids: [],
    effective_from: props.today,
    effective_until: '',
    rotation_anchor_date: '',
    replace_current: true,
});

const employeeSearch = ref('');
const includeOtherBranches = ref(false);

// Personal de la sucursal elegida (sus sedes vigentes); "incluir otras sedes" muestra a todos
const branchEmployees = computed(() => {
    const all = props.employees || [];
    if (includeOtherBranches.value || !assign.branch_id) return all;
    return all.filter((e) => (e.branch_ids || []).includes(Number(assign.branch_id)));
});

const filteredEmployees = computed(() => {
    if (!employeeSearch.value || !employeeSearch.value.trim()) {
        return branchEmployees.value;
    }
    const q = employeeSearch.value.toLowerCase().trim();
    return branchEmployees.value.filter((e) => {
        const fullName = `${e.first_name || ''} ${e.last_name || ''}`.toLowerCase();
        const reverseName = `${e.last_name || ''} ${e.first_name || ''}`.toLowerCase();
        const code = (e.employee_code || '').toLowerCase();
        const doc = (e.document_number || '').toLowerCase();
        return fullName.includes(q) || reverseName.includes(q) || code.includes(q) || doc.includes(q);
    });
});

function toggleEmployee(empId) {
    const idx = assign.user_ids.indexOf(empId);
    if (idx > -1) {
        assign.user_ids.splice(idx, 1);
    } else {
        assign.user_ids.push(empId);
    }
}

function selectAllFilteredEmployees() {
    filteredEmployees.value.forEach((e) => {
        if (!assign.user_ids.includes(e.id)) {
            assign.user_ids.push(e.id);
        }
    });
}

function clearSelectedEmployees() {
    assign.user_ids = [];
}

function getInitials(first, last) {
    const f = (first || '').trim().charAt(0);
    const l = (last || '').trim().charAt(0);
    return (f + l).toUpperCase() || 'E';
}

onMounted(() => {
    if (shiftModalElement.value) {
        shiftModalInstance = Modal.getOrCreateInstance(shiftModalElement.value);
    }
    if (assignModalElement.value) {
        assignModalInstance = Modal.getOrCreateInstance(assignModalElement.value);
    }
    // Tras elegir empresa en la vista global, reabre el formulario que se pidió
    if (consumeIntent('turno')) openCreateModal();
    if (consumeIntent('asignar')) openAssignModal();
});

function openCreateModal() {
    if (!ensureTenant({ intent: 'turno', message: 'Elige la empresa del nuevo turno.' })) {
        return;
    }
    modalEditing.value = false;
    editingTenantId.value = null;
    editingShiftId.value = null;
    modalTab.value = 'general';
    shiftForm.clearErrors();
    const defaults = defaultShiftData();
    Object.keys(defaults).forEach((k) => {
        shiftForm[k] = defaults[k];
    });
    shiftModalInstance?.show();
}

function openEditModal(shift) {
    modalEditing.value = true;
    editingShiftId.value = shift.id;
    editingTenantId.value = shift.tenant_id ?? null;
    modalTab.value = 'general';
    shiftForm.clearErrors();
    shiftForm.name = shift.name || '';
    shiftForm.code = shift.code || '';
    shiftForm.type = shift.type || 'fixed';
    shiftForm.start_time = shift.start_time ? shift.start_time.substring(0, 5) : '08:00';
    shiftForm.end_time = shift.end_time ? shift.end_time.substring(0, 5) : '17:00';
    shiftForm.grace_period_minutes = shift.grace_period_minutes ?? 10;
    shiftForm.daily_hours = shift.daily_hours ?? 8;
    shiftForm.break_allowed = Boolean(shift.break_allowed);
    shiftForm.break_duration_minutes = shift.break_duration_minutes ?? 60;
    shiftForm.working_days = Array.isArray(shift.working_days) ? [...shift.working_days] : [1, 2, 3, 4, 5];
    shiftForm.rotation_work_days = shift.rotation_work_days ?? 4;
    shiftForm.rotation_rest_days = shift.rotation_rest_days ?? 2;
    shiftForm.color = shift.color || '#0284C7';
    shiftForm.is_active = Boolean(shift.is_active);
    shiftForm.description = shift.description || '';
    shiftModalInstance?.show();
}

function openAssignModal() {
    if (!ensureTenant({ intent: 'asignar', message: 'Elige la empresa cuyos colaboradores quieres asignar.' })) {
        return;
    }
    assign.clearErrors();
    employeeSearch.value = '';
    if (!assign.shift_id && activeShifts.value.length) {
        assign.shift_id = activeShifts.value[0].id;
    }
    if (!assign.branch_id && props.branches.length) {
        assign.branch_id = props.branches[0].id;
    }
    assignModalInstance?.show();
}

function nextTab() {
    if (modalTab.value === 'general') modalTab.value = 'schedule';
    else if (modalTab.value === 'schedule') modalTab.value = 'policies';
}

function prevTab() {
    if (modalTab.value === 'policies') modalTab.value = 'schedule';
    else if (modalTab.value === 'schedule') modalTab.value = 'general';
}

function normalizeTime(field) {
    const m = String(shiftForm[field] ?? '').trim().match(/^(\d{1,2})[:.h]?(\d{2})?$/);
    if (m && Number(m[1]) < 24 && Number(m[2] ?? 0) < 60) {
        shiftForm[field] = `${m[1].padStart(2, '0')}:${m[2] ?? '00'}`;
    }
}

function toggleWorkingDay(dayIndex) {
    const idx = shiftForm.working_days.indexOf(dayIndex);
    if (idx > -1) {
        if (shiftForm.working_days.length > 1) {
            shiftForm.working_days.splice(idx, 1);
        }
    } else {
        shiftForm.working_days.push(dayIndex);
        shiftForm.working_days.sort((a, b) => a - b);
    }
}

function setWorkingDaysPreset(preset) {
    if (preset === 'weekdays') shiftForm.working_days = [1, 2, 3, 4, 5];
    else if (preset === 'mon_sat') shiftForm.working_days = [1, 2, 3, 4, 5, 6];
    else if (preset === 'all') shiftForm.working_days = [0, 1, 2, 3, 4, 5, 6];
}

function submitShiftModal() {
    normalizeTime('start_time');
    normalizeTime('end_time');

    if (modalEditing.value) {
        // Vista global: se guarda en la empresa del turno, sin preguntar
        shiftForm.transform((data) => withTenant(data, editingTenantId.value)).put(`/shifts/${editingShiftId.value}`, {
            preserveScroll: true,
            onSuccess: () => {
                shiftModalInstance?.hide();
            },
        });
    } else {
        shiftForm.transform((data) => data).post('/shifts', {
            preserveScroll: true,
            onSuccess: () => {
                shiftModalInstance?.hide();
                const defaults = defaultShiftData();
                Object.keys(defaults).forEach((k) => {
                    shiftForm[k] = defaults[k];
                });
            },
        });
    }
}

function submitAssign() {
    assign.post('/shifts/assign', {
        preserveScroll: true,
        onSuccess: () => {
            assignModalInstance?.hide();
            assign.reset('user_ids', 'effective_until', 'rotation_anchor_date');
        },
    });
}

async function destroy(shift) {
    const ok = await ask({
        title: '¿Eliminar turno?',
        message: `¿Estás seguro de eliminar el turno "${shift.name}"? Los colaboradores asignados perderán la asignación asociada a este turno.`,
        confirmText: 'Sí, eliminar',
        cancelText: 'Cancelar',
        variant: 'danger',
    });
    if (ok) {
        router.delete(`/shifts/${shift.id}`, { data: withTenant({}, shift.tenant_id), preserveScroll: true });
    }
}

async function unassign(assignment) {
    const ok = await ask({
        title: '¿Finalizar asignación?',
        message: `¿Estás seguro de finalizar la asignación vigente de ${assignment.employee || 'este colaborador'} para hoy?`,
        confirmText: 'Sí, finalizar',
        cancelText: 'Cancelar',
        variant: 'warning',
    });
    if (ok) {
        router.delete(`/shifts/assignments/${assignment.id}`, { data: withTenant({}, assignment.tenant_id), preserveScroll: true });
    }
}

const selectedAssignShift = computed(() => {
    return props.shifts.find((s) => s.id === assign.shift_id);
});
</script>

<template>
    <AppLayout title="Turnos y horarios">
        <!-- Header Principal de la Pantalla con Acciones Claras y Espaciado Amplio -->
        <div class="d-flex align-items-center justify-content-between flex-wrap mb-4" style="gap: 1.5rem;">
            <div>
                <h1 class="h2 fw-bold text-dark mb-1">Turnos y horarios</h1>
                <p class="text-secondary mb-0" style="font-size: 0.875rem;">
                    Turnos fijos y rotativos, tolerancia de tardanza y asignación de colaboradores.
                </p>
            </div>
            <div class="d-flex align-items-center" style="gap: 14px !important;">
                <button v-if="canAssign" type="button" class="btn btn-outline-primary d-inline-flex align-items-center shadow-sm" style="height: 42px; border-radius: 8px; font-weight: 600; padding: 0 1.25rem; gap: 8px !important;" @click="openAssignModal">
                    <i class="bi bi-person-check-fill"></i>
                    <span>Asignar turno</span>
                </button>
                <button type="button" class="btn btn-primary d-inline-flex align-items-center shadow-sm" style="height: 42px; border-radius: 8px; font-weight: 600; padding: 0 1.25rem; gap: 8px !important;" @click="openCreateModal">
                    <i class="bi bi-plus-lg"></i>
                    <span>Nuevo turno</span>
                </button>
            </div>
        </div>

        <!-- 1. TABLA PRINCIPAL DE TURNOS (Executive Design System: .emp-table-card) -->
        <div class="emp-table-card mb-5 shadow-sm">
            <div class="emp-table-responsive">
                <table class="emp-table align-middle">
                    <thead>
                        <tr>
                            <th class="ps-4">Turno</th>
                            <th>Tipo</th>
                            <th>Horario</th>
                            <th>Días / Ciclo</th>
                            <th>Tolerancia</th>
                            <th>Asignados</th>
                            <th>Estado</th>
                            <th class="pe-4 text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="shift in shifts" :key="shift.id">
                            <!-- Turno: Dot + Nombre + Badge Código Inline (Sin amontonamiento) -->
                            <td class="ps-4 py-3">
                                <div class="d-flex align-items-center" style="gap: 10px !important;">
                                    <span class="rounded-circle d-inline-block flex-shrink-0 shadow-sm" :style="{ width: '10px', height: '10px', background: shift.color }"></span>
                                    <span class="fw-bold text-dark" style="font-size: 0.885rem;">{{ shift.name }}</span>
                                    <span v-if="shift.code" class="badge text-secondary font-monospace" style="background: rgba(100, 116, 139, 0.1); border-radius: 4px; font-size: 0.72rem; padding: 2px 6px; font-weight: 600; letter-spacing: 0.03em;">{{ shift.code }}</span>
                                </div>
                                <div v-if="shift.tenant_id" style="padding-left: 20px; margin-top: 3px;">
                                    <TenantBadge :tenant-id="shift.tenant_id" />
                                </div>
                            </td>
                            <td class="py-3">
                                <span class="badge border" :class="shift.type === 'rotating' ? 'bg-primary-subtle text-primary border-primary-subtle' : 'bg-body-secondary text-secondary border-secondary-subtle'" style="border-radius: 6px; font-weight: 600; font-size: 0.75rem; padding: 4px 8px;">
                                    {{ shift.type === 'rotating' ? 'Rotativo' : 'Fijo' }}
                                </span>
                            </td>
                            <td class="py-3 text-nowrap" style="font-size: 0.85rem; font-weight: 500; color: #334155;">
                                {{ hora(shift.start_time) }} – {{ hora(shift.end_time) }}
                                <span v-if="shift.crosses_midnight" class="small text-secondary ms-1">(+1 día)</span>
                            </td>
                            <td class="py-3 text-secondary" style="font-size: 0.85rem;">
                                <template v-if="shift.type === 'rotating'">
                                    <span class="fw-semibold text-dark">{{ shift.rotation_work_days }}</span>
                                    <span class="text-muted mx-1">×</span>
                                    <span class="fw-semibold text-dark">{{ shift.rotation_rest_days }}</span>
                                </template>
                                <template v-else>
                                    <span>{{ shift.working_days.map((d) => DAYS[d] ?? d).join(', ') }}</span>
                                </template>
                            </td>
                            <td class="py-3 text-secondary" style="font-size: 0.85rem;">
                                <span class="fw-semibold text-dark">{{ shift.grace_period_minutes }}</span> min
                            </td>
                            <td class="py-3 text-secondary" style="font-size: 0.85rem;">
                                <span class="fw-semibold text-dark">{{ shift.employees_count }}</span>
                            </td>
                            <td class="py-3">
                                <span v-if="shift.is_active" class="badge bg-success-subtle text-success border border-success-subtle" style="border-radius: 6px; font-weight: 600; font-size: 0.75rem; padding: 4px 8px;">
                                    Activo
                                </span>
                                <span v-else class="badge bg-secondary-subtle text-secondary border border-secondary-subtle" style="border-radius: 6px; font-weight: 600; font-size: 0.75rem; padding: 4px 8px;">
                                    Inactivo
                                </span>
                            </td>
                            <!-- Acciones: Solo Iconos Elegantes (btn-table-action estándar del ERP) -->
                            <td class="pe-4 py-3 text-end text-nowrap">
                                <div class="emp-table-actions justify-content-end" style="gap: 8px !important;">
                                    <button type="button" class="btn-table-action" title="Editar turno" @click="openEditModal(shift)">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button type="button" class="btn-table-action btn-table-delete" :title="`Eliminar ${shift.name}`" @click="destroy(shift)">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!shifts.length">
                            <td colspan="8" class="text-center py-5 text-muted">
                                <i class="bi bi-calendar-x fs-1 d-block mb-2 opacity-50"></i>
                                <div class="fw-medium">Aún no hay turnos registrados.</div>
                                <p class="small text-muted mb-3">Crea el primer horario laboral para asignar a tus colaboradores.</p>
                                <button type="button" class="btn btn-sm btn-primary" style="border-radius: 8px; font-weight: 600;" @click="openCreateModal">
                                    <i class="bi bi-plus-lg me-1"></i>Crear turno
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 2. ASIGNACIONES VIGENTES (Separación Generosa, Tabla Limpia y Espaciosa) -->
        <div class="emp-table-card mb-5 shadow-sm">
            <div class="card-header border-bottom bg-transparent py-3 px-4 d-flex align-items-center justify-content-between flex-wrap" style="gap: 1rem;">
                <div class="d-flex align-items-center" style="gap: 10px !important;">
                    <i class="bi bi-people-fill text-primary fs-5"></i>
                    <h2 class="h6 fw-bold mb-0 text-dark">Asignaciones vigentes</h2>
                </div>
                <div class="d-flex align-items-center" style="gap: 12px !important;">
                    <span class="badge bg-body-secondary text-secondary border px-2.5 py-1" style="border-radius: 6px; font-weight: 600; font-size: 0.75rem;">
                        {{ assignments.total ?? assignments.data?.length ?? 0 }} asignaciones activas
                    </span>
                    <button v-if="canAssign" type="button" class="btn btn-sm btn-outline-primary d-inline-flex align-items-center py-1.5 px-3" style="border-radius: 6px; font-weight: 600; font-size: 0.775rem; gap: 6px !important;" @click="openAssignModal">
                        <i class="bi bi-plus"></i>
                        <span>Asignar</span>
                    </button>
                </div>
            </div>
            <div class="emp-table-responsive">
                <table class="emp-table align-middle">
                    <thead>
                        <tr>
                            <th class="ps-4">Colaborador</th>
                            <th>Turno</th>
                            <th>Sucursal</th>
                            <th>Vigencia</th>
                            <th class="pe-4 text-end">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="a in assignments.data" :key="a.id">
                            <td class="ps-4 py-3">
                                <div class="fw-semibold text-dark">{{ a.employee }}</div>
                                <div v-if="a.tenant_id" style="margin-top: 2px;">
                                    <TenantBadge :tenant-id="a.tenant_id" />
                                </div>
                            </td>
                            <td class="py-3">
                                <div class="d-flex align-items-center" style="gap: 8px !important;">
                                    <span class="rounded-circle d-inline-block flex-shrink-0 shadow-sm" :style="{ width: '8px', height: '8px', background: a.color }"></span>
                                    <span class="fw-medium text-dark">{{ a.shift }}</span>
                                </div>
                            </td>
                            <td class="py-3 text-secondary">{{ a.branch }}</td>
                            <td class="py-3 text-nowrap small text-secondary">
                                {{ a.from }} <span class="text-muted mx-1">→</span> {{ a.until ?? 'indefinido' }}
                            </td>
                            <td class="pe-4 py-3 text-end">
                                <button v-if="canAssign" type="button" class="btn btn-sm btn-outline-secondary px-2.5 py-1" style="border-radius: 6px; font-size: 0.775rem; font-weight: 500;" @click="unassign(a)">
                                    Finalizar
                                </button>
                            </td>
                        </tr>
                        <tr v-if="!assignments.data.length">
                            <td colspan="5" class="py-4 text-center text-muted">Sin asignaciones vigentes.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-if="assignments.last_page > 1" class="card-footer bg-transparent border-top p-3">
                <Pagination :paginator="assignments" />
            </div>
        </div>

        <!-- =========================================================================
             MODAL 1: CREAR / EDITAR TURNO (CON CLASES ESTÁNDAR EMP-MODAL)
             ========================================================================= -->
        <div id="shiftModal" ref="shiftModalElement" class="modal fade" tabindex="-1" aria-labelledby="shiftModalTitle" aria-hidden="true" data-bs-backdrop="static">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content emp-modal-content">
                    <!-- Modal Header -->
                    <div class="modal-header emp-modal-header">
                        <div class="d-flex align-items-center" style="gap: 12px !important;">
                            <div class="emp-modal-icon">
                                <i :class="modalEditing ? 'bi bi-pencil-square' : 'bi bi-clock-history'"></i>
                            </div>
                            <div>
                                <h2 id="shiftModalTitle" class="modal-title fs-5 fw-bold text-dark mb-0">
                                    {{ modalEditing ? `Editar turno: ${shiftForm.name || 'Sin nombre'}` : 'Nuevo turno de trabajo' }}
                                </h2>
                                <p class="small text-secondary mb-0">Configura el horario, modalidad de jornada, descansos y tolerancia.</p>
                            </div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                    </div>

                    <!-- Pestañas de Navegación Ejecutiva con Espacio Amplio -->
                    <div class="emp-modal-nav-wrap">
                        <ul class="emp-modal-tabs" role="tablist">
                            <li>
                                <button type="button" class="nav-link" :class="{ active: modalTab === 'general' }" @click="modalTab = 'general'">
                                    <i class="bi bi-clock-history"></i>
                                    <span>1. Horario y Datos</span>
                                </button>
                            </li>
                            <li>
                                <button type="button" class="nav-link" :class="{ active: modalTab === 'schedule' }" @click="modalTab = 'schedule'">
                                    <i class="bi bi-calendar3"></i>
                                    <span>2. Días y Modalidad</span>
                                </button>
                            </li>
                            <li>
                                <button type="button" class="nav-link" :class="{ active: modalTab === 'policies' }" @click="modalTab = 'policies'">
                                    <i class="bi bi-sliders2"></i>
                                    <span>3. Descanso y Estado</span>
                                </button>
                            </li>
                        </ul>
                    </div>

                    <!-- Modal Body Form -->
                    <form @submit.prevent="submitShiftModal">
                        <div class="emp-modal-body">
                            
                            <!-- =========================================================
                                 TAB 1: HORARIO Y DATOS PRINCIPALES
                                 ========================================================= -->
                            <div v-show="modalTab === 'general'">
                                <div class="emp-section-card">
                                    <div class="emp-section-title">
                                        <i class="bi bi-info-circle-fill"></i>
                                        <span>Identificación del Turno</span>
                                    </div>
                                    <div class="row g-3">
                                        <div class="col-12 col-md-7">
                                            <label for="shift_name" class="form-label fw-semibold small text-dark mb-1.5">Nombre del turno <span class="text-danger">*</span></label>
                                            <input id="shift_name" v-model="shiftForm.name" class="form-control" :class="{ 'is-invalid': shiftForm.errors.name }" placeholder="ej. Turno Mañana" required style="border-radius: 8px; height: 42px;">
                                            <div v-if="shiftForm.errors.name" class="invalid-feedback">{{ shiftForm.errors.name }}</div>
                                        </div>
                                        <div class="col-12 col-md-5">
                                            <label for="shift_code" class="form-label fw-semibold small text-dark mb-1.5">Código de turno</label>
                                            <input id="shift_code" v-model="shiftForm.code" class="form-control font-monospace" :class="{ 'is-invalid': shiftForm.errors.code }" placeholder="ej. T-MAÑ" style="border-radius: 8px; height: 42px;">
                                            <div v-if="shiftForm.errors.code" class="invalid-feedback">{{ shiftForm.errors.code }}</div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Color Picker Elegante y Sin Duplicados Confusos -->
                                <div class="emp-section-card">
                                    <div class="emp-section-title">
                                        <i class="bi bi-palette-fill"></i>
                                        <span>Color distintivo en calendario</span>
                                    </div>
                                    <div class="d-flex align-items-center flex-wrap" style="gap: 16px !important;">
                                        <div style="display: flex; align-items: center; gap: 10px !important; flex-wrap: wrap;">
                                            <button v-for="c in PRESET_COLORS" :key="c" type="button" 
                                                    class="shadow-sm p-0 d-inline-flex align-items-center justify-content-center transition" 
                                                    :style="{ width: '32px', height: '32px', borderRadius: '8px', background: c, border: shiftForm.color === c ? '2px solid #0f172a' : '1px solid rgba(0,0,0,0.1)', transform: shiftForm.color === c ? 'scale(1.1)' : 'scale(1)' }" 
                                                    :title="c" @click="shiftForm.color = c">
                                                <i v-if="shiftForm.color === c" class="bi bi-check-lg text-white" style="font-size: 1rem;"></i>
                                            </button>
                                        </div>
                                        <div class="d-flex align-items-center ps-3 border-start" style="gap: 10px !important;">
                                            <label for="custom_shift_color" class="small text-secondary mb-0 fw-semibold">Personalizado:</label>
                                            <input id="custom_shift_color" v-model="shiftForm.color" type="color" class="form-control form-control-color p-0 shadow-sm" style="width: 32px; height: 32px; border-radius: 8px; cursor: pointer;">
                                        </div>
                                    </div>
                                </div>

                                <div class="emp-section-card">
                                    <div class="emp-section-title">
                                        <i class="bi bi-clock-fill"></i>
                                        <span>Horas y Tolerancia</span>
                                    </div>
                                    <div class="row g-3 mb-3">
                                        <div class="col-12 col-md-6">
                                            <label for="shift_start" class="form-label fw-semibold small text-dark mb-1.5">Hora de Entrada (24h) <span class="text-danger">*</span></label>
                                            <input id="shift_start" v-model="shiftForm.start_time" type="text" inputmode="numeric" maxlength="5" placeholder="08:00" class="form-control font-monospace" :class="{ 'is-invalid': shiftForm.errors.start_time }" required style="border-radius: 8px; height: 42px; font-size: 0.95rem;" @blur="normalizeTime('start_time')">
                                            <div class="form-text small text-secondary mt-1">Formato 24 horas (ej. 08:00 o 22:00)</div>
                                            <div v-if="shiftForm.errors.start_time" class="invalid-feedback">{{ shiftForm.errors.start_time }}</div>
                                        </div>
                                        <div class="col-12 col-md-6">
                                            <label for="shift_end" class="form-label fw-semibold small text-dark mb-1.5">Hora de Salida (24h) <span class="text-danger">*</span></label>
                                            <input id="shift_end" v-model="shiftForm.end_time" type="text" inputmode="numeric" maxlength="5" placeholder="17:00" class="form-control font-monospace" :class="{ 'is-invalid': shiftForm.errors.end_time }" required style="border-radius: 8px; height: 42px; font-size: 0.95rem;" @blur="normalizeTime('end_time')">
                                            <div class="form-text small text-secondary mt-1">Formato 24 horas (ej. 17:00 o 06:00)</div>
                                            <div v-if="shiftForm.errors.end_time" class="invalid-feedback">{{ shiftForm.errors.end_time }}</div>
                                        </div>
                                    </div>

                                    <div class="row g-3">
                                        <div class="col-12 col-md-6">
                                            <label for="shift_grace" class="form-label fw-semibold small text-dark mb-1.5">Tolerancia de tardanza (minutos) <span class="text-danger">*</span></label>
                                            <input id="shift_grace" v-model="shiftForm.grace_period_minutes" type="number" min="0" max="240" class="form-control font-monospace" :class="{ 'is-invalid': shiftForm.errors.grace_period_minutes }" required style="border-radius: 8px; height: 42px;">
                                            <div class="form-text small text-secondary mt-1">Minutos permitidos tras la hora de entrada.</div>
                                            <div v-if="shiftForm.errors.grace_period_minutes" class="invalid-feedback">{{ shiftForm.errors.grace_period_minutes }}</div>
                                        </div>
                                        <div class="col-12 col-md-6">
                                            <label for="shift_daily_hours" class="form-label fw-semibold small text-dark mb-1.5">Horas de jornada laboral por día <span class="text-danger">*</span></label>
                                            <input id="shift_daily_hours" v-model="shiftForm.daily_hours" type="number" step="0.25" min="0.5" max="24" class="form-control font-monospace" :class="{ 'is-invalid': shiftForm.errors.daily_hours }" required style="border-radius: 8px; height: 42px;">
                                            <div class="form-text small text-secondary mt-1">Total de horas efectivas diarias requeridas (ej. 8).</div>
                                            <div v-if="shiftForm.errors.daily_hours" class="invalid-feedback">{{ shiftForm.errors.daily_hours }}</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- =========================================================
                                 TAB 2: DÍAS LABORABLES Y MODALIDAD (ESPACIADO GARANTIZADO)
                                 ========================================================= -->
                            <div v-show="modalTab === 'schedule'">
                                <div class="emp-section-card">
                                    <div class="emp-section-title">
                                        <i class="bi bi-diagram-3-fill"></i>
                                        <span>Modalidad de jornada de trabajo</span>
                                    </div>
                                    <div class="row g-3">
                                        <div class="col-12 col-md-6">
                                            <div class="p-3 border rounded-3 cursor-pointer shadow-sm transition" :style="{ borderColor: shiftForm.type === 'fixed' ? 'var(--brand, #0284C7)' : 'var(--border-color)', background: shiftForm.type === 'fixed' ? 'rgba(2, 132, 199, 0.06)' : 'var(--bg-surface)' }" @click="shiftForm.type = 'fixed'">
                                                <div class="d-flex align-items-center" style="gap: 8px !important; margin-bottom: 4px;">
                                                    <i class="bi bi-calendar-check fs-5 text-primary"></i>
                                                    <div class="fw-bold text-dark">Turno Fijo Semanal</div>
                                                </div>
                                                <div class="small text-secondary">Días específicos de la semana (ej. Lunes a Viernes o Lunes a Sábado).</div>
                                            </div>
                                        </div>
                                        <div class="col-12 col-md-6">
                                            <div class="p-3 border rounded-3 cursor-pointer shadow-sm transition" :style="{ borderColor: shiftForm.type === 'rotating' ? 'var(--brand, #0284C7)' : 'var(--border-color)', background: shiftForm.type === 'rotating' ? 'rgba(2, 132, 199, 0.06)' : 'var(--bg-surface)' }" @click="shiftForm.type = 'rotating'">
                                                <div class="d-flex align-items-center" style="gap: 8px !important; margin-bottom: 4px;">
                                                    <i class="bi bi-arrow-repeat fs-5 text-primary"></i>
                                                    <div class="fw-bold text-dark">Turno Rotativo</div>
                                                </div>
                                                <div class="small text-secondary">Ciclos periódicos de trabajo × descanso (ej. 4×2, 6×1 o 14×7).</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Si es Fijo: Botones de días CON ESPACIADO GARANTIZADO DE 10PX -->
                                <div v-if="shiftForm.type === 'fixed'" class="emp-section-card">
                                    <div class="d-flex align-items-center justify-content-between flex-wrap" style="gap: 10px !important; margin-bottom: 12px;">
                                        <div class="emp-section-title mb-0">
                                            <i class="bi bi-calendar-week"></i>
                                            <span>Días laborables seleccionados</span>
                                        </div>
                                        <!-- Presets con espacio garantizado de 8px -->
                                        <div style="display: flex; align-items: center; gap: 8px !important;">
                                            <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-2.5" style="border-radius: 6px; font-size: 0.775rem; font-weight: 500;" @click="setWorkingDaysPreset('weekdays')">
                                                Lunes a Viernes
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-2.5" style="border-radius: 6px; font-size: 0.775rem; font-weight: 500;" @click="setWorkingDaysPreset('mon_sat')">
                                                Lunes a Sábado
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-2.5" style="border-radius: 6px; font-size: 0.775rem; font-weight: 500;" @click="setWorkingDaysPreset('all')">
                                                Toda la semana
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Botones de días en 1 sola fila horizontal de 7 columnas -->
                                    <div class="working-days-grid">
                                        <button v-for="d in ORDERED_DAYS" :key="d.index" type="button" 
                                                class="btn shadow-sm d-inline-flex align-items-center justify-content-center transition day-pill-btn" 
                                                :class="shiftForm.working_days.includes(d.index) ? 'btn-primary' : 'btn-outline-secondary'" 
                                                @click="toggleWorkingDay(d.index)">
                                            <i class="bi" :class="shiftForm.working_days.includes(d.index) ? 'bi-check-circle-fill' : 'bi-circle'"></i>
                                            <span>{{ d.label }}</span>
                                        </button>
                                    </div>
                                    <div v-if="shiftForm.errors.working_days" class="invalid-feedback d-block mt-2">{{ shiftForm.errors.working_days }}</div>
                                </div>

                                <!-- Si es Rotativo: Días de trabajo x Descanso -->
                                <div v-else class="emp-section-card">
                                    <div class="emp-section-title">
                                        <i class="bi bi-arrow-repeat"></i>
                                        <span>Ciclo de Trabajo y Descanso</span>
                                    </div>
                                    <div class="row g-4 mb-3">
                                        <div class="col-12 col-md-6">
                                            <label for="rotation_work" class="form-label fw-semibold small text-dark mb-1.5">Días seguidos de labor <span class="text-danger">*</span></label>
                                            <input id="rotation_work" v-model="shiftForm.rotation_work_days" type="number" min="1" max="60" class="form-control font-monospace" :class="{ 'is-invalid': shiftForm.errors.rotation_work_days }" required style="border-radius: 8px; height: 42px;">
                                            <div class="form-text small text-secondary mt-1">Ejemplo: 4 días consecutivos de trabajo.</div>
                                        </div>
                                        <div class="col-12 col-md-6">
                                            <label for="rotation_rest" class="form-label fw-semibold small text-dark mb-1.5">Días seguidos de descanso <span class="text-danger">*</span></label>
                                            <input id="rotation_rest" v-model="shiftForm.rotation_rest_days" type="number" min="1" max="60" class="form-control font-monospace" :class="{ 'is-invalid': shiftForm.errors.rotation_rest_days }" required style="border-radius: 8px; height: 42px;">
                                            <div class="form-text small text-secondary mt-1">Ejemplo: 2 días consecutivos de descanso.</div>
                                        </div>
                                    </div>
                                    <div class="alert alert-info border-0 d-flex align-items-start p-3 mb-0" style="background: rgba(2, 132, 199, 0.08); border-radius: 8px; font-size: 0.825rem; color: #0369a1; gap: 10px !important;">
                                        <i class="bi bi-info-circle-fill fs-5 flex-shrink-0" style="color: #0284c7;"></i>
                                        <div>
                                            El ciclo rotativo calcula automáticamente los días laborables de cada colaborador tomando como referencia la <strong>fecha de inicio de ciclo</strong> establecida al momento de asignarle el turno.
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- =========================================================
                                 TAB 3: DESCANSO, POLÍTICAS Y ESTADO
                                 ========================================================= -->
                            <div v-show="modalTab === 'policies'">
                                <div class="emp-section-card">
                                    <div class="d-flex align-items-center justify-content-between mb-3">
                                        <div>
                                            <div class="fw-bold text-dark mb-0.5">Permite refrigerio / descanso</div>
                                            <div class="small text-secondary">Habilita la pausa intermedia registrada dentro de la jornada laboral.</div>
                                        </div>
                                        <div class="form-check form-switch mb-0">
                                            <input id="modal_break_allowed" v-model="shiftForm.break_allowed" class="form-check-input" type="checkbox" role="switch" style="width: 2.75em; height: 1.45em; cursor: pointer;">
                                        </div>
                                    </div>
                                    <div v-if="shiftForm.break_allowed" class="pt-3 border-top">
                                        <label for="modal_break_minutes" class="form-label fw-semibold small text-dark mb-1.5">Duración del descanso (minutos) <span class="text-danger">*</span></label>
                                        <input id="modal_break_minutes" v-model="shiftForm.break_duration_minutes" type="number" min="0" max="480" class="form-control font-monospace" :class="{ 'is-invalid': shiftForm.errors.break_duration_minutes }" required style="border-radius: 8px; height: 42px; max-width: 220px;">
                                        <div class="form-text small text-secondary mt-1">Tiempo reglamentario de refrigerio (ej. 45 o 60 minutos).</div>
                                    </div>
                                </div>

                                <div class="emp-section-card d-flex align-items-center justify-content-between">
                                    <div>
                                        <div class="fw-bold text-dark mb-0.5">Turno activo</div>
                                        <div class="small text-secondary">Permite que este turno aparezca disponible para asignación de colaboradores.</div>
                                    </div>
                                    <div class="form-check form-switch mb-0">
                                        <input id="modal_is_active" v-model="shiftForm.is_active" class="form-check-input" type="checkbox" role="switch" style="width: 2.75em; height: 1.45em; cursor: pointer;">
                                    </div>
                                </div>

                                <div class="emp-section-card">
                                    <label for="shift_desc" class="form-label fw-semibold small text-dark mb-1.5">Descripción u observaciones internas</label>
                                    <textarea id="shift_desc" v-model="shiftForm.description" class="form-control" rows="3" style="border-radius: 8px; font-size: 0.875rem;" placeholder="Indicaciones para supervisores, sucursales asignadas, etc..."></textarea>
                                </div>
                            </div>

                        </div>

                        <!-- Modal Footer con Guardar Siempre Disponible y Espaciado Perfecto -->
                        <div class="modal-footer emp-modal-footer">
                            <button type="button" class="btn btn-outline-secondary px-3.5 py-1.5" data-bs-dismiss="modal" style="border-radius: 8px; font-weight: 500;">
                                Cancelar
                            </button>
                            <button type="submit" class="btn btn-primary d-inline-flex align-items-center px-4 py-1.5 shadow-sm" style="border-radius: 8px; font-weight: 600; gap: 8px !important;" :disabled="shiftForm.processing">
                                <span v-if="shiftForm.processing" class="spinner-border spinner-border-sm" role="status"></span>
                                <i v-else class="bi bi-check2"></i>
                                <span>{{ modalEditing ? 'Guardar Cambios' : 'Crear Turno' }}</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- =========================================================================
             MODAL 2: ASIGNAR TURNO A COLABORADORES (MODAL DIALOG DEDICADO)
             ========================================================================= -->
        <div id="assignModal" ref="assignModalElement" class="modal fade" tabindex="-1" aria-labelledby="assignModalTitle" aria-hidden="true" data-bs-backdrop="static">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content emp-modal-content">
                    <!-- Modal Header -->
                    <div class="modal-header emp-modal-header">
                        <div class="d-flex align-items-center" style="gap: 12px !important;">
                            <div class="emp-modal-icon">
                                <i class="bi bi-person-check-fill text-primary"></i>
                            </div>
                            <div>
                                <h2 id="assignModalTitle" class="modal-title fs-5 fw-bold text-dark mb-0">
                                    Asignar turno a colaboradores
                                </h2>
                                <p class="small text-secondary mb-0">Asocia a tu equipo un horario de trabajo y sucursal con vigencia programada.</p>
                            </div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                    </div>

                    <!-- Modal Body Form -->
                    <form @submit.prevent="submitAssign">
                        <div class="emp-modal-body">
                            <div class="emp-section-card">
                                <div class="emp-section-title">
                                    <i class="bi bi-clock-history"></i>
                                    <span>Horario y Sucursal</span>
                                </div>
                                <div class="row g-3">
                                    <div class="col-12 col-md-6">
                                        <label for="modal_assign_shift" class="form-label fw-semibold small text-dark mb-1.5">Turno a asignar <span class="text-danger">*</span></label>
                                        <select id="modal_assign_shift" v-model="assign.shift_id" class="form-select" :class="{ 'is-invalid': assign.errors.shift_id }" required style="border-radius: 8px; height: 42px;">
                                            <option v-for="shift in activeShifts" :key="shift.id" :value="shift.id">
                                                {{ shift.name }} ({{ hora(shift.start_time) }} – {{ hora(shift.end_time) }})
                                            </option>
                                        </select>
                                        <div v-if="assign.errors.shift_id" class="invalid-feedback">{{ assign.errors.shift_id }}</div>
                                    </div>

                                    <div class="col-12 col-md-6">
                                        <label for="modal_assign_branch" class="form-label fw-semibold small text-dark mb-1.5">Sucursal operativa <span class="text-danger">*</span></label>
                                        <select id="modal_assign_branch" v-model="assign.branch_id" class="form-select" :class="{ 'is-invalid': assign.errors.branch_id }" required style="border-radius: 8px; height: 42px;">
                                            <option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
                                        </select>
                                        <div v-if="assign.errors.branch_id" class="invalid-feedback">{{ assign.errors.branch_id }}</div>
                                    </div>
                                </div>
                            </div>

                            <!-- SELECCIONAR COLABORADORES CON BÚSQUEDA Y ESPACIADO ELEGANTE (Screenshot 3 Solved) -->
                            <div class="emp-section-card">
                                <div class="d-flex align-items-center justify-content-between flex-wrap" style="gap: 10px !important; margin-bottom: 12px;">
                                    <div class="emp-section-title mb-0">
                                        <i class="bi bi-people-fill"></i>
                                        <span>Seleccionar Colaboradores <span class="text-danger">*</span></span>
                                    </div>
                                    <div class="d-flex align-items-center" style="gap: 10px !important;">
                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle font-monospace" style="border-radius: 6px; font-size: 0.725rem; padding: 4px 8px;">
                                            {{ assign.user_ids.length }} seleccionados
                                        </span>
                                        <button 
                                            type="button" 
                                            class="btn btn-sm btn-link text-decoration-none p-0 text-primary fw-semibold" 
                                            style="font-size: 0.775rem;" 
                                            @click="selectAllFilteredEmployees"
                                        >
                                            Seleccionar visibles
                                        </button>
                                        <span class="text-muted" style="font-size: 0.75rem;">·</span>
                                        <button 
                                            v-if="assign.user_ids.length" 
                                            type="button" 
                                            class="btn btn-sm btn-link text-decoration-none p-0 text-secondary fw-semibold" 
                                            style="font-size: 0.775rem;" 
                                            @click="clearSelectedEmployees"
                                        >
                                            Limpiar
                                        </button>
                                    </div>
                                </div>

                                <div class="d-flex justify-content-between align-items-center mb-2 small">
                                    <span class="text-secondary">
                                        {{ branchEmployees.length }} colaborador(es) {{ includeOtherBranches ? 'en todas las sedes' : 'de la sucursal elegida' }}
                                    </span>
                                    <div class="form-check form-switch mb-0">
                                        <input id="assign_other_branches" v-model="includeOtherBranches" class="form-check-input" type="checkbox" role="switch">
                                        <label class="form-check-label" for="assign_other_branches">Incluir otras sedes</label>
                                    </div>
                                </div>

                                <!-- Buscador Instantáneo en Tiempo Real -->
                                <div class="position-relative mb-3">
                                    <i class="bi bi-search position-absolute top-50 start-0 translate-middle-y ms-3 text-muted" style="font-size: 0.85rem;"></i>
                                    <input 
                                        v-model="employeeSearch" 
                                        type="text" 
                                        class="form-control ps-5 collab-search-input" 
                                        placeholder="Buscar por nombre, apellido, código o DNI (ej. Alvarez, EMP-003)..."
                                        style="height: 42px; border-radius: 8px; font-size: 0.85rem;"
                                    >
                                    <button 
                                        v-if="employeeSearch" 
                                        type="button" 
                                        class="btn btn-link p-0 position-absolute end-0 top-50 translate-middle-y me-3 text-muted text-decoration-none" 
                                        title="Limpiar búsqueda"
                                        @click="employeeSearch = ''"
                                    >
                                        <i class="bi bi-x-circle-fill" style="font-size: 1rem;"></i>
                                    </button>
                                </div>

                                <!-- Lista Scrollable con Tarjetas de Colaboradores -->
                                <div class="collaborator-picker-list">
                                    <div 
                                        v-for="e in filteredEmployees" 
                                        :key="e.id"
                                        class="collaborator-picker-row"
                                        :class="{ 'is-selected': assign.user_ids.includes(e.id) }"
                                        @click="toggleEmployee(e.id)"
                                    >
                                        <div class="d-flex align-items-center" style="gap: 12px !important;">
                                            <!-- Checkbox personalizable -->
                                            <div class="custom-check-box" :class="{ 'is-checked': assign.user_ids.includes(e.id) }">
                                                <i v-if="assign.user_ids.includes(e.id)" class="bi bi-check-lg"></i>
                                            </div>

                                            <!-- Avatar con iniciales -->
                                            <div class="collab-avatar">
                                                {{ getInitials(e.first_name, e.last_name) }}
                                            </div>

                                            <!-- Información del Colaborador -->
                                            <div class="min-w-0">
                                                <div class="fw-bold text-dark text-truncate" style="font-size: 0.85rem;">
                                                    {{ e.last_name }}, {{ e.first_name }}
                                                </div>
                                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                                    <span v-if="e.employee_code" class="badge text-secondary font-monospace" style="background: rgba(100, 116, 139, 0.1); font-size: 0.685rem; padding: 1px 6px; border-radius: 4px; font-weight: 600;">
                                                        {{ e.employee_code }}
                                                    </span>
                                                    <span v-if="e.document_number" class="small text-muted" style="font-size: 0.725rem;">
                                                        Doc: {{ e.document_number }}
                                                    </span>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Badge de estado seleccionado -->
                                        <div class="ps-2">
                                            <span v-if="assign.user_ids.includes(e.id)" class="badge bg-primary text-white" style="border-radius: 5px; font-size: 0.7rem; font-weight: 600; padding: 3px 7px;">
                                                <i class="bi bi-check2 me-1"></i>Asignado
                                            </span>
                                        </div>
                                    </div>

                                    <!-- Estado sin resultados -->
                                    <div v-if="!filteredEmployees.length" class="text-center py-4 text-muted small">
                                        <i class="bi bi-person-x fs-3 d-block mb-1 opacity-50"></i>
                                        No se encontraron colaboradores que coincidan con "{{ employeeSearch }}".
                                    </div>
                                </div>

                                <div class="form-text small text-secondary mt-2.5">
                                    <i class="bi bi-info-circle me-1"></i>Haz clic sobre cualquier colaborador para agregarlo o removerlo de la asignación.
                                </div>
                                <div v-if="assign.errors.user_ids" class="invalid-feedback d-block mt-1">{{ assign.errors.user_ids }}</div>
                            </div>

                            <!-- VIGENCIA Y PROGRAMACIÓN CON DATE RANGE PICKER MODERNO (Screenshot 1 & 2 Solved) -->
                            <div class="emp-section-card">
                                <div class="emp-section-title">
                                    <i class="bi bi-calendar-range"></i>
                                    <span>Vigencia y Programación</span>
                                </div>
                                
                                <div class="mb-3">
                                    <DateRangePicker 
                                        v-model:startDate="assign.effective_from" 
                                        v-model:endDate="assign.effective_until" 
                                        :today="today"
                                        label="Período de Vigencia"
                                        :allow-indefinite="true"
                                        :drop-up="true"
                                    />
                                    <div v-if="assign.errors.effective_until" class="invalid-feedback d-block mt-1">
                                        {{ assign.errors.effective_until }}
                                    </div>
                                </div>

                                <div v-if="selectedAssignShift?.type === 'rotating'" class="mb-3">
                                    <label class="form-label fw-semibold small text-dark mb-1.5">Inicio de ciclo rotativo <span class="text-danger">*</span></label>
                                    <DateRangePicker 
                                        v-model:startDate="assign.rotation_anchor_date"
                                        :single-date="true"
                                        :today="today"
                                        label="Fecha Base de Ciclo Rotativo"
                                        :allow-indefinite="false"
                                        :drop-up="true"
                                    />
                                    <div class="form-text small text-secondary mt-1">Fecha base desde la cual empezará a contar el primer día del ciclo de trabajo rotativo.</div>
                                </div>

                                <div class="alert alert-info border-0 d-flex align-items-start p-3 mb-0" style="background: rgba(2, 132, 199, 0.08); border-radius: 8px; font-size: 0.825rem; color: #0369a1; gap: 10px !important;">
                                    <i class="bi bi-shield-check fs-5 flex-shrink-0" style="color: #0284c7;"></i>
                                    <div>
                                        <strong>Cierre automático de turno previo:</strong> La asignación vigente de cada colaborador seleccionado se cerrará de forma automática el día anterior a la fecha de inicio indicada.
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Modal Footer -->
                        <div class="modal-footer emp-modal-footer">
                            <button type="button" class="btn btn-outline-secondary px-3.5 py-1.5" data-bs-dismiss="modal" style="border-radius: 8px; font-weight: 500;">
                                Cancelar
                            </button>
                            <button type="submit" class="btn btn-primary d-inline-flex align-items-center px-4 py-1.5 shadow-sm" style="border-radius: 8px; font-weight: 600; gap: 8px !important;" :disabled="assign.processing">
                                <span v-if="assign.processing" class="spinner-border spinner-border-sm" role="status"></span>
                                <i v-else class="bi bi-check2-circle"></i>
                                <span>Confirmar Asignación</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
