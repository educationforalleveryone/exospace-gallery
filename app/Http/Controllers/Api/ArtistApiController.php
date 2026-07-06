<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Artist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * M-28: Public REST API for artists.
 *
 * Read-only endpoints for browsing artist profiles + their galleries.
 */
class ArtistApiController extends Controller
{
    /**
     * List all published artists (paginated).
     * GET /api/v1/artists?per_page=20
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 20), 100);

        $artists = Artist::whereNotNull('slug')
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json([
            'data' => $artists->map(fn($a) => $this->formatArtist($a)),
            'meta' => [
                'pagination' => [
                    'total'        => $artists->total(),
                    'per_page'     => $artists->perPage(),
                    'current_page' => $artists->currentPage(),
                    'last_page'    => $artists->lastPage(),
                ],
            ],
        ]);
    }

    /**
     * Show a single artist.
     * GET /api/v1/artists/{slug}
     */
    public function show(string $slug): JsonResponse
    {
        $artist = Artist::where('slug', $slug)->first();

        if (! $artist) {
            return response()->json(['error' => 'Artist not found'], 404);
        }

        return response()->json([
            'data' => $this->formatArtist($artist),
        ]);
    }

    /**
     * List galleries featuring this artist's work.
     * GET /api/v1/artists/{slug}/galleries
     */
    public function galleries(Request $request, string $slug): JsonResponse
    {
        $artist = Artist::where('slug', $slug)->first();

        if (! $artist) {
            return response()->json(['error' => 'Artist not found'], 404);
        }

        $perPage = min((int) $request->query('per_page', 20), 100);

        $galleries = $artist->galleries()
            ->publiclyViewable()
            ->with(['coverImage', 'venueTemplate'])
            ->has('images', '>=', 1)
            ->paginate($perPage);

        return response()->json([
            'data' => $galleries->map(fn($g) => [
                'id'         => $g->id,
                'title'      => $g->title,
                'slug'       => $g->slug,
                'view_count' => $g->view_count,
                'public_url' => $g->public_url,
            ]),
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

    private function formatArtist(Artist $a): array
    {
        return [
            'id'         => $a->id,
            'name'       => $a->name,
            'slug'       => $a->slug,
            'bio'        => $a->bio,
            'website'    => $a->website,
            'instagram'  => $a->instagram,
            'twitter'    => $a->twitter,
            'email'      => $a->email,
            'location'   => $a->location,
            'profile_url' => url('/artist/' . $a->slug),
            'created_at' => $a->created_at?->toIso8601String(),
        ];
    }
}
