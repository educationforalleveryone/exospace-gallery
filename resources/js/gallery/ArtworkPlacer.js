import * as THREE from 'three';
import { CONFIG } from './config.js';
import { mergeParts } from './GeometryUtils.js';
import { computeFloatLayout } from './PlacementMath.js';
import { createVenueRng, venueSeedSource } from './Rng.js';
import { pairByOrientation, focalWallOf, isFocalHero, FOCAL, resolveSquareHang, resolveDividerHang } from './PlacementCuration.js';

let _placeholderTexture = null;
function getPlaceholderTexture() {
    if (!_placeholderTexture) {
        _placeholderTexture = new THREE.DataTexture(new Uint8Array([16, 16, 20, 255]), 1, 1);
        _placeholderTexture.colorSpace = THREE.SRGBColorSpace;
        _placeholderTexture.needsUpdate = true;
    }
    return _placeholderTexture;
}

export function placeArtworks(data) {
    if (this.artworkImages.length === 0) return;

    const layout = (this._layoutMeta || {}).type || 'square';

    if (this._venueLayoutShape === 'circular' || layout === 'circular') {
        if (this._venuePlacementMode === 'float') {
            _placeArtworksFloating.call(this, data);
        } else if (this._venuePlacementMode === 'garden') {
            _placeArtworksGarden.call(this, data);
        } else if (this._venuePlacementMode === 'lake') {
            _placeArtworksLake.call(this, data);
        } else {
            _placeArtworksCircular.call(this, data);
        }
        return;
    }

    if      (layout === 'corridor') { _placeArtworksCorridor.call(this, data); return; }
    else if (layout === 'l-shape')  { _placeArtworksLShape.call(this, data);   return; }
    else if (layout === 'rotunda')  { _placeArtworksRotunda.call(this, data);  return; }

    _placeArtworksSquare.call(this, data);
}

export function wallRunOffset(runCount, posInRun, spacing, wallLength) {
    if (runCount <= 0) return 0;
    return (wallLength / 2) - ((runCount - 1) * spacing) / 2 + posInRun * spacing;
}

export function squareRunPlan(imageCount, distribute, spacing, wallCount,
                              minWallLength = CONFIG.room.minWallLength) {
    const imagesPerWall = Math.ceil(imageCount / wallCount);
    const wallLength    = Math.max(minWallLength, imagesPerWall * spacing + spacing);
    const runCounts = Array.from({ length: wallCount }, (_, i) =>
        Math.max(0, Math.min(imagesPerWall, distribute - i * imagesPerWall)));
    return { wallLength, imagesPerWall, runCounts };
}

export function squareLinePlan(imageCount, spacing, wallCount, minWallLength, hang) {
    const rows    = (hang && hang.rows > 1) ? hang.rows : 1;
    const perLine = (hang && hang.perLine > 0)
        ? hang.perLine
        : Math.ceil(imageCount / wallCount);
    const wallLength = Math.max(minWallLength, perLine * spacing + spacing);
    const lines = [];
    const lineCount = wallCount * rows;
    for (let i = 0; i < lineCount; i++) {
        const c = Math.min(perLine, Math.max(0, imageCount - i * perLine));
        lines.push(c);
        if (c <= 0 && i >= wallCount) break;   // row-1 lines may stay empty
    }
    while (lines.length < lineCount) lines.push(0);
    return { wallLength, rows, perLine, lines };
}

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

export function wallInset(depth = CONFIG.room.wallDepth) {
    return (depth || 0.3) / 2 + 0.05;
}

