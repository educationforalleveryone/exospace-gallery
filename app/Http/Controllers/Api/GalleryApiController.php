<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Gallery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * M-28: Public REST API for galleries.
 *
 * Read-only endpoints for browsing publicly viewable galleries + their images.
 * No auth required — rate-limited at 60 req/min/IP.
 */
class GalleryApiController extends Controller
{
    /**
     * List publicly viewable galleries (paginated).
     * GET /api/v1/galleries?per_page=20&sort=newest|views|featured
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 20), 100);
        $sort = $request->query('sort', 'featured');

        $query = Gallery::publiclyViewable()
            ->with(['coverImage', 'venueTemplate', 'user'])
            ->has('images', '>=', 1)
            ->whereDoesntHave('user', fn($q) => $q->whereNotNull('banned_at'));

        $query->when($sort === 'views', fn($q) => $q->orderByDesc('view_count'))
              ->when($sort === 'newest', fn($q) => $q->orderByDesc('created_at'))
              ->unless(in_array($sort, ['views', 'newest']), fn($q) => $q->orderByDesc('is_featured')->orderByDesc('view_count'));

        $galleries = $query->paginate($perPage);

        return response()->json([
            'data' => $galleries->map(fn($g) => $this->formatGallery($g)),
            'meta' => [
                'pagination' => [
                    'total'        => $galleries->total(),
                    'per_page'     => $galleries->perPage(),
                    'current_page' => $galleries->currentPage(),
                    'last_page'    => $galleries->lastPage(),
                ],
            ],
        ]);
    }

    /**
     * Show a single gallery.
     * GET /api/v1/galleries/{slug}
     */
    public function show(string $slug): JsonResponse
    {
        $gallery = Gallery::publiclyViewable()
            ->with(['images' => fn($q) => $q->orderBy('position_order'), 'venueTemplate', 'user'])
            ->where('slug', $slug)
            ->first();

        if (! $gallery) {
            return response()->json(['error' => 'Gallery not found'], 404);
        }

        return response()->json([
            'data' => $this->formatGallery($gallery),
            'images' => $gallery->images->map(fn($img) => $this->formatImage($img)),
        ]);
    }

    /**
     * List images for a gallery.
     * GET /api/v1/galleries/{slug}/images
     */
    public function images(Request $request, string $slug): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 50), 200);

        $gallery = Gallery::publiclyViewable()->where('slug', $slug)->first();

        if (! $gallery) {
            return response()->json(['error' => 'Gallery not found'], 404);
        }

        $images = $gallery->images()->orderBy('position_order')->paginate($perPage);

        return response()->json([
            'data' => $images->map(fn($img) => $this->formatImage($img)),
            'meta' => [
                'pagination' => [
                    'total'        => $images->total(),
                    'per_page'     => $images->perPage(),
                    'current_page' => $images->currentPage(),
                    'last_page'    => $images->lastPage(),
                ],
            ],
        ]);
    }

    /**
     * List the authenticated user's own galleries (all, including inactive).
     * GET /api/v1/me/galleries
     */
    public function myGalleries(Request $request): JsonResponse
    {
        $galleries = $request->user()->galleries()
            ->with(['coverImage', 'venueTemplate'])
            ->latest()
            ->paginate(20);

        return response()->json([
            'data' => $galleries->map(fn($g) => array_merge($this->formatGallery($g), [
                'is_active' => $g->is_active,
                'view_count' => $g->view_count,
            ])),
            'meta' => [
                'pagination' => [
                    'total'        => $galleries->total(),
                    'per_page'     => $galleries->perPage(),
                    'current_page' => $galleries->currentPage(),
                    'last_page'    => $galleries->lastPage(),
                ],
            ],
        ]);
    }

    private function formatGallery(Gallery $g): array
    {
        return [
            'id'            => $g->id,
            'title'         => $g->title,
            'slug'          => $g->slug,
            'description'   => $g->description,
            'view_count'    => $g->view_count,
            'is_featured'   => $g->is_featured ?? false,
            'public_url'    => $g->public_url,
            'cover_image'   => $g->coverImage ? [
                'url'    => asset($g->coverImage->path),
                'width'  => $g->coverImage->width,
                'height' => $g->coverImage->height,
            ] : null,
            'venue'         => $g->venueTemplate ? [
                'id'   => $g->venueTemplate->id,
                'name' => $g->venueTemplate->name,
            ] : null,
            'curator'       => $g->user ? [
                'id'   => $g->user->id,
                'name' => $g->user->name,
            ] : null,
            'image_count'   => $g->images->count(),
            'created_at'    => $g->created_at?->toIso8601String(),
            'updated_at'    => $g->updated_at?->toIso8601String(),
        ];
    }

    private function formatImage($img): array
    {
        // AUDIT-P0-1.7 FIX: Previously referenced $img->thumbnail which is
        // neither a column on gallery_images nor an accessor on GalleryImage
        // — so thumbnail_url was always null in API responses. Now uses
        // GalleryImage::conversionUrl('thumb') which is the Spatie Media Library
        // conversion registered in GalleryImage::registerMediaConversions().
        // Falls back to the original asset URL if Spatie throws (corrupted
        // media record, missing file, etc.) or if no media is registered.
        $thumbUrl = null;
        try {
            $thumbUrl = $img->conversionUrl('thumb');
        } catch (\Throwable $e) {
            $thumbUrl = null;
        }
        if (! $thumbUrl) {
            $thumbUrl = asset($img->path);
        }

        return [
            'id'             => $img->id,
            'title'          => $img->title,
            'description'    => $img->description,
            'url'            => asset($img->path),
            'thumbnail_url'  => $thumbUrl,
            'original_name'  => $img->original_name,
            'width'          => $img->width,
            'height'         => $img->height,
            'orientation'    => $img->orientation,
            'position_order' => $img->position_order,
            'price'          => $img->price,
            'currency'       => $img->currency,
            'for_sale'       => $img->for_sale,
            'medium'         => $img->medium,
            'year'           => $img->year,
        ];
    }
}
