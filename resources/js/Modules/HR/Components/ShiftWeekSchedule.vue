<script>
// Horarios típicos por perfil: un clic llena el turno y luego se ajusta
export const SHIFT_TEMPLATES = {
    administrative: {
        start_time: '08:00', end_time: '17:00', break_allowed: true, break_duration_minutes: 60, daily_hours: 8,
        working_days: [1, 2, 3, 4, 5, 6],
        day_schedules: { 6: { start_time: '08:00', end_time: '13:00', break_duration_minutes: 0, daily_hours: 5 } },
        hint: 'Lun–Vie 08:00–17:00 y sábado 08:00–13:00',
    },
    sales: {
        start_time: '10:00', end_time: '19:00', break_allowed: true, break_duration_minutes: 60, daily_hours: 8,
        working_days: [1, 2, 3, 4, 5, 6], day_schedules: {},
        hint: 'Lun–Sáb 10:00–19:00 con 1 h de refrigerio',
    },
    operations: {
        start_time: '07:00', end_time: '16:00', break_allowed: true, break_duration_minutes: 60, daily_hours: 8,
        working_days: [1, 2, 3, 4, 5, 6], day_schedules: {},
        hint: 'Lun–Sáb 07:00–16:00 con 1 h de refrigerio',
    },
};

const DAY_FIELDS = ['start_time', 'end_time', 'break_duration_minutes', 'daily_hours'];

/** Los 7 días con sus campos (vacío = horario general), para enlazar con v-model */
export function emptyDays(source = {}) {
    return Object.fromEntries([0, 1, 2, 3, 4, 5, 6].map((d) => [d, {
        start_time: '', end_time: '', break_duration_minutes: '', daily_hours: '', ...(source?.[d] ?? {}),
    }]));
}
</script>

<script setup>
import { computed } from 'vue';

// `form` es el useForm del turno: este bloque edita profile y day_schedules
const props = defineProps({
    form: { type: Object, required: true },
    profiles: { type: Object, default: () => ({}) },
    part: { type: String, default: 'days' }, // 'profile' | 'days'
});

const ORDERED_DAYS = [
    { index: 1, label: 'Lun' }, { index: 2, label: 'Mar' }, { index: 3, label: 'Mié' }, { index: 4, label: 'Jue' },
    { index: 5, label: 'Vie' }, { index: 6, label: 'Sáb' }, { index: 0, label: 'Dom' },
];

const template = computed(() => SHIFT_TEMPLATES[props.form.profile]);

function applyTemplate() {
    const { hint, ...t } = template.value;
    Object.assign(props.form, { ...t, type: 'fixed', working_days: [...t.working_days], day_schedules: emptyDays(t.day_schedules) });
}

const isSet = (v) => v !== '' && v !== null && v !== undefined;
const hasDayChanges = (d) => DAY_FIELDS.some((f) => isSet(props.form.day_schedules[d]?.[f]));
const resetDay = (d) => DAY_FIELDS.forEach((f) => { props.form.day_schedules[d][f] = ''; });

const days = computed(() => ORDERED_DAYS.filter((d) => props.form.working_days.includes(d.index)));

// Horas a la semana según el horario de cada día (para no pasar el máximo legal sin darse cuenta)
const weeklyHours = computed(() => days.value.reduce((sum, d) => {
    const own = props.form.day_schedules[d.index]?.daily_hours;
    return sum + Number(isSet(own) ? own : props.form.daily_hours || 0);
}, 0));

const dayErrors = computed(() => Object.keys(props.form.errors).some((k) => k.startsWith('day_schedules')));
</script>

<template>
    <div v-if="part === 'profile'">
        <label for="shift-profile" class="emp-form-label">
            Perfil de personal
            <i class="bi bi-question-circle text-muted ms-1" title="Agrupa los turnos por tipo de personal (administrativo, ventas, operaciones) para asignarlos sin confundirse."></i>
        </label>
        <div class="d-flex gap-2">
            <select id="shift-profile" v-model="form.profile" class="emp-form-select" :class="{ 'is-invalid': form.errors.profile }">
                <option value="">General</option>
                <option v-for="(label, key) in profiles" :key="key" :value="key">{{ label }}</option>
            </select>
            <button v-if="template" type="button" class="btn btn-sm btn-outline-primary text-nowrap" style="border-radius: 7px;" @click="applyTemplate">
                <i class="bi bi-magic me-1"></i>Usar horario típico
            </button>
        </div>
        <div v-if="template" class="small text-muted mt-1">Típico: {{ template.hint }}</div>
    </div>

    <!-- Horario por día: vacío = horario general (así el sábado corto calcula bien tardanza, break y horas extra) -->
    <div v-else>
        <div class="fw-semibold text-body mb-1" style="font-size: 0.825rem;">Horario de cada día</div>
        <div class="small text-muted mb-2">Deja en blanco lo que sea igual al horario general. Cambia solo los días distintos (por ejemplo, sábado de 08:00 a 13:00).</div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-1" style="font-size: 0.8rem;">
                <thead>
                    <tr class="text-muted">
                        <th>Día</th><th>Entrada</th><th>Salida</th><th>Refrigerio (min)</th><th>Horas</th><th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="d in days" :key="d.index">
                        <td class="fw-semibold text-nowrap">
                            {{ d.label }}
                            <span v-if="hasDayChanges(d.index)" class="badge text-bg-info ms-1">Propio</span>
                        </td>
                        <td><input v-model="form.day_schedules[d.index].start_time" type="time" class="form-control form-control-sm font-monospace" :aria-label="`Entrada ${d.label}`"></td>
                        <td><input v-model="form.day_schedules[d.index].end_time" type="time" class="form-control form-control-sm font-monospace" :aria-label="`Salida ${d.label}`"></td>
                        <td><input v-model="form.day_schedules[d.index].break_duration_minutes" type="number" min="0" max="480" class="form-control form-control-sm font-monospace" :placeholder="String(form.break_allowed ? form.break_duration_minutes : 0)" :aria-label="`Refrigerio ${d.label}`"></td>
                        <td><input v-model="form.day_schedules[d.index].daily_hours" type="number" step="0.25" min="0.5" max="24" class="form-control form-control-sm font-monospace" :placeholder="String(form.daily_hours)" :aria-label="`Horas ${d.label}`"></td>
                        <td>
                            <button v-if="hasDayChanges(d.index)" type="button" class="btn btn-sm btn-link text-muted p-0" title="Usar el horario general" @click="resetDay(d.index)">
                                <i class="bi bi-arrow-counterclockwise"></i>
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div v-if="dayErrors" class="text-danger small mb-1">Revisa el horario de los días: hay horas o minutos inválidos.</div>
        <div class="small" :class="weeklyHours > 48 ? 'text-danger fw-semibold' : 'text-muted'">
            <i class="bi" :class="weeklyHours > 48 ? 'bi-exclamation-triangle-fill' : 'bi-calculator'"></i>
            Total semanal: {{ weeklyHours }} h
            <span v-if="weeklyHours > 48">— supera la jornada máxima de 48 h semanales.</span>
        </div>
    </div>
</template>
