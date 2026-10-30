<script setup>
import { computed, reactive, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';

// Avisos del servidor (flash) y errores de validación; los de éxito e información se cierran solos a los 6 s
const page = usePage();
const errors = computed(() => Object.values(page.props.errors ?? {}));
const dismissed = reactive(new Set());
watch(() => page.props.flash, (flash) => {
    dismissed.clear();
    for (const key of ['success', 'info']) {
        if (flash?.[key]) setTimeout(() => dismissed.add(key + flash[key]), 6000);
    }
}, { immediate: true });
watch(() => page.props.errors, () => dismissed.delete('errors'));

const FLASH = {
    success: ['success', 'bi-check-lg', 'Listo'],
    info: ['info', 'bi-info-circle-fill', 'Aviso'],
    error: ['danger', 'bi-exclamation-triangle-fill', 'Atención'],
};
</script>

<template>
    <TransitionGroup tag="div" name="toast" class="toast-stack" aria-live="polite">
        <template v-for="(text, key) in page.props.flash" :key="key + text">
            <div v-if="!dismissed.has(key + text) && FLASH[key]" class="alert toast-item" :class="`alert-${FLASH[key][0]} toast-${FLASH[key][0]}`" role="alert">
                <div class="toast-icon-badge" :class="`toast-badge-${FLASH[key][0]}`">
                    <i class="bi" :class="FLASH[key][1]"></i>
                </div>
                <div class="toast-content">
                    <strong class="toast-title">{{ FLASH[key][2] }}</strong>
                    <span class="toast-text">{{ text }}</span>
                </div>
                <button type="button" class="toast-close-btn" aria-label="Cerrar" @click="dismissed.add(key + text)">
                    <i class="bi bi-x-lg" style="font-size: 0.75rem;"></i>
                </button>
            </div>
        </template>

        <div v-if="errors.length && !dismissed.has('errors')" key="errors" class="alert alert-danger toast-item toast-danger" role="alert">
            <div class="toast-icon-badge toast-badge-danger">
                <i class="bi bi-exclamation-triangle-fill"></i>
            </div>
            <div class="toast-content">
                <strong class="toast-title">Revisa los datos</strong>
                <ul class="mb-0 toast-text ps-3">
                    <li v-for="error in errors" :key="error">{{ error }}</li>
                </ul>
            </div>
            <button type="button" class="toast-close-btn" aria-label="Cerrar" @click="dismissed.add('errors')">
                <i class="bi bi-x-lg" style="font-size: 0.75rem;"></i>
            </button>
        </div>
    </TransitionGroup>
</template>
