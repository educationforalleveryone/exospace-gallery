<?php

declare(strict_types=1);

namespace App\Ops\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Ops\Diagnostics\DiagnosticEngine;
use App\Ops\Diagnostics\DiagnosticRegistry;
use App\Ops\Models\OpsApplication;
use App\Ops\Models\OpsEvent;
use App\Ops\Models\OpsIncident;
use App\Ops\Models\OpsDiagnosticRun;
use App\Ops\Services\OpsSweepStatusService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OpsDiagnosticController extends Controller
{
    public function __construct(
        private readonly DiagnosticEngine $engine,
        private readonly OpsSweepStatusService $sweepStatus,
    ) {}

    public function index(Request $request): View
    {
        $application = $this->resolveApplication($request);

        $recentRuns = OpsDiagnosticRun::query()
            ->with(['application', 'actor'])
            ->when($application !== null, fn ($q) => $q->where('ops_application_id', $application->id))
            ->orderByDesc('id')
            ->limit(15)
            ->get();

        try {
            $sweepStatus = $this->sweepStatus->status();
        } catch (\Throwable) {
            $sweepStatus = null; // fail-soft — the page never depends on it
        }

        return view('ops.diagnostics', [
            'diagnostics' => DiagnosticRegistry::all(),
            'groups' => DiagnosticRegistry::groups(),
            'application' => $application,
            'applications' => OpsApplication::orderByDesc('is_self')->orderBy('name')->get(),
            'recentRuns' => $recentRuns,
            'sweepStatus' => $sweepStatus,
        ]);
    }

    public function run(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'diagnostic' => ['required', 'string', 'max:64'],
            'application' => ['nullable', 'integer'],
            'event' => ['nullable', 'integer'],
            'incident' => ['nullable', 'integer'],
        ]);

        $id = (string) $validated['diagnostic'];

        if (! DiagnosticRegistry::has($id)) {
            abort(404, 'Unknown diagnostic.');
        }

        $application = isset($validated['application'])
            ? OpsApplication::find((int) $validated['application'])
            : null;

        if (isset($validated['application']) && $application === null) {
            abort(404, 'Unknown application.');
        }

        // Provenance: where was the button clicked?
        $source = 'manual';
        $sourceId = null;

        if (isset($validated['event'])) {
            $event = OpsEvent::find((int) $validated['event']);
            if ($event === null) {
                abort(404, 'Unknown event.');
            }

            $application ??= $event->application;
            $source = 'event';
            $sourceId = $event->id;
        }

        if (isset($validated['incident'])) {
            $incident = OpsIncident::find((int) $validated['incident']);
            if ($incident === null) {
                abort(404, 'Unknown incident.');
            }

            $application ??= $incident->application;
            $source = 'incident';
            $sourceId = $incident->id;
        }

        $run = $this->engine->run($id, $application, $request->user(), $source, $sourceId);

        if ($run === null) {
            abort(404, 'Unknown diagnostic.');
        }

        return redirect()
            ->route('ops.diagnostics.show', $run)
            ->with('success', 'Diagnostic completed — status: '.$run->statusLabel().'.');
    }

    public function show(Request $request, OpsDiagnosticRun $run): View
    {
        $run->load(['application', 'actor']);

        return view('ops.diagnostic-run', [
            'run' => $run,
            'definition' => DiagnosticRegistry::get($run->diagnostic_id),
        ]);
    }

    private function resolveApplication(Request $request): ?OpsApplication
    {
        $id = $request->query('app');

        if ($id === null || $id === '') {
            return null;
        }

        return OpsApplication::find((int) $id);
    }
}
