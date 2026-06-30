<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Renames the analytics table from `gallery_events` to `analytics_events`.
 *
 * The original name was a misnomer — the table stores analytics events
 * (view, focus, tour_start, dwell, etc.), NOT actual calendar events
 * like opening receptions or artist talks. Round 4 introduces a new
 * `gallery_schedule_events` table for actual events, so we need to
 * free up the name.
 *
 * This migration:
 *   1. Renames the table
 *   2. Updates the GalleryEvent model binding (handled by renaming the
 *      model file to AnalyticsEvent.php and updating the $table property)
 *
 * The AnalyticsController already references `GalleryEvent::class` —
 * we'll update that to `AnalyticsEvent::class` in the controller patch.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('gallery_events') && !Schema::hasTable('analytics_events')) {
            Schema::rename('gallery_events', 'analytics_events');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('analytics_events') && !Schema::hasTable('gallery_events')) {
            Schema::rename('analytics_events', 'gallery_events');
        }
    }
};
