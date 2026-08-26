<?php

namespace App\Console\Commands;

use App\Mail\BillingExportEmail;
use App\Models\AdminAuditLog;
use App\Models\Transaction;
use App\Services\BillingExportService;
use App\Services\JobHeartbeatService;
use App\Services\OperationalAlertService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/**
 * ITERATION 6 — weekly scheduled billing digest.
 *
 * The gap: the Iteration-5 CSV export was on-demand only. Finance
 * reconciliation ("match the 2Checkout statement") runs on a schedule,
 * not on someone remembering to click Export — and a digest that arrives
 * predictably every Monday is itself evidence (a missing digest means
 * the pipeline broke, caught by the job heartbeat).
 *
 * What it sends (to every address in BILLING_EXPORT_EMAIL):
 *   - Trailing-7-day money events (refunds / partial refunds /
 *     chargebacks) as a CSV attachment — byte-identical columns to the
 *     on-demand export (shared BillingExportService code path).
 *   - Week summary: completed sales, revenue, per-status counts.
 *   - Failed-webhook count with a pointer to Billing Review.
 * Zero-row weeks still send (a predictable "nothing happened" is
 * reconciliation evidence; a missing email is a signal).
 *
 * Safety / trust:
 *   - Unconfigured (no BILLING_EXPORT_EMAIL) → clean no-op, same
 *     convention as exospace:reconcile-subscriptions without 2CO creds.
 *   - Every send is audit-logged as billing.exported with actor=system
 *     (actor_id NULL) — PII leaving the system must be attributable,
 *     same bar as the on-demand export.
 *   - Delivery failure of one recipient does not abort the others;
 *     a total failure pages via OperationalAlertService (critical) —
 *     a silent missing digest is exactly what this job exists to
 *     prevent, including for itself.
 *
 * SCHEDULE: weekly Monday 07:00 (after the analytics pair at 06:00/06:30,
 * before business hours) via routes/console.php.
 */
class SendBillingExport extends Command
{
    protected $signature = 'exospace:send-billing-export
                            {--days=7 : Trailing window in days}
                            {--to= : Override recipient(s) — comma-separated (testing/manual)}';

    protected $description = 'Send the weekly billing digest (money-events CSV + summary) to the configured recipients.';

