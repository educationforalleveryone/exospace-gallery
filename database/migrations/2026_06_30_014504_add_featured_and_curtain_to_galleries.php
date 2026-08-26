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
        // ITERATION-1 FIX (consolidated-migration coexistence): rollback
        // runs additive migrations' down() in reverse batch order — the
        // target table may already be gone (owned by the consolidated
        // migration that runs later in the same batch on fresh installs).
        if (! Schema::hasTable('galleries')) {
            return;
        }
        // ITERATION-1 FIX (empty temp-table guard): compute the drop set
        // first; when it covers every remaining column (consolidated
        // rollback already removed the base columns), dropping the table is
        // the only valid SQLite translation.
        $drop = array_values(array_filter(
            ['is_featured', 'curtain_logo_path', 'curtain_bg_color'],
            fn ($col) => Schema::hasColumn('galleries', $col),
        ));
        if ($drop === []) {
            return;
        }
        $remaining = collect(Schema::getColumnListing('galleries'))
            ->diff($drop)->values()->all();
        if ($remaining === []) {
            Schema::dropIfExists('galleries');
            return;
        }
        Schema::table('galleries', function (Blueprint $table) use ($drop) {
            if (in_array('is_featured', $drop, true)) {
                $table->dropIndex(['is_featured']);
                $table->dropColumn('is_featured');
            }
            if (in_array('curtain_logo_path', $drop, true)) {
                $table->dropColumn('curtain_logo_path');
            }
            if (in_array('curtain_bg_color', $drop, true)) {
                $table->dropColumn('curtain_bg_color');
            }
        });
    }
};
