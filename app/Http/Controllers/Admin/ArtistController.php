<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Artist;
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
 * AUTHORIZATION (task C16)
 * ------------------------
 * Previously, "any user can edit any artist profile" — the docblock
 * called this "intentional for the multi-curator group-show scenario."
 * The pre-launch audit flagged this as a critical vulnerability: a
 * malicious free-tier user could change any artist's bio/website/social
 * links (defacement, phishing-link injection) or delete any artist and
 * detach them from every image across every gallery via
 * `$artist->images()->update(['artist_id' => null])`.
 *
 * New model:
 *   - Any authenticated user can VIEW / search all artists (multi-curator
 *     collaboration preserved — the dropdown still shows everyone).
 *   - Only the creator OR a super-admin can edit or delete an artist.
 *   - `created_by` is locked at creation time and cannot be changed via
 *     the update form (it's not in the validated fields).
 *
 * This stops the cross-tenant defacement / attribution-wipe attack
 * while keeping the legitimate collaboration flow working.
 */
class ArtistController extends Controller
{
    public function index(Request $request): View
    {
        $user = Auth::user();
        $query = Artist::withCount('images')
            ->with(['creator'])
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
            // SEO OS (Iteration 6): curator-facing SEO overrides.
            'seo_title'       => ['nullable', 'string', 'max:200'],
            'seo_description' => ['nullable', 'string', 'max:300'],
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

        // SEO OS (Iteration 6): persist curator SEO overrides into the
        // artist's seo_profile (creates on demand).
        if (array_key_exists('seo_title', $validated) || array_key_exists('seo_description', $validated)) {
            $profile = $artist->seoProfileOrCreate();
            $profile->fill([
                'title_override'       => $validated['seo_title'] ?? null,
                'description_override' => $validated['seo_description'] ?? null,
                'updated_by'           => $request->user()->id,
            ])->save();
            unset($validated['seo_title'], $validated['seo_description']);
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
        $this->authorizeArtistMutation($artist);

        return view('admin.artists.edit', compact('artist'));
    }

    public function update(Request $request, Artist $artist): RedirectResponse
    {
        $this->authorizeArtistMutation($artist);

        $validated = $request->validate([
            'name'      => ['required', 'string', 'max:100'],
            'slug'      => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9-]+$/'],
            'bio'       => ['nullable', 'string', 'max:2000'],
            // SEO OS (Iteration 6): curator-facing SEO overrides.
            'seo_title'       => ['nullable', 'string', 'max:200'],
            'seo_description' => ['nullable', 'string', 'max:300'],
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

        // SEO OS (Iteration 6): persist curator SEO overrides into the
        // artist's seo_profile (creates on demand).
        if (array_key_exists('seo_title', $validated) || array_key_exists('seo_description', $validated)) {
            $profile = $artist->seoProfileOrCreate();
            $profile->fill([
                'title_override'       => $validated['seo_title'] ?? null,
                'description_override' => $validated['seo_description'] ?? null,
                'updated_by'           => $request->user()->id,
            ])->save();
            unset($validated['seo_title'], $validated['seo_description']);
        }

        // `created_by` is intentionally NOT in $validated — it is locked
        // at creation time and cannot be transferred via the edit form.

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
        $this->authorizeArtistMutation($artist);

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
     *
     * Search is available to any authenticated user — multi-curator
     * collaboration requires seeing everyone's artists in the dropdown.
     * Only mutation (edit/delete) is restricted by authorizeArtistMutation().
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

    // ── Authorization ─────────────────────────────────────────────────────

    /**
     * Only the artist's creator OR a super-admin can edit/delete.
     *
     * (Task C16) — previously any authenticated user could mutate any
     * artist, enabling cross-tenant defacement and attribution-wipe
     * attacks. Now only the original creator (or a super-admin for
     * support cases) can mutate.
     *
     * The view/search/index/show actions remain open to any authenticated
     * user — multi-curator collaboration requires seeing everyone's
     * artists in the dropdown.
     */
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
