<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Gallery;
use App\Models\GalleryImage;
use App\Models\Team;
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
        try {
            // 1. SECURITY: Check Ownership (personal AND team galleries)
            $this->authorizeGalleryAccess($gallery);

            // 2. LIMIT CHECK: Check User Plan Limits (BEFORE gallery limit)
            $user = auth()->user();
            $maxImages = $user->max_images ?? 10;
            $currentCount = $gallery->images()->count();

            // Cross-gallery limit: count ALL images the user owns across ALL personal galleries
            $totalUserImages = \DB::table('gallery_images')
                ->join('galleries', 'galleries.id', '=', 'gallery_images.gallery_id')
                ->where('galleries.user_id', $user->id)
                ->count();

            if ($totalUserImages >= $maxImages || $currentCount >= $maxImages) {
                Log::info("Plan limit reached for User " . auth()->id() . " (Plan: " . (auth()->user()->plan ?? 'free') . ")");
                return response()->json([
                    'error' => "Plan limit reached ({$maxImages} images). Upgrade to Pro to upload more."
                ], 422);
            }

            // 3. HARD LIMIT: 100 Images Max (System Safety)
            if ($currentCount >= 100) {
                Log::warning("System limit reached for gallery {$gallery->id}");
                return response()->json([
                    'error' => 'System limit reached (100 images per gallery).'
                ], 422);
            }

            // 4. Validation with detailed error messages
            $validator = \Validator::make($request->all(), [
                'file' => 'required|file|image|mimes:jpeg,png,jpg,webp|max:10240', // Increased to 10MB
            ], [
                'file.required' => 'No file was uploaded.',
                'file.file' => 'The uploaded item is not a valid file.',
                'file.image' => 'The file must be an image.',
                'file.mimes' => 'Only JPEG, PNG, JPG, and WEBP images are allowed.',
                'file.max' => 'Image size must not exceed 10MB.',
            ]);

            if ($validator->fails()) {
                $errors = $validator->errors()->all();
                Log::warning("Image validation failed: " . implode(', ', $errors));
                return response()->json([
                    'error' => $errors[0], // Return first error
                    'details' => $errors
                ], 422);
            }

            $file = $request->file('file');
            
            // 5. Additional file checks
            if (!$file->isValid()) {
                Log::error("Invalid file upload: " . $file->getErrorMessage());
                return response()->json([
                    'error' => 'File upload failed: ' . $file->getErrorMessage()
                ], 422);
            }

            // 6. Check actual file size (in case PHP limits are hit)
            $fileSize = $file->getSize();
            if ($fileSize === false || $fileSize === 0) {
                Log::error("File size is 0 or cannot be determined");
                return response()->json([
                    'error' => 'File appears to be empty or corrupted.'
                ], 422);
            }

            // Log file info for debugging
            Log::info("Processing image upload", [
                'filename' => $file->getClientOriginalName(),
                'size' => round($fileSize / 1024 / 1024, 2) . 'MB',
                'mime' => $file->getMimeType(),
                'gallery_id' => $gallery->id,
                'current_count' => $currentCount
            ]);

            // 7. Process Image via Service
            $data = $this->imageService->process($file, $gallery->id);

            // 8. Calculate Orientation
            $ratio = $data['width'] / $data['height'];
            $orientation = 'square';
            if ($ratio > 1.1) $orientation = 'landscape';
            if ($ratio < 0.9) $orientation = 'portrait';

            // 9. Get next position order
            $nextPosition = $gallery->images()->max('position_order') + 1;

            // 10. Save to Database
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

            Log::info("Image uploaded successfully: {$image->id}");

            return response()->json([
                'success' => true,
                'id' => $image->id,
                'path' => asset($image->path)
            ]);

        } catch (\Exception $e) {
            Log::error('Image Upload Error: ' . $e->getMessage(), [
                'file' => $request->hasFile('file') ? $request->file('file')->getClientOriginalName() : 'no file',
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'error' => 'Upload failed: ' . $e->getMessage()
            ], 500);
        }
    }

    public function destroy(GalleryImage $image)
    {
        // Security: Ensure user owns or is a team member of this gallery
        $this->authorizeGalleryAccess($image->gallery);

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
                
                if (!$image) {
                    $errors[] = "Image {$id}: Not found";
                    continue;
                }
                try {
                    $this->authorizeGalleryAccess($image->gallery);
                } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
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

    private function authorizeGalleryAccess(Gallery $gallery): void
    {
        $user = auth()->user();
        if ($gallery->team_id) {
            $team = $gallery->team;
            if (!$team || !$user->belongsToTeam($team)) {
                abort(403);
            }
        } else {
            if ($gallery->user_id !== $user->id) {
                abort(403);
            }
        }
    }
}