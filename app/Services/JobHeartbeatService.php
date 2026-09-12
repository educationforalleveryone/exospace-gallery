<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class JobHeartbeatService
{
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
        'exospace:backup:db'               => 36,
        'exospace:backup:files'            => 192, // weekly Sun
        'exospace:backup:clean'            => 36,
    ];

    public function stamp(string $job): void
    {
        $maxAge = self::MONITORED_JOBS[$job] ?? 48;

        try {
            Cache::put($this->key($job), now()->toIso8601String(), now()->addHours($maxAge * 4));
            // A fresh stamp invalidates any pending "never ran" ack.
            Cache::forget($this->ackKey($job));
        } catch (\Throwable) {
            // Cache unavailable — never fail the monitored job.
        }
    }

    public function status(string $job): string
    {
        $maxAgeHours = self::MONITORED_JOBS[$job] ?? 48;

        $lastAt = $this->lastRunAt($job);

        if ($lastAt === null) {
            $last = Cache::get($this->key($job));

            return $last !== null ? 'stale' : 'missing';
        }

        return $lastAt->addHours($maxAgeHours)->isFuture() ? 'fresh' : 'stale';
    }

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
