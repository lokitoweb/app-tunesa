import 'bootstrap/dist/css/bootstrap.min.css';
import 'bootstrap-icons/font/bootstrap-icons.min.css';
// Misma hoja de estilos que las pantallas Blade aún no migradas (una sola fuente)
import '../../public/css/panel.css';
import 'bootstrap';

import { createApp, h } from 'vue';
import { createInertiaApp, router } from '@inertiajs/vue3';
import { installSkeletonNavigation } from './Modules/Core/Composables/useSkeleton';

// Carga diferida: cada pantalla se importa al navegar a ella. El nombre que
// envía el servidor es la ruta dentro de Modules ("HR/Pages/Shifts/Index").
const pages = import.meta.glob('./Modules/*/Pages/**/*.vue');

createInertiaApp({
    resolve: async (name) => {
        const load = pages[`./Modules/${name}.vue`];
        if (!load) throw new Error(`Pantalla no encontrada: ${name}`);
        return (await load()).default;
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) }).use(plugin).mount(el);
    },
    progress: { color: 'var(--brand, #0d6efd)' },
});

// Skeleton loaders al cambiar de filtro, pestaña o pantalla
installSkeletonNavigation(router);

// Un enlace de una pantalla Vue a una pantalla Blade (aún no migrada) recibe HTML normal:
// en vez de mostrarlo en el modal de Inertia (la web entera dentro de otra), se abre la página.
// Solo visitas GET exitosas: un POST con error sigue en el modal para no perder lo escrito.
router.on('invalid', (event) => {
    const res = event.detail.response;
    const url = res?.request?.responseURL;
    if (res?.status >= 200 && res?.status < 300 && url && (res.config?.method || 'get').toLowerCase() === 'get') {
        event.preventDefault();
        window.location.href = url;
    }
});
