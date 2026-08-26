<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * ITERATION 6 — per-job heartbeat tracking.
 *
 * The gap: OperationalAlertService::checkSchedulerHealth() watches the
 * scheduler.log file mtime — it catches a DEAD SCHEDULER LOOP, but not a
 * job that silently stops running while the scheduler itself is healthy:
 * a schedule entry lost in a deploy, an exception thrown before the
 * command's own alerting runs, an onOneServer mutex stuck after a crash,
 * or a job that was never registered on a new environment. The 2Checkout
 * reconcile job is the sharpest case — it is the safety net for missed
 * billing webhooks; if IT stops running, money drift accumulates silently
 * behind an otherwise green dashboard.
 *
 * How it works:
 *   - stamp($job)  — a scheduled command calls this on SUCCESSFUL
 *     completion (including clean no-ops: a completed no-op still proves
 *     the scheduler ran the job). Cache-backed (Redis in production) so
 *     it survives requests and processes.
 *   - status($job) — 'fresh' (stamped within maxAge), 'stale' (older
 *     than maxAge), or 'missing' (never stamped since monitoring started).
 *     'missing' is only alerted AFTER a first-observation ack has aged
 *     past maxAge — a brand-new install (or a freshly added job) must not
 *     page on day one; a job that NEVER runs pages once the expectation
 *     window has demonstrably elapsed.
 *
 * Heartbeat writes are best-effort: a cache outage must never fail the
 * monitored job (the worst case is a duplicate or late alert, which is
 * better than breaking reconciliation to report on it).
 */
class JobHeartbeatService
{
    /**
     * Jobs monitored by OperationalAlertService::checkJobHeartbeats().
     *
     * max_age_hours is the alerting threshold — generously beyond the
     * schedule cadence (daily 04:xx jobs get 36h: one full missed run
     * plus schedule-jitter headroom; weekly Monday jobs get 8 days).
     *
     * To add a job: add it here and call
     * JobHeartbeatService::stamp('name') at the end of its handle().
     */
    public const MONITORED_JOBS = [
        // Daily 04:10 — the billing-truth safety net.
        'exospace:reconcile-subscriptions' => 36,
        // Daily 04:15 — crawler-facing sitemap warmth.
        'sitemap:warm'                     => 36,
        // Daily 04:00 — retention-bound cleanup.
        'exospace:cleanup-stale'           => 36,
        // Daily 04:30 — SEO health audit.
        'exospace:seo-audit'               => 36,
        // Weekly Monday 06:00 / 06:30 — analytics persistence + delivery.
        'exospace:cohort-retention'        => 192, // 8 days
        'exospace:onboarding-analytics'    => 192, // 8 days
        // Weekly Monday 07:00 — billing export digest (configured installs).
        'exospace:send-billing-export'     => 192, // 8 days
        // ITERATION 7 — backup jobs wrapped in exospace:backup so they
        // stamp heartbeats on success and Slack-alert on failure.
        // spatie's backup:run exits 0/nonzero; the wrapper translates
        // that into the JobHeartbeatService contract (stamp on 0, leave
        // unstamped on failure so the heartbeat monitor becomes the
        // second net for a wrapper that itself crashed before alerting).
        'exospace:backup:db'               => 36,
        'exospace:backup:files'            => 192, // weekly Sun
        'exospace:backup:clean'            => 36,
    ];

    /**
     * Record that a job just completed successfully.
     */
    public function stamp(string $job): void
    {
        $maxAge = self::MONITORED_JOBS[$job] ?? 48;

        try {
            // TTL comfortably beyond the alerting window — a heartbeat
            // must not expire before the staleness check can see it.
            Cache::put($this->key($job), now()->toIso8601String(), now()->addHours($maxAge * 4));
            // A fresh stamp invalidates any pending "never ran" ack.
            Cache::forget($this->ackKey($job));
        } catch (\Throwable) {
            // Cache unavailable — never fail the monitored job.
        }
    }

    /**
     * @return 'fresh'|'stale'|'missing'
     */
    public function status(string $job): string
    {
        $maxAgeHours = self::MONITORED_JOBS[$job] ?? 48;

        $lastAt = $this->lastRunAt($job);

        if ($lastAt === null) {
            $last = Cache::get($this->key($job));

            // An unparseable-but-present heartbeat is corruption — report
            // 'stale' so the alert check surfaces it rather than silently
            // treating it as "never ran".
            return $last !== null ? 'stale' : 'missing';
        }

        return $lastAt->addHours($maxAgeHours)->isFuture() ? 'fresh' : 'stale';
    }

    /**
     * When the job last stamped a heartbeat (null = never).
     */
    public function lastRunAt(string $job): ?\Carbon\Carbon
    {
        $last = Cache::get($this->key($job));

        if (! is_string($last) || $last === '') {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($last);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * First-observation timestamp for a job that has never stamped a
     * heartbeat ("we noticed it hasn't run yet; the clock starts now").
     */
    public function firstObservedMissingAt(string $job): ?\Carbon\Carbon
    {
        $value = Cache::get($this->ackKey($job));

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Acknowledge a missing heartbeat (called by the alert check the
     * first time it observes the job absent — starts the grace clock).
     */
    public function ackMissing(string $job): void
    {
        try {
            if ($this->firstObservedMissingAt($job) === null) {
                Cache::put($this->ackKey($job), now()->toIso8601String(), now()->addHours(24 * 30));
            }
        } catch (\Throwable) {
            // Cache unavailable — never block the alert check.
        }
    }

    private function key(string $job): string
    {
        return "heartbeat:job:{$job}";
    }

    private function ackKey(string $job): string
    {
        return "heartbeat:job:{$job}:missing_since";
    }
}
