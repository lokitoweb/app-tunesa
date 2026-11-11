<script setup>
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import PortalLayout from '@/Modules/HR/Layouts/PortalLayout.vue';
import AppModal from '@/Modules/Core/Components/AppModal.vue';

const props = defineProps({
    period: { type: Object, required: true },
    summary: { type: Object, required: true },
    days: { type: Array, default: () => [] },
});

const months = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'setiembre', 'octubre', 'noviembre', 'diciembre'];
const hhmm = (t) => (t ? t.slice(0, 5) : null);
const dateParts = (iso) => {
    const d = new Date(`${iso}T12:00:00`);
    return { day: d.getDate(), weekday: d.toLocaleDateString('es-PE', { weekday: 'short' }).replace('.', ''), label: d.toLocaleDateString('es-PE', { weekday: 'long', day: 'numeric', month: 'long' }) };
};
function shift(delta) {
    const d = new Date(props.period.year, props.period.month - 1 + delta, 1);
    router.get('/mi-espacio/asistencia', { year: d.getFullYear(), month: d.getMonth() + 1 }, { preserveScroll: true });
}

const stats = computed(() => [
    { label: 'Asistencias', value: props.summary.present, tone: 'tone-emerald' },
    { label: 'Tardanzas', value: props.summary.late, tone: 'tone-amber' },
    { label: 'Faltas', value: props.summary.absent, tone: 'tone-rose' },
    { label: 'Justificadas', value: props.summary.excused, tone: 'tone-blue' },
    { label: 'Horas', value: props.summary.total_hours, tone: 'tone-brand' },
]);

// Color del estado del día (mismos estados que la app y el panel)
const dayTone = (status) => ({
    present: 'tone-emerald', late: 'tone-amber', early_exit: 'tone-amber', incomplete: 'tone-amber', half_day: 'tone-amber',
    absent: 'tone-rose', excused: 'tone-blue', vacation: 'tone-blue', permission: 'tone-blue', medical_leave: 'tone-blue',
}[status] || 'tone-neutral');
// Solo los estados de una justificación enviada ("none" = no se envió: no se muestra nada)
const justification = {
    pending: { text: 'Justificación en revisión', tone: 'tone-amber', icon: 'bi-hourglass-split' },
    approved: { text: 'Justificación aprobada', tone: 'tone-emerald', icon: 'bi-check-circle' },
    rejected: { text: 'Justificación rechazada', tone: 'tone-rose', icon: 'bi-x-circle' },
};
// Se justifica lo que tiene una incidencia (falta, tardanza, salida antes o marca olvidada) y no está ya aprobado
const needsJustify = (d) => d.justification?.status !== 'approved' && d.status !== 'excused'
    && (!d.clock_in || !d.clock_out || (d.summary?.late_minutes || 0) > 0 || (d.early_exit_minutes || 0) > 0);

// Justificar en un diálogo
const openDay = ref(null);
const form = useForm({ notes: '', file: null });
const fileName = ref('');
function openJustify(day) {
    form.reset();
    form.clearErrors();
    fileName.value = '';
    openDay.value = day;
}
function pickFile(event) {
    form.file = event.target.files[0] || null;
    fileName.value = form.file?.name || '';
}
function justify(day) {
    form.post(`/mi-espacio/asistencia/${day.id}/justificacion`, {
        preserveScroll: true, forceFormData: true,
        onSuccess: () => { openDay.value = null; form.reset(); },
    });
}
</script>

