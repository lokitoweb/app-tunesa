<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import PortalLayout from '@/Modules/HR/Layouts/PortalLayout.vue';
import AppModal from '@/Modules/Core/Components/AppModal.vue';

const props = defineProps({
    period: { type: Object, required: true },
    summary: { type: Object, required: true },
    days: { type: Array, default: () => [] },
});

const months = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'setiembre', 'octubre', 'noviembre', 'diciembre'];
const hhmm = (t) => (t ? t.slice(0, 5) : '—');
const fmtDay = (iso) => new Date(`${iso}T12:00:00`).toLocaleDateString('es-PE', { weekday: 'short', day: '2-digit', month: '2-digit' });
function shift(delta) {
    const d = new Date(props.period.year, props.period.month - 1 + delta, 1);
    router.get('/mi-espacio/asistencia', { year: d.getFullYear(), month: d.getMonth() + 1 }, { preserveScroll: true });
}

// Justificar en un diálogo (antes se abría dentro de la tabla)
const openDay = ref(null);
const form = useForm({ notes: '', file: null });
function openJustify(day) {
    form.reset();
    form.clearErrors();
    openDay.value = day;
}
function justify(day) {
    form.post(`/mi-espacio/asistencia/${day.id}/justificacion`, {
        preserveScroll: true, forceFormData: true,
        onSuccess: () => { openDay.value = null; form.reset(); },
    });
}
const statusTone = { pending: 'tone-amber', approved: 'tone-emerald', rejected: 'tone-rose' };
const statusText = { pending: 'Justificación en revisión', approved: 'Justificación aprobada', rejected: 'Justificación rechazada' };
</script>

<template>
    <PortalLayout title="Mi asistencia">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
            <h1 class="portal-title">Mi asistencia</h1>
            <div class="d-flex align-items-center gap-1 mb-3" role="group" aria-label="Mes">
                <button type="button" class="icon-btn border" aria-label="Mes anterior" title="Mes anterior" @click="shift(-1)"><i class="bi bi-chevron-left"></i></button>
                <span class="small fw-bold px-2 text-capitalize">{{ months[period.month - 1] }} {{ period.year }}</span>
                <button type="button" class="icon-btn border" aria-label="Mes siguiente" title="Mes siguiente" @click="shift(1)"><i class="bi bi-chevron-right"></i></button>
            </div>
        </div>

        <section class="portal-stats mb-3" aria-label="Resumen del mes">
            <div v-for="[label, value, tone] in [['Asistencias', summary.present, 'tone-emerald'], ['Tardanzas', summary.late, 'tone-amber'], ['Faltas', summary.absent, 'tone-rose'], ['Justificadas', summary.excused, 'tone-blue'], ['Horas', summary.total_hours, 'tone-brand']]" :key="label" class="portal-stat">
                <span class="dash-dot" :class="tone"></span>
                <span class="portal-stat-label">{{ label }}</span>
                <span class="portal-stat-value">{{ value }}</span>
            </div>
        </section>

        <div class="card">
            <div class="table-responsive">
                <table class="table data-table table-cards align-middle mb-0">
                    <thead><tr><th>Día</th><th>Entrada</th><th>Refrigerio</th><th>Salida</th><th>Estado</th><th><span class="visually-hidden">Justificar</span></th></tr></thead>
                    <tbody>
                        <tr v-for="d in days" :key="d.id">
                            <td data-label="Día" class="text-capitalize"><span class="cell-title">{{ fmtDay(d.work_date) }}</span></td>
                            <td data-label="Entrada" class="tabular">{{ hhmm(d.clock_in?.time) }}</td>
                            <td data-label="Refrigerio" class="tabular">{{ hhmm(d.break?.start_time) }} – {{ hhmm(d.break?.end_time) }}</td>
                            <td data-label="Salida" class="tabular">{{ hhmm(d.clock_out?.time) }}</td>
                            <td data-label="Estado">
                                <span class="small">{{ d.status_label }}</span>
                                <span v-if="d.justification?.status" class="status-pill ms-1" :class="statusTone[d.justification.status]">{{ statusText[d.justification.status] }}</span>
                            </td>
                            <td class="cell-actions">
                                <div class="row-actions">
                                    <button v-if="d.justification?.status !== 'approved'" type="button" class="btn btn-sm btn-light border" @click="openJustify(d)">Justificar</button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!days.length" class="row-empty">
                            <td colspan="6">
                                <div class="dash-empty">
                                    <span class="dash-empty-icon"><i class="bi bi-calendar2"></i></span>
                                    <p class="dash-empty-title">No hay jornadas registradas este mes</p>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <AppModal :show="!!openDay" title="Justificar" :subtitle="openDay ? `${fmtDay(openDay.work_date)} · ${openDay.status_label || ''}` : ''" :busy="form.processing" @close="openDay = null">
            <form v-if="openDay" id="justify-form" class="d-flex flex-column gap-3" @submit.prevent="justify(openDay)">
                <div>
                    <label class="form-label" :for="`j-${openDay.id}`">¿Qué pasó?</label>
                    <textarea :id="`j-${openDay.id}`" v-model="form.notes" class="form-control" :class="{ 'is-invalid': form.errors.notes }" rows="3" maxlength="1000" required
                        placeholder="Por ejemplo: olvidé marcar la salida, salí a las 18:05"></textarea>
                    <div class="invalid-feedback">{{ form.errors.notes }}</div>
                </div>
                <div>
                    <label class="form-label" for="j-file">Sustento <span class="fw-normal text-body-secondary">(opcional: PDF o foto)</span></label>
                    <input id="j-file" type="file" class="form-control" accept=".pdf,.jpg,.jpeg,.png" @input="form.file = $event.target.files[0]" />
                </div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-light border" @click="openDay = null">Cancelar</button>
                <button type="submit" form="justify-form" class="btn btn-primary" :disabled="form.processing">Enviar a revisión</button>
            </template>
        </AppModal>
    </PortalLayout>
</template>