    public function handle(BillingExportService $exporter, OperationalAlertService $alerts): int
    {
        $recipients = $this->resolveRecipients();

        if ($recipients === []) {
            $this->info('No billing-export recipient configured (BILLING_EXPORT_EMAIL) — nothing to send. The on-demand export on Billing Review is unaffected.');

            // A completed no-op still proves the scheduler ran the job
            // (same convention as reconcile's unconfigured path) — feature
            // OFF must not read as job DEAD to the heartbeat monitor.
            app(JobHeartbeatService::class)->stamp('exospace:send-billing-export');

            return self::SUCCESS;
        }

        // Rolling-deploy safety: the ledger backstop reads a table that
        // predates this command, but stay defensive anyway — a digest
        // that crashes the scheduler slot is worse than one that skips
        // its webhook line.
        $webhookCount = 0;
        if (Schema::hasTable('processed_webhooks')) {
            $webhookCount = (int) \App\Models\ProcessedWebhook::where('status', 'failed')->count();
        }

        $days = max(1, min(90, (int) $this->option('days')));
        $since = now()->subDays($days)->startOfDay();
        $window = [
            'from' => $since->toDateString(),
            'to'   => now()->toDateString(),
        ];

        // Same code path as the on-demand export — byte-identical columns.
        $csv = $exporter->transactionsCsv(null, $since);
        $summary = $exporter->summary($since);
        $summary['failed_webhooks'] = $webhookCount;

        $moneyEvents = $summary['refunded'] + $summary['partial_refund'] + $summary['chargeback'];
        $this->info(sprintf(
            'Billing digest %s → %s: %d money event(s), %d completed sale(s), revenue %.2f, %d failed webhook(s).',
            $window['from'],
            $window['to'],
            $moneyEvents,
            $summary['completed'],
            $summary['revenue'],
            $webhookCount,
        ));

        $delivered = 0;
        $failed = 0;

        foreach ($recipients as $recipient) {
            try {
                Mail::to($recipient)->send(new BillingExportEmail($summary, $csv, $window));
                $delivered++;
                $this->info("  → sent to {$recipient}");
            } catch (\Throwable $e) {
                $failed++;
                Log::error('SendBillingExport: delivery failed', [
                    'recipient' => $recipient,
                    'error'     => $e->getMessage(),
                ]);
                $this->error("  → FAILED for {$recipient}: {$e->getMessage()}");
            }
        }

        // PII leaving the system must be attributable — system actor
        // (actor_id NULL), same convention as the webhook-handler audit
        // rows. Target follows the webhook auditWebhook() convention: the
        // newest transaction in the window (a real row that left the
        // system), falling back to the newest transaction ever for
        // zero-row weeks, skipping only on an install with no
        // transactions at all (no PII could have left).
        $target = Transaction::orderByDesc('id')->first();

        if ($target !== null) {
            AdminAuditLog::record('billing.exported', $target, [
                'export_type' => 'scheduled_digest',
                'days'        => $days,
                'row_count'   => $csv['count'],
                'recipients'  => $delivered,
                'delivery_failures' => $failed,
            ]);
        } else {
            Log::info('SendBillingExport: audit row skipped — no transactions exist to target (no PII left the system).');
        }

        if ($failed > 0 && $delivered === 0) {
            $alerts->alert(
                'Weekly billing digest delivery failed',
                "The billing digest could not be delivered to any of {$failed} configured recipient(s) ({$csv['count']} money-event rows were prepared). Check MAIL_* configuration and the recipient addresses.",
                'critical',
                'billing_export_delivery_failed',
            );

            Log::info('SendBillingExport: digest NOT delivered to anyone', [
                'window'   => $window,
                'failures' => $failed,
                'csv_rows' => $csv['count'],
            ]);

            // Total delivery failure: no heartbeat stamp + non-zero exit —
            // the scheduler log records the failure and the heartbeat
            // monitor becomes the second net (a digest that silently
            // never arrives is exactly what this job exists to prevent).
            return self::FAILURE;
        }

        Log::info('SendBillingExport: digest sent', [
            'window'    => $window,
            'recipients'=> $delivered,
            'failures'  => $failed,
            'csv_rows'  => $csv['count'],
        ]);

        app(JobHeartbeatService::class)->stamp('exospace:send-billing-export');

        return self::SUCCESS;
    }

    /**
     * Recipients: --to override first (manual/testing), then the
     * UI-managed DB list (Iteration 7), then the configured env list
     * as fallback. Normalized + de-duplicated.
     *
     * Precedence (highest → lowest):
     *   1. --to option (testing/manual override)
     *   2. billing_digest_recipients rows (UI-managed list — the
     *      source of truth once an admin has added even one; audit-
     *      logged add/remove, survives across deploys)
     *   3. BILLING_EXPORT_EMAIL env var (the original config — kept
     *      as the zero-deploy-config fallback so a fresh install
     *      with no recipients managed in the UI still works)
     *
     * The fallback is the safety hatch for a brand-new install or
     * for a rollback from a UI-managed state to env-only — but the
     * Billing Review page surfaces which source is currently active
     * so an operator is never surprised by who is receiving the
     * financial digest.
     *
     * @return list<string>
     */
    private function resolveRecipients(): array
    {
        $raw = $this->option('to');

        // DB list: one query, ordered for deterministic output.
        // Returns empty array on a rolling deploy where the table
        // doesn't exist yet — Schema::hasTable guard so the command
        // never crashes mid-deploy.
        if (! is_string($raw) || trim($raw) === '') {
            if (\Illuminate\Support\Facades\Schema::hasTable('billing_digest_recipients')) {
                $dbEmails = \App\Models\BillingDigestRecipient::orderBy('email')->pluck('email')->all();
                if ($dbEmails !== []) {
                    return $dbEmails;
                }
            }

            $raw = (string) (config('services.billing_export.email') ?? '');
        }

        if (trim($raw) === '') {
            return [];
        }

        $recipients = [];
        foreach (explode(',', $raw) as $email) {
            $email = trim($email);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && ! in_array($email, $recipients, true)) {
                $recipients[] = $email;
            }
        }

        return $recipients;
    }
}
