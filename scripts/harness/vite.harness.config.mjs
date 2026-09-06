// ─────────────────────────────────────────────────────────────────────────────
// vite.harness.config.mjs — standalone build for the PHP-less viewer harness.
//
//   npx vite build --config scripts/harness/vite.harness.config.mjs
//     → writes public/harness/harness.html + bundled viewer runtime
//   then serve public/ with any static file server and drive it with
//   scripts/harness/shoot.mjs (Playwright).
//
// The app's own vite.config.js stays untouched — this config exists purely
// so venue visual QA can run without a Laravel stack.
// ─────────────────────────────────────────────────────────────────────────────
import { defineConfig } from 'vite';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');

export default defineConfig({
    root,
    base: '/harness/',
    resolve: {
        alias: [
            // Order matters: three/addons must precede three (documented pitfall
            // in the app vite.config.js).
            { find: /^three\/addons(.*)$/, replacement: 'three/examples/jsm$1' },
            { find: /^three$/, replacement: 'three' },
        ],
    },
    publicDir: false, // the harness ships its own assets/ (textures, sample
                      // artworks) — copying all of public/ here would both
                      // pollute the output and bury them in duplicates.
    build: {
        outDir: path.join(root, 'public/harness'),
        emptyOutDir: false, // assets/ (textures, sample art) persist across builds
        target: 'es2020',
        rollupOptions: {
            input: path.join(root, 'scripts/harness/harness.html'),
        },
    },
});
