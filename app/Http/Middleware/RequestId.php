<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * A-9 FIX (Iter-006): Request ID middleware.
 *
 * Generates a UUID per request and attaches it to:
 *   - The request attributes (accessible via $request->attributes->get('request_id'))
 *   - The Monolog log context (via a Log processor wired in AppServiceProvider)
 *   - The response header X-Request-Id (so the user can reference it in support tickets)
 *   - Sentry events (via the scope)
 *
 * This enables tracing a single user's request across multiple log lines
 * (e.g. auth → billing → webhook) by searching for the request_id.
 *
 * The middleware is registered in bootstrap/app.php as a global middleware
 * (runs on every request).
 */
class RequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        // Generate a per-request UUID (or use the incoming X-Request-Id header
        // if present — allows upstream proxies to set their own request IDs).
        $requestId = $request->header('X-Request-Id') ?: Str::uuid()->toString();

        // Store in request attributes for access by log processors, Sentry, etc.
        $request->attributes->set('request_id', $requestId);

        // Merge into the log context so every Log::info() call includes the request_id.
        // This is done via a Monolog processor in AppServiceProvider — the middleware
        // just sets the request attribute; the processor reads it.
        \Illuminate\Support\Facades\Log::shareContext([
            'request_id' => $requestId,
        ]);

        $response = $next($request);

        // Expose the request_id in the response header so the user can reference
        // it in support tickets.
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
