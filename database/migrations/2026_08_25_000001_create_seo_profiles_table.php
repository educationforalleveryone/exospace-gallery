<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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

            $table->string('canonical_override', 500)->nullable();

            $table->string('robots_directive', 100)->nullable();

            // Custom OG image (stored under storage/app/public, path relative)
            $table->string('og_image_path', 500)->nullable();

            $table->boolean('sitemap_include')->nullable();

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
