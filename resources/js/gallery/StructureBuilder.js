// ─────────────────────────────────────────────────────────────────────────────
// StructureBuilder — the descriptor-vocabulary interpreter (roadmap §10.3)
//
// Iteration 3 "Rooms" (roadmap P1.3). This is the ONE genuinely new subsystem
// the roadmap authorises: a deliberately small, declarative vocabulary that
// lets a venue's `visual_config.structure` JSON describe interior structure,
// rendered here generically — the JS contains ZERO venue knowledge (DoD rule
// #7: no slug-keyed JS; the DB is the sole source of venue identity, §10.2).
//
// THE VOCABULARY (≤10 primitives — if a venue needs more, it needs modeling,
// not descriptors; §10.3):
//
//   box | cylinder | cone | plane | sphere | torus |
//   emissive-strip | points-cloud | glyph-plane | instance-grid
//
// ENTRY SCHEMA (one structure element):
//
//   {
//     id:        'shoji-a',                 // optional, used in logs/seeds
//     primitive: 'box',
//
//     at: [x, y, z]                         // world position, OR
//     at: { from: ANCHOR, offset: [side, up, forward] },
//     turn: 'in' | 'out',                   // anchored only: yaw to face
//                                           // into / away from the room
//     rot: [rx, ry, rz],                    // radians (turn yaw is added)
//
//     size: n | [x, y, z],                  // primitive dimensions
//     fit: 'wall' | 'glazing',              // stretch the horizontal tangent
//                                           // axis to the anchored wall span
//     fit_pad: 0.1,                         // total metres trimmed by fit
//
//     material: 'preset_key' | { color: '0x..', roughness, metalness,
//               emissive, emissiveIntensity, transparent, opacity,
//               side: 'double', glass: true, tint, opacity },
//
//     collide: true,                        // register AABB obstacle
//     hangable: true,                       // register artwork-hang surface
//     tier_floor: 'low' | 'mobile' | 'high',// minimum tier that renders it
//     merge: 'group-key',                   // same material + key ⇒ merged
//
//     // instance-grid only:
//     grid: {
//       mode: 'line',                       // 1-D along the anchor's SIDE axis
//       from: ANCHOR, span: 'fit'|number, spacing: 1.4, forward: -0.05,
//     } | {
//       mode: 'box',                        // regular fill of a 3-D box
//       area: AREA, spacing: [x,y,z],
//     } | {
//       mode: 'scatter',                    // seeded distribution
//       area: AREA, count, seed, scale_jitter: [min,max],
//       yaw_jitter: [min,max], grounded: true,
//     },
//     // optional for instance-grid (the shape each instance repeats):
//     grid: { ... , instance: 'box'|'cylinder'|'cone'|'sphere'|'torus'|'plane' },
//     // points-cloud only:
//     cloud: { count, area: AREA, size, opacity, color, drift, seed },
//     // glyph-plane only:
//     text: '…', text_color: '0x..', text_px: 340, bg: '0x..'|null
//   }
//
// AREA spec (box/scatter/cloud): { from: ANCHOR|[x,y,z], size: [w,h,d] |
//   {fit:'room', pad:[x,z]}, forward: n } — a box CENTRED on the anchor.
//   Scatter instances with `grounded: true` sit on the area floor.
//
// ANCHORS (resolved from the room this venue built — never from slugs):
//   'center'      primary exhibition zone centre (origin; l-shape ⇒ wing A)
//   'wall_front' | 'wall_back' | 'wall_left' | 'wall_right'
//                 inner wall-face centre; offset forward = INTO the room
//   'wall_*_outside'   outer wall-face centre; offset forward = AWAY
//   'glazing' | 'glazing_outside'
//                 the wall replaced by glazing (visual_config.glazing_wall);
//                 available on square + l-shape (the Penthouse layouts)
//
// HARD RULES (§10.3 — what keeps this from becoming an engine):
//   no scripting, no conditions, no animation in descriptors (a drifting
//   point cloud reuses the EXISTING particle behaviour — the only animation
//   the runtime has; DO-NOT-DO #5 respected). Complex props stay GLB
//   decorations via the existing pipeline.
//
// VALIDATION: validateStructure() is pure (no THREE import) so the descriptor
// parser is unit-testable in plain Node per §10.8.
//
// ROLLBACK: structure renders only when the venue declares BOTH
//   visual_config.structure_pass = 'rooms' AND a non-empty structure array.
//   Removing either key from one venue's JSON reverts that venue, live, no
//   deploy (config is the only on-switch, §11.3 rule 2).
// ─────────────────────────────────────────────────────────────────────────────

