// ─────────────────────────────────────────────────────────────────────────────
// Exospace gallery — runtime configuration
//
// All tunable constants for the 3D viewer live here. Edit values, not logic.
// Loaded by main.js as a singleton; do not mutate at runtime — write a new
// constants file per venue if a venue needs bespoke tuning.
// ─────────────────────────────────────────────────────────────────────────────

import * as THREE from 'three';

export const CONFIG = {
    camera: {
        fov: 75,
        near: 0.1,
        far: 100,
        height: 1.6, // human eye level, in metres
        // Physics-based movement — feels heavier than the old instant-start
        damping: 10.0,           // higher = stops faster (friction)
        acceleration: 40.0,      // how fast we reach top speed
        maxSpeed: 3.0,           // metres/sec cap
        maxLean: 0.02,           // max camera roll (radians) for cinematic tilt
        leanSpeed: 0.1,          // how fast the camera tilts into turns
    },

    movement: {
        baseSpeed: 0.1,
        speedMultipliers: [1, 2, 4, 8], // keys 1/2/3/4
        currentSpeedIndex: 0,
        sprintMultiplier: 1.5,           // SHIFT
    },

    room: {
        wallHeight: 4,
        artworkSpacing: 3.5,   // metres between artworks along a wall
        minWallLength: 8,
        wallDepth: 0.3,
    },

    // ── Lighting presets ────────────────────────────────────────────────────
    // These tune the THREE.AmbientLight + key/fill ratio + HDRI strength.
    // The actual PointLights are added per-room in RoomBuilder.js.
    lighting: {
        bright: {
            ambient: 0.20,
            spot: 0.45,
            ceiling: 0xffffff,
            fillLight: 0.12,
            proximityDistance: 5,
            hdri: '/assets/textures/env/studio.hdr',
            envIntensity: 0.25,
            toneMappingExposure: 0.5,
        },
        moody: {
            ambient: 0.18,
            spot: 0.50,
            ceiling: 0xe8e8e8,
            fillLight: 0.15,
            proximityDistance: 5,
            hdri: '/assets/textures/env/rural_evening.hdr',
            envIntensity: 0.30,
            toneMappingExposure: 0.55,
        },
        dramatic: {
            ambient: 0.12,
            spot: 0.60,
            ceiling: 0x2a2a2a,
            fillLight: 0.06,
            proximityDistance: 5,
            hdri: '/assets/textures/env/night.hdr',
            envIntensity: 0.30,
            toneMappingExposure: 0.50,
        },
    },

    performance: {
        autoDetectQuality: true,
        lowEndThreshold: 30, // FPS
        textureMaxSize: 2048,
        shadowsEnabled: false, // globally off — venue can opt-in via visual_config
    },

    // ── Post-processing ─────────────────────────────────────────────────────
    // Off on low-end. Tuned per venue by VenueDecorator.
    postFx: {
        bloom: true,
        bloomStrength: 0.6,
        bloomRadius: 0.4,
        bloomThreshold: 0.85,
        ssao: false, // expensive — enable per venue only
        vignette: true,
        vignetteDarkness: 0.5,
        vignetteOffset: 1.0,
    },
};

// ── Texture path map ────────────────────────────────────────────────────────
// Each entry is a directory under public/assets/textures/<surface>/<material>/.
// The Materials.js module loads color.jpg, normal.jpg, roughness.jpg, ao.jpg
// from each directory (any missing file is silently skipped).
//
// To add a new wall material: drop a new folder under walls/ and add a key here.
export const TEXTURE_PATHS = {
    walls: {
        white:    '/assets/textures/walls/white',
        concrete: '/assets/textures/walls/concrete',
        brick:    '/assets/textures/walls/brick',
        wood:     '/assets/textures/walls/wood',
        // New materials — drop the folders in and they become available
        plaster:  '/assets/textures/walls/plaster',
        marble:   '/assets/textures/walls/marble',
        velvet:   '/assets/textures/walls/velvet',
    },
    floors: {
        wood:     '/assets/textures/floors/wood',
        marble:   '/assets/textures/floors/marble',
        concrete: '/assets/textures/floors/concrete',
        // New materials
        terrazzo: '/assets/textures/floors/terrazzo',
        grass:    '/assets/textures/floors/grass',
        sand:     '/assets/textures/floors/sand',
        water:    '/assets/textures/floors/water',
    },
    ceilings: {
        flat:     '/assets/textures/ceilings/flat',
        beamed:   '/assets/textures/ceilings/beamed',
        glass:    '/assets/textures/ceilings/glass',
    },
};

