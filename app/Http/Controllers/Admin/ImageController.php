<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesGalleryAccess;
use App\Models\AdminAuditLog;
use App\Models\Gallery;
use App\Models\GalleryImage;
use App\Services\ImageProcessingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ImageController extends Controller
{
    use AuthorizesGalleryAccess;

    public function __construct(protected ImageProcessingService $imageService) {}

    public function store(Request $request, Gallery $gallery)
    {
        $this->authorizeGalleryAccess($gallery, requireEdit: true);

        try {
            $user = auth()->user();

            $planHolder = $gallery->team_id ? $gallery->team->owner : $user;

            if ($planHolder->currentImageCount() >= $planHolder->max_images) {
                $upgradeTarget = match($planHolder->plan) {
                    'free'    => 'Pro',
                    'pro'     => 'Studio',
                    'studio'  => null, // Already on top tier — no upgrade path
                    default   => 'Pro',
                };

                $message = $upgradeTarget
                    ? "Plan limit reached ({$planHolder->max_images} images). Upgrade to {$upgradeTarget} to upload more."
                    : "Plan limit reached ({$planHolder->max_images} images). You're on the Studio plan — contact support to increase your limit.";

                $response = ['error' => $message];
                if ($upgradeTarget) {
                    $response['upgrade_url'] = route('billing.upgrade', strtolower($upgradeTarget));
                }

                Log::info("Plan limit reached for plan holder {$planHolder->id} (Plan: {$planHolder->plan})", [
                    'gallery_id'   => $gallery->id,
                    'uploaded_by'  => $user->id,
                ]);
                return response()->json($response, 422);
            }

            $perGalleryCap = match($planHolder->plan) {
                'studio'  => 500,
                'pro'     => 100,
                default   => 10,
            };
            $currentCount = $gallery->images()->count();
            if ($currentCount >= $perGalleryCap) {
                return response()->json([
                    'error' => "Per-gallery limit reached ({$perGalleryCap} images for your plan).",
                ], 422);
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

            $this->imageService->registerMedia($image, $file);

            return response()->json(['success' => true, 'id' => $image->id, 'path' => asset($image->path)]);

        } catch (\App\Exceptions\ImageTooLargeException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Image Upload Error: ' . $e->getMessage(), [
                'file'  => $request->hasFile('file') ? $request->file('file')->getClientOriginalName() : 'no file',
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'Upload failed. Please try again — if the problem persists, contact support.'], 500);
        }
    }

    public function destroy(GalleryImage $image)
    {
        $this->authorizeGalleryAccess($image->gallery, requireEdit: true);

        try {
            $this->imageService->delete($image->path);
            $image->delete();
            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            Log::error('Image Delete Error: ' . $e->getMessage());
            return response()->json(['error' => 'Delete failed.'], 500);
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

        // Load all images in one query with their galleries.
        $images = GalleryImage::with('gallery')->whereIn('id', $request->ids)->get()->keyBy('id');

        $byGallery = $images->groupBy('gallery_id');

        foreach ($byGallery as $galleryId => $galleryImages) {
            $gallery = $galleryImages->first()->gallery;

            try {
                $this->authorizeGalleryAccess($gallery, requireEdit: true);
            } catch (HttpException $e) {
                foreach ($galleryImages as $image) {
                    $errors[] = "Image {$image->id}: Unauthorized";
                }
                continue;
            }

            // Delete all images in this gallery atomically.
            DB::transaction(function () use ($galleryImages, &$count, &$errors) {
                foreach ($galleryImages as $image) {
                    try {
                        $this->imageService->delete($image->path);
                        $image->delete();
                        $count++;
                    } catch (\Exception $e) {
                        $errors[] = "Image {$image->id}: " . $e->getMessage();
                        Log::error("Bulk delete error for image {$image->id}: " . $e->getMessage());
                        throw $e;
                    }
                }
            });
        }

        AdminAuditLog::record('gallery.images.bulk_deleted', auth()->user(), [
            'gallery_ids'     => $byGallery->keys()->toArray(),
            'image_ids'       => $images->keys()->toArray(),
            'requested_count' => count($request->ids),
            'deleted_count'   => $count,
            'error_count'     => count($errors),
        ]);

        return response()->json(['success' => $count > 0, 'deleted' => $count, 'errors' => $errors]);
    }
}
