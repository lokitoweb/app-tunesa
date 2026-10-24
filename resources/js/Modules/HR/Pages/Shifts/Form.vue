<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import ShiftWeekSchedule, { emptyDays } from '@/Modules/HR/Components/ShiftWeekSchedule.vue';

const props = defineProps({ shift: Object, profiles: { type: Object, default: () => ({}) } });

const DAYS = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
const ORDERED_DAYS = [
    { index: 1, label: 'Lun' },
    { index: 2, label: 'Mar' },
    { index: 3, label: 'Mié' },
    { index: 4, label: 'Jue' },
    { index: 5, label: 'Vie' },
    { index: 6, label: 'Sáb' },
    { index: 0, label: 'Dom' },
];

const PRESET_COLORS = [
    '#0284C7', '#2563EB', '#059669', '#D97706', '#7C3AED', '#DC2626', '#0D9488', '#475569',
];

const editing = props.shift.id !== null;
const form = useForm({
    ...props.shift,
    start_time: props.shift.start_time ? props.shift.start_time.substring(0, 5) : '08:00',
    end_time: props.shift.end_time ? props.shift.end_time.substring(0, 5) : '17:00',
    working_days: Array.isArray(props.shift.working_days) ? [...props.shift.working_days] : [1, 2, 3, 4, 5],
    profile: props.shift.profile ?? '',
    day_schedules: emptyDays(props.shift.day_schedules),
});

function normalizeTime(field) {
    const m = String(form[field] ?? '').trim().match(/^(\d{1,2})[:.h]?(\d{2})?$/);
    if (m && Number(m[1]) < 24 && Number(m[2] ?? 0) < 60) {
        form[field] = `${m[1].padStart(2, '0')}:${m[2] ?? '00'}`;
    }
}

function toggleWorkingDay(dayIndex) {
    const idx = form.working_days.indexOf(dayIndex);
    if (idx > -1) {
        if (form.working_days.length > 1) {
            form.working_days.splice(idx, 1);
        }
    } else {
        form.working_days.push(dayIndex);
        form.working_days.sort((a, b) => a - b);
    }
}

function setWorkingDaysPreset(preset) {
    if (preset === 'weekdays') form.working_days = [1, 2, 3, 4, 5];
    else if (preset === 'mon_sat') form.working_days = [1, 2, 3, 4, 5, 6];
    else if (preset === 'all') form.working_days = [0, 1, 2, 3, 4, 5, 6];
}

function submit() {
    normalizeTime('start_time');
    normalizeTime('end_time');
    form.transform((data) => ({ ...data, profile: data.profile || null }));
    editing ? form.put(`/shifts/${props.shift.id}`) : form.post('/shifts');
}
</script>

