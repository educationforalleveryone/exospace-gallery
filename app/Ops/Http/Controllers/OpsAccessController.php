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

class OpsAccessController extends Controller
{
    public function __construct(
        private readonly OpsAccessService $access,
    ) {}

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

    public function revoke(Request $request, OpsAccessGrant $grant): RedirectResponse
    {
        $result = $this->access->revoke($grant, $request->user());

        return redirect()
            ->route('ops.access.index')
            ->with($result['ok'] ? 'success' : 'error', $result['message']);
    }
}
