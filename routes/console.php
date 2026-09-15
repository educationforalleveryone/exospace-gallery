<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('exospace:verify-pending-domains')
    ->hourly()
    ->withoutOverlapping(60)
    ->onOneServer();

Schedule::call(function () {
    app(\App\Services\OperationalAlertService::class)->checkAndAlert();
})
    ->everyFiveMinutes()
    ->name('operational-alerts')
    ->withoutOverlapping(5)
    ->onOneServer();

Schedule::command('exospace:purge-banned-sessions')
    ->everyFiveMinutes()
    ->withoutOverlapping(5)
    ->onOneServer();

Schedule::command('exospace:reconcile-subscriptions')
    ->dailyAt('04:10')
    ->withoutOverlapping(30)
    ->onOneServer();

Schedule::command('exospace:rollup-analytics')
    ->dailyAt('03:00')
    ->withoutOverlapping(60)
    ->onOneServer();

Schedule::command('sitemap:warm')
    ->dailyAt('04:15')
    ->withoutOverlapping(30)
    ->onOneServer();

Schedule::command('exospace:seo-audit')
    ->dailyAt('04:30')
    ->withoutOverlapping(30)
    ->onOneServer();

Schedule::command('exospace:abandoned-cart')
    ->dailyAt('10:00')
    ->withoutOverlapping(60)
    ->onOneServer();

Schedule::command('exospace:send-lifecycle-emails')
    ->dailyAt('09:00')
    ->withoutOverlapping(60)
    ->onOneServer();

Schedule::command('exospace:cleanup-stale')
    ->dailyAt('04:00')
    ->withoutOverlapping(60)
    ->onOneServer();

// Remove stale media-library temp files (interrupted conversions) and media
// rows whose model no longer exists. Orphan detection respects soft deletes.
Schedule::command('media-library:clean --delete-orphaned --force')
    ->dailyAt('04:20')
    ->withoutOverlapping(60)
    ->onOneServer();

// Re-register media for artworks whose registration failed; dry-run report
// only when dispatched without --fix.
Schedule::command('exospace:reconcile-artwork-media --fix --limit=100')
    ->dailyAt('04:45')
    ->withoutOverlapping(60)
    ->onOneServer();

Schedule::command('exospace:send-dunning')
    ->dailyAt('11:00')
    ->withoutOverlapping(60)
    ->onOneServer();

// Prune failed jobs older than 7 days.
Schedule::command('queue:prune-failed --hours=168')
    ->dailyAt('02:30')
    ->onOneServer();

Schedule::command('exospace:backup db')
    ->dailyAt('01:00')
    ->withoutOverlapping(60)
    ->onOneServer();

Schedule::command('exospace:backup files')
    ->weeklyOn(0, '01:30')
    ->withoutOverlapping(120)
    ->onOneServer();

Schedule::command('exospace:backup clean')
    ->dailyAt('02:00')
    ->withoutOverlapping(60)
    ->onOneServer();

Schedule::command('exospace:cohort-retention --weeks=8')
    ->weeklyOn(1, '06:00')
    ->withoutOverlapping(60)
    ->onOneServer();

// Onboarding funnel analytics.
Schedule::command('exospace:onboarding-analytics --days=30')
    ->weeklyOn(1, '06:30')
    ->withoutOverlapping(60)
    ->onOneServer();

Schedule::command('exospace:send-billing-export')
    ->weeklyOn(1, '07:00')
    ->withoutOverlapping(60)
    ->onOneServer();

// Partition maintenance for transactions table.
Schedule::command('exospace:prune-transactions')
    ->monthlyOn(1, '05:00')
    ->withoutOverlapping(120)
    ->onOneServer();

// Anonymize PII on old transactions + invoices.
Schedule::command('exospace:anonymize-pii')
    ->monthlyOn(1, '05:30')
    ->withoutOverlapping(120)
    ->onOneServer();

Schedule::command('exospace:anonymize-audit-pii')
    ->monthlyOn(1, '06:00')
    ->withoutOverlapping(120)
    ->onOneServer();

Schedule::command('exospace:anonymize-feedback-pii')
    ->monthlyOn(1, '06:30')
    ->withoutOverlapping(120)
    ->onOneServer();

Schedule::command('exospace:anonymize-rsvp-pii')
    ->monthlyOn(1, '06:45')
    ->withoutOverlapping(120)
    ->onOneServer();

Schedule::command('exospace:anonymize-newsletter-pii')
    ->monthlyOn(1, '07:00')
    ->withoutOverlapping(120)
    ->onOneServer();

Schedule::command('exospace:process-gdpr-deletions')
    ->dailyAt('04:30')
    ->withoutOverlapping(60)
    ->onOneServer();

Schedule::command('webhook-deliveries:prune')
    ->dailyAt('03:17')
    ->name('webhook-deliveries-prune')
    ->withoutOverlapping(60)
    ->onOneServer();

Schedule::command('ops:sync-platform')
    ->everyFiveMinutes()
    ->name('ops-sync-platform')
    ->withoutOverlapping(5)
    ->onOneServer();

Schedule::command('ops:prune-events')
    ->dailyAt('03:35')
    ->name('ops-prune-events')
    ->withoutOverlapping(60)
    ->onOneServer();

Schedule::command('ops:correlate-incidents')
    ->everyFiveMinutes()
    ->name('ops-correlate-incidents')
    ->withoutOverlapping(5)
    ->onOneServer();

Schedule::command('ops:sweep-diagnostics')
    ->everyFifteenMinutes()
    ->name('ops-sweep-diagnostics')
    ->withoutOverlapping(10)
    ->onOneServer();

Schedule::command('ops:sweep-credentials')
    ->dailyAt('09:00')
    ->name('ops-sweep-credentials')
    ->withoutOverlapping(30)
    ->onOneServer();

Schedule::command('ops:send-morning-digest')
    ->dailyAt('08:15')
    ->name('ops-send-morning-digest')
    ->withoutOverlapping(30)
    ->onOneServer();

Schedule::command('ops:send-weekly-review')
    ->weeklyOn(1, '08:30')
    ->name('ops-send-weekly-review')
    ->withoutOverlapping(30)
    ->onOneServer();

Schedule::command('ops:check-digest-delivery')
    ->dailyAt('08:45')
    ->name('ops-check-digest-delivery')
    ->withoutOverlapping(30)
    ->onOneServer();
