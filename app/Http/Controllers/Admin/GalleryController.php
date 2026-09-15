<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesGalleryAccess;
use App\Models\AdminAuditLog;
use App\Models\Gallery;
use App\Models\Team;
use App\Models\VenueTemplate;
use App\Services\CoolifyDomainManager;
use App\Services\VenueConfigExporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;

class GalleryController extends Controller
{
    use AuthorizesGalleryAccess;

    public function __construct(
        private readonly CoolifyDomainManager $coolify,
        private readonly VenueConfigExporter $venueExporter,
    ) {}

    // ── Index: show personal OR team galleries ────────────────────────────

    public function index(Request $request): View
    {
        $user   = Auth::user();
        $team   = $this->resolveTeamContext($user, $request->query('team'));

        $galleries = $team
            ? Gallery::with(['coverImage.media', 'venueTemplate'])->withCount('images')->where('team_id', $team->id)->latest()->paginate(10)
            : Gallery::with(['coverImage.media', 'venueTemplate'])->withCount('images')->where('user_id', $user->id)->whereNull('team_id')->latest()->paginate(10);

        return view('admin.galleries.index', compact('galleries', 'team'));
    }

    public function create(Request $request): View|RedirectResponse
    {
        $user = Auth::user();
        $team = $this->resolveEditableTeam($user, $request->query('team'));

        if ($redirect = $this->checkGalleryLimit($user, $team)) {
            return $redirect;
        }

        $venueTemplates = \App\Models\VenueTemplate::active()
            ->published()
            ->orderBy('sort_order')
            ->get();
        return view('admin.galleries.create', compact('team', 'venueTemplates'));
    }

