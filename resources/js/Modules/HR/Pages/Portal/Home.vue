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

const page = usePage();
const person = computed(() => page.props.portal?.person || {});
const sections = computed(() => page.props.portal || {});
const hour = new Date().getHours();
// Igual que el Dashboard: de madrugada todavía es "buenas noches"
const greeting = hour >= 6 && hour < 12 ? 'Buenos días' : hour >= 12 && hour < 19 ? 'Buenas tardes' : 'Buenas noches';
const longDate = computed(() => {
    const text = new Date(`${props.today}T12:00:00`).toLocaleDateString('es-PE', { weekday: 'long', day: 'numeric', month: 'long' });
    return text.charAt(0).toUpperCase() + text.slice(1);
});
const hhmm = (t) => (t ? t.slice(0, 5) : null);

// Las 4 marcas del día, en orden (las mismas que registra la app)
const slots = computed(() => [
    { key: 'clock_in', label: 'Entrada', icon: 'bi-box-arrow-in-right', time: hhmm(props.attendance?.clock_in?.time) },
    { key: 'break_start', label: 'Inicio de refrigerio', icon: 'bi-cup-hot', time: hhmm(props.attendance?.break?.start_time) },
    { key: 'break_end', label: 'Fin de refrigerio', icon: 'bi-arrow-repeat', time: hhmm(props.attendance?.break?.end_time) },
    { key: 'clock_out', label: 'Salida', icon: 'bi-box-arrow-right', time: hhmm(props.attendance?.clock_out?.time) },
]);

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
const done = computed(() => slots.value.filter((s) => s.time).length);

const shortcuts = computed(() => [
    { href: '/mi-espacio/asistencia', icon: 'bi-calendar2-check', title: 'Mi asistencia', text: 'Tus jornadas del mes y justificaciones', show: sections.value.attendance },
    { href: '/mi-espacio/boletas', icon: 'bi-file-earmark-text', title: 'Mis boletas', text: 'Descarga tus boletas de pago', show: sections.value.payslips },
    { href: '/mi-espacio/perfil', icon: 'bi-person-vcard', title: 'Mi perfil', text: 'Tus datos, legajo y contraseña', show: true },
].filter((s) => s.show));
</script>

<template>
    <PortalLayout title="Inicio">
        <section class="portal-hero">
            <div>
                <p class="portal-hero-date mb-1">{{ longDate }}</p>
                <h1 class="portal-hero-title mb-1">{{ greeting }}, {{ person.first_name }}</h1>
                <p class="portal-hero-sub mb-0">{{ [person.position, person.company].filter(Boolean).join(' · ') }}</p>
            </div>
            <div v-if="sections.attendance" class="portal-hero-shift">
                <i class="bi bi-clock"></i>
                <span v-if="schedule?.shift"><strong>{{ schedule.shift.start_time }} – {{ schedule.shift.end_time }}</strong><small class="d-block">{{ schedule.shift.name }}</small></span>
                <span v-else>Sin turno hoy</span>
            </div>
        </section>

        <div v-if="sections.attendance" class="card mb-3">
            <div class="card-body p-3 p-md-4">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <h2 class="h6 fw-bold mb-0">Mis marcas de hoy</h2>
                    <span class="status-pill" :class="done === 4 ? 'tone-emerald' : 'tone-neutral'">{{ done }} de 4</span>
                </div>

                <div v-if="contingency" class="alert alert-warning d-flex gap-2 align-items-start py-2 small">
                    <i class="bi bi-life-preserver mt-1"></i>
                    <span><strong>Plan de contingencia activo hasta las {{ until }}.</strong> {{ contingency.reason }}. Marca aquí mientras la app no funcione; la hora la pone el sistema.</span>
                </div>
                <p v-else-if="!schedule?.shift && schedule?.message" class="small text-body-secondary">{{ schedule.message }}</p>

                <div class="punch-grid">
                    <div v-for="s in slots" :key="s.key" class="punch" :class="{ 'is-done': s.time, 'is-next': !s.time && allowed.includes(s.key) }">
                        <span class="punch-icon"><i class="bi" :class="s.time ? 'bi-check-lg' : s.icon"></i></span>
                        <span class="punch-label">{{ s.label }}</span>
                        <span v-if="s.time || !allowed.includes(s.key)" class="punch-time">{{ s.time || '--:--' }}</span>
                        <button v-else type="button" class="btn btn-sm punch-btn" :disabled="marking.processing" @click="mark(s.key)">Marcar ahora</button>
                    </div>
                </div>

                <p v-if="!contingency" class="small text-body-secondary mt-3 mb-0">
                    <i class="bi bi-phone me-1"></i>Marcas desde la app. ¿Olvidaste una marca o llegaste tarde? Justifícalo en
                    <Link href="/mi-espacio/asistencia">Mi asistencia</Link>.<span v-if="attendance?.status_label"> Hoy: <strong>{{ attendance.status_label }}</strong>.</span>
                </p>
            </div>
        </div>

        <div class="row g-3">
            <div v-for="s in shortcuts" :key="s.href" class="col-12 col-md-4">
                <Link :href="s.href" class="card h-100 text-decoration-none portal-shortcut">
                    <div class="card-body d-flex align-items-center gap-3 p-3">
                        <span class="portal-shortcut-icon"><i class="bi" :class="s.icon"></i></span>
                        <span class="flex-grow-1">
                            <span class="d-block fw-semibold text-body">{{ s.title }}</span>
                            <span class="d-block small text-body-secondary">{{ s.text }}</span>
                        </span>
                        <i class="bi bi-chevron-right text-body-tertiary"></i>
                    </div>
                </Link>
            </div>
        </div>
    </PortalLayout>
