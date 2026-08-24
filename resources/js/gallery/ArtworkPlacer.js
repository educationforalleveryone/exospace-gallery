// ─────────────────────────────────────────────────────────────────────────────
// ArtworkPlacer — places artworks on walls (square/corridor/l-shape/rotunda)
// or on easels (circular — sculpture garden + void venues)
// ─────────────────────────────────────────────────────────────────────────────

import * as THREE from 'three';
import { CONFIG, CIRCULAR_VENUES } from './config.js';

// ── Shared placeholder texture (PERF-C9) ─────────────────────────────────────
// A 1×1 dark-tinted texture used when neither the real artwork nor a
// thumbnail has arrived yet. Every canvas material is created WITH a map so
// that later swapping in the real texture never changes the shader program
// (map presence is part of the program cache key — creating materials
// map-less and adding maps later would recompile once per artwork).
let _placeholderTexture = null;
function getPlaceholderTexture() {
    if (!_placeholderTexture) {
        _placeholderTexture = new THREE.DataTexture(new Uint8Array([16, 16, 20, 255]), 1, 1);
        _placeholderTexture.colorSpace = THREE.SRGBColorSpace;
        _placeholderTexture.needsUpdate = true;
    }
    return _placeholderTexture;
}

// ── Top-level dispatcher ────────────────────────────────────────────────────
export function placeArtworks(data) {
    if (this.artworkImages.length === 0) return;

    const slug = this._venueSlug;
    const layout = (this._layoutMeta || {}).type || 'square';

    // Circular venues (sculpture garden + void venues) — easels
    if (CIRCULAR_VENUES.has(slug) || layout === 'circular') {
        _placeArtworksCircular.call(this, data);
        return;
    }

    if      (layout === 'corridor') { _placeArtworksCorridor.call(this, data); return; }
    else if (layout === 'l-shape')  { _placeArtworksLShape.call(this, data);   return; }
    else if (layout === 'rotunda')  { _placeArtworksRotunda.call(this, data);  return; }

    // ── SQUARE ────────────────────────────────────────────────────────────
    _placeArtworksSquare.call(this, data);
}

// ── SQUARE placement ─────────────────────────────────────────────────────────
export function _placeArtworksSquare(data) {
    const imageCount = this.artworkImages.length;
    const spacing    = CONFIG.room.artworkSpacing;
    const wallLength = Math.max(CONFIG.room.minWallLength, (Math.ceil(imageCount / 4) * spacing) + spacing);
    const imagesPerWall = Math.ceil(imageCount / 4);
    const eyeLevel = CONFIG.camera.height;

    const walls = [
        { start: [-wallLength/2+spacing, eyeLevel, -wallLength/2+0.2], dir:[1,0,0],  normal:[0,0,1]  },
        { start: [ wallLength/2-spacing, eyeLevel,  wallLength/2-0.2], dir:[-1,0,0], normal:[0,0,-1] },
        { start: [-wallLength/2+0.2,     eyeLevel,  wallLength/2-spacing], dir:[0,0,-1], normal:[1,0,0]  },
        { start: [ wallLength/2-0.2,     eyeLevel, -wallLength/2+spacing], dir:[0,0,1],  normal:[-1,0,0] },
    ];
    let wi = 0, pos = 0;
    this.artworkImages.forEach(img => {
        const wall = walls[wi];
        const { group } = this.makeArtworkGroup(img, data);
        const off = pos * spacing;
        group.position.set(wall.start[0]+wall.dir[0]*off, wall.start[1], wall.start[2]+wall.dir[2]*off);
        group.lookAt(group.position.x+wall.normal[0], group.position.y, group.position.z+wall.normal[2]);
        this.placeAndRegister(group, data);
        pos++;
        if (pos >= imagesPerWall) { pos = 0; wi = Math.min(wi+1, walls.length-1); }
    });
}