import * as THREE from 'three';
import { CONFIG, parseColor } from './config.js';
import { mergeParts } from './GeometryUtils.js';
import { createVenueRng, venueSeedSource } from './Rng.js';
import { makeGlassMaterial } from './TierEffects.js';

// ── The closed primitive set (§10.3: ≤10) ────────────────────────────────────
export const STRUCTURE_PRIMITIVES = Object.freeze([
    'box', 'cylinder', 'cone', 'plane', 'sphere', 'torus',
    'emissive-strip', 'points-cloud', 'glyph-plane', 'instance-grid',
]);

// ── Material presets (interpreted generically; venues reference by key) ──────
// Room-family palette per §5.3 "matte restraint + one precious idea per venue".
// Explicit material objects in a descriptor always win over these presets.
export const SB_MATERIALS = Object.freeze({
    wood_dark:    { color: '0x4a3826', roughness: 0.75, metalness: 0.05 },
    wood_warm:    { color: '0x8b6f47', roughness: 0.70, metalness: 0.10 },
    paper_shoji:  { color: '0xf3ecd8', roughness: 0.95, emissive: '0xffe8c2', emissiveIntensity: 0.12, transparent: true, opacity: 0.50, side: 'double' },
    plaster_warm: { color: '0xd9d2c2', roughness: 0.90, metalness: 0.00 },
    stone:        { color: '0x8f8a80', roughness: 0.85, metalness: 0.05 },
    bronze:       { color: '0x8c6a3f', roughness: 0.35, metalness: 0.90 },
    steel_dark:   { color: '0x2a2c30', roughness: 0.45, metalness: 0.85 },
    dark_trim:    { color: '0x111214', roughness: 0.60, metalness: 0.40 },
    fabric_warm:  { color: '0x6a5a48', roughness: 1.00, metalness: 0.00 },
    fabric_dark:  { color: '0x2e2a26', roughness: 1.00, metalness: 0.00 },
    crate_wood:   { color: '0x6a5230', roughness: 0.90, metalness: 0.00 },
    neon_cyan:    { color: '0x00e5ff', emissive: '0x00e5ff', emissiveIntensity: 2.4 },
    neon_magenta: { color: '0xd500fa', emissive: '0xd500fa', emissiveIntensity: 2.4 },
    tower_cool:   { color: '0x0b1220', emissive: '0x8fb4dd', emissiveIntensity: 0.55 },
    tower_warm:   { color: '0x12100e', emissive: '0xd8b98f', emissiveIntensity: 0.45 },
});

