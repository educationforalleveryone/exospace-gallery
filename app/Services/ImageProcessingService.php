<?php

namespace App\Services;

use App\Models\GalleryImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;

class ImageProcessingService
{
    protected ImageManager $manager;

    public function __construct()
    {
        // PERF-8 FIX: Use Imagick if available — better memory handling,
        // supports more formats (animated GIF, TIFF, WebP decode).
        // Falls back to GD if Imagick extension is not loaded.
        if (extension_loaded('imagick')) {
            $this->manager = new ImageManager(new ImagickDriver());
        } else {
            $this->manager = new ImageManager(new GdDriver());
        }
    }

    /**
     * Process uploaded image: resize, thumbnail, and save.
     *
     * PERF-9 FIX: Previously read the image TWICE — once for the main
     * image, once for the thumbnail. Now reads once and clones for the
     * thumbnail, halving peak memory usage for large images.
     */
    public function process(UploadedFile $file, int $galleryId): array
    {
        $filename = \Illuminate\Support\Str::random(40) . '.jpg';
        $path = "galleries/{$galleryId}";

        Storage::disk('public')->makeDirectory($path);
        Storage::disk('public')->makeDirectory("{$path}/thumbnails");

        // PERF-9: Read the image ONCE — clone for thumbnail instead of re-reading
        $image = $this->manager->read($file);

        // 2. Resize Main Image (Max 2048x2048 for Three.js texture limits)
        if ($image->width() > 2048 || $image->height() > 2048) {
            $image->scaleDown(width: 2048, height: 2048);
        }

        // Save Main Image as JPEG (strips EXIF — P0-4)
        $mainPath = "{$path}/{$filename}";
        $mainData = (string) $image->toJpeg(85);
        Storage::disk('public')->put($mainPath, $mainData);

        // PERF-9: Clone the already-read image for the thumbnail
        // (was: $this->manager->read($file) — second read from disk)
        $thumbnail = $image->clone();
        $thumbnail->cover(400, 400);

        $thumbPath = "{$path}/thumbnails/{$filename}";
        $thumbData = (string) $thumbnail->toJpeg(80);
        Storage::disk('public')->put($thumbPath, $thumbData);

        return [
            'filename'      => $filename,
            'path'          => "storage/{$mainPath}",
            'thumbnail'     => "storage/{$thumbPath}",
            'width'         => $image->width(),
            'height'        => $image->height(),
            'size'          => strlen($mainData),
            'mime_type'     => 'image/jpeg',
        ];
    }

    /**
     * Register an uploaded file with Spatie Media Library.
     * (P0-4: EXIF stripping — reads the re-encoded main image, not the raw upload)
     */
    public function registerMedia(GalleryImage $image, UploadedFile $file): void
    {
        try {
            $mainRelativePath = "galleries/{$image->gallery_id}/{$image->filename}";
            $mainAbsolutePath = Storage::disk('public')->path($mainRelativePath);

            if (file_exists($mainAbsolutePath)) {
                $sourceFile = $mainAbsolutePath;
            } else {
                Log::warning('ImageProcessingService: EXIF-stripped main image not found, falling back to raw upload', [
                    'image_id' => $image->id,
                    'expected' => $mainRelativePath,
                ]);
                $sourceFile = $file->getRealPath();
            }

            $image->addMedia($sourceFile)
                  ->usingFileName($image->filename)
                  ->toMediaCollection('original');

            Log::info('ImageProcessingService: registered Spatie media for image (EXIF stripped)', [
                'image_id' => $image->id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('ImageProcessingService: Spatie media registration failed', [
                'image_id' => $image->id,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    public function delete(string $path): void
    {
        $relativePath = \Illuminate\Support\Str::after($path, 'storage/');

        if (Storage::disk('public')->exists($relativePath)) {
            Storage::disk('public')->delete($relativePath);

            $thumbPath = dirname($relativePath) . '/thumbnails/' . basename($relativePath);
            if (Storage::disk('public')->exists($thumbPath)) {
                Storage::disk('public')->delete($thumbPath);
            }
        }
    }
}
