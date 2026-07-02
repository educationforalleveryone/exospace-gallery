<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consolidated venue_templates table creation. (Task H40 / audit M4)
 *
 * Replaces 2 additive migrations for fresh installs:
 *   2026_06_09_000001_create_venue_templates_table.php  (base)
 *   2026_06_29_184241_extend_venue_templates_table.php  (17 columns added)
 *
 * No-op on existing databases.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('venue_templates')) {
            return;
        }

        Schema::create('venue_templates', function (Blueprint $table) {
            $table->id();

            // Basic info
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('category')->default('standard');
            $table->text('description')->nullable();

            // Plan gating
            $table->string('plan_required', 20)->default('free');
            $table->integer('capacity_min')->default(1);
            $table->integer('capacity_max')->default(50);

            // Visual config (JSON — consumed by VenueConfigExporter + the 3D viewer)
            $table->json('default_settings');
            $table->json('visual_config')->nullable();
            $table->json('material_config')->nullable();
            $table->json('post_fx')->nullable();

            // Assets
            $table->string('thumbnail_path', 500)->nullable();
            $table->string('preview_model_path', 500)->nullable();
            $table->string('hdri_path', 500)->nullable();
            $table->string('default_audio_path', 500)->nullable();

            // Status
            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->integer('sort_order')->default(0);

            $table->timestamps();

            $table->index('is_active');
            $table->index('is_featured');
            $table->index('plan_required');
            $table->index('sort_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_templates');
    }
};
