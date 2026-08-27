<?php

declare(strict_types=1);

namespace App\Ops\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * OpsCenter — SentryApiClient (Iteration 4; error trend in 6; per-app
 * trend in 8).
 *
 * A read-only, THREE-call bridge to the Sentry REST API backing the
 * overview's "Sentry — Unresolved Issues" tile and the Applications
 * page's per-app trend column:
 *   summary() (Iteration 4) — issue HEADLINES via
 *     GET /api/0/organizations/{org}/issues/ (title, culprit, counts,
 *     permalink) so the operator sees the full platform picture in
 *     OpsCenter and clicks through to Sentry for stack traces and
 *     release tagging (ADR: summarize + link out, never clone).
 *   trend() (Iteration 6) — the hourly error-RATE for the last 24 h via
 *     GET /api/0/organizations/{org}/events-stats/ (yAxis=count(),
 *     interval=1h), rendered as a pure-SVG sparkline in the same tile:
 *     "is this spike new or the baseline?" answered at a glance.
 *   trendFor() (Iteration 8) — the SAME stats endpoint scoped to ONE
 *     project slug (the operator-supplied ops_applications.
 *     sentry_project_slug mapping), so the Applications page can answer
 *     "which app is actually throwing?" without leaving OpsCenter.
 *
 * Independence note: SENTRY_LARAVEL_DSN (error REPORTING) and
 * SENTRY_API_TOKEN (this summary pull) are separate concerns on purpose.
 * The tile degrades honestly when either is absent.
 *
 * Degradation contract (same family as CoolifyApiClient): NOTHING here
 * throws and the token NEVER appears in any returned payload. Every
 * failure mode — unconfigured, timeout, 401/403, 404, 429, malformed
 * JSON — returns a structured array the tile renders verbatim. Results
 * (success AND failure) are cached so a slow or broken Sentry API can
 * never slow the dashboard or be hammered by every page load. The trend
 * is cached under its OWN key: a failing stats endpoint (e.g. a token
 * without the event:read scope) must not poison the headlines cache, and
 * vice versa. trendFor() extends the same rule PER PROJECT: each mapped
 * application gets its own cache key (ops:sentry:trend:{slug}), so one
 * app's failing/absent project can never poison the org trend or its
 * siblings' caches.
 *
 * API: GET /api/0/organizations/{org}/issues/ with
 *   query=is:unresolved, statsPeriod=24h, project={slug} (repeated)
 * API: GET /api/0/organizations/{org}/events-stats/ with
 *   yAxis=count(), statsPeriod=24h, interval=1h, project={slug}
 * Docs: https://docs.sentry.io/api/events/list-an-projects-issues/
 *       https://docs.sentry.io/api/events/get-organization-events-stats/
 */
class SentryApiClient
{
    private ?string $token;

    private string $baseUrl;

    private string $org;

    /** @var array<int, string> */
    private array $projects;

    private int $timeout;

    private int $cacheMinutes;

    private int $limit;

    public function __construct()
    {
        $this->token = config('ops.sentry.api_token');
        $this->baseUrl = rtrim((string) config('ops.sentry.base_url', 'https://sentry.io'), '/');
        $this->org = (string) config('ops.sentry.org', '');
        $this->projects = (array) config('ops.sentry.projects', []);
        $this->timeout = (int) config('ops.sentry.timeout', 10);
        $this->cacheMinutes = max(1, (int) config('ops.sentry.cache_minutes', 10));
        $this->limit = max(1, (int) config('ops.sentry.limit', 5));
    }

    public function isConfigured(): bool
    {
        return ! empty($this->token)
            && $this->org !== ''
            && $this->baseUrl !== '';
    }

    /**
     * The cached summary the overview tile renders.
     *
     * @return array{
     *     configured: bool,
     *     error?: string,
     *     fetched_at?: string,
     *     total_issues?: int,
     *     total_events?: int,
     *     total_users?: int,
     *     issues?: array<int, array{title: string, culprit: string, level: string, count: int, user_count: int, first_seen: string, last_seen: string, link: string, project: string}>
     * }
     */
    public function summary(): array
    {
        if (! $this->isConfigured()) {
            return ['configured' => false];
        }

        $key = 'ops:sentry:summary';

        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $result = $this->fetch();

        // Cache failures too (short TTL is per config) — an unreachable
        // Sentry must not turn every dashboard load into an API attempt.
        Cache::put($key, $result, now()->addMinutes($this->cacheMinutes));

        return $result;
    }

    /**
     * The cached hourly error trend the tile's sparkline renders.
     *
     * @return array{
     *     configured: bool,
     *     error?: string,
     *     fetched_at?: string,
     *     points?: int,
     *     total?: int,
     *     peak?: int,
     *     peak_hour?: string,
     *     series?: array<int, array{ts: int, count: int}>
     * }
     */
    public function trend(): array
    {
        if (! $this->isConfigured()) {
            return ['configured' => false];
        }

        $key = 'ops:sentry:trend';

        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $result = $this->fetchTrend(null);

        // Cache failures too — same rationale as the summary (an unhappy
        // endpoint must not turn every dashboard load into an API hit).
        Cache::put($key, $result, now()->addMinutes($this->cacheMinutes));

        return $result;
    }