// ── Validation (pure — unit-testable without a browser, §10.8) ───────────────
export function validateStructure(entries) {
    const errors = [];
    if (!Array.isArray(entries)) {
        return { valid: false, errors: ['structure must be an array'] };
    }
    entries.forEach((e, i) => {
        const at = e && e.id ? `"${e.id}"` : `#${i}`;
        if (!e || typeof e !== 'object') { errors.push(`${at}: entry is not an object`); return; }
        if (!STRUCTURE_PRIMITIVES.includes(e.primitive)) {
            errors.push(`${at}: unknown primitive "${e.primitive}"`);
        }
        if (e.at === undefined || e.at === null) {
            errors.push(`${at}: missing "at"`);
        } else if (Array.isArray(e.at)) {
            if (e.at.length !== 3 || e.at.some(v => typeof v !== 'number')) {
                errors.push(`${at}: "at" array must be 3 numbers`);
            }
        } else if (typeof e.at === 'object') {
            if (!e.at.from) errors.push(`${at}: anchored "at" requires "from"`);
        }
        if (e.material === undefined) {
            errors.push(`${at}: missing "material"`);
        } else if (typeof e.material === 'string' && !SB_MATERIALS[e.material]) {
            errors.push(`${at}: unknown material preset "${e.material}"`);
        } else if (e.material && typeof e.material === 'object' && !e.material.color && !e.material.glass) {
            errors.push(`${at}: explicit material requires "color" or "glass"`);
        }
        if (e.primitive === 'instance-grid') {
            const g = e.grid || {};
            if (g.instance !== undefined && !['box', 'cylinder', 'cone', 'sphere', 'torus', 'plane'].includes(g.instance)) {
                errors.push(`${at}: grid.instance must be a geometry primitive`);
            }
            if (g.mode === 'scatter') {
                if (!g.count || g.count < 1) errors.push(`${at}: scatter grid requires count ≥ 1`);
                if (!g.seed)                 errors.push(`${at}: scatter grid requires seed`);
                if (!g.area)                 errors.push(`${at}: scatter grid requires grid.area`);
            } else if (g.mode === 'box') {
                if (!Array.isArray(g.spacing) || g.spacing.length !== 3) {
                    errors.push(`${at}: box grid requires spacing [x,y,z]`);
                }
                if (!g.area) errors.push(`${at}: box grid requires grid.area`);
            } else if (g.mode === 'line') {
                if (!g.from) errors.push(`${at}: line grid requires grid.from`);
                if (!g.span && !g.spacing) errors.push(`${at}: line grid requires span or spacing`);
            } else {
                errors.push(`${at}: instance-grid requires grid.mode 'line'|'box'|'scatter'`);
            }
        }
        if (e.primitive === 'points-cloud') {
            const c = e.cloud || {};
            if (!c.count || c.count < 1) errors.push(`${at}: points-cloud requires cloud.count`);
            if (!c.seed)                 errors.push(`${at}: points-cloud requires cloud.seed`);
            if (!c.area)                 errors.push(`${at}: points-cloud requires cloud.area`);
        }
        if (e.primitive === 'glyph-plane' && typeof e.text !== 'string') {
            errors.push(`${at}: glyph-plane requires text`);
        }
        if (e.tier_floor && !['low', 'mobile', 'high'].includes(e.tier_floor)) {
            errors.push(`${at}: tier_floor must be low|mobile|high`);
        }
    });
    return { valid: errors.length === 0, errors };
}

// ── Tier floor (pure) ─────────────────────────────────────────────────────────
// 'low'    → every device renders it
// 'mobile' → high + mobile tiers
// 'high'   → high tier only (HDRI-class effects)
export function resolveTierFloor(tierFloor, { isLowEnd = false, isMobileTier = false } = {}) {
    if (tierFloor === 'high')   return !isLowEnd && !isMobileTier;
    if (tierFloor === 'mobile') return !isLowEnd;
    return true;
}

// ── Anchor resolution ─────────────────────────────────────────────────────────
// Returns { pos:[x,y,z], fwd:[x,0,z], width, height } or null when the anchor
// does not exist for the current layout (entries anchored to it are SKIPPED,
// never guessed — a wrong guess would put geometry inside walls).
//
// fwd = the anchor's forward axis: INTO the room for wall anchors, AWAY from
// the room for *_outside anchors. `width` = the span along the wall tangent.
export function resolveAnchor(ctx, from) {
    const meta = ctx._layoutMeta || {};
    const d    = CONFIG.room.wallDepth || 0.3;
    const type = meta.type || 'square';

    const horizontal = (x, z, fx, fz, width) =>
        ({ pos: [x, 0, z], fwd: [fx, 0, fz], width, height: CONFIG.room.wallHeight });

    if (from === 'center') {
        if (type === 'l-shape') return horizontal(meta.wingW / 2, 0, 0, 1, meta.wingW);
        return horizontal(0, 0, 0, 1, 0);
    }

    // The wall replaced by glazing (RoomBuilder sets ctx._glazing when the
    // venue declares visual_config.glazing_wall).
    if (from === 'glazing' || from === 'glazing_outside') {
        const g = ctx._glazing;
        if (!g) return null;
        const out  = from === 'glazing_outside';
        const fx   = out ? -g.inward[0] : g.inward[0];
        const fz   = out ? -g.inward[1] : g.inward[1];
        const push = out ? 0.05 : 0;
        return {
            pos:    [g.cx + fx * push, 0, g.cz + fz * push],
            fwd:    [fx, 0, fz],
            width:  g.width,
            height: g.height,
        };
    }

    if (type === 'square') {
        const L  = meta.wallLength;
        const hi = L / 2 - d / 2;   // inner face
        const ho = L / 2 + d / 2;   // outer face
        switch (from) {
            case 'wall_front':         return horizontal(0, -hi, 0,  1, L);
            case 'wall_back':          return horizontal(0,  hi, 0, -1, L);
            case 'wall_left':          return horizontal(-hi, 0,  1, 0, L);
            case 'wall_right':         return horizontal( hi, 0, -1, 0, L);
            case 'wall_front_outside': return horizontal(0, -ho, 0, -1, L);
            case 'wall_back_outside':  return horizontal(0,  ho, 0,  1, L);
            case 'wall_left_outside':  return horizontal(-ho, 0, -1, 0, L);
            case 'wall_right_outside': return horizontal( ho, 0,  1, 0, L);
        }
    }

    if (type === 'corridor') {
        const { length: L, width: W } = meta;
        const hiX = L / 2 - d / 2, hiZ = W / 2 - d / 2;
        const hoX = L / 2 + d / 2, hoZ = W / 2 + d / 2;
        switch (from) {
            case 'wall_front':         return horizontal(0, -hiZ, 0,  1, L);
            case 'wall_back':          return horizontal(0,  hiZ, 0, -1, L);
            case 'wall_left':          return horizontal(-hiX, 0,  1, 0, W);
            case 'wall_right':         return horizontal( hiX, 0, -1, 0, W);
            case 'wall_front_outside': return horizontal(0, -hoZ, 0, -1, L);
            case 'wall_back_outside':  return horizontal(0,  hoZ, 0,  1, L);
            case 'wall_left_outside':  return horizontal(-hoX, 0, -1, 0, W);
            case 'wall_right_outside': return horizontal( hoX, 0,  1, 0, W);
        }
    }

    return null; // l-shape / rotunda / circular: only center + glazing anchors
}