<template>
    <PortalLayout title="Mi asistencia">
        <div class="att-head">
            <h1 class="portal-title mb-0">Mi asistencia</h1>
            <div class="att-month" role="group" aria-label="Mes">
                <button type="button" aria-label="Mes anterior" title="Mes anterior" @click="shift(-1)"><i class="bi bi-chevron-left"></i></button>
                <span class="text-capitalize">{{ months[period.month - 1] }} {{ period.year }}</span>
                <button type="button" aria-label="Mes siguiente" title="Mes siguiente" @click="shift(1)"><i class="bi bi-chevron-right"></i></button>
            </div>
        </div>

        <section class="card att-stats" aria-label="Resumen del mes">
            <div v-for="s in stats" :key="s.label" class="att-stat">
                <span class="att-stat-value">{{ s.value }}</span>
                <span class="att-stat-label"><span class="dash-dot" :class="s.tone"></span>{{ s.label }}</span>
            </div>
        </section>

        <ul v-if="days.length" class="att-days">
            <li v-for="d in days" :key="d.id" class="card att-day">
                <div class="att-date" :aria-label="dateParts(d.work_date).label">
                    <span class="att-date-num">{{ dateParts(d.work_date).day }}</span>
                    <span class="att-date-wd">{{ dateParts(d.work_date).weekday }}</span>
                </div>
                <div class="att-info">
                    <div class="att-badges">
                        <span class="status-pill" :class="dayTone(d.status)">{{ d.status_label }}</span>
                        <span v-if="justification[d.justification?.status]" class="status-pill" :class="justification[d.justification.status].tone">
                            <i class="bi" :class="justification[d.justification.status].icon"></i>{{ justification[d.justification.status].text }}
                        </span>
                    </div>
                    <dl class="att-times">
                        <div><dt>Entrada</dt><dd>{{ hhmm(d.clock_in?.time) || '—' }}</dd></div>
                        <div><dt>Refrigerio</dt><dd>{{ hhmm(d.break?.start_time) ? `${hhmm(d.break.start_time)}–${hhmm(d.break?.end_time) || '…'}` : '—' }}</dd></div>
                        <div><dt>Salida</dt><dd>{{ hhmm(d.clock_out?.time) || '—' }}</dd></div>
                    </dl>
                </div>
                <button v-if="needsJustify(d)" type="button" class="att-justify" @click="openJustify(d)">
                    <i class="bi bi-chat-square-text"></i>{{ d.justification?.status === 'pending' ? 'Editar' : 'Justificar' }}
                </button>
            </li>
        </ul>
        <div v-else class="card">
            <div class="dash-empty py-5">
                <span class="dash-empty-icon"><i class="bi bi-calendar2"></i></span>
                <p class="dash-empty-title">No hay jornadas registradas este mes</p>
            </div>
        </div>

        <AppModal :show="!!openDay" title="Justificar" :subtitle="openDay ? `${dateParts(openDay.work_date).label} · ${openDay.status_label || ''}` : ''" :busy="form.processing" @close="openDay = null">
            <form v-if="openDay" id="justify-form" class="att-form" @submit.prevent="justify(openDay)">
                <div>
                    <label class="form-label" :for="`j-${openDay.id}`">¿Qué pasó?</label>
                    <textarea :id="`j-${openDay.id}`" v-model="form.notes" class="form-control" :class="{ 'is-invalid': form.errors.notes }" rows="4" maxlength="1000" required
                        placeholder="Por ejemplo: olvidé marcar la salida, salí a las 18:05"></textarea>
                    <div class="d-flex justify-content-between">
                        <div class="invalid-feedback d-block">{{ form.errors.notes }}</div>
                        <small class="text-body-secondary">{{ form.notes.length }}/1000</small>
                    </div>
                </div>
                <div>
                    <span class="form-label d-block">Sustento <span class="fw-normal text-body-secondary">(opcional: PDF o foto)</span></span>
                    <label class="att-file" :class="{ 'has-file': fileName }">
                        <input type="file" accept=".pdf,.jpg,.jpeg,.png" class="visually-hidden" @change="pickFile" />
                        <i class="bi" :class="fileName ? 'bi-file-earmark-check' : 'bi-paperclip'"></i>
                        <span class="text-truncate">{{ fileName || 'Adjuntar archivo' }}</span>
                    </label>
                    <div v-if="form.errors.file" class="invalid-feedback d-block">{{ form.errors.file }}</div>
                </div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-light border" @click="openDay = null">Cancelar</button>
                <button type="submit" form="justify-form" class="btn btn-primary" :disabled="form.processing">Enviar a revisión</button>
            </template>
        </AppModal>
    </PortalLayout>
</template>

