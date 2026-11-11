<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';

// Tarjeta de marcación web (Mi espacio y "Marcar mi asistencia" del panel): mismas reglas que la app
const props = defineProps({
    attendance: { type: Object, default: null },
    schedule: { type: Object, default: null },
    next: { type: Object, default: null },
    contingency: { type: Object, default: null },
    // Jefaturas y oficina (permiso attendance.web_punch): marcan sin plan de contingencia
    webPunch: { type: Boolean, default: false },
    postUrl: { type: String, required: true },
    // Enlace a "Mi asistencia" del portal (en el panel no aplica)
    historyUrl: { type: String, default: null },
    // Encabezado: saludo y una línea debajo (empresa o cargo)
    title: { type: String, default: 'Hoy' },
    subtitle: { type: String, default: null },
});

// Reloj en vivo: la hora que vale es la del servidor al marcar; esto solo orienta
const now = ref(new Date());
let timer = null;
onMounted(() => { timer = setInterval(() => { now.value = new Date(); }, 1000); });
onBeforeUnmount(() => clearInterval(timer));
const pad = (n) => String(n).padStart(2, '0');
const clock = computed(() => {
    const h = now.value.getHours();
    return { h: pad(h % 12 || 12), m: pad(now.value.getMinutes()), ampm: h < 12 ? 'a. m.' : 'p. m.', tick: now.value.getSeconds() % 2 === 0 };
});
const dateLabel = computed(() => {
    const text = now.value.toLocaleDateString('es-PE', { weekday: 'long', day: 'numeric', month: 'long' });
    return text.charAt(0).toUpperCase() + text.slice(1);
});
const hhmm = (t) => (t ? t.slice(0, 5) : null);

// Las 4 marcas del día, en orden y con los mismos nombres que la app (horario partido: tramos)
const split = computed(() => !!props.schedule?.shift?.split || !!props.attendance?.is_split);
const slots = computed(() => [
    { key: 'clock_in', label: 'Entrada', action: 'Marcar entrada', icon: 'bi-box-arrow-in-right', time: hhmm(props.attendance?.clock_in?.time) },
    { key: 'break_start', label: split.value ? 'Fin 1.er tramo' : 'Inicio break', action: split.value ? 'Marcar fin del 1.er tramo' : 'Marcar inicio de break', icon: 'bi-cup-hot', time: hhmm(props.attendance?.break?.start_time) },
    { key: 'break_end', label: split.value ? 'Inicio 2.º tramo' : 'Fin break', action: split.value ? 'Marcar inicio del 2.º tramo' : 'Marcar fin de break', icon: 'bi-arrow-repeat', time: hhmm(props.attendance?.break?.end_time) },
    { key: 'clock_out', label: 'Salida', action: 'Marcar salida', icon: 'bi-box-arrow-right', time: hhmm(props.attendance?.clock_out?.time) },
]);

