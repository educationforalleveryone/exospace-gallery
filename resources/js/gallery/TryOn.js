import * as THREE from 'three';

const TRYON_ID = 'tryon-1';

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

        remove();

        const sourceCanvas = anchor.userData._canvasMesh;
        const sourceFrame  = anchor.userData._frameMesh;

        const group = anchor.clone(true);

        const canvasMesh = group.getObjectByName('artwork-canvas') || group.children.find(c => c.name === 'artwork-canvas');
        const frameMesh  = group.children.find(c => c !== canvasMesh);

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

function readFileAsDataURL(file) {
    if ((file.size ?? 0) > 8 * 1024 * 1024) return Promise.resolve(null);
    return new Promise((resolve) => {
        const reader = new FileReader();
        reader.onload  = () => resolve(typeof reader.result === 'string' ? reader.result : null);
        reader.onerror = () => resolve(null);
        reader.readAsDataURL(file);
    });
}

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
