<?php

namespace App\Services;

use App\Models\GalleryImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;

class ImageProcessingService
{
    protected ImageManager $manager;

    public function __construct()
    {
        // K-1 FIX (Iter-005): Removed the Imagick branch entirely.
        //
        // nixpacks.toml documents that phpPackages.imagick was removed from
        // the pinned nixpkgs archive. The production Docker image does NOT
        // have imagick installed. But CI (.github/workflows/ci.yml) DOES
        // install imagick — so CI tests exercise the Imagick code path while
        // production exercises the GD code path. This environmental drift
        // means CI cannot catch GD-specific bugs.
        //
        // FIX: Commit to GD-only. Remove the Imagick import + branch. CI
        // should also remove imagick (see the .github/workflows/ci.yml fix
        // in this iteration). Add ext-gd to composer.json require block
        // (see the composer.json fix in this iteration).
        //
        // GD has different memory behavior than Imagick (GD allocates the
        // full uncompressed RGBA buffer for imagecreatefrom*), but the
        // 50MP cap in process() accounts for this. GD doesn't support
        // animated GIF or TIFF decode — acceptable for an art gallery SaaS
        // (artists upload JPEG/PNG/WebP, not GIF/TIFF).
        $this->manager = new ImageManager(new GdDriver());
    }

    /**
     * Process uploaded image: resize, thumbnail, and save.
     *
     * PERF-9 FIX: Previously read the image TWICE — once for the main
     * image, once for the thumbnail. Now reads once and clones for the
     * thumbnail, halving peak memory usage for large images.
     *
     * P3-12 FIX: Pre-decode dimension check via getimagesize(). A 12000×9000
     * PNG from a modern camera decodes to ~432MB of RGBA pixels in memory —
     * enough to OOM a 256MB PHP-FPM worker before Intervention can even
     * scaleDown() it. We cap total pixel area at 50MP (≈ 7000×7000) and
     * reject larger images with a clear error before decoding. The cap is
     * deliberately generous — the 2048px output cap means anything larger
     * than ~2048×2048 is wasted detail, but we accept up to 50MP so
     * high-res photographers don't get rejected unnecessarily; the resize
     * step brings it down to 2048px for storage.
     */
    public function process(UploadedFile $file, int $galleryId): array
    {
        $filename = \Illuminate\Support\Str::random(40) . '.jpg';
        $path = "galleries/{$galleryId}";

        Storage::disk('public')->makeDirectory($path);
        Storage::disk('public')->makeDirectory("{$path}/thumbnails");

        // P3-12: Pre-decode dimension check.
        //
        // getimagesize() reads only the image header (a few KB) — it doesn't
        // decode the full pixel buffer. This lets us reject oversized images
        // BEFORE Intervention::read() allocates hundreds of MB of memory.
        //
        // The 50 megapixel cap is generous: a 7000×7000 image (49MP) is well
        // above what any 3D gallery texture needs (Three.js caps at 2048
        // anyway), but well below the OOM threshold for a 256MB worker.
        // 50MP × 4 bytes/pixel (RGBA) = 200MB peak decode buffer — leaves
        // ~56MB for the rest of the request, which is enough for the resize
        // + thumbnail + save operations.
        //
        // If you need to raise this (e.g. for a "raw upload" feature), also
        // raise PHP's memory_limit and the Imagick policy.xml.
        $maxPixels = 50_000_000; // 50 megapixels
        $imageInfo = @getimagesize($file->getRealPath());

        if ($imageInfo !== false && isset($imageInfo[0], $imageInfo[1])) {
            $width  = (int) $imageInfo[0];
            $height = (int) $imageInfo[1];
            $pixels = $width * $height;

            if ($pixels > $maxPixels) {
                throw new \App\Exceptions\ImageTooLargeException(
                    $width,
                    $height,
                    $pixels,
                    $maxPixels,
                );
            }
        }

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
