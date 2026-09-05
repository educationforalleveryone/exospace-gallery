// ─────────────────────────────────────────────────────────────────────────────
// ArtworkPlacer — places artworks on walls (square/corridor/l-shape/rotunda),
// on easels (circular — sculpture garden), or FLOATING in space (void venues).
//
// Iteration 2 "Phenomena" (roadmap P1.2 / §10.5): the hang style is a
// PLACEMENT MODE read from the venue's config (visual_config.placement_mode),
// not slug membership. "Floating artworks in an endless environment" becomes
// literally true for the void family; the sculpture garden keeps its easels
// BY IDENTITY (§4.10) because it declares no mode. No new slug knowledge is
// introduced here (DoD rule #7 — the CIRCULAR_VENUES slug set is DELETED in
// the Iteration 6 consolidation; layout is config-declared).
// ─────────────────────────────────────────────────────────────────────────────

import * as THREE from 'three';
import { CONFIG } from './config.js';
import { mergeParts } from './GeometryUtils.js';
import { computeFloatLayout } from './PlacementMath.js';
import { createVenueRng, venueSeedSource } from './Rng.js';
import { pairByOrientation, focalWallOf, isFocalHero, FOCAL } from './PlacementCuration.js';

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

    const layout = (this._layoutMeta || {}).type || 'square';

    // Circular venues (sculpture garden + void venues) — DECLARED via
    // visual_config.layout_shape since Iteration 6 (the CIRCULAR_VENUES
    // slug set is deleted; §10.5 placement modes unchanged: config-declared
    // 'float' hovers the canvases, the legacy default keeps the easel ring).
    if (this._venueLayoutShape === 'circular' || layout === 'circular') {
        if (this._venuePlacementMode === 'float') {
            _placeArtworksFloating.call(this, data);
        } else {
            _placeArtworksCircular.call(this, data);
        }
        return;
    }

    if      (layout === 'corridor') { _placeArtworksCorridor.call(this, data); return; }
    else if (layout === 'l-shape')  { _placeArtworksLShape.call(this, data);   return; }
    else if (layout === 'rotunda')  { _placeArtworksRotunda.call(this, data);  return; }

    // ── SQUARE ────────────────────────────────────────────────────────────
    _placeArtworksSquare.call(this, data);
}

// ── Centered wall-run offset (pure, shared by square + corridor) ─────────
// A run of `runCount` works on a wall spans `spacing` between neighbours; the
// RUN CENTRE belongs on the wall centre. The old fixed offset (spacing from
// the corner) centred only FULL runs — a 1-work wall hung its piece 0.5–1.75 m
// off centre (a one-artwork show looked accidental). Extracted so the QA
// suite can pin the invariant (scripts/venue-qa).
export function wallRunOffset(runCount, posInRun, spacing, wallLength) {
    if (runCount <= 0) return 0;
    return (wallLength / 2) - ((runCount - 1) * spacing) / 2 + posInRun * spacing;
}

