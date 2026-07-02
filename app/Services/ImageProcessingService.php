<?php

namespace App\Services;

use App\Models\GalleryImage;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ImageProcessingService
{
    protected ImageManager $manager;

    public function __construct()
    {
        $this->manager = new ImageManager(new Driver());
    }

    /**
     * Process uploaded image: resize, thumbnail, and save.
     *
     * (Task H25) — now also fixes metadata inconsistencies from audit M1/M7/M8:
     *   - mime_type now reflects the re-encoded JPEG (was the upload's MIME)
     *   - size now reflects the re-encoded file (was the upload's size)
     *   - filename uses .jpg extension (was getgetClientOriginalExtension
     *     which trusted the user's filename)
     *
     * After the GalleryImage row is created, call registerMedia() to
     * add the file to Spatie's media collection for responsive WebP
     * conversion (Task H21).
     */
    public function process(UploadedFile $file, int $galleryId): array
    {
        // (Task H25 / audit M8) — use .jpg extension since we re-encode to JPEG.
        // Previously used getgetClientOriginalExtension() which trusted the
        // user's filename and could produce .php.jpg polyglot names.
        $filename = uniqid() . '.jpg';
        $path = "galleries/{$galleryId}";

        Storage::disk('public')->makeDirectory($path);
        Storage::disk('public')->makeDirectory("{$path}/thumbnails");

        // 1. Read image
        $image = $this->manager->read($file);

        // 2. Resize Main Image (Max 2048x2048 for Three.js texture limits)
        if ($image->width() > 2048 || $image->height() > 2048) {
            $image->scaleDown(width: 2048, height: 2048);
        }

        // Save Main Image as JPEG
        $mainPath = "{$path}/{$filename}";
        $mainData = (string) $image->toJpeg(85);
        Storage::disk('public')->put($mainPath, $mainData);

        // 4. Create Thumbnail (400x400 crop for Admin UI)
        $thumbnail = $this->manager->read($file);
        $thumbnail->cover(400, 400);

        $thumbPath = "{$path}/thumbnails/{$filename}";
        $thumbData = (string) $thumbnail->toJpeg(80);
        Storage::disk('public')->put($thumbPath, $thumbData);

        // (Task H25 / audit M1, M7) — return metadata from the RE-ENCODED
        // artifact, not the original upload. Previously mime_type was the
        // upload's MIME (e.g. image/png) and size was the upload's size,
        // but the actual file on disk was image/jpeg at a different size.
        return [
            'filename'      => $filename,
            'path'          => "storage/{$mainPath}",
            'thumbnail'     => "storage/{$thumbPath}",
            'width'         => $image->width(),
            'height'        => $image->height(),
            'size'          => strlen($mainData),              // re-encoded size (was $file->getSize())
            'mime_type'     => 'image/jpeg',                   // re-encoded MIME (was $file->getMimeType())
        ];
    }

    /**
     * Register an uploaded file with Spatie Media Library for responsive
     * WebP conversion. (Task H25)
     *
     * Call this AFTER the GalleryImage row is created:
     *
     *   $data = $this->imageService->process($file, $gallery->id);
     *   $image = $gallery->images()->create($data);
     *   $this->imageService->registerMedia($image, $file);
     *
     * The Spatie conversions (thumb, small, medium, large — all WebP)
     * are registered in GalleryImage::registerMediaConversions() and
     * run via the queue (except thumb which is nonQueued).
     */
    public function registerMedia(GalleryImage $image, UploadedFile $file): void
    {
        try {
            $image->addMedia($file)
                  ->usingFileName($image->filename)
                  ->toMediaCollection('original');

            Log::info('ImageProcessingService: registered Spatie media for image', [
                'image_id' => $image->id,
            ]);
        } catch (\Throwable $e) {
            // Don't fail the upload — the legacy `path` column still works.
            // Responsive WebP variants just won't be available for this image.
            Log::warning('ImageProcessingService: Spatie media registration failed', [
                'image_id' => $image->id,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    public function delete(string $path): void
    {
        $relativePath = str_replace('storage/', '', $path);

        if (Storage::disk('public')->exists($relativePath)) {
            Storage::disk('public')->delete($relativePath);

            $thumbPath = dirname($relativePath) . '/thumbnails/' . basename($relativePath);
            if (Storage::disk('public')->exists($thumbPath)) {
                Storage::disk('public')->delete($thumbPath);
            }
        }
    }
}
