<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds two columns to `galleries`:
 *
 *   - is_featured: super-admin curated flag for /discover homepage
 *   - curtain_logo_path: Studio-only custom entrance curtain logo
 *   - curtain_bg_color: Studio-only custom entrance curtain background color
 *
 * The existing `venue_templates.is_featured` is the wrong axis for
 * featuring galleries — it features the venue template, not the
 * exhibition. This migration adds the right axis.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('galleries', function (Blueprint $table) {
            $table->boolean('is_featured')->default(false)->after('view_count')->index();
            $table->string('curtain_logo_path', 500)->nullable()->after('custom_logo_path');
            $table->string('curtain_bg_color', 20)->nullable()->after('curtain_logo_path');
        });
    }

    public function down(): void
    {
        Schema::table('galleries', function (Blueprint $table) {
            if (Schema::hasColumn('galleries', 'is_featured')) {
                $table->dropIndex(['is_featured']);
                $table->dropColumn('is_featured');
            }
            if (Schema::hasColumn('galleries', 'curtain_logo_path')) {
                $table->dropColumn('curtain_logo_path');
            }
            if (Schema::hasColumn('galleries', 'curtain_bg_color')) {
                $table->dropColumn('curtain_bg_color');
            }
        });
    }
};
