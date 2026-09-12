<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_pages', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20)->default('landing');   // landing|editorial
            $table->string('slug', 200)->unique();             // lowercase, hyphenated
            $table->string('title', 200);
            $table->string('seo_title', 200)->nullable();
            $table->string('meta_description', 300)->nullable();
            $table->json('blocks')->nullable();                // typed content blocks
            $table->string('og_image_path', 500)->nullable();
            $table->string('canonical_override', 500)->nullable();
            $table->boolean('noindex')->default(false);
            $table->string('status', 20)->default('draft');    // draft|published
            $table->timestamp('published_at')->nullable();     // future = scheduled
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'published_at']);
            $table->index(['type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_pages');
    }
};