export function _placeArtworksSquare(data) {
    const imageCount = this.artworkImages.length;
    const spacing    = CONFIG.room.artworkSpacing;
    const glazingWallId = this._glazing ? this._glazing.wallId : null;
    const wallCount  = glazingWallId ? 3 : 4;
    const curation  = this._venuePlacement || {};
    const focalWall = focalWallOf(curation);
    const order = curation.pair_orientation === true
        ? pairByOrientation(this.artworkImages)
        : this.artworkImages.map((_, i) => i);

    if (curation.room_divider && typeof curation.room_divider === 'object' && !glazingWallId) {
        _placeArtworksSquareDivider.call(this, data, { order, focalWall });
        return;
    }

    const hang = resolveSquareHang(curation, imageCount, wallCount, spacing,
                                   CONFIG.room.minWallLength);
    if ((hang.rows > 1 || hang.keep) && !glazingWallId) {
        _placeArtworksSquareRows.call(this, data, { hang, order, focalWall });
        return;
    }

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

    const bayPlan = _planBayHangs(imageCount, this._hangableSurfaces, spacing);
    const outerCount = imageCount - (bayPlan ? bayPlan.length : 0);

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
            const off = wallRunOffset(runLen, pos, spacing, wallLength) - spacing;
            group.position.set(wall.start[0]+wall.dir[0]*off, wall.start[1], wall.start[2]+wall.dir[2]*off);
            group.lookAt(group.position.x+wall.normal[0], group.position.y, group.position.z+wall.normal[2]);
            group.userData.wallId = wall.id;
            pos++;
            if (pos >= runLen) { pos = 0; wi = Math.min(wi+1, hangWalls.length-1); }
        }
        this.placeAndRegister(group, data);

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

export const SALON_ROW_CAPS = Object.freeze([
    { maxWidth: 2.4, maxHeight: 1.45 },   // eye line
    { maxWidth: 1.7, maxHeight: 0.84 },   // upper line
]);

