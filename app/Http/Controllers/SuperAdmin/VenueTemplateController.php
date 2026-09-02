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

    /**
     * List all venue templates with gallery counts and key badges.
     * Supports optional ?category= and ?q= filters.
     *
     * Iteration 5 "Authoring" (§9.2 #6): the table is also where catalog
     * decisions live — galleries_count + view_count + the conversion rollup
     * surface here, so retirement / #12 / family-investment calls are
     * data-driven instead of gut-driven.
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
        $authoringEnabled = FeatureFlag::isEnabled('venue_authoring');
        $previewsEnabled  = FeatureFlag::isEnabled('venue_previews');

        return view('super-admin.venues.index', compact(
            'venues', 'categories', 'authoringEnabled', 'previewsEnabled',
        ));
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
     *
     * Iteration 5 "Authoring": this page IS the authoring loop —
     * structured config form, live preview iframe, and the last 5
     * snapshots with one-click restore (each rollback immediately
     * visible in the iframe because save/restore bust the exporter
     * cache via the venue's updated_at, §10.7).
     */
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

    /**
     * Update an existing venue template.
     *
     * Iteration 5 "Authoring": before the save overwrites the venue, the
     * current state is captured as a snapshot (§9.2 #3) — the list on the
     * edit page reads as "what you would go back to". Pruned to 5 by the
     * manager. Redirect returns to the EDIT page (not the index) so the
     * live preview iframe renders the just-saved state — tweak → preview
     * without losing your place.
     */
    public function update(VenueTemplateRequest $request, VenueTemplate $venue): RedirectResponse
    {
        $before = $venue->toArray();

        // Snapshot the state we are about to overwrite — BEFORE fill().
        if (FeatureFlag::isEnabled('venue_authoring')) {
            $this->snapshots->capture($venue, 'before save', $request->user());
        }

        $data = $this->extractData($request, $venue);

        $venue->fill($data);
        $this->handleFileUploads($request, $venue);
        $venue->save();

        AdminAuditLog::record('venue_template.updated', $venue, [
            'before' => $before,
            'after'  => $venue->fresh()->toArray(),
        ]);

        return redirect()
            ->route('super.venues.edit', $venue)
            ->with('status', "Venue \"{$venue->name}\" updated. Preview below reflects the saved state.");
    }

    /**
     * Iteration 5 "Authoring" (§9.2 #2): clone/duplicate.
     *
     * Venue #12 begins as a copy of its family sibling — this kills the
     * 80-line JSON re-typing tax. The clone is ALWAYS created as a draft:
     * a duplicate must never silently go live next to its original.
     * Asset files are COPIED (not shared) so replacing a GLB/HDRI on one
     * venue can never corrupt the other.
     */
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

        // Copy uploaded assets under new names (replace/delete on one venue
        // must not touch the other).
        $disk = Storage::disk('public');
        foreach (['thumbnail_path', 'preview_model_path', 'hdri_path', 'default_audio_path'] as $field) {
            if (!empty($venue->$field) && $disk->exists($venue->$field)) {
                $newPath = dirname($venue->$field).'/'.Str::uuid()->toString().'-'.basename($venue->$field);
                if ($disk->copy($venue->$field, $newPath)) {
                    $copy->$field = $newPath;
                }
            }
        }

        // Slug: generated from the copy's name; the DB unique constraint is
        // the source of truth (same P2-5 convention as Artist) — retry with
        // a numeric suffix on collision.
        $baseSlug = Str::slug($copy->name) ?: 'venue-copy';
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $copy->slug = $attempt === 1 ? $baseSlug : "{$baseSlug}-{$attempt}";
            try {
                $copy->save();
                break;
            } catch (QueryException $e) {
                // MySQL says "Duplicate entry", sqlite/postgres say
                // "UNIQUE constraint" — tests run on sqlite.
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

    /**
     * Iteration 5 "Authoring" (§9.2 #5): explicit publish — draft/published
     * becomes a real workflow step instead of a stray checkbox.
     */
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

    /**
     * Unpublish: back to draft. Instantly hidden from every customer
     * surface (the Iteration 0 draft-leak contract), fully reversible.
     */
    public function unpublish(Request $request, VenueTemplate $venue): RedirectResponse
    {
        $venue->update(['is_draft' => true]);

        AdminAuditLog::record('venue_template.unpublished', $venue, [
            'galleries_count' => $venue->galleries()->count(),
        ]);

        return back()->with('status', "Venue \"{$venue->name}\" moved back to draft.");
    }

    /**
     * Iteration 5 "Authoring" (§9.2 #4): DELETE becomes ARCHIVE.
     *
     * The old hard delete reset every gallery using the venue back to the
     * default white-cube — irreversible and customer-visible. Archive
     * instead:
     *   - hides the venue from every selection surface (scopeActive),
     *   - keeps SERVING every gallery already using it,
     *   - keeps every uploaded file (a live show may still reference them),
     *   - is restorable in one click (unarchive).
     *
     * USAGE GUARD: archiving a venue that N galleries use requires the
     * confirm_usage flag — the UI dialog states the count before the admin
     * can proceed. The confirmation is also recorded in the audit log.
     */
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

    /**
     * Unarchive: back into selection everywhere (scopeActive clears).
     */
    public function unarchive(Request $request, VenueTemplate $venue): RedirectResponse
    {
        $venue->update(['archived_at' => null]);

        AdminAuditLog::record('venue_template.unarchived', $venue, [
            'galleries_count' => $venue->galleries()->count(),
        ]);

        return back()->with('status', "Venue \"{$venue->name}\" restored to selection.");
    }

    /**
     * Iteration 5 "Authoring" (§9.2 #3): one-click snapshot restore.
     * The manager captures the current state first, so even a restore is
     * reversible. Cache visibility is automatic (§10.7: exporter keys on
     * the venue's updated_at — a restore IS a save).
     */
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

    // ─────────────────────────────────────────────────────────────────────
    //  Private helpers
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Extract validated data minus file uploads. JSON fields are already
     * decoded to arrays by VenueTemplateRequest::prepareForValidation().
     *
     * Iteration 5 "Authoring" (§9.3): visual_config arrives from the
     * STRUCTURED form (per-key inputs, empties stripped pre-validation)
     * plus the `visual_config_advanced` raw-JSON field carrying everything
     * the form does not model — structure descriptors (IT3), gates
     * (structure_pass / glazing_wall / sun_shadows), placement,
     * tier_fallbacks, pipeline pastes. Advanced wins on conflict: schema
     * hint, not schema prison. Old JSON-textarea clients keep working —
     * their decoded arrays flow through untouched.
     */
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
