<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `custom_domain` column to galleries for the Studio-plan
 * white-label feature. When a request comes in on a host matching
 * a gallery's custom_domain, the DetectCustomDomain middleware
 * resolves it and the GalleryViewController renders that gallery.
 *
 * Custom domains must be configured at the DNS / Coolify / Nginx level
 * (CNAME → exospace.gallery) and SSL termination happens at the reverse
 * proxy. Laravel only needs to know which domain maps to which gallery.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('galleries', function (Blueprint $table) {
            $table->string('custom_domain', 255)->nullable()->unique()->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('galleries', function (Blueprint $table) {
            $table->dropColumn('custom_domain');
        });
    }
};