export function _placeArtworksSquareRows(data, ctx) {
    const { hang, order, focalWall } = ctx;
    const imageCount = this.artworkImages.length;
    const spacing    = CONFIG.room.artworkSpacing;
    const eyeLevel   = CONFIG.camera.height;

    const plan = squareLinePlan(imageCount, spacing, 4, CONFIG.room.minWallLength, hang);
    const wallLength = plan.wallLength;
    const inset = wallInset();
    const walls = [
        { id: 'front', start: [-wallLength/2+spacing, eyeLevel, -wallLength/2+inset], dir:[1,0,0],  normal:[0,0,1]  },
        { id: 'back',  start: [ wallLength/2-spacing, eyeLevel,  wallLength/2-inset], dir:[-1,0,0], normal:[0,0,-1] },
        { id: 'left',  start: [-wallLength/2+inset,   eyeLevel,  wallLength/2-spacing], dir:[0,0,-1], normal:[1,0,0]  },
        { id: 'right', start: [ wallLength/2-inset,   eyeLevel, -wallLength/2+spacing], dir:[0,0,1],  normal:[-1,0,0] },
    ];

    const curation = this._venuePlacement || {};
    const upperY = Number.isFinite(Number(curation.upper_row_y)) && Number(curation.upper_row_y) > 0
        ? Number(curation.upper_row_y) : 2.98;
    const rowCaps = (Array.isArray(curation.row_caps) && curation.row_caps.length)
        ? curation.row_caps : SALON_ROW_CAPS;
    const keepMaxWidth = hang.keep
        ? Math.max(0, Number(curation.keep_clear?.max_width) || 0) : 0;

    const KEEP_FRAME_PAD = 0.09;   // classic frame border (measured on builds)
    const KEEP_REVEAL    = 0.30;   // wall reveal between assembly and frame
    let keepShift = 0;
    if (hang.keep && keepMaxWidth > 0) {
        keepShift = Math.max(0,
            hang.keep.half + KEEP_REVEAL + KEEP_FRAME_PAD + keepMaxWidth / 2 - spacing / 2);
    }
    const capFor = (row, wallId, nearKeep) => {
        const c = rowCaps[Math.min(row, rowCaps.length - 1)] || {};
        let mw = c.maxWidth;
        if (nearKeep && keepMaxWidth > 0) {
            mw = mw > 0 ? Math.min(mw, keepMaxWidth) : keepMaxWidth;
        }
        return { maxWidth: mw, maxHeight: c.maxHeight };
    };

    const keepLines = new Set();
    if (hang.keep) {
        const kw = walls.findIndex(w => w.id === hang.keep.wall);
        if (kw >= 0) for (let r = 0; r < plan.rows; r++) keepLines.add(kw + r * 4);
    }

    const counts = plan.lines.slice(0, 4 * plan.rows);
    let displaced = 0;
    for (const li of keepLines) {
        if (li < counts.length && counts[li] % 2 === 1) { counts[li] -= 1; displaced += 1; }
    }
    for (let d = 0; d < displaced; d++) {
        for (let i = counts.length - 1; i >= 0; i--) {
            if (!keepLines.has(i) && counts[i] < plan.perLine + 1) { counts[i] += 1; break; }
        }
    }
    let total = counts.reduce((a, b) => a + b, 0);
    for (let guard = 0; total < imageCount && guard < 16; guard++) {
        let best = -1;
        for (let i = counts.length - 1; i >= 0; i--) {
            if (!keepLines.has(i) && (best === -1 || counts[i] <= counts[best])) best = i;
        }
        if (best === -1) break;
        counts[best] += 1; total += 1;
    }

    // Slots in hang order: wall by wall, row 0 before row 1.
    const slots = [];
    for (let w = 0; w < 4; w++) {
        for (let r = 0; r < plan.rows; r++) {
            const n = counts[w + r * 4] || 0;
            if (n <= 0) continue;
            const y = r === 0 ? eyeLevel : upperY;
            const isKeep = keepLines.has(w + r * 4);
            for (let p = 0; p < n; p++) {
                const off0 = wallRunOffset(n, p, spacing, wallLength) - spacing;
                let off = off0;
                let nearKeep = false;
                if (isKeep && keepShift > 0) {
                    const t = ((n - 1) / 2 - p) * spacing;
                    off = off0 - Math.sign(t) * keepShift;
                    nearKeep = Math.abs(t) < spacing;
                }
                slots.push({ wall: walls[w], row: r, y, off, nearKeep });
            }
        }
    }

    let focalHeroTaken = false;
    let si = 0;
    for (const imgIdx of order) {
        const img = this.artworkImages[imgIdx];
        const s = si < slots.length ? slots[si] : null;
        const caps = s ? capFor(s.row, s.wall.id, s.nearKeep) : capFor(0, null, false);
        const { group } = this.makeArtworkGroup(img, data, {
            maxWidth: caps.maxWidth, maxHeight: caps.maxHeight,
        });
        if (s) {
            const wall = s.wall;
            group.position.set(wall.start[0]+wall.dir[0]*s.off, s.y, wall.start[2]+wall.dir[2]*s.off);
            group.lookAt(group.position.x+wall.normal[0], s.y, group.position.z+wall.normal[2]);
            group.userData.wallId = wall.id;
            group.userData.row = s.row;
            si++;
        } else {
            console.warn('[placer] square rows: slot shortfall — work hung centre');
            group.position.set(0, eyeLevel, 0);
        }
        this.placeAndRegister(group, data);

        if (!focalHeroTaken && isFocalHero(focalWall, group.userData.wallId, false)) {
            group.scale.multiplyScalar(FOCAL.scaleBoost);
            const ud = group.userData;
            if (ud.lightMax != null)  ud.lightMax  *= FOCAL.lightBoost;
            if (ud.lightBase != null) ud.lightBase *= FOCAL.lightBoost;
            focalHeroTaken = true;
        }
    }
}

