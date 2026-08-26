<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends venue_templates with data-driven visual configuration,
 * 3D-model support, custom HDRI, decorations, lighting fixtures,
 * categorisation, versioning, and discovery fields.
 *
 * This migration is ADDITIVE — no existing columns are dropped or renamed.
 * The legacy `default_settings` JSON column is preserved for backward
 * compatibility with any code that reads it directly.
 *
 * After running this migration, re-seed venue templates:
 *   php artisan db:seed --class=VenueTemplateSeeder
 *
 * The seeder will populate the new JSON fields with the same values
 * that were previously hardcoded in resources/views/gallery/view.blade.php
 * (the `applyVenueOverrides(slug)` switch), so the visual output is
 * identical before and after the migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venue_templates', function (Blueprint $table) {
            // ── Categorisation & discovery ────────────────────────────────
            $table->string('category', 32)->default('gallery')
                  ->after('plan_required')->index();
            // Allowed values: gallery, museum, warehouse, outdoor,
            //                  futuristic, minimal, luxury, abstract

            $table->json('tags')->nullable()->after('category');

            // ── 3D-model & asset support ─────────────────────────────────
            $table->string('thumbnail_path', 500)->nullable()
                  ->after('thumbnail');
            // Uploaded thumbnail image (replaces the emoji-based atmosphere
            // maps that were hardcoded in create/edit/view Blade files).

            $table->string('preview_model_path', 500)->nullable()
                  ->after('thumbnail_path');
            // GLB / GLTF file. Used in the admin panel for a 3D preview
            // of the venue. May also be used by the viewer as a fallback
            // geometry if `decorations` is empty.

            $table->string('hdri_path', 500)->nullable()
                  ->after('preview_model_path');
            // Custom HDRI environment map (.hdr / .exr). Overrides the
            // preset-based HDRI selection in the viewer.

            $table->string('default_audio_path', 500)->nullable()
                  ->after('hdri_path');
            // Default ambient audio applied to galleries that use this
            // venue and don't override with their own audio_path.

            // ── Visual configuration (replaces JS switch) ────────────────
            $table->json('visual_config')->nullable()
                  ->after('default_audio_path');
            // Shape: {
            //   wall_height: float,
            //   wall_depth: float,
            //   ceiling_type: "flat"|"beamed"|"glass"|"none",
            //   ceiling_height: float,
            //   background_color: "0xRRGGBB",
            //   fog_color: "0xRRGGBB" | null,
            //   fog_near: int,
            //   fog_far: int,
            //   ambient_color: "0xRRGGBB",
            //   ambient_intensity: float,
            //   spot_intensity: float,
            //   fill_intensity: float,
            //   tone_mapping_exposure: float,
            //   frame_override: "gold"|"silver"|"bronze"|"black"|"white"|null
            // }

            $table->json('material_config')->nullable()
                  ->after('visual_config');
            // Shape: {
            //   wall_color: "0xRRGGBB" | null,
            //   wall_roughness: float,
            //   wall_metalness: float,
            //   wall_normal_strength: float,
            //   floor_color: "0xRRGGBB" | null,
            //   floor_roughness: float,
            //   floor_metalness: float,
            //   floor_normal_strength: float
            // }

            $table->json('decorations')->nullable()
                  ->after('material_config');
            // Array of: {
            //   id: string,
            //   type: "sculpture"|"pedestal"|"plant"|"bench"|"column"|"custom",
            //   model_path: string,           // GLB file path (relative to /storage/)
            //   position: [x, y, z],
            //   rotation: [x, y, z],          // radians
            //   scale: float | [x, y, z],
            //   plan_required: "free"|"pro"|"studio"  // gates visibility by visitor's plan
            // }

            $table->json('lighting_fixtures')->nullable()
                  ->after('decorations');
            // Array of: {
            //   id: string,
            //   type: "point"|"spot"|"directional"|"strip",
            //   position: [x, y, z],
            //   color: "0xRRGGBB",
            //   intensity: float,
            //   cast_shadow: boolean,
            //   distance: float | null,
            //   decay: float | null
            // }

            $table->json('supported_layouts')->nullable()
                  ->after('lighting_fixtures');
            // Array of strings — subset of ["square","corridor","l-shape","rotunda"].
            // When null, all four layouts are allowed (backward compat).

            // ── Status & metadata ────────────────────────────────────────
            $table->boolean('is_featured')->default(false)
                  ->after('is_active')->index();
            $table->boolean('is_draft')->default(false)
                  ->after('is_featured');
            $table->unsignedInteger('view_count')->default(0)
                  ->after('is_draft');

            // ── Ownership & versioning ───────────────────────────────────
            $table->foreignId('author_id')->nullable()
                  ->after('view_count')
                  ->constrained('users')->nullOnDelete();
            $table->string('version', 16)->default('1.0.0')
                  ->after('author_id');
            $table->timestamp('published_at')->nullable()
                  ->after('version');
        });

        // Backfill `supported_layouts` for all existing rows so the
        // constraint behaves as "all layouts allowed" until an admin
        // explicitly narrows it.
        \DB::table('venue_templates')
            ->whereNull('supported_layouts')
            ->update([
                'supported_layouts' => json_encode(['square', 'corridor', 'l-shape', 'rotunda']),
            ]);
    }

    public function down(): void
    {
        // ITERATION-1 FIX (consolidated-migration coexistence + SQLite):
        // fresh installs build venue_templates via the consolidated
        // migration that runs later in the batch — dropping every extended
        // column then leaves an EMPTY column list, which SQLite compiles as
        // `create table __temp__venue_templates ()` — a syntax error. Also
        // guard for the table not existing at all.
        if (! Schema::hasTable('venue_templates')) {
            return;
        }
        $dropColumns = array_values(array_filter([
            'category', 'tags',
            'thumbnail_path', 'preview_model_path', 'hdri_path', 'default_audio_path',
            'visual_config', 'material_config',
            'decorations', 'lighting_fixtures', 'supported_layouts',
            'is_featured', 'is_draft', 'view_count',
            'author_id', 'version', 'published_at',
        ], fn ($column) => Schema::hasColumn('venue_templates', $column)));

        if ($dropColumns === []) {
            return; // nothing this migration owns remains to drop
        }

        Schema::table('venue_templates', function (Blueprint $table) {
            // Drop foreign key first to avoid constraint errors
            try {
                $table->dropForeign(['author_id']);
            } catch (\Throwable $e) {
                // Foreign key may not exist if migration was partially applied
            }

            foreach ($dropColumns as $column) {
                $table->dropColumn($column);
            }
        });
    }
};
