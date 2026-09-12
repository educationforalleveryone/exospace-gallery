<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venue_templates', function (Blueprint $table) {
            $table->string('category', 32)->default('gallery')
                  ->after('plan_required')->index();

            $table->json('tags')->nullable()->after('category');

            $table->string('thumbnail_path', 500)->nullable()
                  ->after('thumbnail');

            $table->string('preview_model_path', 500)->nullable()
                  ->after('thumbnail_path');

            $table->string('hdri_path', 500)->nullable()
                  ->after('preview_model_path');

            $table->string('default_audio_path', 500)->nullable()
                  ->after('hdri_path');

            // ── Visual configuration (replaces JS switch) ────────────────
            $table->json('visual_config')->nullable()
                  ->after('default_audio_path');

            $table->json('material_config')->nullable()
                  ->after('visual_config');

            $table->json('decorations')->nullable()
                  ->after('material_config');

            $table->json('lighting_fixtures')->nullable()
                  ->after('decorations');

            $table->json('supported_layouts')->nullable()
                  ->after('lighting_fixtures');

            $table->boolean('is_featured')->default(false)
                  ->after('is_active')->index();
            $table->boolean('is_draft')->default(false)
                  ->after('is_featured');
            $table->unsignedInteger('view_count')->default(0)
                  ->after('is_draft');

            $table->foreignId('author_id')->nullable()
                  ->after('view_count')
                  ->constrained('users')->nullOnDelete();
            $table->string('version', 16)->default('1.0.0')
                  ->after('author_id');
            $table->timestamp('published_at')->nullable()
                  ->after('version');
        });

        \DB::table('venue_templates')
            ->whereNull('supported_layouts')
            ->update([
                'supported_layouts' => json_encode(['square', 'corridor', 'l-shape', 'rotunda']),
            ]);
    }

    public function down(): void
    {
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
