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
 *
 * P0-6 FIX (audit): The previous version of this consolidated migration had
 * a schema that DID NOT MATCH the additive migrations it claims to consolidate.
 * Specifically:
 *   - Missing 9 columns: tags, decorations, lighting_fixtures, supported_layouts,
 *     is_draft, view_count, author_id, version, published_at
 *   - Had a phantom `post_fx` column that doesn't exist in any additive migration
 *     or in the VenueTemplate model's $fillable
 *   - Wrong defaults: category (was 'standard', should be 'gallery'),
 *     capacity_min (was 1, should be 10), capacity_max (was 50 NOT NULL,
 *     should be nullable)
 *   - Wrong nullability: description was nullable, should be NOT NULL
 *   - Missing the `thumbnail` legacy column from the base migration
 *   - Missing the `category` index and `author_id` foreign key constraint
 *
 * This is a TIME BOMB: the migration is currently dead code (the additive
 * migrations run first and the `Schema::hasTable` guard short-circuits),
 * but database/migrations/archive/README.md explicitly instructs maintainers
 * to archive the additive migrations once all production environments are
 * updated. The moment that happens, every fresh install creates a broken
 * table and the VenueTemplateSeeder + admin UI crash.
 *
 * This fix makes the consolidated schema EXACTLY match the result of running
 * both additive migrations, so the archive operation becomes safe.
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

            // ── Basic info ────────────────────────────────────────────────
            // (from 2026_06_09_000001 base migration)
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description'); // NOT nullable — matches base migration
            $table->string('thumbnail')->nullable(); // legacy column from base migration

            // ── Categorisation & discovery ────────────────────────────────
            // (from 2026_06_29_184241 extend migration)
            $table->string('category', 32)->default('gallery')->index();
            // Allowed values: gallery, museum, warehouse, outdoor,
            //                  futuristic, minimal, luxury, abstract
            $table->json('tags')->nullable();

            // ── Plan gating & capacity ────────────────────────────────────
            // (from 2026_06_09_000001 base migration)
            $table->string('plan_required', 20)->default('free')->index();
            $table->integer('capacity_min')->default(10);
            $table->integer('capacity_max')->nullable(); // null = unlimited

            // ── 3D-model & asset support ──────────────────────────────────
            // (from 2026_06_29_184241 extend migration)
            $table->string('thumbnail_path', 500)->nullable();
            $table->string('preview_model_path', 500)->nullable();
            $table->string('hdri_path', 500)->nullable();
            $table->string('default_audio_path', 500)->nullable();

            // ── Visual configuration (replaces JS switch) ────────────────
            // (from 2026_06_29_184241 extend migration)
            $table->json('default_settings'); // legacy — kept for back-compat
            $table->json('visual_config')->nullable();
            $table->json('material_config')->nullable();
            $table->json('decorations')->nullable();
            $table->json('lighting_fixtures')->nullable();
            $table->json('supported_layouts')->nullable();
            // NOTE: NO `post_fx` column — it was a phantom in the previous
            // consolidated migration. The additive migrations and the
            // VenueTemplate model do not reference it.

            // ── Status & discovery ────────────────────────────────────────
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_featured')->default(false)->index();
            $table->boolean('is_draft')->default(false);
            $table->unsignedInteger('view_count')->default(0);
            $table->integer('sort_order')->default(0)->index();

            // ── Ownership & versioning ───────────────────────────────────
            // (from 2026_06_29_184241 extend migration)
            $table->foreignId('author_id')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->string('version', 16)->default('1.0.0');
            $table->timestamp('published_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        // ITERATION-1 FIX (portable rollback): SQLite enforces FKs during
        // DROP TABLE — a consolidated table set must unwind without order
        // constraints. MySQL drops are unaffected (FK checks are per-engine).
        Schema::dropIfExists('venue_templates');
    }
};
