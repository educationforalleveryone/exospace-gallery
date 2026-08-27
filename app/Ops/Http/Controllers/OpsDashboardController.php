<?php

declare(strict_types=1);

namespace App\Ops\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Ops\Models\OpsApplication;
use App\Ops\Models\OpsEvent;
use App\Ops\Models\OpsIncident;
use App\Ops\Services\OpsEventIngestor;
use App\Ops\Services\OpsHealthScoreService;
use App\Ops\Services\OpsHealthService;
use App\Ops\Services\OpsStatusTilesService;
use App\Ops\Services\SentryApiClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

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
 *
 * Iteration 8: applications() additionally loads the per-application
 * Sentry 24 h trend for every MAPPED application (cache-first, per-app
 * cache key — see SentryApiClient::trendFor), and this controller gains
 * its FIRST write path: the super-admin-only, audited Sentry project
 * mapping form (ops.sentry.mapping). The mapping is a label, not a
 * secret — the audit payload may carry the old→new slug verbatim.
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

        // Iteration 6: the 24-hour hourly error trend (Sentry events-stats)
        // for the tile's sparkline — its own cache key + fail-soft, so a
        // stats endpoint the token cannot read never breaks the tile.
        try {
            $sentryTrend = app(SentryApiClient::class)->trend();
        } catch (\Throwable) {
            $sentryTrend = ['configured' => false];
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
            'sentryTrend' => $sentryTrend,
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

        // Iteration 8: per-application Sentry trends — ONLY for mapped
        // applications, cache-first. Steady state costs zero network
        // calls (10-min per-app cache); the cold/expired load pays one
        // bounded call per mapped app (the operator maps a handful, not
        // hundreds). Fail-soft per app: one broken project slug degrades
        // exactly its own cell to an honest amber error, never the page.
        $sentryTrends = [];
        $sentryIssues = [];
        try {
            $client = app(SentryApiClient::class);
            $sentryConfigured = $client->isConfigured();
            if ($sentryConfigured) {
                foreach ($applications as $application) {
                    $slug = trim((string) $application->sentry_project_slug);
                    if ($slug === '') {
                        continue;
                    }
                    try {
                        $sentryTrends[$application->id] = $client->trendFor($slug);

                        // Iteration 9: the headlines card for the same
                        // mapped app — the trend says HOW MUCH it is
                        // throwing, the headlines say WHAT. Same cache
                        // discipline, same per-app degradation: one
                        // project's API failure dims exactly its own card.
                        $sentryIssues[$application->id] = $client->summaryFor($slug);
                    } catch (Throwable) {
                        // trendFor/summaryFor never throw by contract;
                        // belt-and-braces so one app can still never
                        // break the row.
                    }
                }
            }
        } catch (Throwable) {
            // Container trouble — the column renders its muted state.
        }

        return view('ops.applications', [
            'applications' => $applications,
            'scores' => $scores,
            'sentryTrends' => $sentryTrends,
            'sentryConfigured' => $sentryConfigured ?? false,

            // Iteration 9: per-app issue headlines (same mapped-apps-only
            // population as the trends above).
            'sentryIssues' => $sentryIssues,
        ]);
    }

    /**
     * POST /ops/applications/{app}/sentry — set or clear ONE
     * application's Sentry project mapping (Iteration 8).
     *
     * Super-admin-only (route-level), throttled, audited as
     * ops.sentry.mapping with app id + old→new slug (a slug is a public
     * label in Sentry URLs — not a secret). Empty input CLEARS the
     * mapping; the column degrades to "not mapped", never an error.
     */
    public function updateSentryMapping(Request $request, OpsApplication $app): RedirectResponse
    {
        $validated = $request->validate([
            // Sentry slugs: letters, digits, dashes, dots, underscores
            // (uppercase tolerated at the door and normalized below — a
            // pasted URL slug must not bounce). 100 = column width.
            'sentry_project_slug' => ['nullable', 'string', 'max:100', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/'],
        ], [
            'sentry_project_slug.regex' => 'A Sentry project slug is letters, digits, dashes, dots or underscores (e.g. exospace-production).',
        ]);

        $old = (string) $app->sentry_project_slug;
        $new = trim((string) ($validated['sentry_project_slug'] ?? ''));

        if ($new !== '') {
            // Normalize case defensively — Sentry slugs are lowercase;
            // a pasted uppercase slug would render but never match.
            $new = strtolower($new);
        }

        if ($new === $old) {
            return redirect()
                ->route('ops.applications')
                ->with('info', 'Sentry mapping for "'.$app->name.'" is unchanged.');
        }

        $app->sentry_project_slug = $new !== '' ? $new : null;
        $app->save();

        try {
            AdminAuditLog::record('ops.sentry.mapping', $request->user(), [
                'application_id' => $app->id,
                'application' => $app->name,
                'old' => $old !== '' ? $old : null,
                'new' => $new !== '' ? $new : null,
            ]);
        } catch (Throwable) {
            // The mapping is saved; a failed audit row must not turn it
            // into an error page (same convention as the digest send).
        }

        $message = $new !== ''
            ? 'Sentry mapping for "'.$app->name.'" set to '.$new.' — its trend appears on the next page load.'
            : 'Sentry mapping for "'.$app->name.'" cleared.';

        return redirect()->route('ops.applications')->with('success', $message);
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
