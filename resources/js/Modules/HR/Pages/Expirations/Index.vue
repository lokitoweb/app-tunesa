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
const tone = (d) => (d < 0 ? 'tone-rose' : d <= 7 ? 'tone-amber' : 'tone-neutral');
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
        <header class="page-head">
            <div class="page-head-text">
                <h1 class="page-title">Vencimientos</h1>
                <p class="page-subtitle">Documentos del legajo, fin de contratos, capacitaciones y vehículos que vencen o ya vencieron.</p>
            </div>
        </header>

        <section class="dash-kpis dash-kpis-3" aria-label="Resumen de vencimientos">
            <article class="dash-kpi-card">
                <div class="dash-kpi-top"><span class="dash-kpi-label">Vencidos</span><span class="dash-kpi-icon tone-rose"><i class="bi bi-exclamation-octagon"></i></span></div>
                <div class="dash-kpi-value">{{ counts.expired || 0 }}</div>
            </article>
            <article class="dash-kpi-card">
                <div class="dash-kpi-top"><span class="dash-kpi-label">Esta semana</span><span class="dash-kpi-icon tone-amber"><i class="bi bi-calendar-week"></i></span></div>
                <div class="dash-kpi-value">{{ counts.week || 0 }}</div>
            </article>
            <article class="dash-kpi-card">
                <div class="dash-kpi-top"><span class="dash-kpi-label">En 8 a 30 días</span><span class="dash-kpi-icon tone-brand"><i class="bi bi-calendar3"></i></span></div>
                <div class="dash-kpi-value">{{ counts.month || 0 }}</div>
            </article>
        </section>

        <section class="surface mb-4">
            <div class="filter-row">
                <div class="seg-tabs" role="tablist" aria-label="Tipo">
                    <button type="button" role="tab" class="seg-tab" :class="{ 'is-active': !filters.kind }" :aria-selected="!filters.kind" @click="go({ kind: undefined })">Todo</button>
                    <button v-for="(label, key) in kinds" :key="key" type="button" role="tab" class="seg-tab" :class="{ 'is-active': filters.kind === key }" :aria-selected="filters.kind === key" @click="go({ kind: key })">
                        <i class="bi" :class="icons[key]" aria-hidden="true"></i>{{ label }}
                    </button>
                </div>
                <select class="form-select w-auto ms-auto" :value="filters.days" aria-label="Rango" @change="go({ days: $event.target.value })">
                    <option v-for="d in [30, 60, 90, 180]" :key="d" :value="d">Próximos {{ d }} días</option>
                </select>
            </div>
            <div class="table-responsive">
                <table class="table data-table table-cards align-middle mb-0">
                    <thead><tr><th>Qué</th><th>De quién</th><th>Fecha</th><th>Estado</th><th><span class="visually-hidden">Abrir</span></th></tr></thead>
                    <tbody>
                        <tr v-for="(i, n) in items" :key="n" :class="{ 'is-overdue': i.days_left < 0 }">
                            <td data-label="Qué" class="cell-main">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="list-plain-icon"><i class="bi" :class="icons[i.kind]" aria-hidden="true"></i></span>
                                    <div class="min-w-0"><div class="cell-title text-truncate">{{ i.title }}</div><div class="cell-sub">{{ i.kind_label }}</div></div>
                                </div>
                            </td>
                            <td data-label="De quién">{{ i.who || '—' }}</td>
                            <td data-label="Fecha" class="text-nowrap tabular">{{ fmt(i.date) }}</td>
                            <td data-label="Estado"><span class="status-pill" :class="tone(i.days_left)">{{ when(i.days_left) }}</span></td>
                            <td class="cell-actions"><div class="row-actions"><a v-if="i.url" :href="i.url" class="btn btn-sm btn-light border" :aria-label="`Abrir ${i.title}`">Abrir</a></div></td>
                        </tr>
                        <tr v-if="!items.length" class="row-empty">
                            <td colspan="5">
                                <div class="dash-empty">
                                    <span class="dash-empty-icon"><i class="bi bi-check2-circle"></i></span>
                                    <p class="dash-empty-title">Nada vence en este rango</p>
                                    <p class="dash-empty-text">Prueba con un rango más amplio o con otro tipo.</p>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section v-if="settings" class="surface">
            <header class="surface-head">
                <div>
                    <h2 class="surface-title"><i class="bi bi-bell me-1"></i>Alertas por correo y Telegram</h2>
                    <p class="surface-sub">Cada mañana se avisa lo que vence justo en los días que indiques (no todos los días). Puedes poner varios destinatarios separados por coma.</p>
                </div>
            </header>
            <form class="surface-pad row g-3 m-0" novalidate @submit.prevent="save">
                <div class="col-12 col-lg-6">
                    <label class="form-label" for="exp-emails">Correos</label>
                    <input id="exp-emails" v-model.trim="form.emails" class="form-control" :class="{ 'is-invalid': form.errors.emails }" placeholder="rrhh@empresa.com, gerencia@empresa.com" />
                    <div class="invalid-feedback">{{ form.errors.emails }}</div>
                </div>
                <div class="col-12 col-lg-6">
                    <label class="form-label" for="exp-chats">Chats de Telegram</label>
                    <input id="exp-chats" v-model.trim="form.chats" class="form-control font-monospace" :class="{ 'is-invalid': form.errors.chats }" placeholder="123456789, -1001234567890" />
                    <div class="invalid-feedback">{{ form.errors.chats }}</div>
                    <div class="form-text">
                        <template v-if="settings.telegram_ready">Cada persona o grupo escribe /start al bot de la empresa y su número de chat se pone aquí.</template>
                        <span v-else class="text-warning-emphasis">Falta el token del bot de Telegram (Integraciones). Hasta entonces se avisa solo por correo.</span>
                    </div>
                </div>
                <div class="col-12 col-sm-6 col-lg-3">
                    <label class="form-label" for="exp-days">Avisar a los días</label>
                    <input id="exp-days" v-model.trim="form.days" class="form-control" :class="{ 'is-invalid': form.errors.days }" placeholder="30,15,7,1,0" />
                    <div class="invalid-feedback">{{ form.errors.days }}</div>
                    <div class="form-text">0 = el mismo día.</div>
                </div>
                <div class="col-12 d-flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-primary" :disabled="form.processing">Guardar</button>
                    <button type="button" class="btn btn-light border" :disabled="testing.processing || !hasRecipients" :title="hasRecipients ? '' : 'Guarda al menos un destinatario'" @click="test">
                        <i class="bi bi-send me-1"></i>Enviar prueba
                    </button>
                </div>
            </form>
        </section>
    </AppLayout>
</template>