    /**
     * The cached hourly error trend for ONE Sentry project (Iteration 8 —
     * the per-application mapping on the Applications page).
     *
     * Same contract as trend(): nothing throws, the token never appears
     * in any payload, and results (success AND failure) are cached under
     * a per-project key so sibling apps and the org-wide trend can never
     * be poisoned by one project's failure. An empty slug returns the
     * unconfigured shape — the caller renders "not mapped", never a
     * network call.
     *
     * @return array{
     *     configured: bool,
     *     error?: string,
     *     fetched_at?: string,
     *     points?: int,
     *     total?: int,
     *     peak?: int,
     *     peak_hour?: string,
     *     series?: array<int, array{ts: int, count: int}>
     * }
     */
    public function trendFor(string $projectSlug): array
    {
        if (! $this->isConfigured() || trim($projectSlug) === '') {
            return ['configured' => false];
        }

        $slug = trim($projectSlug);
        $key = 'ops:sentry:trend:'.$slug;

        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $result = $this->fetchTrend($slug);

        // Cache failures too — a mapped-but-deleted project must not turn
        // every Applications page load into an API attempt.
        Cache::put($key, $result, now()->addMinutes($this->cacheMinutes));

        return $result;
    }

    /**
     * @param  string|null  $projectSlug  null = the org-wide trend (the
     *                                     config project filter still
     *                                     applies); a slug = exactly that
     *                                     project, config filter ignored.
     * @return array<string, mixed>
     */
    private function fetchTrend(?string $projectSlug = null): array
    {
        $base = ['configured' => true, 'fetched_at' => now()->toIso8601String()];

        if ($projectSlug !== null) {
            $base['project'] = $projectSlug;
        }

        try {
            $query = [
                // yAxis/count are spelled exactly as the events-stats
                // endpoint expects; interval=1h gives 24 points.
                'yAxis' => 'count()',
                'statsPeriod' => '24h',
                'interval' => '1h',
            ];

            if ($projectSlug !== null) {
                // Exactly ONE project — the operator's mapping for this
                // application. The config-wide filter (SENTRY_PROJECT_
                // SLUGS) is deliberately ignored here: it scopes the
                // org-wide surfaces, not a per-app question.
                $query['project'] = $projectSlug;
            } elseif ($this->projects !== []) {
                $query['project'] = $this->projects;
            }

            $response = Http::withToken((string) $this->token)
                ->timeout($this->timeout)
                ->acceptJson()
                ->get($this->baseUrl.'/api/0/organizations/'.$this->org.'/events-stats/', $query);
        } catch (Throwable $e) {
            Log::warning('SentryApiClient: trend request failed', ['reason' => get_class($e)]);

            return $base + ['error' => 'Sentry API unreachable (network timeout or DNS failure)'];
        }

        if (! $response->successful()) {
            $status = $response->status();
            Log::warning('SentryApiClient: trend request failed', ['status' => $status]);

            $reason = match (true) {
                $status === 401 || $status === 403 => "Sentry rejected the API token for stats (HTTP {$status}) — the events-stats endpoint may need the event:read scope",
                $status === 404 => "Organization '{$this->org}' not found (HTTP 404) — check SENTRY_ORG_SLUG",
                $status === 429 => 'Sentry API rate limit hit (HTTP 429) — retrying later',
                default => "Sentry API error (HTTP {$status})",
            };

            return $base + ['error' => $reason];
        }

        $payload = $response->json();
        if (! is_array($payload) || ! is_array($payload['data'] ?? null)) {
            return $base + ['error' => 'Sentry API returned an unexpected response shape'];
        }

        // Normalize: entries have been [time, {count: N}] and
        // [{time, count}] shapes across API versions — both are handled,
        // non-numeric rows are dropped rather than guessed at.
        $series = [];
        $total = 0;
        $peak = 0;
        $peakTs = null;

        foreach ($payload['data'] as $entry) {
            $ts = null;
            $count = null;

            if (is_array($entry)) {
                if (array_is_list($entry) && count($entry) >= 2) {
                    // [unix_ts, {count: N}] shape
                    $ts = is_numeric($entry[0]) ? (int) $entry[0] : null;
                    $count = $this->normalizeCount($entry[1]);
                } elseif (isset($entry['time'])) {
                    // {time: unix_ts|iso, count: N} shape
                    $ts = is_numeric($entry['time']) ? (int) $entry['time'] : (strtotime((string) $entry['time']) ?: null);
                    $count = $this->normalizeCount($entry['count'] ?? 0);
                }
            }

            if ($ts === null || $ts === false) {
                continue;
            }

            $count = max(0, $count ?? 0);
            $series[] = ['ts' => (int) $ts, 'count' => $count];
            $total += $count;

            if ($count > $peak) {
                $peak = $count;
                $peakTs = (int) $ts;
            }
        }

        if ($series === []) {
            return $base + ['error' => 'Sentry API returned no usable data points'];
        }

        // Chronological order (Sentry returns ascending, but the chart
        // must not depend on it).
        usort($series, fn ($a, $b) => $a['ts'] <=> $b['ts']);

        return $base + [
            'points' => count($series),
            'total' => $total,
            'peak' => $peak,
            'peak_hour' => $peakTs !== null
                ? date('H:i', $peakTs).' UTC'
                : '—',
            'series' => $series,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(): array
    {
        $base = ['configured' => true, 'fetched_at' => now()->toIso8601String()];

        try {
            $query = ['query' => 'is:unresolved', 'statsPeriod' => '24h'];

            // Repeated `project` params — Laravel's HTTP client expands
            // arrays into project[]=slug form, which Sentry accepts.
            if ($this->projects !== []) {
                $query['project'] = $this->projects;
            }

            $response = Http::withToken((string) $this->token)
                ->timeout($this->timeout)
                ->acceptJson()
                ->get($this->baseUrl.'/api/0/organizations/'.$this->org.'/issues/', $query);
        } catch (Throwable $e) {
            // Connection-level failure (timeout, DNS). Status code only —
            // the exception message can echo request internals.
            Log::warning('SentryApiClient: request failed', ['reason' => get_class($e)]);

            return $base + ['error' => 'Sentry API unreachable (network timeout or DNS failure)'];
        }

        if (! $response->successful()) {
            $status = $response->status();
            Log::warning('SentryApiClient: request failed', ['status' => $status]);

            $reason = match (true) {
                $status === 401 || $status === 403 => "Sentry rejected the API token (HTTP {$status}) — check SENTRY_API_TOKEN scopes",
                $status === 404 => "Organization '{$this->org}' not found (HTTP 404) — check SENTRY_ORG_SLUG",
                $status === 429 => 'Sentry API rate limit hit (HTTP 429) — retrying later',
                default => "Sentry API error (HTTP {$status})",
            };

            return $base + ['error' => $reason];
        }

        $issues = $response->json();
        if (! is_array($issues)) {
            return $base + ['error' => 'Sentry API returned an unexpected response shape'];
        }

        $normalized = [];
        $totalEvents = 0;
        $totalUsers = 0;

        foreach ($issues as $issue) {
            if (! is_array($issue)) {
                continue;
            }

            $normalized[] = [
                'title' => mb_substr((string) ($issue['title'] ?? 'Untitled issue'), 0, 200),
                'culprit' => mb_substr((string) ($issue['culprit'] ?? ''), 0, 200),
                'level' => strtolower((string) ($issue['level'] ?? 'error')),
                'count' => $this->normalizeCount($issue['count'] ?? 0),
                'user_count' => $this->normalizeCount($issue['userCount'] ?? ($issue['user_count'] ?? 0)),
                'first_seen' => (string) ($issue['firstSeen'] ?? ($issue['first_seen'] ?? '')),
                'last_seen' => (string) ($issue['lastSeen'] ?? ($issue['last_seen'] ?? '')),
                'link' => (string) ($issue['permalink'] ?? $this->fallbackLink((string) ($issue['id'] ?? ''))),
                'project' => (string) ($issue['project']['name'] ?? ($issue['project']['slug'] ?? '')),
            ];

            $totalEvents += $this->normalizeCount($issue['count'] ?? 0);
            $totalUsers += $this->normalizeCount($issue['userCount'] ?? ($issue['user_count'] ?? 0));
        }

        // Most frequent first (Sentry's default sort is 'last seen' —
        // frequency surfaces what users actually hit).
        usort($normalized, fn ($a, $b) => $b['count'] <=> $a['count']);

        return $base + [
            'total_issues' => count($normalized),
            'total_events' => $totalEvents,
            'total_users' => $totalUsers,
            'issues' => array_slice($normalized, 0, $this->limit),
        ];
    }

    /**
     * Sentry has shipped both int and object shapes for these fields
     * across API versions (count: 42 vs count: {count: 42}, userCount:
     * 87 vs userCount: {userCount: 87}) — normalize defensively.
     */
    private function normalizeCount(mixed $value): int
    {
        if (is_array($value)) {
            $value = $value['count'] ?? $value['userCount'] ?? 0;
        }

        return max(0, (int) $value);
    }

    /**
     * Direct issue link when the payload omits permalink (self-hosted or
     * API-version differences).
     */
    private function fallbackLink(string $issueId): string
    {
        if ($issueId === '') {
            return $this->baseUrl.'/organizations/'.$this->org.'/issues/';
        }

        return $this->baseUrl.'/organizations/'.$this->org.'/issues/'.$issueId.'/';
    }

    /**
     * The "open Sentry" link for the tile footer.
     */
    public function issuesUrl(): string
    {
        return $this->baseUrl.'/organizations/'.$this->org.'/issues/?query=is:unresolved&statsPeriod=24h';
    }
}
