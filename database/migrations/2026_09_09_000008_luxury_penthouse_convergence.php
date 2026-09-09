<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * LUXURY PENTHOUSE — production convergence repair (3.0.0, corrective).
 * (2026-09-09. Follows 2026_09_09_000007; repairs the row that chain
 * skipped.)
 *
 * ROOT CAUSE (forensic, 2026-09-09)
 * ---------------------------------
 * The live production row renders the v1.0.0 "Rooms" descriptor body
 * (17 descriptors — the blue/warm preset-tower skyline) wrapped in v3.0.0
 * scalars, while `migrate:status` reports batches 53–55 as RAN. Reason: a
 * venue-template editor save (the SuperAdmin update path) re-serialized
 * `visual_config` through a browser JSON round-trip BEFORE the chain ran:
 *
 *   • float 5.0 was stored as int 5  (JS `JSON.stringify(5.0)` → "5"), and
 *   • every descriptor's keys were re-sorted alphabetically.
 *
 * Every guard in 000005/000006/000007 compares the decoded row with `===`.
 * PHP `int(5) === float(5.0)` is FALSE, so the structure swaps SILENTLY
 * skipped (the scalar guards — 4.5→5.2→6.3, bronze, the description — all
 * matched, which is exactly the Frankenstein state production served). The
 * version string reads 3.0.0 over a v1.0.0 building.
 *
 * THE FIX
 * -------
 * One corrective pass that converges the row using SEMANTIC guards instead
 * of `===`:
 *
 *   • numbers compare by VALUE (int 5 ≡ float 5.0) — the historical drift
 *     shape can never skip this guard;
 *   • maps compare key-order-insensitively; lists stay order-strict;
 *   • the swap fires ONLY while the row still semantically equals one of
 *     the chain bodies (v1.0.0 / v2.0.0 / v2.1.0 / already-3.0.0). A
 *     super-admin's customised structure matches none of them and is
 *     respected untouched;
 *   • lighting_fixtures converges under the same rule ([] / the v2 pair /
 *     the v2.1 five → the v3 five);
 *   • wing_heights + glazing_walls union-add when absent (the v3 body
 *     resolves its junction/glazing_north anchors to null and would render
 *     without its architecture without them);
 *   • every decision LOGS — a guarded pass must never be silent again.
 *
 * Bodies are byte-verbatim slices of the chain migrations (the same
 * byte-for-byte invariant the QA gate pins on 000007). Idempotent. The
 * venue_config cache re-keys from the row contents, so the pass is live on
 * the next render after migrate — no manual cache clear.
 *
 * down() restores the v2.1.0 bodies (the chain's rollback target) under the
 * same semantic guards, so migrate:rollback stays coherent through 000007.
 */
return new class extends Migration
{
    private const SLUG = 'luxury-penthouse';

    private const V1_STRUCTURE = [
            ['id' => 'terrace-deck', 'primitive' => 'box', 'at' => ['from' => 'glazing_outside', 'offset' => [0, 0.04, 2.6]], 'turn' => 'out', 'fit' => 'glazing', 'fit_pad' => 0.1, 'size' => [1, 0.08, 5.0], 'material' => 'dark_trim'],
            ['id' => 'glazing-glass', 'primitive' => 'plane', 'at' => ['from' => 'glazing', 'offset' => [0, 2.2, 0]], 'turn' => 'in', 'fit' => 'glazing', 'fit_pad' => 0.06, 'size' => [1, 4.4], 'material' => ['glass' => true, 'tint' => '0xc4d8ea', 'opacity' => 0.18]],
            ['id' => 'glazing-mullions', 'primitive' => 'instance-grid', 'at' => ['from' => 'glazing', 'offset' => [0, 2.2, 0.05]], 'turn' => 'in', 'size' => [0.06, 4.4, 0.085], 'material' => 'steel_dark', 'merge' => 'ph-steel', 'grid' => ['mode' => 'line', 'from' => 'glazing', 'span' => 'fit', 'fit_pad' => 0.16, 'spacing' => 1.4]],
            ['id' => 'glazing-sill', 'primitive' => 'box', 'at' => ['from' => 'glazing', 'offset' => [0, 0.05, 0]], 'fit' => 'glazing', 'fit_pad' => 0.02, 'size' => [1, 0.1, 0.1], 'material' => 'steel_dark', 'merge' => 'ph-steel', 'collide' => true],
            ['id' => 'glazing-head', 'primitive' => 'box', 'at' => ['from' => 'glazing', 'offset' => [0, 4.42, 0]], 'fit' => 'glazing', 'fit_pad' => 0.02, 'size' => [1, 0.12, 0.1], 'material' => 'steel_dark', 'merge' => 'ph-steel'],
            ['id' => 'rail-bar', 'primitive' => 'box', 'at' => ['from' => 'glazing', 'offset' => [0, 0.95, 0.55]], 'fit' => 'glazing', 'fit_pad' => 0.5, 'size' => [1, 0.05, 0.05], 'material' => 'steel_dark', 'merge' => 'ph-rail', 'collide' => true],
            ['id' => 'rail-posts', 'primitive' => 'instance-grid', 'at' => ['from' => 'glazing', 'offset' => [0, 0.45, 0.55]], 'turn' => 'in', 'size' => [0.035, 0.9, 0.035], 'material' => 'steel_dark', 'merge' => 'ph-rail', 'grid' => ['mode' => 'line', 'from' => 'glazing', 'span' => 'fit', 'fit_pad' => 0.7, 'spacing' => 1.8]],
            ['id' => 'skyline-cool', 'primitive' => 'instance-grid', 'size' => [2.2, 10, 2.2], 'material' => 'tower_cool', 'grid' => ['mode' => 'scatter', 'count' => 9, 'seed' => 'skyline-cool', 'area' => ['from' => 'glazing_outside', 'size' => [26, 0, 30], 'forward' => 5], 'scale_jitter' => [0.55, 2.3], 'grounded' => true, 'yaw_jitter' => [0, 3.14159]]],
            ['id' => 'skyline-warm', 'primitive' => 'instance-grid', 'size' => [1.5, 14, 1.5], 'material' => 'tower_warm', 'grid' => ['mode' => 'scatter', 'count' => 6, 'seed' => 'skyline-warm', 'area' => ['from' => 'glazing_outside', 'size' => [26, 0, 30], 'forward' => 9], 'scale_jitter' => [0.5, 1.7], 'grounded' => true, 'yaw_jitter' => [0, 3.14159]]],
            ['id' => 'lounge-pendant', 'primitive' => 'emissive-strip', 'at' => ['from' => 'glazing', 'offset' => [0, 3.55, 1.75]], 'turn' => 'in', 'fit' => 'glazing', 'fit_pad' => 2.6, 'size' => [1, 0.045, 0.045], 'material' => ['color' => '0xffd9a8', 'emissive' => '0xffc98a', 'emissiveIntensity' => 2.2]],
            ['id' => 'lounge-rug', 'primitive' => 'plane', 'at' => ['from' => 'glazing', 'offset' => [0, 0.012, 2.0]], 'turn' => 'in', 'rot' => [-1.5707963, 0, 0], 'size' => [3.2, 2.3], 'material' => 'fabric_dark'],
            ['id' => 'sofa-base', 'primitive' => 'box', 'at' => ['from' => 'glazing', 'offset' => [0, 0.24, 1.75]], 'turn' => 'in', 'size' => [2.3, 0.48, 0.95], 'material' => 'fabric_warm', 'collide' => true],
            ['id' => 'sofa-back', 'primitive' => 'box', 'at' => ['from' => 'glazing', 'offset' => [0, 0.72, 2.15]], 'turn' => 'in', 'size' => [2.3, 0.5, 0.24], 'material' => 'fabric_warm'],
            ['id' => 'sofa-arm-l', 'primitive' => 'box', 'at' => ['from' => 'glazing', 'offset' => [-1.26, 0.42, 1.78]], 'turn' => 'in', 'size' => [0.22, 0.36, 0.9], 'material' => 'fabric_warm'],
            ['id' => 'sofa-arm-r', 'primitive' => 'box', 'at' => ['from' => 'glazing', 'offset' => [1.26, 0.42, 1.78]], 'turn' => 'in', 'size' => [0.22, 0.36, 0.9], 'material' => 'fabric_warm'],
            ['id' => 'table-top', 'primitive' => 'box', 'at' => ['from' => 'glazing', 'offset' => [0, 0.34, 0.9]], 'turn' => 'in', 'size' => [1.15, 0.05, 0.55], 'material' => 'wood_dark'],
            ['id' => 'table-pedestal', 'primitive' => 'box', 'at' => ['from' => 'glazing', 'offset' => [0, 0.15, 0.9]], 'turn' => 'in', 'size' => [0.5, 0.3, 0.35], 'material' => 'dark_trim', 'collide' => true],
        ];

    private const V2_STRUCTURE = [
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

    private const V2_FIXTURES = [
            ['id' => 'fire-glow', 'type' => 'point', 'anchor' => ['from' => 'wall_front', 'offset' => [0, 0.95, 1.1]], 'color' => '0xff9a50', 'intensity' => 16, 'distance' => 7, 'decay' => 1.8, 'cast_shadow' => false],
            ['id' => 'hearth-wash', 'type' => 'point', 'anchor' => ['from' => 'wall_front', 'offset' => [0, 4.2, 1.3]], 'color' => '0xffd9a0', 'intensity' => 3.5, 'distance' => 5, 'decay' => 1.6, 'cast_shadow' => false],
        ];

    private const V21_STRUCTURE = [
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

    private const V21_FIXTURES = [
            ['id' => 'fire-glow', 'type' => 'point', 'anchor' => ['from' => 'wall_end', 'offset' => [0, 0.95, 1.1]], 'color' => '0xff9a50', 'intensity' => 5, 'distance' => 6, 'decay' => 2, 'cast_shadow' => false],
            ['id' => 'hearth-wash', 'type' => 'point', 'anchor' => ['from' => 'wall_end', 'offset' => [0, 4.2, 1.3]], 'color' => '0xffd9a0', 'intensity' => 2.2, 'distance' => 5, 'decay' => 1.8, 'cast_shadow' => false],
            ['id' => 'cove-wash-a', 'type' => 'point', 'anchor' => ['from' => 'wall_left', 'offset' => [0, 4.1, 2.6]], 'color' => '0xffd9a0', 'intensity' => 3.2, 'distance' => 12, 'decay' => 1.8, 'cast_shadow' => false],
            ['id' => 'cove-wash-b', 'type' => 'point', 'anchor' => ['from' => 'wall_inner', 'offset' => [0, 4.1, 2.6]], 'color' => '0xffd9a0', 'intensity' => 3.2, 'distance' => 12, 'decay' => 1.8, 'cast_shadow' => false],
            ['id' => 'lounge-wash', 'type' => 'point', 'anchor' => ['from' => 'glazing', 'offset' => [0, 3.9, 3.4]], 'color' => '0xffe2b8', 'intensity' => 1.8, 'distance' => 8, 'decay' => 1.8, 'cast_shadow' => false],
        ];

    private const V3_STRUCTURE = [
            // ── THE SEAM (junction anchors): where 3.55 m becomes 6.3 m.
            // The exposed gallery roofline gets a plaster fascia; a lit slot
            // runs the FULL plan width at the step height — along the
            // gallery ceiling edge AND wing B's south wall — the seam made
            // visible from anywhere on the floor.
            ['id' => 'step-fascia', 'primitive' => 'box', 'at' => ['from' => 'junction', 'offset' => [0, 3.375, 0.12]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.3, 'size' => [1, 0.35, 0.3], 'material' => 'plaster_warm'],
            ['id' => 'step-slot', 'primitive' => 'emissive-strip', 'at' => ['from' => 'junction_outside', 'offset' => [0, 3.47, 0.12]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.9, 'size' => [1, 0.06, 0.06], 'material' => ['color' => '0x2a1c10', 'emissive' => '0xffe6c4', 'emissiveIntensity' => 1.6], 'merge' => 'ph-slot'],
            ['id' => 'seam-slot-inner', 'primitive' => 'emissive-strip', 'at' => ['from' => 'wall_inner', 'offset' => [0, 3.47, -0.12]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.9, 'size' => [1, 0.06, 0.06], 'material' => ['color' => '0x2a1c10', 'emissive' => '0xffe6c4', 'emissiveIntensity' => 1.6], 'merge' => 'ph-slot'],
            // ── The axis sculpture: one bronze knot on basalt, exactly on
            // the spawn sightline at the seam — the procession's full stop.
            ['id' => 'plinth', 'primitive' => 'cylinder', 'at' => ['from' => 'junction', 'offset' => [0, 0.55, 1.5]], 'turn' => 'in', 'size' => [0.42, 1.1, 0.42], 'material' => 'basalt', 'collide' => true],
            ['id' => 'sculpture-knot', 'primitive' => 'torus', 'at' => ['from' => 'junction', 'offset' => [0, 1.42, 1.5]], 'turn' => 'in', 'size' => [0.34, 0.1, 0.34], 'params' => ['seg' => 24, 'seg2' => 48], 'material' => 'bronze'],
            // ── THE FIREPLACE PIER: the solid stone moment inside the north
            // glass run. Full volume height; hangable above the mantel (the
            // statement work lives here — bay hang, centre 3.25 m).
            ['id' => 'fireplace-stone', 'primitive' => 'box', 'at' => ['from' => 'wall_end', 'offset' => [0, 3.15, 0.11]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 1.6, 'size' => [1, 6.3, 0.2], 'material' => 'basalt', 'hangable' => ['y' => 3.25]],
            ['id' => 'fireplace-mantel', 'primitive' => 'box', 'at' => ['from' => 'wall_end', 'offset' => [0, 1.3, 0.32]], 'turn' => 'in', 'size' => [2.6, 0.07, 0.3], 'material' => 'walnut'],
            ['id' => 'fireplace-band', 'primitive' => 'emissive-strip', 'at' => ['from' => 'wall_end', 'offset' => [0, 0.62, 0.365]], 'turn' => 'in', 'size' => [1.9, 0.16, 0.05], 'material' => ['color' => '0x1a0d06', 'emissive' => '0xff8a3d', 'emissiveIntensity' => 1.5]],
            ['id' => 'fireplace-hearth', 'primitive' => 'box', 'at' => ['from' => 'wall_end', 'offset' => [0, 0.03, 0.45]], 'turn' => 'in', 'size' => [3.0, 0.06, 0.55], 'material' => 'basalt'],
            // ── THE ART WALL: full-height walnut panel on the volume's west
            // face (wall_left_high) — architectural art wall holding the
            // second statement work (bay hang, centre 2.6 m).
            ['id' => 'art-wall-panel', 'primitive' => 'box', 'at' => ['from' => 'wall_left_high', 'offset' => [0, 2.6, 0.03]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.8, 'size' => [1, 5.2, 0.06], 'material' => 'walnut', 'hangable' => ['y' => 2.6]],
            // ── EAST GLASS (wing B end): tier-resolved cheap-class glass +
            // steel mullions, full volume height.
            ['id' => 'glazing-glass',  'primitive' => 'plane',          'at' => ['from' => 'glazing', 'offset' => [0, 3.15, 0]], 'turn' => 'in', 'fit' => 'glazing', 'fit_pad' => 0.06, 'size' => [1, 6.0], 'material' => ['glass' => true, 'tint' => '0xd8e4ef', 'opacity' => 0.1, 'roughness' => 0.35, 'tier' => 'cheap']],
            ['id' => 'glazing-mullions', 'primitive' => 'instance-grid', 'at' => ['from' => 'glazing', 'offset' => [0, 3.15, 0.05]], 'turn' => 'in', 'size' => [0.06, 6.0, 0.085], 'material' => 'steel_dark', 'merge' => 'ph-steel', 'grid' => ['mode' => 'line', 'from' => 'glazing', 'span' => 'fit', 'fit_pad' => 0.16, 'spacing' => 1.4]],
            ['id' => 'glazing-sill',   'primitive' => 'box',            'at' => ['from' => 'glazing', 'offset' => [0, 0.05, 0]], 'turn' => 'in', 'fit' => 'glazing', 'fit_pad' => 0.02, 'size' => [1, 0.1, 0.1], 'material' => 'steel_dark', 'merge' => 'ph-steel', 'collide' => true],
            ['id' => 'glazing-head',   'primitive' => 'box',            'at' => ['from' => 'glazing', 'offset' => [0, 6.18, 0]], 'turn' => 'in', 'fit' => 'glazing', 'fit_pad' => 0.02, 'size' => [1, 0.12, 0.1], 'material' => 'steel_dark', 'merge' => 'ph-steel'],
            // ── NORTH GLASS (wing B run — the second face, v3): the long
            // panorama; the fireplace pier interrupts it with stone.
            ['id' => 'glazing-glass-north',  'primitive' => 'plane',          'at' => ['from' => 'glazing_north', 'offset' => [0, 3.15, 0]], 'turn' => 'in', 'fit' => 'glazing', 'fit_pad' => 0.06, 'size' => [1, 6.0], 'material' => ['glass' => true, 'tint' => '0xd8e4ef', 'opacity' => 0.1, 'roughness' => 0.35, 'tier' => 'cheap']],
            ['id' => 'glazing-mullions-north', 'primitive' => 'instance-grid', 'at' => ['from' => 'glazing_north', 'offset' => [0, 3.15, 0.05]], 'turn' => 'in', 'size' => [0.06, 6.0, 0.085], 'material' => 'steel_dark', 'merge' => 'ph-steel', 'grid' => ['mode' => 'line', 'from' => 'glazing_north', 'span' => 'fit', 'fit_pad' => 0.16, 'spacing' => 1.4]],
            ['id' => 'glazing-sill-north',   'primitive' => 'box',            'at' => ['from' => 'glazing_north', 'offset' => [0, 0.05, 0]], 'turn' => 'in', 'fit' => 'glazing', 'fit_pad' => 0.02, 'size' => [1, 0.1, 0.1], 'material' => 'steel_dark', 'merge' => 'ph-steel', 'collide' => true],
            ['id' => 'glazing-head-north',   'primitive' => 'box',            'at' => ['from' => 'glazing_north', 'offset' => [0, 6.18, 0]], 'turn' => 'in', 'fit' => 'glazing', 'fit_pad' => 0.02, 'size' => [1, 0.12, 0.1], 'material' => 'steel_dark', 'merge' => 'ph-steel'],
            // ── TERRACE: warm decks + rails wrapping BOTH glazed faces
            // (the glass corner).
            ['id' => 'terrace-deck-east',  'primitive' => 'box', 'at' => ['from' => 'glazing_outside', 'offset' => [0, 0.04, 2.6]], 'turn' => 'out', 'fit' => 'glazing', 'fit_pad' => 0.1, 'size' => [1, 0.08, 5.0], 'material' => 'wood_warm'],
            ['id' => 'terrace-deck-north', 'primitive' => 'box', 'at' => ['from' => 'glazing_north_outside', 'offset' => [0, 0.04, 2.6]], 'turn' => 'out', 'fit' => 'glazing', 'fit_pad' => 0.1, 'size' => [1, 0.08, 5.0], 'material' => 'wood_warm'],
            ['id' => 'rail-bar-east',  'primitive' => 'box',           'at' => ['from' => 'glazing', 'offset' => [0, 0.95, 0.55]], 'turn' => 'in', 'fit' => 'glazing', 'fit_pad' => 0.5, 'size' => [1, 0.05, 0.05], 'material' => 'steel_dark', 'merge' => 'ph-rail', 'collide' => true],
            ['id' => 'rail-posts-east', 'primitive' => 'instance-grid', 'at' => ['from' => 'glazing', 'offset' => [0, 0.45, 0.55]], 'turn' => 'in', 'size' => [0.035, 0.9, 0.035], 'material' => 'steel_dark', 'merge' => 'ph-rail', 'grid' => ['mode' => 'line', 'from' => 'glazing', 'span' => 'fit', 'fit_pad' => 0.7, 'spacing' => 1.8]],
            ['id' => 'rail-bar-north',  'primitive' => 'box',           'at' => ['from' => 'glazing_north', 'offset' => [0, 0.95, 0.55]], 'turn' => 'in', 'fit' => 'glazing', 'fit_pad' => 0.5, 'size' => [1, 0.05, 0.05], 'material' => 'steel_dark', 'merge' => 'ph-rail', 'collide' => true],
            ['id' => 'rail-posts-north', 'primitive' => 'instance-grid', 'at' => ['from' => 'glazing_north', 'offset' => [0, 0.45, 0.55]], 'turn' => 'in', 'size' => [0.035, 0.9, 0.035], 'material' => 'steel_dark', 'merge' => 'ph-rail', 'grid' => ['mode' => 'line', 'from' => 'glazing_north', 'span' => 'fit', 'fit_pad' => 0.7, 'spacing' => 1.8]],
            // ── Dusk city, TWO faces: three grounded depth layers per face
            // (seeded §13.6) + afterglow bands BETWEEN and ABOVE the
            // silhouettes (the v2.1.0 facing-planes lesson kept).
            ['id' => 'skyline-near-east', 'primitive' => 'instance-grid', 'size' => [1.7, 9, 1.7], 'material' => ['color' => '0x0f0d0a', 'emissive' => '0x7a5533', 'emissiveIntensity' => 0.42], 'grid' => ['mode' => 'scatter', 'count' => 6, 'seed' => 'skyline-cool', 'area' => ['from' => 'glazing_outside', 'size' => [34, 0, 20], 'forward' => 24], 'scale_jitter' => [0.4, 0.7], 'grounded' => true, 'yaw_jitter' => [0, 3.14159]]],
            ['id' => 'skyline-mid-east',  'primitive' => 'instance-grid', 'size' => [2.4, 9, 2.4], 'material' => ['color' => '0x0d1119', 'emissive' => '0x33415c', 'emissiveIntensity' => 0.3], 'grid' => ['mode' => 'scatter', 'count' => 8, 'seed' => 'skyline-warm', 'area' => ['from' => 'glazing_outside', 'size' => [36, 0, 24], 'forward' => 28], 'scale_jitter' => [0.6, 1.3], 'grounded' => true, 'yaw_jitter' => [0, 3.14159]]],
            ['id' => 'skyline-far-east',  'primitive' => 'instance-grid', 'size' => [3.4, 14, 3.4], 'material' => ['color' => '0x0a0d15', 'emissive' => '0x1c2740', 'emissiveIntensity' => 0.38], 'grid' => ['mode' => 'scatter', 'count' => 8, 'seed' => 'skyline-far-b', 'area' => ['from' => 'glazing_outside', 'size' => [50, 0, 52], 'forward' => 36], 'scale_jitter' => [0.6, 1.3], 'grounded' => true, 'yaw_jitter' => [0, 3.14159]]],
            ['id' => 'horizon-glow-east', 'primitive' => 'plane', 'at' => ['from' => 'glazing_outside', 'offset' => [0, 8.0, 44]], 'turn' => 'out', 'size' => [130, 26], 'material' => ['color' => '0x140f08', 'emissive' => '0x9a6238', 'emissiveIntensity' => 2.2]],
            ['id' => 'sky-mid-east',      'primitive' => 'plane', 'at' => ['from' => 'glazing_outside', 'offset' => [0, 15, 64]], 'turn' => 'out', 'size' => [130, 26], 'material' => ['color' => '0x0a0e18', 'emissive' => '0x445c82', 'emissiveIntensity' => 1.0]],
            ['id' => 'sky-deep-east',     'primitive' => 'plane', 'at' => ['from' => 'glazing_outside', 'offset' => [0, 22, 86]], 'turn' => 'out', 'size' => [150, 50], 'material' => ['color' => '0x070a12', 'emissive' => '0x111c33', 'emissiveIntensity' => 1.0]],
            ['id' => 'skyline-near-north', 'primitive' => 'instance-grid', 'size' => [1.7, 9, 1.7], 'material' => ['color' => '0x0f0d0a', 'emissive' => '0x7a5533', 'emissiveIntensity' => 0.42], 'grid' => ['mode' => 'scatter', 'count' => 6, 'seed' => 'skyline-n1', 'area' => ['from' => 'glazing_north_outside', 'size' => [44, 0, 20], 'forward' => 24], 'scale_jitter' => [0.4, 0.7], 'grounded' => true, 'yaw_jitter' => [0, 3.14159]]],
            ['id' => 'skyline-mid-north',  'primitive' => 'instance-grid', 'size' => [2.4, 9, 2.4], 'material' => ['color' => '0x0d1119', 'emissive' => '0x33415c', 'emissiveIntensity' => 0.3], 'grid' => ['mode' => 'scatter', 'count' => 8, 'seed' => 'skyline-n2', 'area' => ['from' => 'glazing_north_outside', 'size' => [46, 0, 24], 'forward' => 28], 'scale_jitter' => [0.6, 1.3], 'grounded' => true, 'yaw_jitter' => [0, 3.14159]]],
            ['id' => 'skyline-far-north',  'primitive' => 'instance-grid', 'size' => [3.4, 14, 3.4], 'material' => ['color' => '0x0a0d15', 'emissive' => '0x1c2740', 'emissiveIntensity' => 0.38], 'grid' => ['mode' => 'scatter', 'count' => 8, 'seed' => 'skyline-n3', 'area' => ['from' => 'glazing_north_outside', 'size' => [56, 0, 52], 'forward' => 36], 'scale_jitter' => [0.6, 1.3], 'grounded' => true, 'yaw_jitter' => [0, 3.14159]]],
            ['id' => 'horizon-glow-north', 'primitive' => 'plane', 'at' => ['from' => 'glazing_north_outside', 'offset' => [0, 8.0, 44]], 'turn' => 'out', 'size' => [150, 26], 'material' => ['color' => '0x140f08', 'emissive' => '0x9a6238', 'emissiveIntensity' => 2.2]],
            ['id' => 'sky-mid-north',      'primitive' => 'plane', 'at' => ['from' => 'glazing_north_outside', 'offset' => [0, 15, 64]], 'turn' => 'out', 'size' => [160, 26], 'material' => ['color' => '0x0a0e18', 'emissive' => '0x445c82', 'emissiveIntensity' => 1.0]],
            ['id' => 'sky-deep-north',     'primitive' => 'plane', 'at' => ['from' => 'glazing_north_outside', 'offset' => [0, 22, 86]], 'turn' => 'out', 'size' => [180, 50], 'material' => ['color' => '0x070a12', 'emissive' => '0x111c33', 'emissiveIntensity' => 1.0]],
            // ── Gallery band: warm cove reveals at the 3.55 m ceiling (the
            // low wing's light comes from the coves + the seam slot).
            ['id' => 'cove-left',   'primitive' => 'emissive-strip', 'at' => ['from' => 'wall_left', 'up' => 'ceiling', 'offset' => [0, -0.3, 0.24]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.9, 'size' => [1, 0.05, 0.09], 'material' => ['color' => '0x201810', 'emissive' => '0xffd9a0', 'emissiveIntensity' => 1.15], 'merge' => 'ph-cove'],
            ['id' => 'cove-shelf-left', 'primitive' => 'box', 'at' => ['from' => 'wall_left', 'up' => 'ceiling', 'offset' => [0, -0.22, 0.32]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.9, 'size' => [1, 0.03, 0.2], 'material' => 'dark_trim', 'merge' => 'ph-coveshelf'],
            ['id' => 'cove-right',   'primitive' => 'emissive-strip', 'at' => ['from' => 'wall_right', 'up' => 'ceiling', 'offset' => [0, -0.3, 0.24]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.9, 'size' => [1, 0.05, 0.09], 'material' => ['color' => '0x201810', 'emissive' => '0xffd9a0', 'emissiveIntensity' => 1.15], 'merge' => 'ph-cove'],
            ['id' => 'cove-shelf-right', 'primitive' => 'box', 'at' => ['from' => 'wall_right', 'up' => 'ceiling', 'offset' => [0, -0.22, 0.32]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.9, 'size' => [1, 0.03, 0.2], 'material' => 'dark_trim', 'merge' => 'ph-coveshelf'],
            ['id' => 'cove-front',   'primitive' => 'emissive-strip', 'at' => ['from' => 'wall_front', 'up' => 'ceiling', 'offset' => [0, -0.3, 0.24]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.9, 'size' => [1, 0.05, 0.09], 'material' => ['color' => '0x201810', 'emissive' => '0xffd9a0', 'emissiveIntensity' => 1.15], 'merge' => 'ph-cove'],
            ['id' => 'cove-shelf-front', 'primitive' => 'box', 'at' => ['from' => 'wall_front', 'up' => 'ceiling', 'offset' => [0, -0.22, 0.32]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.9, 'size' => [1, 0.03, 0.2], 'material' => 'dark_trim', 'merge' => 'ph-coveshelf'],
            // ── Bronze base trim: the quiet luxury line (gallery band +
            // the solid seam wall in the volume; never across glass).
            ['id' => 'base-left',   'primitive' => 'box', 'at' => ['from' => 'wall_left', 'offset' => [0, 0.07, 0.02]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.4, 'size' => [1, 0.14, 0.03], 'material' => 'bronze', 'merge' => 'ph-base'],
            ['id' => 'base-right',  'primitive' => 'box', 'at' => ['from' => 'wall_right', 'offset' => [0, 0.07, 0.02]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.4, 'size' => [1, 0.14, 0.03], 'material' => 'bronze', 'merge' => 'ph-base'],
            ['id' => 'base-front',  'primitive' => 'box', 'at' => ['from' => 'wall_front', 'offset' => [0, 0.07, 0.02]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.4, 'size' => [1, 0.14, 0.03], 'material' => 'bronze', 'merge' => 'ph-base'],
            ['id' => 'base-inner',  'primitive' => 'box', 'at' => ['from' => 'wall_inner', 'offset' => [0, 0.07, 0.02]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.4, 'size' => [1, 0.14, 0.03], 'material' => 'bronze', 'merge' => 'ph-base'],
            // ── Gallery bench: under the west-face hang, never blocking
            // the walk.
            ['id' => 'bench-top',      'primitive' => 'box', 'at' => ['from' => 'wall_left', 'offset' => [0, 0.44, 0.42]], 'turn' => 'in', 'size' => [2.2, 0.06, 0.45], 'material' => 'walnut'],
            ['id' => 'bench-base',     'primitive' => 'box', 'at' => ['from' => 'wall_left', 'offset' => [0, 0.21, 0.42]], 'turn' => 'in', 'size' => [2.0, 0.42, 0.38], 'material' => 'dark_trim', 'collide' => true],
            // ── Lounge at the glass corner (few, strong pieces — the sofa
            // faces the room, the city wraps it; pendant above).
            ['id' => 'lounge-rug',     'primitive' => 'plane', 'at' => ['from' => 'glazing', 'offset' => [0.5, 0.012, 2.0]],  'turn' => 'in', 'rot' => [-1.5707963, 0, 0], 'size' => [3.4, 2.5], 'material' => 'fabric_dark'],
            ['id' => 'sofa-base',      'primitive' => 'box',   'at' => ['from' => 'glazing', 'offset' => [0.5, 0.24, 1.75]],  'turn' => 'in', 'size' => [2.3, 0.48, 0.95], 'material' => 'fabric_warm', 'collide' => true],
            ['id' => 'sofa-back',      'primitive' => 'box',   'at' => ['from' => 'glazing', 'offset' => [0.5, 0.72, 2.15]],  'turn' => 'in', 'size' => [2.3, 0.5, 0.24],  'material' => 'fabric_warm'],
            ['id' => 'sofa-arm-l',     'primitive' => 'box',   'at' => ['from' => 'glazing', 'offset' => [-0.76, 0.42, 1.78]], 'turn' => 'in', 'size' => [0.22, 0.36, 0.9], 'material' => 'fabric_warm'],
            ['id' => 'sofa-arm-r',     'primitive' => 'box',   'at' => ['from' => 'glazing', 'offset' => [1.76, 0.42, 1.78]], 'turn' => 'in', 'size' => [0.22, 0.36, 0.9], 'material' => 'fabric_warm'],
            ['id' => 'table-top',      'primitive' => 'box',   'at' => ['from' => 'glazing', 'offset' => [0.5, 0.34, 0.9]],   'turn' => 'in', 'size' => [1.15, 0.05, 0.55], 'material' => 'wood_dark'],
            ['id' => 'table-pedestal', 'primitive' => 'box',   'at' => ['from' => 'glazing', 'offset' => [0.5, 0.15, 0.9]],   'turn' => 'in', 'size' => [0.5, 0.3, 0.35],  'material' => 'dark_trim', 'collide' => true],
            ['id' => 'chair-seat',     'primitive' => 'box',   'at' => ['from' => 'glazing', 'offset' => [-1.45, 0.3, 2.9]], 'turn' => 'in', 'size' => [0.85, 0.44, 0.85], 'material' => 'fabric_dark', 'collide' => true],
            ['id' => 'chair-back',     'primitive' => 'box',   'at' => ['from' => 'glazing', 'offset' => [-1.45, 0.63, 3.35]], 'turn' => 'in', 'size' => [0.12, 0.55, 0.85], 'material' => 'fabric_dark'],
            ['id' => 'lamp-pole',      'primitive' => 'cylinder', 'at' => ['from' => 'glazing', 'offset' => [2.55, 0.8, 2.6]], 'turn' => 'in', 'size' => [0.035, 1.6, 0.035], 'material' => 'steel_dark'],
            ['id' => 'lamp-shade',     'primitive' => 'emissive-strip', 'at' => ['from' => 'glazing', 'offset' => [2.55, 1.68, 2.6]], 'turn' => 'in', 'size' => [0.36, 0.32, 0.36], 'material' => ['color' => '0x2a2018', 'emissive' => '0xffd9a0', 'emissiveIntensity' => 0.8]],
            ['id' => 'lounge-pendant', 'primitive' => 'emissive-strip', 'at' => ['from' => 'glazing', 'offset' => [0.5, 4.6, 1.75]], 'turn' => 'in', 'fit' => 'glazing', 'fit_pad' => 2.6, 'size' => [1, 0.045, 0.045], 'material' => ['color' => '0xffd9a8', 'emissive' => '0xffc98a', 'emissiveIntensity' => 2.2]],
            // ── Stone slab joints: the large-format floor scale cue
            // (procedural grout grid — zero new assets, §14).
            ['id' => 'floor-joints',   'primitive' => 'instance-grid', 'size' => [5.4, 0.012, 0.02], 'material' => ['color' => '0x6b5f4c', 'roughness' => 0.55, 'metalness' => 0.02], 'grid' => ['mode' => 'box', 'area' => ['from' => 'center', 'y' => 0.006, 'size' => ['fit' => 'room', 'pad' => [0.3, 0.9]]], 'spacing' => [0, 0, 2.4]]],
        ];

    private const V3_FIXTURES = [
                    ['id' => 'fire-glow', 'type' => 'point', 'anchor' => ['from' => 'wall_end', 'offset' => [0, 0.95, 1.1]], 'color' => '0xff9a50', 'intensity' => 5, 'distance' => 6, 'decay' => 2, 'cast_shadow' => false],
                    ['id' => 'hearth-wash', 'type' => 'point', 'anchor' => ['from' => 'wall_end', 'offset' => [0, 5.0, 1.3]], 'color' => '0xffd9a0', 'intensity' => 2.2, 'distance' => 5, 'decay' => 1.8, 'cast_shadow' => false],
                    ['id' => 'step-wash', 'type' => 'point', 'anchor' => ['from' => 'junction', 'offset' => [0, 4.65, 1.6]], 'color' => '0xffd9a0', 'intensity' => 3.2, 'distance' => 14, 'decay' => 1.8, 'cast_shadow' => false],
                    ['id' => 'gallery-cove-wash', 'type' => 'point', 'anchor' => ['from' => 'wall_left', 'offset' => [0, 2.8, 2.6]], 'color' => '0xffd9a0', 'intensity' => 3.2, 'distance' => 12, 'decay' => 1.8, 'cast_shadow' => false],
                    ['id' => 'lounge-wash', 'type' => 'point', 'anchor' => ['from' => 'glazing', 'offset' => [0.5, 4.9, 3.4]], 'color' => '0xffe2b8', 'intensity' => 1.8, 'distance' => 8, 'decay' => 1.8, 'cast_shadow' => false],
        ];

    public function up(): void
    {
        $row = DB::table('venue_templates')->where('slug', self::SLUG)->first(['id', 'visual_config', 'lighting_fixtures']);
        if (!$row) {
            return; // venue removed by the operator — respect that
        }

        $visual   = json_decode((string) $row->visual_config, true) ?: [];
        $fixtures = json_decode((string) $row->lighting_fixtures, true) ?: [];
        $update   = [];

        // ── Structure convergence (semantic guards) ─────────────────────
        $structureBodies = [
            'v1.0.0 "Rooms" (the production drift state)' => self::V1_STRUCTURE,
            'v2.0.0 "The Collector\'s Floor"'            => self::V2_STRUCTURE,
            'v2.1.0 "Evening Light"'                     => self::V21_STRUCTURE,
            'v3.0.0 "The Double Volume"'                 => self::V3_STRUCTURE,
        ];
        $current = $visual['structure'] ?? null;
        if (is_array($current)) {
            $label = '';
            if (self::semanticMatches($current, $structureBodies, $label)) {
                if (!self::sameValue($current, self::V3_STRUCTURE)) {
                    $visual['structure'] = self::V3_STRUCTURE;
                    $update['visual_config'] = json_encode($visual);
                    $this->log("structure converged from {$label} → v3.0.0 (61 descriptors).");
                } else {
                    $this->log('structure already carries the v3.0.0 body — no change.');
                }
            } else {
                $this->log('structure matches NO chain body (admin-customised or unknown) — left untouched.');
            }
        } else {
            $this->log('no structure declared — left untouched.');
        }

        // ── Fixture convergence (semantic guards, same rule) ────────────
        $fixtureBodies = [
            'v1.0.0 (empty)'                => [],
            'v2.0.0 (fire-glow/hearth-wash)' => self::V2_FIXTURES,
            'v2.1.0 (the 5-light rig)'      => self::V21_FIXTURES,
            'v3.0.0 (the re-aimed rig)'     => self::V3_FIXTURES,
        ];
        $label = '';
        if (self::semanticMatches($fixtures, $fixtureBodies, $label)) {
            if (!self::sameValue($fixtures, self::V3_FIXTURES)) {
                $update['lighting_fixtures'] = json_encode(self::V3_FIXTURES);
                $this->log("lighting_fixtures converged from {$label} → the v3.0.0 rig (5 fixtures).");
            } else {
                $this->log('lighting_fixtures already carries the v3.0.0 rig — no change.');
            }
        } else {
            $this->log('lighting_fixtures matches NO chain body — left untouched.');
        }

        // ── The v3 architecture keys (absent-key union — same as 000007) ─
        // Without wing_heights the room builds at ONE height and every
        // junction/wall_left_high anchor resolves null (descriptors skip) —
        // the body would render gutted. Added only when ABSENT; an admin's
        // declared split always wins.
        $addedKeys = false;
        foreach ([
            'wing_heights'  => ['wing_a' => 3.55, 'wing_b' => 6.3],
            'glazing_walls' => ['wing_b_end', 'wing_b_north'],
        ] as $key => $value) {
            if (!array_key_exists($key, $visual)) {
                $visual[$key] = $value;
                $addedKeys = true;
                $this->log("{$key} union-added (the v3 anchors need it to resolve).");
            }
        }
        if ($addedKeys) {
            $update['visual_config'] = json_encode($visual);
        }

        if ($update !== []) {
            DB::table('venue_templates')->where('id', $row->id)->update($update);
        } else {
            $this->log('row already converged — nothing to do.');
        }
    }

    public function down(): void
    {
        // Restore the v2.1.0 bodies — the chain's rollback target (000007's
        // down() expects exactly them, and its own exact-match guards then
        // keep working for rows this pass converged).
        $row = DB::table('venue_templates')->where('slug', self::SLUG)->first(['id', 'visual_config', 'lighting_fixtures']);
        if (!$row) {
            return;
        }

        $visual   = json_decode((string) $row->visual_config, true) ?: [];
        $fixtures = json_decode((string) $row->lighting_fixtures, true) ?: [];
        $update   = [];

        if (isset($visual['structure']) && self::sameValue($visual['structure'], self::V3_STRUCTURE)) {
            $visual['structure'] = self::V21_STRUCTURE;
            $update['visual_config'] = json_encode($visual);
            $this->log('structure restored to v2.1.0 (the chain rollback target).');
        }

        if (self::sameValue($fixtures, self::V3_FIXTURES)) {
            $update['lighting_fixtures'] = json_encode(self::V21_FIXTURES);
            $this->log('lighting_fixtures restored to the v2.1.0 rig.');
        }

        if ($update !== []) {
            DB::table('venue_templates')->where('id', $row->id)->update($update);
        }
    }

    /**
     * Semantic equality for venue-config JSON shapes.
     *
     * PHP `===` on json_decode output is type-fragile (int 5 !== float 5.0)
     * and key-order sensitive — the exact drift the venue-template editor's
     * JSON round-trip produced. This comparator:
     *   • compares numbers by value (int/float interchangeable; strings
     *     stay strict so '0x…' colors and ids can never cross-match);
     *   • compares maps key-order-insensitively;
     *   • keeps lists strictly ordered (descriptor order, size/offset
     *     triples are geometry — a swapped [x,z] is a different room);
     *   • treats bool/null strictly.
     */
    private function sameValue($a, $b): bool
    {
        // bool / null are strictly identical — never numeric-coerced.
        if (is_bool($a) || is_bool($b) || is_null($a) || is_null($b)) {
            return $a === $b;
        }
        // Numbers compare by VALUE: int 5 ≡ float 5.0 — the drift shape the
        // venue-template editor's JSON round-trip produced. Strings never
        // take this branch ('0x…' colours, ids, seeds stay strict).
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return (float) $a === (float) $b;
        }
        if (gettype($a) !== gettype($b)) {
            return false;
        }
        if (is_array($a)) {
            if (count($a) !== count($b)) {
                return false;
            }
            $aList = ($a === array_values($a));
            $bList = ($b === array_values($b));
            if ($aList !== $bList) {
                return false;
            }
            if ($aList) {
                foreach ($a as $i => $v) {
                    if (!array_key_exists($i, $b) || !self::sameValue($v, $b[$i])) {
                        return false;
                    }
                }
                return true;
            }
            foreach ($a as $k => $v) {
                if (!array_key_exists($k, $b) || !self::sameValue($v, $b[$k])) {
                    return false;
                }
            }
            return true;
        }
        return $a === $b;
    }

    private function semanticMatches(?array $current, array $bodies, string &$label): bool
    {
        foreach ($bodies as $name => $body) {
            if (self::sameValue($current, $body)) {
                $label = $name;
                return true;
            }
        }
        return false;
    }

    private function log(string $message): void
    {
        echo '[luxury-penthouse-convergence] ' . $message . PHP_EOL;
    }
};
