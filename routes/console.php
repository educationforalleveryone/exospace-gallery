<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ── Scheduled tasks ───────────────────────────────────────────────────────
//
// P1-12 FIX (audit): All scheduled commands now use:
//   - ->withoutOverlapping(60): prevents a second instance from starting
//     while the first is still running (60-minute overlap lock TTL).
//   - ->onOneServer(): ensures the command runs on only ONE container
//     even in a multi-container Coolify deployment.
//
// CR-2 FIX (Iter-001): The scheduler process is now started by
// docker-start.sh (a background loop running schedule:run every 60s).
// Previously NO scheduler process was started.
//
// K-5 FIX (Iter-005): Added queue:prune-failed (daily) — prevents the
// failed_jobs table from growing unbounded.
//
// K-9 FIX (Iter-005): Added cohort-retention + onboarding-analytics (weekly)
// — provides trend tracking data for product decisions.

// Hourly: retry DNS verification for galleries with a pending custom_domain.
Schedule::command('exospace:verify-pending-domains')
    ->hourly()
    ->withoutOverlapping(60)
    ->onOneServer();

// Daily at 3am: roll up raw analytics_events into analytics_daily, then
// prune events older than 90 days.
Schedule::command('exospace:rollup-analytics')
    ->dailyAt('03:00')
    ->withoutOverlapping(60)
    ->onOneServer();

// Every 5 minutes: purge sessions for banned users.
Schedule::command('exospace:purge-banned-sessions')
    ->everyFiveMinutes()
    ->withoutOverlapping(5)
    ->onOneServer();

// Daily at 10am: send abandoned-cart recovery emails.
Schedule::command('exospace:abandoned-cart')
    ->dailyAt('10:00')
    ->withoutOverlapping(60)
    ->onOneServer();

// Daily at 9am: send lifecycle nudge emails.
Schedule::command('exospace:send-lifecycle-emails')
    ->dailyAt('09:00')
    ->withoutOverlapping(60)
    ->onOneServer();

// Daily at 4am: clean up stale data.
Schedule::command('exospace:cleanup-stale')
    ->dailyAt('04:00')
    ->withoutOverlapping(60)
    ->onOneServer();

// S-10: Monthly (1st of each month at 5am) — create future partitions +
// drop old ones on the transactions table. Idempotent, safe to re-run.
// Default retention: 7 years (IRS financial record requirement).
// C-1 FIX (Iter-003): The partition pruning now correctly drops old
// partitions (was silently broken by FROM_DAYS bug).
Schedule::command('exospace:prune-transactions')
    ->monthlyOn(1, '05:00')
    ->withoutOverlapping(120)
    ->onOneServer();

// SEC-10: Monthly (1st of each month at 5:30am) — anonymize PII on
// transactions AND invoices (G-5 FIX from Iter-003) older than 18 months.
Schedule::command('exospace:anonymize-pii')
    ->monthlyOn(1, '05:30')
    ->withoutOverlapping(120)
    ->onOneServer();

// M-9: Daily at 11am — send dunning emails (steps 2 + 3) for subscriptions
// with failed payments.
Schedule::command('exospace:send-dunning')
    ->dailyAt('11:00')
    ->withoutOverlapping(60)
    ->onOneServer();

// K-5 FIX (Iter-005): Prune failed jobs older than 7 days.
// Without this, the failed_jobs table grows unbounded. HealthController::check
// flags degraded if failed_jobs > 100 — without pruning, the health check
// eventually always reports degraded.
Schedule::command('queue:prune-failed --hours=168')
    ->dailyAt('02:30')
    ->onOneServer();

// K-9 FIX (Iter-005): Weekly cohort retention analytics.
// Provides trend tracking data for product decisions. The command prints
// results to STDOUT and logs them. A future iteration could persist results
// to an analytics_reports table for dashboard visualization.
Schedule::command('exospace:cohort-retention --weeks=8')
    ->weeklyOn(1, '06:00')
    ->withoutOverlapping(60)
    ->onOneServer();

// K-9 FIX (Iter-005): Weekly onboarding funnel analytics.
// Tracks signup -> first gallery -> first publish -> first share conversion.
Schedule::command('exospace:onboarding-analytics --days=30')
    ->weeklyOn(1, '06:30')
    ->withoutOverlapping(60)
    ->onOneServer();
