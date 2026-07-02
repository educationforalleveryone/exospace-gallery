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

// Every 5 minutes: purge sessions for banned users. The CheckBanned
// middleware purges the current session, but the user's other sessions
// (laptop, mobile) remain valid until their next request. This command
// catches them. (Task H51 / audit H16.)
Schedule::command('exospace:purge-banned-sessions')->everyFiveMinutes();

// Daily at 10am: send abandoned-cart recovery emails for pending
// upgrades older than 24 hours. (Task H53)
Schedule::command('exospace:abandoned-cart')->dailyAt('10:00');

// Daily at 9am: send lifecycle nudge emails. (Task H55)
//   - "You haven't published in 7 days" for users with 0 published galleries
//   - "Your plan expires soon" for admin-granted expiring plans
Schedule::command('exospace:send-lifecycle-emails')->dailyAt('09:00');
