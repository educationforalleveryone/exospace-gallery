<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEO Operating System (Iteration 4) — managed redirects.
 *
 * When content moves (slug changes, URL scheme changes, sunsets), a 301
 * preserves accumulated signals. Managed via super-admin UI (Iteration 6)
 * or `php artisan tinker`.
 *
 * source_path is the application path WITHOUT leading slash, lowercase,
 * query-free ("/old-exhibition"). destination may be a path or absolute URL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_redirects', function (Blueprint $table) {
            $table->id();
            $table->string('source_path', 500)->unique();   // 'old-path' (no leading /)
            $table->string('destination', 1000);             // '/new-path' or absolute URL
            $table->unsignedSmallInteger('status_code')->default(301); // 301|302|308
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('hits')->default(0);
            $table->timestamp('last_hit_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_redirects');
    }
};
