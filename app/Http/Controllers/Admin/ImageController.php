<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesGalleryAccess;
use App\Models\Gallery;
use App\Models\GalleryImage;
use App\Services\ImageProcessingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ImageController extends Controller
{
    use AuthorizesGalleryAccess;

    public function __construct(protected ImageProcessingService $imageService) {}

    public function store(Request $request, Gallery $gallery)
    {
        try {
            $this->authorizeGalleryAccess($gallery);

            $user = auth()->user();

            // Plan limit: total images across all personal galleries
            if ($user->currentImageCount() >= $user->max_images) {
                Log::info("Plan limit reached for User {$user->id} (Plan: {$user->plan})");
                return response()->json([
                    'error' => "Plan limit reached ({$user->max_images} images). Upgrade to Pro to upload more."
                ], 422);
            }

            // Hard per-gallery safety cap
            $currentCount = $gallery->images()->count();
            if ($currentCount >= 100) {
                return response()->json(['error' => 'System limit reached (100 images per gallery).'], 422);
            }

            $request->validate([
                'file' => 'required|file|image|mimes:jpeg,png,jpg,webp|max:10240',
            ], [
                'file.required' => 'No file was uploaded.',
                'file.image'    => 'The file must be an image.',
                'file.mimes'    => 'Only JPEG, PNG, JPG, and WEBP images are allowed.',
                'file.max'      => 'Image size must not exceed 10MB.',
            ]);

            $file = $request->file('file');
            $data = $this->imageService->process($file, $gallery->id);

            $ratio       = $data['width'] / $data['height'];
            $orientation = match(true) {
                $ratio > 1.1 => 'landscape',
                $ratio < 0.9 => 'portrait',
                default      => 'square',
            };

            $image = $gallery->images()->create([
                'filename'       => $data['filename'],
                'original_name'  => $file->getClientOriginalName(),
                'path'           => $data['path'],
                'mime_type'      => $data['mime_type'],
                'size'           => $data['size'],
                'width'          => $data['width'],
                'height'         => $data['height'],
                'orientation'    => $orientation,
                'position_order' => ($gallery->images()->max('position_order') ?? 0) + 1,
            ]);

            return response()->json(['success' => true, 'id' => $image->id, 'path' => asset($image->path)]);

        } catch (\Exception $e) {
            Log::error('Image Upload Error: ' . $e->getMessage(), [
                'file'  => $request->hasFile('file') ? $request->file('file')->getClientOriginalName() : 'no file',
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'Upload failed: ' . $e->getMessage()], 500);
        }
    }

    public function destroy(GalleryImage $image)
    {
        $this->authorizeGalleryAccess($image->gallery);

        try {
            $this->imageService->delete($image->path);
            $image->delete();
            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            Log::error('Image Delete Error: ' . $e->getMessage());
            return response()->json(['error' => 'Delete failed'], 500);
        }
    }

    public function bulkDestroy(Request $request)
    {
        $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => 'required|integer|exists:gallery_images,id',
        ]);

        $count  = 0;
        $errors = [];

        foreach ($request->ids as $id) {
            try {
                $image = GalleryImage::findOrFail($id);

                try {
                    $this->authorizeGalleryAccess($image->gallery);
                } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                    $errors[] = "Image {$id}: Unauthorized";
                    continue;
                }

                $this->imageService->delete($image->path);
                $image->delete();
                $count++;

            } catch (\Exception $e) {
                $errors[] = "Image {$id}: " . $e->getMessage();
                Log::error("Bulk delete error for image {$id}: " . $e->getMessage());
            }
        }

        return response()->json(['success' => $count > 0, 'deleted' => $count, 'errors' => $errors]);
    }
}