export function _placeArtworksSquareDivider(data, ctx) {
    const { order, focalWall } = ctx;
    const imageCount = this.artworkImages.length;
    const curation   = this._venuePlacement || {};
    const eyeLevel   = CONFIG.camera.height;

    const plan = resolveDividerHang(curation, imageCount, CONFIG.room.artworkSpacing,
                                    CONFIG.room.minWallLength);
    if (!plan) return;   // unreachable (the branch guarantees the key)

    const inset = wallInset();
    const h = plan.wallLength / 2;
    const walls = {
        front: { start: [0, 0, -h + inset],          dir: [1, 0, 0], normal: [0, 0, 1] },
        back:  { start: [0, 0,  h - inset],          dir: [1, 0, 0], normal: [0, 0, -1] },
        left:  { start: [-h + inset, 0, 0],          dir: [0, 0, 1], normal: [1, 0, 0] },
        right: { start: [ h - inset, 0, 0],          dir: [0, 0, 1], normal: [-1, 0, 0] },
    };

    const upperY = Number.isFinite(Number(curation.upper_row_y)) && Number(curation.upper_row_y) > 0
        ? Number(curation.upper_row_y) : 2.98;
    const rowCaps = (Array.isArray(curation.row_caps) && curation.row_caps.length)
        ? curation.row_caps : SALON_ROW_CAPS;
    const capFor = (row) => {
        const c = rowCaps[Math.min(row, rowCaps.length - 1)] || {};
        return { maxWidth: c.maxWidth, maxHeight: c.maxHeight };
    };

    const slots = [];
    for (const ln of plan.lines) {
        const seg = plan.segs[ln.seg];
        if (!seg || seg.len <= 0 || ln.count <= 0) continue;
        const y = ln.row === 0 ? eyeLevel : upperY;
        for (let p = 0; p < ln.count; p++) {
            const off = wallRunOffset(ln.count, p, plan.spacing, seg.len) - seg.len / 2;
            slots.push({ wall: walls[seg.wall], row: ln.row, y, tOff: seg.center + off, segId: ln.seg });
        }
    }

    let focalHeroTaken = false;
    let si = 0;
    for (const imgIdx of order) {
        const img = this.artworkImages[imgIdx];
        const s = si < slots.length ? slots[si] : null;
        const caps = s ? capFor(s.row) : capFor(0);
        const { group } = this.makeArtworkGroup(img, data, {
            maxWidth: caps.maxWidth, maxHeight: caps.maxHeight,
        });
        if (s) {
            const wall = s.wall;
            group.position.set(
                wall.start[0] + wall.dir[0] * s.tOff,
                s.y,
                wall.start[2] + wall.dir[2] * s.tOff,
            );
            group.lookAt(group.position.x + wall.normal[0], s.y, group.position.z + wall.normal[2]);
            group.userData.wallId = wall.id;
            group.userData.row = s.row;
            group.userData.room = s.segId.endsWith('-a') ? 'a' : 'b';
            si++;
        } else {
            console.warn('[placer] square divider: slot shortfall — work hung centre');
            group.position.set(0, eyeLevel, 0);
        }
        this.placeAndRegister(group, data);

        if (!focalHeroTaken && isFocalHero(focalWall, group.userData.wallId, false)) {
            group.scale.multiplyScalar(FOCAL.scaleBoost);
            const ud = group.userData;
            if (ud.lightMax != null)  ud.lightMax  *= FOCAL.lightBoost;
            if (ud.lightBase != null) ud.lightBase *= FOCAL.lightBoost;
            focalHeroTaken = true;
        }
    }
}

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
            const b = bayPlan[k - wallShare];
            const y = b.y ?? eyeLevel;
            group.position.set(b.x, y, b.z);
            group.lookAt(b.x + b.nx, y, b.z + b.nz);
        } else {
            let candidateX;
            if (faceCount === 2) {
                const rowB = Math.floor(k / 2);
                candidateX = xStart + rowB * spacing;
            } else {
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

        _addEasel.call(this, x, z, angle + Math.PI, 0, 1);
    });
}

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
        this.registerObstacle(group, 0.35);
        // Panel stand dims (same sizing contract as makeArtworkGroup)
        const aspect = img.aspectRatio || 1;
        let h = 2.0, w = h * aspect;
        if (w > 3.0) { w = 3.0; h = w / aspect; }
        standData.push({ x: c.x, z: c.z, yaw: c.facing, w, gy, scale: c.scale });
    });

    _addPanelStands.call(this, standData);
}

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

