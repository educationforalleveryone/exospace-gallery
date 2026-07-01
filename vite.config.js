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
//
// ⚠️ IMPORTANT — alias ordering matters!
// The `three/addons` alias MUST be listed BEFORE the `three` alias.
// `@rollup/plugin-alias` (which Vite uses internally) matches in array order —
// first match wins. If `three` came first, it would match
// `three/addons/loaders/GLTFLoader.js` as a prefix and rewrite it to
// `node_modules/three/addons/loaders/GLTFLoader.js` (a literal path that does
// not exist — the addons are physically under examples/jsm/). The build would
// then fail with ENOENT. Putting `three/addons` first ensures the longer
// prefix is matched first.
//
// We use the array form (not the object form) so the order is explicit.
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
        alias: [
            // ⚠️ ORDER MATTERS — three/addons must be FIRST.
            // Matches `three/addons/loaders/GLTFLoader.js` →
            //   node_modules/three/examples/jsm/loaders/GLTFLoader.js
            {
                find: 'three/addons',
                replacement: resolve(__dirname, 'node_modules/three/examples/jsm'),
            },
            // Plain `three` matches `import * as THREE from 'three'` →
            //   node_modules/three (resolved via package.json main field →
            //   build/three.module.js)
            {
                find: 'three',
                replacement: resolve(__dirname, 'node_modules/three'),
            },
        ],
    },
    optimizeDeps: {
        // Three is large; pre-bundle so dev server startup is fast.
        // We don't pre-bundle three/addons/* — those resolve via the alias.
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
