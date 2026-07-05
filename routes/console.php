<?php

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
//     Without this, two overlapping cron runs (multi-container Coolify
//     or a long run + a new cron tick) would both process the same data
//     — e.g. double-sending abandoned-cart emails.
//   - ->onOneServer(): ensures the command runs on only ONE container
//     even in a multi-container Coolify deployment. Requires
//     CACHE_STORE=redis (which the production env has). Without this,
//     every container fires every scheduled task — N containers = N
//     duplicate emails/jobs.
//
// Coolify deployment: a separate `schedule:run` cron service must be
// configured in Coolify for these to fire.

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
Schedule::command('exospace:prune-transactions')
    ->monthlyOn(1, '05:00')
    ->withoutOverlapping(60)
    ->onOneServer();
