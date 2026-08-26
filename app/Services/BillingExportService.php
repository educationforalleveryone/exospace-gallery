<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ProcessedWebhook;
use App\Models\Transaction;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * ITERATION 6 — billing CSV generation, single source of truth.
 *
 * Iteration 5 gave Billing Review an on-demand streamed export. This
 * service extracts the query construction, column sets and row mappers
 * so the NEW weekly billing digest command (exospace:send-billing-export)
 * produces byte-identical CSVs from the same code path — two copies of
 * the finance-reconciliation column list would inevitably drift, and a
 * finance match silently breaking because someone edited one of them is
 * exactly the kind of trust damage this iteration exists to prevent.
 *
 * The controller still streams via cursor() + fputcsv (flat memory for
 * full-history exports); the command builds the whole string because an
 * email attachment needs the bytes — bounded by a 7-day window there.
 *
 * PII note: the transactions CSV contains customer email/name. On-demand
 * exports are audit-logged (billing.exported) with the admin actor; the
 * scheduled digest is audit-logged with actor=null (system) and is gated
 * behind explicit BILLING_EXPORT_EMAIL configuration — an operator
 * opting in is the consent boundary. Documented in the operations manual.
 */
class BillingExportService
{
    /**
     * Money-event statuses surfaced by default (same set the Billing
     * Review page defaults to). 'completed' reachable via explicit filter.
     */
    public const MONEY_STATUSES = ['refunded', 'partial_refund', 'chargeback'];

    // ── Query builders (shared) ───────────────────────────────────────

    public function transactionsQuery(?string $status, ?CarbonInterface $since = null): Builder
    {
        return Transaction::query()
            ->when(
                in_array($status, [...self::MONEY_STATUSES, 'completed', 'manual'], true),
                fn ($q) => $q->where('status', $status),
                fn ($q) => $q->whereIn('status', self::MONEY_STATUSES),
            )
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->orderByDesc('created_at');
    }

    public function webhooksQuery(?string $webhookStatus, ?CarbonInterface $since = null): Builder
    {
        return ProcessedWebhook::query()
            ->when(
                $webhookStatus === 'failed',
                fn ($q) => $q->where('status', 'failed'),
            )
            ->when($since, fn ($q) => $q->where('updated_at', '>=', $since))
            ->orderByDesc('id');
    }

    // ── Column sets + row mappers (shared) ────────────────────────────

    /**
     * @return array{headers: list<string>, row: callable(Transaction): list<mixed>}
     */
    public function transactionsColumns(): array
    {
        return [
            'headers' => ['ID', 'Date', 'Status', 'Plan', 'Amount', 'Currency',
                          'Invoice ID', 'Sale ID', 'User ID', 'User Email',
                          'Customer Name', 'Customer Email'],
            'row' => fn (Transaction $t) => [
                $t->id,
                $t->created_at?->format('Y-m-d H:i:s'),
                $t->status,
                $t->plan,
                $t->amount,
                $t->currency,
                $t->invoice_id,
                $t->sale_id,
                $t->user_id,
                $t->user?->email,
                $t->customer_name,
                $t->customer_email,
            ],
        ];
    }

    /**
     * Ledger CSV reports payload presence, never raw payloads.
     *
     * @return array{headers: list<string>, row: callable(ProcessedWebhook): list<mixed>}
     */
    public function webhooksColumns(): array
    {
        return [
            'headers' => ['ID', 'Message ID', 'Message Type', 'Invoice ID', 'Status',
                          'Replay Count', 'Last Replayed At', 'Processed At', 'Updated At', 'Payload Stored'],
            'row' => fn (ProcessedWebhook $w) => [
                $w->id,
                $w->message_id,
                $w->message_type,
                $w->invoice_id,
                $w->status,
                (int) ($w->replay_count ?? 0),
                $w->last_replayed_at?->format('Y-m-d H:i:s'),
                $w->processed_at?->format('Y-m-d H:i:s'),
                $w->updated_at?->format('Y-m-d H:i:s'),
                $w->payload ? 'yes' : 'no',
            ],
        ];
    }

    // ── Whole-file builders (command / attachment path) ───────────────

    /**
     * Full CSV content for the transactions export (BOM + rows). Uses a
     * php://temp stream so arbitrarily large windows never balloon memory
     * before the string is assembled.
     *
     * @return array{filename: string, content: string, count: int}
     */
    public function transactionsCsv(?string $status, ?CarbonInterface $since = null): array
    {
        return $this->buildCsv(
            $this->transactionsQuery($status, $since),
            $this->transactionsColumns(),
            'transactions',
        );
    }

    /**
     * @return array{filename: string, content: string, count: int}
     */
    public function webhooksCsv(?string $webhookStatus, ?CarbonInterface $since = null): array
    {
        return $this->buildCsv(
            $this->webhooksQuery($webhookStatus, $since),
            $this->webhooksColumns(),
            'webhooks',
        );
    }

    /**
     * Digest summary for a window: per-status transaction counts + the
     * completed revenue total + failed-webhook count. Powers the email
     * body and the Slack fallback summary.
     *
     * @return array{completed: int, refunded: int, partial_refund: int, chargeback: int, manual: int, revenue: float, failed_webhooks: int}
     */
    public function summary(CarbonInterface $since): array
    {
        return [
            'completed'      => Transaction::where('status', 'completed')->where('created_at', '>=', $since)->count(),
            'refunded'       => Transaction::where('status', 'refunded')->where('created_at', '>=', $since)->count(),
            'partial_refund' => Transaction::where('status', 'partial_refund')->where('created_at', '>=', $since)->count(),
            'chargeback'     => Transaction::where('status', 'chargeback')->where('created_at', '>=', $since)->count(),
            'manual'         => Transaction::where('status', 'manual')->where('created_at', '>=', $since)->count(),
            'revenue'        => (float) Transaction::where('status', 'completed')->where('created_at', '>=', $since)->sum('amount'),
            'failed_webhooks'=> ProcessedWebhook::where('status', 'failed')->count(),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param  Builder  $query
     * @param  array{headers: list<string>, row: callable(object): list<mixed>}  $columns
     * @return array{filename: string, content: string, count: int}
     */
    private function buildCsv(Builder $query, array $columns, string $type): array
    {
        $count = (clone $query)->count();

        $out = fopen('php://temp/maxmemory:' . (16 * 1024 * 1024), 'r+');

        // BOM for Excel UTF-8 compatibility (same convention as the
        // user-facing GDPR export and the Iteration-5 streamed export).
        fwrite($out, "\xEF\xBB\xBF");

        fputcsv($out, $columns['headers']);

        $row = $columns['row'];
        foreach ($query->cursor() as $record) {
            fputcsv($out, $row($record));
        }

        $content = (string) stream_get_contents($out, offset: 0);
        fclose($out);

        return [
            'filename' => 'exospace-' . $type . '-' . now()->format('Ymd-His') . '.csv',
            'content'  => $content,
            'count'    => $count,
        ];
    }
}
