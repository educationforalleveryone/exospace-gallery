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
 * OpsCenter — OpsAccessController (Iteration 5).
 *
 * The management surface for viewer grants. SUPER-ADMIN ONLY (mounted
 * inside the nested 'super_admin' route group — a viewer can never reach
 * these routes, let alone grant or revoke).
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
     * The Access page: active viewers, recently revoked grants, and the
     * grant form (user picker over non-super-admin accounts).
     */
    public function index(): View
    {
        $activeGrants = OpsAccessGrant::query()
            ->activeViewers()
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
     * Grant viewer access (by user id from the picker form).
     */
    public function grant(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ], [
            'user_id.exists' => 'That user account does not exist.',
        ]);

        $target = User::find((int) $validated['user_id']);
        $result = $this->access->grant($target, $request->user());

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
