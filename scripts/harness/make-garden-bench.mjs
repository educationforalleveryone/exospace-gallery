#!/usr/bin/env node
import * as THREE from 'three';
import { GLTFExporter } from 'three/addons/exporters/GLTFExporter.js';
import { RoundedBoxGeometry } from 'three/addons/geometries/RoundedBoxGeometry.js';
import { execSync } from 'node:child_process';
import { writeFileSync, unlinkSync, statSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const DEST_DIR = path.join(ROOT, 'public/assets/venues/sculpture-garden');
const RAW = path.join(DEST_DIR, 'bench_01.raw.glb');
const OUT = path.join(DEST_DIR, 'bench_01.glb');

// ── Procedural wood grain (512² canvas, grain runs along U) ──────────────────
function makeWoodTexture() {
    const S = 512;
    const c = document ? document.createElement('canvas') : null;
    if (!c) throw new Error('no canvas (run under a DOM shim)');
    c.width = S; c.height = S;
    const g = c.getContext('2d');
    // Deep oak base — enough saturation to read "timber" at 3 m in daylight
    g.fillStyle = '#93714a';
    g.fillRect(0, 0, S, S);
    for (let i = 0; i < 14; i++) {
        const x = Math.random() * S;
        const w = 14 + Math.random() * 42;
        g.fillStyle = `rgba(${Math.random() < 0.5 ? '122,88,52' : '168,132,88'}, ${0.10 + Math.random() * 0.14})`;
        g.fillRect(x, 0, w, S);
    }
    // Long grain streaks — horizontal, medium alpha
    for (let i = 0; i < 130; i++) {
        const y = Math.random() * S;
        const w = 0.6 + Math.random() * 2.2;
        const dark = Math.random() < 0.6;
        g.strokeStyle = dark
            ? `rgba(96, 66, 36, ${0.12 + Math.random() * 0.20})`
            : `rgba(190, 156, 112, ${0.10 + Math.random() * 0.16})`;
        g.lineWidth = w;
        g.beginPath();
        let yy = y;
        g.moveTo(0, yy);
        for (let x = 0; x <= S; x += 32) {
            yy += (Math.random() - 0.5) * 3.4;
            g.lineTo(x, yy);
        }
        g.stroke();
    }
    // Cathedral arches / knots
    for (let i = 0; i < 6; i++) {
        const x = Math.random() * S, y = Math.random() * S;
        const r = 6 + Math.random() * 13;
        g.strokeStyle = `rgba(80, 54, 30, ${0.16 + Math.random() * 0.16})`;
        g.lineWidth = 1.6;
        for (let k = 1; k <= 3; k++) {
            g.beginPath();
            g.ellipse(x, y, r * k, r * k * 0.42, 0, 0, Math.PI * 2);
            g.stroke();
        }
    }
    // Fine noise
    const img = g.getImageData(0, 0, S, S);
    for (let p = 0; p < img.data.length; p += 4) {
        const n = (Math.random() - 0.5) * 14;
        img.data[p] += n; img.data[p + 1] += n; img.data[p + 2] += n;
    }
    g.putImageData(img, 0, 0);
    const tex = new THREE.CanvasTexture(c);
    tex.colorSpace = THREE.SRGBColorSpace;
    tex.wrapS = tex.wrapT = THREE.RepeatWrapping;
    tex.anisotropy = 4;
    return tex;
}

let document = globalThis.document;
if (!document) {
    try {
        const { createCanvas } = await import('canvas');
        if (typeof globalThis.HTMLCanvasElement === 'undefined') {
            globalThis.HTMLCanvasElement = createCanvas(1, 1).constructor;
        }
        globalThis.document = {
            createElement: () => {
                const cv = createCanvas(512, 512);
                cv.style = {};
                // GLTFExporter consumes textures via canvas.toBlob(cb, mime).
                if (typeof cv.toBlob !== 'function') {
                    cv.toBlob = (cb, mimeType = 'image/png') => {
                        const buf = cv.toBuffer(mimeType === 'image/jpeg' ? 'image/jpeg' : 'image/png');
                        cb(new Blob([buf], { type: mimeType }));
                    };
                }
                return cv;
            },
        };
        document = globalThis.document;
    } catch {
        console.error('The `canvas` npm package is required to author the wood texture (npm i -D canvas).');
        process.exit(1);
    }
}
if (typeof globalThis.FileReader !== 'function') {
    globalThis.FileReader = class FileReader {
        readAsArrayBuffer(blob) {
            blob.arrayBuffer().then((buf) => {
                this.result = buf;
                this.onloadend?.();
            }, (err) => { this.error = err; this.onerror?.(); });
        }
    };
}

const steelMat = new THREE.MeshStandardMaterial({
    color: 0x2b2a26, roughness: 0.6, metalness: 0.35,   // panel-stand steel, exact match
});
const woodMat = new THREE.MeshStandardMaterial({
    color: 0xffffff, roughness: 0.82, metalness: 0.0,   // tint comes from the texture
    map: makeWoodTexture(),
});

const root = new THREE.Group();
root.name = 'bench_01';

const steel = (w, h, d, x, y, z, rx = 0) => {
    const m = new THREE.Mesh(new THREE.BoxGeometry(w, h, d), steelMat);
    m.position.set(x, y, z);
    if (rx) m.rotation.x = rx;
    root.add(m);
    return m;
};
const slat = (w, h, d, x, y, z, rx = 0) => {
    const m = new THREE.Mesh(new RoundedBoxGeometry(w, h, d, 2, Math.min(h, d) * 0.28), woodMat);
    m.position.set(x, y, z);
    if (rx) m.rotation.x = rx;
    root.add(m);
    return m;
};

const RECLINE = 8 * Math.PI / 180;
for (const sx of [-0.74, 0.74]) {
    steel(0.038, 0.42, 0.038, sx, 0.21, 0.205);              // front leg
    steel(0.038, 0.42, 0.038, sx, 0.21, -0.205);             // rear leg
    steel(0.032, 0.030, 0.50, sx, 0.415, 0);                 // seat rail
    // Rear stanchion: bottom anchored on the rear leg, top reclined 8° back.
    const stLen = 0.47;
    steel(0.036, stLen, 0.036, sx, 0.44 + Math.cos(RECLINE) * stLen / 2,
          -0.205 - Math.sin(RECLINE) * stLen / 2, -RECLINE);
    steel(0.042, 0.022, 0.34, sx, 0.645, -0.135);
    const gussetLen = 0.13;
    steel(0.024, gussetLen, 0.024, sx, 0.585, -0.185, -0.85);
    // Foot pads ground the legs visually
    steel(0.056, 0.012, 0.056, sx, 0.006, 0.205);
    steel(0.056, 0.012, 0.056, sx, 0.006, -0.205);
}
// Under-seat cross stretcher
steel(1.42, 0.04, 0.032, 0, 0.30, 0);

for (let i = 0; i < 5; i++) {
    const z = 0.222 - i * (0.086 + 0.014);
    slat(1.70, 0.032, 0.086, 0, 0.443, z);
}

for (const y of [0.575, 0.700, 0.825]) {
    const z = -0.205 - (y - 0.44) * Math.tan(RECLINE) - 0.018 - 0.016;
    slat(1.70, 0.112, 0.030, 0, y, z, -RECLINE);
}

root.updateMatrixWorld(true);
const exporter = new GLTFExporter();
const glb = await exporter.parseAsync(root, { binary: true, onlyVisible: true });
writeFileSync(RAW, Buffer.from(glb));
const kb = (n) => (statSync(n).size / 1024).toFixed(0);
console.log(`raw GLB written: ${RAW} (${kb(RAW)} KB)`);

// ── Compress with the SAME toolchain flags as the other garden assets ───────
execSync(
    `npx @gltf-transform/cli optimize "${RAW}" "${OUT}" ` +
    `--texture-compress webp --compress draco --texture-size 1024`,
    { cwd: ROOT, stdio: 'inherit' }
);
unlinkSync(RAW);
console.log(`bench_01.glb written: ${OUT} (${kb(OUT)} KB)`);
