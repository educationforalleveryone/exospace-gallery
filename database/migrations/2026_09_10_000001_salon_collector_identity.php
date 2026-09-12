<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private function guardedEquals($current, $from): bool
    {
        if ($current === null) return false;
        if (is_string($from)) return is_string($current) && $current === $from;
        if (is_bool($from)) return is_bool($current) && $current === $from;
        return is_numeric($current) && (float) $current === (float) $from;
    }

    private function blockEquals($a, $b): bool
    {
        if (is_array($a) || is_array($b)) {
            if (!is_array($a) || !is_array($b) || count($a) !== count($b)) return false;
            foreach ($b as $k => $v) {
                if (!array_key_exists($k, $a) || !$this->blockEquals($a[$k], $v)) return false;
            }
            return true;
        }
        if (is_string($a) || is_string($b)) return (string) $a === (string) $b;
        if (is_bool($a) || is_bool($b)) return $a === $b;
        if (is_numeric($a) && is_numeric($b)) return (float) $a === (float) $b;
        return $a === $b;
    }

    private function v1Description(): string
    {
        return 'A small, warm room in the domestic tradition: works hung close together at conversational distance, a wooden picture rail and a bench, under soft warm light. Made for studies, prints, photography and portrait formats.';
    }

    private function v2Description(): string
    {
        return 'A warm collector\u2019s salon in the domestic tradition: a doorcase behind you, a hero wall ahead, works hung salon-style \u2014 large at eye level, smaller above \u2014 between walnut trim and a picture rail, under a coved warm light. Made for studies, prints, photography and portrait formats.';
    }

    private function v1Structure(bool $benchTurn = false): array
    {
        $bench = ['id' => 'bench-top', 'primitive' => 'box', 'at' => ['from' => 'center', 'offset' => [0, 0.42, 1.4]]]
            + ($benchTurn ? ['turn' => 'in'] : [])
            + ['size' => [1.5, 0.09, 0.42], 'material' => 'wood_warm', 'collide' => true, 'merge' => 'salon-bench', 'tier_floor' => 'low'];
        return [
            ['id' => 'rail-front', 'primitive' => 'box', 'at' => ['from' => 'wall_front', 'offset' => [0, 0.9, 0.045]], 'size' => [1, 0.07, 0.09], 'fit' => 'wall', 'fit_pad' => 0.3, 'material' => 'wood_dark', 'merge' => 'salon-rail', 'tier_floor' => 'low'],
            ['id' => 'rail-back', 'primitive' => 'box', 'at' => ['from' => 'wall_back', 'offset' => [0, 0.9, 0.045]], 'size' => [1, 0.07, 0.09], 'fit' => 'wall', 'fit_pad' => 0.3, 'material' => 'wood_dark', 'merge' => 'salon-rail', 'tier_floor' => 'low'],
            ['id' => 'rail-left', 'primitive' => 'box', 'at' => ['from' => 'wall_left', 'offset' => [0, 0.9, 0.045]], 'size' => [1, 0.07, 0.09], 'fit' => 'wall', 'fit_pad' => 0.3, 'material' => 'wood_dark', 'merge' => 'salon-rail', 'tier_floor' => 'low'],
            ['id' => 'rail-right', 'primitive' => 'box', 'at' => ['from' => 'wall_right', 'offset' => [0, 0.9, 0.045]], 'size' => [1, 0.07, 0.09], 'fit' => 'wall', 'fit_pad' => 0.3, 'material' => 'wood_dark', 'merge' => 'salon-rail', 'tier_floor' => 'low'],
            $bench,
            ['id' => 'bench-leg-l', 'primitive' => 'box', 'at' => ['from' => 'center', 'offset' => [-0.65, 0.19, 1.4]], 'size' => [0.09, 0.38, 0.38], 'material' => 'wood_dark', 'merge' => 'salon-bench', 'tier_floor' => 'low'],
            ['id' => 'bench-leg-r', 'primitive' => 'box', 'at' => ['from' => 'center', 'offset' => [0.65, 0.19, 1.4]], 'size' => [0.09, 0.38, 0.38], 'material' => 'wood_dark', 'merge' => 'salon-bench', 'tier_floor' => 'low'],
            ['id' => 'rug', 'primitive' => 'plane', 'at' => ['from' => 'center', 'offset' => [0, 0.012, 1.4]], 'rot' => [-1.5707963, 0, 0], 'size' => [2.6, 1.8], 'material' => 'fabric_warm', 'tier_floor' => 'low'],
        ];
    }

    private function v1Placement(): array
    {
        return [
            'density'          => 'intimate',
            'pair_orientation' => true,
        ];
    }

    private function v2Structure(): array
    {
        return [
                        ['id' => 'base-front', 'primitive' => 'box', 'at' => ['from' => 'wall_front', 'offset' => [0, 0.09, 0.0]], 'size' => [1, 0.18, 0.024], 'fit' => 'wall', 'fit_pad' => 0.02, 'material' => 'wood_dark', 'merge' => 'salon-trim', 'tier_floor' => 'low'],
                        ['id' => 'base-back', 'primitive' => 'box', 'at' => ['from' => 'wall_back', 'offset' => [0, 0.09, 0.0]], 'size' => [1, 0.18, 0.024], 'fit' => 'wall', 'fit_pad' => 0.02, 'material' => 'wood_dark', 'merge' => 'salon-trim', 'tier_floor' => 'low'],
                        ['id' => 'base-left', 'primitive' => 'box', 'at' => ['from' => 'wall_left', 'offset' => [0, 0.09, 0.0]], 'size' => [1, 0.18, 0.024], 'fit' => 'wall', 'fit_pad' => 0.02, 'material' => 'wood_dark', 'merge' => 'salon-trim', 'tier_floor' => 'low'],
                        ['id' => 'base-right', 'primitive' => 'box', 'at' => ['from' => 'wall_right', 'offset' => [0, 0.09, 0.0]], 'size' => [1, 0.18, 0.024], 'fit' => 'wall', 'fit_pad' => 0.02, 'material' => 'wood_dark', 'merge' => 'salon-trim', 'tier_floor' => 'low'],
                        ['id' => 'field-front', 'primitive' => 'box', 'at' => ['from' => 'wall_front', 'offset' => [0, 1.98, 0.011]], 'size' => [1, 3.08, 0.022], 'fit' => 'wall', 'fit_pad' => 0.34, 'material' => ['color' => '0xe9dfc9', 'roughness' => 0.94, 'metalness' => 0.0], 'merge' => 'salon-field', 'tier_floor' => 'low'],
                        ['id' => 'field-back', 'primitive' => 'box', 'at' => ['from' => 'wall_back', 'offset' => [0, 1.98, 0.011]], 'size' => [1, 3.08, 0.022], 'fit' => 'wall', 'fit_pad' => 0.34, 'material' => ['color' => '0xe9dfc9', 'roughness' => 0.94, 'metalness' => 0.0], 'merge' => 'salon-field', 'tier_floor' => 'low'],
                        ['id' => 'field-left', 'primitive' => 'box', 'at' => ['from' => 'wall_left', 'offset' => [0, 1.98, 0.011]], 'size' => [1, 3.08, 0.022], 'fit' => 'wall', 'fit_pad' => 0.34, 'material' => ['color' => '0xe9dfc9', 'roughness' => 0.94, 'metalness' => 0.0], 'merge' => 'salon-field', 'tier_floor' => 'low'],
                        ['id' => 'field-right', 'primitive' => 'box', 'at' => ['from' => 'wall_right', 'offset' => [0, 1.98, 0.011]], 'size' => [1, 3.08, 0.022], 'fit' => 'wall', 'fit_pad' => 0.34, 'material' => ['color' => '0xe9dfc9', 'roughness' => 0.94, 'metalness' => 0.0], 'merge' => 'salon-field', 'tier_floor' => 'low'],
                        ['id' => 'rail-front', 'primitive' => 'box', 'at' => ['from' => 'wall_front', 'offset' => [0, 3.53, 0.0]], 'size' => [1, 0.09, 0.04], 'fit' => 'wall', 'fit_pad' => 0.02, 'material' => 'wood_dark', 'merge' => 'salon-trim', 'tier_floor' => 'low'],
                        ['id' => 'rail-back', 'primitive' => 'box', 'at' => ['from' => 'wall_back', 'offset' => [0, 3.53, 0.0]], 'size' => [1, 0.09, 0.04], 'fit' => 'wall', 'fit_pad' => 0.02, 'material' => 'wood_dark', 'merge' => 'salon-trim', 'tier_floor' => 'low'],
                        ['id' => 'rail-left', 'primitive' => 'box', 'at' => ['from' => 'wall_left', 'offset' => [0, 3.53, 0.0]], 'size' => [1, 0.09, 0.04], 'fit' => 'wall', 'fit_pad' => 0.02, 'material' => 'wood_dark', 'merge' => 'salon-trim', 'tier_floor' => 'low'],
                        ['id' => 'rail-right', 'primitive' => 'box', 'at' => ['from' => 'wall_right', 'offset' => [0, 3.53, 0.0]], 'size' => [1, 0.09, 0.04], 'fit' => 'wall', 'fit_pad' => 0.02, 'material' => 'wood_dark', 'merge' => 'salon-trim', 'tier_floor' => 'low'],
                        ['id' => 'cornice-front', 'primitive' => 'box', 'at' => ['from' => 'wall_front', 'offset' => [0, 3.72, 0.0]], 'size' => [1, 0.16, 0.056], 'fit' => 'wall', 'fit_pad' => 0.0, 'material' => ['color' => '0xcabfa4', 'roughness' => 0.9, 'metalness' => 0.0], 'merge' => 'salon-cornice', 'tier_floor' => 'low'],
                        ['id' => 'cornice-back', 'primitive' => 'box', 'at' => ['from' => 'wall_back', 'offset' => [0, 3.72, 0.0]], 'size' => [1, 0.16, 0.056], 'fit' => 'wall', 'fit_pad' => 0.0, 'material' => ['color' => '0xcabfa4', 'roughness' => 0.9, 'metalness' => 0.0], 'merge' => 'salon-cornice', 'tier_floor' => 'low'],
                        ['id' => 'cornice-left', 'primitive' => 'box', 'at' => ['from' => 'wall_left', 'offset' => [0, 3.72, 0.0]], 'size' => [1, 0.16, 0.056], 'fit' => 'wall', 'fit_pad' => 0.0, 'material' => ['color' => '0xcabfa4', 'roughness' => 0.9, 'metalness' => 0.0], 'merge' => 'salon-cornice', 'tier_floor' => 'low'],
                        ['id' => 'cornice-right', 'primitive' => 'box', 'at' => ['from' => 'wall_right', 'offset' => [0, 3.72, 0.0]], 'size' => [1, 0.16, 0.056], 'fit' => 'wall', 'fit_pad' => 0.0, 'material' => ['color' => '0xcabfa4', 'roughness' => 0.9, 'metalness' => 0.0], 'merge' => 'salon-cornice', 'tier_floor' => 'low'],
                        ['id' => 'cove-front', 'primitive' => 'box', 'at' => ['from' => 'wall_front', 'offset' => [0, 3.615, 0.0]], 'size' => [1, 0.045, 0.028], 'fit' => 'wall', 'fit_pad' => 0.06, 'material' => ['color' => '0xffe8cc', 'emissive' => '0xffd9a8', 'emissiveIntensity' => 0.85], 'merge' => 'salon-cove', 'tier_floor' => 'low'],
                        ['id' => 'cove-back', 'primitive' => 'box', 'at' => ['from' => 'wall_back', 'offset' => [0, 3.615, 0.0]], 'size' => [1, 0.045, 0.028], 'fit' => 'wall', 'fit_pad' => 0.06, 'material' => ['color' => '0xffe8cc', 'emissive' => '0xffd9a8', 'emissiveIntensity' => 0.85], 'merge' => 'salon-cove', 'tier_floor' => 'low'],
                        ['id' => 'cove-left', 'primitive' => 'box', 'at' => ['from' => 'wall_left', 'offset' => [0, 3.615, 0.0]], 'size' => [1, 0.045, 0.028], 'fit' => 'wall', 'fit_pad' => 0.06, 'material' => ['color' => '0xffe8cc', 'emissive' => '0xffd9a8', 'emissiveIntensity' => 0.85], 'merge' => 'salon-cove', 'tier_floor' => 'low'],
                        ['id' => 'cove-right', 'primitive' => 'box', 'at' => ['from' => 'wall_right', 'offset' => [0, 3.615, 0.0]], 'size' => [1, 0.045, 0.028], 'fit' => 'wall', 'fit_pad' => 0.06, 'material' => ['color' => '0xffe8cc', 'emissive' => '0xffd9a8', 'emissiveIntensity' => 0.85], 'merge' => 'salon-cove', 'tier_floor' => 'low'],
                        ['id' => 'door-leaf', 'primitive' => 'box', 'at' => ['from' => 'wall_back', 'offset' => [0, 1.21, 0.0]], 'size' => [0.92, 2.42, 0.04], 'material' => ['color' => '0x35281a', 'roughness' => 0.85, 'metalness' => 0.0], 'merge' => 'salon-door', 'tier_floor' => 'low'],
                        ['id' => 'door-panel', 'primitive' => 'box', 'at' => ['from' => 'wall_back', 'offset' => [0, 1.21, 0.026]], 'size' => [0.68, 1.9, 0.012], 'material' => ['color' => '0x463623', 'roughness' => 0.75, 'metalness' => 0.0], 'merge' => 'salon-door', 'tier_floor' => 'low'],
                        ['id' => 'door-jamb-l', 'primitive' => 'box', 'at' => ['from' => 'wall_back', 'offset' => [-0.475, 1.26, 0.02]], 'size' => [0.1, 2.52, 0.032], 'material' => 'wood_dark', 'merge' => 'salon-trim', 'tier_floor' => 'low'],
                        ['id' => 'door-jamb-r', 'primitive' => 'box', 'at' => ['from' => 'wall_back', 'offset' => [0.475, 1.26, 0.02]], 'size' => [0.1, 2.52, 0.032], 'material' => 'wood_dark', 'merge' => 'salon-trim', 'tier_floor' => 'low'],
                        ['id' => 'door-head', 'primitive' => 'box', 'at' => ['from' => 'wall_back', 'offset' => [0, 2.59, 0.02]], 'size' => [1.05, 0.14, 0.032], 'material' => 'wood_dark', 'merge' => 'salon-trim', 'tier_floor' => 'low'],
                        ['id' => 'door-overdoor', 'primitive' => 'box', 'at' => ['from' => 'wall_back', 'offset' => [0, 2.95, 0.008]], 'size' => [1.05, 0.72, 0.024], 'material' => ['color' => '0xe9dfc9', 'roughness' => 0.94, 'metalness' => 0.0], 'merge' => 'salon-field', 'tier_floor' => 'low'],
                        ['id' => 'door-knob', 'primitive' => 'sphere', 'at' => ['from' => 'wall_back', 'offset' => [0.33, 1.08, 0.035]], 'size' => [0.044, 0.044, 0.044], 'material' => 'bronze', 'tier_floor' => 'low'],
                        ['id' => 'bench-top', 'primitive' => 'box', 'at' => ['from' => 'wall_front', 'offset' => [0, 0.34, 0.85]], 'size' => [1.7, 0.1, 0.46], 'material' => 'wood_warm', 'collide' => true, 'merge' => 'salon-bench', 'tier_floor' => 'low'],
                        ['id' => 'bench-leg-l', 'primitive' => 'box', 'at' => ['from' => 'wall_front', 'offset' => [-0.72, 0.145, 0.85]], 'size' => [0.1, 0.29, 0.42], 'material' => 'wood_dark', 'merge' => 'salon-trim', 'tier_floor' => 'low'],
                        ['id' => 'bench-leg-r', 'primitive' => 'box', 'at' => ['from' => 'wall_front', 'offset' => [0.72, 0.145, 0.85]], 'size' => [0.1, 0.29, 0.42], 'material' => 'wood_dark', 'merge' => 'salon-trim', 'tier_floor' => 'low'],
                        ['id' => 'rug', 'primitive' => 'plane', 'at' => ['from' => 'center', 'offset' => [2.2, 0.012, 2.2]], 'rot' => [-1.5707963, 0, 0], 'size' => [2.6, 2.6], 'material' => ['color' => '0x74604a', 'roughness' => 1.0, 'metalness' => 0.0], 'tier_floor' => 'low'],
                        ['id' => 'chair-a-seat', 'primitive' => 'box', 'at' => ['from' => 'center', 'offset' => [2.75, 0.275, 1.75]], 'rot' => [0, -0.785398, 0], 'size' => [0.7, 0.35, 0.7], 'material' => ['color' => '0x6a5a48', 'roughness' => 1.0, 'metalness' => 0.0], 'collide' => true, 'merge' => 'salon-chair-a', 'tier_floor' => 'low'],
                        ['id' => 'chair-a-back', 'primitive' => 'box', 'at' => ['from' => 'center', 'offset' => [3.0, 0.7, 1.5]], 'rot' => [0, -0.785398, 0], 'size' => [0.7, 0.6, 0.14], 'material' => ['color' => '0x6a5a48', 'roughness' => 1.0, 'metalness' => 0.0], 'merge' => 'salon-chair-a', 'tier_floor' => 'low'],
                        ['id' => 'chair-a-arm-l', 'primitive' => 'box', 'at' => ['from' => 'center', 'offset' => [2.5, 0.55, 1.5]], 'rot' => [0, -0.785398, 0], 'size' => [0.12, 0.3, 0.62], 'material' => ['color' => '0x6a5a48', 'roughness' => 1.0, 'metalness' => 0.0], 'merge' => 'salon-chair-a', 'tier_floor' => 'low'],
                        ['id' => 'chair-a-arm-r', 'primitive' => 'box', 'at' => ['from' => 'center', 'offset' => [3.0, 0.55, 2.0]], 'rot' => [0, -0.785398, 0], 'size' => [0.12, 0.3, 0.62], 'material' => ['color' => '0x6a5a48', 'roughness' => 1.0, 'metalness' => 0.0], 'merge' => 'salon-chair-a', 'tier_floor' => 'low'],
                        ['id' => 'chair-b-seat', 'primitive' => 'box', 'at' => ['from' => 'center', 'offset' => [1.75, 0.275, 2.75]], 'rot' => [0, 2.356194, 0], 'size' => [0.7, 0.35, 0.7], 'material' => ['color' => '0x5f5344', 'roughness' => 1.0, 'metalness' => 0.0], 'collide' => true, 'merge' => 'salon-chair-b', 'tier_floor' => 'low'],
                        ['id' => 'chair-b-back', 'primitive' => 'box', 'at' => ['from' => 'center', 'offset' => [1.5, 0.7, 3.0]], 'rot' => [0, 2.356194, 0], 'size' => [0.7, 0.6, 0.14], 'material' => ['color' => '0x5f5344', 'roughness' => 1.0, 'metalness' => 0.0], 'merge' => 'salon-chair-b', 'tier_floor' => 'low'],
                        ['id' => 'chair-b-arm-l', 'primitive' => 'box', 'at' => ['from' => 'center', 'offset' => [1.5, 0.55, 2.5]], 'rot' => [0, 2.356194, 0], 'size' => [0.12, 0.3, 0.62], 'material' => ['color' => '0x5f5344', 'roughness' => 1.0, 'metalness' => 0.0], 'merge' => 'salon-chair-b', 'tier_floor' => 'low'],
                        ['id' => 'chair-b-arm-r', 'primitive' => 'box', 'at' => ['from' => 'center', 'offset' => [2.0, 0.55, 3.0]], 'rot' => [0, 2.356194, 0], 'size' => [0.12, 0.3, 0.62], 'material' => ['color' => '0x5f5344', 'roughness' => 1.0, 'metalness' => 0.0], 'merge' => 'salon-chair-b', 'tier_floor' => 'low'],
                        ['id' => 'table-top', 'primitive' => 'cylinder', 'at' => ['from' => 'center', 'offset' => [2.25, 0.52, 2.25]], 'size' => [0.64, 0.05], 'params' => ['seg' => 20], 'material' => 'wood_warm', 'collide' => true, 'merge' => 'salon-table', 'tier_floor' => 'low'],
                        ['id' => 'table-stem', 'primitive' => 'cylinder', 'at' => ['from' => 'center', 'offset' => [2.25, 0.26, 2.25]], 'size' => [0.07, 0.46], 'params' => ['seg' => 10], 'material' => 'wood_dark', 'merge' => 'salon-table', 'tier_floor' => 'low'],
                        ['id' => 'table-base', 'primitive' => 'cylinder', 'at' => ['from' => 'center', 'offset' => [2.25, 0.015, 2.25]], 'size' => [0.36, 0.03], 'params' => ['seg' => 16], 'material' => 'wood_dark', 'merge' => 'salon-table', 'tier_floor' => 'low'],
                        ['id' => 'rose-disc', 'primitive' => 'cylinder', 'at' => ['from' => 'center', 'offset' => [0, 3.78, 0]], 'size' => [1.0, 0.04], 'params' => ['seg' => 24], 'material' => ['color' => '0xd0c3a8', 'roughness' => 0.9, 'metalness' => 0.0], 'tier_floor' => 'low'],
                        ['id' => 'rose-ring', 'primitive' => 'torus', 'at' => ['from' => 'center', 'offset' => [0, 3.755, 0]], 'rot' => [1.5707963, 0, 0], 'size' => [0.5, 0.05], 'params' => ['seg' => 24, 'seg2' => 32], 'material' => 'bronze', 'tier_floor' => 'low'],
                        ['id' => 'rose-glow', 'primitive' => 'cylinder', 'at' => ['from' => 'center', 'offset' => [0, 3.768, 0]], 'size' => [0.32, 0.02], 'params' => ['seg' => 20], 'material' => ['color' => '0xfff1dc', 'emissive' => '0xffe2b8', 'emissiveIntensity' => 0.9], 'tier_floor' => 'low']
        ];
    }

    private function v2Placement(): array
    {
        return [
                        'density'          => 'intimate',   // salon-close ~2.8 m rhythm
                        'pair_orientation' => true,         // portrait/landscape interleave
                        'focal_wall'       => 'front',      // the hero wall the arrival faces
                        'wall_length_cap'  => 12.6,   // the room stays domestic at any count
                        'salon_rows'       => 2,            // the two-line salon hang when the cap bites
                        'upper_row_y'      => 2.98,         // upper hang centre (eye = 1.6)
                        'keep_clear'       => ['wall' => 'back', 'width' => 1.05, 'max_width' => 1.6],
                        'row_caps'         => [
                            ['maxWidth' => 2.4, 'maxHeight' => 1.45],   // eye line — large works
                            ['maxWidth' => 1.7, 'maxHeight' => 0.84],   // upper line — smaller works
                        ],
                    ];
    }

    private function v2PostFx(): array
    {
        return ['bloom' => false, 'vignette' => true, 'vignette_darkness' => 0.38, 'vignette_offset' => 1.1, 'vignette_blend' => 'black'];
    }

    private function v2Material(): array
    {
        return [
            'wall_color' => '0xd9cbaf',
            'wall_roughness' => 0.92,
            'wall_metalness' => 0.0,
            'wall_normal_strength' => 0.35,
            'floor_color' => '0x7a5c38',
            'floor_roughness' => 0.5,
            'floor_metalness' => 0.02,
            'floor_normal_strength' => 0.55,
            'floor_tile_meters' => 2.4,
            'texture_tint' => true
        ];
    }

    public function up(): void
    {
        $row = DB::table('venue_templates')
            ->where('slug', 'the-salon')
            ->first(['id', 'visual_config', 'material_config', 'default_settings', 'description', 'version']);
        if (!$row) {
            return; // venue removed by the operator — respect that
        }

        $vc = json_decode((string) $row->visual_config, true) ?: [];

        $scalarRewrites = [
            'wall_height'            => ['from' => 3.0, 'to' => 3.8],
            'ceiling_color'          => ['from' => '0x2b241a', 'to' => '0xd8cbb0'],
            'ceiling_height'         => ['from' => 3.0, 'to' => 3.8],
            'background_color'       => ['from' => '0x1d1712', 'to' => '0x171310'],
            'fog_color'              => ['from' => '0x1d1712', 'to' => '0x171310'],
            'fog_near'               => ['from' => 10, 'to' => 22],
            'fog_far'                => ['from' => 32, 'to' => 70],
            'ambient_color'          => ['from' => '0xffdcae', 'to' => '0xffe9cf'],
            'ambient_intensity'      => ['from' => 0.26, 'to' => 0.5],
            'spot_intensity'         => ['from' => 0.5, 'to' => 1.5],
            'fill_intensity'         => ['from' => 0.16, 'to' => 0.8],
            'tone_mapping_exposure'  => ['from' => 0.6, 'to' => 1.0],
        ];
        foreach ($scalarRewrites as $key => ['from' => $from, 'to' => $to]) {
            if (isset($vc[$key]) && $this->guardedEquals($vc[$key], $from)) {
                $vc[$key] = $to;
            }
        }

        if (!isset($vc['frame_override']) || $vc['frame_override'] === null) {
            $vc['frame_override'] = 'classic';
        }

        // Block rewrites — wholesale, under deep-equals guards.
        if (isset($vc['placement']) && $this->blockEquals($vc['placement'], $this->v1Placement())) {
            $vc['placement'] = $this->v2Placement();
        }
        $structureMatchesV1 = isset($vc['structure']) && (
            $this->blockEquals($vc['structure'], $this->v1Structure(false)) ||
            $this->blockEquals($vc['structure'], $this->v1Structure(true))
        );
        if ($structureMatchesV1) {
            $vc['structure'] = $this->v2Structure();
        }

        // New identity keys — added ONLY while absent (admin edits win).
        $vcAdds = [
            'environment'             => 'studio',
            'env_intensity'           => 0.18,
            'hemisphere_intensity'    => 0.22,
            'hemisphere_sky_color'    => '0xfff1dc',
            'hemisphere_ground_color' => '0x4a3f33',
            'artwork_light_base'      => 0.3,
            'artwork_light_pool_cap'  => 12,
            'post_fx'                 => $this->v2PostFx(),
        ];
        foreach ($vcAdds as $key => $value) {
            if (!array_key_exists($key, $vc)) {
                $vc[$key] = $value;
            }
        }

        DB::table('venue_templates')->where('id', $row->id)->update([
            'visual_config' => json_encode($vc),
        ]);

        // ── material_config (texture authority + the tactile layer) ─────
        if ($row->material_config) {
            $mc = json_decode((string) $row->material_config, true) ?: [];
            $mcRewrites = [
                'wall_color'            => ['from' => '0xe6dcc6', 'to' => '0xd9cbaf'],
                'floor_color'           => ['from' => '0x6b5236', 'to' => '0x7a5c38'],
                'floor_roughness'       => ['from' => 0.65, 'to' => 0.5],
                'floor_metalness'       => ['from' => 0.0, 'to' => 0.02],
            ];
            foreach ($mcRewrites as $key => ['from' => $from, 'to' => $to]) {
                if (isset($mc[$key]) && $this->guardedEquals($mc[$key], $from)) {
                    $mc[$key] = $to;
                }
            }
            foreach (['floor_tile_meters' => 2.4, 'texture_tint' => true] as $key => $value) {
                if (!array_key_exists($key, $mc)) {
                    $mc[$key] = $value;
                }
            }
            DB::table('venue_templates')->where('id', $row->id)->update([
                'material_config' => json_encode($mc),
            ]);
        }

        // ── default_settings (plaster walls, classic frames) ─────────────
        if ($row->default_settings) {
            $ds = json_decode((string) $row->default_settings, true) ?: [];
            if (($ds['wall_texture'] ?? null) === 'white')   $ds['wall_texture'] = 'plaster';
            if (($ds['frame_style'] ?? null) === 'minimal')  $ds['frame_style'] = 'classic';
            DB::table('venue_templates')->where('id', $row->id)->update([
                'default_settings' => json_encode($ds),
            ]);
        }

        DB::table('venue_templates')->where('id', $row->id)->update([
            'description' => ((string) $row->description === $this->v1Description())
                ? $this->v2Description()
                : $row->description,
            'version' => ((string) $row->version === '1.0.0') ? '2.0.0' : $row->version,
        ]);
    }

    public function down(): void
    {
        $row = DB::table('venue_templates')
            ->where('slug', 'the-salon')
            ->first(['id', 'visual_config', 'material_config', 'default_settings', 'description', 'version']);
        if (!$row) {
            return;
        }

        // ── visual_config: reverse every rewrite the v2 row still carries ─
        $vc = json_decode((string) $row->visual_config, true) ?: [];

        $vcScalarRestores = [
            'wall_height'            => ['from' => 3.8, 'to' => 3.0],
            'ceiling_color'          => ['from' => '0xd8cbb0', 'to' => '0x2b241a'],
            'ceiling_height'         => ['from' => 3.8, 'to' => 3.0],
            'background_color'       => ['from' => '0x171310', 'to' => '0x1d1712'],
            'fog_color'              => ['from' => '0x171310', 'to' => '0x1d1712'],
            'fog_near'               => ['from' => 22, 'to' => 10],
            'fog_far'                => ['from' => 70, 'to' => 32],
            'ambient_color'          => ['from' => '0xffe9cf', 'to' => '0xffdcae'],
            'ambient_intensity'      => ['from' => 0.5, 'to' => 0.26],
            'spot_intensity'         => ['from' => 1.5, 'to' => 0.5],
            'fill_intensity'         => ['from' => 0.8, 'to' => 0.16],
            'tone_mapping_exposure'  => ['from' => 1.0, 'to' => 0.6],
        ];
        foreach ($vcScalarRestores as $key => ['from' => $from, 'to' => $to]) {
            if (isset($vc[$key]) && $this->guardedEquals($vc[$key], $from)) {
                $vc[$key] = $to;
            }
        }

        if (($vc['frame_override'] ?? null) === 'classic') {
            $vc['frame_override'] = null;
        }

        if (isset($vc['placement']) && $this->blockEquals($vc['placement'], $this->v2Placement())) {
            $vc['placement'] = $this->v1Placement();
        }
        if (isset($vc['structure']) && $this->blockEquals($vc['structure'], $this->v2Structure())) {
            $vc['structure'] = $this->v1Structure(false);
        }

        $vcValueRemoves = [
            'environment'             => 'studio',
            'env_intensity'           => 0.18,
            'hemisphere_intensity'    => 0.22,
            'hemisphere_sky_color'    => '0xfff1dc',
            'hemisphere_ground_color' => '0x4a3f33',
            'artwork_light_base'      => 0.3,
            'artwork_light_pool_cap'  => 12,
        ];
        foreach ($vcValueRemoves as $key => $v2) {
            if (array_key_exists($key, $vc) && $this->guardedEquals($vc[$key], $v2)) {
                unset($vc[$key]);
            }
        }
        if (array_key_exists('post_fx', $vc) && $this->blockEquals($vc['post_fx'], $this->v2PostFx())) {
            unset($vc['post_fx']);
        }

        DB::table('venue_templates')->where('id', $row->id)->update([
            'visual_config' => json_encode($vc),
        ]);

        if ($row->material_config) {
            $mc = json_decode((string) $row->material_config, true) ?: [];
            $mcRestores = [
                'wall_color'      => ['from' => '0xd9cbaf', 'to' => '0xe6dcc6'],
                'floor_color'     => ['from' => '0x7a5c38', 'to' => '0x6b5236'],
                'floor_roughness' => ['from' => 0.5, 'to' => 0.65],
                'floor_metalness' => ['from' => 0.02, 'to' => 0.0],
            ];
            foreach ($mcRestores as $key => ['from' => $from, 'to' => $to]) {
                if (isset($mc[$key]) && $this->guardedEquals($mc[$key], $from)) {
                    $mc[$key] = $to;
                }
            }
            if (($mc['floor_tile_meters'] ?? null) == 2.4)  unset($mc['floor_tile_meters']);
            if (($mc['texture_tint'] ?? null) === true)     unset($mc['texture_tint']);
            DB::table('venue_templates')->where('id', $row->id)->update([
                'material_config' => json_encode($mc),
            ]);
        }

        if ($row->default_settings) {
            $ds = json_decode((string) $row->default_settings, true) ?: [];
            if (($ds['wall_texture'] ?? null) === 'plaster') $ds['wall_texture'] = 'white';
            if (($ds['frame_style'] ?? null) === 'classic')  $ds['frame_style'] = 'minimal';
            DB::table('venue_templates')->where('id', $row->id)->update([
                'default_settings' => json_encode($ds),
            ]);
        }

        DB::table('venue_templates')->where('id', $row->id)->update([
            'description' => ((string) $row->description === $this->v2Description())
                ? $this->v1Description()
                : $row->description,
            'version' => ((string) $row->version === '2.0.0') ? '1.0.0' : $row->version,
        ]);
    }
};
