<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Gallery;
use App\Models\VenueTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * C-7 FIX (Iter-009): Deferred gallery view-count increment.
 *
 * Previously GalleryViewController::show() called $gallery->increment('view_count')
 * AND $gallery->venueTemplate->incrementViewCount() synchronously on every
 * page view. Two UPDATE statements on hot rows per request. Under a viral
 * spike (50 req/s on one gallery), the galleries row is updated 50x/s —
 * InnoDB row-lock contention slows every request, and the analytics_events
 * table is already recording the view separately (so view_count is just a
 * denormalized cache, not the source of truth).
 *
 * This job is dispatched via dispatch()->afterResponse() so it runs after
 * the HTTP response is sent to the client — the user sees no latency, and
 * the increments no longer contend with reads on the galleries row.
 *
 * The job is idempotent: re-running it just increments again (the worst
 * case from a retry is a slightly over-counted view, which is fine for
 * a denormalized counter).
 *
 * ShouldQueue + ShouldBeUnique-ish behavior: we deliberately do NOT make
 * this unique, because we WANT each view to count. Uniqueness would
 * collapse concurrent views into one increment.
 */
class IncrementGalleryViews implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $galleryId,
        public readonly ?int $venueTemplateId = null,
    ) {}

    public function handle(): void
    {
        try {
            // Direct UPDATE — avoids the model's saved event firing, which
            // would re-dispatch analytics events. We're only updating the
            // denormalized counter, not the gallery record itself.
            Gallery::where('id', $this->galleryId)
                ->where('is_active', true)
                ->increment('view_count');

            if ($this->venueTemplateId !== null) {
                VenueTemplate::where('id', $this->venueTemplateId)
                    ->increment('view_count');
            }
        } catch (\Throwable $e) {
            // Don't fail the job — view-count increments are non-critical.
            // The analytics_events table is the source of truth anyway.
            Log::warning('IncrementGalleryViews: failed', [
                'gallery_id'         => $this->galleryId,
                'venue_template_id'  => $this->venueTemplateId,
                'error'              => $e->getMessage(),
            ]);
        }
    }
}
