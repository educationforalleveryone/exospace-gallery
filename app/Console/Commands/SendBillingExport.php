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

            app(JobHeartbeatService::class)->stamp('exospace:send-billing-export');

            return self::SUCCESS;
        }

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
                'csv_rows'  => $csv['count'],
            ]);

            return self::FAILURE;
        }

        if ($failed > 0 && $delivered > 0) {
            $alerts->alert(
                'Weekly billing digest partial delivery failure',
                "The billing digest was delivered to {$delivered} recipient(s) but FAILED for {$failed} (of {$csv['count']} money-event rows prepared). Check MAIL_* configuration and the bouncing address(es). The heartbeat still stamps — the scheduler ran the job; this is a downstream delivery problem.",
                'warning',
                'billing_export_partial_delivery',
            );

            Log::warning('SendBillingExport: partial delivery failure', [
                'window'    => $window,
                'delivered' => $delivered,
                'failures'  => $failed,
                'csv_rows'   => $csv['count'],
            ]);
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

    private function resolveRecipients(): array
    {
        $raw = $this->option('to');

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
            $email = trim(strtolower($email));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && ! in_array($email, $recipients, true)) {
                $recipients[] = $email;
            }
        }

        return $recipients;
    }
}