// ── CORRIDOR placement ───────────────────────────────────────────────────────
export function _placeArtworksCorridor(data) {
    const { length, width } = this._layoutMeta;
    const spacing = CONFIG.room.artworkSpacing;
    const eyeLevel = CONFIG.camera.height;
    const half = Math.ceil(this.artworkImages.length / 2);
    const longWalls = [
        { start: [-length/2+spacing, eyeLevel, -width/2+0.2], dir:[1,0,0],  normal:[0,0,1]  },
        { start: [ length/2-spacing, eyeLevel,  width/2-0.2], dir:[-1,0,0], normal:[0,0,-1] },
    ];
    let wi = 0, pos = 0;
    this.artworkImages.forEach(img => {
        const wall = longWalls[wi];
        const { group } = this.makeArtworkGroup(img, data);
        const off = pos * spacing;
        group.position.set(wall.start[0]+wall.dir[0]*off, wall.start[1], wall.start[2]+wall.dir[2]*off);
        group.lookAt(group.position.x+wall.normal[0], group.position.y, group.position.z+wall.normal[2]);
        this.placeAndRegister(group, data);
        pos++;
        if (pos >= half) { pos = 0; wi = Math.min(wi+1, 1); }
    });
}

// ── L-SHAPE placement ────────────────────────────────────────────────────────
export function _placeArtworksLShape(data) {
    const { wingW, lenA, lenB, jZ, zStart, zLimit } = this._layoutMeta;
    const spacing  = CONFIG.room.artworkSpacing;
    const eyeLevel = CONFIG.camera.height;
    const all = this.artworkImages;

    const wA = [
        { x: 0.2,       normal: [1,0,0]  },
        { x: wingW-0.2, normal: [-1,0,0] },
    ];

    let spillFrom = all.length;
    let sideA = 0, rowA = 0;
    for (let i = 0; i < all.length; i++) {
        const candidateZ = zStart + rowA * spacing;
        if (candidateZ >= zLimit) { spillFrom = i; break; }
        const w = wA[sideA];
        const { group } = this.makeArtworkGroup(all[i], data);
        group.position.set(w.x, eyeLevel, candidateZ);
        group.lookAt(w.x + w.normal[0], eyeLevel, candidateZ + w.normal[2]);
        this.placeAndRegister(group, data);
        sideA = 1 - sideA;
        if (sideA === 0) rowA++;
    }

    const remaining = all.slice(spillFrom);
    if (remaining.length === 0) return;

    const wB = [
        { z: jZ + 0.2,      normal: [0,0,1]  },
        { z: lenA/2 - 0.2,  normal: [0,0,-1] },
    ];
    const xStart = wingW + spacing;
    let sideB = 0, rowB = 0;
    remaining.forEach(img => {
        const w = wB[sideB];
        const candidateX = xStart + rowB * spacing;
        const { group } = this.makeArtworkGroup(img, data);
        group.position.set(candidateX, eyeLevel, w.z);
        group.lookAt(candidateX + w.normal[0], eyeLevel, w.z + w.normal[2]);
        this.placeAndRegister(group, data);
        sideB = 1 - sideB;
        if (sideB === 0) rowB++;
    });
}

// ── ROTUNDA placement ────────────────────────────────────────────────────────
export function _placeArtworksRotunda(data) {
    const radius   = this._rotundaRadius;
    const n        = this.artworkImages.length;
    const eyeLevel = CONFIG.camera.height;
    this.artworkImages.forEach((img, i) => {
        const angle = (i / n) * Math.PI * 2;
        const { group } = this.makeArtworkGroup(img, data);
        group.position.set(Math.sin(angle)*(radius-0.3), eyeLevel, Math.cos(angle)*(radius-0.3));
        group.lookAt(0, eyeLevel, 0);
        this.placeAndRegister(group, data);
    });
}

// ── CIRCULAR placement (NEW — sculpture garden + void venues) ────────────────
// Artworks are placed on easels along the perimeter of the circle, facing inward.
// Each easel is a tripod + canvas frame built procedurally — no GLB required.
export function _placeArtworksCircular(data) {
    const radius   = this._layoutMeta.radius;
    const n        = this.artworkImages.length;
    const eyeLevel = CONFIG.camera.height;

    this.artworkImages.forEach((img, i) => {
        const angle = (i / n) * Math.PI * 2;
        const x = Math.sin(angle) * (radius - 1.5);
        const z = Math.cos(angle) * (radius - 1.5);

        const { group } = this.makeArtworkGroup(img, data);
        group.position.set(x, eyeLevel, z);
        group.lookAt(0, eyeLevel, 0);
        this.placeAndRegister(group, data);

        // Add an easel under the artwork (procedural — tripod legs + crossbar)
        _addEasel.call(this, x, z, angle);
    });
}