<template>
    <AppLayout :title="editing ? 'Editar turno' : 'Nuevo turno'">
        <div class="mb-3">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb small mb-2">
                    <li class="breadcrumb-item"><Link href="/shifts">Turnos y horarios</Link></li>
                    <li class="breadcrumb-item active" aria-current="page">{{ editing ? 'Editar turno' : 'Nuevo turno' }}</li>
                </ol>
            </nav>
            <div class="d-flex align-items-center gap-3">
                <div class="emp-modal-icon shadow-sm" style="width: 44px; height: 44px; font-size: 1.35rem; border-radius: 10px;">
                    <i :class="editing ? 'bi bi-pencil-square' : 'bi bi-plus-circle-fill'"></i>
                </div>
                <div>
                    <h1 class="h3 fw-bold mb-0 text-body">{{ editing ? `Editar turno: ${shift.name}` : 'Nuevo turno' }}</h1>
                    <p class="text-secondary small mb-0 mt-1">Configuración de horario, tolerancias, descansos y periodicidad.</p>
                </div>
            </div>
        </div>

        <form @submit.prevent="submit">
            <!-- Sección 1: Datos Principales -->
            <div class="emp-section-card mb-3">
                <div class="emp-section-title">
                    <i class="bi bi-info-circle-fill"></i>
                    <span>Identificación & Tipo</span>
                </div>
                <div class="row g-3">
                    <div class="col-md-5">
                        <label for="name" class="emp-form-label">Nombre del turno *</label>
                        <input id="name" v-model="form.name" class="emp-form-input" :class="{ 'is-invalid': form.errors.name }" required>
                        <div v-if="form.errors.name" class="text-danger small mt-1">{{ form.errors.name }}</div>
                    </div>
                    <div class="col-md-3">
                        <label for="code" class="emp-form-label">Código</label>
                        <input id="code" v-model="form.code" class="emp-form-input font-monospace" :class="{ 'is-invalid': form.errors.code }">
                        <div v-if="form.errors.code" class="text-danger small mt-1">{{ form.errors.code }}</div>
                    </div>
                    <div class="col-md-4">
                        <label class="emp-form-label">Color distintivo *</label>
                        <div class="d-flex align-items-center gap-2">
                            <input v-model="form.color" type="color" class="form-control form-control-color p-0" style="width: 38px; height: 38px; border-radius: 7px; cursor: pointer;">
                            <div class="d-flex align-items-center gap-1 flex-wrap">
                                <button v-for="c in PRESET_COLORS" :key="c" type="button" class="border-0 p-0 shadow-sm" :style="{ width: '18px', height: '18px', borderRadius: '4px', background: c, outline: form.color === c ? '2px solid var(--accent-cyan)' : 'none' }" :title="c" @click="form.color = c"></button>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <ShiftWeekSchedule :form="form" :profiles="profiles" part="profile" />
                    </div>
                    <div class="col-md-6">
                        <label for="shiftType" class="emp-form-label">Modalidad de jornada *</label>
                        <select id="shiftType" v-model="form.type" class="emp-form-select">
                            <option value="fixed">Fijo — Días predeterminados de la semana (ej. Lun a Vie)</option>
                            <option value="rotating">Rotativo — Ciclos periódicos de N días de trabajo × M de descanso (ej. 4×2)</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Sección 2: Horarios y Tolerancia -->
            <div class="emp-section-card mb-3">
                <div class="emp-section-title">
                    <i class="bi bi-clock-fill"></i>
                    <span>Horarios y Jornada</span>
                </div>
                <div class="row g-3">
                    <div class="col-6 col-md-3">
                        <label for="start_time" class="emp-form-label">Entrada (24h) *</label>
                        <input id="start_time" v-model="form.start_time" type="time" class="emp-form-input font-monospace" :class="{ 'is-invalid': form.errors.start_time }" required @blur="normalizeTime('start_time')">
                        <div v-if="form.errors.start_time" class="text-danger small mt-1">{{ form.errors.start_time }}</div>
                    </div>
                    <div class="col-6 col-md-3">
                        <label for="end_time" class="emp-form-label">Salida (24h) *</label>
                        <input id="end_time" v-model="form.end_time" type="time" class="emp-form-input font-monospace" :class="{ 'is-invalid': form.errors.end_time }" required @blur="normalizeTime('end_time')">
                        <div v-if="form.errors.end_time" class="text-danger small mt-1">{{ form.errors.end_time }}</div>
                    </div>
                    <div class="col-6 col-md-3">
                        <label for="grace_period_minutes" class="emp-form-label">Tolerancia (min) *</label>
                        <input id="grace_period_minutes" v-model="form.grace_period_minutes" type="number" min="0" max="240" class="emp-form-input font-monospace" :class="{ 'is-invalid': form.errors.grace_period_minutes }" required>
                        <div v-if="form.errors.grace_period_minutes" class="text-danger small mt-1">{{ form.errors.grace_period_minutes }}</div>
                    </div>
                    <div class="col-6 col-md-3">
                        <label for="daily_hours" class="emp-form-label">Horas / Día *</label>
                        <input id="daily_hours" v-model="form.daily_hours" type="number" step="0.25" min="0.5" max="24" class="emp-form-input font-monospace" :class="{ 'is-invalid': form.errors.daily_hours }" required>
                        <div v-if="form.errors.daily_hours" class="text-danger small mt-1">{{ form.errors.daily_hours }}</div>
                    </div>
                </div>
            </div>

            <!-- Sección 3: Programación (Fijo vs Rotativo) -->
            <div class="emp-section-card mb-3">
                <div class="emp-section-title">
                    <i class="bi bi-calendar3"></i>
                    <span>{{ form.type === 'fixed' ? 'Días Laborables Semanales' : 'Ciclo de Rotación' }}</span>
                </div>

                <div v-if="form.type === 'fixed'">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2.5">
                        <span class="small text-muted">Selecciona los días que comprende este turno:</span>
                        <div class="d-flex align-items-center gap-1">
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0.5 px-2" style="font-size: 0.725rem; border-radius: 5px;" @click="setWorkingDaysPreset('weekdays')">
                                Lun – Vie
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0.5 px-2" style="font-size: 0.725rem; border-radius: 5px;" @click="setWorkingDaysPreset('mon_sat')">
                                Lun – Sáb
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0.5 px-2" style="font-size: 0.725rem; border-radius: 5px;" @click="setWorkingDaysPreset('all')">
                                Toda la semana
                            </button>
                        </div>
                    </div>
                    <div class="working-days-grid">
                        <button v-for="d in ORDERED_DAYS" :key="d.index" type="button" class="btn shadow-sm d-inline-flex align-items-center justify-content-center transition day-pill-btn" :class="form.working_days.includes(d.index) ? 'btn-primary' : 'btn-outline-secondary'" @click="toggleWorkingDay(d.index)">
                            <i class="bi" :class="form.working_days.includes(d.index) ? 'bi-check-circle-fill' : 'bi-circle'"></i>
                            <span>{{ d.label }}</span>
                        </button>
                    </div>
                    <div v-if="form.errors.working_days" class="text-danger small mt-2">{{ form.errors.working_days }}</div>

                    <div class="mt-3">
                        <ShiftWeekSchedule :form="form" />
                    </div>
                </div>

                <div v-else>
                    <div class="row g-3">
                        <div class="col-6">
                            <label for="rotation_work_days" class="emp-form-label">Días de trabajo seguidos *</label>
                            <input id="rotation_work_days" v-model="form.rotation_work_days" type="number" min="1" max="60" class="emp-form-input font-monospace" :class="{ 'is-invalid': form.errors.rotation_work_days }" required>
                        </div>
                        <div class="col-6">
                            <label for="rotation_rest_days" class="emp-form-label">Días de descanso seguidos *</label>
                            <input id="rotation_rest_days" v-model="form.rotation_rest_days" type="number" min="1" max="60" class="emp-form-input font-monospace" :class="{ 'is-invalid': form.errors.rotation_rest_days }" required>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sección 4: Descanso y Políticas -->
            <div class="emp-section-card mb-3">
                <div class="emp-section-title">
                    <i class="bi bi-cup-hot-fill"></i>
                    <span>Refrigerio y Estado Operativo</span>
                </div>
                <div class="row g-3 align-items-center">
                    <div class="col-md-6">
                        <div class="emp-switch-box d-flex align-items-center justify-content-between">
                            <div>
                                <div class="fw-semibold text-body" style="font-size: 0.825rem;">Permite refrigerio / descanso</div>
                                <div class="small text-muted" style="font-size: 0.725rem;">Habilita el registro de pausa en jornada</div>
                            </div>
                            <div class="form-check form-switch mb-0">
                                <input id="break_allowed" v-model="form.break_allowed" class="form-check-input emp-custom-switch" type="checkbox" role="switch">
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="emp-switch-box d-flex align-items-center justify-content-between">
                            <div>
                                <div class="fw-semibold text-body" style="font-size: 0.825rem;">Turno activo</div>
                                <div class="small text-muted" style="font-size: 0.725rem;">Disponible para asignación a personal</div>
                            </div>
                            <div class="form-check form-switch mb-0">
                                <input id="is_active" v-model="form.is_active" class="form-check-input emp-custom-switch" type="checkbox" role="switch">
                            </div>
                        </div>
                    </div>
                    <div v-if="form.break_allowed" class="col-md-6">
                        <label for="break_duration_minutes" class="emp-form-label">Duración del descanso (minutos) *</label>
                        <input id="break_duration_minutes" v-model="form.break_duration_minutes" type="number" min="0" max="480" class="emp-form-input font-monospace" :class="{ 'is-invalid': form.errors.break_duration_minutes }" required>
                    </div>
                </div>
            </div>

            <!-- Sección 5: Notas -->
            <div class="emp-section-card mb-3">
                <div class="emp-section-title">
                    <i class="bi bi-chat-square-text-fill"></i>
                    <span>Notas & Observaciones</span>
                </div>
                <div>
                    <label for="description" class="emp-form-label">Descripción interna (opcional)</label>
                    <textarea id="description" v-model="form.description" class="form-control" rows="2" style="border-radius: 7px; font-size: 0.825rem;" placeholder="Detalles operativos o indicaciones especiales..."></textarea>
                </div>
            </div>

            <!-- Acciones -->
            <div class="d-flex justify-content-end gap-2 mb-4">
                <Link href="/shifts" class="btn btn-outline-secondary px-3 py-1.5" style="border-radius: 7px;">Cancelar</Link>
                <button class="btn btn-primary px-3.5 py-1.5 d-inline-flex align-items-center gap-2 fw-semibold" :disabled="form.processing" style="border-radius: 7px;">
                    <span v-if="form.processing" class="spinner-border spinner-border-sm" role="status"></span>
                    <i v-else class="bi bi-check2"></i>
                    <span>{{ editing ? 'Guardar cambios' : 'Crear turno' }}</span>
                </button>
            </div>
        </form>
    </AppLayout>
</template>
