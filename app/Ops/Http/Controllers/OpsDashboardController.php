<?php

declare(strict_types=1);

namespace App\Ops\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Ops\Models\OpsApplication;
use App\Ops\Models\OpsEvent;
use App\Ops\Models\OpsIncident;
use App\Ops\Services\OpsEventIngestor;
use App\Ops\Services\OpsHealthScoreService;
use App\Ops\Services\OpsHealthService;
use App\Ops\Services\OpsStatusTilesService;
use App\Ops\Services\SentryApiClient;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * OpsCenter dashboard (Iteration 1 surface).
 *
 * Read-only aggregation views. Every mutation arrives in later iterations
 * (diagnostics/actions) and will be audit-logged through AdminAuditLog —
 * this controller deliberately contains zero write paths.
 *
 * Access: /ops/* route group — auth + verified + super_admin + mfa
 * (the exact bar Master Control already enforces).
 *
 * Iteration 4: overview() additionally computes the platform health
 * score, the backup/webhook tile facts and the cached Sentry summary.
 * All of them are read-only, bounded and fail-soft — a broken input
 * (unreadable disk, unreachable Sentry API) degrades its own tile and
 * never takes the dashboard down.
 */
class OpsDashboardController extends Controller
{
    public function __construct(
        private readonly OpsHealthService $health,
        private readonly OpsHealthScoreService $score,
        private readonly OpsStatusTilesService $tiles,
    ) {}

    /**
     * Overview — answers "what is broken, where, how serious" first.
     */
    public function overview(): View
    {
        $platform = $this->health->platformHealth();
        $applications = $this->health->applicationStatuses();

        $windowHours = (int) config('ops.dashboard.recent_window_hours', 24);

        // Iteration 2: active incidents sit ABOVE raw errors — they are
        // the correlated stories an operator should triage first.
        $activeIncidents = OpsIncident::query()
            ->with(['application', 'rootCause'])
            ->whereIn('status', ['open', 'acknowledged'])
            ->orderByRaw('CASE severity WHEN "critical" THEN 1 WHEN "error" THEN 2 WHEN "warning" THEN 3 ELSE 4 END')
            ->orderByDesc('last_event_at')
            ->limit(6)
            ->get();

        $recentEvents = OpsEvent::query()
            ->with('application')
            ->whereIn('status', ['open', 'acknowledged'])
            ->whereIn('severity', ['critical', 'error', 'warning'])
            ->orderByRaw('CASE severity WHEN "critical" THEN 1 WHEN "error" THEN 2 WHEN "warning" THEN 3 ELSE 4 END')
            ->orderByDesc('last_seen_at')
            ->limit(12)
            ->get();

        $recentDeployments = OpsEvent::query()
            ->with('application')
            ->whereIn('category', ['DEPLOYMENT', 'BUILD'])
            ->where('last_seen_at', '>=', now()->subHours($windowHours))
            ->orderByDesc('last_seen_at')
            ->limit(6)
            ->get();

        $openCounts = OpsEvent::whereIn('status', ['open', 'acknowledged'])
            ->selectRaw('severity, COUNT(*) as n')
            ->groupBy('severity')
            ->pluck('n', 'severity');

        // Iteration 4: the quantified rollups.
        $healthScore = $this->score->computeLive();
        $backupTile = $this->tiles->backupStatus();
        $webhookTile = $this->tiles->webhookStatus();

        try {
            $sentryTile = app(SentryApiClient::class)->summary();
        } catch (\Throwable) {
            $sentryTile = ['configured' => false];
        }

        return view('ops.overview', [
            'platform' => $platform,
            'applications' => $applications,
            'activeIncidents' => $activeIncidents,
            'recentEvents' => $recentEvents,
            'recentDeployments' => $recentDeployments,
            'openCounts' => $openCounts,
            'selfChecks' => $this->health->selfChecks(),
            'selfApp' => OpsEventIngestor::selfApplication(),
            'lastSync' => OpsApplication::whereNotNull('status_checked_at')
                ->max('status_checked_at'),
            'healthScore' => $healthScore,
            'backupTile' => $backupTile,
            'webhookTile' => $webhookTile,
            'sentryTile' => $sentryTile,
        ]);
    }