// ── SQUARE placement ─────────────────────────────────────────────────────────
// Iteration 3 additions (both config-driven, zero slug knowledge):
//   1. A glazing wall (visual_config.glazing_wall — RoomBuilder removed the
//      wall and sized the room for three) holds NO artworks; the same 3-wall
//      math is mirrored here so placement and room can never disagree.
//   2. Bay redistribution (§4.4): surfaces registered by structure as
//      `hangable` (Museum divider faces — StructureBuilder/VenueDecorator)
//      receive the LAST portion of the hang, so the bays gain works instead
//      of holding none while the outer walls hold thirty.
// Iteration 6 curation (P2.3 §6.3–§6.5, all opt-in — no keys ⇒ identity):
//   3. Orientation pairing (placement.pair_orientation) interleaves
//      portrait/landscape inside wall runs so mixed walls read composed.
//   4. Focal wall (placement.focal_wall) gives the FIRST outer-wall piece on
//      that wall the hero treatment (scale + stronger pool, FOCAL consts);
//      every other piece stays equal. Bay pieces never qualify.
export function _placeArtworksSquare(data) {
    const imageCount = this.artworkImages.length;
    const spacing    = CONFIG.room.artworkSpacing;
    const glazingWallId = this._glazing ? this._glazing.wallId : null;
    const wallCount  = glazingWallId ? 3 : 4;
    const wallLength = Math.max(CONFIG.room.minWallLength, (Math.ceil(imageCount / wallCount) * spacing) + spacing);
    const imagesPerWall = Math.ceil(imageCount / wallCount);
    const eyeLevel = CONFIG.camera.height;

    const walls = [
        { id: 'front', start: [-wallLength/2+spacing, eyeLevel, -wallLength/2+0.2], dir:[1,0,0],  normal:[0,0,1]  },
        { id: 'back',  start: [ wallLength/2-spacing, eyeLevel,  wallLength/2-0.2], dir:[-1,0,0], normal:[0,0,-1] },
        { id: 'left',  start: [-wallLength/2+0.2,     eyeLevel,  wallLength/2-spacing], dir:[0,0,-1], normal:[1,0,0]  },
        { id: 'right', start: [ wallLength/2-0.2,     eyeLevel, -wallLength/2+spacing], dir:[0,0,1],  normal:[-1,0,0] },
    ];
    const hangWalls = glazingWallId ? walls.filter(w => w.id !== glazingWallId) : walls;

    const curation  = this._venuePlacement || {};
    const focalWall = focalWallOf(curation);
    // Orientation pairing (§6.4): a stable index permutation. With the key
    // absent this is the identity — hang order untouched (default unchanged).
    const order = curation.pair_orientation === true
        ? pairByOrientation(this.artworkImages)
        : this.artworkImages.map((_, i) => i);

    const bayPlan = _planBayHangs(imageCount, this._hangableSurfaces, spacing);
    const outerCount = imageCount - (bayPlan ? bayPlan.length : 0);

    // Per-wall run counts — the ceil split gives every wall except the last a
    // full run; the LAST wall receives the remainder (possibly smaller). The
    // offset math below centres EACH wall's ACTUAL run, so a remainder run no
    // longer hangs skewed toward the wall's start corner.
    const runCounts = hangWalls.map((_, i) =>
        Math.max(0, Math.min(imagesPerWall, outerCount - i * imagesPerWall))
    );

    let bayIdx = 0;
    let wi = 0, pos = 0;
    let focalHeroTaken = false;

    for (const imgIdx of order) {
        const img = this.artworkImages[imgIdx];
        const { group } = this.makeArtworkGroup(img, data);
        if (imgIdx >= outerCount) {
            const b = bayPlan[bayIdx++];
            group.position.set(b.x, eyeLevel, b.z);
            group.lookAt(b.x + b.nx, eyeLevel, b.z + b.nz);
        } else {
            const wall = hangWalls[wi];
            const runLen  = runCounts[wi];
            // Centered run: the run's midpoint sits on the wall's centreline.
            // wallRunOffset measures from the wall CORNER; wall.start already
            // embeds corner + spacing, so the delta is (c − spacing) — which
            // reduces to the historic `pos * spacing` for full runs.
            const off = wallRunOffset(runLen, pos, spacing, wallLength) - spacing;
            group.position.set(wall.start[0]+wall.dir[0]*off, wall.start[1], wall.start[2]+wall.dir[2]*off);
            group.lookAt(group.position.x+wall.normal[0], group.position.y, group.position.z+wall.normal[2]);
            // Wall metadata (Iteration 6): consumed by ArrivalMath's focal
            // hero bias and useful to studio tooling. Pure metadata — zero
            // rendering impact without a declared focal wall.
            group.userData.wallId = wall.id;
            pos++;
            if (pos >= runLen) { pos = 0; wi = Math.min(wi+1, hangWalls.length-1); }
        }
        this.placeAndRegister(group, data);

        // Focal hero treatment (§6.5) — exactly ONE piece per hang: the
        // first outer-wall piece on the declared focal wall.
        if (!focalHeroTaken && imgIdx < outerCount &&
            isFocalHero(focalWall, group.userData.wallId, false)) {
            group.scale.multiplyScalar(FOCAL.scaleBoost);
            const ud = group.userData;
            if (ud.lightMax != null)  ud.lightMax  *= FOCAL.lightBoost;
            if (ud.lightBase != null) ud.lightBase *= FOCAL.lightBoost;
            focalHeroTaken = true;
        }
    }
}

