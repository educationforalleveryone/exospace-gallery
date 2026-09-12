<?php

declare(strict_types=1);

namespace App\Ops\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Ops\Models\OpsIncident;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OpsIncidentController extends Controller
{
    public function index(Request $request): View
    {
        $status = (string) $request->input('status', 'active');

        $query = OpsIncident::query()->with(['application', 'rootCause']);

        if ($status === 'active') {
            $query->whereIn('status', ['open', 'acknowledged']);
        } elseif (in_array($status, ['open', 'acknowledged', 'resolved'], true)) {
            $query->where('status', $status);
        }

        $incidents = $query
            ->orderByRaw('CASE severity WHEN "critical" THEN 1 WHEN "error" THEN 2 WHEN "warning" THEN 3 ELSE 4 END')
            ->orderByDesc('last_event_at')
            ->paginate((int) config('ops.dashboard.per_page', 25))
            ->withQueryString();

        return view('ops.incidents', [
            'incidents' => $incidents,
            'status' => $status,
        ]);
    }

    public function show(OpsIncident $incident): View
    {
        $incident->load(['application', 'rootCause.application']);

        // The timeline: member events in chronological order.
        $timeline = $incident->timeline()->with('application')->get();

        return view('ops.incident-detail', [
            'incident' => $incident,
            'timeline' => $timeline,
        ]);
    }

    public function acknowledge(Request $request, OpsIncident $incident)
    {
        if ($incident->status !== 'open') {
            return redirect()
                ->route('ops.incidents.show', $incident)
                ->withErrors(['incident' => 'Only open incidents can be acknowledged.']);
        }

        $incident->update([
            'status' => 'acknowledged',
            'acknowledged_at' => now(),
        ]);

        AdminAuditLog::record('ops.incident.acknowledged', $incident, [
            'title' => $incident->title,
            'severity' => $incident->severity,
        ]);

        return redirect()
            ->route('ops.incidents.show', $incident)
            ->with('success', 'Incident acknowledged — it stays on the board until resolved.');
    }

    public function resolve(Request $request, OpsIncident $incident)
    {
        if ($incident->status === 'resolved') {
            return redirect()
                ->route('ops.incidents.show', $incident)
                ->withErrors(['incident' => 'Incident is already resolved.']);
        }

        $incident->update([
            'status' => 'resolved',
            'resolved_at' => now(),
        ]);

        AdminAuditLog::record('ops.incident.resolved', $incident, [
            'title' => $incident->title,
            'severity' => $incident->severity,
            'duration' => $incident->first_event_at !== null
                ? round($incident->first_event_at->diffInMinutes($incident->resolved_at)).' min'
                : null,
        ]);

        return redirect()
            ->route('ops.incidents.show', $incident)
            ->with('success', 'Incident resolved.');
    }

    public function reopen(Request $request, OpsIncident $incident)
    {
        if ($incident->status !== 'resolved') {
            return redirect()
                ->route('ops.incidents.show', $incident)
                ->withErrors(['incident' => 'Only resolved incidents can be reopened.']);
        }

        $incident->update([
            'status' => 'open',
            'resolved_at' => null,
        ]);

        AdminAuditLog::record('ops.incident.reopened', $incident, [
            'title' => $incident->title,
        ]);

        return redirect()
            ->route('ops.incidents.show', $incident)
            ->with('success', 'Incident reopened.');
    }
}
