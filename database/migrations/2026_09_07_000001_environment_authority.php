<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const DECLARATIONS = [
        'white-cube'     => 'studio',
        'infinite-void'  => 'none',
        'industrial-loft' => 'night',
        'dark-museum'    => 'night',
    ];

    public function up(): void
    {
        foreach (self::DECLARATIONS as $slug => $environment) {
            $row = DB::table('venue_templates')
                ->where('slug', $slug)
                ->first(['id', 'visual_config']);
            if (!$row) {
                continue; // venue removed by the operator — respect that
            }

            $vc = json_decode((string) $row->visual_config, true) ?: [];

            // Key add — only when absent (an admin's declared value wins).
            if (!array_key_exists('environment', $vc)) {
                $vc['environment'] = $environment;

                DB::table('venue_templates')
                    ->where('id', $row->id)
                    ->update(['visual_config' => json_encode($vc)]);
            }
        }
    }

    public function down(): void
    {
        foreach (self::DECLARATIONS as $slug => $environment) {
            $row = DB::table('venue_templates')
                ->where('slug', $slug)
                ->first(['id', 'visual_config']);
            if (!$row) {
                continue;
            }

            $vc = json_decode((string) $row->visual_config, true) ?: [];

            // Remove only while it still equals what we added.
            if (($vc['environment'] ?? null) === $environment) {
                unset($vc['environment']);

                DB::table('venue_templates')
                    ->where('id', $row->id)
                    ->update(['visual_config' => json_encode($vc)]);
            }
        }
    }
};
