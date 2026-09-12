<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class PreventAuthenticatedCaching
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestIsAuthenticated = Auth::check();

        $response = $next($request);

        // Guest requests keep the application's default caching behavior.
        if (! $requestIsAuthenticated) {
            return $response;
        }

        $contentType = strtolower((string) $response->headers->get('Content-Type', ''));
        $isHtmlDocument = str_starts_with($contentType, 'text/html');

        // Personalized HTML and redirects only — never binary/stream bodies.
        if (! $isHtmlDocument && ! $response->isRedirection()) {
            return $response;
        }

        $current = strtolower((string) $response->headers->get('Cache-Control', ''));

        if (! str_contains($current, 'no-store')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
