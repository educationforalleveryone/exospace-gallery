<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('venue_templates')) {
            return;
        }

        Schema::create('venue_templates', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description'); // NOT nullable — matches base migration
            $table->string('thumbnail')->nullable(); // legacy column from base migration

            $table->string('category', 32)->default('gallery')->index();
            $table->json('tags')->nullable();

            $table->string('plan_required', 20)->default('free')->index();
            $table->integer('capacity_min')->default(10);
            $table->integer('capacity_max')->nullable(); // null = unlimited

            $table->string('thumbnail_path', 500)->nullable();
            $table->string('preview_model_path', 500)->nullable();
            $table->string('hdri_path', 500)->nullable();
            $table->string('default_audio_path', 500)->nullable();

            $table->json('default_settings'); // legacy — kept for back-compat
            $table->json('visual_config')->nullable();
            $table->json('material_config')->nullable();
            $table->json('decorations')->nullable();
            $table->json('lighting_fixtures')->nullable();
            $table->json('supported_layouts')->nullable();

            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_featured')->default(false)->index();
            $table->boolean('is_draft')->default(false);
            $table->unsignedInteger('view_count')->default(0);
            $table->integer('sort_order')->default(0)->index();

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
        Schema::dropIfExists('venue_templates');
    }
};
