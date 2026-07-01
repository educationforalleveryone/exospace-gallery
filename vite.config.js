import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { fileURLToPath } from 'node:url';
import { resolve, dirname } from 'node:path';
import copyDecodersPlugin from './scripts/copy-decoders-plugin.js';

// ─────────────────────────────────────────────────────────────────────────────
// Exospace — Vite configuration
//
// Why this looks different from the old config:
//   - We now bundle Three.js, GLTFLoader, DRACOLoader, KTX2Loader, MeshoptDecoder
//     and GSAP locally (no more unpkg CDN at runtime — fixes the version mismatch
//     and removes the third-party SPOF).
//   - Three.js addons are loaded via the "three/addons/" alias so import paths
//     like `import { GLTFLoader } from 'three/addons/loaders/GLTFLoader.js'`
//     resolve to the same version of three as the core.
//   - The copyDecodersPlugin auto-copies the DRACO + KTX2 wasm files to
//     public/decoders/ on every build/dev start — no manual script needed.
//   - The gallery entry point lives in resources/js/gallery/main.js; the legacy
//     resources/js/app.js stays for the admin pages.
// ─────────────────────────────────────────────────────────────────────────────

const __dirname = dirname(fileURLToPath(import.meta.url));

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/gallery/main.js',
            ],
            refresh: true,
        }),
        // Auto-copy DRACO + KTX2 decoder wasm files to public/decoders/
        // every time the build runs. Eliminates a manual setup step.
        copyDecodersPlugin(),
    ],
    resolve: {
        alias: {
            // Three core + addons both resolve to the same node_modules install
            three: resolve(__dirname, 'node_modules/three'),
            'three/addons': resolve(__dirname, 'node_modules/three/examples/jsm'),
        },
    },
    optimizeDeps: {
        // Three is large; pre-bundle so dev server startup is fast
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
        // Three + addons + gallery code = ~1.1 MB minified, ~280 KB gzip
        // Bump the warning limit so the build is quiet
        chunkSizeWarningLimit: 1500,
    },
    server: {
        host: '0.0.0.0',
        port: 5173,
    },
});
