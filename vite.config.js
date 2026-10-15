import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

// Un chunk por módulo del ERP (hr-[hash].js, integrations-[hash].js...): el
// navegador solo descarga el código del módulo que el usuario abre.
const moduleChunk = (id) => id.match(/\/resources\/js\/Modules\/(\w+)\//)?.[1].toLowerCase();

export default defineConfig({
    plugins: [
        laravel({ input: 'resources/js/app.js', refresh: true }),
        vue({ template: { transformAssetUrls: { base: null, includeAbsolute: false } } }),
    ],
    resolve: {
        alias: { '@': '/resources/js' },
    },
    build: {
        sourcemap: false,
        rollupOptions: {
            output: {
                // El CSS de Bootstrap va con el del panel (mismo archivo y orden): así
                // panel.css le gana y los botones toman el color de la marca
                manualChunks: (id) => (id.includes('node_modules') && !id.endsWith('.css') ? 'vendor' : moduleChunk(id)),
                chunkFileNames: 'assets/[name]-[hash].js',
            },
        },
    },
});