// ── Area resolution (instance-grid box/scatter + points-cloud) ───────────────
// Returns { cx, cy, cz, sx, sy, sz } — a world-axis box — or null.
function resolveArea(ctx, area) {
    if (!area) return null;
    let cx = 0, cz = 0;
    if (Array.isArray(area.from)) {
        cx = area.from[0]; cz = area.from[2];
    } else if (area.from) {
        const a = resolveAnchor(ctx, area.from);
        if (!a) return null;
        cx = a.pos[0]; cz = a.pos[2];
        const push = area.forward || 0;
        cx += a.fwd[0] * push; cz += a.fwd[2] * push;
    }

    let sx, sy = 0, sz;
    const size = area.size || [0, 0, 0];
    if (size && !Array.isArray(size) && size.fit === 'room') {
        const meta = ctx._layoutMeta || {};
        const pad  = size.pad || [0, 0];
        if (meta.type === 'corridor') {
            sx = meta.length - pad[0] * 2; sz = meta.width - pad[1] * 2;
        } else if (meta.type === 'l-shape') {
            sx = meta.wingW - pad[0] * 2; sz = meta.lenA - pad[1] * 2;
            cx = meta.wingW / 2;
        } else {
            const L = (meta.wallLength || 12) * 2;
            sx = L - pad[0] * 2; sz = L - pad[1] * 2;
        }
    } else {
        sx = size[0]; sy = size[1] || 0; sz = size[2];
    }
    return { cx, cy: area.y || 0, cz, sx: Math.max(0, sx), sy: Math.max(0, sy), sz: Math.max(0, sz) };
}

// ── Materials ─────────────────────────────────────────────────────────────────
function buildMaterial(ctx, mat) {
    const spec = typeof mat === 'string' ? (SB_MATERIALS[mat] || SB_MATERIALS.wood_warm) : mat;
    if (spec.glass) {
        // Tier-resolved (TierResolve): transmission high / cheap mobile / flat
        // low-end — the same null-glass-proof path the Cathedral colonnade uses.
        return makeGlassMaterial(ctx, {
            tint: parseColor(spec.tint) || new THREE.Color(0xcfdde8),
            opacity: spec.opacity ?? 0.25,
        });
    }
    const color    = parseColor(spec.color) || new THREE.Color(0x8b6f47);
    const emissive = spec.emissive ? parseColor(spec.emissive) : null;
    if (ctx.isLowEnd) {
        const m = new THREE.MeshLambertMaterial({ color });
        if (emissive) { m.emissive = emissive; m.emissiveIntensity = spec.emissiveIntensity ?? 1; }
        if (spec.transparent) { m.transparent = true; m.opacity = spec.opacity ?? 1; }
        if (spec.side === 'double') m.side = THREE.DoubleSide;
        return m;
    }
    const m = new THREE.MeshStandardMaterial({
        color,
        roughness: spec.roughness ?? 0.8,
        metalness: spec.metalness ?? 0.0,
    });
    if (emissive) { m.emissive = emissive; m.emissiveIntensity = spec.emissiveIntensity ?? 1; }
    if (spec.transparent) { m.transparent = true; m.opacity = spec.opacity ?? 1; }
    if (spec.side === 'double') m.side = THREE.DoubleSide;
    return m;
}

