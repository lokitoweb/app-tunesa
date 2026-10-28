<script setup>
import { computed } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Modules/Core/Layouts/AppLayout.vue';

const props = defineProps({
    items: { type: Array, default: () => [] },
    counts: { type: Object, default: () => ({}) },
    kinds: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    settings: { type: Object, default: null },
});

const fmt = (iso) => iso.split('-').reverse().join('/');
const when = (d) => (d < 0 ? `Venció hace ${-d} día${d === -1 ? '' : 's'}` : d === 0 ? 'Vence hoy' : d === 1 ? 'Vence mañana' : `En ${d} días`);
const tone = (d) => (d < 0 ? 'danger' : d <= 7 ? 'warning' : 'secondary');
const icons = { document: 'bi-file-earmark-medical', contract: 'bi-file-earmark-text', training: 'bi-mortarboard', vehicle: 'bi-bus-front' };

function go(params) {
    router.get('/expirations', { days: props.filters.days, kind: props.filters.kind || undefined, ...params }, { preserveState: true, replace: true, preserveScroll: true });
}

const form = useForm({ emails: props.settings?.emails || '', chats: props.settings?.chats || '', days: props.settings?.days || '30,15,7,1,0' });
const save = () => form.put('/expirations/settings', { preserveScroll: true });
const testing = useForm({});
const test = () => testing.post('/expirations/test', { preserveScroll: true });
const hasRecipients = computed(() => !!(props.settings?.emails || props.settings?.chats));
</script>

<template>
    <AppLayout title="Vencimientos">
        <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-3">
            <div>
                <h1 class="h4 fw-bold mb-1">Vencimientos</h1>
                <p class="text-body-secondary small mb-0">Documentos del legajo, fin de contratos, capacitaciones y vehículos que vencen o ya vencieron.</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <select class="form-select form-select-sm w-auto" :value="filters.kind || ''" aria-label="Tipo" @change="go({ kind: $event.target.value || undefined })">
                    <option value="">Todo</option>
                    <option v-for="(label, key) in kinds" :key="key" :value="key">{{ label }}</option>
                </select>
                <select class="form-select form-select-sm w-auto" :value="filters.days" aria-label="Rango" @change="go({ days: $event.target.value })">
                    <option v-for="d in [30, 60, 90, 180]" :key="d" :value="d">Próximos {{ d }} días</option>
                </select>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-4"><div class="card shadow-sm h-100"><div class="card-body py-3"><div class="h4 fw-bold text-danger mb-0">{{ counts.expired || 0 }}</div><div class="small text-body-secondary">Vencidos</div></div></div></div>
            <div class="col-4"><div class="card shadow-sm h-100"><div class="card-body py-3"><div class="h4 fw-bold text-warning-emphasis mb-0">{{ counts.week || 0 }}</div><div class="small text-body-secondary">Esta semana</div></div></div></div>
            <div class="col-4"><div class="card shadow-sm h-100"><div class="card-body py-3"><div class="h4 fw-bold mb-0">{{ counts.month || 0 }}</div><div class="small text-body-secondary">En 8 a 30 días</div></div></div></div>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Qué</th><th>De quién</th><th>Fecha</th><th>Estado</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="(i, n) in items" :key="n">
                            <td><i class="bi me-2 text-body-secondary" :class="icons[i.kind]" aria-hidden="true"></i><span class="fw-semibold">{{ i.title }}</span><div class="small text-body-secondary">{{ i.kind_label }}</div></td>
                            <td>{{ i.who || '—' }}</td>
                            <td class="text-nowrap">{{ fmt(i.date) }}</td>
                            <td><span class="badge" :class="`bg-${tone(i.days_left)}-subtle text-${tone(i.days_left)}-emphasis`">{{ when(i.days_left) }}</span></td>
                            <td class="text-end"><a v-if="i.url" :href="i.url" class="btn btn-sm btn-outline-secondary" :aria-label="`Abrir ${i.title}`">Abrir</a></td>
                        </tr>
                        <tr v-if="!items.length"><td colspan="5" class="text-center text-body-secondary py-5"><i class="bi bi-check2-circle fs-2 d-block mb-2"></i>Nada vence en este rango.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="settings" class="card shadow-sm">
            <div class="card-body">
                <h2 class="h6 fw-bold mb-1"><i class="bi bi-bell me-1"></i>Alertas por correo y Telegram</h2>
                <p class="small text-body-secondary">Cada mañana se avisa lo que vence justo en los días que indiques (no todos los días). Puedes poner varios destinatarios separados por coma.</p>
                <form class="row g-3" novalidate @submit.prevent="save">
                    <div class="col-12 col-lg-6">
                        <label class="form-label small fw-semibold" for="exp-emails">Correos</label>
                        <input id="exp-emails" v-model.trim="form.emails" class="form-control" :class="{ 'is-invalid': form.errors.emails }" placeholder="rrhh@empresa.com, gerencia@empresa.com" />
                        <div class="invalid-feedback">{{ form.errors.emails }}</div>
                    </div>
                    <div class="col-12 col-lg-6">
                        <label class="form-label small fw-semibold" for="exp-chats">Chats de Telegram</label>
                        <input id="exp-chats" v-model.trim="form.chats" class="form-control font-monospace" :class="{ 'is-invalid': form.errors.chats }" placeholder="123456789, -1001234567890" />
                        <div class="invalid-feedback">{{ form.errors.chats }}</div>
                        <div class="form-text">
                            <template v-if="settings.telegram_ready">Cada persona o grupo escribe /start al bot de la empresa y su número de chat se pone aquí.</template>
                            <span v-else class="text-warning-emphasis">Falta el token del bot de Telegram (Integraciones). Hasta entonces se avisa solo por correo.</span>
                        </div>
                    </div>
                    <div class="col-12 col-sm-6 col-lg-3">
                        <label class="form-label small fw-semibold" for="exp-days">Avisar a los días</label>
                        <input id="exp-days" v-model.trim="form.days" class="form-control" :class="{ 'is-invalid': form.errors.days }" placeholder="30,15,7,1,0" />
                        <div class="invalid-feedback">{{ form.errors.days }}</div>
                        <div class="form-text">0 = el mismo día.</div>
                    </div>
                    <div class="col-12 d-flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-primary" :disabled="form.processing">Guardar</button>
                        <button type="button" class="btn btn-outline-secondary" :disabled="testing.processing || !hasRecipients" :title="hasRecipients ? '' : 'Guarda al menos un destinatario'" @click="test">
                            <i class="bi bi-send me-1"></i>Enviar prueba
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
