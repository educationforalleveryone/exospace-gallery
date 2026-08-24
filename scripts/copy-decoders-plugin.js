// ─────────────────────────────────────────────────────────────────────────────
// copy-decoders-plugin.js — Vite plugin that auto-copies DRACO + KTX2 (Basis)
// decoder wasm files from node_modules/three/examples/jsm/libs/ to public/decoders/
// every time `npm run build` or `npm run dev` runs.
//
// Why this exists:
//   Three.js's DRACOLoader and KTX2Loader need these wasm files at runtime.
//   The default path is /decoders/draco/ and /decoders/basis/ — set in
//   AssetLoader.js. Without this plugin, you'd have to remember to run
//   `bash scripts/copy-decoders.sh` after every `npm install` or three upgrade.
//
// With this plugin, the decoders are always in sync with the installed
// three version. No manual step required.
// ─────────────────────────────────────────────────────────────────────────────

import { existsSync, mkdirSync, copyFileSync, readdirSync, statSync } from 'node:fs';
import { resolve, dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));

function copyRecursive(src, dst) {
    if (!existsSync(src)) return;
    const stat = statSync(src);
    if (stat.isDirectory()) {
        if (!existsSync(dst)) mkdirSync(dst, { recursive: true });
        for (const entry of readdirSync(src)) {
            copyRecursive(join(src, entry), join(dst, entry));
        }
    } else {
        copyFileSync(src, dst);
    }
}

export default function copyDecodersPlugin() {
    return {
        name: 'exospace-copy-decoders',
        // Runs once at the start of every dev server start + every build
        buildStart() {
            // PERF-A3 (3D audit F3): BOTH paths were resolved against scripts/
            // (__dirname) instead of the project root, so this plugin has
            // silently no-op'd since it was written — production only worked
            // because decoders were manually placed in public/decoders/.
            // Resolve from the project root (one level up from scripts/).
            const threeLibsDir = resolve(__dirname, '..', 'node_modules/three/examples/jsm/libs');
            const outDir       = resolve(__dirname, '..', 'public/decoders');

            if (!existsSync(threeLibsDir)) {
                this.warn('three/examples/jsm/libs not found — skipping decoder copy. Run `npm install` first.');
                return;
            }

            // Ensure output dir exists
            mkdirSync(join(outDir, 'draco'), { recursive: true });
            mkdirSync(join(outDir, 'basis'), { recursive: true });

            // ── DRACO ────────────────────────────────────────────────────────
            const dracoSrc = join(threeLibsDir, 'draco');
            if (existsSync(dracoSrc)) {
                // Copy everything (js + wasm + gltf subfolder)
                copyRecursive(dracoSrc, join(outDir, 'draco'));
            } else {
                this.warn('DRACO decoder source not found in three/examples/jsm/libs/draco');
            }

            // ── KTX2 / Basis ──────────────────────────────────────────────────
            const basisSrc = join(threeLibsDir, 'basis');
            if (existsSync(basisSrc)) {
                copyRecursive(basisSrc, join(outDir, 'basis'));
            } else {
                this.warn('Basis transcoder source not found in three/examples/jsm/libs/basis');
            }
        },
    };
}
