<script setup>
defineProps({
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
        default: 'danger', // 'danger' | 'warning' | 'primary'
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

function handleConfirm() {
    emit('confirm');
}

function handleCancel() {
    emit('cancel');
    emit('close');
}
</script>

<template>
    <div 
        v-if="show" 
        class="confirm-modal-overlay d-flex align-items-center justify-content-center"
        role="dialog"
        aria-modal="true"
        @click.self="handleCancel"
    >
        <div class="confirm-modal-card emp-modal-content shadow-lg p-4 animate-scale-in">
            <!-- Icono Central Temático -->
            <div class="d-flex justify-content-center mb-3">
                <div 
                    class="confirm-icon-box"
                    :class="{
                        'tone-danger': variant === 'danger',
                        'tone-warning': variant === 'warning',
                        'tone-primary': variant === 'primary'
                    }"
                >
                    <i 
                        :class="icon || (variant === 'danger' ? 'bi bi-trash3-fill' : (variant === 'warning' ? 'bi bi-exclamation-triangle-fill' : 'bi bi-check-circle-fill'))"
                    ></i>
                </div>
            </div>

            <!-- Título y Mensaje -->
            <div class="text-center mb-4">
                <h3 class="h5 fw-bold text-dark mb-2">{{ title }}</h3>
                <p class="text-secondary small mb-0 px-2" style="line-height: 1.5;">
                    {{ message }}
                </p>
            </div>

            <!-- Botones de Acción con Separación Clara -->
            <div class="d-flex align-items-center justify-content-center" style="gap: 12px !important;">
                <button 
                    type="button" 
                    class="btn btn-outline-secondary px-4 py-2" 
                    style="border-radius: 8px; font-weight: 500; font-size: 0.85rem;" 
                    :disabled="processing"
                    @click="handleCancel"
                >
                    {{ cancelText }}
                </button>
                <button 
                    type="button" 
                    class="btn d-inline-flex align-items-center justify-content-center px-4 py-2 shadow-sm text-white" 
                    :class="variant === 'danger' ? 'btn-danger' : (variant === 'warning' ? 'btn-warning text-dark' : 'btn-primary')"
                    style="border-radius: 8px; font-weight: 600; font-size: 0.85rem; gap: 8px !important;" 
                    :disabled="processing"
                    @click="handleConfirm"
                >
                    <span v-if="processing" class="spinner-border spinner-border-sm me-2" role="status"></span>
                    <i v-else :class="variant === 'danger' ? 'bi bi-trash3' : 'bi bi-check2'" style="margin-right: 4px;"></i>
                    <span>{{ confirmText }}</span>
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.confirm-modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100vw;
    height: 100vh;
    background: rgba(15, 23, 42, 0.65);
    backdrop-filter: blur(4px);
    z-index: 1090;
    padding: 1rem;
}

.confirm-modal-card {
    width: 100%;
    max-width: 440px;
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: 14px;
}

.confirm-icon-box {
    width: 60px;
    height: 60px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.75rem;
}

.confirm-icon-box.tone-danger {
    background: rgba(220, 38, 38, 0.12);
    color: #dc2626;
    border: 1px solid rgba(220, 38, 38, 0.25);
}

.confirm-icon-box.tone-warning {
    background: rgba(217, 119, 6, 0.12);
    color: #d97706;
    border: 1px solid rgba(217, 119, 6, 0.25);
}

.confirm-icon-box.tone-primary {
    background: rgba(2, 132, 199, 0.12);
    color: #0284c7;
    border: 1px solid rgba(2, 132, 199, 0.25);
}

.animate-scale-in {
    animation: scaleIn 0.15s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}

@keyframes scaleIn {
    from {
        opacity: 0;
        transform: scale(0.95);
    }
    to {
        opacity: 1;
        transform: scale(1);
    }
}
</style>
