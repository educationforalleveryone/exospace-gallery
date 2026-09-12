<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SLUG = 'luxury-penthouse';

    private const OLD_VERSION = '1.0.0';
    private const NEW_VERSION = '2.0.0';

    private const OLD_DESCRIPTION =
        'A private collector\'s evening — a glazed wall over the city lights, a lounge by the glass, dark walls and gold frames.';
    private const NEW_DESCRIPTION =
        'A private collector\'s floor at dusk — a walnut-and-stone gallery wing warming into a lounge at the glass, the city glowing beyond it, art hung the way a residence lives with it.';

        private const OLD_STRUCTURE = [
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

        private const NEW_STRUCTURE = [
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

    private const NEW_FIXTURES = [
        ['id' => 'fire-glow', 'type' => 'point', 'anchor' => ['from' => 'wall_front', 'offset' => [0, 0.95, 1.1]], 'color' => '0xff9a50', 'intensity' => 16, 'distance' => 7, 'decay' => 1.8, 'cast_shadow' => false],
        ['id' => 'hearth-wash', 'type' => 'point', 'anchor' => ['from' => 'wall_front', 'offset' => [0, 4.2, 1.3]], 'color' => '0xffd9a0', 'intensity' => 3.5, 'distance' => 5, 'decay' => 1.6, 'cast_shadow' => false],
    ];

    public function up(): void
    {
        $row = DB::table('venue_templates')->where('slug', self::SLUG)->first(['id', 'visual_config', 'material_config', 'lighting_fixtures', 'description', 'version']);
        if (!$row) {
            return; // venue removed by the operator — respect that
        }

        $visual   = json_decode((string) $row->visual_config, true) ?: [];
        $material = json_decode((string) $row->material_config, true) ?: [];

        // Changed values — only while still equal to the seeded v1.0.0 value.
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

        // post_fx (P3) — union-add when absent, exactly like the s3/s6 rule.
        if (!is_array($visual['post_fx'] ?? null)) {
            $visual['post_fx'] = [];
        }
        $visual['post_fx'] += [
            'bloom'             => true,
            'bloom_strength'    => 0.32,
            'bloom_threshold'   => 0.85,
            'bloom_radius'      => 0.35,
            'vignette'          => true,
            'vignette_darkness' => 0.5,
            'vignette_offset'   => 1.12,
            'vignette_blend'    => 'black',
        ];

        if (($visual['structure'] ?? null) === self::OLD_STRUCTURE) {
            $visual['structure'] = self::NEW_STRUCTURE;
        }

        // Material config — guarded changed + union-added.
        foreach ($this->materialChanges()['changed'] as $key => ['from' => $from, 'to' => $to]) {
            if (($material[$key] ?? null) === $from) {
                $material[$key] = $to;
            }
        }
        foreach ($this->materialChanges()['added'] as $key => $value) {
            if (!array_key_exists($key, $material)) {
                $material[$key] = $value;
            }
        }

        $update = [
            'visual_config'   => json_encode($visual),
            'material_config' => json_encode($material),
        ];

        $fixtures = json_decode((string) $row->lighting_fixtures, true) ?: [];
        if ($fixtures === []) {
            $update['lighting_fixtures'] = json_encode(self::NEW_FIXTURES);
        }

        // Copy — exact-match swap (the promise matrix: names what renders).
        if ((string) $row->description === self::OLD_DESCRIPTION) {
            $update['description'] = self::NEW_DESCRIPTION;
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

        // Reverse changed values — only while still equal to the NEW value.
        foreach ($this->changedVisualKeys() as $key => ['from' => $from, 'to' => $to]) {
            if (($visual[$key] ?? null) === $to) {
                $visual[$key] = $from;
            }
        }

        // Reverse added keys — only while still equal to the added value.
        foreach ($this->addedVisualKeys() as $key => $value) {
            if (($visual[$key] ?? null) === $value) {
                unset($visual[$key]);
            }
        }

        // Reverse post_fx keys present in the NEW set only when untouched.
        if (is_array($visual['post_fx'] ?? null)) {
            foreach ([
                'bloom'             => true,
                'bloom_strength'    => 0.32,
                'bloom_threshold'   => 0.85,
                'bloom_radius'      => 0.35,
                'vignette'          => true,
                'vignette_darkness' => 0.5,
                'vignette_offset'   => 1.12,
                'vignette_blend'    => 'black',
            ] as $key => $value) {
                if (($visual['post_fx'][$key] ?? null) === $value) {
                    unset($visual['post_fx'][$key]);
                }
            }
            if ($visual['post_fx'] === []) {
                unset($visual['post_fx']);
            }
        }

        // Reverse structure — exact match with the NEW list only.
        if (($visual['structure'] ?? null) === self::NEW_STRUCTURE) {
            $visual['structure'] = self::OLD_STRUCTURE;
        }

        foreach ($this->materialChanges()['changed'] as $key => ['from' => $from, 'to' => $to]) {
            if (($material[$key] ?? null) === $to) {
                $material[$key] = $from;
            }
        }
        foreach ($this->materialChanges()['added'] as $key => $value) {
            if (($material[$key] ?? null) === $value) {
                unset($material[$key]);
            }
        }

        $update = [
            'visual_config'   => json_encode($visual),
            'material_config' => json_encode($material),
        ];

        $fixtures = json_decode((string) $row->lighting_fixtures, true) ?: [];
        if ($fixtures === self::NEW_FIXTURES) {
            $update['lighting_fixtures'] = json_encode([]);
        }

        if ((string) $row->description === self::NEW_DESCRIPTION) {
            $update['description'] = self::OLD_DESCRIPTION;
        }

        if ($row->version === self::NEW_VERSION) {
            $update['version'] = self::OLD_VERSION;
        }

        DB::table('venue_templates')->where('id', $row->id)->update($update);
    }

    private function changedVisualKeys(): array
    {
        return [
            // P2 grand volume + warm ceiling
            'wall_height'           => ['from' => 4.5, 'to' => 5.2],
            'ceiling_height'        => ['from' => 4.5, 'to' => 5.2],
            'ceiling_color'         => ['from' => '0x080808', 'to' => '0x14110d'],
            // P4/P5 city-dusk atmosphere + fog depth layers
            'background_color'      => ['from' => '0x08090d', 'to' => '0x0a0b11'],
            'fog_color'             => ['from' => '0x08090d', 'to' => '0x0a0b10'],
            'fog_near'              => ['from' => 8, 'to' => 16],
            'fog_far'               => ['from' => 25, 'to' => 55],
            // P7 warm evening rig
            'ambient_color'         => ['from' => '0xb8c8e8', 'to' => '0xe6d6bc'],
            'ambient_intensity'     => ['from' => 0.2, 'to' => 0.26],
            'spot_intensity'        => ['from' => 0.5, 'to' => 0.62],
            'fill_intensity'        => ['from' => 0.15, 'to' => 0.16],
            'tone_mapping_exposure' => ['from' => 0.55, 'to' => 0.78],
        ];
    }

    private function addedVisualKeys(): array
    {
        return [
            // P4 the venue declares its sky (no HDRI — the sky is the city)
            'environment'            => 'none',
            'env_intensity'          => 0,
            'hemisphere_intensity'   => 0.14,
            // P9 artwork legibility floor
            'artwork_light_base'     => 0.22,
            'artwork_light_pool_cap' => 12,
        ];
    }

    private function materialChanges(): array
    {
        return [
            'changed' => [
                // P6 material hierarchy — warm mineral white / honed stone
                'wall_color'      => ['from' => null, 'to' => '0xe9e2d4'],
                'wall_roughness'  => ['from' => 0.8, 'to' => 0.9],
                'floor_color'     => ['from' => null, 'to' => '0x9b8d78'],
                'floor_roughness' => ['from' => 0.3, 'to' => 0.42],
                'floor_metalness' => ['from' => 0.2, 'to' => 0.06],
                'floor_normal_strength' => ['from' => 0.5, 'to' => 0.35],
            ],
            'added' => [
                'texture_tint'      => true,
                'floor_tile_meters' => 2.4,
            ],
        ];
    }
};
