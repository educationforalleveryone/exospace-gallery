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

/**
 * OpsCenter — OpsDiagnosticController (Iteration 3).
 *
 * The diagnostics surface: catalog (grouped by domain), one-click run, and
 * the run result page. READ-ONLY by construction — the engine only executes
 * allow-listed checks; this controller adds no capabilities of its own.
 *
 * Security bar: the /ops route group already enforces auth + verified +
 * super_admin + mfa. Runs are additionally throttled (live API calls and DB
 * probes must not be hammerable) and every run is audited by the engine
 * (AdminAuditLog ops.diagnostic.run).
 */
class OpsDiagnosticController extends Controller
{
    public function __construct(
        private readonly DiagnosticEngine $engine,
        private readonly OpsSweepStatusService $sweepStatus,
    ) {}

    /**
     * GET /ops/diagnostics — the catalog + recent runs.
     */
    public function index(Request $request): View
    {
        $application = $this->resolveApplication($request);

        $recentRuns = OpsDiagnosticRun::query()
            ->with(['application', 'actor'])
            ->when($application !== null, fn ($q) => $q->where('ops_application_id', $application->id))
            ->orderByDesc('id')
            ->limit(15)
            ->get();

        // Iteration 7: the sweep-cadence panel (self-scoped checks only —
        // the panel explains the watch, which always targets self).
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

    /**
     * POST /ops/diagnostics/run — execute one allow-listed diagnostic.
     *
     * Accepts:  diagnostic (id), application (id, optional),
     *           event (id, optional), incident (id, optional)
     *           — the latter two only annotate provenance.
     */
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
            // Not in the allow-list — indistinguishable from "does not
            // exist" on purpose (no capability oracle).
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

            // The event's application is the natural target when the caller
            // did not pass one explicitly.
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

    /**
     * GET /ops/diagnostics/runs/{run} — the result page.
     */
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
