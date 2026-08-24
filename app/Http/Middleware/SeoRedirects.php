<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\SeoRedirect;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Managed redirects (SEO OS Iteration 4).
 *
 * Runs BEFORE routing (prepended in bootstrap/app.php, right after
 * DetectCustomDomain): if the request path matches an active seo_redirect,
 * respond with its redirect status — regardless of whether a real route
 * exists at that path. This is deliberate: if an operator has declared a
 * redirect for a path, that declaration wins.
 *
 * The lookup is a cached in-memory map fetch (one Cache::remember per
 * 10 minutes), so the hot-path cost is a single cache GET.
 */
class SeoRedirects
{
    public function handle(Request $request, Closure $next): Response
    {
        // Only GET/HEAD requests are redirected; POST/PUT/DELETE to an old
        // path is a client bug and should surface normally.
        if ($request->isMethod('get') || $request->isMethod('head')) {
            $map = SeoRedirect::cachedMap();

            if ($map !== []) {
                $path = SeoRedirect::normalizePath($request->path());

                if (isset($map[$path])) {
                    [$destination, $status] = $map[$path];

                    // Relative destinations keep the current host.
                    $target = $destination;
                    if ($target !== '' && $target[0] === '/') {
                        $target = $request->getSchemeAndHttpHost() . $target;
                    }

                    $redirect = redirect()->to($target, $status);
                    $redirect->header('Cache-Control', 'public, max-age=86400');

                    return $redirect;
                }
            }
        }

        return $next($request);
    }
}
