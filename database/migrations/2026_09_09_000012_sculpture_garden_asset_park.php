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

    private function gardenAssets(): array
    {
        return [
            'tree_large'  => 'tree_large_01.glb',
            'tree_medium' => 'tree_medium_01.glb',
            'tree_accent' => 'tree_medium_02.glb',
            'shrub'       => 'shrub_01.glb',
            'grass'       => 'grass_clump_01.glb',
            'boulder'     => 'boulder_01.glb',
            'bench'       => 'bench_01.glb',
        ];
    }

    public function up(): void
    {
        $row = DB::table('venue_templates')
            ->where('slug', 'sculpture-garden')
            ->first(['id', 'visual_config', 'material_config', 'description', 'version']);
        if (!$row) {
            return; // venue removed by the operator — respect that
        }

        $vc = json_decode((string) $row->visual_config, true) ?: [];

        $vcRewrites = [
            'background_color' => ['from' => '0xd6e0e2', 'to' => '0xdfe2d1'],
            'fog_color'        => ['from' => '0xd6e0e2', 'to' => '0xdfe2d1'],
            'field_radius_bonus' => ['from' => 2.2, 'to' => 2.6],
            'field_radius_min'   => ['from' => 12.5, 'to' => 14],
        ];
        foreach ($vcRewrites as $key => ['from' => $from, 'to' => $to]) {
            if ($this->guardedEquals($vc[$key] ?? null, $from)) {
                $vc[$key] = $to;
            }
        }

        if (is_array($vc['garden'] ?? null) && !isset($vc['garden']['assets_base'])) {
            $vc['garden']['assets_base'] = '/assets/venues/sculpture-garden/';
            $vc['garden']['assets'] = $this->gardenAssets();
        }

        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update(['visual_config' => json_encode($vc)]);

        if ($row->material_config) {
            $mc = json_decode((string) $row->material_config, true) ?: [];
            $mcRewrites = [
                'floor_color'       => ['from' => '0x3a6a2a', 'to' => '0x5e7a46'],
                'floor_tile_meters' => ['from' => 2.0, 'to' => 3.0],
            ];
            $changed = false;
            foreach ($mcRewrites as $key => ['from' => $from, 'to' => $to]) {
                if ($this->guardedEquals($mc[$key] ?? null, $from)) {
                    $mc[$key] = $to;
                    $changed = true;
                }
            }
            if ($changed) {
                DB::table('venue_templates')
                    ->where('id', $row->id)
                    ->update(['material_config' => json_encode($mc)]);
            }
        }

        // ── description (customer-facing truth) ──────────────────────────
        $v3Description = 'A curated landscape exhibition. A stone promenade leads from the garden gate to a bronze centrepiece, then on to sculpture clearings framed by trees, hedges and rolling meadow. Works are discovered one by one — never all at once.';
        $v4Description = 'A curated open-air exhibition. A gravel walk leads from the tree-lined gate to a bronze centrepiece on a travertine court, then on to works presented on outdoor museum stands across lawns and sculpture clearings, framed by mature trees and a distant treeline.';
        if ((string) $row->description === $v3Description) {
            DB::table('venue_templates')
                ->where('id', $row->id)
                ->update(['description' => $v4Description]);
        }

        if ($this->guardedEquals($row->version, '3.0.0')) {
            DB::table('venue_templates')->where('id', $row->id)->update(['version' => '4.0.0']);
        }
    }

    public function down(): void
    {
        $row = DB::table('venue_templates')
            ->where('slug', 'sculpture-garden')
            ->first(['id', 'visual_config', 'material_config', 'description', 'version']);
        if (!$row) {
            return;
        }

        $vc = json_decode((string) $row->visual_config, true) ?: [];

        $vcRewrites = [
            'background_color' => ['from' => '0xdfe2d1', 'to' => '0xd6e0e2'],
            'fog_color'        => ['from' => '0xdfe2d1', 'to' => '0xd6e0e2'],
            'field_radius_bonus' => ['from' => 2.6, 'to' => 2.2],
            'field_radius_min'   => ['from' => 14, 'to' => 12.5],
        ];
        foreach ($vcRewrites as $key => ['from' => $from, 'to' => $to]) {
            if ($this->guardedEquals($vc[$key] ?? null, $from)) {
                $vc[$key] = $to;
            }
        }

        // Remove the asset manifest only while it still equals what up() wrote.
        if (is_array($vc['garden'] ?? null)
            && ($vc['garden']['assets_base'] ?? null) === '/assets/venues/sculpture-garden/'
            && ($vc['garden']['assets'] ?? null) === $this->gardenAssets()) {
            unset($vc['garden']['assets_base'], $vc['garden']['assets']);
        }

        DB::table('venue_templates')
            ->where('id', $row->id)
            ->update(['visual_config' => json_encode($vc)]);

        if ($row->material_config) {
            $mc = json_decode((string) $row->material_config, true) ?: [];
            $mcRewrites = [
                'floor_color'       => ['from' => '0x5e7a46', 'to' => '0x3a6a2a'],
                'floor_tile_meters' => ['from' => 3.0, 'to' => 2.0],
            ];
            $changed = false;
            foreach ($mcRewrites as $key => ['from' => $from, 'to' => $to]) {
                if ($this->guardedEquals($mc[$key] ?? null, $from)) {
                    $mc[$key] = $to;
                    $changed = true;
                }
            }
            if ($changed) {
                DB::table('venue_templates')
                    ->where('id', $row->id)
                    ->update(['material_config' => json_encode($mc)]);
            }
        }

        $v3Description = 'A curated landscape exhibition. A stone promenade leads from the garden gate to a bronze centrepiece, then on to sculpture clearings framed by trees, hedges and rolling meadow. Works are discovered one by one — never all at once.';
        $v4Description = 'A curated open-air exhibition. A gravel walk leads from the tree-lined gate to a bronze centrepiece on a travertine court, then on to works presented on outdoor museum stands across lawns and sculpture clearings, framed by mature trees and a distant treeline.';
        if ((string) $row->description === $v4Description) {
            DB::table('venue_templates')
                ->where('id', $row->id)
                ->update(['description' => $v3Description]);
        }

        if ($this->guardedEquals($row->version, '4.0.0')) {
            DB::table('venue_templates')->where('id', $row->id)->update(['version' => '3.0.0']);
        }
    }
};
