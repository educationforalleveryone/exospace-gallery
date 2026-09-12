<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private function consolidationKeys(): array
    {
        return [
            'infinite-void' => [
                'open_air'      => true,
                'layout_shape'  => 'circular',
                'void_dust'     => true,
            ],
            'industrial-loft' => [
                'ceiling_color' => '0x1a1a18',
                'ceiling_beams' => true,
                'structure_pass' => 'loft',
            ],
            'dark-museum' => [
                'ceiling_color' => '0x080808',
                'structure_pass' => 'museum',
            ],
            'zen-gallery' => [
                'ceiling_color' => '0x1e1c14',
            ],
            'crystal-cathedral' => [
                'open_air'      => true,
                'layout_shape'  => 'circular',
                'void_colonnade' => true,
            ],
            'nebula-drift' => [
                'open_air'      => true,
                'layout_shape'  => 'circular',
                'void_starfield' => true,
            ],
            'luxury-penthouse' => [
                'ceiling_color' => '0x080808',
            ],
            'cyber-gallery' => [
                'ceiling_color' => '0x04081a',
                'ceiling_neon'  => true,
            ],
            'sculpture-garden' => [
                'open_air'      => true,
                'layout_shape'  => 'circular',
                'structure_pass' => 'garden',
            ],
            'mirror-lake' => [
                'open_air'      => true,
                'layout_shape'  => 'circular',
                'void_lake'     => true,
            ],
        ];
    }

    public function up(): void
    {
        foreach ($this->consolidationKeys() as $slug => $keys) {
            $row = DB::table('venue_templates')->where('slug', $slug)->first(['id', 'visual_config']);
            if (!$row) {
                continue; // venue removed by the operator — respect that
            }
            $existing = json_decode((string) $row->visual_config, true) ?: [];
            $merged   = $existing + $keys;
            if ($merged !== $existing) {
                DB::table('venue_templates')
                    ->where('id', $row->id)
                    ->update(['visual_config' => json_encode($merged)]);
            }
        }

        $row = DB::table('venue_templates')->where('slug', 'white-cube')->first(['id', 'visual_config']);
        if ($row) {
            $existing = json_decode((string) $row->visual_config, true) ?: [];
            if (($existing['structure_pass'] ?? null) === 'rooms') {
                $existing['structure_pass'] = 'cube';
                DB::table('venue_templates')
                    ->where('id', $row->id)
                    ->update(['visual_config' => json_encode($existing)]);
            }
        }
    }

    public function down(): void
    {
        // Restore white-cube first (reverse of up's guarded update).
        $row = DB::table('venue_templates')->where('slug', 'white-cube')->first(['id', 'visual_config']);
        if ($row) {
            $existing = json_decode((string) $row->visual_config, true) ?: [];
            if (($existing['structure_pass'] ?? null) === 'cube') {
                $existing['structure_pass'] = 'rooms';
                DB::table('venue_templates')
                    ->where('id', $row->id)
                    ->update(['visual_config' => json_encode($existing)]);
            }
        }

        foreach ($this->consolidationKeys() as $slug => $keys) {
            $row = DB::table('venue_templates')->where('slug', $slug)->first(['id', 'visual_config']);
            if (!$row) {
                continue;
            }
            $existing = json_decode((string) $row->visual_config, true) ?: [];
            $changed  = false;
            foreach ($keys as $key => $value) {
                if (array_key_exists($key, $existing) && $existing[$key] === $value) {
                    unset($existing[$key]);
                    $changed = true;
                }
            }
            if ($changed) {
                DB::table('venue_templates')
                    ->where('id', $row->id)
                    ->update(['visual_config' => json_encode($existing)]);
            }
        }
    }
};
