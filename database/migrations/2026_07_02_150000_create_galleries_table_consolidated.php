<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consolidated galleries table creation. (Task H36 / audit M4)
 *
 * This migration replaces 11 additive migrations that ran sequentially
 * for fresh installs:
 *   2026_01_19_111649_create_galleries_table.php            (base)
 *   2026_02_06_054006_add_audio_to_galleries_table.php
 *   2026_02_06_084946_add_custom_logo_to_galleries_table.php
 *   2026_03_22_184944_add_room_layout_to_galleries_table.php
 *   2026_04_21_201851_add_pin_to_galleries_table.php
 *   2026_04_22_140439_add_schedule_to_galleries_table.php
 *   2026_06_29_195411_add_custom_domain_to_galleries_table.php
 *   2026_06_30_014504_add_featured_and_curtain_to_galleries.php
 *   2026_07_01_070923_convert_gallery_material_columns_to_varchar.php
 *   2026_07_02_000001_add_visual_overrides_to_galleries.php
 *   2026_07_02_100000_add_custom_domain_verification_to_galleries_table.php
 *
 * For fresh installs, this single migration produces the final schema.
 * Existing production databases that have already run the additive
 * migrations are unaffected — Laravel tracks migrations by filename,
 * and this file has a different name.
 *
 * The old additive migrations can be archived to database/migrations/archive/
 * once all production environments have been updated. They're kept in
 * place for now so existing deployments don't break.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('galleries')) {
            // Table already exists (created by the original additive
            // migrations). This consolidated migration is a no-op on
            // existing databases. It only runs the full CREATE on
            // fresh installs where the old migrations haven't run.
            return;
        }

        Schema::create('galleries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('team_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('venue_template_id')->nullable()->constrained()->onDelete('set null');

            // Basic info
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();

            // Venue materials (VARCHAR(20) — not ENUM, so new values
            // can be added without a migration. See task C15.)
            $table->string('wall_texture', 20)->default('white');
            $table->string('frame_style', 20)->default('modern');
            $table->string('lighting_preset', 20)->default('bright');
            $table->string('floor_material', 20)->default('wood');
            $table->string('room_layout', 20)->default('square');

            // Media paths (disk-relative — see audit M6 for convention notes)
            $table->string('audio_path', 500)->nullable();
            $table->string('custom_logo_path', 500)->nullable();
            $table->string('curtain_logo_path', 500)->nullable();
            $table->string('curtain_bg_color', 20)->nullable();

            // Access control
            $table->string('pin_hash')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('view_count')->default(0);

            // Scheduling
            $table->timestamp('opens_at')->nullable();
            $table->timestamp('closes_at')->nullable();

            // Custom domain (Studio plan)
            $table->string('custom_domain')->nullable()->unique();
            $table->string('custom_domain_verification_token', 64)->nullable();
            $table->timestamp('custom_domain_verified_at')->nullable();

            // Featured exhibitions (super-admin curated)
            $table->boolean('is_featured')->default(false);

            // Visual overrides (Live Preview — per-gallery tweaks)
            $table->json('visual_overrides')->nullable();

            $table->timestamps();

            // Indexes
            $table->index('user_id');
            $table->index('team_id');
            $table->index('venue_template_id');
            $table->index('is_active');
            $table->index('is_featured');
            $table->index('custom_domain_verified_at');
            $table->index('opens_at');
            $table->index('closes_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('galleries');
    }
};
