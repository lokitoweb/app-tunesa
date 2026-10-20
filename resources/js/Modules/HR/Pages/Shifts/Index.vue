<script setup>
import { computed, ref, onMounted } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { Modal } from 'bootstrap';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import Pagination from '@/Modules/Core/Components/Pagination.vue';
import TenantBadge from '@/Modules/Core/Components/TenantBadge.vue';

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
// GESTIÓN DEL MODAL DIALOG (CREAR Y EDITAR IN-PLACE)
// =========================================================================
const modalElement = ref(null);
let modalInstance = null;
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

onMounted(() => {
    if (modalElement.value) {
        modalInstance = Modal.getOrCreateInstance(modalElement.value);
    }
});

function openCreateModal() {
    modalEditing.value = false;
    editingShiftId.value = null;
    modalTab.value = 'general';
    shiftForm.clearErrors();
    const defaults = defaultShiftData();
    Object.keys(defaults).forEach((k) => {
        shiftForm[k] = defaults[k];
    });
    modalInstance?.show();
}

function openEditModal(shift) {
    modalEditing.value = true;
    editingShiftId.value = shift.id;
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
    modalInstance?.show();
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
        shiftForm.put(`/shifts/${editingShiftId.value}`, {
            preserveScroll: true,
            onSuccess: () => {
                modalInstance?.hide();
            },
        });
    } else {
        shiftForm.post('/shifts', {
            preserveScroll: true,
            onSuccess: () => {
                modalInstance?.hide();
                const defaults = defaultShiftData();
                Object.keys(defaults).forEach((k) => {
                    shiftForm[k] = defaults[k];
                });
            },
        });
    }
}

function destroy(shift) {
    if (confirm(`¿Estás seguro de eliminar el turno "${shift.name}"?`)) {
        router.delete(`/shifts/${shift.id}`, { preserveScroll: true });
    }
}

// =========================================================================
// FORMULARIO DE ASIGNACIÓN DE TURNO
// =========================================================================
const assign = useForm({
    shift_id: activeShifts.value[0]?.id ?? null,
    branch_id: props.branches[0]?.id ?? null,
    user_ids: [],
    effective_from: props.today,
    effective_until: '',
    rotation_anchor_date: '',
    replace_current: true,
});

function submitAssign() {
    assign.post('/shifts/assign', {
        preserveScroll: true,
        onSuccess: () => assign.reset('user_ids', 'effective_until', 'rotation_anchor_date'),
    });
}

function unassign(assignment) {
    if (confirm('¿Finalizar esta asignación hoy?')) {
        router.delete(`/shifts/assignments/${assignment.id}`, { preserveScroll: true });
    }
}
</script>