    public function store(Request $request): RedirectResponse
    {
        $user = Auth::user();
        $team = $this->resolveEditableTeam($user, $request->input('team_id'));

        if ($redirect = $this->checkGalleryLimit($user, $team)) {
            return $redirect;
        }

        // Strip emoji from title to prevent utf8 column errors
        $request->merge([
            'title' => preg_replace('/[\x{1F000}-\x{1FFFF}]|[\x{2600}-\x{27FF}]|[\x{2B00}-\x{2BFF}]|[\x{FE00}-\x{FE0F}]|[\x{1F300}-\x{1F9FF}]|[\x{1FA00}-\x{1FA9F}]|\x{200D}/u', '', $request->input('title', '')),
        ]);

        $validated = $request->validate($this->galleryValidationRules());

        $planHolder = $team ? $team->owner : $user;

        if (! $planHolder->isPro()) {
            unset($validated['opens_at'], $validated['closes_at']);
        }

        if (!empty($validated['venue_template_id'])
            && ($redirect = $this->assertVenueAccessibleForPlan($validated['venue_template_id'], $planHolder))) {
            return $redirect;
        }

        $audioPath = null;
        if ($request->hasFile('audio') && $planHolder->isPro()) {
            $audioPath = $request->file('audio')->store('audio', 'public');
        }

        $logoPath = null;
        if ($request->hasFile('custom_logo') && $planHolder->plan === 'studio') {
            $logoPath = $request->file('custom_logo')->store('branding', 'public');
        }

        $venueTemplateId = !empty($validated['venue_template_id']) ? $validated['venue_template_id'] : null;

        // Custom domain is Studio-plan only
        $customDomain = null;
        if (!empty($validated['custom_domain']) && $planHolder->plan === 'studio') {
            $customDomain = $this->normaliseCustomDomain($validated['custom_domain']);
            // Uniqueness check
            if (Gallery::where('custom_domain', $customDomain)->exists()) {
                return back()->withInput()->with('error', "The custom domain \"{$customDomain}\" is already in use.");
            }
        }

        try {
            $gallery = Gallery::create([
                'user_id'          => $user->id,
                'team_id'          => $team?->id,
                'title'            => $validated['title'],
                'is_active'        => false,
                'description'      => $validated['description'] ?? null,
                'wall_texture'     => $validated['wall_texture'],
                'frame_style'      => $validated['frame_style'],
                'lighting_preset'  => $validated['lighting_preset'],
                'floor_material'   => $validated['floor_material'],
                'room_layout'      => $validated['room_layout'],
                'pin_hash'         => !empty($validated['gallery_pin']) ? Hash::make($validated['gallery_pin']) : null,
                'opens_at'         => $validated['opens_at'] ?? null,
                'closes_at'        => $validated['closes_at'] ?? null,
                'venue_template_id' => $venueTemplateId,
                'audio_path'        => $audioPath,
                'custom_logo_path'  => $logoPath,
                'custom_domain'     => $customDomain,
                'visual_overrides'  => $this->normalizeVisualOverrides(
                    $this->parseVisualOverrides($validated['visual_overrides_json'] ?? null),
                    $venueTemplateId ? VenueTemplate::find($venueTemplateId) : null
                ),
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            $this->deleteFilesQuietly(array_filter([$audioPath, $logoPath]));

            // The existence check above cannot see a domain another request
            // (or a soft-deleted exhibition) claimed in the meantime.
            return back()
                ->withInput()
                ->with('error', "The custom domain \"{$customDomain}\" is already in use.");
        } catch (\Throwable $e) {
            $this->deleteFilesQuietly(array_filter([$audioPath, $logoPath]));

            \Log::error('Gallery::create failed', [
                'message'  => $e->getMessage(),
                'title'    => $validated['title'] ?? null,
                'user_id'  => $user->id,
                'venue_id' => $venueTemplateId,
                'layout'   => $validated['room_layout'] ?? null,
            ]);
            return back()
                ->withInput()
                ->with('error', 'We couldn\'t create your gallery — nothing was lost. Please try again; if it keeps failing, contact support and mention what you were doing.');
        }

        if ($customDomain) {
            $result = $this->coolify->addDomain($customDomain);
            if (!$result['success']) {
                \Log::warning('Coolify domain registration deferred.', [
                    'gallery_id' => $gallery->id,
                    'domain'     => $customDomain,
                    'reason'     => $result['message'],
                ]);
                // Surface a soft warning to the user via session flash
                return redirect()->route('admin.galleries.edit', $gallery)
                    ->with('status', 'Gallery created as a draft — upload your artworks, then publish.')
                    ->with('warning', "Custom domain could not be auto-configured in Coolify: {$result['message']} DNS + SSL setup will need to be done manually.");
            }
        }

        $personalGalleryCount = Gallery::where('user_id', $user->id)
            ->whereNull('team_id')
            ->count();
        if ($personalGalleryCount === 1) {
            try {
                \Illuminate\Support\Facades\Mail::to($user->email)
                    ->send(new \App\Mail\FirstGalleryCreatedEmail($user, $gallery));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('FirstGalleryCreatedEmail send failed', [
                    'user_id'    => $user->id,
                    'gallery_id' => $gallery->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        return redirect()->route('admin.galleries.edit', $gallery)
                         ->with('status', 'Gallery created as a draft — upload your artworks, then publish.');
    }

    // ── Publish / Unpublish ─────────────────────────────────────────────

    public function publish(Request $request, Gallery $gallery): \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
    {
        $this->authorizeGalleryAccess($gallery, requireEdit: true);

        if ($gallery->images()->count() === 0) {
            $message = 'Add at least one artwork before publishing — visitors would see an empty exhibition.';
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'message' => $message], 422);
            }
            return back()->with('error', $message);
        }

        if (! $gallery->is_active) {
            $gallery->is_active = true;
            if ($gallery->published_at === null) {
                $gallery->published_at = now();
            }
            $gallery->save();
            $this->invalidateGalleryCaches($gallery);
        }

        $message = 'Exhibition is live! Share your link to get your first view.';
        if ($request->wantsJson() || $request->ajax()) {
            return response()->json(['success' => true, 'message' => $message, 'is_active' => true]);
        }
        return back()->with('status', $message);
    }

    public function unpublish(Request $request, Gallery $gallery): \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
    {
        $this->authorizeGalleryAccess($gallery, requireEdit: true);

        if ($gallery->is_active) {
            $gallery->is_active = false;
            $gallery->save();
            $this->invalidateGalleryCaches($gallery);
        }

        $message = 'Exhibition is back to draft — the public link is now inactive.';
        if ($request->wantsJson() || $request->ajax()) {
            return response()->json(['success' => true, 'message' => $message, 'is_active' => false]);
        }
        return back()->with('status', $message);
    }

    public function duplicate(Gallery $gallery): RedirectResponse
    {
        $this->authorizeGalleryAccess($gallery, requireEdit: true);

        $user = Auth::user();
        $team = $gallery->team;

        // Plan limit check
        if ($redirect = $this->checkGalleryLimit($user, $team)) {
            return $redirect;
        }

        // Create the clone
        $clone = $gallery->replicate([
            'id', 'slug', 'view_count', 'opens_at', 'closes_at',
            'custom_domain', // custom domains are unique — never copy
            'created_at', 'updated_at',
            'published_at',
        ]);

        $clone->title       = $gallery->title . ' (Copy)';
        $clone->slug        = null; // boot() will generate a new one
        $clone->view_count  = 0;
        $clone->is_active   = $gallery->is_active;
        $clone->published_at = $gallery->is_active ? now() : null;
        $clone->is_featured = false;
        $clone->custom_domain_verification_token = null;
        $clone->custom_domain_verified_at        = null;

        // Copy audio + logo files on disk so the clone is independent
        if ($gallery->audio_path) {
            $newPath = $this->copyFile($gallery->audio_path, 'audio');
            if ($newPath) $clone->audio_path = $newPath;
        }
        if ($gallery->custom_logo_path) {
            $newPath = $this->copyFile($gallery->custom_logo_path, 'branding');
            if ($newPath) $clone->custom_logo_path = $newPath;
        }
        // Also copy the curtain logo
        if ($gallery->curtain_logo_path) {
            $newPath = $this->copyFile($gallery->curtain_logo_path, 'branding');
            if ($newPath) $clone->curtain_logo_path = $newPath;
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($gallery, $clone) {
            $clone->save();

            // Copy all images — duplicate files on disk + create new GalleryImage rows
            foreach ($gallery->images()->orderBy('position_order')->get() as $image) {
                $newImagePath = $this->copyFile($image->path, 'gallery-images');
                if (!$newImagePath) {
                    \Log::warning("Duplicate: failed to copy image {$image->path}");
                    continue;
                }
                \App\Models\GalleryImage::create([
                'gallery_id'     => $clone->id,
                'filename'       => $image->filename,
                'original_name'  => $image->original_name,
                'path'           => $newImagePath,
                'mime_type'      => $image->mime_type,
                'size'           => $image->size,
                'width'          => $image->width,
                'height'         => $image->height,
                'orientation'    => $image->orientation,
                'position_order' => $image->position_order,
                'wall_position'  => $image->wall_position,
                'title'          => $image->title,
                'description'    => $image->description,
                'artist_id'      => $image->artist_id,
                'price'          => $image->price,
                'currency'       => $image->currency,
                'for_sale'       => $image->for_sale,
                'medium'         => $image->medium,
                'year'           => $image->year,
                'dimensions'     => $image->dimensions,
                'edition_size'   => $image->edition_size,
                'edition_number' => $image->edition_number,
                'external_url'   => $image->external_url,
                ]);
            }
        });

        $redirectParams = $team ? ['team' => $team->id] : [];
        return redirect()
            ->route('admin.galleries.index', $redirectParams)
            ->with('status', "Gallery duplicated as \"{$clone->title}\".");
    }

    public function show(Gallery $gallery)
    {
        $this->authorizeGalleryAccess($gallery);
        return redirect()->route('admin.galleries.edit', $gallery);
    }

    public function edit(Gallery $gallery): View
    {
        $this->authorizeGalleryAccess($gallery);
        $gallery->load('images', 'venueTemplate', 'team.owner');
        $venueTemplates = \App\Models\VenueTemplate::active()
            ->published()
            ->orderBy('sort_order')
            ->get();

        $artistOptions = \App\Models\Artist::query()
            ->where('created_by', $gallery->user_id)
            ->orWhereIn('id', $gallery->images->pluck('artist_id')->filter())
            ->orderBy('name')
            ->pluck('name', 'id');

        return view('admin.galleries.edit', compact('gallery', 'venueTemplates', 'artistOptions'));
    }

    public function preview(Request $request, Gallery $gallery): View
    {
        $this->authorizeGalleryAccess($gallery);
        $gallery->load(['images.artist', 'images.media', 'user', 'venueTemplate']);

        $runtimeOverrides = [];
        if ($request->filled('override')) {
            $decoded = base64_decode(strtr($request->input('override'), '-_', '+/'), true);
            if ($decoded !== false) {
                $parsed = json_decode($decoded, true);
                if (is_array($parsed)) {
                    $runtimeOverrides = $parsed;
                }
            }
        }

        $venueConfig = $gallery->venueTemplate
            ? $this->venueExporter->forGalleryPreview($gallery, $runtimeOverrides)
            : null;

        $galleryData = $this->buildGalleryData($gallery, $venueConfig, isPreview: true);

        $galleryData['isPreview'] = true;

        return view('admin.galleries.preview', compact('gallery', 'galleryData'));
    }

    public function update(Request $request, Gallery $gallery): \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
    {
        $this->authorizeGalleryAccess($gallery, requireEdit: true);

        // Strip emoji from title
        $request->merge([
            'title' => preg_replace('/[\x{1F000}-\x{1FFFF}]|[\x{2600}-\x{27FF}]|[\x{2B00}-\x{2BFF}]|[\x{FE00}-\x{FE0F}]|[\x{1F300}-\x{1F9FF}]|[\x{1FA00}-\x{1FA9F}]|\x{200D}/u', '', $request->input('title', '')),
        ]);

        $validated = $request->validate($this->galleryValidationRules(isUpdate: true));

        $planHolder = $this->galleryPlanHolder($gallery);

        if (!empty($validated['venue_template_id'])
            && ($redirect = $this->assertVenueAccessibleForPlan($validated['venue_template_id'], $planHolder))) {
            return $redirect;
        }

        // Delegate to extracted helpers
        [$staleFiles, $uploadedFiles] = $this->handleFileUploads($request, $gallery, $planHolder, $validated);
        $this->handlePinAndSchedule($request, $validated, $planHolder);
        $this->handleVenueTemplate($validated);

        $submittedVenueId = $validated['venue_template_id'] ?? null;
        if (!empty($submittedVenueId)
            && (int) $submittedVenueId !== (int) $gallery->venue_template_id) {
            $validated['visual_overrides'] = null;
            unset($validated['visual_overrides_json']);

            $newVenue = VenueTemplate::find($submittedVenueId);
            if ($newVenue) {
                $venueDefaults = $newVenue->default_settings ?? [];
                foreach (['wall_texture', 'floor_material', 'frame_style', 'lighting_preset', 'room_layout'] as $exhibitionKey) {
                    if (!empty($venueDefaults[$exhibitionKey])) {
                        $validated[$exhibitionKey] = $venueDefaults[$exhibitionKey];
                    }
                }
            }
        } else {
            $normalizedAgainstId = $validated['venue_template_id'] ?? $gallery->venue_template_id;
            $validated['visual_overrides'] = $this->normalizeVisualOverrides(
                $this->parseVisualOverrides($validated['visual_overrides_json'] ?? null),
                $normalizedAgainstId ? VenueTemplate::find($normalizedAgainstId) : null
            );
        }

        // Custom domain handling may return early on uniqueness conflict
        $domainResult = $this->handleCustomDomain($request, $gallery, $planHolder, $validated);
        if ($domainResult !== null) {
            // Files stored for this request were never committed.
            $this->deleteFilesQuietly($uploadedFiles);

            return $domainResult; // Redirect back with error
        }

        if (array_key_exists('seo_title', $validated) || array_key_exists('seo_description', $validated)) {
            $profile = $gallery->seoProfileOrCreate();
            $profile->fill([
                'title_override'       => $validated['seo_title'] ?? null,
                'description_override' => $validated['seo_description'] ?? null,
                'updated_by'           => $request->user()->id,
            ])->save();
            unset($validated['seo_title'], $validated['seo_description']);
        }

        // Remove non-fillable keys before update
        unset($validated['gallery_pin'], $validated['clear_pin'], $validated['audio'], $validated['custom_logo'],
              $validated['curtain_logo'], $validated['clear_curtain_logo'], $validated['clear_curtain_bg'],
              $validated['curtain_bg_color_text'], $validated['visual_overrides_json']);

        try {
            $gallery->update($validated);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // The existence check in handleCustomDomain() cannot see a domain
            // another request claimed in the meantime. Files stored for this
            // request were never committed — remove them.
            $this->deleteFilesQuietly($uploadedFiles);

            $domain = $validated['custom_domain'] ?? 'chosen';

            return back()->withInput()
                ->with('error', "The custom domain \"{$domain}\" is already in use.");
        } catch (\Throwable $e) {
            // The row update failed; the bytes this request wrote would
            // otherwise linger with no database reference.
            $this->deleteFilesQuietly($uploadedFiles);
            throw $e;
        }

        // Post-update: set guarded custom-domain verification fields
        $this->applyPostUpdateGuardedFields($request, $gallery);

        $this->deleteStaleFiles($staleFiles);

        $this->invalidateGalleryCaches($gallery);

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json(['success' => true, 'message' => 'Gallery settings updated!']);
        }
        return back()->with('status', 'Gallery settings updated!');
    }

    private function handleFileUploads(Request $request, Gallery $gallery, $planHolder, array &$validated): array
    {
        $staleFiles = [];
        $uploadedFiles = [];

        // Audio (Pro+)
        if ($request->hasFile('audio') && $planHolder->isPro()) {
            if ($gallery->audio_path) $staleFiles[] = $gallery->audio_path;
            $uploadedFiles[] = $validated['audio_path'] = $request->file('audio')->store('audio', 'public');
        }

        // Custom logo (Studio only)
        if ($request->hasFile('custom_logo') && $planHolder->plan === 'studio') {
            if ($gallery->custom_logo_path) $staleFiles[] = $gallery->custom_logo_path;
            $uploadedFiles[] = $validated['custom_logo_path'] = $request->file('custom_logo')->store('branding', 'public');
        }

        // Curtain logo (Studio only) — upload or clear
        if ($planHolder->plan === 'studio') {
            if ($request->hasFile('curtain_logo')) {
                if ($gallery->curtain_logo_path) $staleFiles[] = $gallery->curtain_logo_path;
                $uploadedFiles[] = $validated['curtain_logo_path'] = $request->file('curtain_logo')->store('branding', 'public');
            } elseif ($request->boolean('clear_curtain_logo') && $gallery->curtain_logo_path) {
                $staleFiles[] = $gallery->curtain_logo_path;
                $validated['curtain_logo_path'] = null;
            }

            // Curtain background color — clear or validate hex
            if ($request->boolean('clear_curtain_bg')) {
                $validated['curtain_bg_color'] = null;
            } elseif (!empty($validated['curtain_bg_color'])) {
                if (!preg_match('/^#[0-9a-fA-F]{6}$/', $validated['curtain_bg_color'])) {
                    $validated['curtain_bg_color'] = null;
                }
            }
        }

        return [$staleFiles, $uploadedFiles];
    }

    private function deleteStaleFiles(array $paths): void
    {
        $this->deleteFilesQuietly($paths);
    }

    /**
     * Best-effort filesystem cleanup for paths written by a request whose
     * database change never committed. A failed unlink is logged, never
     * fatal — the caller has already decided the response.
     */
    private function deleteFilesQuietly(array $paths): void
    {
        foreach (array_filter($paths) as $path) {
            try {
                \Storage::disk('public')->delete($path);
            } catch (\Throwable $e) {
                \Log::warning('GalleryController: file cleanup failed', [
                    'path'  => $path,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function handlePinAndSchedule(Request $request, array &$validated, $planHolder): void
    {
        if ($request->boolean('clear_pin')) {
            $validated['pin_hash'] = null;
        } elseif (! empty($validated['gallery_pin'])) {
            $validated['pin_hash'] = Hash::make($validated['gallery_pin']);
        }

        if ($planHolder->isPro()) {
            $validated['opens_at']  = $validated['opens_at']  ?? null;
            $validated['closes_at'] = $validated['closes_at'] ?? null;
        } else {
            unset($validated['opens_at'], $validated['closes_at']);
        }
    }

    private function handleVenueTemplate(array &$validated): void
    {
        if (array_key_exists('venue_template_id', $validated)) {
            $validated['venue_template_id'] = !empty($validated['venue_template_id'])
                ? $validated['venue_template_id']
                : null;
        }
    }

    private function handleCustomDomain(Request $request, Gallery $gallery, $planHolder, array &$validated): ?\Illuminate\Http\RedirectResponse
    {
        if (! array_key_exists('custom_domain', $validated)) {
            return null;
        }

        $cd = $validated['custom_domain'];

        if (!empty($cd) && $planHolder->plan === 'studio') {
            // Studio user setting a new domain
            $cd = $this->normaliseCustomDomain($cd);
            $exists = Gallery::where('custom_domain', $cd)
                ->where('id', '!=', $gallery->id)
                ->exists();
            if ($exists) {
                return back()->withInput()
                    ->with('error', "The custom domain \"{$cd}\" is already in use.");
            }

            $oldDomain = $gallery->getOriginal('custom_domain');
            $domainChanged = $cd !== $oldDomain;

            $validated['custom_domain'] = $cd;

            \Illuminate\Support\Facades\Cache::forget("custom_domain:{$cd}");
            if ($domainChanged && $oldDomain) {
                \Illuminate\Support\Facades\Cache::forget("custom_domain:{$oldDomain}");
            }

            if ($domainChanged) {
                $request->attributes->set('_pending_domain_token', \Illuminate\Support\Str::random(32));

                if ($oldDomain && $gallery->custom_domain_verified_at) {
                    $this->coolify->removeDomain($oldDomain);
                }
            }
        } elseif (empty($cd)) {
            // Domain was cleared
            if ($gallery->custom_domain) {
                $oldDomain = $gallery->custom_domain;
                \Illuminate\Support\Facades\Cache::forget("custom_domain:{$oldDomain}");
                if ($gallery->custom_domain_verified_at) {
                    $this->coolify->removeDomain($oldDomain);
                }
            }
            $validated['custom_domain'] = null;
            $request->attributes->set('_clear_domain_verification', true);
        } else {
            // Non-Studio plan trying to set a custom domain — block silently
            unset($validated['custom_domain']);
        }

        return null;
    }

    private function applyPostUpdateGuardedFields(Request $request, Gallery $gallery): void
    {
        if ($request->attributes->has('_pending_domain_token')) {
            $gallery->forceFill([
                'custom_domain_verification_token' => $request->attributes->get('_pending_domain_token'),
                'custom_domain_verified_at'        => null,
            ])->save();

            session()->flash('info', 'Custom domain saved. Add the TXT record shown below to your DNS, then click "Verify domain".');
        } elseif ($request->attributes->get('_clear_domain_verification')) {
            $gallery->forceFill([
                'custom_domain_verification_token' => null,
                'custom_domain_verified_at'        => null,
            ])->save();
        }
    }

    public function verifyCustomDomain(Request $request, Gallery $gallery)
    {
        $this->authorizeGalleryAccess($gallery, requireEdit: true);

        if (empty($gallery->custom_domain)) {
            return back()->with('error', 'No custom domain to verify.');
        }

        if ($gallery->isCustomDomainVerified()) {
            return back()->with('status', 'Domain is already verified.');
        }

        if (empty($gallery->custom_domain_verification_token)) {
            $gallery->generateDomainVerificationToken();
            $gallery->refresh();
        }

        $verified = $this->checkDnsTxtRecord(
            $gallery->domainVerificationTxtHost(),
            $gallery->domainVerificationTxtValue()
        );

        if (! $verified) {
            return back()->with('error', 'DNS verification failed. Make sure the TXT record has propagated (this can take 5–60 minutes for some DNS providers), then try again.');
        }

        // ── Verified! Mark + register with Coolify ──────────────────────
        $gallery->forceFill(['custom_domain_verified_at' => now()])->save();

        \Illuminate\Support\Facades\Cache::forget("custom_domain:{$gallery->custom_domain}");

        $coolifyResult = $this->coolify->addDomain($gallery->custom_domain);
        if (! $coolifyResult['success']) {
            \Log::warning('Coolify domain registration deferred on verification.', [
                'gallery_id' => $gallery->id,
                'domain'     => $gallery->custom_domain,
                'reason'     => $coolifyResult['message'],
            ]);
            session()->flash('warning', "Domain verified, but Coolify could not auto-configure the routing: {$coolifyResult['message']}");
        }

        return back()->with('status', "Domain \"{$gallery->custom_domain}\" verified! SSL cert will be provisioned automatically (may take 1–5 minutes).");
    }

    private function checkDnsTxtRecord(string $host, string $expectedValue): bool
    {
        if (empty($host) || empty($expectedValue)) {
            return false;
        }

        $records = @dns_get_record($host, DNS_TXT);

        if (! is_array($records)) {
            \Log::info('Custom domain DNS lookup failed (no array returned)', [
                'host' => $host,
            ]);
            return false;
        }

        foreach ($records as $record) {
            $candidates = [];
            if (isset($record['txt'])) {
                $candidates[] = trim($record['txt'], '"');
            }
            if (isset($record['entries']) && is_array($record['entries'])) {
                foreach ($record['entries'] as $entry) {
                    $candidates[] = trim($entry, '"');
                }
            }

            foreach ($candidates as $candidate) {
                if (hash_equals($expectedValue, $candidate)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function destroy(Gallery $gallery): RedirectResponse
    {
        $this->authorizeGalleryAccess($gallery, requireEdit: true);
        $teamId = $gallery->team_id;
        $hadCustomDomain = ! empty($gallery->custom_domain);
        $wasDomainVerified = $hadCustomDomain && $gallery->custom_domain_verified_at !== null;

        if ($gallery->custom_domain) {
            $oldDomain = $gallery->custom_domain;
            \Illuminate\Support\Facades\Cache::forget("custom_domain:{$oldDomain}");
            if ($gallery->custom_domain_verified_at) {
                $this->coolify->removeDomain($oldDomain);
            }
        }

        $gallery->delete();

        // Log gallery deletion. 'name' is PII — auto-scrubbed.
        AdminAuditLog::record('gallery.deleted', $gallery, [
            'title'                 => $gallery->title,
            'slug'                  => $gallery->slug,
            'team_id'               => $teamId,
            'had_custom_domain'     => $hadCustomDomain,
            'custom_domain_verified' => $wasDomainVerified,
        ]);

        return redirect()->route('admin.galleries.index', $teamId ? ['team' => $teamId] : [])
                         ->with('status', 'Gallery deleted.');
    }

    public function reorderImages(Request $request, Gallery $gallery)
    {
        $this->authorizeGalleryAccess($gallery, requireEdit: true);
        $request->validate(['order' => 'required|array', 'order.*' => 'integer']);

        foreach ($request->order as $position => $imageId) {
            $gallery->images()->where('id', $imageId)->update(['position_order' => $position + 1]);
        }

        // Mass updates bypass model events, so nothing else refreshes the
        // caches that depend on image order: the OG card picks its cover by
        // position, and stamped payload caches key off the gallery timestamp.
        $gallery->touch();
        \App\Support\SitemapVersion::bump();

        return response()->json(['success' => true]);
    }

    public function uploadAudio(Request $request, Gallery $gallery)
    {
        $this->authorizeGalleryAccess($gallery, requireEdit: true);

        if (! $this->galleryPlanHolder($gallery)->isPro()) {
            return response()->json(['success' => false, 'message' => 'Upgrade to Pro to use background music'], 403);
        }

        $request->validate(['audio' => 'required|file|mimes:mp3,wav,m4a|max:10240']);
        try {
            $audioPath = $request->file('audio')->store('audio', 'public');
            $oldPath = $gallery->audio_path;
            try {
                $gallery->update(['audio_path' => $audioPath]);
            } catch (\Throwable $e) {
                try {
                    \Storage::disk('public')->delete($audioPath);
                } catch (\Throwable) {
                    // Best-effort cleanup; the DB failure is reported below.
                }
                throw $e;
            }
            if ($oldPath) \Storage::disk('public')->delete($oldPath);
            return response()->json(['success' => true, 'message' => 'Background music uploaded successfully!', 'audio_url' => asset('storage/' . $audioPath), 'filename' => basename($audioPath)]);
        } catch (\Exception $e) {
            \Log::error('Audio upload failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Upload failed. Please try again.'], 500);
        }
    }

    public function uploadLogo(Request $request, Gallery $gallery)
    {
        $this->authorizeGalleryAccess($gallery, requireEdit: true);

        if ($this->galleryPlanHolder($gallery)->plan !== 'studio') {
            return response()->json(['success' => false, 'message' => 'Upgrade to Studio to use custom branding'], 403);
        }

        $request->validate(['custom_logo' => 'required|file|mimes:png,jpg,jpeg|max:2048']);
        try {
            $logoPath = $request->file('custom_logo')->store('branding', 'public');
            $oldPath = $gallery->custom_logo_path;
            try {
                $gallery->update(['custom_logo_path' => $logoPath]);
            } catch (\Throwable $e) {
                try {
                    \Storage::disk('public')->delete($logoPath);
                } catch (\Throwable) {
                    // Best-effort cleanup; the DB failure is reported below.
                }
                throw $e;
            }
            if ($oldPath) \Storage::disk('public')->delete($oldPath);
            return response()->json(['success' => true, 'message' => 'Custom logo uploaded successfully!', 'logo_url' => asset('storage/' . $logoPath), 'filename' => basename($logoPath)]);
        } catch (\Exception $e) {
            \Log::error('Logo upload failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Upload failed. Please try again.'], 500);
        }
    }

    private function resolveTeamContext($user, ?string $teamId): ?Team
    {
        if (! $teamId) {
            $teamId = $user->current_team_id;
        }
        if (! $teamId) return null;

        $team = Team::find($teamId);
        return ($team && $user->belongsToTeam($team)) ? $team : null;
    }

    private function resolveEditableTeam($user, ?string $teamId): ?Team
    {
        if (! $teamId) {
            $teamId = $user->current_team_id;
        }
        if (! $teamId) return null;

        $team = Team::find($teamId);
        return ($team && $team->canEdit($user)) ? $team : null;
    }

    private function checkGalleryLimit($user, ?Team $team): ?RedirectResponse
    {
        if (! $team) {
            if (! $user->canCreateGallery()) {
                return redirect()->route('admin.galleries.index')->with('upgrade', true);
            }
            return null;
        }

        $owner = $team->owner;
        if (Gallery::where('team_id', $team->id)->count() >= $owner->max_galleries) {
            return redirect()->route('admin.galleries.index', ['team' => $team->id])->with('upgrade', true);
        }

        return null;
    }

    private function normaliseCustomDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = explode('/', $domain)[0];
        $domain = explode(':', $domain)[0];
        return $domain;
    }

    private function copyFile(?string $path, string $folder): ?string
    {
        if (!$path) return null;
        try {
            $disk = Storage::disk('public');
            if (!$disk->exists($path)) return null;
            $ext = pathinfo($path, PATHINFO_EXTENSION);
            $newName = $folder . '/' . \Str::random(40) . ($ext ? '.' . $ext : '');
            $disk->copy($path, $newName);
            return $newName;
        } catch (\Throwable $e) {
            \Log::warning("copyFile failed for {$path}: {$e->getMessage()}");
            return null;
        }
    }

    private function parseVisualOverrides(?string $json): ?array
    {
        if (!$json || trim($json) === '') return null;
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) return null;

        $clean = [
            'visual_config'   => is_array($decoded['visual_config']   ?? null) ? $decoded['visual_config']   : [],
            'material_config' => is_array($decoded['material_config'] ?? null) ? $decoded['material_config'] : [],
            'post_fx'         => is_array($decoded['post_fx']         ?? null) ? $decoded['post_fx']         : [],
        ];

        $clean = array_filter($clean, fn ($bucket) => !empty($bucket));
        return empty($clean) ? null : $clean;
    }

    private function normalizeVisualOverrides(?array $overrides, ?VenueTemplate $venue): ?array
    {
        if (!$overrides || !$venue) {
            return $overrides;
        }

        foreach (array_keys($overrides['visual_config'] ?? []) as $key) {
            if (VenueConfigExporter::isVenueOwnedKey((string) $key)) {
                unset($overrides['visual_config'][$key]);
            }
        }
        if (!empty($overrides['material_config'])) {
            foreach (VenueConfigExporter::VENUE_OWNED_MATERIAL_KEYS as $owned) {
                unset($overrides['material_config'][$owned]);
            }
        }
        unset($overrides['post_fx']);
        if (empty($overrides['visual_config'])) {
            unset($overrides['visual_config']);
        }
        if (empty($overrides['material_config'])) {
            unset($overrides['material_config']);
        }

        $venueVisual   = is_array($venue->visual_config)   ? $venue->visual_config   : [];
        $venueMaterial = is_array($venue->material_config) ? $venue->material_config : [];
        // The venue declares post-processing INSIDE visual_config.post_fx.
        $venuePostFx   = is_array($venueVisual['post_fx'] ?? null) ? $venueVisual['post_fx'] : [];

        $buckets = [
            'visual_config'   => $venueVisual,
            'material_config' => $venueMaterial,
            'post_fx'         => $venuePostFx,
        ];

        foreach ($buckets as $bucket => $defaults) {
            if (empty($overrides[$bucket]) || empty($defaults)) {
                continue;
            }
            foreach ($overrides[$bucket] as $key => $value) {
                if (!array_key_exists($key, $defaults)) {
                    // Undeclared in the venue — a real deviation either way.
                    $overrides[$bucket][$key] = $this->canonicalizeOverrideValue($value);
                    continue;
                }
                if ($this->overrideValueEquals($value, $defaults[$key])) {
                    unset($overrides[$bucket][$key]);
                } else {
                    $overrides[$bucket][$key] = $this->canonicalizeOverrideValue($value);
                }
            }
            if (empty($overrides[$bucket])) {
                unset($overrides[$bucket]);
            }
        }

        return empty($overrides) ? null : $overrides;
    }

    private function overrideValueEquals($a, $b): bool
    {
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a === (float) $b;
        }
        if (is_string($a) && is_string($b)) {
            $ca = $this->canonicalizeOverrideValue($a);
            $cb = $this->canonicalizeOverrideValue($b);
            return $ca === $cb;
        }
        return $a === $b;
    }

    private function canonicalizeOverrideValue($value)
    {
        if (is_string($value) && preg_match('/^(?:0x|#)([0-9a-fA-F]{6})$/', $value, $m)) {
            return '0x' . strtolower($m[1]);
        }
        return $value;
    }

    private function buildGalleryData(Gallery $gallery, ?array $venueConfig, bool $isPreview = false): array
    {
        [$preset, $layout] = [
            $this->venueExporter->presetForGallery($gallery),
            $this->venueExporter->layoutForGallery($gallery),
        ];

        return [
            'id'          => $gallery->id,
            'title'       => $gallery->title,
            'description' => $gallery->description,
            'wall_texture'    => $gallery->wall_texture,
            'floor_material'  => $gallery->floor_material,
            'frame_style'     => $gallery->frame_style,
            'lighting_preset' => $preset,
            'room_layout'     => $layout,
            'venue_slug'      => $gallery->venueTemplate?->slug,
            'venueConfig'     => $venueConfig,
            'images' => $gallery->images->map(fn($img) => array_filter([
                'id'             => $img->id,
                'url'            => asset($img->path),
                'textures'       => [
                    'thumb'  => $img->conversionUrl('thumb'),
                    'small'  => $img->conversionUrl('small'),
                    'medium' => $img->conversionUrl('medium'),
                    'large'  => $img->conversionUrl('large'),
                ],
                'width'          => $img->width,
                'height'         => $img->height,
                'aspectRatio'    => $img->width / max($img->height, 1),
                'orientation'    => $img->orientation,
                'title'          => $img->title ?? $img->original_name,
                'description'    => $img->description,
                'artist'         => $img->artist ? [
                    'id'     => $img->artist->id,
                    'name'   => $img->artist->name,
                    'slug'   => $img->artist->slug,
                    'url'    => route('artist.profile', $img->artist->slug),
                ] : null,
                'price'          => $img->price ? (float) $img->price : null,
                'currency'       => $img->currency,
                'formattedPrice' => $img->formattedPrice(),
                'forSale'        => (bool) $img->for_sale,
                'medium'         => $img->medium,
                'year'           => $img->year,
                'dimensions'     => $img->dimensions,
                'edition'        => $img->formattedEdition(),
                'externalUrl'    => $img->external_url,
            ], fn ($v) => $v !== null))->values(),
            'imageCount'     => $gallery->images->count(),
            'audioUrl'       => $gallery->audio_path ? asset('storage/' . $gallery->audio_path) : null,
            'userPlan'       => $gallery->user->plan ?? 'free',
            'customLogoUrl'  => ($gallery->custom_logo_path && $gallery->user->plan === 'studio')
                                    ? asset('storage/' . $gallery->custom_logo_path)
                                    : null,
            'curtainLogoUrl' => ($gallery->curtain_logo_path && $gallery->user->plan === 'studio')
                                    ? asset('storage/' . $gallery->curtain_logo_path)
                                    : null,
            'curtainBgColor' => ($gallery->curtain_bg_color && $gallery->user->plan === 'studio')
                                    ? $gallery->curtain_bg_color
                                    : null,
            'newsletterUrl'  => $isPreview ? null : route('gallery.newsletter', $gallery->slug),
            'eventsUrl'      => $isPreview ? null : route('gallery.events.index', $gallery->slug),
            'hasUpcomingEvents' => $isPreview ? false : $gallery->scheduleEvents()->active()->upcoming()->exists(),

            'arrival_enabled' => \App\Services\FeatureFlag::isEnabled('arrival_choreography'),
        ];
    }

    private function assertVenueAccessibleForPlan(int $venueTemplateId, \App\Models\User $planHolder): ?\Illuminate\Http\RedirectResponse
    {
        $venue = \App\Models\VenueTemplate::find($venueTemplateId);

        if (! $venue || $venue->isAccessibleBy($planHolder)) {
            return null;
        }

        \Log::warning('Venue plan-tier enforcement: rejected venue above plan', [
            'venue_id'        => $venueTemplateId,
            'venue_plan'      => $venue?->plan_required,
            'plan_holder_id'  => $planHolder->id,
            'plan'            => $planHolder->plan,
        ]);

        return back()->withInput()->with('error',
            "The \"{$venue->name}\" venue requires the " . ucfirst($venue->plan_required) .
            " plan. Please choose a venue available on your current plan or upgrade."
        );
    }

    private function invalidateGalleryCaches(Gallery $gallery): void
    {
        // Only tags that are actually written get flushed — analytics blocks
        // are tagged via CacheTagService. Everything public (OG images, QR
        // codes, sitemaps, SEO listings, viewer config) rides on stamped
        // keys that rotate through SitemapVersion and row timestamps.
        app(\App\Services\CacheTagService::class)->invalidateTags([
            'analytics',
            "analytics:gallery:{$gallery->id}",
        ]);
    }

    private function galleryValidationRules(bool $isUpdate = false): array
    {
        $rules = [
            'title'           => 'required|string|max:255',
            'description'     => 'nullable|string|max:1000',
            'seo_title'       => 'nullable|string|max:200',
            'seo_description' => 'nullable|string|max:300',
            'wall_texture'    => 'required|in:white,concrete,brick,wood,plaster,marble,velvet',
            'frame_style'     => 'required|in:modern,classic,minimal,gold,silver,bronze,black',
            'lighting_preset' => 'required|in:bright,moody,dramatic',
            'floor_material'  => 'required|in:wood,marble,concrete,terrazzo,grass,sand',
            'room_layout'     => 'required|in:square,corridor,l-shape,rotunda',
            'venue_template_id' => ['nullable', 'integer',
                \Illuminate\Validation\Rule::exists('venue_templates', 'id')
                    ->where(fn ($q) => $q->where('is_active', true)->where('is_draft', false)),
            ],
            'gallery_pin'     => 'nullable|digits:4',
            'opens_at'        => 'nullable|date',
            'closes_at'       => 'nullable|date|after_or_equal:opens_at',
            'audio'           => 'nullable|file|mimes:mp3,wav,m4a|max:10240',
            'custom_logo'     => 'nullable|file|mimes:png,jpg,jpeg|max:2048',
            'custom_domain'   => ['nullable', 'string', 'max:255', 'regex:/^([a-z0-9-]+\.)+[a-z]{2,}$/i'],
            // NEW (Round 4) — Branded entrance curtain (Studio only)
            'curtain_logo'        => 'nullable|file|mimes:png,jpeg,webp|max:2048',
            'curtain_bg_color'    => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'curtain_bg_color_text' => 'nullable|string|max:20',
            'visual_overrides_json' => ['nullable', 'string', 'max:16000', 'regex:/^\s*(\{.*\}|\[\])?\s*$/s'],
        ];

        if ($isUpdate) {
            $rules['clear_pin']          = 'nullable|boolean';
            $rules['clear_curtain_logo'] = 'nullable|boolean';
            $rules['clear_curtain_bg']   = 'nullable|boolean';
        }

        return $rules;
    }
}
