<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private function guardedEquals($current, $from): bool
    {
        if ($current === null) {
            return false;
        }
        if (is_string($from)) {
            return is_string($current) && $current === $from;
        }
        return is_numeric($current) && (float) $current === (float) $from;
    }

    private function v1Description(): string
    {
        return 'A still, dark lake reflects the floating artworks and the moon. Mist drifts low. Quiet, spacious, meditative.';
    }

    private function v3Description(): string
    {
        return 'A gallery at dusk on the shore of a still lake. Works hover above the calm water along the shore, doubled by their reflection. Arrive on the stone landing, follow the shoreline walk, cross the timber pier to the lantern-lit viewing pavilion, and look back as the far shore fades into mist under a rising moon.';
    }

    private function v3LakeBlock(): array
    {
        return [
            'sky_environment' => true,
            'assets_base'     => '/assets/venues/mirror-lake/',
            'assets'          => [
                'tree_large'  => 'tree_large_01.glb',
                'tree_medium' => 'tree_medium_01.glb',
                'tree_accent' => 'tree_medium_02.glb',
                'shrub'       => 'shrub_01.glb',
                'grass'       => 'grass_clump_01.glb',
                'boulder'     => 'boulder_01.glb',
                'bench'       => 'bench_01.glb',
            ],
        ];
    }

    private function v3PostFx(): array
    {
        return [
            'bloom'             => false,
            'vignette'          => true,
            'vignette_darkness' => 0.5,
            'vignette_offset'   => 1.15,
            'vignette_blend'    => 'black',
        ];
    }

    public function up(): void
    {
        $row = DB::table('venue_templates')
            ->where('slug', 'mirror-lake')
            ->first(['id', 'visual_config', 'material_config', 'lighting_fixtures', 'tags', 'description', 'version']);
        if (!$row) {
            return; // venue removed by the operator — respect that
        }

        $vc = json_decode((string) $row->visual_config, true) ?: [];

        $vcRewrites = [
            'background_color'      => ['from' => '0x0a0a18', 'to' => '0x0f1726'],
            'fog_color'             => ['from' => '0x0a0a18', 'to' => '0x0f1726'],
            'fog_near'              => ['from' => 15, 'to' => 20],
            'fog_far'               => ['from' => 45, 'to' => 64],
            'ambient_color'         => ['from' => '0xb0c8ff', 'to' => '0x93a8c8'],
            'ambient_intensity'     => ['from' => 0.18, 'to' => 0.26],
            'spot_intensity'        => ['from' => 0.5, 'to' => 0.4],
            'tone_mapping_exposure' => ['from' => 0.55, 'to' => 1.15],
            'placement_mode'        => ['from' => 'float', 'to' => 'lake'],
            'env_intensity'         => ['from' => 0.15, 'to' => 0.14],
            'structure_pass'        => ['from' => 'phenomena', 'to' => 'lake'],
        ];
        foreach ($vcRewrites as $key => ['from' => $from, 'to' => $to]) {
            if ($this->guardedEquals($vc[$key] ?? null, $from)) {
                $vc[$key] = $to;
            }
        }

        $vcAdds = [
            'hemisphere_intensity'    => 0.45,
            'hemisphere_sky_color'    => '0x3d5680',
            'hemisphere_ground_color' => '0x0c0f14',
            'ceiling_fill_light'      => false,
            'field_radius_bonus'      => 4,
            'field_radius_min'        => 17,
            'artwork_light_base'      => 0.6,
            'artwork_light_pool_cap'  => 12,
            'placement'               => ['focal_wall' => 'lake-hero'],
            'lake'                    => $this->v3LakeBlock(),
            'post_fx'                 => $this->v3PostFx(),
        ];
        foreach ($vcAdds as $key => $value) {
            if (!array_key_exists($key, $vc)) {
                $vc[$key] = $value;
            }
        }

        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update(['visual_config' => json_encode($vc)]);

        // ── material_config (land, not mirror-metal) ─────────────────────
        if ($row->material_config) {
            $mc = json_decode((string) $row->material_config, true) ?: [];
            $mcRewrites = [
                'floor_color'           => ['from' => '0x202830', 'to' => '0x46523a'],
                'floor_roughness'       => ['from' => 0.0, 'to' => 1.0],
                'floor_metalness'       => ['from' => 1.0, 'to' => 0.0],
                'floor_normal_strength' => ['from' => 0.1, 'to' => 0.5],
            ];
            $changed = false;
            foreach ($mcRewrites as $key => ['from' => $from, 'to' => $to]) {
                if ($this->guardedEquals($mc[$key] ?? null, $from)) {
                    $mc[$key] = $to;
                    $changed = true;
                }
            }
            if (!isset($mc['floor_tile_meters'])) {
                $mc['floor_tile_meters'] = 3.0;
                $changed = true;
            }
            if ($changed) {
                DB::table('venue_templates')
                    ->where('id', $row->id)
                    ->update(['material_config' => json_encode($mc)]);
            }
        }

        // ── lighting_fixtures (the moon is plan-built now) ───────────────
        $v1Fixtures = [[
            'id'          => 'moonlight',
            'type'        => 'directional',
            'position'    => [12, 22, -8],
            'color'       => '0xb0c8ff',
            'intensity'   => 0.6,
            'cast_shadow' => false,
        ]];
        if (json_decode((string) $row->lighting_fixtures, true) === $v1Fixtures) {
            DB::table('venue_templates')
                ->where('id', $row->id)
                ->update(['lighting_fixtures' => json_encode([])]);
        }

        $v1Tags = ['mirror', 'reflection', 'moonlit', 'meditative'];
        if (json_decode((string) $row->tags, true) === $v1Tags) {
            DB::table('venue_templates')
                ->where('id', $row->id)
                ->update(['tags' => json_encode(['mirror', 'reflection', 'lakeside', 'meditative'])]);
        }

        // ── description (customer-facing truth) ──────────────────────────
        if ((string) $row->description === $this->v1Description()) {
            DB::table('venue_templates')
                ->where('id', $row->id)
                ->update(['description' => $this->v3Description()]);
        }

        if ($this->guardedEquals($row->version, '1.0.0')) {
            DB::table('venue_templates')->where('id', $row->id)->update(['version' => '3.0.0']);
        }
    }

    public function down(): void
    {
        $row = DB::table('venue_templates')
            ->where('slug', 'mirror-lake')
            ->first(['id', 'visual_config', 'material_config', 'lighting_fixtures', 'tags', 'description', 'version']);
        if (!$row) {
            return;
        }

        $vc = json_decode((string) $row->visual_config, true) ?: [];

        $vcRewrites = [
            'background_color'      => ['from' => '0x0f1726', 'to' => '0x0a0a18'],
            'fog_color'             => ['from' => '0x0f1726', 'to' => '0x0a0a18'],
            'fog_near'              => ['from' => 20, 'to' => 15],
            'fog_far'               => ['from' => 64, 'to' => 45],
            'ambient_color'         => ['from' => '0x93a8c8', 'to' => '0xb0c8ff'],
            'ambient_intensity'     => ['from' => 0.26, 'to' => 0.18],
            'spot_intensity'        => ['from' => 0.4, 'to' => 0.5],
            'tone_mapping_exposure' => ['from' => 1.15, 'to' => 0.55],
            'placement_mode'        => ['from' => 'lake', 'to' => 'float'],
            'env_intensity'         => ['from' => 0.14, 'to' => 0.15],
            'structure_pass'        => ['from' => 'lake', 'to' => 'phenomena'],
        ];
        foreach ($vcRewrites as $key => ['from' => $from, 'to' => $to]) {
            if ($this->guardedEquals($vc[$key] ?? null, $from)) {
                $vc[$key] = $to;
            }
        }

        // Remove exactly what up() added (same-value guards).
        $vcRemoves = [
            'hemisphere_intensity'    => 0.45,
            'hemisphere_sky_color'    => '0x3d5680',
            'hemisphere_ground_color' => '0x0c0f14',
            'ceiling_fill_light'      => false,
            'field_radius_bonus'      => 4,
            'field_radius_min'        => 17,
            'artwork_light_base'      => 0.6,
            'artwork_light_pool_cap'  => 12,
        ];
        foreach ($vcRemoves as $key => $written) {
            if ($this->guardedEquals($vc[$key] ?? null, $written)) {
                unset($vc[$key]);
            }
        }
        if (($vc['placement'] ?? null) === ['focal_wall' => 'lake-hero']) {
            unset($vc['placement']);
        }
        if (($vc['lake'] ?? null) === $this->v3LakeBlock()) {
            unset($vc['lake']);
        }
        if (($vc['post_fx'] ?? null) === $this->v3PostFx()) {
            unset($vc['post_fx']);
        }

        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update(['visual_config' => json_encode($vc)]);

        if ($row->material_config) {
            $mc = json_decode((string) $row->material_config, true) ?: [];
            $mcRewrites = [
                'floor_color'           => ['from' => '0x46523a', 'to' => '0x202830'],
                'floor_roughness'       => ['from' => 1.0, 'to' => 0.0],
                'floor_metalness'       => ['from' => 0.0, 'to' => 1.0],
                'floor_normal_strength' => ['from' => 0.5, 'to' => 0.1],
            ];
            $changed = false;
            foreach ($mcRewrites as $key => ['from' => $from, 'to' => $to]) {
                if ($this->guardedEquals($mc[$key] ?? null, $from)) {
                    $mc[$key] = $to;
                    $changed = true;
                }
            }
            if (($mc['floor_tile_meters'] ?? null) === 3.0 || $mc['floor_tile_meters'] === '3') {
                unset($mc['floor_tile_meters']);
                $changed = true;
            }
            if ($changed) {
                DB::table('venue_templates')
                    ->where('id', $row->id)
                    ->update(['material_config' => json_encode($mc)]);
            }
        }

        $v1Fixtures = [[
            'id'          => 'moonlight',
            'type'        => 'directional',
            'position'    => [12, 22, -8],
            'color'       => '0xb0c8ff',
            'intensity'   => 0.6,
            'cast_shadow' => false,
        ]];
        if (json_decode((string) $row->lighting_fixtures, true) === []) {
            DB::table('venue_templates')
                ->where('id', $row->id)
                ->update(['lighting_fixtures' => json_encode($v1Fixtures)]);
        }

        if (json_decode((string) $row->tags, true) === ['mirror', 'reflection', 'lakeside', 'meditative']) {
            DB::table('venue_templates')
                ->where('id', $row->id)
                ->update(['tags' => json_encode(['mirror', 'reflection', 'moonlit', 'meditative'])]);
        }

        if ((string) $row->description === $this->v3Description()) {
            DB::table('venue_templates')
                ->where('id', $row->id)
                ->update(['description' => $this->v1Description()]);
        }

        if ($this->guardedEquals($row->version, '3.0.0')) {
            DB::table('venue_templates')->where('id', $row->id)->update(['version' => '1.0.0']);
        }
    }
};
