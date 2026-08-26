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
// CR-2 FIX (Iter-001): Scheduler started by docker-start.sh.
// K-5 FIX (Iter-005): queue:prune-failed added.
// K-9 FIX (Iter-005): cohort/onboarding analytics added.
// A-1 FIX (Iter-006): backup commands added.
// A-10 FIX (Iter-006): operational alert check added.

// ── Hourly ────────────────────────────────────────────────────────────────

Schedule::command('exospace:verify-pending-domains')
    ->hourly()
    ->withoutOverlapping(60)
    ->onOneServer();

// A-10 FIX (Iter-006): Check operational health every 5 minutes.
// Sends alerts if failed_jobs > threshold, disk > 80%, or scheduler is stale.
Schedule::call(function () {
    app(\App\Services\OperationalAlertService::class)->checkAndAlert();
})
    ->everyFiveMinutes()
    ->name('operational-alerts')
    ->withoutOverlapping(5)
    ->onOneServer();

// ── Every 5 minutes ───────────────────────────────────────────────────────

Schedule::command('exospace:purge-banned-sessions')
    ->everyFiveMinutes()
    ->withoutOverlapping(5)
    ->onOneServer();

// ── Daily ─────────────────────────────────────────────────────────────────

// ITERATION-3: reconcile local plan state against the 2Checkout API —
// catches missed cancellation webhooks (paid entitlements leaking after a
// 2CO-side subscription end) and missed payment webhooks (alert-only).
// 04:10 offsets it from the 03:00–04:00 maintenance batch; capped at 200
// users per run, drift beyond the cap reconciles on following runs.
Schedule::command('exospace:reconcile-subscriptions')
    ->dailyAt('04:10')
    ->withoutOverlapping(30)
    ->onOneServer();

Schedule::command('exospace:rollup-analytics')
    ->dailyAt('03:00')
    ->withoutOverlapping(60)
    ->onOneServer();

// ITERATION-4: pre-populate sitemap caches so crawler requests never pay
// the cold-rebuild cost in-request (Cache::flexible's cold path computes
// inline — multi-second COUNT + 2k-row render lands on Googlebot today).
// 04:15 slots it after reconcile (04:10) and before the seo:audit health
// check (04:30), so the audit inspects a warmed cache. Page-capped per
// group; deeper pages stay lazy.
Schedule::command('sitemap:warm')
    ->dailyAt('04:15')
    ->withoutOverlapping(30)
    ->onOneServer();

// ── SEO OS (Iteration 6): daily SEO health audit ──────────────────────────
// Platform-data-only report (never fabricated search data). Posts to Slack
// via OPERATIONAL_ALERT_WEBHOOK when warnings exist.
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

Schedule::command('exospace:send-dunning')
    ->dailyAt('11:00')
    ->withoutOverlapping(60)
    ->onOneServer();

// K-5 FIX (Iter-005): Prune failed jobs older than 7 days.
Schedule::command('queue:prune-failed --hours=168')
    ->dailyAt('02:30')
    ->onOneServer();

// A-1 FIX (Iter-006): Daily database backup at 1am.
// Requires: composer require spatie/laravel-backup
Schedule::command('backup:run --only-db')
    ->dailyAt('01:00')
    ->withoutOverlapping(60)
    ->onOneServer();

// A-1 FIX (Iter-006): Weekly file backup (user uploads) on Sundays at 1:30am.
Schedule::command('backup:run --only-files')
    ->weeklyOn(0, '01:30')
    ->withoutOverlapping(120)
    ->onOneServer();

// A-1 FIX (Iter-006): Clean up old backups daily at 2am.
Schedule::command('backup:clean')
    ->dailyAt('02:00')
    ->withoutOverlapping(60)
    ->onOneServer();

// ── Weekly ────────────────────────────────────────────────────────────────

// K-9 FIX (Iter-005): Cohort retention analytics.
Schedule::command('exospace:cohort-retention --weeks=8')
    ->weeklyOn(1, '06:00')
    ->withoutOverlapping(60)
    ->onOneServer();

// K-9 FIX (Iter-005): Onboarding funnel analytics.
Schedule::command('exospace:onboarding-analytics --days=30')
    ->weeklyOn(1, '06:30')
    ->withoutOverlapping(60)
    ->onOneServer();

// ── Monthly ───────────────────────────────────────────────────────────────

// C-1 FIX (Iter-003): Partition maintenance for transactions table.
Schedule::command('exospace:prune-transactions')
    ->monthlyOn(1, '05:00')
    ->withoutOverlapping(120)
    ->onOneServer();

// G-5 FIX (Iter-003): Anonymize PII on old transactions + invoices.
Schedule::command('exospace:anonymize-pii')
    ->monthlyOn(1, '05:30')
    ->withoutOverlapping(120)
    ->onOneServer();

// G-6 FIX (Iter-010): Scrub PII from old admin_audit_logs.payload.
// Runs after exospace:anonymize-pii so all PII retention happens in
// one monthly batch (predictable operator schedule).
Schedule::command('exospace:anonymize-audit-pii')
    ->monthlyOn(1, '06:00')
    ->withoutOverlapping(120)
    ->onOneServer();

// ── ITERATION-5 (AUDIT-P1-5.1/5.2/5.3): PII retention for the 3 tables ──
// that were missing anonymization jobs (flagged in the original audit).
// These run after exospace:anonymize-audit-pii so ALL PII retention
// (transactions, invoices, audit logs, feedback, RSVPs, newsletter signups)
// completes in one monthly batch before any morning traffic.
//
// Each command anonymizes PII on rows older than 18 months (default),
// preserving the non-PII fields (category, status, gallery_id, etc.) for
// aggregate analytics. Idempotent — re-running is a no-op.
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

// A-5 FIX (Iter-006): Process scheduled GDPR deletion requests.
// Deletes users whose 30-day grace period has expired.
Schedule::call(function () {
    $requests = \App\Models\GdprDeletionRequest::where('status', 'pending')
        ->where('scheduled_deletion_at', '<=', now())
        ->get();

    foreach ($requests as $request) {
        $user = \App\Models\User::find($request->user_id);
        if ($user) {
            app(\App\Services\UserDeletionService::class)
                ->deleteUser($user, 'GDPR deletion request (30-day grace period expired)');
        }
        $request->update([
            'status'        => 'completed',
            'completed_at'  => now(),
        ]);
    }
})
    ->dailyAt('04:30')
    ->name('gdpr-deletion-processing')
    ->withoutOverlapping(60)
    ->onOneServer();
