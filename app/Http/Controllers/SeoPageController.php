<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\SeoPage;
use App\Services\Seo\SeoPageRenderer;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Public SEO page rendering (Iteration 5).
 *
 * Resolved via the FALLBACK route: real product routes always win; only
 * otherwise-unmatched paths are checked against the cached seo_pages slug
 * allow-list. Landing pages live at /{slug}, editorial content at
 * /{prefix}/{slug}.
 *
 * Preview: append ?preview={token} (page-specific HMAC token, see
 * SeoPage::previewToken()). Previews render with noindex regardless of
 * status.
 */
class SeoPageController extends Controller
{
    public function __construct(
        private SeoPageRenderer $renderer,
    ) {}

    /**
     * Fallback handler: render the matching seo_page or 404.
     */
    public function __invoke(Request $request)
    {
        if (!$request->isMethod('get') && !$request->isMethod('head')) {
            abort(405);
        }

        $path = trim($request->path(), '/');
        $isPreview = false;

        // Two candidate shapes: '{slug}' (landing) or '{prefix}/{slug}'
        // (editorial). The slug map carries both.
        $map = SeoPage::cachedSlugMap();

        $pageId = $map[$path] ?? null;

        if ($pageId === null) {
            // Maybe a preview of an UNPUBLISHED page (not in the map yet).
            $page = $this->findByPath($path);
            if ($page && $page->isValidPreviewToken($request->query('preview'))) {
                $isPreview = true;
            } else {
                abort(404);
            }
        } else {
            $page = SeoPage::query()->whereKey($pageId)->first();
            if (!$page) {
                abort(404);
            }

            // Published in the map but maybe scheduled for the future —
            // visible only with a valid preview token.
            if ($page->isScheduled()) {
                if ($page->isValidPreviewToken($request->query('preview'))) {
                    $isPreview = true;
                } else {
                    abort(404);
                }
            } elseif ($request->query('preview') !== null) {
                // Explicit preview on a live page is fine — still noindex.
                $isPreview = $page->isValidPreviewToken($request->query('preview'));
            }
        }

        $seo = $this->renderer->seoFor($page, $isPreview);
        $breadcrumbs = $this->renderer->breadcrumbsFor($page);

        return view('seo.pages.show', [
            'page'        => $page,
            'seoData'     => $seo,
            'breadcrumbs' => $breadcrumbs,
            'content'     => $this->renderer->renderBlocks($page),
            'isPreview'   => $isPreview,
        ]);
    }

    /**
     * Uncached path lookup for previews of unpublished pages.
     */
    private function findByPath(string $path): ?SeoPage
    {
        $editorialPrefix = (string) config('seo.pages.editorial_prefix', 'resources');

        if (str_contains($path, '/')) {
            [$prefix, $slug] = explode('/', $path, 2);
            if ($prefix !== $editorialPrefix || str_contains($slug, '/')) {
                return null;
            }

            return SeoPage::query()->where('type', 'editorial')->where('slug', $slug)->first();
        }

        // Landing path must be a single lowercase segment.
        if (!preg_match('/^[a-z0-9-]+$/', $path)) {
            return null;
        }

        return SeoPage::query()->where('type', 'landing')->where('slug', $path)->first();
    }
}
