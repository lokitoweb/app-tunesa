<script setup>
import { computed, ref, onMounted, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { Modal } from 'bootstrap';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import TenantBadge from '@/Modules/Core/Components/TenantBadge.vue';
import DateRangePicker from '@/Modules/Core/Components/DateRangePicker.vue';
import { useConfirm } from '@/Modules/Core/Composables/useConfirm';
import { useTenantPicker } from '@/Modules/Core/Composables/useTenantPicker';
import ShiftWeekSchedule, { emptyDays } from '@/Modules/HR/Components/ShiftWeekSchedule.vue';
import { PRESET_COLORS, colorOwner, firstFreeColor } from '@/Modules/HR/shiftColors';

const { ask } = useConfirm();
const { ensureTenant, consumeIntent, withTenant } = useTenantPicker();
const editingTenantId = ref(null);

const props = defineProps({
    shifts: Array,
    branches: { type: Array, default: () => [] },
    profiles: { type: Object, default: () => ({}) },
});

// Filtro por perfil de personal (administrativo, ventas…): '' = todos
const profileFilter = ref('');
const branchFilter = ref('');   // '' = todas, 'none' = sin agencia, o id
const search = ref('');
const matchesProfile = (s, key) => !key || (key === 'none' ? !s.profile : s.profile === key);
const matchesRest = (s) => {
    const q = search.value.trim().toLowerCase();
    const branchOk = !branchFilter.value || (branchFilter.value === 'none' ? !s.branch_id : s.branch_id === Number(branchFilter.value));
    return branchOk && (!q || `${s.name} ${s.code || ''}`.toLowerCase().includes(q));
};
const visibleShifts = computed(() => props.shifts.filter((s) => matchesRest(s) && matchesProfile(s, profileFilter.value)));
const profileOptions = computed(() => Object.entries({ '': 'Todos', ...props.profiles, none: 'General' })
    .map(([key, label]) => ({ key, label, count: props.shifts.filter((s) => matchesRest(s) && matchesProfile(s, key)).length })));
const hasFilters = computed(() => !!(profileFilter.value || branchFilter.value || search.value.trim()));
function clearFilters() {
    profileFilter.value = '';
    branchFilter.value = '';
    search.value = '';
}
// Días con horario propio, p. ej. "Sáb 08:00–13:00"
const ownDays = (shift) => Object.entries(shift.day_schedules || {})
    .filter(([, v]) => v.start_time || v.end_time)
    .map(([d, v]) => `${DAYS[d]} ${v.start_time || shift.start_time.substring(0, 5)}–${v.end_time || shift.end_time.substring(0, 5)}`);

// QA3-04: las 4 marcaciones si el horario fija el refrigerio; si no, entrada – salida
const hasBreakWindow = (s) => s.break_allowed && s.break_start_time && s.break_end_time;
const timesOf = (s) => (hasBreakWindow(s) && s.split_shift
    ? `${hora(s.start_time)} – ${hora(s.break_start_time)} · ${hora(s.break_end_time)} – ${hora(s.end_time)}`
    : hasBreakWindow(s)
    ? [s.start_time, s.break_start_time, s.break_end_time, s.end_time].map((t) => hora(t)).join(' · ')
    : `${hora(s.start_time)} – ${hora(s.end_time)}`);

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

const activeShifts = computed(() => props.shifts.filter((s) => s.is_active));

// Colores usados por otro horario de la agencia elegida (el servidor también lo valida).
// Vista global: solo los horarios de la misma empresa (las agencias son de una empresa)
const sameCompany = computed(() => props.shifts.filter((s) => !editingTenantId.value || s.tenant_id === editingTenantId.value));
const colorTaken = (c) => colorOwner(sameCompany.value, shiftForm.branch_id, c, editingShiftId.value);
function pickColor(c) {
    if (!colorTaken(c)) shiftForm.color = c;
}

// =========================================================================
// 1. MODAL DIALOG: CREAR Y EDITAR TURNO (IN-PLACE)
// =========================================================================
const shiftModalElement = ref(null);
let shiftModalInstance = null;
const modalEditing = ref(false);
const editingShiftId = ref(null);
const modalTab = ref('general'); // 'general' | 'schedule' | 'policies'

const defaultShiftData = () => ({
    // Agencia obligatoria; si la empresa tiene una sola, ya viene elegida
    branch_id: props.branches.length === 1 ? props.branches[0].id : null,
    name: '',
    code: '',
    type: 'fixed',
    start_time: '08:00',
    end_time: '17:00',
    grace_period_minutes: 10,
    daily_hours: 8,
    break_allowed: true,
    break_duration_minutes: 60,
    // QA3-04: horas fijas del refrigerio (vacías = 2 marcaciones)
    break_start_time: '',
    break_end_time: '',
    split_shift: false,
    working_days: [1, 2, 3, 4, 5],
    rotation_work_days: 4,
    rotation_rest_days: 2,
    color: '#0284C7',
    is_active: true,
    description: '',
    profile: profileFilter.value || '',
    day_schedules: emptyDays(),
});

const shiftForm = useForm(defaultShiftData());

// Horario nuevo: al elegir la agencia, si su color ya está usado ahí, se pasa al primero libre
watch(() => shiftForm.branch_id, () => {
    if (!modalEditing.value && colorTaken(shiftForm.color)) {
        shiftForm.color = firstFreeColor(sameCompany.value, shiftForm.branch_id);
    }
});

// La asignación de horarios a colaboradores se hace solo en "Programación de horarios" (matriz)

onMounted(() => {
    if (shiftModalElement.value) {
        shiftModalInstance = Modal.getOrCreateInstance(shiftModalElement.value);
    }
    // Tras elegir empresa en la vista global, reabre el formulario que se pidió
    if (consumeIntent('turno')) openCreateModal();
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
    shiftForm.color = firstFreeColor(sameCompany.value, shiftForm.branch_id);
    shiftModalInstance?.show();
}

function openEditModal(shift) {
    modalEditing.value = true;
    editingShiftId.value = shift.id;
    editingTenantId.value = shift.tenant_id ?? null;
    modalTab.value = 'general';
    shiftForm.clearErrors();
    shiftForm.branch_id = shift.branch_id ?? null;
    shiftForm.name = shift.name || '';
    shiftForm.code = shift.code || '';
    shiftForm.type = shift.type || 'fixed';
    shiftForm.start_time = shift.start_time ? shift.start_time.substring(0, 5) : '08:00';
    shiftForm.end_time = shift.end_time ? shift.end_time.substring(0, 5) : '17:00';
    shiftForm.grace_period_minutes = shift.grace_period_minutes ?? 10;
    shiftForm.daily_hours = shift.daily_hours ?? 8;
    shiftForm.break_allowed = Boolean(shift.break_allowed);
    shiftForm.break_duration_minutes = shift.break_duration_minutes ?? 60;
    shiftForm.break_start_time = shift.break_start_time || '';
    shiftForm.break_end_time = shift.break_end_time || '';
    shiftForm.split_shift = Boolean(shift.split_shift);
    shiftForm.working_days = Array.isArray(shift.working_days) ? [...shift.working_days] : [1, 2, 3, 4, 5];
    shiftForm.rotation_work_days = shift.rotation_work_days ?? 4;
    shiftForm.rotation_rest_days = shift.rotation_rest_days ?? 2;
    shiftForm.color = shift.color || '#0284C7';
    shiftForm.is_active = Boolean(shift.is_active);
    shiftForm.description = shift.description || '';
    shiftForm.profile = shift.profile || '';
    shiftForm.day_schedules = emptyDays(shift.day_schedules);
    shiftModalInstance?.show();
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

// Si el error está en la pestaña 1 (agencia, nombre, código, color), se vuelve a ella
const GENERAL_FIELDS = ['branch_id', 'name', 'code', 'color', 'profile'];
const showErrorTab = (errors) => {
    if (GENERAL_FIELDS.some((f) => errors[f])) modalTab.value = 'general';
};

function submitShiftModal() {
    normalizeTime('start_time');
    normalizeTime('end_time');
    normalizeTime('break_start_time');
    normalizeTime('break_end_time');

    if (modalEditing.value) {
        // Vista global: se guarda en la empresa del turno, sin preguntar
        shiftForm.transform((data) => withTenant(data, editingTenantId.value)).put(`/shifts/${editingShiftId.value}`, {
            preserveScroll: true,
            onError: showErrorTab,
            onSuccess: () => {
                shiftModalInstance?.hide();
            },
        });
    } else {
        shiftForm.transform((data) => data).post('/shifts', {
            preserveScroll: true,
            onError: showErrorTab,
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
</script>

<template>
    <AppLayout title="Horarios">
        <header class="page-head">
            <div class="page-head-text">
                <h1 class="page-title">Horarios</h1>
                <p class="page-subtitle">
                    Horarios fijos y rotativos de cada agencia, con su color y tolerancia. Los colaboradores se programan en
                    <a href="/shifts/schedule">Programación de horarios</a>.
                </p>
            </div>
            <div class="page-actions">
                <button type="button" class="btn btn-primary" @click="openCreateModal"><i class="bi bi-plus-lg me-1"></i>Nuevo turno</button>
            </div>
        </header>

        <section class="surface mb-4">
            <!-- Filtros: búsqueda, agencia y perfil (en el navegador, sin recargar) -->
            <div class="filter-row">
                <div class="search-field">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input v-model="search" type="search" class="form-control" placeholder="Buscar por nombre o código…" aria-label="Buscar horario">
                </div>
                <select v-model="branchFilter" class="form-select w-auto" aria-label="Filtrar por agencia">
                    <option value="">Todas las agencias</option>
                    <option v-for="b in branches" :key="b.id" :value="String(b.id)">{{ b.name }}</option>
                    <option value="none">Sin agencia asignada</option>
                </select>
                <div v-if="Object.keys(profiles).length" class="seg-tabs" role="tablist" aria-label="Filtrar por perfil">
                    <button v-for="o in profileOptions" :key="o.key" type="button" role="tab" class="seg-tab" :class="{ 'is-active': profileFilter === o.key }"
                            :aria-selected="profileFilter === o.key" @click="profileFilter = o.key">
                        {{ o.label }} <span class="count-pill">{{ o.count }}</span>
                    </button>
                </div>
                <button v-if="hasFilters" type="button" class="btn btn-sm btn-link text-decoration-none ms-auto" @click="clearFilters"><i class="bi bi-x-circle me-1"></i>Limpiar</button>
            </div>

            <div class="table-responsive">
                <table class="table data-table table-cards align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Turno</th>
                            <th>Tipo</th>
                            <th>Horario</th>
                            <th>Días / Ciclo</th>
                            <th class="text-end">Tolerancia</th>
                            <th class="text-end">Asignados</th>
                            <th>Estado</th>
                            <th class="text-end"><span class="visually-hidden">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="shift in visibleShifts" :key="shift.id" :class="{ 'is-muted': !shift.is_active }">
                            <td data-label="Turno" class="cell-main">
                                <div class="d-flex align-items-start gap-2">
                                    <span class="shift-dot" :style="{ background: shift.color }" aria-hidden="true"></span>
                                    <div class="min-w-0">
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="cell-title text-truncate">{{ shift.name }}</span>
                                            <span v-if="shift.code" class="status-pill tone-neutral font-monospace">{{ shift.code }}</span>
                                        </div>
                                        <div class="cell-sub text-truncate"><i class="bi bi-geo-alt me-1"></i>{{ shift.branch || 'Todas las agencias (sin agencia asignada)' }}</div>
                                        <div v-if="shift.tenant_id" class="mt-1"><TenantBadge :tenant-id="shift.tenant_id" /></div>
                                    </div>
                                </div>
                            </td>
                            <td data-label="Tipo">
                                <span class="status-pill" :class="shift.type === 'rotating' ? 'tone-brand' : 'tone-neutral'">{{ shift.type === 'rotating' ? 'Rotativo' : 'Fijo' }}</span>
                                <div v-if="shift.profile" class="cell-sub mt-1">{{ profiles[shift.profile] }}</div>
                            </td>
                            <td data-label="Horario" class="text-nowrap">
                                <span class="cell-title fw-medium tabular">{{ timesOf(shift) }}</span>
                                <span v-if="shift.crosses_midnight" class="cell-sub ms-1">(+1 día)</span>
                                <div class="cell-sub">{{ hasBreakWindow(shift) ? '4 marcaciones (con refrigerio)' : '2 marcaciones' }}</div>
                                <div v-for="d in ownDays(shift)" :key="d" class="cell-sub text-info-emphasis" title="Horario propio de ese día">{{ d }}</div>
                            </td>
                            <td data-label="Días / Ciclo">
                                <span v-if="shift.type === 'rotating'" class="cell-title fw-medium">{{ shift.rotation_work_days }} × {{ shift.rotation_rest_days }}</span>
                                <span v-else class="small">{{ shift.working_days.map((d) => DAYS[d] ?? d).join(', ') }}</span>
                            </td>
                            <td data-label="Tolerancia" class="text-end text-nowrap tabular">{{ shift.grace_period_minutes }} min</td>
                            <td data-label="Asignados" class="text-end"><span class="cell-amount">{{ shift.employees_count }}</span></td>
                            <td data-label="Estado"><span class="status-pill" :class="shift.is_active ? 'tone-emerald' : 'tone-neutral'">{{ shift.is_active ? 'Activo' : 'Inactivo' }}</span></td>
                            <td class="cell-actions">
                                <div class="row-actions">
                                    <button type="button" class="icon-btn" title="Editar turno" :aria-label="`Editar ${shift.name}`" @click="openEditModal(shift)"><i class="bi bi-pencil"></i></button>
                                    <button type="button" class="icon-btn icon-btn-danger" :title="`Eliminar ${shift.name}`" :aria-label="`Eliminar ${shift.name}`" @click="destroy(shift)"><i class="bi bi-trash3"></i></button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!visibleShifts.length && shifts.length" class="row-empty">
                            <td colspan="8">
                                <div class="dash-empty">
                                    <span class="dash-empty-icon"><i class="bi bi-funnel"></i></span>
                                    <p class="dash-empty-title">Ningún horario coincide con los filtros</p>
                                    <button type="button" class="btn btn-sm btn-light border mt-2" @click="clearFilters">Limpiar filtros</button>
                                </div>
                            </td>
                        </tr>
                        <tr v-else-if="!visibleShifts.length" class="row-empty">
                            <td colspan="8">
                                <div class="dash-empty">
                                    <span class="dash-empty-icon"><i class="bi bi-calendar-x"></i></span>
                                    <p class="dash-empty-title">Aún no hay turnos registrados</p>
                                    <p class="dash-empty-text">Crea el primer horario laboral para asignar a tus colaboradores.</p>
                                    <button type="button" class="btn btn-sm btn-primary mt-2" @click="openCreateModal"><i class="bi bi-plus-lg me-1"></i>Crear turno</button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- =========================================================================
             MODAL 1: CREAR / EDITAR TURNO (CON CLASES ESTÁNDAR EMP-MODAL)
             ========================================================================= -->
        <div id="shiftModal" ref="shiftModalElement" class="modal fade" tabindex="-1" aria-labelledby="shiftModalTitle" aria-hidden="true" data-bs-backdrop="static">
            <div class="modal-dialog modal-dialog-scrollable modal-fullscreen-sm-down modal-lg modal-dialog-centered">
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
                                        <div class="col-12">
                                            <label for="shift_branch" class="form-label fw-semibold small text-dark mb-1.5">Agencia / Sede <span class="text-danger">*</span></label>
                                            <select id="shift_branch" v-model.number="shiftForm.branch_id" class="form-select" :class="{ 'is-invalid': shiftForm.errors.branch_id }" required style="border-radius: 8px; height: 42px;">
                                                <option :value="null" disabled>Elige la agencia del horario…</option>
                                                <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                                            </select>
                                            <div v-if="shiftForm.errors.branch_id" class="invalid-feedback">{{ shiftForm.errors.branch_id }}</div>
                                            <div v-else class="form-text">En la programación, este horario solo se ofrece para esta agencia.</div>
                                        </div>
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
                                        <div class="col-12">
                                            <ShiftWeekSchedule :form="shiftForm" :profiles="profiles" part="profile" />
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
                                        <div class="shift-swatches" role="radiogroup" aria-label="Color del horario">
                                            <button v-for="c in PRESET_COLORS" :key="c" type="button" role="radio"
                                                    class="shift-swatch" :class="{ 'is-selected': shiftForm.color?.toUpperCase() === c, 'is-taken': colorTaken(c) }"
                                                    :style="{ '--swatch': c }" :disabled="!!colorTaken(c)" :aria-checked="shiftForm.color?.toUpperCase() === c"
                                                    :title="colorTaken(c) ? `En esta agencia lo usa «${colorTaken(c)}»` : 'Disponible'" @click="pickColor(c)">
                                                <i v-if="colorTaken(c)" class="bi bi-lock-fill"></i>
                                                <i v-else-if="shiftForm.color?.toUpperCase() === c" class="bi bi-check-lg"></i>
                                            </button>
                                        </div>
                                        <div class="d-flex align-items-center ps-3 border-start" style="gap: 10px !important;">
                                            <label for="custom_shift_color" class="small text-secondary mb-0 fw-semibold">Personalizado:</label>
                                            <input id="custom_shift_color" v-model="shiftForm.color" type="color" class="form-control form-control-color p-0 shadow-sm" style="width: 32px; height: 32px; border-radius: 8px; cursor: pointer;">
                                        </div>
                                    </div>
                                    <div v-if="shiftForm.errors.color" class="small text-danger mt-2"><i class="bi bi-exclamation-circle me-1"></i>{{ shiftForm.errors.color }}</div>
                                    <div v-else-if="colorTaken(shiftForm.color)" class="small text-danger mt-2"><i class="bi bi-exclamation-circle me-1"></i>En esta agencia ese color ya lo usa «{{ colorTaken(shiftForm.color) }}». Elige otro.</div>
                                    <div v-else-if="!shiftForm.branch_id" class="small text-secondary mt-2"><i class="bi bi-geo-alt me-1"></i>Elige la agencia para ver qué colores ya usa.</div>
                                    <div v-else class="small text-secondary mt-2"><i class="bi bi-lock me-1"></i>Los colores con candado ya los usa otro horario de esta agencia.</div>
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
                                            <div class="p-3 border rounded-3 cursor-pointer shadow-sm transition" :style="{ borderColor: shiftForm.type === 'fixed' ? 'var(--brand, var(--brand))' : 'var(--border-color)', background: shiftForm.type === 'fixed' ? 'rgba(var(--brand-rgb), 0.06)' : 'var(--bg-surface)' }" @click="shiftForm.type = 'fixed'">
                                                <div class="d-flex align-items-center" style="gap: 8px !important; margin-bottom: 4px;">
                                                    <i class="bi bi-calendar-check fs-5 text-primary"></i>
                                                    <div class="fw-bold text-dark">Turno Fijo Semanal</div>
                                                </div>
                                                <div class="small text-secondary">Días específicos de la semana (ej. Lunes a Viernes o Lunes a Sábado).</div>
                                            </div>
                                        </div>
                                        <div class="col-12 col-md-6">
                                            <div class="p-3 border rounded-3 cursor-pointer shadow-sm transition" :style="{ borderColor: shiftForm.type === 'rotating' ? 'var(--brand, var(--brand))' : 'var(--border-color)', background: shiftForm.type === 'rotating' ? 'rgba(var(--brand-rgb), 0.06)' : 'var(--bg-surface)' }" @click="shiftForm.type = 'rotating'">
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
                                    <div class="mt-3">
                                        <ShiftWeekSchedule :form="shiftForm" />
                                    </div>
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
                                    <div class="alert alert-info border-0 d-flex align-items-start p-3 mb-0" style="border-radius: 8px; font-size: 0.825rem; gap: 10px !important;">
                                        <i class="bi bi-info-circle-fill fs-5 flex-shrink-0"></i>
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
                                        <!-- QA3-04: con las dos horas el horario es de 4 marcaciones; sin ellas, de 2 (solo duración) -->
                                        <div class="row g-3 mb-2">
                                            <div class="col-6 col-md-4">
                                                <label for="modal_break_start" class="form-label fw-semibold small text-dark mb-1.5">{{ shiftForm.split_shift ? 'Fin del 1.er tramo' : 'Salida a refrigerio' }}</label>
                                                <input id="modal_break_start" v-model="shiftForm.break_start_time" type="text" inputmode="numeric" maxlength="5" placeholder="13:00" class="form-control font-monospace" :class="{ 'is-invalid': shiftForm.errors.break_start_time }" style="border-radius: 8px; height: 42px;" @blur="normalizeTime('break_start_time')">
                                            </div>
                                            <div class="col-6 col-md-4">
                                                <label for="modal_break_end" class="form-label fw-semibold small text-dark mb-1.5">{{ shiftForm.split_shift ? 'Inicio del 2.º tramo' : 'Retorno de refrigerio' }}</label>
                                                <input id="modal_break_end" v-model="shiftForm.break_end_time" type="text" inputmode="numeric" maxlength="5" placeholder="14:00" class="form-control font-monospace" :class="{ 'is-invalid': shiftForm.errors.break_end_time }" style="border-radius: 8px; height: 42px;" @blur="normalizeTime('break_end_time')">
                                            </div>
                                            <div v-if="shiftForm.errors.break_start_time || shiftForm.errors.break_end_time" class="col-12 invalid-feedback d-block mt-0">{{ shiftForm.errors.break_start_time || shiftForm.errors.break_end_time }}</div>
                                        </div>
                                        <div v-if="shiftForm.break_start_time && shiftForm.break_end_time" class="d-flex align-items-center justify-content-between gap-3 mb-2">
                                            <label for="modal_split_shift" class="mb-0">
                                                <span class="fw-semibold small text-dark d-block">Horario partido (dos tramos)</span>
                                                <span class="small text-secondary">Ej.: 08:00–12:00 y 18:00–22:00. En la app se marca fin del 1.er tramo e inicio del 2.º.</span>
                                            </label>
                                            <div class="form-check form-switch mb-0">
                                                <input id="modal_split_shift" v-model="shiftForm.split_shift" class="form-check-input" type="checkbox" role="switch" style="width: 2.75em; height: 1.45em; cursor: pointer;">
                                            </div>
                                        </div>
                                        <div class="form-text small text-secondary mb-3">
                                            Con las dos horas el horario pide <strong>4 marcaciones</strong> (entrada, salida a refrigerio, retorno y salida) y la duración se calcula sola.
                                            Déjalas vacías para un horario de 2 marcaciones (por ejemplo, el sábado sin refrigerio).
                                        </div>
                                        <template v-if="!(shiftForm.break_start_time && shiftForm.break_end_time)">
                                            <label for="modal_break_minutes" class="form-label fw-semibold small text-dark mb-1.5">Duración del descanso (minutos) <span class="text-danger">*</span></label>
                                            <input id="modal_break_minutes" v-model="shiftForm.break_duration_minutes" type="number" min="0" max="480" class="form-control font-monospace" :class="{ 'is-invalid': shiftForm.errors.break_duration_minutes }" required style="border-radius: 8px; height: 42px; max-width: 220px;">
                                            <div class="form-text small text-secondary mt-1">Tiempo reglamentario de refrigerio (ej. 45 o 60 minutos).</div>
                                        </template>
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

    </AppLayout>
</template>

<style scoped>
/* Punto de color del turno en la lista */
.shift-dot { width: 10px; height: 10px; margin-top: 6px; border-radius: 50%; flex-shrink: 0; box-shadow: 0 0 0 2px var(--bg-surface), 0 0 0 3px var(--border-color); }
.shift-swatches { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
.shift-swatch {
    width: 34px; height: 34px; padding: 0; border-radius: 9px;
    display: inline-flex; align-items: center; justify-content: center;
    background: var(--swatch); color: #fff; font-size: 1rem;
    border: 1px solid rgba(0, 0, 0, 0.1); box-shadow: 0 1px 2px rgba(15, 23, 42, 0.12);
    transition: transform .12s, box-shadow .12s;
}
.shift-swatch:not(:disabled):hover { transform: translateY(-1px); box-shadow: 0 3px 8px rgba(15, 23, 42, 0.18); }
.shift-swatch.is-selected { outline: 2px solid var(--text-primary, #0f172a); outline-offset: 2px; }
.shift-swatch.is-taken {
    cursor: not-allowed; opacity: 0.45; font-size: 0.8rem;
    background-image: repeating-linear-gradient(45deg, rgba(255,255,255,.28) 0 4px, transparent 4px 8px);
    background-color: var(--swatch);
}
.shift-swatch:focus-visible { outline: 2px solid var(--brand, var(--brand)); outline-offset: 2px; }
</style>
