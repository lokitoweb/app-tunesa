<script setup>
// Historial de envíos (GET /settings/branding/deliveries): últimos 20, con detalle desplegable
import { onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';

const props = defineProps({ request: { type: Function, required: true }, subtitle: String });

const STATUS = {
    success: ['success', 'Entregado', 'bi-check-circle-fill'],
    pending: ['warning', 'Pendiente', 'bi-hourglass-split'],
    failed: ['danger', 'Fallido', 'bi-x-circle-fill']
};
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
    <div class="card border-0 shadow-sm integrations-card overflow-hidden">
        <div class="card-header border-0 bg-transparent pt-3 pb-2 px-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h2 class="h6 fw-bold mb-0 text-primary-emphasis">Historial reciente de entregas (Últimos 20)</h2>
                <small class="settings-helper-text">{{ subtitle }}</small>
            </div>
            <div class="d-flex align-items-center gap-3">
                <div class="form-check form-switch mb-0 d-flex align-items-center gap-2">
                    <input id="deliveriesAuto" v-model="auto" class="form-check-input" type="checkbox" role="switch" style="cursor: pointer;">
                    <label class="form-check-label small fw-medium" for="deliveriesAuto">Auto (10 s)</label>
                </div>
                <button type="button" class="btn-integration-secondary" :disabled="loading" @click="load">
                    <span v-if="loading" class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>
                    <i v-else class="bi bi-arrow-clockwise me-2"></i>
                    Actualizar
                </button>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Evento</th>
                        <th>Host destino</th>
                        <th class="text-center">Intento</th>
                        <th class="text-center">HTTP</th>
                        <th class="text-end">Latencia</th>
                        <th>Estado</th>
                        <th>Fecha de transmisión</th>
                        <th class="text-center pe-3"><span class="visually-hidden">Detalle</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="error">
                        <td colspan="8" class="text-center text-danger py-4">
                            <i class="bi bi-exclamation-triangle-fill me-1"></i> {{ error }}
                        </td>
                    </tr>
                    <tr v-else-if="rows === null">
                        <td colspan="8" class="text-center text-secondary py-4">
                            <span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span> Cargando bitácora de entregas…
                        </td>
                    </tr>
                    <tr v-else-if="!rows.length">
                        <td colspan="8" class="text-center text-secondary py-4">
                            <i class="bi bi-inbox me-1"></i> Todavía no se han registrado transmisiones de webhook.
                        </td>
                    </tr>
                    <template v-for="d in error ? [] : rows ?? []" :key="d.id">
                        <tr>
                            <td class="ps-3">
                                <div class="fw-semibold text-primary-emphasis">{{ d.event_label || d.event }}</div>
                                <code class="small text-secondary">{{ d.event }}</code>
                            </td>
                            <td class="text-break font-monospace small">{{ d.host }}</td>
                            <td class="text-center">
                                <span class="badge bg-secondary-subtle text-secondary-emphasis border">{{ d.attempt }}</span>
                            </td>
                            <td class="text-center">
                                <span v-if="d.http_status" class="badge" :class="`text-bg-${tone(d.http_status)}`">HTTP {{ d.http_status }}</span>
                                <span v-else class="text-secondary">—</span>
                            </td>
                            <td class="text-end text-nowrap font-monospace">{{ d.response_ms != null ? `${d.response_ms} ms` : '—' }}</td>
                            <td>
                                <span v-if="STATUS[d.status]" class="badge d-inline-flex align-items-center gap-1" :class="`bg-${STATUS[d.status][0]}-subtle text-${STATUS[d.status][0]} border border-${STATUS[d.status][0]}-subtle`">
                                    <i class="bi" :class="STATUS[d.status][2]"></i>
                                    {{ STATUS[d.status][1] }}
                                </span>
                                <span v-else class="badge bg-secondary">{{ d.status }}</span>
                            </td>
                            <td class="text-nowrap text-secondary">{{ d.created_at }}</td>
                            <td class="text-center pe-3">
                                <button type="button" class="btn-table-action" :class="{ 'active': open.has(d.id) }" :title="open.has(d.id) ? 'Cerrar detalle' : 'Ver payload y respuesta'" @click="open.has(d.id) ? open.delete(d.id) : open.add(d.id)">
                                    <i class="bi" :class="open.has(d.id) ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
                                </button>
                            </td>
                        </tr>
                        <tr v-if="open.has(d.id)">
                            <td colspan="8" class="bg-body-tertiary-subtle p-3">
                                <div class="delivery-detail">
                                    <div v-if="d.error" class="text-danger mb-2 small fw-semibold">
                                        <i class="bi bi-exclamation-triangle-fill me-1"></i> Error: {{ d.error }}
                                    </div>
                                    <div v-if="d.next_retry_at" class="text-secondary mb-2 small">
                                        <i class="bi bi-arrow-clockwise me-1"></i> Próximo reintento programado: {{ d.next_retry_at }}
                                    </div>
                                    <div class="text-secondary mb-2 small font-monospace text-break">
                                        <i class="bi bi-link-45deg me-1"></i> URL: {{ d.url }}
                                    </div>
                                    <pre v-if="d.response_body" class="bg-body-tertiary border rounded p-3 mb-0 integration-pre font-monospace small">{{ pretty(d.response_body) }}</pre>
                                    <span v-else class="small text-secondary fst-italic">Sin cuerpo de respuesta registrado.</span>
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
.delivery-detail { position: sticky; left: .5rem; max-width: calc(100vw - 4rem); }
</style>