// ── Easel — three angled legs + horizontal crossbar ──────────────────────────
// Pure geometry — no external GLB dependency.
export function _addEasel(x, z, angle) {
    const woodMat = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: 0x6b4a2a })
        : new THREE.MeshStandardMaterial({ color: 0x6b4a2a, roughness: 0.85, metalness: 0.05 });

    const easel = new THREE.Group();

    // Three legs angled outward
    const legGeo = new THREE.CylinderGeometry(0.04, 0.04, 2.0, 6);
    for (let i = 0; i < 3; i++) {
        const legAngle = (i / 3) * Math.PI * 2;
        const leg = new THREE.Mesh(legGeo, woodMat);
        // Tilt each leg outward at the bottom
        leg.position.set(
            Math.sin(legAngle) * 0.25,
            1.0,
            Math.cos(legAngle) * 0.25
        );
        leg.rotation.x = Math.cos(legAngle) * 0.15;
        leg.rotation.z = -Math.sin(legAngle) * 0.15;
        easel.add(leg);
    }

    // Crossbar — horizontal support under the canvas
    const barGeo = new THREE.BoxGeometry(0.7, 0.04, 0.04);
    const bar = new THREE.Mesh(barGeo, woodMat);
    bar.position.set(0, 1.2, 0);
    easel.add(bar);

    easel.position.set(x, 0, z);
    easel.rotation.y = -angle + Math.PI;
    easel.traverse(c => { if (c.isMesh) { c.castShadow = !this.isLowEnd; c.receiveShadow = !this.isLowEnd; } });
    this.scene.add(easel);
}

// ── Build a single artwork group (canvas + frame + light) ────────────────────
// PERF-C9 (3D audit F9): the canvas material may be created from the real
// texture, the blur-up thumbnail, or the dark placeholder — depending on
// where we are in the progressive load. The mesh is named + registered in
// group.userData._canvasMesh so applyArtworkTexture() can find it when the
// full-quality texture streams in.
export function makeArtworkGroup(img, data) {
    const aspectRatio = img.aspectRatio || 1;
    const maxHeight   = 2.0;
    const maxWidth    = 3.0;
    let height = maxHeight;
    let width  = height * aspectRatio;
    if (width > maxWidth) { width = maxWidth; height = width / aspectRatio; }

    // Canvas (the artwork itself) — real texture → thumb → dark placeholder
    const tex = img.texture || img.thumbTexture || getPlaceholderTexture();

    const canvasGeo = new THREE.PlaneGeometry(width, height);
    const canvasMat = this.isLowEnd
        ? new THREE.MeshBasicMaterial({ map: tex })
        : new THREE.MeshStandardMaterial({
            map: tex,
            roughness: 0.7,
            metalness: 0.0,
            // Add canvas normal map for tactile art texture
            ...(this.textures.canvasNormal ? { normalMap: this.textures.canvasNormal, normalScale: new THREE.Vector2(0.3, 0.3) } : {}),
        });
    const canvas = new THREE.Mesh(canvasGeo, canvasMat);
    canvas.name = 'artwork-canvas';
    canvas.castShadow    = !this.isLowEnd;
    canvas.receiveShadow = !this.isLowEnd;

    // Frame
    const frame = this.createFrame(width, height, data.frame_style);

    // Group
    const group = new THREE.Group();
    group.add(frame);
    group.add(canvas);
    group.userData = {
        type: 'artwork',
        id: img.id,
        title: img.title || img.original_name || 'Untitled',
        description: img.description,
        // Lookup handle for progressive texture swaps (AssetLoader phase B)
        _canvasMesh: canvas,
        // Round-trip metadata for the info panel
        ...img,
    };

    return { group };
}

// ── Progressive texture swap (PERF-C9) ───────────────────────────────────────
// Called by AssetLoader when a background-streamed artwork texture arrives.
// Swaps the map on the existing canvas material — same material class, same
// map slot → no shader recompile, the artwork simply sharpens into place.
export function applyArtworkTexture(img) {
    if (!img || !img.texture || !this.artworks) return;

    const group = this.artworks.find(a => a.userData.id === img.id);
    const canvasMesh = group?.userData?._canvasMesh;
    if (!canvasMesh || !canvasMesh.material) return;

    canvasMesh.material.map = img.texture;
    // Safe no-op when a map was already present (thumb/placeholder); forces
    // uniform rebind if the placeholder path ever changes.
    canvasMesh.material.needsUpdate = true;
}

// ── Register artwork in the scene + add proximity light ──────────────────────
export function placeAndRegister(group, data) {
    this.scene.add(group);
    this.artworks.push(group);
    this.addArtworkLight(group, data.lighting_preset || 'bright');
}
