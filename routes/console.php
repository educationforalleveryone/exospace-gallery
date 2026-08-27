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

// A-1 FIX (Iter-006 / Iter-7): backup commands run through the
// exospace:backup wrapper so they stamp heartbeats on success
// (JobHeartbeatService) and post Slack alerts on failure (in addition
// to spatie's own mail notifications + the backup-health check that
// catches a stale newest-zip on a disk).
//
// Schedule is unchanged in cadence; only the invoked command swapped:
//   daily 01:00   → exospace:backup db     (was: backup:run --only-db)
//   Sun   01:30   → exospace:backup files  (was: backup:run --only-files)
//   daily 02:00   → exospace:backup clean  (was: backup:clean)
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

// ── Weekly ────────────────────────────────────────────────────────────────

// K-9 FIX (Iter-005): Cohort retention analytics.
// ITERATION 6: the command now persists its matrix into retention_snapshots
// (retention history) and posts the summary to the operational Slack
// channel instead of scheduler stdout.
Schedule::command('exospace:cohort-retention --weeks=8')
    ->weeklyOn(1, '06:00')
    ->withoutOverlapping(60)
    ->onOneServer();

// K-9 FIX (Iter-005): Onboarding funnel analytics.
Schedule::command('exospace:onboarding-analytics --days=30')
    ->weeklyOn(1, '06:30')
    ->withoutOverlapping(60)
    ->onOneServer();

// ITERATION 6: weekly billing digest — trailing-7-day money events as a
// CSV (same code path as the Billing Review on-demand export) emailed to
// BILLING_EXPORT_EMAIL. Unconfigured → clean no-op. After the analytics
// pair (06:00/06:30), before business hours.
Schedule::command('exospace:send-billing-export')
    ->weeklyOn(1, '07:00')
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

// ── ITERATION 11: prune the webhook_deliveries ledger. ───────────────
// One row per OutboundWebhookService::dispatch completion (success OR
// retry-exhausted) — unbounded row growth without this prune. Default
// retention: 30 days (configurable via OUTBOUND_WEBHOOK_LEDGER_
// RETENTION_DAYS). Runs daily at 03:17 (off-peak, before the 04:30
// GDPR-deletion-processing and the 05:00 backup window — keeps the
// retention batch isolated). Audit-logged as webhook.deliveries_pruned
// (target = newest surviving row, same convention as RunMonitoredBackup).
Schedule::command('webhook-deliveries:prune')
    ->dailyAt('03:17')
    ->name('webhook-deliveries-prune')
    ->withoutOverlapping(60)
    ->onOneServer();

// ── OpsCenter (Iteration 1) ───────────────────────────────────────────
//
// Platform sync: pulls servers/applications/databases/services/deployments
// from the Coolify API into the ops tables every 5 minutes. This is what
// makes the control plane platform-wide (all apps on the box, not just
// Exospace) with zero agents and zero Docker socket access. Failures are
// recorded as events by the command itself — never fatal to the chain.
Schedule::command('ops:sync-platform')
    ->everyFiveMinutes()
    ->name('ops-sync-platform')
    ->withoutOverlapping(5)
    ->onOneServer();

// ops_events retention: auto-resolve stale events, delete old resolved
// ones (documented policy in config/ops.php). 03:35 slots it after the
// 03:17 webhook-ledger prune, before the 04:00 maintenance batch.
Schedule::command('ops:prune-events')
    ->dailyAt('03:35')
    ->name('ops-prune-events')
    ->withoutOverlapping(60)
    ->onOneServer();
