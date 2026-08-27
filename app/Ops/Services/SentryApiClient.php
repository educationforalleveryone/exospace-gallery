<?php

declare(strict_types=1);

namespace App\Ops\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * OpsCenter — SentryApiClient (Iteration 4).
 *
 * A read-only, single-endpoint bridge to the Sentry REST API backing the
 * overview's "Sentry — Unresolved Issues" tile. Following the discovery
 * audit's decision (ADR: "Sentry stays the deep-dive error UI. OpsCenter
 * summarizes and links out; it does not clone Sentry"), this client pulls
 * issue HEADLINES only — title, culprit, counts, permalink — so the
 * operator sees the full platform picture in OpsCenter and clicks through
 * to Sentry for stack traces and release tagging.
 *
 * Independence note: SENTRY_LARAVEL_DSN (error REPORTING) and
 * SENTRY_API_TOKEN (this summary pull) are separate concerns on purpose.
 * The tile degrades honestly when either is absent.
 *
 * Degradation contract (same family as CoolifyApiClient): NOTHING here
 * throws and the token NEVER appears in any returned payload. Every
 * failure mode — unconfigured, timeout, 401/403, malformed JSON — returns
 * a structured array the tile renders verbatim. Results (success AND
 * failure) are cached so a slow or broken Sentry API can never slow the
 * dashboard or be hammered by every page load.
 *
 * API: GET /api/0/organizations/{org}/issues/ with
 *   query=is:unresolved, statsPeriod=24h, project={slug} (repeated)
 * Docs: https://docs.sentry.io/api/events/list-an-projects-issues/
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
