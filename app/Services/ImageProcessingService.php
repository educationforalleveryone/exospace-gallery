<?php

namespace App\Services;

use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ImageProcessingService
{
    protected ImageManager $manager;

    public function __construct()
    {
        // Initialize Intervention Image with GD driver
        $this->manager = new ImageManager(new Driver());
    }

    /**
     * Process uploaded image: resize, thumbnail, and save
     */
    public function process(UploadedFile $file, int $galleryId): array
    {
        $filename = uniqid() . '.' . $file->getClientOriginalExtension();
        $path = "galleries/{$galleryId}";
        
        // Ensure directories exist
        Storage::disk('public')->makeDirectory($path);
        Storage::disk('public')->makeDirectory("{$path}/thumbnails");

        // 1. Read image
        $image = $this->manager->read($file);
        
        // 2. Correct orientation (Removed: v3 handles this automatically on read usually, 
        // or we skip strict orientation for now as it's not present in the base interface)

        // 3. Resize Main Image (Max 2048x2048 for Three.js texture limits)
        if ($image->width() > 2048 || $image->height() > 2048) {
            $image->scaleDown(width: 2048, height: 2048);
        }
        
        // Save Main Image
        $mainPath = "{$path}/{$filename}";
        Storage::disk('public')->put($mainPath, (string) $image->toJpeg(85)); // Encode as JPEG

        // 4. Create Thumbnail (400x400 crop for Admin UI)
        $thumbnail = $this->manager->read($file); // Re-read to ensure clean state
        $thumbnail->cover(400, 400);
        
        // Save Thumbnail
        $thumbPath = "{$path}/thumbnails/{$filename}";
        Storage::disk('public')->put($thumbPath, (string) $thumbnail->toJpeg(80));

        return [
            'filename' => $filename,
            'path' => "storage/{$mainPath}",
            'thumbnail' => "storage/{$thumbPath}",
            'width' => $image->width(),
            'height' => $image->height(),
            'size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
        ];
    }

    public function delete(string $path): void
    {
        // Remove storage/ prefix if present for Storage facade
        $relativePath = str_replace('storage/', '', $path);
        
        if (Storage::disk('public')->exists($relativePath)) {
            Storage::disk('public')->delete($relativePath);
            
            // Try to delete thumbnail too
            $thumbPath = dirname($relativePath) . '/thumbnails/' . basename($relativePath);
            if (Storage::disk('public')->exists($thumbPath)) {
                Storage::disk('public')->delete($thumbPath);
            }
        }
    }
}