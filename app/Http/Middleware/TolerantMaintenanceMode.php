<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Maintenance gate that refuses to become a second outage.
 *
 * Production keeps the maintenance payload in the Redis cache. When Redis
 * is unreachable, the default middleware's active() check throws and every
 * request dies with a 500 before reaching the application — even routes
 * that do not touch Redis could otherwise still serve. Treating an
 * unreachable maintenance store as "not in maintenance" keeps the blast
 * radius on the store itself; the check re-runs on the next request.
 */
class TolerantMaintenanceMode extends PreventRequestsDuringMaintenance
{
    public function handle($request, Closure $next)
    {
        try {
            $down = $this->app->maintenanceMode()->active();
        } catch (Throwable $e) {
            Log::warning('Maintenance store unreachable — continuing as if not in maintenance', [
                'error' => $e->getMessage(),
            ]);

            return $next($request);
        }

        if (! $down) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }
}
