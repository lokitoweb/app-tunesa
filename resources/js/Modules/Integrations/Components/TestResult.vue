<script setup>
// Resultado de una prueba en vivo: {ok, status, ms, signed, body, error}
defineProps({ result: { type: Object, default: null } });

const tone = (status) => (status < 300 ? 'success' : status < 400 ? 'info' : status < 500 ? 'warning' : 'danger');
const pretty = (text) => {
    try { return JSON.stringify(JSON.parse(text), null, 2); } catch (e) { return text; }
};
</script>

<template>
    <div v-if="result" class="mt-2" aria-live="polite">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <i class="bi" :class="result.ok ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger'"></i>
            <span v-if="result.status" class="badge" :class="`text-bg-${tone(result.status)}`">HTTP {{ result.status }}</span>
            <span v-else-if="result.message" class="small">{{ result.message }}</span>
            <span v-else-if="!result.error" class="badge text-bg-secondary">Sin respuesta</span>
            <span v-if="result.ms" class="small text-body-secondary">{{ result.ms }} ms</span>
            <span v-if="result.signed === false" class="small text-body-secondary">(sin firma: no hay secreto)</span>
        </div>
        <div v-if="result.error" class="small text-danger mt-1">{{ result.error }}</div>
        <pre v-if="result.body" class="small bg-body-tertiary rounded p-2 mt-1 mb-0 integration-pre">{{ pretty(result.body) }}</pre>
    </div>
</template>

<style>
.integration-pre { max-height: 240px; overflow: auto; white-space: pre-wrap; word-break: break-word; }
</style>
