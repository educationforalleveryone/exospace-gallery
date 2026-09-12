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
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Throwable;

class OpsDashboardController extends Controller
{
    public function __construct(
        private readonly OpsHealthService $health,
        private readonly OpsHealthScoreService $score,
        private readonly OpsStatusTilesService $tiles,
    ) {}

    public function overview(): View
    {
        $platform = $this->health->platformHealth();
        $applications = $this->health->applicationStatuses();

        $windowHours = (int) config('ops.dashboard.recent_window_hours', 24);

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

        try {
            $sentryTrend = app(SentryApiClient::class)->trend();
        } catch (\Throwable) {
            $sentryTrend = ['configured' => false];
        }

        $lastSyncRaw = OpsApplication::whereNotNull('status_checked_at')
            ->max('status_checked_at');

        return view('ops.overview', [
            'platform' => $platform,
            'applications' => $applications,
            'activeIncidents' => $activeIncidents,
            'recentEvents' => $recentEvents,
            'recentDeployments' => $recentDeployments,
            'openCounts' => $openCounts,
            'selfChecks' => $this->health->selfChecks(),
            'selfApp' => OpsEventIngestor::selfApplication(),
            'lastSync' => $lastSyncRaw !== null ? Carbon::parse($lastSyncRaw) : null,
            'healthScore' => $healthScore,
            'backupTile' => $backupTile,
            'webhookTile' => $webhookTile,
            'sentryTile' => $sentryTile,
            'sentryTrend' => $sentryTrend,
        ]);
    }

    public function applications(): View
    {
        $applications = OpsApplication::query()
            ->withCount(['events' => fn ($q) => $q->whereIn('status', ['open', 'acknowledged'])
                ->whereIn('severity', ['critical', 'error'])])
            ->orderByDesc('is_self')
            ->orderBy('kind')
            ->orderBy('name')
            ->get();

        try {
            $scores = $this->score->computeForApplications($applications);
        } catch (\Throwable) {
            $scores = [];
        }

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

                        $sentryIssues[$application->id] = $client->summaryFor($slug);
                    } catch (Throwable) {
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

            'sentryIssues' => $sentryIssues,
        ]);
    }

    public function updateSentryMapping(Request $request, OpsApplication $app): RedirectResponse
    {
        $validated = $request->validate([
            'sentry_project_slug' => ['nullable', 'string', 'max:100', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/'],
        ], [
            'sentry_project_slug.regex' => 'A Sentry project slug is letters, digits, dashes, dots or underscores (e.g. exospace-production).',
        ]);

        $old = (string) $app->sentry_project_slug;
        $new = trim((string) ($validated['sentry_project_slug'] ?? ''));

        if ($new !== '') {
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
        }

        $message = $new !== ''
            ? 'Sentry mapping for "'.$app->name.'" set to '.$new.' — its trend appears on the next page load.'
            : 'Sentry mapping for "'.$app->name.'" cleared.';

        return redirect()->route('ops.applications')->with('success', $message);
    }

    public function events(Request $request): View
    {
        $query = OpsEvent::query()->with('application');

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

    public function eventDetail(OpsEvent $event): View
    {
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