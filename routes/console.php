<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ── Scheduled tasks (added in Iteration 02 / task C06) ───────────────────
//
// The audit noted (M2) that this project has zero scheduled tasks. We add
// the first one here — DNS verification retry for custom domains. More
// scheduled tasks (analytics rollup, stale-session cleanup, backup,
// sitemap warm) should be added in later iterations.
//
// Coolify deployment: a separate `schedule:run` cron service must be
// configured in Coolify for these to fire. See the deployment notes in
// the CHANGELOG for Iteration 02.

// Hourly: retry DNS verification for galleries with a pending custom_domain.
// DNS propagation can take up to 60 min for some providers; this catches
// pending verifications in the background so the user doesn't have to
// click "Verify now" repeatedly. (Task C06.)
Schedule::command('exospace:verify-pending-domains')->hourly();

// Daily at 3am: roll up raw analytics_events into analytics_daily, then
// prune events older than 90 days. Keeps the analytics_events table from
// growing unboundedly. (Task H30 / audit H31.)
Schedule::command('exospace:rollup-analytics')->dailyAt('03:00');