export function _placeArtworksFloating(data) {
    const radius = this._layoutMeta.radius;

    const rng = this._venueRng || createVenueRng(venueSeedSource(this._venueSlug));
    const bandsWanted = Math.max(1, Math.floor(this._venuePlacement?.depth_bands || 1));
    const layout = computeFloatLayout(this.artworkImages.length, radius, rng, {
        depthBands: bandsWanted,
        elevationStep: Number(this._venuePlacement?.elevation_step) || 0,
    });

    this.artworkImages.forEach((img, i) => {
        const p = layout[i];
        const { group } = this.makeArtworkGroup(img, data);
        group.position.set(p.x, p.y, p.z);
        group.lookAt(0, p.y, 0);   // face the centre at its own hover height
        group.rotateZ(p.roll);     // seeded roll in the canvas plane
        this.placeAndRegister(group, data);
        this.registerObstacle(group, 0.3);
    });
}

export function _placeArtworksLake(data) {
    const plan = this._lakePlan;
    if (!plan || plan.courts.length !== this.artworkImages.length) {
        console.warn('[lake] plan/artwork mismatch — falling back to the float ring');
        _placeArtworksFloating.call(this, data);
        return;
    }

    this.artworkImages.forEach((img, i) => {
        const c = plan.courts[i];
        const { group } = this.makeArtworkGroup(img, data, { maxWidth: 2.2 });
        group.position.set(c.x, c.y, c.z);
        group.rotation.y = c.facing;     // canvas front toward the walk
        group.rotateZ(c.roll ?? 0);      // seeded roll in the canvas plane
        group.scale.setScalar(c.scale);  // role hierarchy (hero > primary)
        if (c.role === 'hero') group.userData.wallId = 'lake-hero';
        this.placeAndRegister(group, data);
        this.registerObstacle(group, 0.3);
    });
}

export function _addEasel(x, z, canvasYaw, groundY = 0, scale = 1) {
    const woodMat = this.isLowEnd
        ? new THREE.MeshLambertMaterial({ color: 0x6b4a2a })
        : new THREE.MeshStandardMaterial({ color: 0x6b4a2a, roughness: 0.85, metalness: 0.05 });

    const legGeo = new THREE.CylinderGeometry(0.042, 0.052, 2.0, 6);
    const barGeo = new THREE.BoxGeometry(0.8, 0.055, 0.055);

    const parts = [];
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

export function makeArtworkGroup(img, data, opts = {}) {
    const aspectRatio = img.aspectRatio || 1;
    const maxHeight   = Number(opts.maxHeight) > 0 ? Number(opts.maxHeight) : 2.0;
    const maxWidth    = Number(opts.maxWidth) > 0 ? Number(opts.maxWidth) : 3.0;
    let height = maxHeight;
    let width  = height * aspectRatio;
    if (width > maxWidth) { width = maxWidth; height = width / aspectRatio; }

    // Canvas (the artwork itself) — real texture → thumb → dark placeholder
    const tex = img.texture || img.thumbTexture || getPlaceholderTexture();

    const canvasGeo = new THREE.PlaneGeometry(width, height);

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

    let bezel = null;
    if (reactive) {
        const bezelMat = this.makeReactiveBezelMaterial();
        if (bezelMat) {
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
        _canvasMesh: canvas,
        _frameMesh: frame,
        // Round-trip metadata for the info panel
        ...img,
    };

    return { group };
}

export function applyArtworkTexture(img) {
    if (!img || !img.texture || !this.artworks) return;

    const group = this.artworks.find(a => a.userData.id === img.id);
    const canvasMesh = group?.userData?._canvasMesh;
    if (!canvasMesh || !canvasMesh.material) return;

    canvasMesh.material.map = img.texture;
    canvasMesh.material.needsUpdate = true;

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
