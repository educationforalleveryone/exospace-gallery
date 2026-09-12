import { defineConfig } from 'vite';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');

export default defineConfig({
    root,
    base: '/harness/',
    resolve: {
        alias: [
            { find: /^three\/addons(.*)$/, replacement: 'three/examples/jsm$1' },
            { find: /^three$/, replacement: 'three' },
        ],
    },
    publicDir: false, // the harness ships its own assets/ (textures, sample
    build: {
        outDir: path.join(root, 'public/harness'),
        emptyOutDir: false, // assets/ (textures, sample art) persist across builds
        target: 'es2020',
        rollupOptions: {
            input: path.join(root, 'scripts/harness/harness.html'),
        },
    },
});
