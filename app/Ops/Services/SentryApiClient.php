<?php

declare(strict_types=1);

namespace App\Ops\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SentryApiClient
{
    private ?string $token;

    private string $baseUrl;

    private string $org;

    /**
 * @var array<int, string>
 */
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

        $result = $this->fetch(null);

        Cache::put($key, $result, now()->addMinutes($this->cacheMinutes));

        return $result;
    }

    public function summaryFor(string $projectSlug): array
    {
        if (! $this->isConfigured() || trim($projectSlug) === '') {
            return ['configured' => false];
        }

        $slug = trim($projectSlug);
        $key = 'ops:sentry:summary:'.$slug;

        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $result = $this->fetch($slug);

        Cache::put($key, $result, now()->addMinutes($this->cacheMinutes));

        return $result;
    }

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

        Cache::put($key, $result, now()->addMinutes($this->cacheMinutes));

        return $result;
    }

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

        Cache::put($key, $result, now()->addMinutes($this->cacheMinutes));

        return $result;
    }

    private function fetchTrend(?string $projectSlug = null): array
    {
        $base = ['configured' => true, 'fetched_at' => now()->toIso8601String()];

        if ($projectSlug !== null) {
            $base['project'] = $projectSlug;
        }

        try {
            $query = [
                'yAxis' => 'count()',
                'statsPeriod' => '24h',
                'interval' => '1h',
            ];

            if ($projectSlug !== null) {
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

    private function fetch(?string $projectSlug = null): array
    {
        $base = ['configured' => true, 'fetched_at' => now()->toIso8601String()];

        if ($projectSlug !== null) {
            $base['project'] = $projectSlug;
        }

        try {
            $query = ['query' => 'is:unresolved', 'statsPeriod' => '24h'];

            if ($projectSlug !== null) {
                $query['project'] = $projectSlug;
            } elseif ($this->projects !== []) {
                $query['project'] = $this->projects;
            }

            $response = Http::withToken((string) $this->token)
                ->timeout($this->timeout)
                ->acceptJson()
                ->get($this->baseUrl.'/api/0/organizations/'.$this->org.'/issues/', $query);
        } catch (Throwable $e) {
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

        usort($normalized, fn ($a, $b) => $b['count'] <=> $a['count']);

        return $base + [
            'total_issues' => count($normalized),
            'total_events' => $totalEvents,
            'total_users' => $totalUsers,
            'issues' => array_slice($normalized, 0, $this->limit),
        ];
    }

    private function normalizeCount(mixed $value): int
    {
        if (is_array($value)) {
            $value = $value['count'] ?? $value['userCount'] ?? 0;
        }

        return max(0, (int) $value);
    }

    private function fallbackLink(string $issueId): string
    {
        if ($issueId === '') {
            return $this->baseUrl.'/organizations/'.$this->org.'/issues/';
        }

        return $this->baseUrl.'/organizations/'.$this->org.'/issues/'.$issueId.'/';
    }

    public function issuesUrl(): string
    {
        return $this->baseUrl.'/organizations/'.$this->org.'/issues/?query=is:unresolved&statsPeriod=24h';
    }
}
