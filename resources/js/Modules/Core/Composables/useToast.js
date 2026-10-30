import { reactive } from 'vue';

// Avisos que nacen en el navegador (guardado por AJAX, copiar, validar…) en la misma pila que los
// avisos del servidor: FlashToasts.vue los muestra. Tipos: success | info | warning | danger.
const items = reactive([]);
let seq = 0;

export function dismissToast(id) {
    const i = items.findIndex((t) => t.id === id);
    if (i !== -1) items.splice(i, 1);
}

export function toast(message, type = 'success') {
    const id = ++seq;
    items.push({ id, message, type });
    // Un error se lee con calma; un aviso de éxito se va solo antes
    setTimeout(() => dismissToast(id), type === 'danger' ? 8000 : 4500);
    return id;
}

export function useToast() {
    return { toasts: items, toast, dismissToast };
}