<style>
.att-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .75rem; margin: .5rem 0 1rem; }
.att-month { display: inline-flex; align-items: center; gap: .25rem; padding: .25rem; border: 1px solid var(--border-color); border-radius: 999px; background: var(--bg-surface); }
.att-month span { font-size: .88rem; font-weight: 700; padding: 0 .5rem; min-width: 118px; text-align: center; }
.att-month button { width: 34px; height: 34px; border: 0; border-radius: 50%; background: transparent; color: var(--text-secondary); }
.att-month button:hover { background: var(--bg-surface-elevated); color: var(--brand); }
.att-stats { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); margin-bottom: 1rem; padding: .25rem 0; }
.att-stat { display: flex; flex-direction: column; align-items: center; gap: .2rem; padding: .85rem .25rem; text-align: center; }
.att-stat + .att-stat { border-left: 1px solid var(--border-color); }
.att-stat-value { font-size: 1.5rem; font-weight: 700; line-height: 1.1; font-variant-numeric: tabular-nums; color: var(--text-primary); }
.att-stat-label { display: inline-flex; align-items: center; gap: .3rem; font-size: .75rem; font-weight: 600; color: var(--text-secondary); }
.att-days { list-style: none; padding: 0; margin: 0; display: grid; gap: .6rem; }
.att-day { display: grid; grid-template-columns: 52px minmax(0, 1fr) auto; align-items: center; gap: 1rem; padding: .85rem 1rem; }
.att-date { display: flex; flex-direction: column; align-items: center; justify-content: center; width: 52px; height: 56px; flex-shrink: 0; border-radius: 12px;
    background: rgba(var(--brand-rgb), .08); color: var(--brand); }
.att-date-num { font-size: 1.35rem; font-weight: 700; line-height: 1; }
.att-date-wd { font-size: .7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; margin-top: .15rem; }
.att-info { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: .45rem; }
.att-badges { display: flex; flex-wrap: wrap; gap: .35rem; }
.att-times { display: flex; flex-wrap: wrap; gap: .25rem 1.25rem; margin: 0; }
.att-times div { display: flex; gap: .35rem; align-items: baseline; }
.att-times dt { font-size: .75rem; font-weight: 600; color: var(--text-muted); }
.att-times dd { margin: 0; white-space: nowrap; font-size: .9rem; font-weight: 600; font-variant-numeric: tabular-nums; color: var(--text-primary); }
.att-justify { display: inline-flex; align-items: center; gap: .4rem; flex-shrink: 0; padding: .5rem .85rem; border-radius: 10px; font-size: .85rem; font-weight: 600;
    border: 1px solid rgba(var(--brand-rgb), .35); background: rgba(var(--brand-rgb), .06); color: var(--brand); transition: background-color var(--dur-2) var(--ease-out); }
.att-justify:hover { background: rgba(var(--brand-rgb), .12); }
.att-justify:focus-visible { outline: none; box-shadow: var(--ring); }
.att-form { display: flex; flex-direction: column; gap: 1.1rem; }
.att-form textarea.form-control { min-height: 120px; border-radius: 10px; padding: .75rem .85rem; line-height: 1.5; resize: vertical; }
.att-file { display: flex; align-items: center; gap: .6rem; min-height: 48px; padding: .6rem .9rem; border: 1px dashed var(--border-color); border-radius: 10px;
    color: var(--text-secondary); font-weight: 600; cursor: pointer; transition: border-color var(--dur-2) var(--ease-out), background-color var(--dur-2) var(--ease-out); }
.att-file:hover { border-color: var(--brand); color: var(--brand); }
.att-file.has-file { border-style: solid; border-color: rgba(var(--bs-success-rgb), .5); color: var(--bs-success); background: rgba(var(--bs-success-rgb), .05); }
.att-file i { font-size: 1.15rem; }
@media (max-width: 575.98px) {
    .att-stat-value { font-size: 1.2rem; }
    .att-stat-label { font-size: .66rem; flex-direction: column; gap: .15rem; }
    .att-day { grid-template-columns: 52px minmax(0, 1fr); gap: .75rem; padding: .85rem; align-items: start; }
    .att-times { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .5rem; }
    .att-times div { flex-direction: column; gap: .1rem; }
    .att-times dd { font-size: .84rem; }
    .att-justify { grid-column: 1 / -1; width: 100%; justify-content: center; min-height: 44px; }
}
</style>