// Se marca con un plan de contingencia vigente o con el permiso de marcación web; solo la marca que toca
const canPunch = computed(() => !!props.contingency || props.webPunch);
const allowed = computed(() => {
    if (!canPunch.value || !props.next) return [];
    const actions = props.next.actions ? props.next.actions.map((a) => a.action) : [props.next.action];
    return actions.filter((a) => a && (a !== 'clock_in' || props.schedule?.can_clock_in !== false));
});
const primary = computed(() => slots.value.find((s) => allowed.value.includes(s.key)) || null);
const others = computed(() => slots.value.filter((s) => s.key !== primary.value?.key && allowed.value.includes(s.key)));
const marking = useForm({ type: '' });
function mark(type) {
    marking.type = type;
    marking.post(props.postUrl, { preserveScroll: true });
}
const until = computed(() => (props.contingency ? new Date(props.contingency.ends_at).toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit' }) : null));
const done = computed(() => slots.value.filter((s) => s.time).length);
const dayDone = computed(() => !!props.attendance?.clock_out?.time);
const shiftLabel = computed(() => (props.schedule?.shift
    ? `${props.schedule.shift.start_time} – ${props.schedule.shift.end_time} · ${props.schedule.shift.name}`
    : props.schedule?.unconfigured ? 'Sin horario configurado' : 'Sin turno hoy'));
</script>

<template>
    <div class="card punch-card">
        <header class="punch-hero">
            <div class="punch-hero-top">
                <div class="min-w-0">
                    <p class="punch-hero-title">{{ title }}</p>
                    <p v-if="subtitle" class="punch-hero-sub">{{ subtitle }}</p>
                </div>
                <span class="punch-hero-count" :class="{ 'is-complete': done === 4 || dayDone }">
                    <i class="bi" :class="done === 4 || dayDone ? 'bi-check2-circle' : 'bi-fingerprint'"></i>{{ done }}/4
                </span>
            </div>
            <div class="punch-time" role="timer" aria-label="Hora actual">
                <span class="punch-time-digits">{{ clock.h }}<span class="punch-time-colon" :class="{ 'is-dim': !clock.tick }">:</span>{{ clock.m }}</span>
                <span class="punch-time-ampm">{{ clock.ampm }}</span>
            </div>
            <p class="punch-hero-date">{{ dateLabel }}</p>
            <span class="punch-hero-shift"><i class="bi bi-clock"></i>{{ shiftLabel }}</span>
        </header>

        <div class="punch-body">
            <div v-if="contingency" class="punch-notice tone-amber">
                <i class="bi bi-life-preserver"></i>
                <span><strong>Plan de contingencia activo hasta las {{ until }}.</strong> {{ contingency.reason }}. La hora la pone el sistema.</span>
            </div>
            <div v-else-if="webPunch" class="punch-notice tone-blue">
                <i class="bi bi-shield-check"></i>
                <span>Por tu cargo puedes marcar desde aquí. La hora la pone el sistema y queda registrado que fue desde la web.</span>
            </div>
            <div v-if="schedule?.message" class="punch-notice tone-neutral">
                <i class="bi bi-info-circle"></i><span>{{ schedule.message }}</span>
            </div>

            <div v-if="primary" class="punch-primary">
                <button type="button" class="punch-cta" :disabled="marking.processing" @click="mark(primary.key)">
                    <span v-if="marking.processing" class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                    <i v-else class="bi" :class="primary.icon"></i>
                    <span>{{ marking.processing ? 'Registrando…' : primary.action }}</span>
                </button>
                <button v-for="o in others" :key="o.key" type="button" class="btn punch-alt" :disabled="marking.processing" @click="mark(o.key)">
                    {{ o.action }}
                </button>
            </div>
            <p v-else-if="dayDone" class="punch-finished"><i class="bi bi-check-circle-fill"></i>Jornada completa. ¡Buen trabajo!</p>

            <div class="punch-list-head">
                <span>Marcas de hoy</span>
                <span v-if="attendance?.status_label" class="status-pill tone-neutral">{{ attendance.status_label }}</span>
            </div>
            <ol class="punch-timeline">
                <li v-for="s in slots" :key="s.key" class="punch-step" :class="{ 'is-done': s.time, 'is-next': !s.time && primary?.key === s.key }">
                    <span class="punch-dot"><i class="bi" :class="s.time ? 'bi-check-lg' : s.icon"></i></span>
                    <span class="punch-step-label">{{ s.label }}</span>
                    <span class="punch-step-time">{{ s.time || '--:--' }}</span>
                </li>
            </ol>

            <p v-if="!canPunch" class="punch-foot">
                <i class="bi bi-phone"></i>
                <span>Marcas desde la app.<template v-if="historyUrl"> ¿Olvidaste una marca o llegaste tarde? Justifícalo en <Link :href="historyUrl">Mi asistencia</Link>.</template></span>
            </p>
        </div>
    </div>
</template>

<style>
.punch-card { overflow: hidden; }
.punch-hero { position: relative; overflow: hidden; padding: 1.4rem 1.5rem 1.5rem; color: #fff;
    background: linear-gradient(140deg, var(--brand) 0%, color-mix(in srgb, var(--brand) 72%, #0b1220) 100%); }
.punch-hero::after { content: ''; position: absolute; right: -60px; top: -80px; width: 260px; height: 260px; border-radius: 50%;
    background: radial-gradient(circle, rgba(255, 255, 255, .14) 0%, rgba(255, 255, 255, 0) 70%); pointer-events: none; }
.punch-hero-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; position: relative; z-index: 1; }
.punch-hero-title { margin: 0; font-size: 1.05rem; font-weight: 700; letter-spacing: -.01em; }
.punch-hero-sub { margin: .1rem 0 0; font-size: .82rem; opacity: .8; }
.punch-hero-count { display: inline-flex; align-items: center; gap: .35rem; padding: .3rem .7rem; border-radius: 999px; font-size: .8rem; font-weight: 700;
    background: rgba(255, 255, 255, .16); border: 1px solid rgba(255, 255, 255, .22); white-space: nowrap; }
.punch-hero-count.is-complete { background: rgba(16, 185, 129, .3); border-color: rgba(16, 185, 129, .5); }
.punch-time { display: flex; align-items: baseline; gap: .5rem; margin-top: 1.1rem; position: relative; z-index: 1; }
.punch-time-digits { font-size: 3.6rem; font-weight: 300; line-height: 1; letter-spacing: -.04em; font-variant-numeric: tabular-nums; }
.punch-time-colon { transition: opacity .4s ease; }
.punch-time-colon.is-dim { opacity: .35; }
.punch-time-ampm { font-size: 1rem; font-weight: 600; opacity: .85; letter-spacing: .02em; }
.punch-hero-date { margin: .5rem 0 .75rem; font-size: .92rem; opacity: .9; position: relative; z-index: 1; }
.punch-hero-shift { display: inline-flex; align-items: center; gap: .45rem; padding: .35rem .8rem; border-radius: 999px; font-size: .82rem; font-weight: 600;
    background: rgba(255, 255, 255, .14); position: relative; z-index: 1; }
.punch-body { padding: 1.25rem 1.5rem 1.5rem; }
.punch-notice { display: flex; gap: .6rem; align-items: flex-start; padding: .7rem .85rem; margin-bottom: .75rem; border-radius: var(--radius-md); font-size: .86rem; line-height: 1.45; }
.punch-notice i { margin-top: .1rem; }
.punch-notice.tone-amber { background: rgba(var(--bs-warning-rgb), .12); color: var(--text-primary); }
.punch-notice.tone-blue { background: rgba(var(--brand-rgb), .07); color: var(--text-primary); }
.punch-notice.tone-blue i { color: var(--brand); }
.punch-notice.tone-neutral { background: var(--bg-surface-elevated); color: var(--text-secondary); }
.punch-primary { display: flex; flex-direction: column; gap: .5rem; margin: .5rem 0 1.5rem; }
.punch-cta { display: inline-flex; align-items: center; justify-content: center; gap: .6rem; width: 100%; min-height: 56px; border: 0; border-radius: 14px;
    background: var(--brand); color: var(--brand-text, #fff); font-size: 1.05rem; font-weight: 700; letter-spacing: .01em;
    box-shadow: 0 10px 24px rgba(var(--brand-rgb), .3); transition: transform var(--dur-1) var(--ease-out), filter var(--dur-2) var(--ease-out); }
.punch-cta i { font-size: 1.3rem; }
.punch-cta:hover:not(:disabled) { filter: brightness(.96); }
.punch-cta:active:not(:disabled) { transform: scale(.985); }
.punch-cta:focus-visible { outline: none; box-shadow: var(--ring); }
.punch-cta:disabled { opacity: .75; }
.punch-alt { border: 1px solid var(--border-color); border-radius: 14px; min-height: 48px; font-weight: 600; color: var(--text-primary); }
.punch-finished { display: flex; align-items: center; gap: .5rem; margin: .5rem 0 1.5rem; padding: .85rem 1rem; border-radius: 14px;
    background: rgba(var(--bs-success-rgb), .08); color: var(--bs-success); font-weight: 600; }
.punch-list-head { display: flex; justify-content: space-between; align-items: center; gap: .5rem; margin-bottom: .6rem;
    font-size: .78rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--text-muted); }
.punch-timeline { list-style: none; padding: 0; margin: 0; display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: .6rem; }
.punch-step { display: flex; align-items: center; gap: .65rem; padding: .75rem .85rem; border-radius: 14px;
    border: 1px solid var(--border-color); background: var(--bg-surface); min-width: 0; }
.punch-dot { width: 34px; height: 34px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; font-size: 1rem; flex-shrink: 0;
    background: var(--bg-surface-elevated); color: var(--text-muted); }
.punch-step-label { font-size: .8rem; font-weight: 600; color: var(--text-secondary); line-height: 1.2; flex: 1; min-width: 0; }
.punch-step-time { font-size: 1.05rem; font-weight: 700; font-variant-numeric: tabular-nums; color: var(--text-muted); }
.punch-step.is-done .punch-dot { background: rgba(var(--bs-success-rgb), .14); color: var(--bs-success); }
.punch-step.is-done .punch-step-time { color: var(--text-primary); }
.punch-step.is-next { border-color: var(--brand); box-shadow: 0 0 0 3px rgba(var(--brand-rgb), .12); }
.punch-step.is-next .punch-dot { background: rgba(var(--brand-rgb), .12); color: var(--brand); }
.punch-foot { display: flex; gap: .5rem; align-items: flex-start; margin: 1rem 0 0; font-size: .84rem; color: var(--text-secondary); }
@media (min-width: 768px) {
    .punch-primary { flex-direction: row; flex-wrap: wrap; }
    .punch-cta { width: auto; min-width: 280px; padding: 0 2rem; }
}
@media (max-width: 991.98px) {
    .punch-timeline { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 767.98px) {
    .punch-hero { padding: 1.25rem 1.25rem 1.4rem; text-align: center; }
    .punch-hero-top { text-align: left; }
    .punch-time { justify-content: center; margin-top: 1.25rem; }
    .punch-time-digits { font-size: 4.25rem; }
    .punch-body { padding: 1.1rem 1rem 1.25rem; }
    .punch-timeline { grid-template-columns: 1fr; gap: .5rem; }
}
</style>
