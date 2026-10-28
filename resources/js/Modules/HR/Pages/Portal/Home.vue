<script setup>
import { computed } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import PortalLayout from '@/Modules/HR/Layouts/PortalLayout.vue';

const props = defineProps({
    today: { type: String, required: true },
    attendance: { type: Object, default: null },
    schedule: { type: Object, default: null },
    next: { type: Object, default: null },
    contingency: { type: Object, default: null },
});

// Con un plan de contingencia vigente se marca aquí; solo la marca que toca (mismo orden que la app)
const allowed = computed(() => {
    if (!props.contingency || !props.next) return [];
    const actions = props.next.actions ? props.next.actions.map((a) => a.action) : [props.next.action];
    return actions.filter((a) => a !== 'clock_in' || props.schedule?.can_clock_in !== false);
});
const marking = useForm({ type: '' });
function mark(type) {
    marking.type = type;
    marking.post('/mi-espacio/marcar', { preserveScroll: true });
}
const until = computed(() => (props.contingency ? new Date(props.contingency.ends_at).toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit' }) : null));

const page = usePage();
const first = computed(() => (page.props.auth?.name || '').split(' ')[0]);
const longDate = computed(() => {
    const text = new Date(`${props.today}T12:00:00`).toLocaleDateString('es-PE', { weekday: 'long', day: 'numeric', month: 'long' });
    return text.charAt(0).toUpperCase() + text.slice(1);
});
const hhmm = (t) => (t ? t.slice(0, 5) : null);
// Las 4 marcas del día, en orden (las mismas que registra la app)
const slots = computed(() => [
    { key: 'clock_in', label: 'Entrada', time: hhmm(props.attendance?.clock_in?.time) },
    { key: 'break_start', label: 'Inicio de refrigerio', time: hhmm(props.attendance?.break?.start_time) },
    { key: 'break_end', label: 'Fin de refrigerio', time: hhmm(props.attendance?.break?.end_time) },
    { key: 'clock_out', label: 'Salida', time: hhmm(props.attendance?.clock_out?.time) },
]);
</script>

<template>
    <PortalLayout title="Mi espacio">
        <h1 class="h4 fw-bold mt-2 mb-1">Hola, {{ first }}</h1>
        <p class="text-body-secondary mb-3">{{ longDate }}</p>

        <div v-if="page.props.portal?.attendance" class="card shadow-sm mb-3">
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <h2 class="h6 fw-bold mb-0">Mis marcas de hoy</h2>
                    <span class="small text-body-secondary">
                        <template v-if="schedule?.shift">Turno {{ schedule.shift.name }} · {{ schedule.shift.start_time }} – {{ schedule.shift.end_time }}</template>
                        <template v-else>{{ schedule?.message || 'Sin turno programado hoy' }}</template>
                    </span>
                </div>
                <div v-if="contingency" class="alert alert-warning py-2 small">
                    <i class="bi bi-life-preserver me-1"></i>Plan de contingencia activo hasta las {{ until }} ({{ contingency.reason }}):
                    marca aquí mientras la app no funcione. La hora la pone el sistema.
                </div>
                <div class="row g-2">
                    <div v-for="s in slots" :key="s.key" class="col-6 col-md-3">
                        <div class="border rounded p-3 text-center h-100" :class="s.time ? 'border-success-subtle' : allowed.includes(s.key) ? 'border-primary border-2' : ''">
                            <div class="small text-body-secondary">{{ s.label }}</div>
                            <div v-if="s.time || !allowed.includes(s.key)" class="fs-4 fw-semibold" :class="s.time ? 'text-success' : 'text-body-tertiary'">{{ s.time || '--:--' }}</div>
                            <button v-else type="button" class="btn btn-primary btn-sm w-100 mt-1" :disabled="marking.processing" @click="mark(s.key)">
                                <i class="bi bi-clock me-1"></i>Marcar
                            </button>
                        </div>
                    </div>
                </div>
                <p v-if="!contingency" class="small text-body-secondary mt-3 mb-0">
                    <i class="bi bi-phone me-1"></i>Marcas desde la app. Si olvidaste una marca o llegaste tarde, justifícalo en
                    <Link href="/mi-espacio/asistencia">Mi asistencia</Link>.
                    <span v-if="attendance?.status_label"> Hoy: <strong>{{ attendance.status_label }}</strong>.</span>
                </p>
            </div>
        </div>

        <div class="row g-3">
            <div v-if="page.props.portal?.attendance" class="col-12 col-md-4">
                <Link href="/mi-espacio/asistencia" class="card shadow-sm h-100 text-decoration-none">
                    <div class="card-body"><i class="bi bi-calendar-check fs-4 text-primary"></i><div class="fw-semibold mt-1">Mi asistencia</div><div class="small text-body-secondary">Tus jornadas del mes y justificaciones</div></div>
                </Link>
            </div>
            <div v-if="page.props.portal?.payslips" class="col-12 col-md-4">
                <Link href="/mi-espacio/boletas" class="card shadow-sm h-100 text-decoration-none">
                    <div class="card-body"><i class="bi bi-file-earmark-text fs-4 text-primary"></i><div class="fw-semibold mt-1">Mis boletas</div><div class="small text-body-secondary">Descarga tus boletas de pago</div></div>
                </Link>
            </div>
            <div class="col-12 col-md-4">
                <Link href="/mi-espacio/perfil" class="card shadow-sm h-100 text-decoration-none">
                    <div class="card-body"><i class="bi bi-person fs-4 text-primary"></i><div class="fw-semibold mt-1">Mi perfil</div><div class="small text-body-secondary">Tus datos, legajo y contraseña</div></div>
                </Link>
            </div>
        </div>
    </PortalLayout>
</template>
