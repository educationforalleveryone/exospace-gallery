<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Gallery;
use App\Models\GalleryImage;
use App\Services\ImageProcessingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ImageController extends Controller
{
    protected ImageProcessingService $imageService;

    public function __construct(ImageProcessingService $imageService)
    {
        $this->imageService = $imageService;
    }

    public function store(Request $request, Gallery $gallery)
    {
        // 1. Validation
        $request->validate([
            'file' => 'required|image|mimes:jpeg,png,jpg,webp|max:5120', // Max 5MB
        ]);

        // 2. Check gallery image limit (prevent performance issues)
        if ($gallery->images()->count() >= 100) {
            return response()->json([
                'error' => 'Gallery limit reached. Maximum 100 images per gallery.'
            ], 422);
        }

        try {
            // 3. Process Image via Service
            $file = $request->file('file');
            $data = $this->imageService->process($file, $gallery->id);

            // 4. Calculate Orientation
            $ratio = $data['width'] / $data['height'];
            $orientation = 'square';
            if ($ratio > 1.1) $orientation = 'landscape';
            if ($ratio < 0.9) $orientation = 'portrait';

            // 5. Get next position order
            $nextPosition = $gallery->images()->max('position_order') + 1;

            // 6. Save to Database
            $image = $gallery->images()->create([
                'filename' => $data['filename'],
                'original_name' => $file->getClientOriginalName(),
                'path' => $data['path'],
                'mime_type' => $data['mime_type'],
                'size' => $data['size'],
                'width' => $data['width'],
                'height' => $data['height'],
                'orientation' => $orientation,
                'position_order' => $nextPosition ?? 1,
            ]);

            // Allow file system to complete writes
            usleep(100000); // 100ms delay

            return response()->json([
                'success' => true,
                'id' => $image->id,
                'path' => asset($image->path)
            ]);

        } catch (\Exception $e) {
            Log::error('Image Upload Error: ' . $e->getMessage());
            return response()->json(['error' => 'Upload failed: ' . $e->getMessage()], 500);
        }
    }

    public function destroy(GalleryImage $image)
    {
        // Security: Ensure user owns the gallery
        if ($image->gallery->user_id !== auth()->id()) {
            abort(403);
        }

        try {
            // Delete file from disk
            $this->imageService->delete($image->path);
            
            // Delete record
            $image->delete();

            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            Log::error('Image Delete Error: ' . $e->getMessage());
            return response()->json(['error' => 'Delete failed'], 500);
        }
    }

    /**
     * Bulk delete images
     */
    public function bulkDestroy(Request $request)
    {
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'required|integer|exists:gallery_images,id'
        ]);

        $count = 0;
        $errors = [];
        
        foreach ($request->ids as $id) {
            try {
                $image = GalleryImage::find($id);
                
                // Security: ensure user owns the gallery
                if (!$image || $image->gallery->user_id !== auth()->id()) {
                    $errors[] = "Image {$id}: Unauthorized";
                    continue;
                }
                
                // Delete file from disk
                $this->imageService->delete($image->path);
                
                // Delete database record
                $image->delete();
                $count++;
                
            } catch (\Exception $e) {
                $errors[] = "Image {$id}: " . $e->getMessage();
                Log::error("Bulk delete error for image {$id}: " . $e->getMessage());
            }
        }

        return response()->json([
            'success' => $count > 0,
            'deleted' => $count,
            'errors' => $errors
        ]);
    }
}