// ─────────────────────────────────────────────────────────────────────────────
// Lighting — ambient + per-artwork proximity lights + custom venue fixtures
//
// INDUSTRIAL LOFT FLICKER FIX:
// The old code binary-switched which artwork light was "on" — only the closest
// one lit up, all others dimmed to 0. As the player walked, the closest artwork
// flipped rapidly between two pieces, causing visible flicker.
//
// New behaviour: every artwork light stays on at a low base intensity; the
// closest artwork gets a smooth boost. No more rapid on/off = no flicker.
// ─────────────────────────────────────────────────────────────────────────────

import * as THREE from 'three';
import { CONFIG, parseColor } from './config.js';

// ── Ambient + key + fill setup ──────────────────────────────────────────────
export function setupLighting(preset) {
    this.lightingConfig = CONFIG.lighting[preset] || CONFIG.lighting.bright;
    const cfg = this.lightingConfig;

    // Low-end: boost ambient to compensate for missing per-artwork PointLights
    const ambientIntensity = this.isLowEnd ? cfg.ambient * 3.5 : cfg.ambient;
    const ambientLight = new THREE.AmbientLight(0xffffff, ambientIntensity);
    this.scene.add(ambientLight);

    // Venue-tinted ambient (warm sodium for industrial, candlelight for zen, etc.)
    const venueTints = {
        'white-cube':       0xffffff,
        'industrial-loft':  0xffe8c0,  // warm sodium
        'dark-museum':      0xffffff,
        'zen-gallery':      0xfff5e0,  // candlelight
        'luxury-penthouse': 0xb8c8e8,
        'cyber-gallery':    0x3060ff,
        'sculpture-garden': 0xe8fce8,
        'infinite-void':    0xaaaaff,
        'crystal-cathedral':0xddeeff,
        'nebula-drift':     0x8844ff,
        'mirror-lake':      0xa0c0ff,
    };
    const tint = (this._venueAmbientColor ?? venueTints[this._venueSlug]) ?? 0xffffff;
    const tintIntensity = this._venueAmbientIntensity ?? ambientIntensity;
    if (tint !== 0xffffff || this._venueAmbientColor) {
        const tinted = new THREE.AmbientLight(tint, tintIntensity * 0.5);
        this.scene.add(tinted);
    }

    // Hemisphere light — soft fill from above
    const hemi = new THREE.HemisphereLight(0xffffff, 0x404040, this.isLowEnd ? 0.4 : 0.15);
    this.scene.add(hemi);
}

// ── Per-artwork proximity light — POOLED ──────────────────────────────────
// PERF-B2 (3D audit F2): every artwork used to get its own PointLight, and
// updateProximityLighting toggled `light.visible` as intensities crossed
// 0.01. The visible point-light COUNT therefore changed while the visitor
// walked — three.js includes the light count in its program cache key, so
// each change compiled a new shader variant (10–100 ms hitch, worst on
// mobile). This was almost certainly the source of the periodic stutter.
//
// Now a fixed pool of PointLights is created once and REASSIGNED to the
// nearest artworks every update. Pool lights are never toggled invisible,
// so the scene's light count is constant → zero mid-walk recompiles.
// A 100-artwork gallery also no longer allocates 100 PointLight objects.
//
// On low-end: skipped entirely (ambient + hemisphere carry the scene).
export function addArtworkLight(artworkGroup, preset) {
    if (this.isLowEnd) return;

    const cfg = CONFIG.lighting[preset] || CONFIG.lighting.bright;
    const targetMax = (this._venueSpotIntensity ?? cfg.spot) * 3.5;
    const baseIntensity = targetMax * 0.15; // base glow even when not closest

    // Where this artwork's pooled light sits when assigned (in front of the
    // canvas, slightly above centre — same anchor the old per-artwork light
    // used). Computed once; the pool copies it on assignment.
    const anchor = artworkGroup.position.clone();
    const normal = new THREE.Vector3(0, 0, 1).applyQuaternion(artworkGroup.quaternion);
    anchor.y += 0.3;
    anchor.add(normal.multiplyScalar(0.8));

    artworkGroup.userData.lightAnchor = anchor;
    artworkGroup.userData.lightBase    = baseIntensity;
    artworkGroup.userData.lightMax     = targetMax;
    artworkGroup.userData.lightCurrent = baseIntensity;
}

// Create / resize the shared light pool. Idempotent; called from
// updateProximityLighting so quality changes (which alter _maxActiveLights)
// are picked up on the next tick.
function _ensureLightPool() {
    const desired = this._maxActiveLights || 6;
    if (this._lightPool && this._lightPool.length === desired) return;

    if (this._lightPool) {
        for (const l of this._lightPool) this.scene.remove(l);
    }
    this._lightPool = [];
    for (let i = 0; i < desired; i++) {
        const light = new THREE.PointLight(0xfff5e6, 0, 10);
        light.castShadow = false;
        // NOTE: never toggle .visible on pool lights — that would change the
        // light count and trigger exactly the recompile this pool prevents.
        // Unassigned lights simply sit at intensity 0.
        this.scene.add(light);
        this._lightPool.push(light);
    }
}

