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
        $row = DB::table('venue_templates')->where('slug', 'white-cube')->first(['id', 'visual_config', 'material_config', 'default_settings']);
        if (!$row) {
            return; // venue removed by the operator — respect that
        }

        $vc = json_decode((string) $row->visual_config, true) ?: [];

        $vcRewrites = [
            'background_color'      => ['from' => '0x0f0f0f', 'to' => '0xf2f1ee'],
            'fog_color'             => ['from' => '0x0f0f0f', 'to' => '0xf2f1ee'],
            'fog_near'              => ['from' => 10,         'to' => 16],
            'fog_far'               => ['from' => 30,         'to' => 60],
            'ambient_intensity'     => ['from' => 0.2,        'to' => 0.55],
            'spot_intensity'        => ['from' => 0.45,       'to' => 3.2],
            'fill_intensity'        => ['from' => 0.12,       'to' => 2.6],
            'tone_mapping_exposure' => ['from' => 0.5,        'to' => 1.05],
        ];
        foreach ($vcRewrites as $key => ['from' => $from, 'to' => $to]) {
            if ($this->guardedEquals($vc[$key] ?? null, $from)) {
                $vc[$key] = $to;
            }
        }

        // Key adds — only when absent (an admin's declared post_fx wins).
        if (!array_key_exists('post_fx', $vc)) {
            $vc['post_fx'] = [
                'bloom'             => false,
                'vignette'          => true,
                'vignette_darkness' => 0.28,
                'vignette_offset'   => 1.05,
            ];
        }

        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update(['visual_config' => json_encode($vc)]);

        $mc = json_decode((string) $row->material_config, true) ?: [];
        if (($mc['floor_color'] ?? null) === null) {
            $mc['floor_color'] = '0x9c9c98';
        }
        if ($this->guardedEquals($mc['floor_roughness'] ?? null, 0.7)) {
            $mc['floor_roughness'] = 0.55;
        }
        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update(['material_config' => json_encode($mc)]);

        $ds = json_decode((string) $row->default_settings, true) ?: [];
        if (($ds['frame_style'] ?? null) === 'minimal') {
            $ds['frame_style'] = 'modern';
        }
        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update(['default_settings' => json_encode($ds)]);
    }

    public function down(): void
    {
        $row = DB::table('venue_templates')->where('slug', 'white-cube')->first(['id', 'visual_config', 'material_config', 'default_settings']);
        if (!$row) {
            return;
        }

        $vc = json_decode((string) $row->visual_config, true) ?: [];
        $vcRewrites = [
            'background_color'      => ['from' => '0xf2f1ee', 'to' => '0x0f0f0f'],
            'fog_color'             => ['from' => '0xf2f1ee', 'to' => '0x0f0f0f'],
            'fog_near'              => ['from' => 16,         'to' => 10],
            'fog_far'               => ['from' => 60,         'to' => 30],
            'ambient_intensity'     => ['from' => 0.55,       'to' => 0.2],
            'spot_intensity'        => ['from' => 3.2,        'to' => 0.45],
            'fill_intensity'        => ['from' => 2.6,        'to' => 0.12],
            'tone_mapping_exposure' => ['from' => 1.05,       'to' => 0.5],
        ];
        foreach ($vcRewrites as $key => ['from' => $from, 'to' => $to]) {
            if ($this->guardedEquals($vc[$key] ?? null, $from)) {
                $vc[$key] = $to;
            }
        }
        // Remove post_fx only while it still equals what up() wrote.
        if (($vc['post_fx'] ?? null) === [
            'bloom'             => false,
            'vignette'          => true,
            'vignette_darkness' => 0.28,
            'vignette_offset'   => 1.05,
        ]) {
            unset($vc['post_fx']);
        }
        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update(['visual_config' => json_encode($vc)]);

        $mc = json_decode((string) $row->material_config, true) ?: [];
        if (($mc['floor_color'] ?? null) === '0x9c9c98') {
            $mc['floor_color'] = null;
        }
        if ($this->guardedEquals($mc['floor_roughness'] ?? null, 0.55)) {
            $mc['floor_roughness'] = 0.7;
        }
        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update(['material_config' => json_encode($mc)]);

        $ds = json_decode((string) $row->default_settings, true) ?: [];
        if (($ds['frame_style'] ?? null) === 'modern') {
            $ds['frame_style'] = 'minimal';
        }
        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update(['default_settings' => json_encode($ds)]);
    }
};
