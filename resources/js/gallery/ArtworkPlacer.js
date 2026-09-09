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
    // 'float' hovers the canvases, 'garden' composes the CURATED WALK
    // (courts, hierarchy, approaches — see GardenLayout.js), and the legacy
    // default keeps the easel ring).
    if (this._venueLayoutShape === 'circular' || layout === 'circular') {
        if (this._venuePlacementMode === 'float') {
            _placeArtworksFloating.call(this, data);
        } else if (this._venuePlacementMode === 'garden') {
            _placeArtworksGarden.call(this, data);
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

// ── Square run plan (pure, shared by the square placer AND structure passes)
// ─────────────────────────────────────────────────────────────────────────────
// The framed-bay architecture (VenueDecorator 'bays' pass) must frame the
// hang it serves: fins stand exactly at the run boundaries, so STRUCTURE and
// PLACEMENT consume one split math — they can never disagree (the loft
// precedent, generalised). `distribute` = works placed on outer walls (the
// placer passes outerCount — imageCount when no bay surfaces exist; a
// structure pass passes imageCount). Byte-identical to the historic inline
// math of _placeArtworksSquare.
export function squareRunPlan(imageCount, distribute, spacing, wallCount,
                              minWallLength = CONFIG.room.minWallLength) {
    const imagesPerWall = Math.ceil(imageCount / wallCount);
    const wallLength    = Math.max(minWallLength, imagesPerWall * spacing + spacing);
    const runCounts = Array.from({ length: wallCount }, (_, i) =>
        Math.max(0, Math.min(imagesPerWall, distribute - i * imagesPerWall)));
    return { wallLength, imagesPerWall, runCounts };
}

// ── L-shape row plan (pure, shared by the l-shape placer AND structure passes)
// ─────────────────────────────────────────────────────────────────────────────
// Mirrors the alternating-face walk: rows step zStart→zLimit by `spacing`,
// two faces per row in wing A (outer wall face 0, inner wall face 1), the
// remainder spills into wing B along the x axis the same way. Face row
// counts derive from the same alternation (face f holds rows floor to
// ceil of half the wing's works), so a bay pass can frame both wings
// without a second placement implementation.
export function lshapeRowPlan(imageCount, zStart, zLimit, spacing) {
    let spillFrom = imageCount, sideA = 0, rowA = 0;
    for (let i = 0; i < imageCount; i++) {
        if (zStart + rowA * spacing >= zLimit) { spillFrom = i; break; }
        sideA = 1 - sideA;
        if (sideA === 0) rowA++;
    }
    const remaining = imageCount - spillFrom;
    return {
        spillFrom,
        remaining,
        rowsA:  Math.ceil(spillFrom / 2),   // wing A face 0 rows
        rowsA1: Math.floor(spillFrom / 2),  // wing A face 1 rows
        rowsB:  Math.ceil(remaining / 2),   // wing B face 0 rows
        rowsB1: Math.floor(remaining / 2),  // wing B face 1 rows
    };
}

// ── Wall-face standoff (pure, shared by every wall layout) ────────────────
// ARTWORK-BURIAL FIX (Industrial Loft forensic audit): the hang used to
// measure its standoff from the wall CENTRE plane with a constant 0.2 m —
// correct only for the historical wall_depth 0.3 (0.15 half + 0.05 gap).
// wall_depth is venue-configurable (visual_config.wall_depth), and the one
// venue that changed it — Industrial Loft, 0.5 m walls — buried every
// artwork 5 cm INSIDE the wall box: the venue hung glowing rectangles of
// pool light on visibly empty walls in preview AND public.
//
// The standoff is now derived from the wall geometry itself:
//   wallDepth/2 (centre → inner face) + 0.05 m clearance to the frame back.
// depth 0.3 → 0.20 (byte-identical to the historic constant, so White Cube
// and every 0.3-wall venue render unchanged); depth 0.5 → 0.30. The same
// helper is consumed by VenueDecorator's structure code so columns, coves
// and props always share ONE definition of "the wall face".
export function wallInset(depth = CONFIG.room.wallDepth) {
    return (depth || 0.3) / 2 + 0.05;
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
    // Room sizing comes from the SHARED pure helper — the 'bays' structure
    // pass consumes the same plan, so the framed architecture always wraps
    // the real hang (one math, never two).
    const { wallLength } = squareRunPlan(imageCount, imageCount, spacing, wallCount);
    const eyeLevel = CONFIG.camera.height;

    // Wall-face standoff — venue wall_depth aware (see wallInset above).
    const inset = wallInset();
    const walls = [
        { id: 'front', start: [-wallLength/2+spacing, eyeLevel, -wallLength/2+inset], dir:[1,0,0],  normal:[0,0,1]  },
        { id: 'back',  start: [ wallLength/2-spacing, eyeLevel,  wallLength/2-inset], dir:[-1,0,0], normal:[0,0,-1] },
        { id: 'left',  start: [-wallLength/2+inset,   eyeLevel,  wallLength/2-spacing], dir:[0,0,-1], normal:[1,0,0]  },
        { id: 'right', start: [ wallLength/2-inset,   eyeLevel, -wallLength/2+spacing], dir:[0,0,1],  normal:[-1,0,0] },
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

    // Per-wall run counts from the shared helper (the ceil split gives every
    // wall except the last a full run; the LAST wall receives the remainder,
    // and the offset math below centres EACH wall's ACTUAL run). Outer
    // distribution respects any bay take; the bays pass passes imageCount.
    const { runCounts } = squareRunPlan(imageCount, outerCount, spacing, hangWalls.length);

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
                    // v3: a surface may declare its own hang centre height
                    // (the above-the-fire hang on the double-height pier).
                    // Null ⇒ the placer's eye level (historic behaviour).
                    y: Number.isFinite(Number(surf.y)) ? Number(surf.y) : null,
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
    const inset = wallInset(); // venue wall_depth aware — see wallInset()
    const longWalls = [
        { start: [-length/2+spacing, eyeLevel, -width/2+inset], dir:[1,0,0],  normal:[0,0,1]  },
        { start: [ length/2-spacing, eyeLevel,  width/2-inset], dir:[-1,0,0], normal:[0,0,-1] },
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

    const inset = wallInset(); // venue wall_depth aware — see wallInset()
    const wA = [
        { x: inset,          normal: [1,0,0]  },
        { x: wingW - inset,  normal: [-1,0,0] },
    ];

    // Spill math from the SHARED pure helper (the 'bays' pass frames both
    // wings from the same plan). Artwork i sits on face i%2, row floor(i/2)
    // — identical to the historic mutating walk.
    const plan = lshapeRowPlan(all.length, zStart, zLimit, spacing);
    for (let i = 0; i < plan.spillFrom; i++) {
        const sideA = i % 2, rowA = Math.floor(i / 2);
        const w = wA[sideA];
        const candidateZ = zStart + rowA * spacing;
        const { group } = this.makeArtworkGroup(all[i], data);
        group.position.set(w.x, eyeLevel, candidateZ);
        group.lookAt(w.x + w.normal[0], eyeLevel, candidateZ + w.normal[2]);
        this.placeAndRegister(group, data);
    }

    const remaining = all.slice(plan.spillFrom);
    if (remaining.length === 0) return;

    // ── v3 "The Double Volume" (generic, two independent opt-ins) ─────────
    // 1. A glazed spill face holds NO artworks: the wing B north face is
    //    skipped when the venue opened it as the second glass face
    //    (this._glazingNorth) — the spill concentrates on the remaining
    //    solid face(s) instead of back lighting itself against the city.
    // 2. Hangable surfaces registered by structure (the double-height
    //    fireplace wall, an art wall) receive the LAST portion of the spill
    //    — the same bay mechanics the square placer has run since Iteration
    //    3, now on l-shape too (parity; ≤30% cap, ≥6-work hang). Venues
    //    without hangable surfaces and without the second glazing resolve
    //    EXACTLY as before (§11.3 rule 2).
    const wB = [
        { z: jZ + inset,     normal: [0,0,1]  },   // wing B south wall (solid)
        { z: lenA/2 - inset, normal: [0,0,-1] },   // wing B north face (skipped when glazed)
    ];
    const faces = this._glazingNorth ? wB.slice(0, 1) : wB;

    const bayPlan = _planBayHangs(all.length, this._hangableSurfaces, spacing);
    const bayCount = bayPlan ? bayPlan.length : 0;
    const wallShare = Math.max(0, remaining.length - bayCount);

    const xStart = wingW + spacing;
    const faceCount = faces.length;
    remaining.forEach((img, k) => {
        const { group } = this.makeArtworkGroup(img, data);
        if (k >= wallShare && bayPlan) {
            // Statement work into a registered hangable surface (its own
            // hang centre height — the above-the-fire hang on the tall
            // pier, the art wall in the volume).
            const b = bayPlan[k - wallShare];
            const y = b.y ?? eyeLevel;
            group.position.set(b.x, y, b.z);
            group.lookAt(b.x + b.nx, y, b.z + b.nz);
        } else {
            let candidateX;
            if (faceCount === 2) {
                // Historic alternation — lenB is SIZED for two faces, so the
                // row math always lands inside the wing (bit-identical).
                const rowB = Math.floor(k / 2);
                candidateX = xStart + rowB * spacing;
            } else {
                // GLAZED-FACE CASE: with a face removed the run would double
                // its length and overflow the wing (lenB was sized against
                // two faces). Spread the run evenly and CENTRED across the
                // wing's usable span instead — the salon-wall read, always
                // inside the building.
                const per = Math.ceil(wallShare / faceCount);
                const start = wingW + 1.5;
                const end = wingW + lenB - 1.5;
                const posInFace = Math.floor(k / faceCount);
                const step = per > 1 ? (end - start) / (per - 1) : 0;
                candidateX = start + posInFace * step;
            }
            const w = faces[k % faceCount];
            group.position.set(candidateX, eyeLevel, w.z);
            group.lookAt(candidateX + w.normal[0], eyeLevel, w.z + w.normal[2]);
        }
        this.placeAndRegister(group, data);
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

        // Add an easel under the artwork (procedural — tripod + crossbar).
        // canvasYaw from the lookAt above = polar angle + π; the easel leans
        // away from the viewer (v3 geometry contract).
        _addEasel.call(this, x, z, angle + Math.PI, 0, 1);
    });
}

// ── GARDEN placement (Sculpture Garden v4.0.0 — "The Sculpture Park") ─────
// Every artwork stands on its own COURT — a clearing the pure GardenLayout
// planner composed with a role (primary / secondary / transitional), an
// approach (the nearest walk) and a facing (the panel turns toward the
// visitor's arrival, not mechanically toward the centre). The pieces sit on
// the TERRAIN (ground height from the plan's own field) and are PHYSICAL:
// each registers a collision obstacle, so a visitor brushes up against a
// sculpture court instead of clipping through it (float-mode precedent).
//
// v4 PRESENTATION: the v2/v3 artist's tripod easel (three brown sticks)
// read as yard-sale signage — fatal to the "premium exhibition" read. Each
// work now stands on a MUSEUM PANEL STAND: two slim charcoal-steel posts, a
// grounded sled foot and a rear lean strut, plus a small label plaque —
// the architecture of an outdoor exhibition, not a painting prop. All
// stands share ONE unit geometry per part and render as 4 InstancedMeshes
// total (n artworks → 4 draw calls, was n merged easel meshes).
// If the plan and the artwork list ever disagree (a pathological build
// order), the legacy ring takes over — placement never guesses.
export function _placeArtworksGarden(data) {
    const plan = this._gardenPlan;
    if (!plan || plan.courts.length !== this.artworkImages.length) {
        console.warn('[garden] plan/artwork mismatch — falling back to the ring layout');
        _placeArtworksCircular.call(this, data);
        return;
    }
    const eyeLevel = CONFIG.camera.height;
    const standData = [];

    this.artworkImages.forEach((img, i) => {
        const c = plan.courts[i];
        const gy = plan.terrain.height(c.x, c.z);
        const { group } = this.makeArtworkGroup(img, data);
        group.position.set(c.x, gy + eyeLevel, c.z);
        group.rotation.y = c.facing;          // canvas front toward the approach
        group.scale.setScalar(c.scale);       // role hierarchy (§8), carefully
        this.placeAndRegister(group, data);
        // Physical presence — the padded AABB stops the visitor ~0.35 m
        // short of the canvas, exactly like the float venues.
        this.registerObstacle(group, 0.35);
        // Panel stand dims (same sizing contract as makeArtworkGroup)
        const aspect = img.aspectRatio || 1;
        let h = 2.0, w = h * aspect;
        if (w > 3.0) { w = 3.0; h = w / aspect; }
        standData.push({ x: c.x, z: c.z, yaw: c.facing, w, gy, scale: c.scale });
    });

    _addPanelStands.call(this, standData);
}

// ── Museum panel stands — instanced outdoor exhibition hardware ─────────
// Parts (unit geometry, composed per instance):
//   • 2 posts  — slim charcoal cylinders behind the canvas (±0.36·w),
//     leaning back 3° like real gallery stands
//   • 1 sled   — grounded foot bar spanning the posts
//   • 1 plaque — small label plate mounted on the right post
// n artworks → 4n instances across 3 draw calls. Low tier: Lambert, no
// shadows — silhouette identical.
export function _addPanelStands(standData) {
    if (!standData.length) return;
    const low = this.isLowEnd;
    const steelMat = low
        ? new THREE.MeshLambertMaterial({ color: 0x2b2a26 })
        : new THREE.MeshStandardMaterial({ color: 0x2b2a26, roughness: 0.6, metalness: 0.35 });
    const plaqueMat = low
        ? new THREE.MeshLambertMaterial({ color: 0x8f8a80 })
        : new THREE.MeshStandardMaterial({ color: 0x8f8a80, roughness: 0.35, metalness: 0.6 });

    const n = standData.length;
    const postGeo   = new THREE.CylinderGeometry(0.016, 0.021, 1.5, 8);
    const sledGeo   = new THREE.BoxGeometry(1, 0.045, 0.07);
    const plaqueGeo = new THREE.BoxGeometry(0.085, 0.055, 0.006);

    const posts   = new THREE.InstancedMesh(postGeo, steelMat, n * 2);
    const sleds   = new THREE.InstancedMesh(sledGeo, steelMat, n);
    const plaques = new THREE.InstancedMesh(plaqueGeo, plaqueMat, n);
    for (const im of [posts, sleds, plaques]) {
        im.frustumCulled = false;
        im.castShadow = !low;
        im.receiveShadow = !low;
        im.matrixAutoUpdate = false;
    }

    const M = new THREE.Matrix4();
    const Mroot = new THREE.Matrix4();
    const Mlocal = new THREE.Matrix4();
    const pos = new THREE.Vector3(), scl = new THREE.Vector3();
    const quat = new THREE.Quaternion(), euler = new THREE.Euler();

    // world = T(root) · R(rootYaw) · [ T(local) · R(lean) · S(localScale) ]
    const compose = (rootYaw, rootX, rootY, rootZ, lx, ly, lz,
                     lScaleX = 1, lScaleY = 1, lScaleZ = 1, leanX = 0) => {
        euler.set(0, rootYaw, 0);
        quat.setFromEuler(euler);
        pos.set(rootX, rootY, rootZ);
        scl.set(1, 1, 1);
        Mroot.compose(pos, quat, scl);
        euler.set(leanX, 0, 0);
        quat.setFromEuler(euler);
        pos.set(lx, ly, lz);
        scl.set(lScaleX, lScaleY, lScaleZ);
        Mlocal.compose(pos, quat, scl);
        M.multiplyMatrices(Mroot, Mlocal);
    };

    const LEAN = -0.055;   // posts lean back ~3° (negative X tips the top to −z)
    let pi = 0;
    standData.forEach((s, i) => {
        const hw = 0.36 * s.w;
        compose(s.yaw, s.x, s.gy, s.z, -hw, 0.75, -0.08, 1, 1, 1, LEAN);
        posts.setMatrixAt(pi++, M);
        compose(s.yaw, s.x, s.gy, s.z,  hw, 0.75, -0.08, 1, 1, 1, LEAN);
        posts.setMatrixAt(pi++, M);
        compose(s.yaw, s.x, s.gy, s.z, 0, 0.023, -0.055, hw * 2 + 0.26, 1, 1);
        sleds.setMatrixAt(i, M);
        // plaque on the right post at label height, tilted toward the viewer
        compose(s.yaw, s.x, s.gy, s.z, hw + 0.033, 0.9, -0.125, 1, 1, 1, -0.42);
        plaques.setMatrixAt(i, M);
    });
    posts.count = pi; sleds.count = n; plaques.count = n;
    for (const im of [posts, sleds, plaques]) {
        im.instanceMatrix.needsUpdate = true;
        this.scene.add(im);
    }
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
    const layout = computeFloatLayout(this.artworkImages.length, radius, rng, {
        depthBands: bandsWanted,
        // v2.2.0 (generic): visual_config.placement.elevation_step lifts each
        // inner band (metres) — the hang composes vertically as well as in
        // depth. Undeclared ⇒ 0 ⇒ every existing venue is bit-identical.
        elevationStep: Number(this._venuePlacement?.elevation_step) || 0,
    });

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

// ── Easel — two front legs, a leaning rear leg + a crossbar BEHIND the
// canvas, merged. Pure geometry — no external GLB dependency.
// PERF-D21 (3D audit F21): was 4 separate Meshes per easel (3 legs + bar) —
// a 30-artwork sculpture garden paid 120 draw calls for easels alone. Now
// one merged mesh per easel = 30 draw calls, identical silhouette.
//
// Sculpture Garden v3: the easel STANDS ON THE TERRAIN (groundY) and leans
// AWAY from the viewer (local +z points opposite the canvas front), with
// the crossbar behind the canvas plane — the canvas rests against the
// frame instead of the old bar poking through its plane at oblique ring
// angles (a latent v2 geometry defect this rebuild fixes).
export function _addEasel(x, z, canvasYaw, groundY = 0, scale = 1) {
    const woodMat = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: 0x6b4a2a })
        : new THREE.MeshStandardMaterial({ color: 0x6b4a2a, roughness: 0.85, metalness: 0.05 });

    const legGeo = new THREE.CylinderGeometry(0.042, 0.052, 2.0, 6);
    const barGeo = new THREE.BoxGeometry(0.8, 0.055, 0.055);

    const parts = [];
    // Local +z faces the SAME way as the canvas front (rotation.y =
    // canvasYaw below) — so every part lives at z <= 0, BEHIND the canvas
    // plane: two splayed legs just under the canvas, one rear leg leaning
    // back, and the crossbar the canvas rests on. The depth spread stays
    // small so an edge-on easel still reads as one legged stand.
    parts.push({ geo: legGeo, pos: [-0.3, 0.98, -0.04], rot: [-0.04, 0, 0.13] });
    parts.push({ geo: legGeo, pos: [ 0.3, 0.98, -0.04], rot: [-0.04, 0, -0.13] });
    parts.push({ geo: legGeo, pos: [ 0,    0.98, -0.3],  rot: [-0.2, 0, 0] });
    // Crossbar BEHIND the canvas plane (the canvas leans on it).
    parts.push({ geo: barGeo, pos: [0, 1.18, -0.12] });

    const merged = mergeParts(parts);
    legGeo.dispose();
    barGeo.dispose();

    const easel = new THREE.Mesh(merged, woodMat);
    easel.position.set(x, groundY, z);
    easel.rotation.y = canvasYaw;             // local +z = canvas front; parts sit behind the plane
    easel.scale.setScalar(0.9 + scale * 0.1); // primary courts carry a sturdier easel
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

    // ── Cyber Gallery iteration: movement-reactive canvas material ──────
    // When the venue declares visual_config.artwork_reactive, the canvas is
    // built as LIVING DIGITAL MEDIA: the SAME material class (Standard /
    // Basic per tier) with the glitch language injected via onBeforeCompile.
    // Keeping the class preserves PBR lighting, the focus highlight and the
    // progressive texture swap (applyArtworkTexture); undeclared venues take
    // the historic path byte-identically.
    const reactive = !!this._reactive;
    const canvasMat = this.isLowEnd
        ? new THREE.MeshBasicMaterial({ map: tex })
        : new THREE.MeshStandardMaterial({
            map: tex,
            roughness: 0.7,
            metalness: 0.0,
            // Add canvas normal map for tactile art texture
            ...(this.textures.canvasNormal ? { normalMap: this.textures.canvasNormal, normalScale: new THREE.Vector2(0.3, 0.3) } : {}),
        });
    if (reactive) {
        this.patchReactiveMaterial(canvasMat, img.id ?? `${this.artworks.length}`);
    }

    const canvas = new THREE.Mesh(canvasGeo, canvasMat);
    canvas.name = 'artwork-canvas';
    canvas.castShadow    = !this.isLowEnd;
    canvas.receiveShadow = !this.isLowEnd;

    // Luminous bezel — the display-methodology boundary: a thin emissive
    // plate slightly larger than the canvas, sitting just behind it, so the
    // artwork reads as an integrated digital display surface. One shared
    // material for the whole build (its quiet breathing is one color write
    // per frame — see ArtworkReactive.updateArtworkReactive).
    let bezel = null;
    if (reactive) {
        const bezelMat = this.makeReactiveBezelMaterial();
        if (bezelMat) {
            // Thin luminous rim — 0.045 m reads as a hairline of light around
            // the canvas at every viewing distance (0.075 read as a glowing
            // slab up close and its bloom halo washed the artwork out).
            const margin = 0.045;
            bezel = new THREE.Mesh(
                new THREE.PlaneGeometry(width + margin, height + margin),
                bezelMat
            );
            bezel.position.z = -0.012;   // behind the canvas plane
            bezel.renderOrder = -1;
            bezel.name = 'artwork-bezel';
        }
    }

    // Frame
    const frame = this.createFrame(width, height, data.frame_style);

    // ── REAR PRESENTATION FIX (garden-iteration-5) ──────────────────────────
    // The artwork used to be a single FrontSide plane inside a closed frame
    // ring: from the FRONT everything read correctly, but from the REAR the
    // canvas was backface-CULLED, so the frame showed an empty hole — an
    // accidental-looking broken object. Wall venues hide the rear against
    // the wall, but any venue where a visitor can legitimately walk around
    // a piece (the sculpture garden's panel stands, the floating void
    // placements, easel rings) exposed the hole. This is a GLOBAL artwork
    // pipeline property, so the fix lives here — in the shared artwork
    // group builder — NOT in any venue.
    //
    // What real exhibition practice does: a framed 2D work is backed. The
    // rear shows the frame + a neutral backing board, never the image and
    // never a void. So we add a backing plane just behind the canvas:
    //   • sized canvas+1.2 cm so it fully fills the frame's inner opening
    //     with a hairline overlap margin (no grazing-angle gaps)
    //   • 6 mm behind the canvas plane, inside the frame's 8 cm depth
    //   • rotated π so its face points REARWARD (FrontSide material, no
    //     culling surprises, no lighting flip)
    //   • ONE shared material for the whole scene (archival warm grey,
    //     fully matte) — 2 triangles and zero texture memory per artwork
    //
    // Deliberately NOT done instead: making the canvas DoubleSide would
    // mirror the artwork (wrong for anything containing text/signatures),
    // double-light the surface, and contradict exhibition practice. The
    // canvas stays FrontSide — its front presentation is untouched, the
    // progressive texture swap (applyArtworkTexture) is untouched, and the
    // Cyber Gallery reactive material/bezel path is untouched (the bezel
    // faces forward as before; from the rear it is occluded by the backing).
    let backingMat = this._artworkBackingMat;
    if (!backingMat) {
        backingMat = this._artworkBackingMat = this.isLowEnd
            ? new THREE.MeshLambertMaterial({ color: 0x8a847a })
            : new THREE.MeshStandardMaterial({ color: 0x8a847a, roughness: 0.95, metalness: 0.0 });
    }
    const backing = new THREE.Mesh(
        new THREE.PlaneGeometry(width + 0.012, height + 0.012),
        backingMat
    );
    backing.position.z = -0.006;          // just behind the canvas, inside the frame depth
    backing.rotation.y = Math.PI;         // faces the artwork's rear — intentional backing
    backing.name = 'artwork-backing';
    backing.castShadow = false;
    backing.receiveShadow = false;

    // Group
    const group = new THREE.Group();
    if (bezel) group.add(bezel);   // luminous boundary first — behind canvas
    group.add(frame);
    group.add(backing);            // rear presentation — intentional, never an empty frame
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
