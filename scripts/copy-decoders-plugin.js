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
            const threeLibsDir = resolve(__dirname, '..', 'node_modules/three/examples/jsm/libs');
            const outDir       = resolve(__dirname, '..', 'public/decoders');

            if (!existsSync(threeLibsDir)) {
                this.warn('three/examples/jsm/libs not found — skipping decoder copy. Run `npm install` first.');
                return;
            }

            // Ensure output dir exists
            mkdirSync(join(outDir, 'draco'), { recursive: true });
            mkdirSync(join(outDir, 'basis'), { recursive: true });

            const dracoSrc = join(threeLibsDir, 'draco');
            if (existsSync(dracoSrc)) {
                // Copy everything (js + wasm + gltf subfolder)
                copyRecursive(dracoSrc, join(outDir, 'draco'));
            } else {
                this.warn('DRACO decoder source not found in three/examples/jsm/libs/draco');
            }

            const basisSrc = join(threeLibsDir, 'basis');
            if (existsSync(basisSrc)) {
                copyRecursive(basisSrc, join(outDir, 'basis'));
            } else {
                this.warn('Basis transcoder source not found in three/examples/jsm/libs/basis');
            }
        },
    };
}
