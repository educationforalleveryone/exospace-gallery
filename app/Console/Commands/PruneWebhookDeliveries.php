<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AdminAuditLog;
use App\Models\WebhookDelivery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * ITERATION 11 — daily prune of the webhook_deliveries ledger.
 *
 * The ledger grows by one row per OutboundWebhookService::dispatch
 * completion (success OR retry-exhausted). The async path's ledger
 * writes are deferred per the dispatchAsync docstring — but the SYNC
 * path (billing.recipient_added / _removed, security-page events)
 * writes a row per dispatch, and that's unbounded row growth over
 * time. This command prunes rows older than the retention window
 * (default 30 days, configurable via OUTBOUND_WEBHOOK_LEDGER_
 * RETENTION_DAYS).
 *
 * Audit-logging: the prune is a system-actor operation. Following
 * the RunMonitoredBackup + SendBillingExport precedent, the target
 * is the newest surviving WebhookDelivery row after the prune (a
 * real Model row proving the install has data; falls back to
 * Log::info skip on a fresh install with zero rows after prune).
 * The audit payload carries `rows_deleted` + `oldest_delivered_at`
 * + `retention_days` so the operator can reconstruct the prune
 * scope at any audit-row inspection.
 *
 * Schedule: daily at 03:17 (off-peak). Logged via Log::info on
 * completion (the same convention as RunMonitoredBackup).
 *
 * The Schema::hasTable guard means a fresh install with the
 * migration not yet applied (or a test database that didn't run
 * this migration) is a no-op with a friendly log line (same shape
 * as the Iter-10 Schema::hasTable('webhook_subscriptions') guard).
 */
class PruneWebhookDeliveries extends Command
{
    protected $signature = 'webhook-deliveries:prune
                            {--dry-run : Report what would be deleted without actually deleting}
                            {--days= : Override the retention window (defaults to OUTBOUND_WEBHOOK_LEDGER_RETENTION_DAYS)}';

    protected $description = 'ITERATION 11 — prune webhook_deliveries rows older than the retention window (default 30 days).';

    public function handle(): int
    {
        if (! Schema::hasTable('webhook_deliveries')) {
            $this->info('webhook_deliveries table does not exist yet — nothing to prune (fresh install before the Iter-11 migration ran).');
            Log::info('PruneWebhookDeliveries: table not yet migrated — no-op.');
            return self::SUCCESS;
        }

        $retentionDays = (int) ($this->option('days') !== null
            ? $this->option('days')
            : config('services.outbound_webhook.ledger_retention_days', 30));

        if ($retentionDays < 1) {
            $this->error('Retention window must be at least 1 day. Got: ' . $retentionDays);
            return self::FAILURE;
        }

        $cutoff = now()->subDays($retentionDays);
        $dryRun = (bool) $this->option('dry-run');

        // Count + oldest delivered_at for the audit payload + log.
        // COUNT(*) is fast on the delivered_at index (the index
        // created by the migration makes this an index-only scan
        // over the rows older than the cutoff).
        $countToDelete = WebhookDelivery::where('delivered_at', '<', $cutoff)->count();
        $oldestRow = WebhookDelivery::where('delivered_at', '<', $cutoff)
            ->orderBy('delivered_at')
            ->first();
        $oldestDeliveredAt = $oldestRow?->delivered_at?->toIso8601String();

        if ($dryRun) {
            $this->info(sprintf(
                'DRY RUN: would delete %d rows older than %s (retention: %d days).',
                $countToDelete,
                $cutoff->toIso8601String(),
                $retentionDays,
            ));
            return self::SUCCESS;
        }

        if ($countToDelete === 0) {
            $this->info('No rows older than the retention window — nothing to prune.');
            Log::info('PruneWebhookDeliveries: no rows older than retention window.', [
                'retention_days' => $retentionDays,
                'cutoff'         => $cutoff->toIso8601String(),
            ]);
            return self::SUCCESS;
        }

        // Delete the rows. The delivered_at index makes this a single
        // index range scan + delete (no table scan).
        $deleted = WebhookDelivery::where('delivered_at', '<', $cutoff)->delete();

        $this->info(sprintf(
            'Pruned %d webhook_deliveries rows older than %s (retention: %d days).',
            $deleted,
            $cutoff->toIso8601String(),
            $retentionDays,
        ));

        Log::info('PruneWebhookDeliveries: pruned rows.', [
            'rows_deleted'     => $deleted,
            'oldest_deleted'   => $oldestDeliveredAt,
            'retention_days'   => $retentionDays,
            'cutoff'           => $cutoff->toIso8601String(),
        ]);

        // Audit-log the prune. Target = the newest surviving row (a
        // real Model row proving the install has data). On a fresh
        // install where the prune emptied the table, skip the audit
        // row with Log::info (same convention as RunMonitoredBackup
        // — the absence of an audit row is explainable).
        try {
            $newestSurviving = WebhookDelivery::orderByDesc('delivered_at')
                ->orderByDesc('id')
                ->first();

            if ($newestSurviving !== null) {
                AdminAuditLog::record('webhook.deliveries_pruned', $newestSurviving, [
                    'rows_deleted'    => $deleted,
                    'oldest_deleted'  => $oldestDeliveredAt,
                    'retention_days'  => $retentionDays,
                    'cutoff'          => $cutoff->toIso8601String(),
                ]);
            } else {
                Log::info('PruneWebhookDeliveries: audit row skipped — no surviving rows to target.');
            }
        } catch (\Throwable $e) {
            // Audit-log failure must never break the prune path
            // (the rows are already deleted; the operator just
            // loses the audit attribution row — log + continue).
            Log::warning('PruneWebhookDeliveries: audit row skipped', ['error' => $e->getMessage()]);
        }

        return self::SUCCESS;
    }
}
