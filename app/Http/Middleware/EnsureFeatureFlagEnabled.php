<?php

namespace App\Http\Middleware;

use App\Services\FeatureFlag;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

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
