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

    public function up(): void
    {
        $row = DB::table('venue_templates')
            ->where('slug', 'dark-museum')
            ->first(['id', 'visual_config', 'material_config', 'description', 'version']);
        if (!$row) {
            return; // venue removed by the operator — respect that
        }

        $vc = json_decode((string) $row->visual_config, true) ?: [];

        $vcRewrites = [
            'ceiling_color'         => ['from' => '0x080808', 'to' => '0x0a0a0a'],
            'background_color'      => ['from' => '0x020202', 'to' => '0x050505'],
            'fog_color'             => ['from' => '0x020202', 'to' => '0x050505'],
            'fog_near'              => ['from' => 5,          'to' => 12],
            'fog_far'               => ['from' => 18,         'to' => 70],
            'ambient_color'         => ['from' => '0xfff4e6', 'to' => '0xffe8c8'],
            'ambient_intensity'     => ['from' => 0.15,       'to' => 3.2],
            'spot_intensity'        => ['from' => 0.55,       'to' => 1.9],
            'fill_intensity'        => ['from' => 0.08,       'to' => 0.5],
            'tone_mapping_exposure' => ['from' => 0.5,        'to' => 0.8],
        ];
        foreach ($vcRewrites as $key => ['from' => $from, 'to' => $to]) {
            if ($this->guardedEquals($vc[$key] ?? null, $from)) {
                $vc[$key] = $to;
            }
        }

        // Key adds — only when absent (an admin's declared value wins).
        if (!array_key_exists('post_fx', $vc)) {
            $vc['post_fx'] = [
                'bloom'             => false,
                'vignette'          => true,
                'vignette_blend'    => 'black',
                'vignette_darkness' => 0.5,
                'vignette_offset'   => 1.15,
            ];
        } elseif (is_array($vc['post_fx']) && !array_key_exists('vignette_blend', $vc['post_fx'])) {
            $vc['post_fx']['vignette_blend'] = 'black';
        }
        if (!array_key_exists('artwork_light_base', $vc)) {
            $vc['artwork_light_base'] = 0.32;
        }
        if (!array_key_exists('artwork_light_pool_cap', $vc)) {
            $vc['artwork_light_pool_cap'] = 14;
        }
        if (!array_key_exists('env_intensity', $vc)) {
            $vc['env_intensity'] = 0.14;
        }
        if (!array_key_exists('hemisphere_intensity', $vc)) {
            $vc['hemisphere_intensity'] = 0.04;
        }
        if (!array_key_exists('placement', $vc)) {
            $vc['placement'] = [
                'density'          => 'generous',
                'focal_wall'       => 'front',
                'pair_orientation' => true,
            ];
        }

        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update(['visual_config' => json_encode($vc)]);

        $mc = json_decode((string) $row->material_config, true) ?: [];

        if ($this->guardedEquals($mc['wall_color'] ?? null, '0x1a1a1a')) {
            $mc['wall_color'] = '0x7a746c';
        }
        if ($this->guardedEquals($mc['wall_roughness'] ?? null, 0.85)) {
            $mc['wall_roughness'] = 0.92;
        }
        if ($this->guardedEquals($mc['wall_normal_strength'] ?? null, 0.6)) {
            $mc['wall_normal_strength'] = 0.5;
        }
        if (!array_key_exists('floor_color', $mc) || $mc['floor_color'] === null) {
            $mc['floor_color'] = '0x3a3835';
        }
        if ($this->guardedEquals($mc['floor_metalness'] ?? null, 0.2)) {
            $mc['floor_metalness'] = 0.15;
        }
        if (!array_key_exists('texture_tint', $mc)) {
            $mc['texture_tint'] = true;   // THE fix — declared colours become
                                          // authoritative over the PBR sets
        }
        if (!array_key_exists('floor_tile_meters', $mc)) {
            $mc['floor_tile_meters'] = 3.0;
        }
        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update(['material_config' => json_encode($mc)]);

        // ── description (customer-facing truth) ──────────────────────────
        $v1Description = 'Dramatic lighting with black walls. Premium artwork presentation with gold-leaf frames.';
        $v2Description = 'A night-lit institution: charcoal galleries under a shadow-gap black ceiling, brass picture lights over every work, polished dark stone below. The architecture recedes; the artwork glows.';
        if ((string) $row->description === $v1Description) {
            DB::table('venue_templates')
                ->where('id', $row->id)
                ->update(['description' => $v2Description]);
        }

        if ($this->guardedEquals($row->version, '1.0.0')) {
            DB::table('venue_templates')->where('id', $row->id)->update(['version' => '2.0.0']);
        }
    }

    public function down(): void
    {
        $row = DB::table('venue_templates')
            ->where('slug', 'dark-museum')
            ->first(['id', 'visual_config', 'material_config', 'description', 'version']);
        if (!$row) {
            return;
        }

        $vc = json_decode((string) $row->visual_config, true) ?: [];
        $vcRewrites = [
            'ceiling_color'         => ['from' => '0x0a0a0a', 'to' => '0x080808'],
            'background_color'      => ['from' => '0x050505', 'to' => '0x020202'],
            'fog_color'             => ['from' => '0x050505', 'to' => '0x020202'],
            'fog_near'              => ['from' => 12,         'to' => 5],
            'fog_far'               => ['from' => 70,         'to' => 18],
            'ambient_color'         => ['from' => '0xffe8c8', 'to' => '0xfff4e6'],
            'ambient_intensity'     => ['from' => 3.2,        'to' => 0.15],
            'spot_intensity'        => ['from' => 1.9,        'to' => 0.55],
            'fill_intensity'        => ['from' => 0.5,        'to' => 0.08],
            'tone_mapping_exposure' => ['from' => 0.8,        'to' => 0.5],
        ];
        foreach ($vcRewrites as $key => ['from' => $from, 'to' => $to]) {
            if ($this->guardedEquals($vc[$key] ?? null, $from)) {
                $vc[$key] = $to;
            }
        }
        // Remove the added keys only while they still equal what up() wrote.
        $seededPostFx = [
            'bloom'             => false,
            'vignette'          => true,
            'vignette_blend'    => 'black',
            'vignette_darkness' => 0.5,
            'vignette_offset'   => 1.15,
        ];
        if (($vc['post_fx'] ?? null) === $seededPostFx) {
            unset($vc['post_fx']);
        } elseif (is_array($vc['post_fx'])
            && ($vc['post_fx']['vignette_blend'] ?? null) === 'black'
            && count($vc['post_fx']) === 1) {
            unset($vc['post_fx']['vignette_blend']);
        }
        foreach ([
            'artwork_light_base'     => 0.32,
            'artwork_light_pool_cap' => 14,
            'env_intensity'          => 0.14,
            'hemisphere_intensity'   => 0.04,
        ] as $key => $seeded) {
            if (($vc[$key] ?? null) === $seeded) {
                unset($vc[$key]);
            }
        }
        if (($vc['placement'] ?? null) === [
            'density'          => 'generous',
            'focal_wall'       => 'front',
            'pair_orientation' => true,
        ]) {
            unset($vc['placement']);
        }
        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update(['visual_config' => json_encode($vc)]);

        $mc = json_decode((string) $row->material_config, true) ?: [];
        if ($this->guardedEquals($mc['wall_color'] ?? null, '0x7a746c')) {
            $mc['wall_color'] = '0x1a1a1a';
        }
        if ($this->guardedEquals($mc['wall_roughness'] ?? null, 0.92)) {
            $mc['wall_roughness'] = 0.85;
        }
        if ($this->guardedEquals($mc['wall_normal_strength'] ?? null, 0.5)) {
            $mc['wall_normal_strength'] = 0.6;
        }
        if ($this->guardedEquals($mc['floor_color'] ?? null, '0x3a3835')) {
            $mc['floor_color'] = null;
        }
        if ($this->guardedEquals($mc['floor_metalness'] ?? null, 0.15)) {
            $mc['floor_metalness'] = 0.2;
        }
        if (($mc['texture_tint'] ?? null) === true) {
            unset($mc['texture_tint']);
        }
        if (($mc['floor_tile_meters'] ?? null) === 3.0 || ($mc['floor_tile_meters'] ?? null) === 3) {
            unset($mc['floor_tile_meters']);
        }
        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update(['material_config' => json_encode($mc)]);

        $v1Description = 'Dramatic lighting with black walls. Premium artwork presentation with gold-leaf frames.';
        $v2Description = 'A night-lit institution: charcoal galleries under a shadow-gap black ceiling, brass picture lights over every work, polished dark stone below. The architecture recedes; the artwork glows.';
        if ((string) $row->description === $v2Description) {
            DB::table('venue_templates')
                ->where('id', $row->id)
                ->update(['description' => $v1Description]);
        }

        // ── version (reversible under the same guard) ────────────────────
        if ($this->guardedEquals($row->version, '2.0.0')) {
            DB::table('venue_templates')->where('id', $row->id)->update(['version' => '1.0.0']);
        }
    }
};
