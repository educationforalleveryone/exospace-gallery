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
        $this->manager = new ImageManager(new GdDriver());
    }

    public function process(UploadedFile $file, int $galleryId): array
    {
        $filename = \Illuminate\Support\Str::random(40) . '.jpg';
        $path = "galleries/{$galleryId}";

        Storage::disk('public')->makeDirectory($path);
        Storage::disk('public')->makeDirectory("{$path}/thumbnails");

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

        $thumbnail = clone $image;
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
                  ->preservingOriginal()
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
