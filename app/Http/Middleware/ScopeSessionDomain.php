<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Gallery;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class ScopeSessionDomain
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->getHost();
        $host = strtolower($host);
        $host = explode(':', $host)[0];
        $host = preg_replace('/^www\./', '', $host);

        // Resolve the primary app host from APP_URL
        $appHost = parse_url(config('app.url'), PHP_URL_HOST);
        $appHost = strtolower($appHost ?? '');
        $appHost = preg_replace('/^www\./', '', $appHost);

        // Skip on primary domain, localhost, or IP addresses
        if (!$host
            || $host === $appHost
            || $host === 'localhost'
            || $host === '127.0.0.1'
            || filter_var($host, FILTER_VALIDATE_IP)
        ) {
            return $next($request);
        }

        $cacheKey = "custom_domain:{$host}";
        $galleryId = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($host) {
            return Gallery::where('custom_domain', $host)
                ->whereNotNull('custom_domain_verified_at')
                ->value('id');
        });

        if (! $galleryId) {
            \Illuminate\Support\Facades\Log::info('ScopeSessionDomain: rejected unverified host', [
                'host' => $host,
                'ip'   => $request->ip(),
            ]);

            return response()->make('', 404);
        }

        config(['session.domain' => '.' . $host]);

        $sanctumStateful = config('sanctum.stateful');
        if (is_array($sanctumStateful)) {
            if (!in_array($host, $sanctumStateful, true)) {
                $sanctumStateful[] = $host;
                config(['sanctum.stateful' => $sanctumStateful]);
            }
        }

        return $next($request);
    }
}
