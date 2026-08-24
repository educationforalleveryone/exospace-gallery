<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEO Operating System (Iteration 1) — polymorphic SEO profile table.
 *
 * One row per entity (gallery, artist, seo_page, ...) holding admin-level
 * SEO overrides. All columns are nullable: a missing row or missing value
 * means "use the automatically generated value from real content".
 *
 * Deliberate design decisions:
 *  - Polymorphic (not JSON columns on each entity table) so new entity
 *    types get SEO support by adding one relation, zero migrations.
 *  - No keyword column: keyword strategy (when it arrives) is applied
 *    through title_override / description_override and content, not stored
 *    as unused metadata. This table is an override layer, not a strategy
 *    store.
 *  - noindex / sitemap_include / structured_data_enabled are tri-state in
 *    effect: NULL = follow the automatic quality rules; true/false =
 *    forced. (For noindex we store a robots directive string instead, so
 *    admins can express "noindex,follow" vs "noindex,nofollow".)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_profiles', function (Blueprint $table) {
            $table->id();

            // Polymorphic owner
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->unique(['subject_type', 'subject_id']);
            $table->index('subject_type');

            // Metadata overrides (NULL = auto-generate from entity content)
            $table->string('title_override')->nullable();
            $table->text('description_override')->nullable();

            // Canonical override (absolute URL). Use with care — usually
            // the automatic canonical (public_url etc.) is correct.
            $table->string('canonical_override', 500)->nullable();

            // Robots directive override, e.g. "noindex,follow".
            // NULL = automatic quality rules decide.
            $table->string('robots_directive', 100)->nullable();

            // Custom OG image (stored under storage/app/public, path relative)
            $table->string('og_image_path', 500)->nullable();

            // Sitemap inclusion: NULL = automatic (indexable pages only),
            // false = never include, true = include even if auto rules would
            // exclude (admin takes responsibility for quality).
            $table->boolean('sitemap_include')->nullable();

            // Structured-data eligibility: false = suppress entity schema
            // (e.g. when an admin knows the entity data is too thin to be
            // a useful schema object). NULL = automatic.
            $table->boolean('structured_data_enabled')->nullable();

            // Who last edited this profile (audit trail)
            $table->foreignId('updated_by')->nullable()
                  ->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_profiles');
    }
};
