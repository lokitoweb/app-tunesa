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
                manualChunks: (id) => (id.includes('node_modules') ? 'vendor' : moduleChunk(id)),
                chunkFileNames: 'assets/[name]-[hash].js',
            },
        },
    },
});
