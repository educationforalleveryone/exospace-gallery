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
            Gallery::where('id', $this->galleryId)
                ->where('is_active', true)
                ->increment('view_count');

            if ($this->venueTemplateId !== null) {
                VenueTemplate::where('id', $this->venueTemplateId)
                    ->increment('view_count');
            }
        } catch (\Throwable $e) {
            Log::warning('IncrementGalleryViews: failed', [
                'gallery_id'         => $this->galleryId,
                'venue_template_id'  => $this->venueTemplateId,
                'error'              => $e->getMessage(),
            ]);
        }
    }
}