function materialKey(mat) {
    return typeof mat === 'string' ? mat : JSON.stringify(mat);
}

// ── Geometry per primitive (local, untransformed) ─────────────────────────────
// `e.size`: number | [x, y, z] — box w/h/d, plane w/h, others via params.
// `fitWidth` > 0 replaces the tangent size component for fit:'wall'/'glazing'.
// `areaDims` enables fit:'area_x' / 'area_z' (grid instances stretch to the
// resolved area box — the cyber floor grid spans the room whatever its size).
function buildGeometry(e, fitWidth, areaDims) {
    const s = Array.isArray(e.size) ? e.size : [e.size ?? 1, e.size ?? 1, e.size ?? 1];
    const p = e.params || {};
    switch (e.primitive) {
        case 'box':
        case 'emissive-strip': {
            let w = s[0];
            let d = s[2];
            if (e.fit === 'area_x' && areaDims) {
                w = Math.max(0.05, areaDims.sx - (e.fit_pad ?? 0) * 2);
            } else if (e.fit === 'area_z' && areaDims) {
                d = Math.max(0.05, areaDims.sz - (e.fit_pad ?? 0) * 2);
            } else if (e.fit) {
                w = fitWidth;
            }
            return new THREE.BoxGeometry(w, s[1], d);
        }
        case 'plane': {
            const w = e.fit ? fitWidth : s[0];
            return new THREE.PlaneGeometry(w, s[1]);
        }
        case 'cylinder': return new THREE.CylinderGeometry(p.rt ?? s[0] / 2, p.rb ?? s[0] / 2, p.h ?? s[1], p.seg ?? 16);
        case 'cone':     return new THREE.ConeGeometry(p.r ?? s[0] / 2, p.h ?? s[1], p.seg ?? 12);
        case 'sphere':   return new THREE.SphereGeometry(p.r ?? s[0] / 2, p.seg ?? 20, p.seg ? Math.max(8, Math.round(p.seg / 2)) : 12);
        case 'torus':    return new THREE.TorusGeometry(p.r ?? s[0] / 2, p.tube ?? (s[1] / 2 || 0.1), p.seg ?? 12, p.seg2 ?? 32);
        default:         return null; // grid / cloud / glyph handled by their builders
    }
}

// ── Glyph plane — canvas text on a plane (signage) ────────────────────────────
function buildGlyphMaterial(e) {
    const px = e.text_px || 340;
    const canvas = document.createElement('canvas');
    canvas.width = px * 2; canvas.height = px * 2;
    const g = canvas.getContext('2d');
    if (e.bg) {
        const bg = parseColor(e.bg);
        if (bg) { g.fillStyle = '#' + bg.getHexString(); g.fillRect(0, 0, canvas.width, canvas.height); }
    }
    const fg = parseColor(e.text_color || '0x222222');
    g.fillStyle = '#' + (fg ? fg.getHexString() : '222222');
    g.font = `${px}px serif`;
    g.textAlign = 'center';
    g.textBaseline = 'middle';
    g.fillText(e.text, canvas.width / 2, canvas.height / 2);
    const tex = new THREE.CanvasTexture(canvas);
    tex.colorSpace = THREE.SRGBColorSpace;
    tex.anisotropy = 4;
    return new THREE.MeshStandardMaterial({
        map: tex,
        transparent: !e.bg,
        roughness: 0.9,
        metalness: 0.0,
        side: THREE.DoubleSide,
    });
}