<template>
    <AppLayout title="Turnos y horarios">
        <!-- Header Principal de la Pantalla -->
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
            <div>
                <h1 class="h2 fw-bold text-dark mb-1">Turnos y horarios</h1>
                <p class="text-secondary mb-0" style="font-size: 0.875rem;">
                    Turnos fijos y rotativos, tolerancia de tardanza y asignación de colaboradores.
                </p>
            </div>
            <div>
                <button type="button" class="btn btn-primary d-inline-flex align-items-center gap-2 shadow-sm" style="height: 40px; border-radius: 8px; font-weight: 600; padding: 0 1.25rem;" @click="openCreateModal">
                    <i class="bi bi-plus-lg"></i>
                    <span>Nuevo turno</span>
                </button>
            </div>
        </div>

        <!-- Tabla Principal de Turnos (Espaciosa, Elegante, Squircle Badges) -->
        <div class="card border-0 mb-5 shadow-sm" style="background: var(--bg-surface); border: 1px solid var(--border-color) !important; border-radius: 12px; overflow: hidden;">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr style="border-bottom: 1px solid var(--border-color);">
                            <th class="ps-4 py-3 text-secondary text-uppercase fw-semibold" style="font-size: 0.725rem; letter-spacing: 0.05em; background: var(--bg-surface-elevated);">Turno</th>
                            <th class="py-3 text-secondary text-uppercase fw-semibold" style="font-size: 0.725rem; letter-spacing: 0.05em; background: var(--bg-surface-elevated);">Tipo</th>
                            <th class="py-3 text-secondary text-uppercase fw-semibold" style="font-size: 0.725rem; letter-spacing: 0.05em; background: var(--bg-surface-elevated);">Horario</th>
                            <th class="py-3 text-secondary text-uppercase fw-semibold" style="font-size: 0.725rem; letter-spacing: 0.05em; background: var(--bg-surface-elevated);">Días / Ciclo</th>
                            <th class="py-3 text-secondary text-uppercase fw-semibold" style="font-size: 0.725rem; letter-spacing: 0.05em; background: var(--bg-surface-elevated);">Tolerancia</th>
                            <th class="py-3 text-secondary text-uppercase fw-semibold" style="font-size: 0.725rem; letter-spacing: 0.05em; background: var(--bg-surface-elevated);">Asignados</th>
                            <th class="py-3 text-secondary text-uppercase fw-semibold" style="font-size: 0.725rem; letter-spacing: 0.05em; background: var(--bg-surface-elevated);">Estado</th>
                            <th class="pe-4 py-3 text-secondary text-uppercase fw-semibold text-end" style="font-size: 0.725rem; letter-spacing: 0.05em; background: var(--bg-surface-elevated);">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="shift in shifts" :key="shift.id" style="border-bottom: 1px solid #f1f5f9;">
                            <td class="ps-4 py-3.5">
                                <div class="d-flex align-items-center gap-2.5">
                                    <span class="rounded-circle d-inline-block flex-shrink-0 shadow-sm" :style="{ width: '10px', height: '10px', background: shift.color }"></span>
                                    <div>
                                        <div class="fw-bold text-dark" style="font-size: 0.875rem;">{{ shift.name }}</div>
                                        <div v-if="shift.code" class="small text-secondary font-monospace" style="font-size: 0.75rem;">{{ shift.code }}</div>
                                    </div>
                                </div>
                                <div class="mt-1">
                                    <TenantBadge :tenant-id="shift.tenant_id" />
                                </div>
                            </td>
                            <td class="py-3.5">
                                <span class="badge border" :class="shift.type === 'rotating' ? 'bg-primary-subtle text-primary border-primary-subtle' : 'bg-body-secondary text-secondary border-secondary-subtle'" style="border-radius: 6px; font-weight: 600; font-size: 0.75rem; padding: 4px 8px;">
                                    {{ shift.type === 'rotating' ? 'Rotativo' : 'Fijo' }}
                                </span>
                            </td>
                            <td class="py-3.5 text-nowrap" style="font-size: 0.85rem; font-weight: 500; color: #334155;">
                                {{ hora(shift.start_time) }} – {{ hora(shift.end_time) }}
                                <span v-if="shift.crosses_midnight" class="small text-secondary ms-1">(+1 día)</span>
                            </td>
                            <td class="py-3.5 text-secondary" style="font-size: 0.85rem;">
                                <template v-if="shift.type === 'rotating'">
                                    <span class="fw-semibold text-dark">{{ shift.rotation_work_days }}</span>
                                    <span class="text-muted mx-1.5">×</span>
                                    <span class="fw-semibold text-dark">{{ shift.rotation_rest_days }}</span>
                                </template>
                                <template v-else>
                                    <span>{{ shift.working_days.map((d) => DAYS[d] ?? d).join(', ') }}</span>
                                </template>
                            </td>
                            <td class="py-3.5 text-secondary" style="font-size: 0.85rem;">
                                <span class="fw-semibold text-dark">{{ shift.grace_period_minutes }}</span> min
                            </td>
                            <td class="py-3.5 text-secondary" style="font-size: 0.85rem;">
                                <span class="fw-semibold text-dark">{{ shift.employees_count }}</span>
                            </td>
                            <td class="py-3.5">
                                <span v-if="shift.is_active" class="badge bg-success-subtle text-success border border-success-subtle" style="border-radius: 6px; font-weight: 600; font-size: 0.75rem; padding: 4px 8px;">
                                    Activo
                                </span>
                                <span v-else class="badge bg-secondary-subtle text-secondary border border-secondary-subtle" style="border-radius: 6px; font-weight: 600; font-size: 0.75rem; padding: 4px 8px;">
                                    Inactivo
                                </span>
                            </td>
                            <td class="pe-4 py-3.5 text-end text-nowrap">
                                <div class="d-flex align-items-center justify-content-end gap-1.5">
                                    <button type="button" class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1.5 px-2.5 py-1" style="border-radius: 6px; font-size: 0.8rem; font-weight: 500;" @click="openEditModal(shift)">
                                        <i class="bi bi-pencil"></i>
                                        <span>Editar</span>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-danger d-inline-flex align-items-center justify-content-center p-1" style="width: 32px; height: 32px; border-radius: 6px;" :title="`Eliminar ${shift.name}`" @click="destroy(shift)">
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
                                <button type="button" class="btn btn-sm btn-primary" @click="openCreateModal">
                                    <i class="bi bi-plus-lg me-1"></i>Crear turno
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Sección Inferior: Asignar Turno a Colaboradores (Amplia y Bien Espaciada) -->
        <div v-if="canAssign" class="card border-0 mb-4 shadow-sm" style="background: var(--bg-surface); border: 1px solid var(--border-color) !important; border-radius: 12px;">
            <div class="card-header border-bottom bg-transparent py-3 px-4 d-flex align-items-center gap-2">
                <i class="bi bi-person-check-fill text-primary fs-5"></i>
                <h2 class="h6 fw-bold mb-0 text-dark">Asignar turno a colaboradores</h2>
            </div>
            <div class="card-body p-4">
                <form @submit.prevent="submitAssign">
                    <div class="row g-4">
                        <!-- Columna Izquierda: Parámetros del Turno y Personal -->
                        <div class="col-12 col-lg-6">
                            <div class="mb-3">
                                <label for="assign_shift" class="form-label fw-semibold small text-dark mb-1.5">Turno a asignar <span class="text-danger">*</span></label>
                                <select id="assign_shift" v-model="assign.shift_id" class="form-select" :class="{ 'is-invalid': assign.errors.shift_id }" required style="border-radius: 8px; height: 42px;">
                                    <option v-for="shift in activeShifts" :key="shift.id" :value="shift.id">
                                        {{ shift.name }} ({{ hora(shift.start_time) }} – {{ hora(shift.end_time) }})
                                    </option>
                                </select>
                                <div v-if="assign.errors.shift_id" class="invalid-feedback">{{ assign.errors.shift_id }}</div>
                            </div>

                            <div class="mb-3">
                                <label for="assign_branch" class="form-label fw-semibold small text-dark mb-1.5">Sucursal operativa <span class="text-danger">*</span></label>
                                <select id="assign_branch" v-model="assign.branch_id" class="form-select" :class="{ 'is-invalid': assign.errors.branch_id }" required style="border-radius: 8px; height: 42px;">
                                    <option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
                                </select>
                                <div v-if="assign.errors.branch_id" class="invalid-feedback">{{ assign.errors.branch_id }}</div>
                            </div>

                            <div>
                                <div class="d-flex align-items-center justify-content-between mb-1.5">
                                    <label for="assign_users" class="form-label fw-semibold small text-dark mb-0">Seleccionar Colaboradores <span class="text-danger">*</span></label>
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle font-monospace" style="border-radius: 6px; font-size: 0.725rem;">
                                        {{ assign.user_ids.length }} seleccionados
                                    </span>
                                </div>
                                <select id="assign_users" v-model="assign.user_ids" class="form-select" :class="{ 'is-invalid': assign.errors.user_ids }" multiple size="5" required style="border-radius: 8px; font-size: 0.85rem; padding: 0.5rem;">
                                    <option v-for="e in employees" :key="e.id" :value="e.id">
                                        {{ e.last_name }}, {{ e.first_name }}{{ e.employee_code ? ` · ${e.employee_code}` : '' }}
                                    </option>
                                </select>
                                <div class="form-text small text-secondary mt-1.5">
                                    <i class="bi bi-info-circle me-1"></i>Mantén presionada la tecla Ctrl (o Cmd) para elegir varios colaboradores a la vez.
                                </div>
                                <div v-if="assign.errors.user_ids" class="invalid-feedback d-block">{{ assign.errors.user_ids }}</div>
                            </div>
                        </div>

                        <!-- Columna Derecha: Fechas de Vigencia y Acción -->
                        <div class="col-12 col-lg-6 d-flex flex-column justify-content-between">
                            <div>
                                <div class="row g-3 mb-3">
                                    <div class="col-12 col-sm-6">
                                        <label for="effective_from" class="form-label fw-semibold small text-dark mb-1.5">Fecha de inicio <span class="text-danger">*</span></label>
                                        <input id="effective_from" v-model="assign.effective_from" type="date" class="form-control" required style="border-radius: 8px; height: 42px;">
                                    </div>
                                    <div class="col-12 col-sm-6">
                                        <label for="effective_until" class="form-label fw-semibold small text-dark mb-1.5">Fecha de fin <span class="text-secondary fw-normal">(opcional)</span></label>
                                        <input id="effective_until" v-model="assign.effective_until" type="date" class="form-control" :class="{ 'is-invalid': assign.errors.effective_until }" style="border-radius: 8px; height: 42px;">
                                        <div v-if="assign.errors.effective_until" class="invalid-feedback">{{ assign.errors.effective_until }}</div>
                                    </div>
                                    <div class="col-12">
                                        <label for="rotation_anchor_date" class="form-label fw-semibold small text-dark mb-1.5">Inicio de ciclo rotativo <span class="text-secondary fw-normal">(solo si es rotativo)</span></label>
                                        <input id="rotation_anchor_date" v-model="assign.rotation_anchor_date" type="date" class="form-control" style="border-radius: 8px; height: 42px;">
                                        <div class="form-text small text-secondary mt-1">Fecha base desde la cual comenzará a contar el primer día de labor del ciclo.</div>
                                    </div>
                                </div>

                                <div class="alert alert-info border-0 d-flex align-items-start gap-2.5 p-3 mb-4" style="background: rgba(2, 132, 199, 0.08); border-radius: 8px; font-size: 0.825rem; color: #0369a1;">
                                    <i class="bi bi-shield-check fs-5 flex-shrink-0" style="color: #0284c7;"></i>
                                    <div>
                                        <strong>Cierre automático de turno previo:</strong> La asignación vigente de cada colaborador seleccionado se cerrará de forma automática el día anterior a la fecha de inicio.
                                    </div>
                                </div>
                            </div>

                            <div>
                                <button type="submit" class="btn btn-primary w-100 d-inline-flex align-items-center justify-content-center gap-2 shadow-sm" style="height: 44px; border-radius: 8px; font-weight: 600;" :disabled="assign.processing">
                                    <span v-if="assign.processing" class="spinner-border spinner-border-sm" role="status"></span>
                                    <i v-else class="bi bi-check2-circle fs-5"></i>
                                    <span>Asignar Turno a los Colaboradores</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Asignaciones Vigentes (Tabla Completa y Elegante) -->
        <div class="card border-0 mb-5 shadow-sm" style="background: var(--bg-surface); border: 1px solid var(--border-color) !important; border-radius: 12px; overflow: hidden;">
            <div class="card-header border-bottom bg-transparent py-3 px-4 d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-people-fill text-primary fs-5"></i>
                    <h2 class="h6 fw-bold mb-0 text-dark">Asignaciones vigentes</h2>
                </div>
                <span class="badge bg-body-secondary text-secondary border px-2.5 py-1" style="border-radius: 6px; font-weight: 600; font-size: 0.75rem;">
                    {{ assignments.total ?? assignments.data?.length ?? 0 }} asignaciones activas
                </span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr style="border-bottom: 1px solid var(--border-color);">
                            <th class="ps-4 py-3 text-secondary text-uppercase fw-semibold" style="font-size: 0.725rem; letter-spacing: 0.05em; background: var(--bg-surface-elevated);">Colaborador</th>
                            <th class="py-3 text-secondary text-uppercase fw-semibold" style="font-size: 0.725rem; letter-spacing: 0.05em; background: var(--bg-surface-elevated);">Turno</th>
                            <th class="py-3 text-secondary text-uppercase fw-semibold" style="font-size: 0.725rem; letter-spacing: 0.05em; background: var(--bg-surface-elevated);">Sucursal</th>
                            <th class="py-3 text-secondary text-uppercase fw-semibold" style="font-size: 0.725rem; letter-spacing: 0.05em; background: var(--bg-surface-elevated);">Vigencia</th>
                            <th class="pe-4 py-3 text-secondary text-uppercase fw-semibold text-end" style="font-size: 0.725rem; letter-spacing: 0.05em; background: var(--bg-surface-elevated);">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="a in assignments.data" :key="a.id" style="border-bottom: 1px solid #f1f5f9;">
                            <td class="ps-4 py-3">
                                <div class="fw-semibold text-dark">{{ a.employee }}</div>
                                <TenantBadge :tenant-id="a.tenant_id" />
                            </td>
                            <td class="py-3">
                                <div class="d-flex align-items-center gap-2">
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
             MODAL DIALOG: NUEVO TURNO / EDITAR TURNO (CON TABS EJECUTIVAS Y RESPIRACIÓN)
             ========================================================================= -->
        <div id="shiftModal" ref="modalElement" class="modal fade" tabindex="-1" aria-labelledby="shiftModalTitle" aria-hidden="true" data-bs-backdrop="static">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content shadow-lg border" style="border-radius: 14px; overflow: hidden; background: var(--bg-surface);">
                    <!-- Modal Header -->
                    <div class="modal-header emp-modal-header px-4 py-3 border-bottom d-flex align-items-center justify-content-between">
                        <div class="d-flex align-items-center gap-3">
                            <div class="emp-modal-icon shadow-sm" style="width: 40px; height: 40px; border-radius: 10px; font-size: 1.25rem;">
                                <i :class="modalEditing ? 'bi bi-pencil-square' : 'bi bi-plus-circle-fill'"></i>
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

                    <!-- Pestañas de Navegación Ejecutiva -->
                    <div class="emp-modal-nav-wrap px-4 py-2 border-bottom" style="background: var(--bg-surface-elevated);">
                        <ul class="nav nav-pills emp-modal-tabs" role="tablist">
                            <li class="nav-item">
                                <button type="button" class="nav-link" :class="{ active: modalTab === 'general' }" @click="modalTab = 'general'">
                                    <i class="bi bi-clock-history me-1.5"></i>
                                    <span>1. Horario y Datos</span>
                                </button>
                            </li>
                            <li class="nav-item">
                                <button type="button" class="nav-link" :class="{ active: modalTab === 'schedule' }" @click="modalTab = 'schedule'">
                                    <i class="bi bi-calendar3 me-1.5"></i>
                                    <span>2. Días y Modalidad</span>
                                </button>
                            </li>
                            <li class="nav-item">
                                <button type="button" class="nav-link" :class="{ active: modalTab === 'policies' }" @click="modalTab = 'policies'">
                                    <i class="bi bi-sliders2 me-1.5"></i>
                                    <span>3. Descanso y Estado</span>
                                </button>
                            </li>
                        </ul>
                    </div>

                    <!-- Modal Body Form -->
                    <form @submit.prevent="submitShiftModal">
                        <div class="modal-body p-4" style="background: var(--bg-surface);">
                            
                            <!-- =========================================================
                                 TAB 1: HORARIO Y DATOS PRINCIPALES
                                 ========================================================= -->
                            <div v-show="modalTab === 'general'">
                                <div class="row g-3 mb-4">
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

                                <div class="mb-4">
                                    <label class="form-label fw-semibold small text-dark mb-2">Color distintivo en calendario</label>
                                    <div class="d-flex align-items-center gap-2.5 flex-wrap">
                                        <input v-model="shiftForm.color" type="color" class="form-control form-control-color p-0 shadow-sm" style="width: 42px; height: 42px; border-radius: 8px; cursor: pointer;">
                                        <div class="d-flex align-items-center gap-2 flex-wrap">
                                            <button v-for="c in PRESET_COLORS" :key="c" type="button" class="border shadow-sm p-0 d-inline-flex align-items-center justify-content-center" :style="{ width: '32px', height: '32px', borderRadius: '8px', background: c, outline: shiftForm.color === c ? '2px solid #0284C7' : 'none', outlineOffset: '2px' }" :title="c" @click="shiftForm.color = c">
                                                <i v-if="shiftForm.color === c" class="bi bi-check-lg text-white" style="font-size: 1rem;"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <div class="row g-3 mb-4">
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
                                        <div class="form-text small text-secondary mt-1">Minutos tras la entrada permitidos antes de marcar tardanza.</div>
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

                            <!-- =========================================================
                                 TAB 2: DÍAS LABORABLES Y MODALIDAD DE JORNADA
                                 ========================================================= -->
                            <div v-show="modalTab === 'schedule'">
                                <div class="mb-4">
                                    <label class="form-label fw-semibold small text-dark mb-2">Selecciona la modalidad de jornada <span class="text-danger">*</span></label>
                                    <div class="row g-3">
                                        <div class="col-12 col-md-6">
                                            <div class="p-3 border rounded-3 cursor-pointer shadow-sm transition" :style="{ borderColor: shiftForm.type === 'fixed' ? 'var(--brand, #0284C7)' : 'var(--border-color)', background: shiftForm.type === 'fixed' ? 'rgba(2, 132, 199, 0.05)' : 'var(--bg-surface)' }" @click="shiftForm.type = 'fixed'">
                                                <div class="d-flex align-items-center gap-2 mb-1">
                                                    <i class="bi bi-calendar-check fs-5 text-primary"></i>
                                                    <div class="fw-bold text-dark">Turno Fijo</div>
                                                </div>
                                                <div class="small text-secondary">Días específicos de la semana fija (ej. Lunes a Viernes o Lunes a Sábado).</div>
                                            </div>
                                        </div>
                                        <div class="col-12 col-md-6">
                                            <div class="p-3 border rounded-3 cursor-pointer shadow-sm transition" :style="{ borderColor: shiftForm.type === 'rotating' ? 'var(--brand, #0284C7)' : 'var(--border-color)', background: shiftForm.type === 'rotating' ? 'rgba(2, 132, 199, 0.05)' : 'var(--bg-surface)' }" @click="shiftForm.type = 'rotating'">
                                                <div class="d-flex align-items-center gap-2 mb-1">
                                                    <i class="bi bi-arrow-repeat fs-5 text-primary"></i>
                                                    <div class="fw-bold text-dark">Turno Rotativo</div>
                                                </div>
                                                <div class="small text-secondary">Ciclos continuos de N días de trabajo seguidos de M días de descanso (ej. 4×2 o 6×1).</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Si es Fijo: Botones de días amplios -->
                                <div v-if="shiftForm.type === 'fixed'">
                                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                                        <label class="form-label fw-semibold small text-dark mb-0">Días laborables seleccionados:</label>
                                        <div class="d-flex align-items-center gap-1.5">
                                            <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-2.5" style="border-radius: 6px; font-size: 0.775rem;" @click="setWorkingDaysPreset('weekdays')">
                                                Lunes a Viernes
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-2.5" style="border-radius: 6px; font-size: 0.775rem;" @click="setWorkingDaysPreset('mon_sat')">
                                                Lunes a Sábado
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-2.5" style="border-radius: 6px; font-size: 0.775rem;" @click="setWorkingDaysPreset('all')">
                                                Toda la semana
                                            </button>
                                        </div>
                                    </div>

                                    <div class="d-flex flex-wrap gap-2.5 mb-2">
                                        <button v-for="d in ORDERED_DAYS" :key="d.index" type="button" class="btn px-3.5 py-2 fw-semibold d-inline-flex align-items-center gap-2 shadow-sm" :class="shiftForm.working_days.includes(d.index) ? 'btn-primary' : 'btn-outline-secondary'" style="border-radius: 8px; font-size: 0.875rem;" @click="toggleWorkingDay(d.index)">
                                            <i class="bi" :class="shiftForm.working_days.includes(d.index) ? 'bi-check-circle-fill' : 'bi-circle'"></i>
                                            <span>{{ d.label }}</span>
                                        </button>
                                    </div>
                                    <div v-if="shiftForm.errors.working_days" class="invalid-feedback d-block mt-2">{{ shiftForm.errors.working_days }}</div>
                                </div>

                                <!-- Si es Rotativo: Días de trabajo x Descanso -->
                                <div v-else>
                                    <div class="row g-4 mb-4">
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
                                    <div class="alert alert-info border-0 d-flex align-items-start gap-2.5 p-3 mb-0" style="background: rgba(2, 132, 199, 0.08); border-radius: 8px; font-size: 0.825rem; color: #0369a1;">
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
                                <div class="p-3 border rounded-3 mb-4 shadow-sm" style="background: var(--bg-surface);">
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

                                <div class="p-3 border rounded-3 mb-4 shadow-sm d-flex align-items-center justify-content-between" style="background: var(--bg-surface);">
                                    <div>
                                        <div class="fw-bold text-dark mb-0.5">Turno activo</div>
                                        <div class="small text-secondary">Permite que este turno aparezca disponible para asignación de colaboradores.</div>
                                    </div>
                                    <div class="form-check form-switch mb-0">
                                        <input id="modal_is_active" v-model="shiftForm.is_active" class="form-check-input" type="checkbox" role="switch" style="width: 2.75em; height: 1.45em; cursor: pointer;">
                                    </div>
                                </div>

                                <div>
                                    <label for="shift_desc" class="form-label fw-semibold small text-dark mb-1.5">Descripción u observaciones internas</label>
                                    <textarea id="shift_desc" v-model="shiftForm.description" class="form-control" rows="3" style="border-radius: 8px; font-size: 0.875rem;" placeholder="Indicaciones para supervisores, sucursales asignadas, etc..."></textarea>
                                </div>
                            </div>

                        </div>

                        <!-- Modal Footer con Navegación Limpia -->
                        <div class="modal-footer px-4 py-3 border-top d-flex justify-content-between align-items-center" style="background: var(--bg-surface-elevated);">
                            <div>
                                <button v-if="modalTab !== 'general'" type="button" class="btn btn-outline-secondary d-inline-flex align-items-center gap-1.5 px-3 py-1.5" style="border-radius: 8px; font-weight: 500;" @click="prevTab">
                                    <i class="bi bi-arrow-left"></i>
                                    <span>Anterior</span>
                                </button>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <button type="button" class="btn btn-outline-secondary px-3 py-1.5" data-bs-dismiss="modal" style="border-radius: 8px; font-weight: 500;">
                                    Cancelar
                                </button>
                                <button v-if="modalTab !== 'policies'" type="button" class="btn btn-primary d-inline-flex align-items-center gap-1.5 px-3.5 py-1.5" style="border-radius: 8px; font-weight: 600;" @click="nextTab">
                                    <span>Siguiente</span>
                                    <i class="bi bi-arrow-right"></i>
                                </button>
                                <button v-else type="submit" class="btn btn-primary d-inline-flex align-items-center gap-2 px-4 py-1.5 shadow-sm" style="border-radius: 8px; font-weight: 600;" :disabled="shiftForm.processing">
                                    <span v-if="shiftForm.processing" class="spinner-border spinner-border-sm" role="status"></span>
                                    <i v-else class="bi bi-check2"></i>
                                    <span>{{ modalEditing ? 'Guardar Cambios' : 'Crear Turno' }}</span>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
