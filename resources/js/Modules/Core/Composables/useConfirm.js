import { ref } from 'vue';

const confirmState = ref({
    show: false,
    title: '¿Estás seguro?',
    message: 'Esta acción no se puede deshacer.',
    confirmText: 'Sí, confirmar',
    cancelText: 'Cancelar',
    variant: 'danger',
    icon: '',
    resolve: null,
});

export function useConfirm() {
    function ask({
        title = '¿Estás seguro?',
        message = 'Esta acción no se puede deshacer.',
        confirmText = 'Sí, confirmar',
        cancelText = 'Cancelar',
        variant = 'danger',
        icon = '',
    } = {}) {
        return new Promise((resolve) => {
            confirmState.value = {
                show: true,
                title,
                message,
                confirmText,
                cancelText,
                variant,
                icon,
                resolve,
            };
        });
    }

    function onConfirm() {
        if (confirmState.value.resolve) {
            confirmState.value.resolve(true);
        }
        confirmState.value.show = false;
    }

    function onCancel() {
        if (confirmState.value.resolve) {
            confirmState.value.resolve(false);
        }
        confirmState.value.show = false;
    }

    return {
        confirmState,
        ask,
        onConfirm,
        onCancel,
    };
}