// ── Bay-hang planner (Iteration 3, pure) ─────────────────────────────────────
// Round-robin over registered hangable surfaces (capacity per surface =
// floor(width / spacing)); offsets spread slots evenly across each face.
// Deterministic: surface registration order + fixed assignment pattern.
// Keeps the MAJORITY of the hang on the outer walls (≤30% into the bays).
export function _planBayHangs(n, surfaces, spacing) {
    if (!n || !Array.isArray(surfaces) || surfaces.length === 0 || n < 6) return null;
    const caps = surfaces.map(s => Math.max(1, Math.floor((s.width || 0) / spacing)));
    const total = caps.reduce((a, b) => a + b, 0);
    const take = Math.min(total, Math.floor(n * 0.3));
    if (take <= 0) return null;

    const plan = [];
    let assigned = 0, pass = 0;
    while (assigned < take) {
        let placedThisPass = false;
        for (let s = 0; s < surfaces.length && assigned < take; s++) {
            if (pass < caps[s]) {
                const surf = surfaces[s];
                const per  = caps[s];
                const off  = (pass - (per - 1) / 2) * Math.min(spacing, surf.width / per);
                const tx = -surf.nz, tz = surf.nx;   // tangent (normal × up)
                plan.push({
                    x: surf.x + tx * off + surf.nx * 0.02,
                    z: surf.z + tz * off + surf.nz * 0.02,
                    nx: surf.nx, nz: surf.nz,
                });
                assigned++;
                placedThisPass = true;
            }
        }
        if (!placedThisPass) break;
        pass++;
    }
    return plan.length ? plan : null;
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
    // Per-wall run counts (wall B receives the odd remainder) — each run is
    // centred on its wall so an odd-count hang does not skew toward one end.
    const runCounts = [Math.min(half, this.artworkImages.length),
                       Math.max(0, this.artworkImages.length - half)];
    let wi = 0, pos = 0;
    this.artworkImages.forEach(img => {
        const wall = longWalls[wi];
        const { group } = this.makeArtworkGroup(img, data);
        const off = wallRunOffset(runCounts[wi], pos, spacing, length) - spacing;
        group.position.set(wall.start[0]+wall.dir[0]*off, wall.start[1], wall.start[2]+wall.dir[2]*off);
        group.lookAt(group.position.x+wall.normal[0], group.position.y, group.position.z+wall.normal[2]);
        this.placeAndRegister(group, data);
        pos++;
        if (pos >= runCounts[wi]) { pos = 0; wi = Math.min(wi+1, 1); }
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

// ── FLOAT placement (Iteration 2 — void family; depth bands since the
//    Infinite Void deepening) ─────────────────────────────────────────────
// Artworks hover in space — no easel, no stand, no wires. Each piece gets a
// seeded radial wander, a seeded hover height inside the legibility band
// (1.6 m ± 0.45) and a seeded roll around its view axis, all from the
// venue's seeded rng — the composition is identical on every load
// (Iteration 0's determinism contract extends to placement).
//
// Depth bands (visual_config.placement.depth_bands, interpreted by the pure
// PlacementMath module): larger collections compose in DEPTH — an outer ring
// plus inner rings stepping toward the centre — so walking reveals parallax
// and the hang reads as a constellation rather than a fence. The radius was
// sized for the same band count (RoomBuilder via computeFloatFieldRadius);
// the two can never disagree.
//
// Floating pieces register as collision obstacles: in a wall gallery the
// wall stops the visitor; in a void the artwork itself is the only physical
// thing at eye height, and gliding THROUGH a canvas shatters the fiction.
// Gated on float mode — wall/easel venues keep their historic behaviour.
export function _placeArtworksFloating(data) {
    const radius = this._layoutMeta.radius;

    // addVenueStructure created this._venueRng BEFORE placement runs
    // (RoomBuilder calls it for every circular venue first). The fallback
    // only exists so a pathological call order can never crash — it draws
    // from the same seed and is therefore still deterministic.
    const rng = this._venueRng || createVenueRng(venueSeedSource(this._venueSlug));
    const bandsWanted = Math.max(1, Math.floor(this._venuePlacement?.depth_bands || 1));
    const layout = computeFloatLayout(this.artworkImages.length, radius, rng, { depthBands: bandsWanted });

    this.artworkImages.forEach((img, i) => {
        const p = layout[i];
        const { group } = this.makeArtworkGroup(img, data);
        group.position.set(p.x, p.y, p.z);
        group.lookAt(0, p.y, 0);   // face the centre at its own hover height
        group.rotateZ(p.roll);     // seeded roll in the canvas plane
        this.placeAndRegister(group, data);
        // Physical presence: the padded AABB (Collisions) blocks the visitor
        // ~0.3 m short of the frame — walking up to a floating work still
        // feels close (focus distance is 1.8 m); walking through is gone.
        this.registerObstacle(group, 0.3);
    });
}

// ── Easel — three angled legs + horizontal crossbar, merged ─────────────────
// Pure geometry — no external GLB dependency.
// PERF-D21 (3D audit F21): was 4 separate Meshes per easel (3 legs + bar) —
// a 30-artwork sculpture garden paid 120 draw calls for easels alone. Now
// one merged mesh per easel = 30 draw calls, identical silhouette.
export function _addEasel(x, z, angle) {
    const woodMat = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: 0x6b4a2a })
        : new THREE.MeshStandardMaterial({ color: 0x6b4a2a, roughness: 0.85, metalness: 0.05 });

    const legGeo = new THREE.CylinderGeometry(0.04, 0.04, 2.0, 6);
    const barGeo = new THREE.BoxGeometry(0.7, 0.04, 0.04);

    // Three legs angled outward + crossbar under the canvas — one geometry
    const parts = [];
    for (let i = 0; i < 3; i++) {
        const legAngle = (i / 3) * Math.PI * 2;
        parts.push({
            geo: legGeo,
            // Tilt each leg outward at the bottom
            pos: [Math.sin(legAngle) * 0.25, 1.0, Math.cos(legAngle) * 0.25],
            rot: [Math.cos(legAngle) * 0.15, 0, -Math.sin(legAngle) * 0.15],
        });
    }
    parts.push({ geo: barGeo, pos: [0, 1.2, 0] });

    const merged = mergeParts(parts);
    legGeo.dispose();
    barGeo.dispose();

    const easel = new THREE.Mesh(merged, woodMat);
    easel.position.set(x, 0, z);
    easel.rotation.y = -angle + Math.PI;
    easel.castShadow    = !this.isLowEnd;
    easel.receiveShadow = !this.isLowEnd;
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
        // Lookup handles for progressive swaps (AssetLoader phase B) and
        // the focus highlight (FocusMode)
        _canvasMesh: canvas,
        _frameMesh: frame,
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

    // PERF-D22 (3D audit F22): the blur-up thumbnail was uploaded to the GPU
    // while it served as the material map. Once the full-quality texture
    // replaces it, nothing references it — but a THREE.Texture keeps its GPU
    // copy until disposed. Free it now: on a 30-artwork gallery that's 30
    // ~400px textures returned to the browser's GPU memory pool.
    if (img.thumbTexture) {
        img.thumbTexture.dispose();
        img.thumbTexture = null;
    }
}

// ── Register artwork in the scene + add proximity light ──────────────────────
export function placeAndRegister(group, data) {
    this.scene.add(group);
    this.artworks.push(group);
    this.addArtworkLight(group, data.lighting_preset || 'bright');
}
