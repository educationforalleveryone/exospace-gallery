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
            ->where('slug', 'cyber-gallery')
            ->first(['id', 'visual_config', 'material_config', 'description', 'version']);
        if (!$row) {
            return; // venue removed by the operator — respect that
        }

        $vc = json_decode((string) $row->visual_config, true) ?: [];

        $vcRewrites = [
            'fog_near'              => ['from' => 6,    'to' => 10],
            'fog_far'               => ['from' => 22,   'to' => 26],
            'ambient_intensity'     => ['from' => 0.18, 'to' => 0.42],
            'spot_intensity'        => ['from' => 0.55, 'to' => 1.6],
            'fill_intensity'        => ['from' => 0.1,  'to' => 0.4],
            'tone_mapping_exposure' => ['from' => 0.5,  'to' => 0.7],
        ];
        foreach ($vcRewrites as $key => ['from' => $from, 'to' => $to]) {
            if ($this->guardedEquals($vc[$key] ?? null, $from)) {
                $vc[$key] = $to;
            }
        }

        if (!array_key_exists('frame_override', $vc) || $vc['frame_override'] === null) {
            $vc['frame_override'] = 'black';
        }

        // Key adds — only when absent (an admin's declared value wins).
        if (!array_key_exists('environment', $vc)) {
            $vc['environment'] = 'none';
        }
        if (!array_key_exists('env_intensity', $vc)) {
            $vc['env_intensity'] = 0;
        }
        if (!array_key_exists('hemisphere_intensity', $vc)) {
            $vc['hemisphere_intensity'] = 0.05;
        }
        if (!array_key_exists('artwork_light_base', $vc)) {
            $vc['artwork_light_base'] = 0.28;
        }
        if (!array_key_exists('artwork_light_pool_cap', $vc)) {
            $vc['artwork_light_pool_cap'] = 12;
        }
        if (!array_key_exists('post_fx', $vc)) {
            $vc['post_fx'] = [
                'bloom'             => true,
                'bloom_strength'    => 0.55,
                'bloom_threshold'   => 0.8,
                'bloom_radius'      => 0.4,
                'vignette'          => true,
                'vignette_blend'    => 'black',
                'vignette_darkness' => 0.55,
                'vignette_offset'   => 1.1,
            ];
        }
        if (!array_key_exists('artwork_reactive', $vc)) {
            $vc['artwork_reactive'] = [
                'enabled'       => true,
                'dead_zone'     => 0.18,
                'ref_speed'     => 3.0,
                'attack'        => 0.18,
                'release'       => 1.1,
                'max_intensity' => 1.0,
                'bezel_color'   => '0x00e5ff',
            ];
        }

        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update(['visual_config' => json_encode($vc)]);

        $mc = json_decode((string) $row->material_config, true) ?: [];

        if (!array_key_exists('texture_tint', $mc)) {
            $mc['texture_tint'] = true;   // THE fix — declared colours become
                                          // authoritative over the PBR sets
        }
        if (!array_key_exists('floor_color', $mc) || $mc['floor_color'] === null) {
            $mc['floor_color'] = '0x0b0d14';
        }
        if ($this->guardedEquals($mc['floor_roughness'] ?? null, 0.4)) {
            $mc['floor_roughness'] = 0.35;
        }
        if ($this->guardedEquals($mc['floor_metalness'] ?? null, 0.5)) {
            $mc['floor_metalness'] = 0.55;
        }
        if (!array_key_exists('floor_tile_meters', $mc)) {
            $mc['floor_tile_meters'] = 2.0;
        }
        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update(['material_config' => json_encode($mc)]);

        // ── description (customer-facing truth) ──────────────────────────
        $v1Description = 'A dark electric space ringed with neon on every edge, the floor traced in light. For digital and web3 creators.';
        $v2Description = 'A signal room for digital natives: dark anodized walls, a floor traced in light, neon ringing every edge — and artworks that behave like living media. Stand still and they hold still. Move, and they react to you.';
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
            ->where('slug', 'cyber-gallery')
            ->first(['id', 'visual_config', 'material_config', 'description', 'version']);
        if (!$row) {
            return;
        }

        $vc = json_decode((string) $row->visual_config, true) ?: [];

        $vcRewrites = [
            'fog_near'              => ['from' => 10,   'to' => 6],
            'fog_far'               => ['from' => 26,   'to' => 22],
            'ambient_intensity'     => ['from' => 0.42, 'to' => 0.18],
            'spot_intensity'        => ['from' => 1.6,  'to' => 0.55],
            'fill_intensity'        => ['from' => 0.4,  'to' => 0.1],
            'tone_mapping_exposure' => ['from' => 0.7,  'to' => 0.5],
        ];
        foreach ($vcRewrites as $key => ['from' => $from, 'to' => $to]) {
            if ($this->guardedEquals($vc[$key] ?? null, $from)) {
                $vc[$key] = $to;
            }
        }
        if (($vc['frame_override'] ?? null) === 'black') {
            $vc['frame_override'] = null;
        }
        // Remove the added keys only while they still equal what up() wrote.
        $seededPostFx = [
            'bloom'             => true,
            'bloom_strength'    => 0.55,
            'bloom_threshold'   => 0.8,
            'bloom_radius'      => 0.4,
            'vignette'          => true,
            'vignette_blend'    => 'black',
            'vignette_darkness' => 0.55,
            'vignette_offset'   => 1.1,
        ];
        if (($vc['post_fx'] ?? null) === $seededPostFx) {
            unset($vc['post_fx']);
        }
        $seededReactive = [
            'enabled'       => true,
            'dead_zone'     => 0.18,
            'ref_speed'     => 3.0,
            'attack'        => 0.18,
            'release'       => 1.1,
            'max_intensity' => 1.0,
            'bezel_color'   => '0x00e5ff',
        ];
        if (($vc['artwork_reactive'] ?? null) === $seededReactive) {
            unset($vc['artwork_reactive']);
        }
        foreach ([
            'environment'            => 'none',
            'env_intensity'          => 0,
            'hemisphere_intensity'   => 0.05,
            'artwork_light_base'     => 0.28,
            'artwork_light_pool_cap' => 12,
        ] as $key => $seeded) {
            if (($vc[$key] ?? null) === $seeded) {
                unset($vc[$key]);
            }
        }
        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update(['visual_config' => json_encode($vc)]);

        $mc = json_decode((string) $row->material_config, true) ?: [];
        if (($mc['texture_tint'] ?? null) === true) {
            unset($mc['texture_tint']);
        }
        if ($this->guardedEquals($mc['floor_color'] ?? null, '0x0b0d14')) {
            $mc['floor_color'] = null;
        }
        if ($this->guardedEquals($mc['floor_roughness'] ?? null, 0.35)) {
            $mc['floor_roughness'] = 0.4;
        }
        if ($this->guardedEquals($mc['floor_metalness'] ?? null, 0.55)) {
            $mc['floor_metalness'] = 0.5;
        }
        if (($mc['floor_tile_meters'] ?? null) === 2.0 || ($mc['floor_tile_meters'] ?? null) === 2) {
            unset($mc['floor_tile_meters']);
        }
        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update(['material_config' => json_encode($mc)]);

        $v1Description = 'A dark electric space ringed with neon on every edge, the floor traced in light. For digital and web3 creators.';
        $v2Description = 'A signal room for digital natives: dark anodized walls, a floor traced in light, neon ringing every edge — and artworks that behave like living media. Stand still and they hold still. Move, and they react to you.';
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
