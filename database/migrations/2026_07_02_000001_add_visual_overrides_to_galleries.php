<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `visual_overrides` JSON column to the `galleries` table.
 *
 * This column stores per-gallery tweaks made through the Live Preview panel
 * in admin/galleries/edit. It sits ON TOP of the venue template's
 * visual_config + material_config + post_fx — the VenueConfigExporter merges
 * the three layers (venue defaults → gallery-level explicit fields → visual_overrides)
 * before sending the config to the 3D viewer.
 *
 * Stored shape (structured, not flat, so the exporter can merge each section
 * independently without knowing which key belongs to which config bucket):
 *
 *   {
 *     "visual_config": {
 *       "wall_height": 5.5,
 *       "fog_color": "0x1a1a1a",
 *       "fog_near": 8,
 *       "fog_far": 28,
 *       "background_color": "0x0a0a14",
 *       "ambient_intensity": 0.22,
 *       "spot_intensity": 0.55,
 *       "fill_intensity": 0.14,
 *       "tone_mapping_exposure": 0.55
 *     },
 *     "material_config": {
 *       "wall_roughness": 0.7,
 *       "wall_metalness": 0.05,
 *       "wall_normal_strength": 0.4,
 *       "wall_color": "0xf0e8d0",
 *       "floor_roughness": 0.4,
 *       "floor_metalness": 0.15,
 *       "floor_color": "0x404040"
 *     },
 *     "post_fx": {
 *       "bloom_strength": 0.7,
 *       "bloom_threshold": 0.85,
 *       "vignette_darkness": 0.45,
 *       "vignette_offset": 1.0
 *     }
 *   }
 *
 * Null/missing keys fall back to the venue template default — so partial
 * overrides are valid. An entirely null `visual_overrides` (existing
 * galleries pre-migration) behaves exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('galleries', function (Blueprint $table) {
            $table->json('visual_overrides')->nullable()->after('room_layout');
        });
    }

    public function down(): void
    {
        Schema::table('galleries', function (Blueprint $table) {
            $table->dropColumn('visual_overrides');
        });
    }
};
