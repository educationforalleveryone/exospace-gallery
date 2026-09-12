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
        $row = DB::table('venue_templates')->where('slug', 'infinite-void')->first(['id', 'visual_config', 'material_config', 'default_settings', 'version']);
        if (!$row) {
            return; // venue removed by the operator — respect that
        }

        $vc = json_decode((string) $row->visual_config, true) ?: [];

        // Rig retune — exact-match guarded against the IT0/IT2 seeded values.
        $vcRewrites = [
            'ambient_intensity'     => ['from' => 0.2,  'to' => 0.3],
            'spot_intensity'        => ['from' => 0.55, 'to' => 1.3],
            'fill_intensity'        => ['from' => 0.12, 'to' => 0.2],
            'tone_mapping_exposure' => ['from' => 0.55, 'to' => 0.9],
        ];
        foreach ($vcRewrites as $key => ['from' => $from, 'to' => $to]) {
            if ($this->guardedEquals($vc[$key] ?? null, $from)) {
                $vc[$key] = $to;
            }
        }

        // Declared adds — only when absent (an admin's declared value wins).
        if (!array_key_exists('artwork_light_base', $vc)) {
            // Standing-glow fraction for the pooled artwork lights (Lighting.js).
            $vc['artwork_light_base'] = 0.45;
        }
        if (!array_key_exists('artwork_light_pool_cap', $vc)) {
            $vc['artwork_light_pool_cap'] = 12;
        }
        if (!array_key_exists('void_depth_gradient', $vc)) {
            // 'phenomena' pass ingredient: the zenith depth cue.
            $vc['void_depth_gradient'] = true;
        }
        if (!array_key_exists('post_fx', $vc)) {
            $vc['post_fx'] = [
                'bloom'             => false,
                'vignette'          => true,
                'vignette_darkness' => 1.0,
                'vignette_offset'   => 1.35,
            ];
        }
        if (!array_key_exists('placement', $vc)) {
            $vc['placement'] = ['depth_bands' => 2];
        }

        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update(['visual_config' => json_encode($vc)]);

        $mc = json_decode((string) $row->material_config, true) ?: [];

        if ($this->guardedEquals($mc['floor_roughness'] ?? null, 0.4)) {
            $mc['floor_roughness'] = 0.32;
        }
        if ($this->guardedEquals($mc['floor_metalness'] ?? null, 0.6)) {
            $mc['floor_metalness'] = 0.25;
        }
        if (!array_key_exists('texture_tint', $mc)) {
            $mc['texture_tint'] = true;
        }

        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update(['material_config' => json_encode($mc)]);

        $ds = json_decode((string) $row->default_settings, true) ?: [];
        if ($this->guardedEquals($ds['frame_style'] ?? null, 'minimal')) {
            $ds['frame_style'] = 'modern';
        }
        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update(['default_settings' => json_encode($ds)]);

        if ($this->guardedEquals($row->version, '1.0.0')) {
            DB::table('venue_templates')->where('id', $row->id)->update(['version' => '2.0.0']);
        }
    }

    public function down(): void
    {
        $row = DB::table('venue_templates')->where('slug', 'infinite-void')->first(['id', 'visual_config', 'material_config', 'default_settings', 'version']);
        if (!$row) {
            return;
        }

        $vc = json_decode((string) $row->visual_config, true) ?: [];
        $vcRewrites = [
            'ambient_intensity'     => ['from' => 0.3,  'to' => 0.2],
            'spot_intensity'        => ['from' => 1.3,  'to' => 0.55],
            'fill_intensity'        => ['from' => 0.2,  'to' => 0.12],
            'tone_mapping_exposure' => ['from' => 0.9,  'to' => 0.55],
        ];
        foreach ($vcRewrites as $key => ['from' => $from, 'to' => $to]) {
            if ($this->guardedEquals($vc[$key] ?? null, $from)) {
                $vc[$key] = $to;
            }
        }
        if (($vc['artwork_light_base'] ?? null) === 0.45) {
            unset($vc['artwork_light_base']);
        }
        if (($vc['artwork_light_pool_cap'] ?? null) === 12) {
            unset($vc['artwork_light_pool_cap']);
        }
        if (($vc['void_depth_gradient'] ?? null) === true) {
            unset($vc['void_depth_gradient']);
        }
        if (($vc['post_fx'] ?? null) === ['bloom' => false, 'vignette' => true, 'vignette_darkness' => 1.0, 'vignette_offset' => 0.92]) {
            unset($vc['post_fx']);
        }
        if (($vc['placement'] ?? null) === ['depth_bands' => 2]) {
            unset($vc['placement']);
        }
        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update(['visual_config' => json_encode($vc)]);

        $mc = json_decode((string) $row->material_config, true) ?: [];
        if ($this->guardedEquals($mc['floor_roughness'] ?? null, 0.32)) {
            $mc['floor_roughness'] = 0.4;
        }
        if ($this->guardedEquals($mc['floor_metalness'] ?? null, 0.25)) {
            $mc['floor_metalness'] = 0.6;
        }
        if (($mc['texture_tint'] ?? null) === true) {
            unset($mc['texture_tint']);
        }
        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update(['material_config' => json_encode($mc)]);

        $ds = json_decode((string) $row->default_settings, true) ?: [];
        if ($this->guardedEquals($ds['frame_style'] ?? null, 'modern')) {
            $ds['frame_style'] = 'minimal';
        }
        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update(['default_settings' => json_encode($ds)]);

        if ($this->guardedEquals($row->version, '2.0.0')) {
            DB::table('venue_templates')->where('id', $row->id)->update(['version' => '1.0.0']);
        }
    }
};
