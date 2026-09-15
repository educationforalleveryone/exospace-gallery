<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\VenueTemplateRequest;
use App\Models\AdminAuditLog;
use App\Models\VenueTemplate;
use App\Models\VenueTemplateSnapshot;
use App\Services\FeatureFlag;
use App\Services\VenueSnapshotManager;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;

class VenueTemplateController extends Controller
{
    public function __construct(
        private VenueSnapshotManager $snapshots,
    ) {}

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
        $authoringEnabled = FeatureFlag::isEnabled('venue_authoring');
        $previewsEnabled  = FeatureFlag::isEnabled('venue_previews');

        return view('super-admin.venues.index', compact(
            'venues', 'categories', 'authoringEnabled', 'previewsEnabled',
        ));
    }

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

    public function store(VenueTemplateRequest $request): RedirectResponse
    {
        $data = $this->extractData($request);

        $venue = VenueTemplate::create($data);
        $uploads = $this->handleFileUploads($request, $venue);

        try {
            $venue->save();
        } catch (\Throwable $e) {
            $this->deleteFilesQuietly($uploads['stored']);
            throw $e;
        }

        $this->deleteFilesQuietly($uploads['stale']);

        AdminAuditLog::record('venue_template.created', $venue, [
            'name' => $venue->name,
            'slug' => $venue->slug,
        ]);

        return redirect()
            ->route('super.venues.index')
            ->with('status', "Venue \"{$venue->name}\" created.");
    }

    public function edit(VenueTemplate $venue): View
    {
        $categories = VenueTemplate::CATEGORIES;
        $layouts = VenueTemplate::LAYOUTS;
        $snapshots = VenueTemplateSnapshot::forVenue($venue->id)
            ->with('author:id,name')
            ->get();
        $authoringEnabled = FeatureFlag::isEnabled('venue_authoring');
        $previewsEnabled  = FeatureFlag::isEnabled('venue_previews');

        return view('super-admin.venues.edit', compact(
            'venue', 'categories', 'layouts', 'snapshots', 'authoringEnabled', 'previewsEnabled',
        ));
    }

    public function update(VenueTemplateRequest $request, VenueTemplate $venue): RedirectResponse
    {
        $before = $venue->toArray();

        // Snapshot the state we are about to overwrite — BEFORE fill().
        if (FeatureFlag::isEnabled('venue_authoring')) {
            $this->snapshots->capture($venue, 'before save', $request->user());
        }

        $data = $this->extractData($request, $venue);

        $venue->fill($data);
        $uploads = $this->handleFileUploads($request, $venue);

        try {
            $venue->save();
        } catch (\Throwable $e) {
            // The replaced assets were never committed — remove only the
            // files this request wrote. The previous asset stays intact.
            $this->deleteFilesQuietly($uploads['stored']);
            throw $e;
        }

        $this->deleteFilesQuietly($uploads['stale']);

        AdminAuditLog::record('venue_template.updated', $venue, [
            'before' => $before,
            'after'  => $venue->fresh()->toArray(),
        ]);

        return redirect()
            ->route('super.venues.edit', $venue)
            ->with('status', "Venue \"{$venue->name}\" updated. Preview below reflects the saved state.");
    }

    public function cloneVenue(Request $request, VenueTemplate $venue): RedirectResponse
    {
        $copy = $venue->replicate(['slug', 'view_count', 'published_at', 'archived_at']);

        $copy->name        = $venue->name.' (Copy)';
        $copy->is_draft    = true;   // duplicates never auto-publish
        $copy->is_featured = false;  // featured is curation of the original
        $copy->view_count  = 0;
        $copy->published_at = null;
        $copy->archived_at  = null;
        $copy->sort_order  = (VenueTemplate::max('sort_order') ?? 0) + 1;

        $disk = Storage::disk('public');
        foreach (['thumbnail_path', 'preview_model_path', 'hdri_path', 'default_audio_path'] as $field) {
            if (!empty($venue->$field) && $disk->exists($venue->$field)) {
                $newPath = dirname($venue->$field).'/'.Str::uuid()->toString().'-'.basename($venue->$field);
                try {
                    if ($disk->copy($venue->$field, $newPath)) {
                        $copy->$field = $newPath;
                    }
                } catch (\Throwable $e) {
                    \Log::warning('VenueTemplateController: clone file copy failed', [
                        'field'    => $field,
                        'source'   => $venue->$field,
                        'error'    => $e->getMessage(),
                    ]);
                }
            }
        }

        $baseSlug = Str::slug($copy->name) ?: 'venue-copy';
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $copy->slug = $attempt === 1 ? $baseSlug : "{$baseSlug}-{$attempt}";
            try {
                $copy->save();
                break;
            } catch (QueryException $e) {
                $msg = strtolower($e->getMessage());
                $isCollision = str_contains($msg, 'duplicate entry')
                    || str_contains($msg, 'unique constraint');
                if ($attempt === 10 || !$isCollision) {
                    throw $e;
                }
            }
        }

        AdminAuditLog::record('venue_template.cloned', $copy, [
            'source_id' => $venue->id,
            'source_slug' => $venue->slug,
            'name' => $copy->name,
            'slug' => $copy->slug,
        ]);

        return redirect()
            ->route('super.venues.edit', $copy)
            ->with('status', "Venue cloned as \"{$copy->name}\" (draft). Configure, preview, then publish.");
    }

    public function publish(Request $request, VenueTemplate $venue): RedirectResponse
    {
        $wasDraft = $venue->is_draft;

        $venue->fill(['is_draft' => false]);
        if (!$venue->published_at) {
            $venue->published_at = now();
        }
        $venue->save();

        AdminAuditLog::record('venue_template.published', $venue, [
            'was_draft' => $wasDraft,
            'published_at' => $venue->published_at?->toIso8601String(),
        ]);

        return back()->with('status', "Venue \"{$venue->name}\" published — now selectable in the picker.");
    }

    public function unpublish(Request $request, VenueTemplate $venue): RedirectResponse
    {
        $venue->update(['is_draft' => true]);

        AdminAuditLog::record('venue_template.unpublished', $venue, [
            'galleries_count' => $venue->galleries()->count(),
        ]);

        return back()->with('status', "Venue \"{$venue->name}\" moved back to draft.");
    }

    public function destroy(Request $request, VenueTemplate $venue): RedirectResponse
    {
        if ($venue->isArchived()) {
            return back()->with('status', "Venue \"{$venue->name}\" is already archived.");
        }

        $galleriesCount = $venue->galleries()->count();

        if ($galleriesCount > 0 && !$request->boolean('confirm_usage')) {
            return back()->with(
                'error',
                "\"{$venue->name}\" is used by {$galleriesCount} ".str('gallery')->plural($galleriesCount)
                .'. Confirm the archive dialog to retire it anyway — those galleries keep rendering this venue.'
            );
        }

        $venue->update(['archived_at' => now()]);

        AdminAuditLog::record('venue_template.archived', $venue, [
            'galleries_count' => $galleriesCount,
            'usage_confirmed' => $galleriesCount > 0,
        ]);

        return redirect()
            ->route('super.venues.index')
            ->with('status', "Venue \"{$venue->name}\" archived — hidden from selection, existing galleries unaffected. Restore anytime.");
    }

    public function unarchive(Request $request, VenueTemplate $venue): RedirectResponse
    {
        $venue->update(['archived_at' => null]);

        AdminAuditLog::record('venue_template.unarchived', $venue, [
            'galleries_count' => $venue->galleries()->count(),
        ]);

        return back()->with('status', "Venue \"{$venue->name}\" restored to selection.");
    }

    public function restoreSnapshot(Request $request, VenueTemplate $venue, VenueTemplateSnapshot $snapshot): RedirectResponse
    {
        abort_unless($snapshot->venue_template_id === $venue->id, 404);

        $result = $this->snapshots->restore($snapshot, $request->user());

        AdminAuditLog::record('venue_template.snapshot_restored', $venue, [
            'snapshot_id' => $snapshot->id,
            'snapshot_label' => $snapshot->label,
            'before' => $result['before'],
            'after' => $result['after'],
        ]);

        return redirect()
            ->route('super.venues.edit', $venue)
            ->with('status', "Rolled back to snapshot from {$snapshot->created_at->format('Y-m-d H:i')} (the overwritten state was itself snapshotted).");
    }

    private function extractData(VenueTemplateRequest $request, ?VenueTemplate $venue = null): array
    {
        $data = $request->validated();

        // File uploads are handled separately — pull them out of the data array.
        foreach (['thumbnail_image', 'preview_model', 'hdri_file', 'default_audio'] as $fileField) {
            unset($data[$fileField]);
        }

        // The advanced raw-JSON field is consumed here, never persisted raw.
        $advanced = is_array($data['visual_config_advanced'] ?? null)
            ? $data['visual_config_advanced']
            : [];
        unset($data['visual_config_advanced']);

        // Merge: structured keys (base) + advanced raw JSON (wins).
        if (array_key_exists('visual_config', $data)) {
            $structured = is_array($data['visual_config']) ? $data['visual_config'] : [];
            $merged = array_merge($structured, $advanced);
            $data['visual_config'] = $merged === [] ? null : $merged;
        }

        // An empty structured material form means "no overrides at all".
        if (array_key_exists('material_config', $data) && $data['material_config'] === []) {
            $data['material_config'] = null;
        }

        // Booleans come through as null if unchecked in the form — normalise.
        foreach (['is_active', 'is_featured', 'is_draft'] as $bool) {
            $data[$bool] = $request->boolean($bool);
        }

        // Default slug from name if not provided
        if (empty($data['slug']) && !empty($data['name'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        // Author attribution on create
        if (!$venue && empty($data['author_id'])) {
            $data['author_id'] = $request->user()->id;
        }

        return $data;
    }

    /**
     * Store uploads for this request and swap them onto the venue.
     *
     * Returns the paths written by this request (["stored"]) and the paths
     * they replace (["stale"]). Callers delete stale files only AFTER the
     * venue row has been saved, so a failed save never destroys the asset
     * that is still serving.
     */
    private function handleFileUploads(VenueTemplateRequest $request, VenueTemplate $venue): array
    {
        $stored = [];
        $stale = [];

        if ($request->hasFile('thumbnail_image')) {
            $stored[] = $venue->thumbnail_path = $request->file('thumbnail_image')
                ->store('venue-thumbnails', 'public');
            if ($venue->getOriginal('thumbnail_path')) {
                $stale[] = $venue->getOriginal('thumbnail_path');
            }
        }

        if ($request->hasFile('preview_model')) {
            $stored[] = $venue->preview_model_path = $request->file('preview_model')
                ->store('venue-models', 'public');
            if ($venue->getOriginal('preview_model_path')) {
                $stale[] = $venue->getOriginal('preview_model_path');
            }
        }

        if ($request->hasFile('hdri_file')) {
            $stored[] = $venue->hdri_path = $request->file('hdri_file')
                ->store('venue-hdri', 'public');
            if ($venue->getOriginal('hdri_path')) {
                $stale[] = $venue->getOriginal('hdri_path');
            }
        }

        if ($request->hasFile('default_audio')) {
            $stored[] = $venue->default_audio_path = $request->file('default_audio')
                ->store('venue-audio', 'public');
            if ($venue->getOriginal('default_audio_path')) {
                $stale[] = $venue->getOriginal('default_audio_path');
            }
        }

        return ['stored' => $stored, 'stale' => $stale];
    }

    private function deleteFilesQuietly(array $paths): void
    {
        $disk = Storage::disk('public');

        foreach (array_filter($paths) as $path) {
            try {
                $disk->delete($path);
            } catch (\Throwable $e) {
                \Log::warning('VenueTemplateController: file cleanup failed', [
                    'path'  => $path,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
