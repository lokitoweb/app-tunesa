<script setup>
import { computed } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';
import { useConfirm } from '@/Modules/Core/Composables/useConfirm';

const props = defineProps({
    plans: { type: Array, default: () => [] },
    report: { type: Object, default: null },
    options: { type: Object, required: true },
});

const { ask } = useConfirm();
const form = useForm({ scope: 'branch', scope_ids: [], reason: '', hours: 8 });
const targets = computed(() => ({ branch: props.options.branches, department: props.options.departments, users: props.options.people }[form.scope] || []));
const labels = { clock_in: 'Entrada', break_start: 'Inicio refrigerio', break_end: 'Fin refrigerio', clock_out: 'Salida' };
const active = computed(() => props.plans.filter((p) => p.active));

async function activate() {
    const ok = await ask({
        title: '¿Activar el plan de contingencia?',
        message: `Las personas cubiertas podrán marcar desde Mi espacio, con la hora del servidor y sin GPS, durante ${form.hours} h. Queda registrado en la bitácora.`,
        confirmText: 'Sí, activar', variant: 'danger', icon: 'bi bi-life-preserver',
    });
    if (ok) form.post('/attendance-contingency', { preserveScroll: true, onSuccess: () => form.reset() });
}
async function end(plan) {
    const ok = await ask({ title: '¿Desactivar el plan?', message: 'Desde ahora se marca solo con la app.', confirmText: 'Sí, desactivar', icon: 'bi bi-stop-circle' });
    if (ok) router.post(`/attendance-contingency/${plan.id}/end`, {}, { preserveScroll: true });
}
const show = (id) => router.get('/attendance-contingency', { plan: id }, { preserveScroll: true, preserveState: true });
</script>

<template>
    <AppLayout title="Plan de contingencia">
        <div class="mb-3">
            <h1 class="h4 fw-bold mb-1">Plan de contingencia</h1>
            <p class="text-body-secondary small mb-0">
                Si la app no funciona (caída, sin señal, falla del sistema), activa el plan: las personas cubiertas marcan sus 4 marcas desde
                Mi espacio, con la hora del servidor y sin GPS. Se apaga solo al vencer; cada marca queda etiquetada como contingencia.
            </p>
        </div>

        <div v-if="active.length" class="alert alert-warning d-flex flex-wrap align-items-center justify-content-between gap-2">
            <span><i class="bi bi-life-preserver me-1"></i>{{ active.length }} plan(es) activo(s).</span>
        </div>

        <div class="row g-3">
            <div class="col-12 col-lg-4">
                <form class="card shadow-sm" @submit.prevent="activate">
                    <div class="card-body">
                        <h2 class="h6 fw-bold">Activar</h2>
                        <label class="form-label small fw-semibold" for="c-scope">Para</label>
                        <select id="c-scope" v-model="form.scope" class="form-select form-select-sm mb-2" @change="form.scope_ids = []">
                            <option v-for="(label, key) in options.scopes" :key="key" :value="key">{{ label }}</option>
                        </select>
                        <template v-if="form.scope !== 'all'">
                            <label class="form-label small fw-semibold" for="c-targets">¿Cuáles?</label>
                            <select id="c-targets" v-model="form.scope_ids" multiple size="6" class="form-select form-select-sm mb-1" :class="{ 'is-invalid': form.errors.scope_ids }">
                                <option v-for="t in targets" :key="t.id" :value="t.id">{{ t.name }}</option>
                            </select>
                            <div class="invalid-feedback">{{ form.errors.scope_ids }}</div>
                            <div class="form-text small mb-2">Ctrl o Cmd + clic para elegir varios.</div>
                        </template>
                        <label class="form-label small fw-semibold" for="c-reason">Motivo</label>
                        <input id="c-reason" v-model="form.reason" class="form-control form-control-sm mb-2" :class="{ 'is-invalid': form.errors.reason }" maxlength="200" placeholder="Caída de la app en la sede" required />
                        <div class="invalid-feedback">{{ form.errors.reason }}</div>
                        <label class="form-label small fw-semibold" for="c-hours">Durante</label>
                        <div class="input-group input-group-sm mb-3">
                            <input id="c-hours" v-model="form.hours" type="number" min="1" :max="options.max_hours" class="form-control" :class="{ 'is-invalid': form.errors.hours }" required />
                            <span class="input-group-text">horas (máx. {{ options.max_hours }})</span>
                        </div>
                        <button type="submit" class="btn btn-sm btn-warning w-100" :disabled="form.processing"><i class="bi bi-life-preserver me-1"></i>Activar plan</button>
                    </div>
                </form>
            </div>

            <div class="col-12 col-lg-8">
                <div class="card shadow-sm mb-3">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle small mb-0">
                            <thead class="table-light"><tr><th>Para</th><th>Motivo</th><th>Vigencia</th><th>Estado</th><th></th></tr></thead>
                            <tbody>
                                <tr v-for="p in plans" :key="p.id" :class="{ 'table-active': report?.id === p.id }" role="button" @click="show(p.id)">
                                    <td>{{ p.scope }}<div v-if="p.targets" class="text-body-secondary">{{ p.targets }}</div></td>
                                    <td>{{ p.reason }}<div class="text-body-secondary">por {{ p.by || '—' }}</div></td>
                                    <td class="text-nowrap">{{ p.starts_at }}<br>hasta {{ p.ended_at || p.ends_at }}</td>
                                    <td>
                                        <span v-if="p.active" class="badge bg-warning-subtle text-warning-emphasis">Activo</span>
                                        <span v-else-if="p.ended_at" class="badge bg-secondary-subtle text-secondary">Desactivado<span v-if="p.ended_by"> por {{ p.ended_by }}</span></span>
                                        <span v-else class="badge bg-secondary-subtle text-secondary">Vencido</span>
                                    </td>
                                    <td class="text-end"><button v-if="p.active" type="button" class="btn btn-sm btn-outline-secondary" @click.stop="end(p)">Desactivar</button></td>
                                </tr>
                                <tr v-if="!plans.length"><td colspan="5" class="text-center text-body-secondary py-4">Aún no se ha activado ningún plan.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div v-if="report" class="card shadow-sm">
                    <div class="card-body">
                        <h2 class="h6 fw-bold mb-2">Cierre del plan</h2>
                        <p class="small mb-2">
                            {{ report.marked }} de {{ report.covered }} persona(s) marcaron con el plan ·
                            <a href="/approvals?tab=justifications">{{ report.pending_justifications }} justificación(es) por revisar</a>
                        </p>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle small mb-0">
                                <thead class="table-light"><tr><th>Persona</th><th>Marcas de contingencia</th></tr></thead>
                                <tbody>
                                    <tr v-for="person in report.people" :key="person.document + person.name">
                                        <td>{{ person.name }}<div class="text-body-secondary">{{ person.document }}</div></td>
                                        <td>
                                            <span v-for="(m, i) in person.punches" :key="i" class="badge bg-body-secondary text-body me-1">{{ labels[m.type] }} {{ m.time }}</span>
                                            <span v-if="!person.punches.length" class="text-warning-emphasis">No marcó</span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
