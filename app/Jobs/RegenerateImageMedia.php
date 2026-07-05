<?php

namespace App\Jobs;

use App\Models\GalleryImage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Regenerate Spatie Media Library conversions for existing GalleryImage
 * rows that were uploaded before the H21/H25 responsive-image integration.
 *
 * Usage:
 *   php artisan tinker
 *   >>> App\Models\GalleryImage::whereDoesntHave('media')->chunkById(50, function($images) {
 *   ...     foreach ($images as $image) {
 *   ...         App\Jobs\RegenerateImageMedia::dispatch($image);
 *   ...     }
 *   ... });
 *
 * P1-10 FIX (audit): Previously, the catch block swallowed ALL exceptions
 * — the job always reported success and never reached failed_jobs. Failed
 * regenerations sat silently in laravel.log with no retry path. Now the
 * exception is re-thrown so the job lands in failed_jobs and can be
 * retried via `php artisan queue:retry`.
 *
 * Also fixed: str_replace → Str::after for path stripping (same fix as
 * P0-1/P0-4 in PlanDowngradeService/UserDeletionService).
 */
class RegenerateImageMedia implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries = 1;

    public function __construct(
        public GalleryImage $image
    ) {}

    public function handle(): void
    {
        // Skip if already has media
        if ($this->image->hasMedia('original')) {
            Log::info('RegenerateImageMedia: skipping — already has media', [
                'image_id' => $this->image->id,
            ]);
            return;
        }

        // P1-10: Use Str::after instead of str_replace (same fix as P0-1/P0-4)
        $relativePath = \Illuminate\Support\Str::after($this->image->path, 'storage/');
        $fullPath = Storage::disk('public')->path($relativePath);

        if (! file_exists($fullPath)) {
            Log::warning('RegenerateImageMedia: file not found on disk', [
                'image_id' => $this->image->id,
                'path'     => $fullPath,
            ]);
            // P1-10: Don't swallow — fail the job so it's visible in failed_jobs.
            // The file may have been deleted by the user; failing the job lets
            // ops decide whether to clean up the GalleryImage row or re-upload.
            throw new \RuntimeException("RegenerateImageMedia: file not found on disk for image {$this->image->id}: {$fullPath}");
        }

        // P1-10: Re-throw exceptions instead of swallowing them.
        // The job will land in failed_jobs and can be retried via
        // `php artisan queue:retry`. Previously, failures were silently
        // logged and the job reported success — no retry path, no alerting.
        $this->image->addMedia($fullPath)
            ->usingFileName($this->image->filename)
            ->toMediaCollection('original');

        Log::info('RegenerateImageMedia: registered media', [
            'image_id' => $this->image->id,
        ]);
    }
}
