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
        // (Task H06 / audit H10) — authorize OUTSIDE the try/catch so
        // abort(403) propagates correctly. Previously the catch swallowed
        // HttpException and returned 500 with $e->getMessage() leaked to
        // the client.
        //
        // ITERATION-1 P0 SECURITY FIX: was view-level — a team "viewer"
        // could upload images to team galleries. GalleryPolicy::uploadMedia
        // requires owner/editor. Matches the gallery-settings permission
        // model (GalleryEventController already required edit).
        $this->authorizeGalleryAccess($gallery, requireEdit: true);

        try {
            $user = auth()->user();

            // ITERATION-1 FIX (entitlement consistency): limits are billed
            // against the PLAN HOLDER — the team owner for team galleries,
            // the acting user for personal galleries. Previously the
            // UPLOADER's plan was checked: a Free-plan editor uploading to
            // a Studio team owner's gallery was wrongly blocked at 10
            // images, while the same team owner's own uploads were counted
            // against a different bucket than the gallery limit — the
            // same entitlement enforced two different ways.
            $planHolder = $gallery->team_id ? $gallery->team->owner : $user;

            // Plan limit: total images across all of the plan holder's galleries.
            // (Task H04 / audit H8) — plan-aware error message. Previously
            // ALL users hitting the limit saw "Upgrade to Pro" — confusing
            // for Studio users who are already on the top tier.
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

            // Per-gallery safety cap, from the PLAN HOLDER's tier (same
            // match as config/plans.php — a follow-up iteration should move
            // this to a single planLimits() source to eliminate the
            // duplication).
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

            // (Task H25) Register with Spatie Media Library for responsive
            // WebP variants. Non-blocking — if it fails, the legacy `path`
            // column still serves the JPEG.
            $this->imageService->registerMedia($image, $file);

            return response()->json(['success' => true, 'id' => $image->id, 'path' => asset($image->path)]);

        } catch (\App\Exceptions\ImageTooLargeException $e) {
            // P3-12: Pre-decode dimension cap was exceeded. Return a 422 with
            // a user-friendly message that tells them the actual dimensions
            // and the limit, so they can resize and retry.
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
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
        // ITERATION-1 P0 SECURITY FIX: was view-level — a team "viewer"
        // could delete artworks from team galleries. Editor only.
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
            // ITERATION-1 P0 SECURITY FIX: requireEdit — a team "viewer"
            // could bulk-delete artworks. Editor/owner only.
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
                        // Re-throw to roll back the transaction for this
                        // gallery's batch — partial deletes within a
                        // gallery leave the file/row state inconsistent.
                        throw $e;
                    }
                }
            });
        }

        // AUDIT-P1-4.15: Log bulk image deletion. Single audit entry per
        // request (not per image) to avoid log flooding. Placed OUTSIDE the
        // per-gallery DB::transaction calls so the audit entry survives even
        // if a batch rolls back — critical for forensic visibility.
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
