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
            throw new \RuntimeException("RegenerateImageMedia: file not found on disk for image {$this->image->id}: {$fullPath}");
        }

        $this->image->addMedia($fullPath)
            ->usingFileName($this->image->filename)
            ->toMediaCollection('original');

        Log::info('RegenerateImageMedia: registered media', [
            'image_id' => $this->image->id,
        ]);
    }
}
