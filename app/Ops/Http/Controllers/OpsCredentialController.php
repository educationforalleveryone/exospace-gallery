<?php

declare(strict_types=1);

namespace App\Ops\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Ops\Services\OpsCredentialInventoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OpsCredentialController extends Controller
{
    public function __construct(
        private readonly OpsCredentialInventoryService $inventory,
    ) {}

    public function index(): View
    {
        return view('ops.credentials', $this->inventory->inventory());
    }

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
