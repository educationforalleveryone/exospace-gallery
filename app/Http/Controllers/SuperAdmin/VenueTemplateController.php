<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\VenueTemplateRequest;
use App\Models\AdminAuditLog;
use App\Models\VenueTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;

class VenueTemplateController extends Controller
{
    /**
     * List all venue templates with gallery counts and key badges.
     * Supports optional ?category= and ?q= filters.
     */
    public function index(Request $request): View
    {
        $query = VenueTemplate::query()
            ->withCount('galleries')
            ->orderBy('sort_order')
            ->orderBy('name');

        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }

        if ($search = trim((string) $request->query('q', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('slug', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $venues = $query->paginate(20)->withQueryString();
        $categories = VenueTemplate::CATEGORIES;

        return view('super-admin.venues.index', compact('venues', 'categories'));
    }

    /**
     * Show the create form.
     */
    public function create(): View
    {
        $venue = new VenueTemplate([
            'category'      => 'gallery',
            'plan_required' => 'free',
            'capacity_min'  => 10,
            'capacity_max'  => 50,
            'is_active'      => true,
            'is_featured'    => false,
            'is_draft'       => false,
            'sort_order'     => (VenueTemplate::max('sort_order') ?? 0) + 1,
            'version'        => '1.0.0',
            'supported_layouts' => ['square', 'corridor', 'l-shape', 'rotunda'],
        ]);

        $categories = VenueTemplate::CATEGORIES;
        $layouts = VenueTemplate::LAYOUTS;

        return view('super-admin.venues.create', compact('venue', 'categories', 'layouts'));
    }

    /**
     * Store a new venue template.
     */
    public function store(VenueTemplateRequest $request): RedirectResponse
    {
        $data = $this->extractData($request);

        $venue = VenueTemplate::create($data);
        $this->handleFileUploads($request, $venue);
        $venue->save();

        AdminAuditLog::record('venue_template.created', $venue, [
            'name' => $venue->name,
            'slug' => $venue->slug,
        ]);

        return redirect()
            ->route('super.venues.index')
            ->with('status', "Venue \"{$venue->name}\" created.");
    }

    /**
     * Show the edit form.
     */
    public function edit(VenueTemplate $venue): View
    {
        $categories = VenueTemplate::CATEGORIES;
        $layouts = VenueTemplate::LAYOUTS;

        return view('super-admin.venues.edit', compact('venue', 'categories', 'layouts'));
    }

    /**
     * Update an existing venue template.
     */
    public function update(VenueTemplateRequest $request, VenueTemplate $venue): RedirectResponse
    {
        $before = $venue->toArray();
        $data = $this->extractData($request, $venue);

        $venue->fill($data);
        $this->handleFileUploads($request, $venue);
        $venue->save();

        AdminAuditLog::record('venue_template.updated', $venue, [
            'before' => $before,
            'after'  => $venue->fresh()->toArray(),
        ]);

        return redirect()
            ->route('super.venues.index')
            ->with('status', "Venue \"{$venue->name}\" updated.");
    }

    /**
     * Toggle is_active.
     */
    public function toggle(VenueTemplate $venue): RedirectResponse
    {
        $venue->update(['is_active' => !$venue->is_active]);
        $state = $venue->is_active ? 'enabled' : 'disabled';

        AdminAuditLog::record('venue_template.toggled', $venue, [
            'is_active' => $venue->is_active,
        ]);

        return back()->with('status', "Venue \"{$venue->name}\" {$state}.");
    }

    /**
     * Toggle is_featured.
     */
    public function toggleFeatured(VenueTemplate $venue): RedirectResponse
    {
        $venue->update(['is_featured' => !$venue->is_featured]);

        AdminAuditLog::record('venue_template.feature_toggled', $venue, [
            'is_featured' => $venue->is_featured,
        ]);

        return back()->with('status', "Venue \"{$venue->name}\" featured status updated.");
    }

    /**
     * Soft-delete-equivalent: we hard-delete because venue templates are
     * rarely deleted and any galleries using them get nullOnDelete on the
     * foreign key (set back to "no venue" / fallback to white-cube).
     */
    public function destroy(VenueTemplate $venue): RedirectResponse
    {
        $name = $venue->name;
        $galleriesCount = $venue->galleries()->count();

        // Clean up uploaded files
        foreach (['thumbnail_path', 'preview_model_path', 'hdri_path', 'default_audio_path'] as $field) {
            if ($venue->$field) {
                Storage::disk('public')->delete($venue->$field);
            }
        }

        $venue->delete();

        AdminAuditLog::record('venue_template.deleted', $venue, [
            'name' => $name,
            'galleries_count' => $galleriesCount,
        ]);

        return redirect()
            ->route('super.venues.index')
            ->with('status', "Venue \"{$name}\" deleted. {$galleriesCount} galleries reset to default venue.");
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Private helpers
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Extract validated data minus file uploads. JSON fields are already
     * decoded to arrays by VenueTemplateRequest::prepareForValidation().
     */
    private function extractData(VenueTemplateRequest $request, ?VenueTemplate $venue = null): array
    {
        $data = $request->validated();

        // File uploads are handled separately — pull them out of the data array.
        foreach (['thumbnail_image', 'preview_model', 'hdri_file', 'default_audio'] as $fileField) {
            unset($data[$fileField]);
        }

        // Booleans come through as null if unchecked in the form — normalise.
        foreach (['is_active', 'is_featured', 'is_draft'] as $bool) {
            $data[$bool] = $request->boolean($bool);
        }

        // Default slug from name if not provided
        if (empty($data['slug']) && !empty($data['name'])) {
            $data['slug'] = \Str::slug($data['name']);
        }

        // Author attribution on create
        if (!$venue && empty($data['author_id'])) {
            $data['author_id'] = $request->user()->id;
        }

        // Tags as comma-separated string from the form? No — the form
        // uses a JSON textarea, so $data['tags'] is already an array or null.

        return $data;
    }

    /**
     * Handle file uploads for thumbnail / preview model / HDRI / audio.
     * Deletes the previous file if replaced.
     */
    private function handleFileUploads(VenueTemplateRequest $request, VenueTemplate $venue): void
    {
        $disk = Storage::disk('public');

        if ($request->hasFile('thumbnail_image')) {
            if ($venue->thumbnail_path) {
                $disk->delete($venue->thumbnail_path);
            }
            $venue->thumbnail_path = $request->file('thumbnail_image')
                ->store('venue-thumbnails', 'public');
        }

        if ($request->hasFile('preview_model')) {
            if ($venue->preview_model_path) {
                $disk->delete($venue->preview_model_path);
            }
            $venue->preview_model_path = $request->file('preview_model')
                ->store('venue-models', 'public');
        }

        if ($request->hasFile('hdri_file')) {
            if ($venue->hdri_path) {
                $disk->delete($venue->hdri_path);
            }
            $venue->hdri_path = $request->file('hdri_file')
                ->store('venue-hdri', 'public');
        }

        if ($request->hasFile('default_audio')) {
            if ($venue->default_audio_path) {
                $disk->delete($venue->default_audio_path);
            }
            $venue->default_audio_path = $request->file('default_audio')
                ->store('venue-audio', 'public');
        }
    }
}
