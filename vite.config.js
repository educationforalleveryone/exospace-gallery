import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { fileURLToPath } from 'node:url';
import { resolve, dirname } from 'node:path';
import copyDecodersPlugin from './scripts/copy-decoders-plugin.js';

const __dirname = dirname(fileURLToPath(import.meta.url));

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/gallery/main.js',
                'resources/js/admin-vendor.js', // (Task H19) Chart.js + Dropzone + SortableJS
            ],
            refresh: true,
        }),
        copyDecodersPlugin(),
    ],
    resolve: {
        alias: [
            {
                find: 'three/addons',
                replacement: resolve(__dirname, 'node_modules/three/examples/jsm'),
            },
            {
                find: 'three',
                replacement: resolve(__dirname, 'node_modules/three'),
            },
        ],
    },
    optimizeDeps: {
        include: [
            'three',
            'gsap',
        ],
    },
    build: {
        target: 'es2020',
        rollupOptions: {
            output: {
                // Split three into its own chunk so it caches across deploys
                manualChunks: {
                    three: ['three'],
                    gsap: ['gsap'],
                },
            },
        },
        chunkSizeWarningLimit: 1500,
    },
    server: {
        host: '0.0.0.0',
        port: 5173,
    },
});
