<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    title: {
        type: String,
        default: '¿Estás seguro?',
    },
    message: {
        type: String,
        default: 'Esta acción no se puede deshacer.',
    },
    confirmText: {
        type: String,
        default: 'Sí, confirmar',
    },
    cancelText: {
        type: String,
        default: 'Cancelar',
    },
    variant: {
        type: String,
        // Rojo solo cuando se pide (eliminar, anular): sin variante es una confirmación normal
        default: 'primary', // 'danger' | 'warning' | 'primary'
    },
    icon: {
        type: String,
        default: '',
    },
    processing: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['confirm', 'cancel', 'close']);

// Ícono del botón: el del aviso en su versión de línea (bi-archive-fill → bi-archive); si no hay, uno por variante
const buttonIcon = computed(() => (props.icon
    ? props.icon.replace(/-fill\b/, '')
    : ({ danger: 'bi bi-trash3', warning: 'bi bi-exclamation-triangle' }[props.variant] ?? 'bi bi-check2')));

function handleConfirm() {
    emit('confirm');
}

function handleCancel() {
    emit('cancel');
    emit('close');
}

// Escape cancela; el foco va a "Cancelar" para que Enter no confirme sin querer una acción destructiva
const cancelButton = ref(null);
const onKey = (e) => { if (e.key === 'Escape' && !props.processing) handleCancel(); };
watch(() => props.show, async (open) => {
    if (open) {
        document.addEventListener('keydown', onKey);
        await nextTick();
        cancelButton.value?.focus();
    } else {
        document.removeEventListener('keydown', onKey);
    }
}, { immediate: true });
onBeforeUnmount(() => document.removeEventListener('keydown', onKey));
</script>

<template>
    <Transition name="confirm">
        <div
            v-if="show"
            class="confirm-modal-overlay"
            role="alertdialog"
            aria-modal="true"
            aria-labelledby="confirm-modal-title"
            aria-describedby="confirm-modal-message"
            @click.self="!processing && handleCancel()"
        >
            <div class="confirm-modal-card">
                <div class="d-flex gap-3">
                    <div class="confirm-icon-box" :class="`tone-${variant}`" aria-hidden="true">
                        <i :class="icon || (variant === 'danger' ? 'bi bi-trash3' : (variant === 'warning' ? 'bi bi-exclamation-triangle' : 'bi bi-check2-circle'))"></i>
                    </div>
                    <div class="min-w-0">
                        <h3 id="confirm-modal-title" class="confirm-modal-title">{{ title }}</h3>
                        <p id="confirm-modal-message" class="confirm-modal-message">{{ message }}</p>
                    </div>
                </div>

                <div class="confirm-modal-actions">
                    <button ref="cancelButton" type="button" class="btn btn-light border" :disabled="processing" @click="handleCancel">
                        {{ cancelText }}
                    </button>
                    <button
                        type="button"
                        class="btn d-inline-flex align-items-center justify-content-center gap-2"
                        :class="variant === 'danger' ? 'btn-danger' : (variant === 'warning' ? 'btn-warning' : 'btn-primary')"
                        :disabled="processing"
                        @click="handleConfirm"
                    >
                        <span v-if="processing" class="spinner-border spinner-border-sm" role="status"></span>
                        <!-- El botón repite el ícono del aviso (anular, regenerar…); el tacho solo para eliminar -->
                        <i v-else :class="buttonIcon"></i>
                        <span>{{ confirmText }}</span>
                    </button>
                </div>
            </div>
        </div>
    </Transition>
</template>

<style scoped>
.confirm-modal-overlay {
    position: fixed;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(15, 23, 42, 0.45);
    backdrop-filter: blur(2px);
    z-index: 1090;
    padding: 1rem;
}

.confirm-modal-card {
    width: 100%;
    max-width: 440px;
    padding: 1.25rem;
    background: var(--bg-surface);
    border-radius: var(--radius-lg, 14px);
    box-shadow: var(--shadow-lg);
}

.confirm-modal-title {
    font-size: 1rem;
    font-weight: 700;
    letter-spacing: -0.015em;
    color: var(--text-primary);
    margin: 0.15rem 0 0.35rem;
}

.confirm-modal-message {
    font-size: 0.85rem;
    line-height: 1.55;
    color: var(--text-secondary);
    margin: 0;
}

.confirm-modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 0.5rem;
    margin-top: 1.25rem;
}

.confirm-modal-actions .btn { font-size: 0.85rem; font-weight: 600; padding: 0.5rem 1rem; }

@media (max-width: 575.98px) {
    .confirm-modal-overlay { align-items: flex-end; }
    .confirm-modal-actions { flex-direction: column-reverse; }
    .confirm-modal-actions .btn { width: 100%; }
}

.confirm-icon-box {
    width: 40px;
    height: 40px;
    flex-shrink: 0;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.15rem;
}

/* Colores del tema: en modo oscuro Bootstrap aclara los *-text-emphasis */
.confirm-icon-box.tone-danger { background: var(--bs-danger-bg-subtle); color: var(--bs-danger-text-emphasis); }
.confirm-icon-box.tone-warning { background: var(--bs-warning-bg-subtle); color: var(--bs-warning-text-emphasis); }
.confirm-icon-box.tone-primary { background: rgba(var(--brand-rgb), 0.1); color: var(--brand); }

/* Entrada: el fondo aparece y la tarjeta pasa de 0.96 a 1; salida más corta que la entrada */
.confirm-enter-active { transition: opacity var(--dur-3, 240ms) var(--ease-out); }
.confirm-leave-active { transition: opacity var(--dur-2, 180ms) var(--ease-out); }
.confirm-enter-active .confirm-modal-card { transition: transform var(--dur-3, 240ms) var(--ease-out); }
.confirm-leave-active .confirm-modal-card { transition: transform var(--dur-2, 180ms) var(--ease-out); }
.confirm-enter-from, .confirm-leave-to { opacity: 0; }
.confirm-enter-from .confirm-modal-card, .confirm-leave-to .confirm-modal-card { transform: translateY(6px) scale(0.96); }
</style>
