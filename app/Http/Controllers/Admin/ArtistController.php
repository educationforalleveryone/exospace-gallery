<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Artist;
use App\Models\Gallery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;

class ArtistController extends Controller
{
    public function index(Request $request): View
    {
        $user = Auth::user();
        $query = Artist::withCount('images')
            ->orderBy('name');

        if ($search = trim((string) $request->query('q', ''))) {
            $search = mb_substr($search, 0, 100);
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
            'slug'      => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9-]+$/', Rule::unique('artists', 'slug')],
            'bio'       => ['nullable', 'string', 'max:2000'],
            // SEO OS: curator-facing SEO overrides.
            'seo_title'       => ['nullable', 'string', 'max:200'],
            'seo_description' => ['nullable', 'string', 'max:300'],
            'website'   => ['nullable', 'string', 'max:500', 'url'],
            'instagram' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9._]+$/'],
            'twitter'   => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9._]+$/'],
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

        // The slug generator checks for existing slugs before saving, but a
        // concurrent creation can claim the same slug in between; the unique
        // index rejects the insert and regenerating the slug resolves it.
        $attempts = 0;

        try {
            while (true) {
                try {
                    $artist = Artist::create($validated);
                    break;
                } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                    if (++$attempts >= 3) {
                        throw $e;
                    }
                }
            }
        } catch (\Throwable $e) {
            // No row was created, so the uploaded portrait would be orphaned.
            if (!empty($validated['portrait_path'])) {
                try {
                    Storage::disk('public')->delete($validated['portrait_path']);
                } catch (\Throwable) {
                    // The upload itself failed to land — nothing to clean.
                }
            }
            throw $e;
        }

        if (array_key_exists('seo_title', $validated) || array_key_exists('seo_description', $validated)) {
            $profile = $artist->seoProfileOrCreate();
            $profile->fill([
                'title_override'       => $validated['seo_title'] ?? null,
                'description_override' => $validated['seo_description'] ?? null,
                'updated_by'           => $request->user()->id,
            ])->save();
            unset($validated['seo_title'], $validated['seo_description']);
        }

        return redirect()
            ->route('admin.artists.index')
            ->with('status', "Artist \"{$artist->name}\" created.");
    }

    public function show(Artist $artist): View
    {
        $artist->load(['images.gallery.venueTemplate']);

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
        $this->authorizeArtistMutation($artist);

        return view('admin.artists.edit', compact('artist'));
    }

    public function update(Request $request, Artist $artist): RedirectResponse
    {
        $this->authorizeArtistMutation($artist);

        $validated = $request->validate([
            'name'      => ['required', 'string', 'max:100'],
            'slug'      => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9-]+$/', Rule::unique('artists', 'slug')->ignore($artist)],
            'bio'       => ['nullable', 'string', 'max:2000'],
            // SEO OS: curator-facing SEO overrides.
            'seo_title'       => ['nullable', 'string', 'max:200'],
            'seo_description' => ['nullable', 'string', 'max:300'],
            'website'   => ['nullable', 'string', 'max:500', 'url'],
            'instagram' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9._]+$/'],
            'twitter'   => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9._]+$/'],
            'email'     => ['nullable', 'string', 'max:255', 'email'],
            'location'  => ['nullable', 'string', 'max:255'],
            'portrait'  => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        foreach (['instagram', 'twitter'] as $field) {
            if (!empty($validated[$field])) {
                $validated[$field] = ltrim($validated[$field], '@');
            }
        }

        if (array_key_exists('seo_title', $validated) || array_key_exists('seo_description', $validated)) {
            $profile = $artist->seoProfileOrCreate();
            $profile->fill([
                'title_override'       => $validated['seo_title'] ?? null,
                'description_override' => $validated['seo_description'] ?? null,
                'updated_by'           => $request->user()->id,
            ])->save();
            unset($validated['seo_title'], $validated['seo_description']);
        }

        $newPortraitPath = null;
        $oldPortraitPath = $artist->portrait_path;
        if ($request->hasFile('portrait')) {
            // Store the replacement first; the previous portrait stays
            // available until the row update has committed.
            $newPortraitPath = $request->file('portrait')
                ->store('artist-portraits', 'public');
            $validated['portrait_path'] = $newPortraitPath;
        }

        try {
            $artist->update($validated);
        } catch (\Throwable $e) {
            if ($newPortraitPath !== null) {
                try {
                    Storage::disk('public')->delete($newPortraitPath);
                } catch (\Throwable) {
                    // Cleanup is best-effort; the DB failure is the real signal.
                }
            }
            throw $e;
        }

        if ($newPortraitPath !== null && $oldPortraitPath) {
            $this->deleteFileQuietly($oldPortraitPath);
        }

        return redirect()
            ->route('admin.artists.index')
            ->with('status', "Artist \"{$artist->name}\" updated.");
    }

    public function destroy(Artist $artist): RedirectResponse
    {
        $this->authorizeArtistMutation($artist);

        $name = $artist->name;
        $portraitPath = $artist->portrait_path;

        // Snapshot the owning galleries before detaching — artwork OG cards
        // render the artist name and stamp their cache keys off these rows.
        $galleryIds = $artist->images()->pluck('gallery_id')->unique();

        // Detach from all images (set artist_id to null — images stay)
        $artist->images()->update(['artist_id' => null]);

        $artist->delete();

        // The mass detach above bypasses model events; touching the galleries
        // rotates the stamped caches (OG artwork cards, custom-domain
        // payloads) without waiting out their TTLs.
        if ($galleryIds->isNotEmpty()) {
            Gallery::whereIn('id', $galleryIds)->update(['updated_at' => now()]);
        }

        // Physical removal happens after the row is gone; a failed delete
        // must not leave a live artist row pointing at missing bytes.
        if ($portraitPath) {
            $this->deleteFileQuietly($portraitPath);
        }

        return redirect()
            ->route('admin.artists.index')
            ->with('status', "Artist \"{$name}\" deleted. Their artworks remain but are now unattributed.");
    }

    public function search(Request $request)
    {
        $term = trim((string) $request->query('q', ''));
        if (strlen($term) < 1) {
            return response()->json([]);
        }
        $term = mb_substr($term, 0, 100);

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

    private function deleteFileQuietly(string $path): void
    {
        try {
            Storage::disk('public')->delete($path);
        } catch (\Throwable $e) {
            Log::warning('ArtistController: file cleanup failed', [
                'path'  => $path,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function authorizeArtistMutation(Artist $artist): void
    {
        $user = Auth::user();

        if ($user->is_super_admin) {
            return;
        }

        if ($artist->created_by === $user->id) {
            return;
        }

        abort(403, 'You can only edit artists you created. Contact a super-admin if you need to correct another curator\'s artist profile.');
    }
}
