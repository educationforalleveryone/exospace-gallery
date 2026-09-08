<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * LUXURY PENTHOUSE — "Evening Light" deploy-review pass (2.0.0 → 2.1.0).
 * (2026-09-09. Predecessor: 2026_09_08_000005 "The Collector's Floor".)
 *
 * WHY (production walk-through verdict: "many problems")
 * ------------------------------------------------------
 * The v2.0.0 venue reached production and photographed as a dark museum
 * tunnel, not a residence. Forensic re-review of the deployed state found:
 *
 *  D1  LIGHT STARVATION. ambient 0.26 + hemi 0.14 + fill 0.16 at exposure
 *      0.78 rendered walls tan-on-black; the 5.2 m ceiling (0x14110d) read
 *      as a black void and the honed stone floor as black glass. The rig
 *      lifts into a real evening interior: ambient 0.42, hemi 0.3, fill
 *      0.42, exposure 0.92, ceiling 0x5c4c3a (lit plaster), artwork base
 *      0.5 (every canvas legible along the whole wing).
 *  D2  THE GLOW ORBS. floor_roughness 0.42 acted as a mirror for the 14
 *      pooled point lights — a dot-matrix of specular orbs across every
 *      dark floor (and two orb reflections in the transmission glass read
 *      as UFOs in the sky). Floor is honed, not polished (0.62/0.03); the
 *      glass leaves the transmission class entirely (see D4).
 *  D3  THE TRANSMISSION SHEET. The glazing declared opacity 0.18 — but the
 *      high tier renders descriptor glass as TRANSMISSION (opacity forced
 *      to 1.0, roughness 0.05): a 6 m refracting sheet that re-renders the
 *      scene per frame, tints the city pale blue and throws hard specular
 *      orbs. The glazing now declares the new opt-in 'cheap' glass class
 *      (broad sheen, opacity 0.1, roughness 0.35, no scene pass) — the
 *      window reads as open air and the cheapest material in the scene.
 *  D4  THE SKY THAT NEVER WAS. The v2.0.0 horizon-glow plane anchored
 *      glazing_outside with turn 'in' — but that anchor's forward axis
 *      points OUTWARD, so the single-sided plane faced away from the
 *      interior and was back-face culled from day one. The dusk sky is
 *      rebuilt as three facing planes (afterglow band + haze + gradient
 *      field) and the skyline drops to low-slung silhouettes so the
 *      afterglow reads BETWEEN and ABOVE the towers.
 *  D5  THE FIREPLACE BEHIND THE SPAWN. wall_front (the south wall behind
 *      the arrival camera) carried the fireplace — visitors never faced
 *      it; the walk ended on a blank wall. The whole family moves to the
 *      NEW wall_end anchor (wing-A north terminus): the corridor now ends
 *      on a warm destination. The fire accent drops from 16 cd to 5 (it
 *      previously blew an orange flood across the south corner).
 *  D6  RUNWAY COVES. The full-length cove strips read as runway lights
 *      over a black ceiling. Each strip drops under a dark valance shelf
 *      and gains a real (soft) cove-wash point light per wing — the cove
 *      finally does the architectural job its geometry promised.
 *
 * SHARED JS (additive, backward compatible, bit-exact for siblings):
 * StructureBuilder wall_end/wall_end_outside l-shape anchors (no shipped
 * descriptor referenced them); descriptor glass accepts roughness + tier;
 * TierResolve 'cheap' declared glass class. No exporter change — every
 * key already rides the s6 venue-owned sets.
 *
 * SAFETY (the guarded pattern; see 2026_09_07_000001 for the rationale)
 * ---------------------------------------------------------------------
 * Changed values swap ONLY from the seeded v2.0.0 value; added keys are
 * union-added; the structure and fixture lists swap only on an EXACT
 * match with the seeded v2.0.0 lists; down() restores the exact previous
 * state under mirrored guards. Idempotent. The venue_config cache re-keys
 * from the row contents, so the pass is live on the next render.
 */
