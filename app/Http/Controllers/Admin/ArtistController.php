<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesGalleryAccess;
use App\Models\Artist;
use App\Models\Gallery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Admin CRUD for artist profiles.
 *
 * Curators (any authenticated user) can create artist profiles and
 * assign them to artworks. The creator is recorded in `created_by`.
 *
 * Any user can edit any artist profile (no per-user ownership enforced)
 * — this is intentional for the multi-curator group-show scenario
 * where one curator corrects another's typo. If we later need stricter
 * ownership, we can add an `editable_only_by` scope.
 */
class ArtistController extends Controller
{
    public function index(Request $request): View
    {
        $user = Auth::user();
        $query = Artist::withCount('images')
            ->with(['creator', 'images.gallery'])
            ->orderBy('name');

        if ($search = trim((string) $request->query('q', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('bio', 'like', "%{$search}%")
                  ->orWhere('location', 'like', "%{$search}%");
            });
        }

        $artists = $query->paginate(20)->withQueryString();

        return view('admin.artists.index', compact('artists'));
    }

    public function create(): View
    {
        $artist = new Artist();
        return view('admin.artists.create', compact('artist'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name'      => ['required', 'string', 'max:100'],
            'slug'      => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9-]+$/'],
            'bio'       => ['nullable', 'string', 'max:2000'],
            'website'   => ['nullable', 'string', 'max:500', 'url'],
            'instagram' => ['nullable', 'string', 'max:255'],
            'twitter'   => ['nullable', 'string', 'max:255'],
            'email'     => ['nullable', 'string', 'max:255', 'email'],
            'location'  => ['nullable', 'string', 'max:255'],
            'portrait'  => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        // Normalize social handles (strip leading @)
        foreach (['instagram', 'twitter'] as $field) {
            if (!empty($validated[$field])) {
                $validated[$field] = ltrim($validated[$field], '@');
            }
        }

        $validated['created_by'] = Auth::id();

        // Handle portrait upload
        if ($request->hasFile('portrait')) {
            $validated['portrait_path'] = $request->file('portrait')
                ->store('artist-portraits', 'public');
        }

        $artist = Artist::create($validated);

        return redirect()
            ->route('admin.artists.index')
            ->with('status', "Artist \"{$artist->name}\" created.");
    }

    public function show(Artist $artist): View
    {
        $artist->load(['images.gallery.venueTemplate', 'images.gallery.user']);

        // Group images by gallery
        $galleries = $artist->images
            ->filter(fn ($img) => $img->gallery && $img->gallery->is_active)
            ->groupBy('gallery_id')
            ->map(function ($images) {
                $gallery = $images->first()->gallery;
                return [
                    'gallery' => $gallery,
                    'images' => $images,
                ];
            });

        return view('admin.artists.show', compact('artist', 'galleries'));
    }

    public function edit(Artist $artist): View
    {
        return view('admin.artists.edit', compact('artist'));
    }

    public function update(Request $request, Artist $artist): RedirectResponse
    {
        $validated = $request->validate([
            'name'      => ['required', 'string', 'max:100'],
            'slug'      => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9-]+$/'],
            'bio'       => ['nullable', 'string', 'max:2000'],
            'website'   => ['nullable', 'string', 'max:500', 'url'],
            'instagram' => ['nullable', 'string', 'max:255'],
            'twitter'   => ['nullable', 'string', 'max:255'],
            'email'     => ['nullable', 'string', 'max:255', 'email'],
            'location'  => ['nullable', 'string', 'max:255'],
            'portrait'  => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        foreach (['instagram', 'twitter'] as $field) {
            if (!empty($validated[$field])) {
                $validated[$field] = ltrim($validated[$field], '@');
            }
        }

        if ($request->hasFile('portrait')) {
            if ($artist->portrait_path) {
                Storage::disk('public')->delete($artist->portrait_path);
            }
            $validated['portrait_path'] = $request->file('portrait')
                ->store('artist-portraits', 'public');
        }

        $artist->update($validated);

        return redirect()
            ->route('admin.artists.index')
            ->with('status', "Artist \"{$artist->name}\" updated.");
    }

    public function destroy(Artist $artist): RedirectResponse
    {
        $name = $artist->name;

        if ($artist->portrait_path) {
            Storage::disk('public')->delete($artist->portrait_path);
        }

        // Detach from all images (set artist_id to null — images stay)
        $artist->images()->update(['artist_id' => null]);

        $artist->delete();

        return redirect()
            ->route('admin.artists.index')
            ->with('status', "Artist \"{$name}\" deleted. Their artworks remain but are now unattributed.");
    }

    /**
     * AJAX endpoint: search artists by name (for the image-edit dropdown).
     * Returns JSON [{id, name, location, portrait_url}].
     */
    public function search(Request $request)
    {
        $term = trim((string) $request->query('q', ''));
        if (strlen($term) < 1) {
            return response()->json([]);
        }

        $artists = Artist::where('name', 'like', "%{$term}%")
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'location', 'portrait_path']);

        return response()->json($artists->map(fn ($a) => [
            'id'           => $a->id,
            'name'         => $a->name,
            'location'     => $a->location,
            'portrait_url' => $a->portrait_url,
            'initials'     => $a->initials,
        ]));
    }
}