// ── Update proximity lighting — smooth distance-based falloff, pooled ───────
// PERF-B12 (3D audit F12): the old implementation allocated an array of
// {art, distSqr} objects on EVERY call (every 2nd frame) and sorted it —
// pure GC pressure. This version reuses pre-allocated scratch storage and
// selects the top-K artworks with a fixed number of linear scans (no sort,
// no per-call allocations).
export function updateProximityLighting() {
    if (!this.artworks || this.artworks.length === 0) return;
    if (this.isLowEnd) return;

    const playerPos = this.camera.position;
    const lightingConfig = this.lightingConfig || CONFIG.lighting[this.lightingPreset] || CONFIG.lighting.bright;
    const proximityDist = lightingConfig.proximityDistance || 5;
    const sqrProximityDist = proximityDist * proximityDist;

    const artworks = this.artworks;
    const n = artworks.length;

    if (!this._proxScratch || this._proxScratch.length < n) {
        this._proxScratch = new Array(n);
    }
    const scratch = this._proxScratch;

    // ── Ease each artwork's lightCurrent toward its distance-based target ──
    let count = 0;
    for (let i = 0; i < n; i++) {
        const art = artworks[i];
        const ud = art.userData;
        if (ud.lightBase === undefined) continue; // registered without light data

        const dx = playerPos.x - art.position.x;
        const dz = playerPos.z - art.position.z;
        const distSqr = dx * dx + dz * dz;

        // Smoothstep falloff: 1.0 at distance 0, base at proximityDist, 0 beyond
        let target;
        if (distSqr < sqrProximityDist) {
            const t = 1.0 - Math.sqrt(distSqr) / proximityDist;
            const smooth = t * t * (3.0 - 2.0 * t);
            target = ud.lightBase + (ud.lightMax - ud.lightBase) * smooth;
        } else {
            target = 0;
        }

        // Lerp 0.15 = smooth fade, no flicker (same feel as the old system)
        ud.lightCurrent = ud.lightCurrent + (target - ud.lightCurrent) * 0.15;
        scratch[count++] = art;
    }

    // ── Assign the fixed pool to the artworks with the highest current ────
    // intensity (≈ the closest). Reassignment only ever happens at the rank
    // boundary, where both artworks' intensities are near base — the light
    // teleport is imperceptible, and it costs zero shader recompiles.
    _ensureLightPool.call(this);
    const pool = this._lightPool;
    const K = Math.min(pool.length, count);

    if (!this._topArts || this._topArts.length < K) {
        this._topArts = new Array(Math.max(K, 8));
    }
    const top = this._topArts;
    let filled = 0;

    for (let k = 0; k < K; k++) {
        let bestIdx = -1;
        let bestVal = 0.01; // effectively-off lights don't need a pool slot
        for (let i = 0; i < count; i++) {
            const art = scratch[i];
            if (art.userData._poolPicked) continue;
            const v = art.userData.lightCurrent;
            if (v > bestVal) { bestVal = v; bestIdx = i; }
        }
        if (bestIdx < 0) break;
        scratch[bestIdx].userData._poolPicked = true;
        top[filled++] = scratch[bestIdx];
    }

    for (let i = 0; i < count; i++) {
        scratch[i].userData._poolPicked = false;
    }

    for (let i = 0; i < pool.length; i++) {
        const light = pool[i];
        if (i < filled) {
            const art = top[i];
            light.position.copy(art.userData.lightAnchor);
            light.intensity = art.userData.lightCurrent;
        } else {
            light.intensity = 0;
        }
    }
}

// ── Custom venue lighting fixtures (from lighting_fixtures JSON) ─────────────
export function addCustomLights(fixtures) {
    for (const f of fixtures) {
        let light;
        const color = f.color ? parseColor(f.color) : 0xffffff;
        const intensity = f.intensity ?? 1;
        switch (f.type) {
            case 'point':
                light = new THREE.PointLight(color, intensity, f.distance ?? 0, f.decay ?? 2);
                break;
            case 'spot':
                light = new THREE.SpotLight(color, intensity, f.distance ?? 0);
                break;
            case 'directional':
                light = new THREE.DirectionalLight(color, intensity);
                break;
            case 'strip':
                // Modeled as a point light — pair with a GLB mesh that has emissive material
                light = new THREE.PointLight(color, intensity, f.distance ?? 10, f.decay ?? 1.5);
                break;
            default:
                continue;
        }
        if (f.position) light.position.set(f.position[0], f.position[1], f.position[2]);
        if (f.cast_shadow !== false && light.castShadow !== undefined) {
            light.castShadow = true;
        }
        this.scene.add(light);
    }
}
