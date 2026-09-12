<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SLUG = 'luxury-penthouse';

    private const OLD_VERSION = '2.1.0';
    private const NEW_VERSION = '3.0.0';

    private const OLD_DESCRIPTION = 'A private collector\'s floor at dusk — a walnut-and-stone gallery wing warming into a lounge at the glass, the city glowing beyond it, art hung the way a residence lives with it.';
    private const NEW_DESCRIPTION = 'A private collector\'s floor in two volumes — a low, coved gallery procession that lifts at a lit seam into a double-height living room glazed to the dusk city on two faces, the largest work living above the stone fireplace, the terrace wrapping the glass corner.';

    private const OLD_STRUCTURE = [
        // ── Terrace deck just outside the glazing (warm wood).
        ['id' => 'terrace-deck',   'primitive' => 'box',            'at' => ['from' => 'glazing_outside', 'offset' => [0, 0.04, 2.6]], 'turn' => 'out', 'fit' => 'glazing', 'fit_pad' => 0.1, 'size' => [1, 0.08, 5.0], 'material' => 'wood_warm'],
        // ── The glazing itself: tier-resolved glass + steel mullions.
        ['id' => 'glazing-glass',  'primitive' => 'plane',          'at' => ['from' => 'glazing', 'offset' => [0, 2.45, 0]], 'turn' => 'in', 'fit' => 'glazing', 'fit_pad' => 0.06, 'size' => [1, 4.9], 'material' => ['glass' => true, 'tint' => '0xd8e4ef', 'opacity' => 0.1, 'roughness' => 0.35, 'tier' => 'cheap']],
        ['id' => 'glazing-mullions', 'primitive' => 'instance-grid', 'at' => ['from' => 'glazing', 'offset' => [0, 2.45, 0.05]], 'turn' => 'in', 'size' => [0.06, 4.9, 0.085], 'material' => 'steel_dark', 'merge' => 'ph-steel', 'grid' => ['mode' => 'line', 'from' => 'glazing', 'span' => 'fit', 'fit_pad' => 0.16, 'spacing' => 1.4]],
        ['id' => 'glazing-sill',   'primitive' => 'box',            'at' => ['from' => 'glazing', 'offset' => [0, 0.05, 0]], 'turn' => 'in', 'fit' => 'glazing', 'fit_pad' => 0.02, 'size' => [1, 0.1, 0.1], 'material' => 'steel_dark', 'merge' => 'ph-steel', 'collide' => true],
        ['id' => 'glazing-head',   'primitive' => 'box',            'at' => ['from' => 'glazing', 'offset' => [0, 5.12, 0]], 'turn' => 'in', 'fit' => 'glazing', 'fit_pad' => 0.02, 'size' => [1, 0.12, 0.1], 'material' => 'steel_dark', 'merge' => 'ph-steel'],
        // ── Terrace rail line (inside the glass).
        ['id' => 'rail-bar',       'primitive' => 'box',            'at' => ['from' => 'glazing', 'offset' => [0, 0.95, 0.55]], 'turn' => 'in', 'fit' => 'glazing', 'fit_pad' => 0.5, 'size' => [1, 0.05, 0.05], 'material' => 'steel_dark', 'merge' => 'ph-rail', 'collide' => true],
        ['id' => 'rail-posts',     'primitive' => 'instance-grid',  'at' => ['from' => 'glazing', 'offset' => [0, 0.45, 0.55]], 'turn' => 'in', 'size' => [0.035, 0.9, 0.035], 'material' => 'steel_dark', 'merge' => 'ph-rail', 'grid' => ['mode' => 'line', 'from' => 'glazing', 'span' => 'fit', 'fit_pad' => 0.7, 'spacing' => 1.8]],
        ['id' => 'skyline-near',   'primitive' => 'instance-grid',  'size' => [1.7, 9, 1.7], 'material' => ['color' => '0x0f0d0a', 'emissive' => '0x7a5533', 'emissiveIntensity' => 0.42], 'grid' => ['mode' => 'scatter', 'count' => 6, 'seed' => 'skyline-cool', 'area' => ['from' => 'glazing_outside', 'size' => [34, 0, 20], 'forward' => 24], 'scale_jitter' => [0.4, 0.7], 'grounded' => true, 'yaw_jitter' => [0, 3.14159]]],
        ['id' => 'skyline-mid',    'primitive' => 'instance-grid',  'size' => [2.4, 9, 2.4], 'material' => ['color' => '0x0d1119', 'emissive' => '0x33415c', 'emissiveIntensity' => 0.3], 'grid' => ['mode' => 'scatter', 'count' => 8, 'seed' => 'skyline-warm', 'area' => ['from' => 'glazing_outside', 'size' => [36, 0, 24], 'forward' => 28], 'scale_jitter' => [0.6, 1.3], 'grounded' => true, 'yaw_jitter' => [0, 3.14159]]],
        ['id' => 'skyline-far',    'primitive' => 'instance-grid',  'size' => [3.4, 14, 3.4], 'material' => ['color' => '0x0a0d15', 'emissive' => '0x1c2740', 'emissiveIntensity' => 0.38], 'grid' => ['mode' => 'scatter', 'count' => 8, 'seed' => 'skyline-far-b', 'area' => ['from' => 'glazing_outside', 'size' => [50, 0, 52], 'forward' => 36], 'scale_jitter' => [0.6, 1.3], 'grounded' => true, 'yaw_jitter' => [0, 3.14159]]],
        ['id' => 'horizon-glow',   'primitive' => 'plane',          'at' => ['from' => 'glazing_outside', 'offset' => [0, 8.0, 44]], 'turn' => 'out', 'size' => [130, 26], 'material' => ['color' => '0x140f08', 'emissive' => '0x9a6238', 'emissiveIntensity' => 2.2]],
        ['id' => 'city-haze',     'primitive' => 'plane',          'at' => ['from' => 'glazing_outside', 'offset' => [0, 8.0, 32]], 'turn' => 'out', 'size' => [110, 22], 'material' => ['color' => '0x0d0a06', 'emissive' => '0x5f4126', 'emissiveIntensity' => 1.8]],
        ['id' => 'sky-mid',       'primitive' => 'plane',          'at' => ['from' => 'glazing_outside', 'offset' => [0, 15, 64]], 'turn' => 'out', 'size' => [130, 26], 'material' => ['color' => '0x0a0e18', 'emissive' => '0x445c82', 'emissiveIntensity' => 1.0]],
        ['id' => 'sky-deep',      'primitive' => 'plane',          'at' => ['from' => 'glazing_outside', 'offset' => [0, 22, 86]], 'turn' => 'out', 'size' => [150, 50], 'material' => ['color' => '0x070a12', 'emissive' => '0x111c33', 'emissiveIntensity' => 1.0]],
        ['id' => 'fireplace-stone', 'primitive' => 'box', 'at' => ['from' => 'wall_end', 'offset' => [0, 2.6, 0.11]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 1.6, 'size' => [1, 5.2, 0.2], 'material' => 'basalt'],
        ['id' => 'fireplace-mantel', 'primitive' => 'box', 'at' => ['from' => 'wall_end', 'offset' => [0, 1.3, 0.32]], 'turn' => 'in', 'size' => [2.6, 0.07, 0.3], 'material' => 'walnut'],
        ['id' => 'fireplace-band', 'primitive' => 'emissive-strip', 'at' => ['from' => 'wall_end', 'offset' => [0, 0.62, 0.365]], 'turn' => 'in', 'size' => [1.9, 0.16, 0.05], 'material' => ['color' => '0x1a0d06', 'emissive' => '0xff8a3d', 'emissiveIntensity' => 1.5]],
        ['id' => 'fireplace-hearth', 'primitive' => 'box', 'at' => ['from' => 'wall_end', 'offset' => [0, 0.03, 0.45]], 'turn' => 'in', 'size' => [3.0, 0.06, 0.55], 'material' => 'basalt'],
        ['id' => 'cove-left',   'primitive' => 'emissive-strip', 'at' => ['from' => 'wall_left', 'up' => 'ceiling', 'offset' => [0, -0.3, 0.24]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.9, 'size' => [1, 0.05, 0.09], 'material' => ['color' => '0x201810', 'emissive' => '0xffd9a0', 'emissiveIntensity' => 1.15], 'merge' => 'ph-cove'],
        ['id' => 'cove-shelf-left', 'primitive' => 'box', 'at' => ['from' => 'wall_left', 'up' => 'ceiling', 'offset' => [0, -0.22, 0.32]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.9, 'size' => [1, 0.03, 0.2], 'material' => 'dark_trim', 'merge' => 'ph-coveshelf'],
        ['id' => 'cove-right',   'primitive' => 'emissive-strip', 'at' => ['from' => 'wall_right', 'up' => 'ceiling', 'offset' => [0, -0.3, 0.24]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.9, 'size' => [1, 0.05, 0.09], 'material' => ['color' => '0x201810', 'emissive' => '0xffd9a0', 'emissiveIntensity' => 1.15], 'merge' => 'ph-cove'],
        ['id' => 'cove-shelf-right', 'primitive' => 'box', 'at' => ['from' => 'wall_right', 'up' => 'ceiling', 'offset' => [0, -0.22, 0.32]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.9, 'size' => [1, 0.03, 0.2], 'material' => 'dark_trim', 'merge' => 'ph-coveshelf'],
        ['id' => 'cove-inner',   'primitive' => 'emissive-strip', 'at' => ['from' => 'wall_inner', 'up' => 'ceiling', 'offset' => [0, -0.3, 0.24]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.9, 'size' => [1, 0.05, 0.09], 'material' => ['color' => '0x201810', 'emissive' => '0xffd9a0', 'emissiveIntensity' => 1.15], 'merge' => 'ph-cove'],
        ['id' => 'cove-shelf-inner', 'primitive' => 'box', 'at' => ['from' => 'wall_inner', 'up' => 'ceiling', 'offset' => [0, -0.22, 0.32]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.9, 'size' => [1, 0.03, 0.2], 'material' => 'dark_trim', 'merge' => 'ph-coveshelf'],
        ['id' => 'cove-back',   'primitive' => 'emissive-strip', 'at' => ['from' => 'wall_back', 'up' => 'ceiling', 'offset' => [0, -0.3, 0.24]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.9, 'size' => [1, 0.05, 0.09], 'material' => ['color' => '0x201810', 'emissive' => '0xffd9a0', 'emissiveIntensity' => 1.15], 'merge' => 'ph-cove'],
        ['id' => 'cove-shelf-back', 'primitive' => 'box', 'at' => ['from' => 'wall_back', 'up' => 'ceiling', 'offset' => [0, -0.22, 0.32]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.9, 'size' => [1, 0.03, 0.2], 'material' => 'dark_trim', 'merge' => 'ph-coveshelf'],
        // ── Bronze base trim: the quiet luxury line.
        ['id' => 'base-left',   'primitive' => 'box', 'at' => ['from' => 'wall_left', 'offset' => [0, 0.07, 0.02]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.4, 'size' => [1, 0.14, 0.03], 'material' => 'bronze', 'merge' => 'ph-base'],
        ['id' => 'base-right',  'primitive' => 'box', 'at' => ['from' => 'wall_right', 'offset' => [0, 0.07, 0.02]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.4, 'size' => [1, 0.14, 0.03], 'material' => 'bronze', 'merge' => 'ph-base'],
        ['id' => 'base-inner',  'primitive' => 'box', 'at' => ['from' => 'wall_inner', 'offset' => [0, 0.07, 0.02]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.4, 'size' => [1, 0.14, 0.03], 'material' => 'bronze', 'merge' => 'ph-base'],
        ['id' => 'base-back',   'primitive' => 'box', 'at' => ['from' => 'wall_back', 'offset' => [0, 0.07, 0.02]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.4, 'size' => [1, 0.14, 0.03], 'material' => 'bronze', 'merge' => 'ph-base'],
        ['id' => 'lounge-pendant', 'primitive' => 'emissive-strip', 'at' => ['from' => 'glazing', 'offset' => [0, 4.35, 1.75]], 'turn' => 'in', 'fit' => 'glazing', 'fit_pad' => 2.6, 'size' => [1, 0.045, 0.045], 'material' => ['color' => '0xffd9a8', 'emissive' => '0xffc98a', 'emissiveIntensity' => 2.2]],
        ['id' => 'lounge-rug',     'primitive' => 'plane', 'at' => ['from' => 'glazing', 'offset' => [0, 0.012, 2.0]],  'turn' => 'in', 'rot' => [-1.5707963, 0, 0], 'size' => [3.6, 2.6], 'material' => 'fabric_dark'],
        ['id' => 'sofa-base',      'primitive' => 'box',   'at' => ['from' => 'glazing', 'offset' => [0, 0.24, 1.75]],  'turn' => 'in', 'size' => [2.3, 0.48, 0.95], 'material' => 'fabric_warm', 'collide' => true],
        ['id' => 'sofa-back',      'primitive' => 'box',   'at' => ['from' => 'glazing', 'offset' => [0, 0.72, 2.15]],  'turn' => 'in', 'size' => [2.3, 0.5, 0.24],  'material' => 'fabric_warm'],
        ['id' => 'sofa-arm-l',     'primitive' => 'box',   'at' => ['from' => 'glazing', 'offset' => [-1.26, 0.42, 1.78]], 'turn' => 'in', 'size' => [0.22, 0.36, 0.9], 'material' => 'fabric_warm'],
        ['id' => 'sofa-arm-r',     'primitive' => 'box',   'at' => ['from' => 'glazing', 'offset' => [1.26, 0.42, 1.78]], 'turn' => 'in', 'size' => [0.22, 0.36, 0.9], 'material' => 'fabric_warm'],
        ['id' => 'table-top',      'primitive' => 'box',   'at' => ['from' => 'glazing', 'offset' => [0, 0.34, 0.9]],   'turn' => 'in', 'size' => [1.15, 0.05, 0.55], 'material' => 'wood_dark'],
        ['id' => 'table-pedestal', 'primitive' => 'box',   'at' => ['from' => 'glazing', 'offset' => [0, 0.15, 0.9]],   'turn' => 'in', 'size' => [0.5, 0.3, 0.35],  'material' => 'dark_trim', 'collide' => true],
        ['id' => 'chair-seat',     'primitive' => 'box',   'at' => ['from' => 'glazing', 'offset' => [-1.95, 0.3, 2.9]], 'turn' => 'in', 'size' => [0.85, 0.44, 0.85], 'material' => 'fabric_dark', 'collide' => true],
        ['id' => 'chair-back',     'primitive' => 'box',   'at' => ['from' => 'glazing', 'offset' => [-1.95, 0.63, 3.35]], 'turn' => 'in', 'size' => [0.12, 0.55, 0.85], 'material' => 'fabric_dark'],
        ['id' => 'lamp-pole',      'primitive' => 'cylinder', 'at' => ['from' => 'glazing', 'offset' => [2.05, 0.8, 2.6]], 'turn' => 'in', 'size' => [0.035, 1.6, 0.035], 'material' => 'steel_dark'],
        ['id' => 'lamp-shade',     'primitive' => 'emissive-strip', 'at' => ['from' => 'glazing', 'offset' => [2.05, 1.68, 2.6]], 'turn' => 'in', 'size' => [0.36, 0.32, 0.36], 'material' => ['color' => '0x2a2018', 'emissive' => '0xffd9a0', 'emissiveIntensity' => 0.8]],
        ['id' => 'plinth',         'primitive' => 'cylinder', 'at' => ['from' => 'glazing', 'offset' => [2.35, 0.55, 4.35]], 'turn' => 'in', 'size' => [0.42, 1.1, 0.42], 'material' => 'basalt', 'collide' => true],
        ['id' => 'sculpture-torus', 'primitive' => 'torus', 'at' => ['from' => 'glazing', 'offset' => [2.35, 1.42, 4.35]], 'turn' => 'in', 'size' => [0.3, 0.09, 0.3], 'params' => ['seg' => 24, 'seg2' => 48], 'material' => 'bronze'],
        ['id' => 'bench-top',      'primitive' => 'box', 'at' => ['from' => 'wall_left', 'offset' => [0, 0.44, 0.42]], 'turn' => 'in', 'size' => [2.2, 0.06, 0.45], 'material' => 'walnut'],
        ['id' => 'bench-base',     'primitive' => 'box', 'at' => ['from' => 'wall_left', 'offset' => [0, 0.21, 0.42]], 'turn' => 'in', 'size' => [2.0, 0.42, 0.38], 'material' => 'dark_trim', 'collide' => true],
        ['id' => 'floor-joints',   'primitive' => 'instance-grid', 'size' => [5.4, 0.012, 0.02], 'material' => ['color' => '0x6b5f4c', 'roughness' => 0.55, 'metalness' => 0.02], 'grid' => ['mode' => 'box', 'area' => ['from' => 'center', 'y' => 0.006, 'size' => ['fit' => 'room', 'pad' => [0.3, 0.9]]], 'spacing' => [0, 0, 2.4]]],
    ];

    private const NEW_STRUCTURE = [
        ['id' => 'step-fascia', 'primitive' => 'box', 'at' => ['from' => 'junction', 'offset' => [0, 3.375, 0.12]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.3, 'size' => [1, 0.35, 0.3], 'material' => 'plaster_warm'],
        ['id' => 'step-slot', 'primitive' => 'emissive-strip', 'at' => ['from' => 'junction_outside', 'offset' => [0, 3.47, 0.12]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.9, 'size' => [1, 0.06, 0.06], 'material' => ['color' => '0x2a1c10', 'emissive' => '0xffe6c4', 'emissiveIntensity' => 1.6], 'merge' => 'ph-slot'],
        ['id' => 'seam-slot-inner', 'primitive' => 'emissive-strip', 'at' => ['from' => 'wall_inner', 'offset' => [0, 3.47, -0.12]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.9, 'size' => [1, 0.06, 0.06], 'material' => ['color' => '0x2a1c10', 'emissive' => '0xffe6c4', 'emissiveIntensity' => 1.6], 'merge' => 'ph-slot'],
        ['id' => 'plinth', 'primitive' => 'cylinder', 'at' => ['from' => 'junction', 'offset' => [0, 0.55, 1.5]], 'turn' => 'in', 'size' => [0.42, 1.1, 0.42], 'material' => 'basalt', 'collide' => true],
        ['id' => 'sculpture-knot', 'primitive' => 'torus', 'at' => ['from' => 'junction', 'offset' => [0, 1.42, 1.5]], 'turn' => 'in', 'size' => [0.34, 0.1, 0.34], 'params' => ['seg' => 24, 'seg2' => 48], 'material' => 'bronze'],
        ['id' => 'fireplace-stone', 'primitive' => 'box', 'at' => ['from' => 'wall_end', 'offset' => [0, 3.15, 0.11]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 1.6, 'size' => [1, 6.3, 0.2], 'material' => 'basalt', 'hangable' => ['y' => 3.25]],
        ['id' => 'fireplace-mantel', 'primitive' => 'box', 'at' => ['from' => 'wall_end', 'offset' => [0, 1.3, 0.32]], 'turn' => 'in', 'size' => [2.6, 0.07, 0.3], 'material' => 'walnut'],
        ['id' => 'fireplace-band', 'primitive' => 'emissive-strip', 'at' => ['from' => 'wall_end', 'offset' => [0, 0.62, 0.365]], 'turn' => 'in', 'size' => [1.9, 0.16, 0.05], 'material' => ['color' => '0x1a0d06', 'emissive' => '0xff8a3d', 'emissiveIntensity' => 1.5]],
        ['id' => 'fireplace-hearth', 'primitive' => 'box', 'at' => ['from' => 'wall_end', 'offset' => [0, 0.03, 0.45]], 'turn' => 'in', 'size' => [3.0, 0.06, 0.55], 'material' => 'basalt'],
        ['id' => 'art-wall-panel', 'primitive' => 'box', 'at' => ['from' => 'wall_left_high', 'offset' => [0, 2.6, 0.03]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.8, 'size' => [1, 5.2, 0.06], 'material' => 'walnut', 'hangable' => ['y' => 2.6]],
        ['id' => 'glazing-glass',  'primitive' => 'plane',          'at' => ['from' => 'glazing', 'offset' => [0, 3.15, 0]], 'turn' => 'in', 'fit' => 'glazing', 'fit_pad' => 0.06, 'size' => [1, 6.0], 'material' => ['glass' => true, 'tint' => '0xd8e4ef', 'opacity' => 0.1, 'roughness' => 0.35, 'tier' => 'cheap']],
        ['id' => 'glazing-mullions', 'primitive' => 'instance-grid', 'at' => ['from' => 'glazing', 'offset' => [0, 3.15, 0.05]], 'turn' => 'in', 'size' => [0.06, 6.0, 0.085], 'material' => 'steel_dark', 'merge' => 'ph-steel', 'grid' => ['mode' => 'line', 'from' => 'glazing', 'span' => 'fit', 'fit_pad' => 0.16, 'spacing' => 1.4]],
        ['id' => 'glazing-sill',   'primitive' => 'box',            'at' => ['from' => 'glazing', 'offset' => [0, 0.05, 0]], 'turn' => 'in', 'fit' => 'glazing', 'fit_pad' => 0.02, 'size' => [1, 0.1, 0.1], 'material' => 'steel_dark', 'merge' => 'ph-steel', 'collide' => true],
        ['id' => 'glazing-head',   'primitive' => 'box',            'at' => ['from' => 'glazing', 'offset' => [0, 6.18, 0]], 'turn' => 'in', 'fit' => 'glazing', 'fit_pad' => 0.02, 'size' => [1, 0.12, 0.1], 'material' => 'steel_dark', 'merge' => 'ph-steel'],
        ['id' => 'glazing-glass-north',  'primitive' => 'plane',          'at' => ['from' => 'glazing_north', 'offset' => [0, 3.15, 0]], 'turn' => 'in', 'fit' => 'glazing', 'fit_pad' => 0.06, 'size' => [1, 6.0], 'material' => ['glass' => true, 'tint' => '0xd8e4ef', 'opacity' => 0.1, 'roughness' => 0.35, 'tier' => 'cheap']],
        ['id' => 'glazing-mullions-north', 'primitive' => 'instance-grid', 'at' => ['from' => 'glazing_north', 'offset' => [0, 3.15, 0.05]], 'turn' => 'in', 'size' => [0.06, 6.0, 0.085], 'material' => 'steel_dark', 'merge' => 'ph-steel', 'grid' => ['mode' => 'line', 'from' => 'glazing_north', 'span' => 'fit', 'fit_pad' => 0.16, 'spacing' => 1.4]],
        ['id' => 'glazing-sill-north',   'primitive' => 'box',            'at' => ['from' => 'glazing_north', 'offset' => [0, 0.05, 0]], 'turn' => 'in', 'fit' => 'glazing', 'fit_pad' => 0.02, 'size' => [1, 0.1, 0.1], 'material' => 'steel_dark', 'merge' => 'ph-steel', 'collide' => true],
        ['id' => 'glazing-head-north',   'primitive' => 'box',            'at' => ['from' => 'glazing_north', 'offset' => [0, 6.18, 0]], 'turn' => 'in', 'fit' => 'glazing', 'fit_pad' => 0.02, 'size' => [1, 0.12, 0.1], 'material' => 'steel_dark', 'merge' => 'ph-steel'],
        ['id' => 'terrace-deck-east',  'primitive' => 'box', 'at' => ['from' => 'glazing_outside', 'offset' => [0, 0.04, 2.6]], 'turn' => 'out', 'fit' => 'glazing', 'fit_pad' => 0.1, 'size' => [1, 0.08, 5.0], 'material' => 'wood_warm'],
        ['id' => 'terrace-deck-north', 'primitive' => 'box', 'at' => ['from' => 'glazing_north_outside', 'offset' => [0, 0.04, 2.6]], 'turn' => 'out', 'fit' => 'glazing', 'fit_pad' => 0.1, 'size' => [1, 0.08, 5.0], 'material' => 'wood_warm'],
        ['id' => 'rail-bar-east',  'primitive' => 'box',           'at' => ['from' => 'glazing', 'offset' => [0, 0.95, 0.55]], 'turn' => 'in', 'fit' => 'glazing', 'fit_pad' => 0.5, 'size' => [1, 0.05, 0.05], 'material' => 'steel_dark', 'merge' => 'ph-rail', 'collide' => true],
        ['id' => 'rail-posts-east', 'primitive' => 'instance-grid', 'at' => ['from' => 'glazing', 'offset' => [0, 0.45, 0.55]], 'turn' => 'in', 'size' => [0.035, 0.9, 0.035], 'material' => 'steel_dark', 'merge' => 'ph-rail', 'grid' => ['mode' => 'line', 'from' => 'glazing', 'span' => 'fit', 'fit_pad' => 0.7, 'spacing' => 1.8]],
        ['id' => 'rail-bar-north',  'primitive' => 'box',           'at' => ['from' => 'glazing_north', 'offset' => [0, 0.95, 0.55]], 'turn' => 'in', 'fit' => 'glazing', 'fit_pad' => 0.5, 'size' => [1, 0.05, 0.05], 'material' => 'steel_dark', 'merge' => 'ph-rail', 'collide' => true],
        ['id' => 'rail-posts-north', 'primitive' => 'instance-grid', 'at' => ['from' => 'glazing_north', 'offset' => [0, 0.45, 0.55]], 'turn' => 'in', 'size' => [0.035, 0.9, 0.035], 'material' => 'steel_dark', 'merge' => 'ph-rail', 'grid' => ['mode' => 'line', 'from' => 'glazing_north', 'span' => 'fit', 'fit_pad' => 0.7, 'spacing' => 1.8]],
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
        ['id' => 'cove-left',   'primitive' => 'emissive-strip', 'at' => ['from' => 'wall_left', 'up' => 'ceiling', 'offset' => [0, -0.3, 0.24]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.9, 'size' => [1, 0.05, 0.09], 'material' => ['color' => '0x201810', 'emissive' => '0xffd9a0', 'emissiveIntensity' => 1.15], 'merge' => 'ph-cove'],
        ['id' => 'cove-shelf-left', 'primitive' => 'box', 'at' => ['from' => 'wall_left', 'up' => 'ceiling', 'offset' => [0, -0.22, 0.32]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.9, 'size' => [1, 0.03, 0.2], 'material' => 'dark_trim', 'merge' => 'ph-coveshelf'],
        ['id' => 'cove-right',   'primitive' => 'emissive-strip', 'at' => ['from' => 'wall_right', 'up' => 'ceiling', 'offset' => [0, -0.3, 0.24]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.9, 'size' => [1, 0.05, 0.09], 'material' => ['color' => '0x201810', 'emissive' => '0xffd9a0', 'emissiveIntensity' => 1.15], 'merge' => 'ph-cove'],
        ['id' => 'cove-shelf-right', 'primitive' => 'box', 'at' => ['from' => 'wall_right', 'up' => 'ceiling', 'offset' => [0, -0.22, 0.32]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.9, 'size' => [1, 0.03, 0.2], 'material' => 'dark_trim', 'merge' => 'ph-coveshelf'],
        ['id' => 'cove-front',   'primitive' => 'emissive-strip', 'at' => ['from' => 'wall_front', 'up' => 'ceiling', 'offset' => [0, -0.3, 0.24]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.9, 'size' => [1, 0.05, 0.09], 'material' => ['color' => '0x201810', 'emissive' => '0xffd9a0', 'emissiveIntensity' => 1.15], 'merge' => 'ph-cove'],
        ['id' => 'cove-shelf-front', 'primitive' => 'box', 'at' => ['from' => 'wall_front', 'up' => 'ceiling', 'offset' => [0, -0.22, 0.32]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.9, 'size' => [1, 0.03, 0.2], 'material' => 'dark_trim', 'merge' => 'ph-coveshelf'],
        ['id' => 'base-left',   'primitive' => 'box', 'at' => ['from' => 'wall_left', 'offset' => [0, 0.07, 0.02]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.4, 'size' => [1, 0.14, 0.03], 'material' => 'bronze', 'merge' => 'ph-base'],
        ['id' => 'base-right',  'primitive' => 'box', 'at' => ['from' => 'wall_right', 'offset' => [0, 0.07, 0.02]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.4, 'size' => [1, 0.14, 0.03], 'material' => 'bronze', 'merge' => 'ph-base'],
        ['id' => 'base-front',  'primitive' => 'box', 'at' => ['from' => 'wall_front', 'offset' => [0, 0.07, 0.02]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.4, 'size' => [1, 0.14, 0.03], 'material' => 'bronze', 'merge' => 'ph-base'],
        ['id' => 'base-inner',  'primitive' => 'box', 'at' => ['from' => 'wall_inner', 'offset' => [0, 0.07, 0.02]], 'turn' => 'in', 'fit' => 'wall', 'fit_pad' => 0.4, 'size' => [1, 0.14, 0.03], 'material' => 'bronze', 'merge' => 'ph-base'],
        ['id' => 'bench-top',      'primitive' => 'box', 'at' => ['from' => 'wall_left', 'offset' => [0, 0.44, 0.42]], 'turn' => 'in', 'size' => [2.2, 0.06, 0.45], 'material' => 'walnut'],
        ['id' => 'bench-base',     'primitive' => 'box', 'at' => ['from' => 'wall_left', 'offset' => [0, 0.21, 0.42]], 'turn' => 'in', 'size' => [2.0, 0.42, 0.38], 'material' => 'dark_trim', 'collide' => true],
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
        ['id' => 'floor-joints',   'primitive' => 'instance-grid', 'size' => [5.4, 0.012, 0.02], 'material' => ['color' => '0x6b5f4c', 'roughness' => 0.55, 'metalness' => 0.02], 'grid' => ['mode' => 'box', 'area' => ['from' => 'center', 'y' => 0.006, 'size' => ['fit' => 'room', 'pad' => [0.3, 0.9]]], 'spacing' => [0, 0, 2.4]]],
    ];

    private const OLD_FIXTURES = [
                ['id' => 'fire-glow', 'type' => 'point', 'anchor' => ['from' => 'wall_end', 'offset' => [0, 0.95, 1.1]], 'color' => '0xff9a50', 'intensity' => 5, 'distance' => 6, 'decay' => 2, 'cast_shadow' => false],
                ['id' => 'hearth-wash', 'type' => 'point', 'anchor' => ['from' => 'wall_end', 'offset' => [0, 4.2, 1.3]], 'color' => '0xffd9a0', 'intensity' => 2.2, 'distance' => 5, 'decay' => 1.8, 'cast_shadow' => false],
                ['id' => 'cove-wash-a', 'type' => 'point', 'anchor' => ['from' => 'wall_left', 'offset' => [0, 4.1, 2.6]], 'color' => '0xffd9a0', 'intensity' => 3.2, 'distance' => 12, 'decay' => 1.8, 'cast_shadow' => false],
                ['id' => 'cove-wash-b', 'type' => 'point', 'anchor' => ['from' => 'wall_inner', 'offset' => [0, 4.1, 2.6]], 'color' => '0xffd9a0', 'intensity' => 3.2, 'distance' => 12, 'decay' => 1.8, 'cast_shadow' => false],
                ['id' => 'lounge-wash', 'type' => 'point', 'anchor' => ['from' => 'glazing', 'offset' => [0, 3.9, 3.4]], 'color' => '0xffe2b8', 'intensity' => 1.8, 'distance' => 8, 'decay' => 1.8, 'cast_shadow' => false],
    ];

    private const NEW_FIXTURES = [
                ['id' => 'fire-glow', 'type' => 'point', 'anchor' => ['from' => 'wall_end', 'offset' => [0, 0.95, 1.1]], 'color' => '0xff9a50', 'intensity' => 5, 'distance' => 6, 'decay' => 2, 'cast_shadow' => false],
                ['id' => 'hearth-wash', 'type' => 'point', 'anchor' => ['from' => 'wall_end', 'offset' => [0, 5.0, 1.3]], 'color' => '0xffd9a0', 'intensity' => 2.2, 'distance' => 5, 'decay' => 1.8, 'cast_shadow' => false],
                ['id' => 'step-wash', 'type' => 'point', 'anchor' => ['from' => 'junction', 'offset' => [0, 4.65, 1.6]], 'color' => '0xffd9a0', 'intensity' => 3.2, 'distance' => 14, 'decay' => 1.8, 'cast_shadow' => false],
                ['id' => 'gallery-cove-wash', 'type' => 'point', 'anchor' => ['from' => 'wall_left', 'offset' => [0, 2.8, 2.6]], 'color' => '0xffd9a0', 'intensity' => 3.2, 'distance' => 12, 'decay' => 1.8, 'cast_shadow' => false],
                ['id' => 'lounge-wash', 'type' => 'point', 'anchor' => ['from' => 'glazing', 'offset' => [0.5, 4.9, 3.4]], 'color' => '0xffe2b8', 'intensity' => 1.8, 'distance' => 8, 'decay' => 1.8, 'cast_shadow' => false],
    ];

    public function up(): void
    {
        $row = DB::table('venue_templates')->where('slug', self::SLUG)->first(['id', 'visual_config', 'lighting_fixtures', 'description', 'version']);
        if (!$row) {
            return; // venue removed by the operator — respect that
        }

        $visual = json_decode((string) $row->visual_config, true) ?: [];

        // Changed values — only while still equal to the seeded v2.1.0 value.
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

        if (($visual['structure'] ?? null) === self::OLD_STRUCTURE) {
            $visual['structure'] = self::NEW_STRUCTURE;
        }

        $update = [
            'visual_config' => json_encode($visual),
        ];

        $fixtures = json_decode((string) $row->lighting_fixtures, true) ?: [];
        if ($fixtures === self::OLD_FIXTURES) {
            $update['lighting_fixtures'] = json_encode(self::NEW_FIXTURES);
        }

        if ($row->description === self::OLD_DESCRIPTION) {
            $update['description'] = self::NEW_DESCRIPTION;
        }

        if ($row->version === self::OLD_VERSION) {
            $update['version'] = self::NEW_VERSION;
        }

        DB::table('venue_templates')->where('id', $row->id)->update($update);
    }

    public function down(): void
    {
        $row = DB::table('venue_templates')->where('slug', self::SLUG)->first(['id', 'visual_config', 'lighting_fixtures', 'description', 'version']);
        if (!$row) {
            return;
        }

        $visual = json_decode((string) $row->visual_config, true) ?: [];

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

        $update = [
            'visual_config' => json_encode($visual),
        ];

        $fixtures = json_decode((string) $row->lighting_fixtures, true) ?: [];
        if ($fixtures === self::NEW_FIXTURES) {
            $update['lighting_fixtures'] = json_encode(self::OLD_FIXTURES);
        }

        if ($row->description === self::NEW_DESCRIPTION) {
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
            'wall_height' => ['from' => 5.2, 'to' => 6.3],
            'ceiling_height' => ['from' => 5.2, 'to' => 6.3],
            'frame_override' => ['from' => 'gold', 'to' => 'bronze'],
        ];
    }

    private function addedVisualKeys(): array
    {
        return [
            'wing_heights' => ['wing_a' => 3.55, 'wing_b' => 6.3],
            'glazing_walls' => ['wing_b_end', 'wing_b_north'],
        ];
    }
};
