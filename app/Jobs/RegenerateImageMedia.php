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
 * (Task H25 follow-up) — existing images have a `path` column pointing at
 * a JPEG file on the public disk, but no Spatie media record. This job
 * reads the file from disk and registers it with Spatie so the WebP
 * conversions (thumb, small, medium, large) are generated.
 *
 * Usage:
 *   php artisan tinker
 *   >>> App\Models\GalleryImage::whereDoesntHave('media')->chunkById(50, function($images) {
 *   ...     foreach ($images as $image) {
 *   ...         App\Jobs\RegenerateImageMedia::dispatch($image);
 *   ...     }
 *   ... });
 *
 * Or run synchronously:
 *   >>> App\Models\GalleryImage::whereDoesntHave('media')->chunkById(50, function($images) {
 *   ...     foreach ($images as $image) App\Jobs\RegenerateImageMedia::dispatchSync($image);
 *   ... });
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

        // Resolve the file path from the legacy `path` column
        $relativePath = str_replace('storage/', '', $this->image->path);
        $fullPath = Storage::disk('public')->path($relativePath);

        if (! file_exists($fullPath)) {
            Log::warning('RegenerateImageMedia: file not found on disk', [
                'image_id' => $this->image->id,
                'path'     => $fullPath,
            ]);
            return;
        }

        try {
            $this->image->addMedia($fullPath)
                ->usingFileName($this->image->filename)
                ->toMediaCollection('original');

            Log::info('RegenerateImageMedia: registered media', [
                'image_id' => $this->image->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('RegenerateImageMedia: failed', [
                'image_id' => $this->image->id,
                'error'    => $e->getMessage(),
            ]);
        }
    }
}
