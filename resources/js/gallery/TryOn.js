// ─────────────────────────────────────────────────────────────────────────────
// TryOn — P3.1 spike "personal try-on" (roadmap P3.1, Iteration 7 "Frontier")
//
// "Upload one image, see it here" — the conversion killer, spiked behind the
// venue_try_on flag (default OFF; a spike, not a feature — §14 P3.1).
//
// HARD SAFETY CONTRACT (§14 P3.1: "client-side only, nothing persisted"):
//   1. ZERO NETWORK. This module performs no HTTP requests of any kind —
//      no remote calls, no form posts, no beacons, no messaging channels.
//      The uploaded image exists only as a data URL in page memory and as a
//      GPU texture. Nothing is stored, uploaded, or logged — the abuse review
//      answer is structural, not procedural.
//   2. PREVIEW-ONLY SURFACE. The preview controller exposes tryOnEnabled only
//      on the sample-only preview payload (venue pages, not customer galleries),
//      so the spike can never touch real exhibition state.
//   3. NO AUTHORED STATE MUTATION. The spike renders the upload as a CLONE of
//      an already-placed sample artwork (first hang — deterministic on every
//      layout), with its canvas material swapped to the uploaded texture.
//      Placement math, PRNG, and the sample set are never re-run or mutated.
//   4. REVERSIBLE + DISPOSABLE. apply() replaces the previous try-on clone
//      (one at a time); remove() disposes the cloned meshes and the GPU
//      texture. Two loads identical is untouched: nothing here runs before
//      the scene is ready and nothing here is random.
//
// DoD #7 (no slug-keyed JS): this module reads no venue identity — it clones
// whatever the interpreter already placed.
// ─────────────────────────────────────────────────────────────────────────────

import * as THREE from 'three';

const TRYON_ID = 'tryon-1';

/**
 * Wire the try-on affordance to a ready scene.
 *
 * @param {object} scene  a booted GalleryScene (scene.artworks populated)
 * @returns {{ apply: Function, remove: Function, dispose: Function }}
 *   controller object, also mirrored as window.exospaceTryOn for the
 *   blade's inline wiring.
 */
export function initTryOn(scene) {
    let current = null;   // { group, texture, material, frameMesh, canvasMesh }

    const anchorOf = () => (scene?.artworks?.length > 0 ? scene.artworks[0] : null);

    const remove = () => {
        if (!current) return;
        try {
            scene.remove(current.group);
            current.material.map = null;
            current.material.dispose();
            current.frameMesh?.traverse?.((o) => {
                if (o.geometry) o.geometry.dispose();
            });
            current.canvasMesh?.geometry?.dispose?.();
            // Free the GPU copy of the uploaded image.
            current.texture?.dispose?.();
        } catch {
            // A disposed scene must never break the page — swallow and reset.
        }
        current = null;
    };

    const apply = async (file) => {
        if (!file || !/^image\//.test(file.type || '')) return false;

        const anchor = anchorOf();
        if (!anchor?.userData?._canvasMesh) return false;

        const dataUrl = await readFileAsDataURL(file);
        if (!dataUrl) return false;

        const texture = await loadTexture(dataUrl);
        if (!texture) return false;

        // One try-on at a time: the previous clone (and its GPU texture)
        // dies the moment a new image arrives.
        remove();

        const sourceCanvas = anchor.userData._canvasMesh;
        const sourceFrame  = anchor.userData._frameMesh;

        // Clone the anchored group in place, then swap ONLY the canvas
        // material's map + aspect. Frame, wall, orientation, lighting —
        // everything the venue already composed — carries over untouched.
        const group = anchor.clone(true);

        const canvasMesh = group.getObjectByName('artwork-canvas') || group.children.find(c => c.name === 'artwork-canvas');
        const frameMesh  = group.children.find(c => c !== canvasMesh);

        // Fresh material instance (clone(true) shares materials) — otherwise
        // the swap would repaint the ORIGINAL sample artwork too.
        const material = sourceCanvas.material.clone();
        material.map = texture;
        material.needsUpdate = true;
        if (canvasMesh) {
            canvasMesh.material = material;
            resizeToAspect(canvasMesh, texture.image);
        }

        group.userData = {
            ...anchor.userData,
            type: 'artwork',
            id: TRYON_ID,
            title: 'Your artwork (local preview)',
            description: 'Uploaded in your browser for this preview only — never uploaded to any server.',
            _canvasMesh: canvasMesh,
            _frameMesh: frameMesh,
            _tryOn: true,
        };

        scene.add(group);
        current = { group, texture, material, frameMesh, canvasMesh };
        return true;
    };

    const dispose = () => {
        remove();
    };

    const controller = { apply, remove, dispose };
    if (typeof window !== 'undefined') {
        window.exospaceTryOn = controller;
    }
    return controller;
}

/**
 * Read a File as a data URL. Rejects non-images at the caller; rejects
 * oversize files here (8 MB — plenty for a preview texture, small enough
 * to keep a mobile page alive while decoding).
 */
function readFileAsDataURL(file) {
    if ((file.size ?? 0) > 8 * 1024 * 1024) return Promise.resolve(null);
    return new Promise((resolve) => {
        const reader = new FileReader();
        reader.onload  = () => resolve(typeof reader.result === 'string' ? reader.result : null);
        reader.onerror = () => resolve(null);
        reader.readAsDataURL(file);
    });
}

/**
 * Decode a data URL into a THREE.Texture. Resolves null on any failure —
 * a broken upload removes nothing and breaks nothing.
 */
function loadTexture(dataUrl) {
    return new Promise((resolve) => {
        const loader = new THREE.TextureLoader();
        loader.load(
            dataUrl,
            (texture) => {
                texture.colorSpace = THREE.SRGBColorSpace;
                texture.minFilter  = THREE.LinearMipmapLinearFilter;
                texture.magFilter  = THREE.LinearFilter;
                resolve(texture);
            },
            undefined,
            () => resolve(null),
        );
    });
}

/**
 * Match the canvas plane's proportions to the uploaded image (within the
 * same max bounds makeArtworkGroup uses: height ≤ 2 m, width ≤ 3 m) so the
 * clone sits like a real hang, not a stretched sticker.
 */
function resizeToAspect(canvasMesh, image) {
    if (!image?.width || !image?.height) return;
    const aspectRatio = image.width / image.height;
    const maxHeight = 2.0;
    const maxWidth  = 3.0;
    let height = maxHeight;
    let width  = height * aspectRatio;
    if (width > maxWidth) { width = maxWidth; height = width / aspectRatio; }
    canvasMesh.geometry?.dispose?.();
    canvasMesh.geometry = new THREE.PlaneGeometry(width, height);
}
