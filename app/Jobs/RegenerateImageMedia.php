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

class RegenerateImageMedia implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries = 1;

    public function __construct(
        public readonly int $imageId
    ) {}

    public function handle(): void
    {
        // Reload authoritative state instead of trusting a serialized model:
        // the image may have been deleted (or its media rebuilt) while this
        // job sat in the queue.
        $image = GalleryImage::find($this->imageId);

        if (! $image) {
            Log::info('RegenerateImageMedia: image not found (deleted?) — skipping', [
                'image_id' => $this->imageId,
            ]);
            return;
        }

        // Skip if already has media
        if ($image->hasMedia('original')) {
            Log::info('RegenerateImageMedia: skipping — already has media', [
                'image_id' => $image->id,
            ]);
            return;
        }

        // Use Str::after instead of str_replace so the path is split on the
        // FIRST 'storage/' occurrence only.
        $relativePath = \Illuminate\Support\Str::after($image->path, 'storage/');
        $fullPath = Storage::disk('public')->path($relativePath);

        if (! file_exists($fullPath)) {
            Log::warning('RegenerateImageMedia: file not found on disk', [
                'image_id' => $image->id,
                'path'     => $fullPath,
            ]);
            throw new \RuntimeException("RegenerateImageMedia: file not found on disk for image {$image->id}: {$fullPath}");
        }

        // preservingOriginal: addMedia() unlinks the source file by default.
        // The legacy file at $image->path is the fallback asset served when
        // media lookups fail, so it must survive media registration — same
        // behavior as ImageProcessingService::registerMedia().
        $image->addMedia($fullPath)
            ->preservingOriginal()
            ->usingFileName($image->filename)
            ->toMediaCollection('original');

        Log::info('RegenerateImageMedia: registered media', [
            'image_id' => $image->id,
        ]);
    }
}
