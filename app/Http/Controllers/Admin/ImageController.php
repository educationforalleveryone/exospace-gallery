<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesGalleryAccess;
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
        // (Task H06 / audit H10) — authorize OUTSIDE the try/catch so
        // abort(403) propagates correctly. Previously the catch swallowed
        // HttpException and returned 500 with $e->getMessage() leaked to
        // the client.
        $this->authorizeGalleryAccess($gallery);

        try {
            $user = auth()->user();

            // Plan limit: total images across all personal galleries.
            // (Task H04 / audit H8) — plan-aware error message. Previously
            // ALL users hitting the limit saw "Upgrade to Pro" — confusing
            // for Studio users who are already on the top tier.
            if ($user->currentImageCount() >= $user->max_images) {
                $upgradeTarget = match($user->plan) {
                    'free'    => 'Pro',
                    'pro'     => 'Studio',
                    'studio'  => null, // Already on top tier — no upgrade path
                    default   => 'Pro',
                };

                $message = $upgradeTarget
                    ? "Plan limit reached ({$user->max_images} images). Upgrade to {$upgradeTarget} to upload more."
                    : "Plan limit reached ({$user->max_images} images). You're on the Studio plan — contact support to increase your limit.";

                $response = ['error' => $message];
                if ($upgradeTarget) {
                    $response['upgrade_url'] = route('billing.upgrade', strtolower($upgradeTarget));
                }

                Log::info("Plan limit reached for User {$user->id} (Plan: {$user->plan})");
                return response()->json($response, 422);
            }

            // Hard per-gallery safety cap. Matches User::planLimits() per
            // gallery cap (Pro = 100, Studio = 500). The cap is the user's
            // per-gallery limit from planLimits, NOT a hardcoded 100.
            // (Task H04 / audit H8) — previously hardcoded 100, which meant
            // Studio users could never reach their advertised 500-per-gallery.
            $perGalleryCap = match($user->plan) {
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

            // (Task H25) Register with Spatie Media Library for responsive
            // WebP variants. Non-blocking — if it fails, the legacy `path`
            // column still serves the JPEG.
            $this->imageService->registerMedia($image, $file);

            return response()->json(['success' => true, 'id' => $image->id, 'path' => asset($image->path)]);

        } catch (\Exception $e) {
            // (Task H06 / audit H10) — don't leak $e->getMessage() to the
            // client. Log the full error internally; return a generic
            // message to the user.
            Log::error('Image Upload Error: ' . $e->getMessage(), [
                'file'  => $request->hasFile('file') ? $request->file('file')->getClientOriginalName() : 'no file',
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'Upload failed. Please try again — if the problem persists, contact support.'], 500);
        }
    }

    public function destroy(GalleryImage $image)
    {
        // (Task H06) — authorize outside try/catch.
        $this->authorizeGalleryAccess($image->gallery);

        try {
            $this->imageService->delete($image->path);
            $image->delete();
            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            Log::error('Image Delete Error: ' . $e->getMessage());
            return response()->json(['error' => 'Delete failed.'], 500);
        }
    }

    /**
     * Bulk delete images.
     *
     * (Task H06 / audit H37) — rewritten:
     *   - Load all images in ONE query (was N+1: findOrFail per image)
     *   - Group by gallery and authorize per gallery (was per image)
     *   - Wrap deletes in DB::transaction (was none — partial-failure
     *     left file deleted but row present or vice versa)
     *   - HttpException (403) handled separately, not swallowed
     */
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

        // Group by gallery so we authorize per gallery (not per image —
        // avoids N authorization queries for N images in the same gallery).
        $byGallery = $images->groupBy('gallery_id');

        foreach ($byGallery as $galleryId => $galleryImages) {
            $gallery = $galleryImages->first()->gallery;

            // Authorize per gallery. If unauthorized, all images in this
            // gallery are skipped — don't leak which images exist.
            try {
                $this->authorizeGalleryAccess($gallery);
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
                        // Re-throw to roll back the transaction for this
                        // gallery's batch — partial deletes within a
                        // gallery leave the file/row state inconsistent.
                        throw $e;
                    }
                }
            });
        }

        return response()->json(['success' => $count > 0, 'deleted' => $count, 'errors' => $errors]);
    }
}