return new class extends Migration
{
    private const SLUG = 'luxury-penthouse';

    private const OLD_VERSION = '2.0.0';
    private const NEW_VERSION = '2.1.0';

    /**
     * The seeded v2.0.0 structure array ("The Collector's Floor",
     * verbatim from VenueTemplateSeeder + 2026_09_08_000005). The swap
     * guard compares against THIS — a super-admin's edited structure is
     * respected.
     */
    private const OLD_STRUCTURE = [
        ['id' => 'terrace-deck', 'primitive' => 'box', 'at' => ['from' => 'glazing_outside', 'offset' => [0, 0.04, 2.6]], 'turn' => 'out', 'fit' => 'glazing', 'fit_pad' => 0.1, 'size' => [1, 0.08, 5.0], 'material' => 'wood_warm'],
        ['id' => 'glazing-glass', 'primitive' => 'plane', 'at' => ['from' => 'glazing', 'offset' => [0, 2.45, 0]], 'turn' => 'in', 'fit' => 'glazing', 'fit_pad' => 0.06, 'size' => [1, 4.9], 'material' => ['glass' => true, 'tint' => '0xc4d8ea', 'opacity' => 0.18]],
        ['id' => 'glazing-mullions', 'primitive' => 'instance-grid', 'at' => ['from' => 'glazing', 'offset' => [0, 2.45, 0.05]], 'turn' => 'in', 'size' => [0.06, 4.9, 0.085], 'material' => 'steel_dark', 'merge' => 'ph-steel', 'grid' => ['mode' => 'line', 'from' => 'glazing', 'span' => 'fit', 'fit_pad' => 0.16, 'spacing' => 1.4]],
        ['id' => 'glazing-sill', 'primitive' => 'box', 'at' => ['from' => 'glazing', 'offset' => [0, 0.05, 0]], 'turn' => 'in', 'fit' => 'glazing', 'fit_pad' => 0.02, 'size' => [1, 0.1, 0.1], 'material' => 'steel_dark', 'merge' => 'ph-steel', 'collide' => true],
        ['id' => 'glazing-head', 'primitive' => 'box', 'at' => ['from' => 'glazing', 'offset' => [0, 5.12, 0]], 'turn' => 'in', 'fit' => 'glazing', 'fit_pad' => 0.02, 'size' => [1, 0.12, 0.1], 'material' => 'steel_dark', 'merge' => 'ph-steel'],
        ['id' => 'rail-bar', 'primitive' => 'box', 'at' => ['from' => 'glazing', 'offset' => [0, 0.95, 0.55]], 'turn' => 'in', 'fit' => 'glazing', 'fit_pad' => 0.5, 'size' => [1, 0.05, 0.05], 'material' => 'steel_dark', 'merge' => 'ph-rail', 'collide' => true],
        ['id' => 'rail-posts', 'primitive' => 'instance-grid', 'at' => ['from' => 'glazing', 'offset' => [0, 0.45, 0.55]], 'turn' => 'in', 'size' => [0.035, 0.9, 0.035], 'material' => 'steel_dark', 'merge' => 'ph-rail', 'grid' => ['mode' => 'line', 'from' => 'glazing', 'span' => 'fit', 'fit_pad' => 0.7, 'spacing' => 1.8]],
        ['id' => 'skyline-cool', 'primitive' => 'instance-grid', 'size' => [2.2, 10, 2.2], 'material' => ['color' => '0x0b1220', 'emissive' => '0x8fb4dd', 'emissiveIntensity' => 0.14], 'grid' => ['mode' => 'scatter', 'count' => 9, 'seed' => 'skyline-cool', 'area' => ['from' => 'glazing_outside', 'size' => [30, 0, 36], 'forward' => 24], 'scale_jitter' => [0.6, 1.5], 'grounded' => true, 'yaw_jitter' => [0, 3.14159]]],
        ['id' => 'skyline-warm', 'primitive' => 'instance-grid', 'size' => [1.5, 14, 1.5], 'material' => ['color' => '0x12100e', 'emissive' => '0xd8b98f', 'emissiveIntensity' => 0.11], 'grid' => ['mode' => 'scatter', 'count' => 6, 'seed' => 'skyline-warm', 'area' => ['from' => 'glazing_outside', 'size' => [30, 0, 36], 'forward' => 26], 'scale_jitter' => [0.5, 1.2], 'grounded' => true, 'yaw_jitter' => [0, 3.14159]]],
        ['id' => 'skyline-far', 'primitive' => 'instance-grid', 'size' => [3.4, 22, 3.4], 'material' => ['color' => '0x0d0f16', 'emissive' => '0x5a6f96', 'emissiveIntensity' => 0.1], 'grid' => ['mode' => 'scatter', 'count' => 8, 'seed' => 'skyline-far-b', 'area' => ['from' => 'glazing_outside', 'size' => [50, 0, 64], 'forward' => 30], 'scale_jitter' => [0.5, 1.0], 'grounded' => true, 'yaw_jitter' => [0, 3.14159]]],
        ['id' => 'horizon-glow', 'primitive' => 'plane', 'at' => ['from' => 'glazing_outside', 'offset' => [0, 6.0, 24]], 'turn' => 'in', 'size' => [64, 10], 'material' => ['color' => '0x0e1017', 'emissive' => '0x8a6a40', 'emissiveIntensity' => 1.0]],
        ['id' => 'fireplace-stone', 'primitive' => 'box', 'at' => ['from' => 'wall_front', 'offset' => [0, 2.6, 0.11]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 1.6, 'size' => [1, 5.2, 0.2], 'material' => 'basalt'],
        ['id' => 'fireplace-mantel', 'primitive' => 'box', 'at' => ['from' => 'wall_front', 'offset' => [0, 1.3, 0.32]], 'turn' => 'in', 'size' => [2.6, 0.07, 0.3], 'material' => 'walnut'],
        ['id' => 'fireplace-band', 'primitive' => 'emissive-strip', 'at' => ['from' => 'wall_front', 'offset' => [0, 0.62, 0.365]], 'turn' => 'in', 'size' => [1.9, 0.16, 0.05], 'material' => ['color' => '0x1a0d06', 'emissive' => '0xff8a3d', 'emissiveIntensity' => 1.5]],
        ['id' => 'fireplace-hearth', 'primitive' => 'box', 'at' => ['from' => 'wall_front', 'offset' => [0, 0.03, 0.45]], 'turn' => 'in', 'size' => [3.0, 0.06, 0.55], 'material' => 'basalt'],
        ['id' => 'cove-left', 'primitive' => 'emissive-strip', 'at' => ['from' => 'wall_left', 'up' => 'ceiling', 'offset' => [0, -0.18, 0.22]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 1.0, 'size' => [1, 0.05, 0.09], 'material' => ['color' => '0x201810', 'emissive' => '0xffd9a0', 'emissiveIntensity' => 1.35], 'merge' => 'ph-cove'],
        ['id' => 'cove-right', 'primitive' => 'emissive-strip', 'at' => ['from' => 'wall_right', 'up' => 'ceiling', 'offset' => [0, -0.18, 0.22]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 1.0, 'size' => [1, 0.05, 0.09], 'material' => ['color' => '0x201810', 'emissive' => '0xffd9a0', 'emissiveIntensity' => 1.35], 'merge' => 'ph-cove'],
        ['id' => 'cove-inner', 'primitive' => 'emissive-strip', 'at' => ['from' => 'wall_inner', 'up' => 'ceiling', 'offset' => [0, -0.18, 0.22]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 1.0, 'size' => [1, 0.05, 0.09], 'material' => ['color' => '0x201810', 'emissive' => '0xffd9a0', 'emissiveIntensity' => 1.35], 'merge' => 'ph-cove'],
        ['id' => 'cove-back', 'primitive' => 'emissive-strip', 'at' => ['from' => 'wall_back', 'up' => 'ceiling', 'offset' => [0, -0.18, 0.22]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 1.0, 'size' => [1, 0.05, 0.09], 'material' => ['color' => '0x201810', 'emissive' => '0xffd9a0', 'emissiveIntensity' => 1.35], 'merge' => 'ph-cove'],
        ['id' => 'base-left', 'primitive' => 'box', 'at' => ['from' => 'wall_left', 'offset' => [0, 0.07, 0.02]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.4, 'size' => [1, 0.14, 0.03], 'material' => 'bronze', 'merge' => 'ph-base'],
        ['id' => 'base-right', 'primitive' => 'box', 'at' => ['from' => 'wall_right', 'offset' => [0, 0.07, 0.02]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.4, 'size' => [1, 0.14, 0.03], 'material' => 'bronze', 'merge' => 'ph-base'],
        ['id' => 'base-inner', 'primitive' => 'box', 'at' => ['from' => 'wall_inner', 'offset' => [0, 0.07, 0.02]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.4, 'size' => [1, 0.14, 0.03], 'material' => 'bronze', 'merge' => 'ph-base'],
        ['id' => 'base-back', 'primitive' => 'box', 'at' => ['from' => 'wall_back', 'offset' => [0, 0.07, 0.02]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.4, 'size' => [1, 0.14, 0.03], 'material' => 'bronze', 'merge' => 'ph-base'],
        ['id' => 'lounge-pendant', 'primitive' => 'emissive-strip', 'at' => ['from' => 'glazing', 'offset' => [0, 4.35, 1.75]], 'turn' => 'in', 'fit' => 'glazing', 'fit_pad' => 2.6, 'size' => [1, 0.045, 0.045], 'material' => ['color' => '0xffd9a8', 'emissive' => '0xffc98a', 'emissiveIntensity' => 2.2]],
        ['id' => 'lounge-rug', 'primitive' => 'plane', 'at' => ['from' => 'glazing', 'offset' => [0, 0.012, 2.0]], 'turn' => 'in', 'rot' => [-1.5707963, 0, 0], 'size' => [3.6, 2.6], 'material' => 'fabric_dark'],
        ['id' => 'sofa-base', 'primitive' => 'box', 'at' => ['from' => 'glazing', 'offset' => [0, 0.24, 1.75]], 'turn' => 'in', 'size' => [2.3, 0.48, 0.95], 'material' => 'fabric_warm', 'collide' => true],
        ['id' => 'sofa-back', 'primitive' => 'box', 'at' => ['from' => 'glazing', 'offset' => [0, 0.72, 2.15]], 'turn' => 'in', 'size' => [2.3, 0.5, 0.24], 'material' => 'fabric_warm'],
        ['id' => 'sofa-arm-l', 'primitive' => 'box', 'at' => ['from' => 'glazing', 'offset' => [-1.26, 0.42, 1.78]], 'turn' => 'in', 'size' => [0.22, 0.36, 0.9], 'material' => 'fabric_warm'],
        ['id' => 'sofa-arm-r', 'primitive' => 'box', 'at' => ['from' => 'glazing', 'offset' => [1.26, 0.42, 1.78]], 'turn' => 'in', 'size' => [0.22, 0.36, 0.9], 'material' => 'fabric_warm'],
        ['id' => 'table-top', 'primitive' => 'box', 'at' => ['from' => 'glazing', 'offset' => [0, 0.34, 0.9]], 'turn' => 'in', 'size' => [1.15, 0.05, 0.55], 'material' => 'wood_dark'],
        ['id' => 'table-pedestal', 'primitive' => 'box', 'at' => ['from' => 'glazing', 'offset' => [0, 0.15, 0.9]], 'turn' => 'in', 'size' => [0.5, 0.3, 0.35], 'material' => 'dark_trim', 'collide' => true],
        ['id' => 'chair-seat', 'primitive' => 'box', 'at' => ['from' => 'glazing', 'offset' => [-1.95, 0.3, 2.9]], 'turn' => 'in', 'size' => [0.85, 0.44, 0.85], 'material' => 'fabric_dark', 'collide' => true],
        ['id' => 'chair-back', 'primitive' => 'box', 'at' => ['from' => 'glazing', 'offset' => [-1.95, 0.63, 3.35]], 'turn' => 'in', 'size' => [0.12, 0.55, 0.85], 'material' => 'fabric_dark'],
        ['id' => 'lamp-pole', 'primitive' => 'cylinder', 'at' => ['from' => 'glazing', 'offset' => [2.05, 0.8, 2.6]], 'turn' => 'in', 'size' => [0.035, 1.6, 0.035], 'material' => 'steel_dark'],
        ['id' => 'lamp-shade', 'primitive' => 'emissive-strip', 'at' => ['from' => 'glazing', 'offset' => [2.05, 1.68, 2.6]], 'turn' => 'in', 'size' => [0.36, 0.32, 0.36], 'material' => ['color' => '0x2a2018', 'emissive' => '0xffd9a0', 'emissiveIntensity' => 0.8]],
        ['id' => 'plinth', 'primitive' => 'cylinder', 'at' => ['from' => 'glazing', 'offset' => [2.35, 0.55, 4.35]], 'turn' => 'in', 'size' => [0.42, 1.1, 0.42], 'material' => 'basalt', 'collide' => true],
        ['id' => 'sculpture-torus', 'primitive' => 'torus', 'at' => ['from' => 'glazing', 'offset' => [2.35, 1.42, 4.35]], 'turn' => 'in', 'size' => [0.3, 0.09, 0.3], 'params' => ['seg' => 24, 'seg2' => 48], 'material' => 'bronze'],
        ['id' => 'bench-top', 'primitive' => 'box', 'at' => ['from' => 'wall_left', 'offset' => [0, 0.44, 0.42]], 'turn' => 'in', 'size' => [2.2, 0.06, 0.45], 'material' => 'walnut'],
        ['id' => 'bench-base', 'primitive' => 'box', 'at' => ['from' => 'wall_left', 'offset' => [0, 0.21, 0.42]], 'turn' => 'in', 'size' => [2.0, 0.42, 0.38], 'material' => 'dark_trim', 'collide' => true],
        ['id' => 'floor-joints', 'primitive' => 'instance-grid', 'size' => [5.4, 0.012, 0.028], 'material' => ['color' => '0x40372a', 'roughness' => 0.55, 'metalness' => 0.02], 'grid' => ['mode' => 'box', 'area' => ['from' => 'center', 'y' => 0.006, 'size' => ['fit' => 'room', 'pad' => [0.3, 0.9]]], 'spacing' => [0, 0, 2.4]]],
    ];

    /**
     * The v2.1.0 structure — "Evening Light": the same residence with a
     * legible hang, a low dusk skyline that actually renders, the
     * fireplace at the corridor terminus and coved ceilings with real
     * cove light.
     */
    private const NEW_STRUCTURE = [
        ['id' => 'terrace-deck', 'primitive' => 'box', 'at' => ['from' => 'glazing_outside', 'offset' => [0, 0.04, 2.6]], 'turn' => 'out', 'fit' => 'glazing', 'fit_pad' => 0.1, 'size' => [1, 0.08, 5.0], 'material' => 'wood_warm'],
        ['id' => 'glazing-glass', 'primitive' => 'plane', 'at' => ['from' => 'glazing', 'offset' => [0, 2.45, 0]], 'turn' => 'in', 'fit' => 'glazing', 'fit_pad' => 0.06, 'size' => [1, 4.9], 'material' => ['glass' => true, 'tint' => '0xd8e4ef', 'opacity' => 0.1, 'roughness' => 0.35, 'tier' => 'cheap']],
        ['id' => 'glazing-mullions', 'primitive' => 'instance-grid', 'at' => ['from' => 'glazing', 'offset' => [0, 2.45, 0.05]], 'turn' => 'in', 'size' => [0.06, 4.9, 0.085], 'material' => 'steel_dark', 'merge' => 'ph-steel', 'grid' => ['mode' => 'line', 'from' => 'glazing', 'span' => 'fit', 'fit_pad' => 0.16, 'spacing' => 1.4]],
        ['id' => 'glazing-sill', 'primitive' => 'box', 'at' => ['from' => 'glazing', 'offset' => [0, 0.05, 0]], 'turn' => 'in', 'fit' => 'glazing', 'fit_pad' => 0.02, 'size' => [1, 0.1, 0.1], 'material' => 'steel_dark', 'merge' => 'ph-steel', 'collide' => true],
        ['id' => 'glazing-head', 'primitive' => 'box', 'at' => ['from' => 'glazing', 'offset' => [0, 5.12, 0]], 'turn' => 'in', 'fit' => 'glazing', 'fit_pad' => 0.02, 'size' => [1, 0.12, 0.1], 'material' => 'steel_dark', 'merge' => 'ph-steel'],
        ['id' => 'rail-bar', 'primitive' => 'box', 'at' => ['from' => 'glazing', 'offset' => [0, 0.95, 0.55]], 'turn' => 'in', 'fit' => 'glazing', 'fit_pad' => 0.5, 'size' => [1, 0.05, 0.05], 'material' => 'steel_dark', 'merge' => 'ph-rail', 'collide' => true],
        ['id' => 'rail-posts', 'primitive' => 'instance-grid', 'at' => ['from' => 'glazing', 'offset' => [0, 0.45, 0.55]], 'turn' => 'in', 'size' => [0.035, 0.9, 0.035], 'material' => 'steel_dark', 'merge' => 'ph-rail', 'grid' => ['mode' => 'line', 'from' => 'glazing', 'span' => 'fit', 'fit_pad' => 0.7, 'spacing' => 1.8]],
        ['id' => 'skyline-near', 'primitive' => 'instance-grid', 'size' => [1.7, 9, 1.7], 'material' => ['color' => '0x0f0d0a', 'emissive' => '0x7a5533', 'emissiveIntensity' => 0.42], 'grid' => ['mode' => 'scatter', 'count' => 6, 'seed' => 'skyline-cool', 'area' => ['from' => 'glazing_outside', 'size' => [34, 0, 20], 'forward' => 24], 'scale_jitter' => [0.4, 0.7], 'grounded' => true, 'yaw_jitter' => [0, 3.14159]]],
        ['id' => 'skyline-mid', 'primitive' => 'instance-grid', 'size' => [2.4, 9, 2.4], 'material' => ['color' => '0x0d1119', 'emissive' => '0x33415c', 'emissiveIntensity' => 0.3], 'grid' => ['mode' => 'scatter', 'count' => 8, 'seed' => 'skyline-warm', 'area' => ['from' => 'glazing_outside', 'size' => [36, 0, 24], 'forward' => 28], 'scale_jitter' => [0.6, 1.3], 'grounded' => true, 'yaw_jitter' => [0, 3.14159]]],
        ['id' => 'skyline-far', 'primitive' => 'instance-grid', 'size' => [3.4, 14, 3.4], 'material' => ['color' => '0x0a0d15', 'emissive' => '0x1c2740', 'emissiveIntensity' => 0.38], 'grid' => ['mode' => 'scatter', 'count' => 8, 'seed' => 'skyline-far-b', 'area' => ['from' => 'glazing_outside', 'size' => [50, 0, 52], 'forward' => 36], 'scale_jitter' => [0.6, 1.3], 'grounded' => true, 'yaw_jitter' => [0, 3.14159]]],
        ['id' => 'horizon-glow', 'primitive' => 'plane', 'at' => ['from' => 'glazing_outside', 'offset' => [0, 8.0, 44]], 'turn' => 'out', 'size' => [130, 26], 'material' => ['color' => '0x140f08', 'emissive' => '0x9a6238', 'emissiveIntensity' => 2.2]],
        ['id' => 'city-haze', 'primitive' => 'plane', 'at' => ['from' => 'glazing_outside', 'offset' => [0, 8.0, 32]], 'turn' => 'out', 'size' => [110, 22], 'material' => ['color' => '0x0d0a06', 'emissive' => '0x5f4126', 'emissiveIntensity' => 1.8]],
        ['id' => 'sky-mid', 'primitive' => 'plane', 'at' => ['from' => 'glazing_outside', 'offset' => [0, 15, 64]], 'turn' => 'out', 'size' => [130, 26], 'material' => ['color' => '0x0a0e18', 'emissive' => '0x445c82', 'emissiveIntensity' => 1.0]],
        ['id' => 'sky-deep', 'primitive' => 'plane', 'at' => ['from' => 'glazing_outside', 'offset' => [0, 22, 86]], 'turn' => 'out', 'size' => [150, 50], 'material' => ['color' => '0x070a12', 'emissive' => '0x111c33', 'emissiveIntensity' => 1.0]],
        ['id' => 'fireplace-stone', 'primitive' => 'box', 'at' => ['from' => 'wall_end', 'offset' => [0, 2.6, 0.11]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 1.6, 'size' => [1, 5.2, 0.2], 'material' => 'basalt'],
        ['id' => 'fireplace-mantel', 'primitive' => 'box', 'at' => ['from' => 'wall_end', 'offset' => [0, 1.3, 0.32]], 'turn' => 'in', 'size' => [2.6, 0.07, 0.3], 'material' => 'walnut'],
        ['id' => 'fireplace-band', 'primitive' => 'emissive-strip', 'at' => ['from' => 'wall_end', 'offset' => [0, 0.62, 0.365]], 'turn' => 'in', 'size' => [1.9, 0.16, 0.05], 'material' => ['color' => '0x1a0d06', 'emissive' => '0xff8a3d', 'emissiveIntensity' => 1.5]],
        ['id' => 'fireplace-hearth', 'primitive' => 'box', 'at' => ['from' => 'wall_end', 'offset' => [0, 0.03, 0.45]], 'turn' => 'in', 'size' => [3.0, 0.06, 0.55], 'material' => 'basalt'],
        ['id' => 'cove-left', 'primitive' => 'emissive-strip', 'at' => ['from' => 'wall_left', 'up' => 'ceiling', 'offset' => [0, -0.3, 0.24]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.9, 'size' => [1, 0.05, 0.09], 'material' => ['color' => '0x201810', 'emissive' => '0xffd9a0', 'emissiveIntensity' => 1.15], 'merge' => 'ph-cove'],
        ['id' => 'cove-shelf-left', 'primitive' => 'box', 'at' => ['from' => 'wall_left', 'up' => 'ceiling', 'offset' => [0, -0.22, 0.32]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.9, 'size' => [1, 0.03, 0.2], 'material' => 'dark_trim', 'merge' => 'ph-coveshelf'],
        ['id' => 'cove-right', 'primitive' => 'emissive-strip', 'at' => ['from' => 'wall_right', 'up' => 'ceiling', 'offset' => [0, -0.3, 0.24]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.9, 'size' => [1, 0.05, 0.09], 'material' => ['color' => '0x201810', 'emissive' => '0xffd9a0', 'emissiveIntensity' => 1.15], 'merge' => 'ph-cove'],
        ['id' => 'cove-shelf-right', 'primitive' => 'box', 'at' => ['from' => 'wall_right', 'up' => 'ceiling', 'offset' => [0, -0.22, 0.32]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.9, 'size' => [1, 0.03, 0.2], 'material' => 'dark_trim', 'merge' => 'ph-coveshelf'],
        ['id' => 'cove-inner', 'primitive' => 'emissive-strip', 'at' => ['from' => 'wall_inner', 'up' => 'ceiling', 'offset' => [0, -0.3, 0.24]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.9, 'size' => [1, 0.05, 0.09], 'material' => ['color' => '0x201810', 'emissive' => '0xffd9a0', 'emissiveIntensity' => 1.15], 'merge' => 'ph-cove'],
        ['id' => 'cove-shelf-inner', 'primitive' => 'box', 'at' => ['from' => 'wall_inner', 'up' => 'ceiling', 'offset' => [0, -0.22, 0.32]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.9, 'size' => [1, 0.03, 0.2], 'material' => 'dark_trim', 'merge' => 'ph-coveshelf'],
        ['id' => 'cove-back', 'primitive' => 'emissive-strip', 'at' => ['from' => 'wall_back', 'up' => 'ceiling', 'offset' => [0, -0.3, 0.24]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.9, 'size' => [1, 0.05, 0.09], 'material' => ['color' => '0x201810', 'emissive' => '0xffd9a0', 'emissiveIntensity' => 1.15], 'merge' => 'ph-cove'],
        ['id' => 'cove-shelf-back', 'primitive' => 'box', 'at' => ['from' => 'wall_back', 'up' => 'ceiling', 'offset' => [0, -0.22, 0.32]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.9, 'size' => [1, 0.03, 0.2], 'material' => 'dark_trim', 'merge' => 'ph-coveshelf'],
        ['id' => 'base-left', 'primitive' => 'box', 'at' => ['from' => 'wall_left', 'offset' => [0, 0.07, 0.02]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.4, 'size' => [1, 0.14, 0.03], 'material' => 'bronze', 'merge' => 'ph-base'],
        ['id' => 'base-right', 'primitive' => 'box', 'at' => ['from' => 'wall_right', 'offset' => [0, 0.07, 0.02]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.4, 'size' => [1, 0.14, 0.03], 'material' => 'bronze', 'merge' => 'ph-base'],
        ['id' => 'base-inner', 'primitive' => 'box', 'at' => ['from' => 'wall_inner', 'offset' => [0, 0.07, 0.02]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.4, 'size' => [1, 0.14, 0.03], 'material' => 'bronze', 'merge' => 'ph-base'],
        ['id' => 'base-back', 'primitive' => 'box', 'at' => ['from' => 'wall_back', 'offset' => [0, 0.07, 0.02]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.4, 'size' => [1, 0.14, 0.03], 'material' => 'bronze', 'merge' => 'ph-base'],
        ['id' => 'lounge-pendant', 'primitive' => 'emissive-strip', 'at' => ['from' => 'glazing', 'offset' => [0, 4.35, 1.75]], 'turn' => 'in', 'fit' => 'glazing', 'fit_pad' => 2.6, 'size' => [1, 0.045, 0.045], 'material' => ['color' => '0xffd9a8', 'emissive' => '0xffc98a', 'emissiveIntensity' => 2.2]],
        ['id' => 'lounge-rug', 'primitive' => 'plane', 'at' => ['from' => 'glazing', 'offset' => [0, 0.012, 2.0]], 'turn' => 'in', 'rot' => [-1.5707963, 0, 0], 'size' => [3.6, 2.6], 'material' => 'fabric_dark'],
        ['id' => 'sofa-base', 'primitive' => 'box', 'at' => ['from' => 'glazing', 'offset' => [0, 0.24, 1.75]], 'turn' => 'in', 'size' => [2.3, 0.48, 0.95], 'material' => 'fabric_warm', 'collide' => true],
        ['id' => 'sofa-back', 'primitive' => 'box', 'at' => ['from' => 'glazing', 'offset' => [0, 0.72, 2.15]], 'turn' => 'in', 'size' => [2.3, 0.5, 0.24], 'material' => 'fabric_warm'],
        ['id' => 'sofa-arm-l', 'primitive' => 'box', 'at' => ['from' => 'glazing', 'offset' => [-1.26, 0.42, 1.78]], 'turn' => 'in', 'size' => [0.22, 0.36, 0.9], 'material' => 'fabric_warm'],
        ['id' => 'sofa-arm-r', 'primitive' => 'box', 'at' => ['from' => 'glazing', 'offset' => [1.26, 0.42, 1.78]], 'turn' => 'in', 'size' => [0.22, 0.36, 0.9], 'material' => 'fabric_warm'],
        ['id' => 'table-top', 'primitive' => 'box', 'at' => ['from' => 'glazing', 'offset' => [0, 0.34, 0.9]], 'turn' => 'in', 'size' => [1.15, 0.05, 0.55], 'material' => 'wood_dark'],
        ['id' => 'table-pedestal', 'primitive' => 'box', 'at' => ['from' => 'glazing', 'offset' => [0, 0.15, 0.9]], 'turn' => 'in', 'size' => [0.5, 0.3, 0.35], 'material' => 'dark_trim', 'collide' => true],
        ['id' => 'chair-seat', 'primitive' => 'box', 'at' => ['from' => 'glazing', 'offset' => [-1.95, 0.3, 2.9]], 'turn' => 'in', 'size' => [0.85, 0.44, 0.85], 'material' => 'fabric_dark', 'collide' => true],
        ['id' => 'chair-back', 'primitive' => 'box', 'at' => ['from' => 'glazing', 'offset' => [-1.95, 0.63, 3.35]], 'turn' => 'in', 'size' => [0.12, 0.55, 0.85], 'material' => 'fabric_dark'],
        ['id' => 'lamp-pole', 'primitive' => 'cylinder', 'at' => ['from' => 'glazing', 'offset' => [2.05, 0.8, 2.6]], 'turn' => 'in', 'size' => [0.035, 1.6, 0.035], 'material' => 'steel_dark'],
        ['id' => 'lamp-shade', 'primitive' => 'emissive-strip', 'at' => ['from' => 'glazing', 'offset' => [2.05, 1.68, 2.6]], 'turn' => 'in', 'size' => [0.36, 0.32, 0.36], 'material' => ['color' => '0x2a2018', 'emissive' => '0xffd9a0', 'emissiveIntensity' => 0.8]],
        ['id' => 'plinth', 'primitive' => 'cylinder', 'at' => ['from' => 'glazing', 'offset' => [2.35, 0.55, 4.35]], 'turn' => 'in', 'size' => [0.42, 1.1, 0.42], 'material' => 'basalt', 'collide' => true],
        ['id' => 'sculpture-torus', 'primitive' => 'torus', 'at' => ['from' => 'glazing', 'offset' => [2.35, 1.42, 4.35]], 'turn' => 'in', 'size' => [0.3, 0.09, 0.3], 'params' => ['seg' => 24, 'seg2' => 48], 'material' => 'bronze'],
        ['id' => 'bench-top', 'primitive' => 'box', 'at' => ['from' => 'wall_left', 'offset' => [0, 0.44, 0.42]], 'turn' => 'in', 'size' => [2.2, 0.06, 0.45], 'material' => 'walnut'],
        ['id' => 'bench-base', 'primitive' => 'box', 'at' => ['from' => 'wall_left', 'offset' => [0, 0.21, 0.42]], 'turn' => 'in', 'size' => [2.0, 0.42, 0.38], 'material' => 'dark_trim', 'collide' => true],
        ['id' => 'floor-joints', 'primitive' => 'instance-grid', 'size' => [5.4, 0.012, 0.02], 'material' => ['color' => '0x6b5f4c', 'roughness' => 0.55, 'metalness' => 0.02], 'grid' => ['mode' => 'box', 'area' => ['from' => 'center', 'y' => 0.006, 'size' => ['fit' => 'room', 'pad' => [0.3, 0.9]]], 'spacing' => [0, 0, 2.4]]],
    ];

    private const OLD_FIXTURES = [
        ['id' => 'fire-glow', 'type' => 'point', 'anchor' => ['from' => 'wall_front', 'offset' => [0, 0.95, 1.1]], 'color' => '0xff9a50', 'intensity' => 16, 'distance' => 7, 'decay' => 1.8, 'cast_shadow' => false],
        ['id' => 'hearth-wash', 'type' => 'point', 'anchor' => ['from' => 'wall_front', 'offset' => [0, 4.2, 1.3]], 'color' => '0xffd9a0', 'intensity' => 3.5, 'distance' => 5, 'decay' => 1.6, 'cast_shadow' => false],
    ];

    private const NEW_FIXTURES = [
        ['id' => 'fire-glow', 'type' => 'point', 'anchor' => ['from' => 'wall_end', 'offset' => [0, 0.95, 1.1]], 'color' => '0xff9a50', 'intensity' => 5, 'distance' => 6, 'decay' => 2, 'cast_shadow' => false],
        ['id' => 'hearth-wash', 'type' => 'point', 'anchor' => ['from' => 'wall_end', 'offset' => [0, 4.2, 1.3]], 'color' => '0xffd9a0', 'intensity' => 2.2, 'distance' => 5, 'decay' => 1.8, 'cast_shadow' => false],
        ['id' => 'cove-wash-a', 'type' => 'point', 'anchor' => ['from' => 'wall_left', 'offset' => [0, 4.1, 2.6]], 'color' => '0xffd9a0', 'intensity' => 3.2, 'distance' => 12, 'decay' => 1.8, 'cast_shadow' => false],
        ['id' => 'cove-wash-b', 'type' => 'point', 'anchor' => ['from' => 'wall_inner', 'offset' => [0, 4.1, 2.6]], 'color' => '0xffd9a0', 'intensity' => 3.2, 'distance' => 12, 'decay' => 1.8, 'cast_shadow' => false],
        ['id' => 'lounge-wash', 'type' => 'point', 'anchor' => ['from' => 'glazing', 'offset' => [0, 3.9, 3.4]], 'color' => '0xffe2b8', 'intensity' => 1.8, 'distance' => 8, 'decay' => 1.8, 'cast_shadow' => false],
    ];

    public function up(): void
    {
        $row = DB::table('venue_templates')->where('slug', self::SLUG)->first(['id', 'visual_config', 'material_config', 'lighting_fixtures', 'description', 'version']);
        if (!$row) {
            return; // venue removed by the operator — respect that
        }

        $visual   = json_decode((string) $row->visual_config, true) ?: [];
        $material = json_decode((string) $row->material_config, true) ?: [];

        // Changed values — only while still equal to the seeded v2.0.0 value.
        foreach ($this->changedVisualKeys() as $key => ['from' => $from, 'to' => $to]) {
            if (($visual[$key] ?? null) === $from) {
                $visual[$key] = $to;
            }
        }

        // Added keys — union (absent key only).
        foreach ($this->addedVisualKeys() as $key => $value) {
            if (!array_key_exists($key, $visual)) {
                $visual[$key] = $value;
            }
        }

        // Structure swap (D1/D3/D4/D5) — EXACT match with the seeded
        // v2.0.0 descriptor list only.
        if (($visual['structure'] ?? null) === self::OLD_STRUCTURE) {
            $visual['structure'] = self::NEW_STRUCTURE;
        }

        // Material config — guarded changed (honed floor, D2).
        foreach ($this->materialChanges()['changed'] as $key => ['from' => $from, 'to' => $to]) {
            if (($material[$key] ?? null) === $from) {
                $material[$key] = $to;
            }
        }

        $update = [
            'visual_config'   => json_encode($visual),
            'material_config' => json_encode($material),
        ];

        // Fixtures (D5/D6) — exact-match swap of the seeded v2.0.0 list;
        // the cove washes + terminus fireplace replace the south-corner
        // floodlight pair.
        $fixtures = json_decode((string) $row->lighting_fixtures, true) ?: [];
        if ($fixtures === self::OLD_FIXTURES) {
            $update['lighting_fixtures'] = json_encode(self::NEW_FIXTURES);
        }

        if ($row->version === self::OLD_VERSION) {
            $update['version'] = self::NEW_VERSION;
        }

        DB::table('venue_templates')->where('id', $row->id)->update($update);
    }

    public function down(): void
    {
        $row = DB::table('venue_templates')->where('slug', self::SLUG)->first(['id', 'visual_config', 'material_config', 'lighting_fixtures', 'description', 'version']);
        if (!$row) {
            return;
        }

        $visual   = json_decode((string) $row->visual_config, true) ?: [];
        $material = json_decode((string) $row->material_config, true) ?: [];

        foreach ($this->changedVisualKeys() as $key => ['from' => $from, 'to' => $to]) {
            if (($visual[$key] ?? null) === $to) {
                $visual[$key] = $from;
            }
        }

        foreach ($this->addedVisualKeys() as $key => $value) {
            if (($visual[$key] ?? null) === $value) {
                unset($visual[$key]);
            }
        }

        if (($visual['structure'] ?? null) === self::NEW_STRUCTURE) {
            $visual['structure'] = self::OLD_STRUCTURE;
        }

        foreach ($this->materialChanges()['changed'] as $key => ['from' => $from, 'to' => $to]) {
            if (($material[$key] ?? null) === $to) {
                $material[$key] = $from;
            }
        }

        $update = [
            'visual_config'   => json_encode($visual),
            'material_config' => json_encode($material),
        ];

        $fixtures = json_decode((string) $row->lighting_fixtures, true) ?: [];
        if ($fixtures === self::NEW_FIXTURES) {
            $update['lighting_fixtures'] = json_encode(self::OLD_FIXTURES);
        }

        if ($row->version === self::NEW_VERSION) {
            $update['version'] = self::OLD_VERSION;
        }

        DB::table('venue_templates')->where('id', $row->id)->update($update);
    }

    private function changedVisualKeys(): array
    {
        return [
            'ceiling_color' => ['from' => '0x14110d', 'to' => '0x5c4c3a'],
            'background_color' => ['from' => '0x0a0b11', 'to' => '0x0b0f1a'],
            'fog_color' => ['from' => '0x0a0b10', 'to' => '0x191c26'],
            'fog_near' => ['from' => 16, 'to' => 26],
            'fog_far' => ['from' => 55, 'to' => 160],
            'ambient_color' => ['from' => '0xe6d6bc', 'to' => '0xe9dfcd'],
            'ambient_intensity' => ['from' => 0.26, 'to' => 0.42],
            'spot_intensity' => ['from' => 0.62, 'to' => 0.5],
            'fill_intensity' => ['from' => 0.16, 'to' => 0.42],
            'tone_mapping_exposure' => ['from' => 0.78, 'to' => 0.92],
            'hemisphere_intensity' => ['from' => 0.14, 'to' => 0.3],
            'artwork_light_base' => ['from' => 0.22, 'to' => 0.5],
        ];
    }

    private function addedVisualKeys(): array
    {
        return [

        ];
    }

    private function materialChanges(): array
    {
        return [
            'changed' => [
                'floor_roughness' => ['from' => 0.42, 'to' => 0.62],
                'floor_metalness' => ['from' => 0.06, 'to' => 0.03],
            ],
        ];
    }
};
