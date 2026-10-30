<script setup>
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';

// Diálogo modal de las pantallas Vue: entra con escala 0.96 → 1, se cierra con Escape o tocando afuera,
// lleva el foco adentro al abrir y lo devuelve al cerrar; en celular ocupa la pantalla.
const props = defineProps({
    show: { type: Boolean, default: false },
    title: { type: String, required: true },
    subtitle: { type: String, default: '' },
    size: { type: String, default: '' }, // '', 'lg', 'xl'
    busy: { type: Boolean, default: false }, // mientras se guarda no se cierra por accidente
});
const emit = defineEmits(['close']);

const dialog = ref(null);
let opener = null;
const close = () => { if (!props.busy) emit('close'); };
const onKey = (e) => { if (e.key === 'Escape') close(); };

watch(() => props.show, async (open) => {
    if (open) {
        opener = document.activeElement;
        document.addEventListener('keydown', onKey);
        document.body.classList.add('modal-open');
        await nextTick();
        (dialog.value?.querySelector('input:not([type=hidden]), select, textarea') || dialog.value)?.focus({ preventScroll: true });
    } else {
        document.removeEventListener('keydown', onKey);
        document.body.classList.remove('modal-open');
        opener?.focus?.({ preventScroll: true });
    }
}, { immediate: true });
onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKey);
    document.body.classList.remove('modal-open');
});
</script>

<template>
    <Teleport to="body">
        <Transition name="app-modal">
            <div v-if="show" class="app-modal-overlay" @mousedown.self="close">
                <div ref="dialog" class="app-modal" :class="size ? `app-modal-${size}` : ''" role="dialog" aria-modal="true" :aria-label="title" tabindex="-1">
                    <header class="app-modal-head">
                        <div class="min-w-0">
                            <h2 class="app-modal-title">{{ title }}</h2>
                            <p v-if="subtitle || $slots.subtitle" class="app-modal-sub"><slot name="subtitle">{{ subtitle }}</slot></p>
                        </div>
                        <button type="button" class="btn-close" aria-label="Cerrar" :disabled="busy" @click="close"></button>
                    </header>
                    <div class="app-modal-body"><slot /></div>
                    <footer v-if="$slots.footer" class="app-modal-foot"><slot name="footer" /></footer>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
