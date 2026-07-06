<?php

namespace App\Http\Middleware;

use App\Services\FeatureFlag;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * M-14: Feature flag middleware.
 *
 * Aborts with 404 when the specified feature flag is disabled. This
 * prevents users from accessing routes for features that haven't been
 * rolled out yet (or have been rolled back).
 *
 * Usage in routes:
 *   Route::get('/billing/upgrade/{plan}', [BillingController::class, 'upgrade'])
 *       ->middleware('feature_flag:subscriptions');
 *
 * Multiple flags can be comma-separated (ALL must be enabled):
 *   ->middleware('feature_flag:subscriptions,invoicing');
 *
 * The middleware uses 404 (not 403) so users can't tell the difference
 * between "this route doesn't exist" and "this feature is disabled" —
 * preventing information leakage about unannounced features.
 */
class EnsureFeatureFlagEnabled
{
    public function handle(Request $request, Closure $next, string ...$flags): Response
    {
        foreach ($flags as $flag) {
            if (! FeatureFlag::isEnabled($flag)) {
                abort(404);
            }
        }

        return $next($request);
    }
}