// ── Main entry ─────────────────────────────────────────────────────────────────
// ctx = the GalleryScene controller (same convention as every gallery module).
// Reads only generic state: _layoutMeta, _glazing, isLowEnd, _isMobileTier,
// _venueSlug (seed source only), scene, registerObstacle(), _particleSystems,
// _hangableSurfaces. Returns the number of draw calls emitted.
export function buildStructure(ctx, entries) {
    const check = validateStructure(entries);
    if (!check.valid) {
        console.warn('[StructureBuilder] invalid descriptors — structure skipped:', check.errors);
        return 0;
    }

    const seedSource = venueSeedSource(ctx._venueSlug || 'venue');
    const mergeGroups = new Map();   // `${group}|${matKey}` → { mat, parts, collide, hangs }
    let built = 0;

    const finishMesh = (mesh, e, collide, hang) => {
        mesh.name = `structure:${e.id || e.primitive}`;
        if (e.primitive !== 'emissive-strip' && e.primitive !== 'points-cloud') {
            mesh.castShadow    = !ctx.isLowEnd;
            mesh.receiveShadow = !ctx.isLowEnd;
        }
        ctx.scene.add(mesh);
        if (collide) ctx.registerObstacle(mesh, 0.25);
        if (hang) {
            ctx._hangableSurfaces = ctx._hangableSurfaces || [];
            ctx._hangableSurfaces.push(hang);
            built++;
        }
        built++;
    };

    for (const e of entries) {
        // 1. Tier floor — an undeclared effect never appears (§11.3 rule 2).
        if (!resolveTierFloor(e.tier_floor, { isLowEnd: !!ctx.isLowEnd, isMobileTier: !!ctx._isMobileTier })) continue;

        // 2. Anchor + transform.
        let pos, fwd = null, yaw = 0;
        if (Array.isArray(e.at)) {
            pos = e.at;
        } else {
            const a = resolveAnchor(ctx, e.at?.from);
            if (!a) continue;                       // anchor unavailable on this layout
            const o  = e.at.offset || [0, 0, 0];
            const fx = a.fwd[0], fz = a.fwd[2];
            const sx =  fz, sz = -fx;               // side = up × fwd (horizontal)
            // at.up = 'ceiling' → the up offset measures from the anchored
            // wall's TOP (dynamic wall height) instead of the floor — neon
            // edges track the room whatever height the admin sets.
            const baseY = e.at.up === 'ceiling' ? (a.height || CONFIG.room.wallHeight) : 0;
            pos = [
                a.pos[0] + sx * o[0] + fx * o[2],
                baseY + o[1],
                a.pos[2] + sz * o[0] + fz * o[2],
            ];
            fwd = [fx, fz];
            if (e.turn === 'in')  yaw = Math.atan2(fx, fz);
            if (e.turn === 'out') yaw = Math.atan2(fx, fz) + Math.PI;
        }
        const rot = e.rot || [0, 0, 0];
        const ry  = rot[1] + yaw;

        // 3. Fit — stretch the horizontal tangent axis to the anchored span.
        let fitWidth = 0;
        if (e.fit) {
            const pad = e.fit_pad ?? 0.1;
            let span = 0;
            if (e.fit === 'glazing') {
                span = ctx._glazing ? ctx._glazing.width : 0;
            } else if (fwd) {
                span = resolveAnchor(ctx, e.at.from)?.width || 0;
            }
            if (span <= 0) continue;
            fitWidth = Math.max(0.1, span - pad);
        }

        // 4. Primitive families.
        if (e.primitive === 'instance-grid') { built += buildInstanceGrid(ctx, e, pos, ry, fwd, seedSource, finishMesh); continue; }
        if (e.primitive === 'points-cloud')  { built += buildPointCloud(ctx, e, seedSource);                              continue; }

        let mesh;
        if (e.primitive === 'glyph-plane') {
            const s = Array.isArray(e.size) ? e.size : [e.size ?? 1, e.size ?? 1];
            mesh = new THREE.Mesh(new THREE.PlaneGeometry(e.fit ? fitWidth : s[0], s[1]), buildGlyphMaterial(e));
        } else {
            mesh = new THREE.Mesh(buildGeometry(e, fitWidth), buildMaterial(ctx, e.material));
        }
        mesh.position.set(pos[0], pos[1], pos[2]);
        // 'YXZ' order: yaw applies AFTER any tip, in the world — a flat rug
        // (rot x = -π/2) stays up-facing under the turn yaw on every layout.
        // (Merged parts keep the XYZ default — they must stay yaw-only.)
        mesh.rotation.order = 'YXZ';
        mesh.rotation.set(rot[0], ry, rot[2]);

        // Hangable surfaces: wall-facing planes/boxes register an artwork span
        // (Iteration 3 bay redistribution — Museum dividers, generic mechanism).
        let hang = null;
        if (e.hangable && (e.primitive === 'plane' || e.primitive === 'box')) {
            const s = Array.isArray(e.size) ? e.size : [e.size ?? 1, e.size ?? 1];
            const n = fwd || [Math.sin(ry), Math.cos(ry)];
            hang = { x: pos[0], z: pos[2], nx: n[0], nz: n[1], width: e.fit ? fitWidth : s[0], height: s[1] };
        }

        // 5. Merge groups — static same-material primitives auto-merge (§10.3).
        // Collision is tracked PER PART: a merged group's own AABB would span
        // the whole venue, so colliding parts register individual proxies at
        // emit time (see below).
        if (e.merge) {
            const key = `${e.merge}|${materialKey(e.material)}`;
            if (!mergeGroups.has(key)) mergeGroups.set(key, { mat: null, parts: [], collideParts: [], hangs: [] });
            const grp = mergeGroups.get(key);
            if (!grp.mat) grp.mat = mesh.material;
            else mesh.material.dispose();          // one material per group
            grp.parts.push({ geo: mesh.geometry, pos, rot: [rot[0], ry, rot[2]] });
            if (e.collide) grp.collideParts.push(grp.parts.length - 1);
            if (hang) grp.hangs.push(hang);
            mesh.geometry = new THREE.BufferGeometry(); // detach; real geo lives in the group
            continue;
        }
        finishMesh(mesh, e, e.collide, hang);
    }

    // 6. Emit merged groups — one draw call per (group, material) pair.
    for (const [key, grp] of mergeGroups) {
        const merged = new THREE.Mesh(mergeParts(grp.parts), grp.mat);
        grp.parts.forEach(p => p.geo.dispose());   // free the pre-merge sources
        const id = `merged:${key.split('|')[0]}:${grp.parts.length}`;
        finishMesh(merged, { id, primitive: 'box' }, false, null);
        for (const idx of grp.collideParts) {
            const p = grp.parts[idx];
            const proxy = new THREE.Mesh(p.geo.clone(), _collisionProxyMaterial());
            proxy.position.set(p.pos[0], p.pos[1], p.pos[2]);
            proxy.rotation.set(p.rot[0], p.rot[1], p.rot[2]);
            ctx.registerObstacle(proxy, 0.25);
            proxy.geometry.dispose();
        }
        for (const h of grp.hangs) {
            ctx._hangableSurfaces = ctx._hangableSurfaces || [];
            ctx._hangableSurfaces.push(h);
            built++;
        }
        built++;
    }

    return built;
}