</template>

<style>
.portal-hero { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-end; gap: 1rem; margin-bottom: 1rem; padding: 1.5rem;
    border-radius: var(--portal-radius); color: #fff;
    background: linear-gradient(135deg, var(--brand) 0%, rgba(var(--brand-rgb), .78) 100%); box-shadow: 0 8px 24px rgba(var(--brand-rgb), .25); }
.portal-hero-date { opacity: .85; font-size: .9rem; }
.portal-hero-title { font-size: 1.6rem; font-weight: 700; letter-spacing: -.01em; }
.portal-hero-sub { opacity: .9; }
.portal-hero-shift { display: flex; align-items: center; gap: .6rem; padding: .6rem .9rem; border-radius: 12px; background: rgba(255, 255, 255, .16); backdrop-filter: blur(4px); }
.portal-hero-shift i { font-size: 1.25rem; }
.punch-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: .75rem; }
.punch { display: flex; flex-direction: column; align-items: center; text-align: center; gap: .35rem; padding: 1rem .5rem; border-radius: var(--radius-md);
    border: 1px solid var(--border-color); background: var(--bg-surface); transition: border-color var(--dur-2) var(--ease-out), background-color var(--dur-2) var(--ease-out); }
.punch-icon { width: 40px; height: 40px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 1.1rem;
    background: var(--bg-surface-elevated); color: var(--text-muted); }
.punch-label { font-size: .82rem; font-weight: 600; color: var(--text-secondary); }
.punch-time { font-size: 1.4rem; font-weight: 700; font-variant-numeric: tabular-nums; color: var(--text-muted); }
.punch.is-done { border-color: rgba(var(--bs-success-rgb), .35); background: rgba(var(--bs-success-rgb), .05); }
.punch.is-done .punch-icon { background: rgba(var(--bs-success-rgb), .15); color: var(--bs-success); }
.punch.is-done .punch-time { color: var(--bs-success); }
.punch.is-next { border: 2px solid var(--brand); }
.punch.is-next .punch-icon { background: rgba(var(--brand-rgb), .12); color: var(--brand); }
.punch-btn { background: var(--brand); color: var(--brand-text, #fff); border-radius: var(--radius-sm); font-weight: 600; width: 100%; min-height: 38px; }
.punch-btn:hover { filter: brightness(.95); color: var(--brand-text, #fff); }
.portal-shortcut { transition: border-color var(--dur-2) var(--ease-out), box-shadow var(--dur-2) var(--ease-out); }
.portal-shortcut:hover { border-color: rgba(var(--brand-rgb), .35) !important; box-shadow: var(--shadow-sm) !important; }
.portal-shortcut:active { transform: scale(.99); }
.portal-shortcut:hover .bi-chevron-right { color: var(--brand) !important; translate: 2px 0; }
.portal-shortcut .bi-chevron-right { transition: translate var(--dur-2) var(--ease-out), color var(--dur-2) var(--ease-out); }
.portal-shortcut-icon { width: 44px; height: 44px; border-radius: 12px; display: inline-flex; align-items: center; justify-content: center; font-size: 1.25rem;
    background: rgba(var(--brand-rgb), .1); color: var(--brand); flex-shrink: 0; }
@media (max-width: 767.98px) {
    .punch-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .portal-hero { padding: 1.25rem; }
    .portal-hero-title { font-size: 1.35rem; }
}
</style>
