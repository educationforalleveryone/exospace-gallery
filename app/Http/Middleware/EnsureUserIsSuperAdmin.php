<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsSuperAdmin
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // If user is not authenticated or not a super admin, kick them out
        if (!auth()->check() || !auth()->user()->is_super_admin) {
            abort(403, 'Unauthorized. This area is restricted.');
        }

        return $next($request);
    }
}