// Shared invisible material for collision proxies (never rendered — the
// proxies exist only so registerObstacle can measure their world AABB).
let _proxyMat = null;
function _collisionProxyMaterial() {
    if (!_proxyMat) _proxyMat = new THREE.MeshBasicMaterial({ visible: false });
    return _proxyMat;
}

// ── instance-grid ──────────────────────────────────────────────────────────────
// mode 'line'    → 1-D endpoint-inclusive row along the anchor's SIDE axis
//                  (mullions, rail posts — spans adapt to the room via 'fit')
// mode 'box'     → regular 3-D fill of the area box (cyber floor light grid)
// mode 'scatter' → seeded distribution (skyline towers — deterministic §13.6)
// Returns draw calls emitted (0 or 1 — always one merged mesh).
function buildInstanceGrid(ctx, e, pos, ry, fwd, seedSource, finishMesh) {
    const g = e.grid;
    const mat = buildMaterial(ctx, e.material);
    const parts = [];
    const baseRot = e.rot || [0, 0, 0];

    const place = (x, y, z, scale, yawJit, areaDims) => {
        // The INSTANCE shape: grid.instance (default 'box') — the entry's own
        // primitive is 'instance-grid', which buildGeometry does not build.
        const instance = { ...e, primitive: g.instance || 'box' };
        parts.push({
            geo: buildGeometry(instance, 0, areaDims || null),
            pos: [x, y, z],
            rot: [baseRot[0], ry + (yawJit || 0), baseRot[2]],
            scale,
        });
    };

    if (g.mode === 'line') {
        // Base = the entry's resolved `at` position; instances spread along the
        // anchor's side axis. span 'fit' = anchored wall/glazing width.
        let span = g.span;
        if (span === 'fit') {
            const pad = g.fit_pad ?? e.fit_pad ?? 0.1;
            const a   = resolveAnchor(ctx, g.from);
            if (!a) return 0;
            span = Math.max(0.1, a.width - pad);
        }
        const n = g.spacing ? Math.max(2, Math.round(span / g.spacing) + 1) : (g.count || 2);
        const fx = fwd ? fwd[0] : Math.sin(ry), fz = fwd ? fwd[2] : Math.cos(ry);
        const sx = fz, sz = -fx;                       // side axis (unit)
        for (let i = 0; i < n; i++) {
            const off = n > 1 ? -span / 2 + (i * span) / (n - 1) : 0;
            place(pos[0] + sx * off, pos[1], pos[2] + sz * off, 1, 0);
        }
    } else if (g.mode === 'box') {
        const area = resolveArea(ctx, g.area);
        if (!area) return 0;
        const sp = g.spacing;
        const nx = sp[0] > 0 ? Math.max(1, Math.round(area.sx / sp[0]) + 1) : 1;
        const ny = sp[1] > 0 ? Math.max(1, Math.round(area.sy / sp[1]) + 1) : 1;
        const nz = sp[2] > 0 ? Math.max(1, Math.round(area.sz / sp[2]) + 1) : 1;
        for (let i = 0; i < nx; i++) {
            for (let j = 0; j < ny; j++) {
                for (let k = 0; k < nz; k++) {
                    const px = nx > 1 ? area.cx - area.sx / 2 + (i * area.sx) / (nx - 1) : area.cx;
                    const py = ny > 1 ? area.cy - area.sy / 2 + (j * area.sy) / (ny - 1) : area.cy;
                    const pz = nz > 1 ? area.cz - area.sz / 2 + (k * area.sz) / (nz - 1) : area.cz;
                    place(px, py, pz, 1, 0, area);
                }
            }
        }
    } else { // scatter
        const area = resolveArea(ctx, g.area);
        if (!area) return 0;
        const rng = createVenueRng(`${seedSource}:${g.seed || e.id || 'scatter'}`);
        const [smin, smax] = g.scale_jitter || [1, 1];
        const [ymin, ymax] = g.yaw_jitter || [0, 0];
        const s = Array.isArray(e.size) ? e.size : [e.size ?? 1, e.size ?? 1, e.size ?? 1];
        for (let i = 0; i < (g.count || 1); i++) {
            const x = area.cx + (rng.next() - 0.5) * area.sx;
            const z = area.cz + (rng.next() - 0.5) * area.sz;
            const scale = smin + rng.next() * (smax - smin);
            const yawJ  = ymin + rng.next() * (ymax - ymin);
            const h = s[1] * scale;
            place(x, g.grounded ? area.cy + h / 2 : area.cy + (rng.next() - 0.5) * area.sy, z, scale, yawJ, area);
        }
    }

    const merged = new THREE.Mesh(mergeParts(parts), mat);
    parts.forEach(p => p.geo.dispose());
    finishMesh(merged, e, e.collide, null);
    return 1;
}

