<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import PortalLayout from '@/Modules/HR/Layouts/PortalLayout.vue';

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

const open = ref(null);
const form = useForm({ notes: '', file: null });
function justify(day) {
    form.post(`/mi-espacio/asistencia/${day.id}/justificacion`, {
        preserveScroll: true, forceFormData: true,
        onSuccess: () => { open.value = null; form.reset(); },
    });
}
const statusTone = { pending: 'warning', approved: 'success', rejected: 'danger' };
const statusText = { pending: 'Justificación en revisión', approved: 'Justificación aprobada', rejected: 'Justificación rechazada' };
</script>

<template>
    <PortalLayout title="Mi asistencia">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-2 mb-3">
            <h1 class="portal-title mb-0">Mi asistencia</h1>
            <div class="btn-group btn-group-sm" role="group" aria-label="Mes">
                <button type="button" class="btn btn-outline-secondary" aria-label="Mes anterior" @click="shift(-1)"><i class="bi bi-chevron-left"></i></button>
                <span class="btn btn-outline-secondary disabled text-capitalize">{{ months[period.month - 1] }} {{ period.year }}</span>
                <button type="button" class="btn btn-outline-secondary" aria-label="Mes siguiente" @click="shift(1)"><i class="bi bi-chevron-right"></i></button>
            </div>
        </div>

        <div class="row g-2 mb-3">
            <div v-for="[label, value] in [['Asistencias', summary.present], ['Tardanzas', summary.late], ['Faltas', summary.absent], ['Justificadas', summary.excused], ['Horas', summary.total_hours]]" :key="label" class="col-4 col-md">
                <div class="card shadow-sm"><div class="card-body py-2 text-center"><div class="small text-body-secondary">{{ label }}</div><div class="fs-5 fw-semibold">{{ value }}</div></div></div>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover align-middle small mb-0">
                    <thead class="table-light"><tr><th>Día</th><th>Entrada</th><th>Refrigerio</th><th>Salida</th><th>Estado</th><th><span class="visually-hidden">Justificar</span></th></tr></thead>
                    <tbody>
                        <template v-for="d in days" :key="d.id">
                            <tr>
                                <td class="text-capitalize">{{ fmtDay(d.work_date) }}</td>
                                <td>{{ hhmm(d.clock_in?.time) }}</td>
                                <td>{{ hhmm(d.break?.start_time) }} – {{ hhmm(d.break?.end_time) }}</td>
                                <td>{{ hhmm(d.clock_out?.time) }}</td>
                                <td>
                                    {{ d.status_label }}
                                    <span v-if="d.justification?.status" class="badge ms-1" :class="`bg-${statusTone[d.justification.status]}-subtle text-${statusTone[d.justification.status]}-emphasis`">{{ statusText[d.justification.status] }}</span>
                                </td>
                                <td class="text-end">
                                    <button v-if="d.justification?.status !== 'approved'" type="button" class="btn btn-sm btn-light" @click="open = open === d.id ? null : d.id">Justificar</button>
                                </td>
                            </tr>
                            <tr v-if="open === d.id">
                                <td colspan="6">
                                    <form class="d-flex flex-column gap-2" @submit.prevent="justify(d)">
                                        <label class="form-label small fw-semibold mb-0" :for="`j-${d.id}`">¿Qué pasó? (por ejemplo: «olvidé marcar la salida, salí a las 18:05»)</label>
                                        <textarea :id="`j-${d.id}`" v-model="form.notes" class="form-control form-control-sm" :class="{ 'is-invalid': form.errors.notes }" rows="2" maxlength="1000" required></textarea>
                                        <div class="invalid-feedback">{{ form.errors.notes }}</div>
                                        <input type="file" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png" aria-label="Sustento (opcional)" @input="form.file = $event.target.files[0]" />
                                        <div class="d-flex gap-2 justify-content-end">
                                            <button type="button" class="btn btn-sm btn-light" @click="open = null">Cancelar</button>
                                            <button type="submit" class="btn btn-sm btn-primary" :disabled="form.processing">Enviar a revisión</button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="!days.length"><td colspan="6" class="text-center text-body-secondary py-4">No hay jornadas registradas este mes.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </PortalLayout>
</template>
