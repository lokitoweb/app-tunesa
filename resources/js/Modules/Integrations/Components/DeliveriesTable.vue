<script setup>
// Historial de envíos (GET /settings/branding/deliveries): últimos 20, con detalle desplegable
import { onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';

const props = defineProps({ request: { type: Function, required: true }, subtitle: String });

const STATUS = { success: ['success', 'Entregado'], pending: ['warning', 'Pendiente'], failed: ['danger', 'Fallido'] };
const tone = (status) => (status < 300 ? 'success' : status < 400 ? 'info' : status < 500 ? 'warning' : 'danger');
const pretty = (text) => {
    try { return JSON.stringify(JSON.parse(text), null, 2); } catch (e) { return text; }
};

const rows = ref(null); // null = cargando
const error = ref('');
const loading = ref(false);
const open = reactive(new Set());
const auto = ref(false);
let timer = null;

async function load() {
    if (loading.value) return;
    loading.value = true;
    try {
        rows.value = (await props.request('/settings/branding/deliveries', { method: 'GET' })).data;
        error.value = '';
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
}

watch(auto, (on) => {
    clearInterval(timer);
    timer = on ? setInterval(() => { if (!document.hidden) load(); }, 10000) : null;
});
onMounted(load);
onBeforeUnmount(() => clearInterval(timer));

defineExpose({ load });
</script>

<template>
    <div class="card border-0">
        <div class="card-header border-0 pt-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h2 class="h6 fw-semibold mb-0">Últimos 20 envíos</h2>
                <small class="text-body-secondary">{{ subtitle }}</small>
            </div>
            <div class="d-flex align-items-center gap-2">
                <div class="form-check form-switch mb-0">
                    <input id="deliveriesAuto" v-model="auto" class="form-check-input" type="checkbox" role="switch">
                    <label class="form-check-label small" for="deliveriesAuto">Auto (10 s)</label>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary" :disabled="loading" @click="load">
                    <i class="bi bi-arrow-clockwise me-1"></i>Actualizar
                </button>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0 small">
                <thead>
                    <tr>
                        <th>Evento</th><th>Destino</th><th class="text-center">Intento</th><th class="text-center">HTTP</th>
                        <th class="text-end">Tiempo</th><th>Estado</th><th>Fecha</th><th class="text-end"><span class="visually-hidden">Detalle</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="error"><td colspan="8" class="text-center text-danger py-3">{{ error }}</td></tr>
                    <tr v-else-if="rows === null"><td colspan="8" class="text-center text-body-secondary py-3">Cargando…</td></tr>
                    <tr v-else-if="!rows.length"><td colspan="8" class="text-center text-body-secondary py-3">Todavía no hay envíos.</td></tr>
                    <template v-for="d in error ? [] : rows ?? []" :key="d.id">
                        <tr>
                            <td><div class="fw-medium">{{ d.event_label || d.event }}</div><code class="small">{{ d.event }}</code></td>
                            <td class="text-break">{{ d.host }}</td>
                            <td class="text-center">{{ d.attempt }}</td>
                            <td class="text-center">
                                <span v-if="d.http_status" class="badge" :class="`text-bg-${tone(d.http_status)}`">HTTP {{ d.http_status }}</span>
                                <template v-else>—</template>
                            </td>
                            <td class="text-end text-nowrap">{{ d.response_ms != null ? `${d.response_ms} ms` : '—' }}</td>
                            <td>
                                <span v-if="STATUS[d.status]" class="badge" :class="`text-bg-${STATUS[d.status][0]}`">{{ STATUS[d.status][1] }}</span>
                                <template v-else>{{ d.status }}</template>
                            </td>
                            <td class="text-nowrap">{{ d.created_at }}</td>
                            <td class="text-end">
                                <button type="button" class="btn btn-link btn-sm p-0" :aria-expanded="open.has(d.id)" @click="open.has(d.id) ? open.delete(d.id) : open.add(d.id)">
                                    <i class="bi" :class="open.has(d.id) ? 'bi-chevron-up' : 'bi-chevron-down'"></i><span class="visually-hidden">Ver respuesta</span>
                                </button>
                            </td>
                        </tr>
                        <tr v-if="open.has(d.id)">
                            <td colspan="8" class="bg-body-tertiary-subtle">
                                <div class="delivery-detail">
                                    <div v-if="d.error" class="text-danger mb-1">{{ d.error }}</div>
                                    <div v-if="d.next_retry_at" class="text-body-secondary mb-1">Próximo reintento: {{ d.next_retry_at }}</div>
                                    <div class="text-body-secondary mb-1 text-break">{{ d.url }}</div>
                                    <pre v-if="d.response_body" class="bg-body-tertiary rounded p-2 mb-0 integration-pre">{{ pretty(d.response_body) }}</pre>
                                    <span v-else class="text-body-secondary">Sin cuerpo de respuesta.</span>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>
</template>

<style scoped>
/* El detalle ocupa toda la fila: en pantallas angostas queda fijo al borde visible de la tabla con scroll */
.delivery-detail { position: sticky; left: .5rem; max-width: calc(100vw - 4rem); }
</style>
