<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ENVIRONMENT AUTHORITY (s4) — stamps the venue's declared sky into
 * visual_config.environment for the four live venues.
 *
 * WHY
 * ---
 * The LAST unguarded identity channel was the environment (HDRI). It was
 * resolved at runtime from the GALLERY's lighting_preset column, so a stale
 * gallery-era preset installed a bright studio/dusk sky inside the Dark
 * Museum and its reflections read as a "cloudy sheen on the floor" (the
 * deployed-screenshot incident, second channel after the s1–s3 atmosphere/
 * material guards). The full fix has three parts:
 *
 *   1. exporter (already shipped) — visual_config.environment is venue-owned;
 *      owned-key lists ship in the payload; the preset/layout resolve through
 *      the venue (presetForGallery / layoutForGallery).
 *   2. runtime (this deploy) — AssetLoader resolves the HDRI through the
 *      venue authority chain; Materials honour the venue's env_intensity;
 *      GalleryScene.applyLiveOverride consumes the shipped owned-key lists.
 *   3. DATA (this migration + the paired seeder baseline) — existing venue
 *      ROWS must declare their environment, because the runtime reads the
 *      declaration from the DB, not from the seeder.
 *
 * WHICH VENUES
 * ------------
 *   white-cube     → 'studio'  (neutral bright museum reflections)
 *   infinite-void  → 'none'    (a void has no sky; HDRI download silenced —
 *                               pairs with its declared env_intensity: 0)
 *   industrial-loft→ 'night'   (a dusk-lit interior; no daytime cloud deck)
 *   dark-museum    → 'night'   (THE incident venue — a night institution)
 *
 * Zen Gallery is deliberately NOT touched by this migration (its design is
 * a separate workstream; the seeder already carries its baseline value).
 * Venues created through the editor declare their own via the Environment
 * select; rows that already declare the key are never modified.
 *
 * GUARDING (same contract as the IT3/IT6/white-cube/loft/deepening
 * migrations): the key is ADDED only when ABSENT. A super-admin's declared
 * value — even a hand-authored advanced-JSON one — always wins. Idempotent;
 * down() removes the key only while it still equals the value this
 * migration added (strings, strict).
 */
return new class extends Migration
{
    /** slug → declared environment (mirrors VenueTemplateSeeder baseline). */
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