// Per-material PBR fallback values used when no texture map is present
// (or when running on low-end where textures are skipped).
export const MATERIAL_PRESETS = {
    walls: {
        white:    { color: 0xf5f5f5, roughness: 0.85, metalness: 0.00, normalStrength: 0.30 },
        concrete: { color: 0x8a8a8a, roughness: 0.95, metalness: 0.00, normalStrength: 0.80 },
        brick:    { color: 0xa0826d, roughness: 0.95, metalness: 0.00, normalStrength: 0.70 },
        wood:     { color: 0x8b6f47, roughness: 0.70, metalness: 0.10, normalStrength: 0.50 },
        plaster:  { color: 0xeae3d2, roughness: 0.90, metalness: 0.00, normalStrength: 0.40 },
        marble:   { color: 0xe8e8e8, roughness: 0.30, metalness: 0.20, normalStrength: 0.50 },
        velvet:   { color: 0x3b1f3b, roughness: 1.00, metalness: 0.00, normalStrength: 0.20 },
    },
    floors: {
        wood:     { color: 0x5c4033, roughness: 0.70, metalness: 0.10, normalStrength: 0.60 },
        marble:   { color: 0xe8e8e8, roughness: 0.30, metalness: 0.20, normalStrength: 0.50 },
        concrete: { color: 0x6b6b6b, roughness: 0.90, metalness: 0.05, normalStrength: 0.70 },
        terrazzo: { color: 0xb0a890, roughness: 0.40, metalness: 0.10, normalStrength: 0.30 },
        grass:    { color: 0x3a6a2a, roughness: 1.00, metalness: 0.00, normalStrength: 0.90 },
        sand:     { color: 0xc8b27a, roughness: 1.00, metalness: 0.00, normalStrength: 0.60 },
        water:    { color: 0x1a4a6a, roughness: 0.05, metalness: 0.30, normalStrength: 0.10 },
    },
};

// Frame styles → colour + PBR for createFrame() in Materials.js
export const FRAME_STYLES = {
    modern:  { color: 0x2c2c2c, roughness: 0.30, metalness: 0.80 },
    classic: { color: 0x8b7355, roughness: 0.40, metalness: 0.30 },
    minimal: { color: 0xffffff, roughness: 0.60, metalness: 0.00 },
    gold:    { color: 0xc9a84c, roughness: 0.20, metalness: 0.95 },
    silver:  { color: 0xc0c0c0, roughness: 0.20, metalness: 0.95 },
    bronze:  { color: 0x8c6a3f, roughness: 0.30, metalness: 0.90 },
    black:   { color: 0x0a0a0a, roughness: 0.50, metalness: 0.20 },
};

// Special venues that should NEVER have walls/ceiling — outdoor or void types
export const OPEN_AIR_VENUES = new Set([
    'sculpture-garden',
    'infinite-void',
    'crystal-cathedral',
    'nebula-drift',
    'mirror-lake',
]);

// Special venues that use a circular ground plane (no L-shape, no square)
export const CIRCULAR_VENUES = new Set([
    'infinite-void',
    'crystal-cathedral',
    'nebula-drift',
    'mirror-lake',
    'sculpture-garden',
]);

// ── Color parsing helper ────────────────────────────────────────────────────
// Venue configs from the database store colors as strings like '0x87ceeb'.
// Three.js r182's Color constructor treats strings as CSS color names, so
// `new THREE.Color('0x87ceeb')` fails with "Unknown color".
// This helper converts '0xRRGGBB' strings to numbers before passing to THREE.Color.
//
// Usage:
//   import { parseColor } from './config.js';
//   const c = parseColor(vc.background_color); // handles string, number, or null
//   if (c) this.scene.background = c;
export function parseColor(value) {
    if (value === null || value === undefined || value === '') return null;
    if (typeof value === 'number') return new THREE.Color(value);
    if (typeof value === 'string') {
        // '0xRRGGBB' → parse as hex number
        if (value.startsWith('0x') || value.startsWith('0X')) {
            return new THREE.Color(parseInt(value, 16));
        }
        // '#RRGGBB' or CSS color name → let THREE.Color handle it
        return new THREE.Color(value);
    }
    return null;
}
