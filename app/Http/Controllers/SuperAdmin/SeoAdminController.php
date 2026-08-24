<?php

declare(strict_types=1);

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Artist;
use App\Models\Gallery;
use App\Models\SeoPage;
use App\Models\SeoProfile;
use App\Models\SeoRedirect;
use App\Services\Seo\SeoAuditService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Super-admin SEO console (Iteration 6).
 *
 * One surface for the whole operational layer:
 *   - Health dashboard (SeoAuditService — real data only)
 *   - seo_profiles overrides for galleries + artists (title/description for
 *     curators; robots/canonical/sitemap/structured-data for operators)
 *   - Redirect manager
 *   - SEO page list with quick publish/unpublish (full block editing stays
 *     in tinker/CLI — pages are structural, edited rarely)
 *   - Cache rebuild trigger (seo:rebuild)
 *
 * Audit trail: every mutation records to AdminAuditLog.
 */
class SeoAdminController extends Controller
{
    public function __construct(
        private SeoAuditService $audit,
    ) {}

    public function index(Request $request): View
    {
        $tab = $request->query('tab', 'health');

        $data = match ($tab) {
            'galleries' => $this->galleriesTab($request),
            'artists' => $this->artistsTab($request),
            'redirects' => $this->redirectsTab(),
            'pages' => $this->pagesTab(),
            default => $this->healthTab(),
        };

        return view('super-admin.seo.index', array_merge($data, ['tab' => $tab]));
    }

    // ── Tabs ─────────────────────────────────────────────────────────────

    private function healthTab(): array
    {
        return [
            'summary' => $this->audit->summary(),
            'issues' => $this->audit->issues(),
        ];
    }

    private function galleriesTab(Request $request): array
    {
        $search = trim((string) $request->query('q', ''));
        $filter = (string) $request->query('filter', 'public');

        return ['galleries' => $this->audit->galleryTable($search, $filter), 'search' => $search, 'filter' => $filter];
    }

    private function artistsTab(Request $request): array
    {
        $search = trim((string) $request->query('q', ''));

        return ['artists' => $this->audit->artistTable($search), 'search' => $search];
    }

    private function redirectsTab(): array
    {
        return ['redirects' => SeoRedirect::query()->orderByDesc('created_at')->paginate(30)];
    }

    private function pagesTab(): array
    {
        return ['seoPages' => SeoPage::query()->orderByDesc('updated_at')->paginate(30)];
    }

    // ── Mutations ────────────────────────────────────────────────────────

    /**
     * Save the seo_profile for a gallery or artist.
     */
    public function updateProfile(Request $request, string $type, int $id): RedirectResponse
    {
        $validated = $request->validate([
            'title_override'        => ['nullable', 'string', 'max:200'],
            'description_override'  => ['nullable', 'string', 'max:300'],
            'canonical_override'    => ['nullable', 'string', 'max:500'],
            'robots_directive'      => ['nullable', 'string', 'max:100', 'regex:/^(index|noindex)(,(follow|nofollow))?$/'],
            'sitemap_include'       => ['nullable', 'in:0,1'],
            'structured_data'       => ['nullable', 'in:0,1'],
        ]);

        $subject = $this->resolveSubject($type, $id);
        if (!$subject) {
            abort(404);
        }

        $profile = $subject->seoProfileOrCreate();
        $profile->fill([
            'title_override'       => $validated['title_override'] ?: null,
            'description_override' => $validated['description_override'] ?: null,
            'canonical_override'   => $validated['canonical_override'] ?: null,
            'robots_directive'     => $validated['robots_directive'] ?: null,
            'sitemap_include'      => array_key_exists('sitemap_include', $validated) && $validated['sitemap_include'] !== null
                ? (bool) $validated['sitemap_include'] : null,
            'structured_data_enabled' => array_key_exists('structured_data', $validated) && $validated['structured_data'] !== null
                ? (bool) $validated['structured_data'] : null,
            'updated_by' => $request->user()->id,
        ])->save();

        // Entity metadata changed → refresh sitemap caches.
        \Illuminate\Support\Facades\Artisan::call('seo:rebuild');

        \App\Models\AdminAuditLog::record('seo.profile_updated', $subject, $validated);

        return back()->with('status', 'SEO profile saved.');
    }

    public function storeRedirect(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'source_path' => ['required', 'string', 'max:500'],
            'destination' => ['required', 'string', 'max:1000'],
            'status_code' => ['nullable', 'integer', 'in:301,302,308'],
        ]);

        $redirect = SeoRedirect::create([
            'source_path' => SeoRedirect::normalizePath($validated['source_path']),
            'destination' => $validated['destination'],
            'status_code' => $validated['status_code'] ?? 301,
            'created_by'  => $request->user()->id,
        ]);

        SeoRedirect::clearMapCache();

        \App\Models\AdminAuditLog::record('seo.redirect_created', $redirect, $validated);

        return back()->with('status', "Redirect /{$validated['source_path']} created.");
    }

    public function destroyRedirect(SeoRedirect $redirect): RedirectResponse
    {
        $source = $redirect->source_path;

        \App\Models\AdminAuditLog::record('seo.redirect_deleted', $redirect, []);

        $redirect->delete();
        SeoRedirect::clearMapCache();

        return back()->with('status', "Redirect /{$source} deleted.");
    }

    public function togglePage(SeoPage $page): RedirectResponse
    {
        $page->update([
            'status' => $page->status === 'published' ? 'draft' : 'published',
            'published_at' => $page->status === 'draft' ? now() : $page->published_at,
        ]);

        \Illuminate\Support\Facades\Artisan::call('seo:rebuild');

        \App\Models\AdminAuditLog::record('seo.page_status_toggled', $page, ['status' => $page->status]);

        return back()->with('status', "\"{$page->title}\" is now {$page->status}.");
    }

    public function rebuild(Request $request): RedirectResponse
    {
        \Illuminate\Support\Facades\Artisan::call('seo:rebuild');

        \App\Models\AdminAuditLog::record('seo.caches_rebuilt', $request->user(), []);

        return back()->with('status', 'SEO caches rebuilt.');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * @return Gallery|Artist|null
     */
    private function resolveSubject(string $type, int $id)
    {
        return match ($type) {
            'gallery' => Gallery::find($id),
            'artist' => Artist::find($id),
            default => null,
        };
    }
}
