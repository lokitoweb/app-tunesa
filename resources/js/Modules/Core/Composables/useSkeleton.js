import { onBeforeUnmount, ref, watch } from 'vue';

// Retardo antes de mostrar la silueta: una respuesta rápida no parpadea.
export const SKELETON_DELAY = 150;

/**
 * Convierte un "está cargando" en "mostrar silueta": se activa solo si la
 * carga dura más de SKELETON_DELAY y se apaga en cuanto termina. Se usa con la
 * clase .sk-loading de panel.css (tablas, KPIs y listas en silueta).
 */
export function useSkeleton(loading, delay = SKELETON_DELAY) {
    const visible = ref(false);
    let timer = null;
    watch(loading, (on) => {
        clearTimeout(timer);
        if (on) timer = setTimeout(() => { visible.value = true; }, delay);
        else visible.value = false;
    }, { immediate: true });
    onBeforeUnmount(() => clearTimeout(timer));
    return visible;
}

/**
 * Navegaciones GET de Inertia (filtros, pestañas, paginación, cambio de
 * pantalla): la pantalla actual pasa a silueta hasta que llega la nueva.
 * Los envíos de formularios y las precargas no la muestran.
 */
export function installSkeletonNavigation(router, selector = '#main-content') {
    let timer = null;
    const main = () => document.querySelector(selector);
    const off = () => {
        clearTimeout(timer);
        const el = main();
        if (el) { el.classList.remove('sk-loading'); el.removeAttribute('aria-busy'); }
    };
    router.on('start', (event) => {
        const visit = event.detail.visit;
        if (visit.method !== 'get' || visit.prefetch) return;
        clearTimeout(timer);
        timer = setTimeout(() => {
            const el = main();
            if (el) { el.classList.add('sk-loading'); el.setAttribute('aria-busy', 'true'); }
        }, SKELETON_DELAY);
    });
    router.on('finish', off);
}