    /**
     * Applications — the platform-wide inventory (Coolify resources +
     * ingest-API reporters + self). Iteration 5: each row carries its own
     * sub-score (§16.2) — same verdict-cap philosophy as the platform
     * score, scoped to the single application.
     */
    public function applications(): View
    {
        $applications = OpsApplication::query()
            ->withCount(['events' => fn ($q) => $q->whereIn('status', ['open', 'acknowledged'])
                ->whereIn('severity', ['critical', 'error'])])
            ->orderByDesc('is_self')
            ->orderBy('kind')
            ->orderBy('name')
            ->get();

        // Fail-soft: an unavailable score must never take the page down —
        // rows simply render without a badge (the view handles null).
        try {
            $scores = $this->score->computeForApplications($applications);
        } catch (\Throwable) {
            $scores = [];
        }

        return view('ops.applications', [
            'applications' => $applications,
            'scores' => $scores,
        ]);
    }

    /**
     * Events list — filterable/searchable error inventory.
     */
    public function events(Request $request): View
    {
        $query = OpsEvent::query()->with('application');

        // Filters (all optional, all safe defaults). Values are pulled via
        // input() (plain strings) — $request->string() returns Stringable
        // objects which don't cast or strict-compare cleanly.
        $severity = (string) $request->input('severity', '');
        $category = (string) $request->input('category', '');
        $applicationId = (int) $request->input('application', 0);

        if (in_array($severity, OpsEvent::SEVERITIES, true)) {
            $query->where('severity', $severity);
        }

        if (in_array($category, OpsEvent::CATEGORIES, true)) {
            $query->where('category', $category);
        }

        if ($applicationId > 0) {
            $query->where('ops_application_id', $applicationId);
        }

        $status = (string) $request->input('status', 'active');
        if ($status === 'active') {
            $query->whereIn('status', ['open', 'acknowledged']);
        } elseif (in_array($status, ['open', 'acknowledged', 'resolved'], true)) {
            $query->where('status', $status);
        }

        $hours = (int) $request->input('hours', 168);
        if ($hours > 0) {
            $query->where('last_seen_at', '>=', now()->subHours($hours));
        }

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $term = str_replace(['%', '_'], ['\\%', '\\_'], $search);
            $query->where(fn ($q) => $q
                ->where('title', 'like', "%{$term}%")
                ->orWhere('message', 'like', "%{$term}%"));
        }

        $events = $query
            ->orderByRaw('CASE severity WHEN "critical" THEN 1 WHEN "error" THEN 2 WHEN "warning" THEN 3 ELSE 4 END')
            ->orderByDesc('last_seen_at')
            ->paginate((int) config('ops.dashboard.per_page', 25))
            ->withQueryString();

        return view('ops.events', [
            'events' => $events,
            'filters' => [
                'severity' => $severity,
                'category' => $category,
                'application' => $applicationId > 0 ? (string) $applicationId : '',
                'status' => $status,
                'hours' => $hours,
                'q' => $search,
            ],
            'applications' => OpsApplication::orderBy('name')->get(),
        ]);
    }

    /**
     * Error detail — the "operational" view of one error: what happened,
     * why it matters, where, when, how often, what changed, and what to
     * do next. Raw technical context stays available but secondary.
     */
    public function eventDetail(OpsEvent $event): View
    {
        // Related events from the same application in the same window —
        // the manual precursor to Iteration 2's correlation engine.
        $related = OpsEvent::query()
            ->where('id', '!=', $event->id)
            ->where('ops_application_id', $event->ops_application_id)
            ->whereIn('status', ['open', 'acknowledged'])
            ->whereBetween('last_seen_at', [
                $event->first_seen_at?->copy()->subHours(1) ?? now()->subDay(),
                $event->last_seen_at?->copy()->addHours(1) ?? now(),
            ])
            ->orderByDesc('last_seen_at')
            ->limit(8)
            ->get();

        return view('ops.event-detail', [
            'event' => $event->load('application'),
            'related' => $related,
        ]);
    }
}
