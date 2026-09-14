<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Stored start/end values are curator-entered wall-clock digits that the
    // application labelled UTC without ever applying the declared IANA
    // timezone. Reinterpret each value as wall-clock in its declared timezone
    // and normalise it to the UTC instant it was meant to represent, so the
    // stored instants and every timezone-aware renderer agree.
    public function up(): void
    {
        $shifted = 0;

        $events = DB::table('gallery_schedule_events')
            ->whereNotIn('timezone', ['UTC', 'utc', ''])
            ->get(['id', 'starts_at', 'ends_at', 'timezone']);

        foreach ($events as $event) {
            try {
                $timezone = new DateTimeZone($event->timezone);
            } catch (Throwable) {
                // Unknown identifier — timezone-aware rendering falls back to
                // UTC for this row, which keeps the current interpretation.
                continue;
            }

            $updates = ['updated_at' => now()];

            foreach (['starts_at', 'ends_at'] as $column) {
                $stored = $event->{$column};

                if (! $stored) {
                    continue;
                }

                try {
                    $wallClock = Carbon::createFromFormat('Y-m-d H:i:s', $stored, $timezone);
                } catch (Throwable) {
                    // Not a plain datetime string — leave the row untouched.
                    continue;
                }

                if (! $wallClock) {
                    continue;
                }

                $updates[$column] = $wallClock->utc()->format('Y-m-d H:i:s');
            }

            if (count($updates) === 1) {
                continue;
            }

            DB::table('gallery_schedule_events')->where('id', $event->id)->update($updates);
            $shifted++;
        }

        if ($shifted > 0) {
            try {
                // Schedule timestamps feed the events sitemap group; a bulk
                // data correction bypasses the model events that bump it.
                Cache::add('seo:sitemap:version', 1);
                Cache::increment('seo:sitemap:version');
            } catch (Throwable) {
                // Cache unavailable — sitemaps fall back to TTL-only staleness.
            }
        }
    }

    public function down(): void
    {
        //
    }
};
