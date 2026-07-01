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

// ── Per-artwork proximity light ──────────────────────────────────────────────
// On low-end: skipped entirely (ambient + hemisphere carry the scene).
// On high-end: every artwork gets a low base light; the closest one boosts.
export function addArtworkLight(artworkGroup, preset) {
    if (this.isLowEnd) return;

    const cfg = CONFIG.lighting[preset] || CONFIG.lighting.bright;
    const targetMax = (this._venueSpotIntensity ?? cfg.spot) * 3.5;
    const baseIntensity = targetMax * 0.15; // base glow even when not closest

    const artworkLight = new THREE.PointLight(0xfff5e6, baseIntensity, 10);

    const normal = new THREE.Vector3(0, 0, 1).applyQuaternion(artworkGroup.quaternion);
    artworkLight.position.copy(artworkGroup.position);
    artworkLight.position.y += 0.3;
    artworkLight.position.add(normal.multiplyScalar(0.8));
    artworkLight.castShadow = false;

    this.scene.add(artworkLight);
    artworkGroup.userData.light         = artworkLight;
    artworkGroup.userData.lightBase     = baseIntensity;
    artworkGroup.userData.lightMax      = targetMax;
    artworkGroup.userData.lightCurrent  = baseIntensity;
}

// ── Update proximity lighting — smooth distance-based falloff with light cap ─
// PERF FIX: Cap the number of simultaneously active artwork lights to 6.
// Each active PointLight adds a full per-fragment lighting pass in WebGL.
// A 30-artwork gallery with all lights on = 30 passes = 20fps on a mid-range
// GPU. With only the 6 closest lights active, the GPU does 6 passes = 60fps.
// Distant lights are set to intensity 0 and visibility false (free — no
// per-fragment cost).
export function updateProximityLighting() {
    if (!this.artworks || this.artworks.length === 0) return;
    if (this.isLowEnd) return;

    const playerPos = this.camera.position;
    const lightingConfig = this.lightingConfig || CONFIG.lighting[this.lightingPreset] || CONFIG.lighting.bright;
    const proximityDist = lightingConfig.proximityDistance || 5;
    const sqrProximityDist = proximityDist * proximityDist;

    // ── Compute squared distance to every artwork (cheap — no sqrt) ────────
    const dists = [];
    for (let i = 0; i < this.artworks.length; i++) {
        const art = this.artworks[i];
        const dx = playerPos.x - art.position.x;
        const dz = playerPos.z - art.position.z;
        dists.push({ art, distSqr: dx * dx + dz * dz });
    }

    // ── Sort by distance (ascending) — closest first ───────────────────────
    // For 30 artworks this is ~30*log(30) ≈ 150 comparisons — negligible.
    dists.sort((a, b) => a.distSqr - b.distSqr);

    // ── Only the closest MAX_ACTIVE lights get boosted; rest go dark ───────
    // This is the single biggest performance win for large galleries.
    const MAX_ACTIVE = this._maxActiveLights || 6;

    for (let i = 0; i < dists.length; i++) {
        const entry = dists[i];
        const light = entry.art.userData.light;
        if (!light) continue;

        let target;
        if (i < MAX_ACTIVE && entry.distSqr < sqrProximityDist) {
            // Smoothstep falloff: 1.0 at distance 0, 0 at proximityDist
            const t = 1.0 - Math.sqrt(entry.distSqr) / proximityDist;
            const smooth = t * t * (3.0 - 2.0 * t);
            target = entry.art.userData.lightBase +
                     (entry.art.userData.lightMax - entry.art.userData.lightBase) * smooth;
        } else {
            // Beyond the cap or beyond proximity range → turn off entirely
            target = 0;
        }

        // Ease current toward target (lerp 0.15 = smooth fade, no flicker)
        const current = entry.art.userData.lightCurrent;
        const next = current + (target - current) * 0.15;
        entry.art.userData.lightCurrent = next;
        light.intensity = next;
        light.visible = next > 0.01;
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
