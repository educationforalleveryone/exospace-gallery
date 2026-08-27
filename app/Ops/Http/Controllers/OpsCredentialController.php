<?php

declare(strict_types=1);

namespace App\Ops\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Ops\Services\OpsCredentialInventoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * OpsCenter — OpsCredentialController (Iteration 5).
 *
 * The credential-governance surface: the §15 checklist made live.
 * SUPER-ADMIN ONLY (nested 'super_admin' route group). The page shows,
 * per credential: whether it is configured (a boolean — never the
 * value), when it was last rotated, and what to do next.
 *
 * Recording a rotation does NOT rotate anything by itself — the operator
 * rotates in the provider's dashboard, then records it here so the
 * ledger, the audit trail and the Slack channel agree on history.
 */
class OpsCredentialController extends Controller
{
    public function __construct(
        private readonly OpsCredentialInventoryService $inventory,
    ) {}

    public function index(): View
    {
        return view('ops.credentials', $this->inventory->inventory());
    }

    /**
     * Record a rotation for one catalog credential.
     */
    public function rotate(Request $request, string $key): RedirectResponse
    {
        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:250'],
        ]);

        $result = $this->inventory->markRotated($key, $request->user(), $validated['note'] ?? null);

        return redirect()
            ->route('ops.credentials.index')
            ->with($result['ok'] ? 'success' : 'error', $result['message']);
    }
}
