<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\AdminAuditLog;
use App\Services\ImpersonationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RestrictImpersonation
{
    /**
     * Paths an impersonating admin must never reach: billing, and changes to the
     * credentials or sign-in methods of the account being viewed.
     */
    private const FORBIDDEN_PATHS = [
        'billing',
        'billing/*',
        'mfa/setup',
        'mfa/disable',
        'password',
        'auth/*/redirect',
        'auth/*/unlink',
    ];

    public function __construct(private ImpersonationService $impersonation) {}

    public function handle(Request $request, Closure $next): Response
    {
        $admin = $this->impersonation->getImpersonatingAdmin();

        if (! $this->impersonation->isImpersonating() || ! $request->user()) {
            return $next($request);
        }

        if ($this->isForbidden($request)) {
            abort(403, 'This action is not available while viewing the site as another user.');
        }

        if (! $request->isMethodSafe() && $admin) {
            AdminAuditLog::record('impersonation_request', $request->user(), [
                'admin_id' => $admin->id,
                'method' => $request->method(),
                'route' => $request->route()?->getName() ?? $request->path(),
            ], $admin->id);
        }

        return $next($request);
    }

    private function isForbidden(Request $request): bool
    {
        if ($request->is(...self::FORBIDDEN_PATHS)) {
            return true;
        }

        // Profile is viewable, but not editable or deletable.
        return $request->is('profile') && ! $request->isMethodSafe();
    }
}
