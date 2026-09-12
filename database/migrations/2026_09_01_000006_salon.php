<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function salonTemplate(): array
    {
        return [
            'name'          => 'The Salon',
            'slug'          => 'the-salon',
            'description'   => 'A small, warm room in the domestic tradition: works hung close together at conversational distance, a wooden picture rail and a bench, under soft warm light. Made for studies, prints, photography and portrait formats.',
            'category'      => 'classic',
            'tags'          => ['salon', 'warm', 'intimate', 'portrait'],
            'plan_required' => 'pro',
            'capacity_min'  => 5,
            'capacity_max'  => 30,
            'sort_order'    => 12,
            'is_featured'   => false,
            'version'       => '1.0.0',
            'default_settings' => [
                'wall_texture'    => 'white',
                'floor_material'  => 'wood',
                'lighting_preset'  => 'bright',
                'frame_style'     => 'minimal',
                'room_layout'     => 'square',
            ],
            'visual_config' => [
                'wall_height'            => 3.0,
                'wall_depth'             => 0.15,
                'ceiling_type'           => 'flat',
                'ceiling_color'          => '0x2b241a',
                'ceiling_height'         => 3.0,
                'background_color'       => '0x1d1712',
                'fog_color'              => '0x1d1712',
                'fog_near'               => 10,
                'fog_far'                => 32,
                'ambient_color'          => '0xffdcae',
                'ambient_intensity'      => 0.26,
                'spot_intensity'         => 0.5,
                'fill_intensity'         => 0.16,
                'tone_mapping_exposure'  => 0.6,
                'frame_override'         => null,
                'structure_pass'        => 'rooms',
                'placement'             => [
                    'density'          => 'intimate',  // §6.3 — ~2.8 m salon-close rhythm
                    'pair_orientation' => true,        // §6.4 — portrait/landscape interleave
                ],
                'structure'              => [
                ['id' => 'rail-front', 'primitive' => 'box', 'at' => ['from' => 'wall_front', 'offset' => [0, 0.9, 0.045]], 'size' => [1, 0.07, 0.09], 'fit' => 'wall', 'fit_pad' => 0.3, 'material' => 'wood_dark', 'merge' => 'salon-rail', 'tier_floor' => 'low'],
                ['id' => 'rail-back',  'primitive' => 'box', 'at' => ['from' => 'wall_back',  'offset' => [0, 0.9, 0.045]], 'size' => [1, 0.07, 0.09], 'fit' => 'wall', 'fit_pad' => 0.3, 'material' => 'wood_dark', 'merge' => 'salon-rail', 'tier_floor' => 'low'],
                ['id' => 'rail-left',  'primitive' => 'box', 'at' => ['from' => 'wall_left',  'offset' => [0, 0.9, 0.045]], 'size' => [1, 0.07, 0.09], 'fit' => 'wall', 'fit_pad' => 0.3, 'material' => 'wood_dark', 'merge' => 'salon-rail', 'tier_floor' => 'low'],
                ['id' => 'rail-right', 'primitive' => 'box', 'at' => ['from' => 'wall_right', 'offset' => [0, 0.9, 0.045]], 'size' => [1, 0.07, 0.09], 'fit' => 'wall', 'fit_pad' => 0.3, 'material' => 'wood_dark', 'merge' => 'salon-rail', 'tier_floor' => 'low'],
                ['id' => 'bench-top',  'primitive' => 'box', 'at' => ['from' => 'center', 'offset' => [0, 0.42, 1.4]], 'size' => [1.5, 0.09, 0.42], 'material' => 'wood_warm', 'collide' => true, 'merge' => 'salon-bench', 'tier_floor' => 'low'],
                ['id' => 'bench-leg-l', 'primitive' => 'box', 'at' => ['from' => 'center', 'offset' => [-0.65, 0.19, 1.4]], 'size' => [0.09, 0.38, 0.38], 'material' => 'wood_dark', 'merge' => 'salon-bench', 'tier_floor' => 'low'],
                ['id' => 'bench-leg-r', 'primitive' => 'box', 'at' => ['from' => 'center', 'offset' => [0.65, 0.19, 1.4]], 'size' => [0.09, 0.38, 0.38], 'material' => 'wood_dark', 'merge' => 'salon-bench', 'tier_floor' => 'low'],
                ['id' => 'rug', 'primitive' => 'plane', 'at' => ['from' => 'center', 'offset' => [0, 0.012, 1.4]], 'rot' => [-1.5707963, 0, 0], 'size' => [2.6, 1.8], 'material' => 'fabric_warm', 'tier_floor' => 'low'],
            ],
            ],
            'material_config' => [
                'wall_color'             => '0xe6dcc6',
                'wall_roughness'         => 0.92,
                'wall_metalness'         => 0.0,
                'wall_normal_strength'   => 0.35,
                'floor_color'            => '0x6b5236',
                'floor_roughness'        => 0.65,
                'floor_metalness'        => 0.0,
                'floor_normal_strength'  => 0.55,
            ],
            'decorations'       => [],  // rail + bench + rug are descriptors (StructureBuilder)
            'lighting_fixtures' => [],
            'supported_layouts' => ['square'],
        ];
    }

    public function up(): void
    {
        // GUARDED INSERT — never overwrites, never re-seeds (§15.13).
        if (DB::table('venue_templates')->where('slug', 'the-salon')->exists()) {
            return;
        }

        $t     = $this->salonTemplate();
        $now   = now();

        DB::table('venue_templates')->insert([
            'name'             => $t['name'],
            'slug'             => $t['slug'],
            'description'      => $t['description'],
            'category'         => $t['category'],
            'tags'             => json_encode($t['tags']),
            'plan_required'    => $t['plan_required'],
            'capacity_min'     => $t['capacity_min'],
            'capacity_max'     => $t['capacity_max'],
            'sort_order'       => $t['sort_order'],
            'is_featured'      => $t['is_featured'],
            'is_active'        => true,
            'is_draft'         => false,
            'version'          => $t['version'],
            'default_settings' => json_encode($t['default_settings']),
            'visual_config'    => json_encode($t['visual_config']),
            'material_config'  => json_encode($t['material_config']),
            'decorations'      => json_encode($t['decorations']),
            'lighting_fixtures'=> json_encode($t['lighting_fixtures']),
            'supported_layouts'=> json_encode($t['supported_layouts']),
            'view_count'       => 0,
            'published_at'     => $now,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);
    }


    private function jsonEquals($a, $b): bool
    {
        if (is_array($a) || is_array($b)) {
            if (!is_array($a) || !is_array($b) || count($a) !== count($b)) {
                return false;
            }
            foreach ($b as $k => $v) {
                if (!array_key_exists($k, $a) || !$this->jsonEquals($a[$k], $v)) {
                    return false;
                }
            }
            return true;
        }
        if (is_string($a) || is_string($b)) {
            return (string) $a === (string) $b;
        }
        if (is_bool($a) || is_bool($b)) {
            return $a === $b;
        }
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a === (float) $b;
        }
        return $a === $b;
    }

    public function down(): void
    {
        $row = DB::table('venue_templates')->where('slug', 'the-salon')->first();
        if (!$row) {
            return;
        }

        $inUse = DB::table('galleries')->where('venue_template_id', $row->id)->exists();
        if ($inUse) {
            return;
        }

        $t = $this->salonTemplate();
        foreach (['tags', 'default_settings', 'visual_config', 'material_config', 'decorations', 'lighting_fixtures', 'supported_layouts'] as $col) {
            if (!$this->jsonEquals(json_decode((string) $row->{$col}, true), $t[$col])) {
                return;
            }
        }
        if ((string) $row->name !== $t['name']
            || (string) $row->description !== $t['description']
            || (string) $row->category !== $t['category']
            || (string) $row->plan_required !== $t['plan_required']
            || (int) $row->capacity_min !== $t['capacity_min']
            || (int) $row->capacity_max !== $t['capacity_max']
            || (int) $row->sort_order !== $t['sort_order']) {
            return;
        }

        DB::table('venue_templates')->where('id', $row->id)->delete();
    }
};
