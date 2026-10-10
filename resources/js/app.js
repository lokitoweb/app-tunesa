import 'bootstrap/dist/css/bootstrap.min.css';
import 'bootstrap-icons/font/bootstrap-icons.min.css';
// Misma hoja de estilos que las pantallas Blade aún no migradas (una sola fuente)
import '../../public/css/panel.css';
import 'bootstrap';

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';

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
