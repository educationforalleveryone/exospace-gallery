<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\SeoRedirect;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SeoRedirects
{
    public function handle(Request $request, Closure $next): Response
    {
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