// ── points-cloud ───────────────────────────────────────────────────────────────
// Reuses the EXISTING drift particle behaviour (the runtime's only animation —
// §10.3: descriptors add no new animation classes; DO-NOT-DO #5).
function buildPointCloud(ctx, e, seedSource) {
    const c = e.cloud;
    const area = resolveArea(ctx, c.area);
    if (!area) return 0;
    const rng = createVenueRng(`${seedSource}:${c.seed}`);
    const positions = new Float32Array(c.count * 3);
    for (let i = 0; i < c.count; i++) {
        positions[i * 3]     = area.cx + (rng.next() - 0.5) * area.sx;
        positions[i * 3 + 1] = area.cy + (rng.next() - 0.5) * area.sy;
        positions[i * 3 + 2] = area.cz + (rng.next() - 0.5) * area.sz;
    }
    const geo = new THREE.BufferGeometry();
    geo.setAttribute('position', new THREE.BufferAttribute(positions, 3));
    const points = new THREE.Points(geo, new THREE.PointsMaterial({
        color: parseColor(c.color) || new THREE.Color(0xaaccff),
        size: c.size || 0.05,
        transparent: true,
        opacity: c.opacity ?? 0.6,
        sizeAttenuation: true,
    }));
    points.userData.isParticle = true;
    ctx.scene.add(points);
    if (c.drift) {
        ctx._particleSystems = ctx._particleSystems || [];
        ctx._particleSystems.push({ obj: points, type: 'drift', phase: rng.next() * Math.PI * 2 });
    }
    return 1;
}
