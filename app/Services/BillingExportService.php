<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ProcessedWebhook;
use App\Models\Transaction;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

class BillingExportService
{
    public const MONEY_STATUSES = ['refunded', 'partial_refund', 'chargeback'];

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

    public function transactionsCsv(?string $status, ?CarbonInterface $since = null): array
    {
        return $this->buildCsv(
            $this->transactionsQuery($status, $since),
            $this->transactionsColumns(),
            'transactions',
        );
    }

    public function webhooksCsv(?string $webhookStatus, ?CarbonInterface $since = null): array
    {
        return $this->buildCsv(
            $this->webhooksQuery($webhookStatus, $since),
            $this->webhooksColumns(),
            'webhooks',
        );
    }

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

    private function buildCsv(Builder $query, array $columns, string $type): array
    {
        $count = (clone $query)->count();

        $out = fopen('php://temp/maxmemory:' . (16 * 1024 * 1024), 'r+');

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
