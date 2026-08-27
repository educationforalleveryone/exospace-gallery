<?php

declare(strict_types=1);

namespace App\Ops\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Ops\Models\OpsAccessGrant;
use App\Ops\Services\OpsAccessService;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * OpsCenter — OpsAccessController (Iteration 5; operator tier in 6).
 *
 * The management surface for access grants (viewer + operator tiers).
 * SUPER-ADMIN ONLY (mounted inside the nested 'super_admin' route group
 * — a grantee can never reach these routes, let alone grant, change a
 * level, or revoke).
 *
 * Everything mutates only OpsCenter's own grant rows; every change is
 * audited and announced on Slack by OpsAccessService.
 */
class OpsAccessController extends Controller
{
    public function __construct(
        private readonly OpsAccessService $access,
    ) {}

    /**
     * The Access page: active grants (both tiers), recently revoked
     * grants, and the grant form (user picker + level).
     */
    public function index(): View
    {
        $activeGrants = OpsAccessGrant::query()
            ->activeGranted()
            ->with(['user:id,name,email,google2fa_secret,email_verified_at', 'granter:id,name'])
            ->orderByDesc('granted_at')
            ->get();

        $revokedGrants = OpsAccessGrant::query()
            ->whereNotNull('revoked_at')
            ->with(['user:id,name,email', 'granter:id,name'])
            ->orderByDesc('revoked_at')
            ->limit(10)
            ->get();

        // Candidates for the grant form: real accounts that are neither
        // super-admins nor already holding an active grant. Shows MFA /
        // verified state so the operator knows what happens on first
        // visit before granting.
        $candidates = User::query()
            ->where('is_super_admin', false)
            ->whereNotIn('id', $activeGrants->pluck('user_id'))
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name', 'email', 'google2fa_secret', 'email_verified_at']);

        return view('ops.access', [
            'activeGrants' => $activeGrants,
            'revokedGrants' => $revokedGrants,
            'candidates' => $candidates,
        ]);
    }

    /**
     * Grant access (by user id + level from the picker form). Granting a
     * different level to an account that already holds an active grant
     * performs the atomic level change (revoke old + grant new).
     */
    public function grant(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'level' => ['nullable', 'string', 'in:'.implode(',', OpsAccessGrant::LEVELS)],
        ], [
            'user_id.exists' => 'That user account does not exist.',
            'level.in' => 'Unknown access level.',
        ]);

        $target = User::find((int) $validated['user_id']);
        $level = (string) ($validated['level'] ?? OpsAccessGrant::LEVEL_VIEWER);
        $result = $this->access->grant($target, $request->user(), $level);

        return redirect()
            ->route('ops.access.index')
            ->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    /**
     * Revoke a grant.
     */
    public function revoke(Request $request, OpsAccessGrant $grant): RedirectResponse
    {
        $result = $this->access->revoke($grant, $request->user());

        return redirect()
            ->route('ops.access.index')
            ->with($result['ok'] ? 'success' : 'error', $result['message']);
    }
}
