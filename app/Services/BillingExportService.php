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
        $statuses = in_array($status, [...self::MONEY_STATUSES, 'completed', 'manual'], true)
            ? [$status]
            : self::MONEY_STATUSES;

        // A refund or chargeback mutates the original sale row, so its event
        // time is updated_at; created_at is the sale date. Sales use created_at.
        return Transaction::query()
            ->with('user:id,email')
            ->where(function ($q) use ($statuses, $since) {
                foreach ($statuses as $s) {
                    $q->orWhere(function ($inner) use ($s, $since) {
                        $inner->where('status', $s);
                        if ($since) {
                            $inner->where($this->eventColumn($s), '>=', $since);
                        }
                    });
                }
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    private function eventColumn(string $status): string
    {
        return in_array($status, self::MONEY_STATUSES, true) ? 'updated_at' : 'created_at';
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
                self::safeCell($t->invoice_id),
                self::safeCell($t->sale_id),
                $t->user_id,
                self::safeCell($t->user?->email),
                self::safeCell($t->customer_name),
                self::safeCell($t->customer_email),
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
                self::safeCell($w->message_id),
                self::safeCell($w->message_type),
                self::safeCell($w->invoice_id),
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

    /**
     * Prefix values a spreadsheet would evaluate as a formula (CSV injection).
     */
    public static function safeCell(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && str_contains("=+-@\t\r", $value[0])) {
            return "'".$value;
        }

        return $value;
    }

    public function summary(CarbonInterface $since): array
    {
        $count = fn (string $status) => Transaction::where('status', $status)
            ->where($this->eventColumn($status), '>=', $since)
            ->count();

        return [
            'completed' => $count('completed'),
            'refunded' => $count('refunded'),
            'partial_refund' => $count('partial_refund'),
            'chargeback' => $count('chargeback'),
            'manual' => $count('manual'),
            'revenue' => (float) Transaction::where('status', 'completed')->where('created_at', '>=', $since)->sum('amount'),
            'failed_webhooks' => ProcessedWebhook::where('status', 'failed')->count(),
        ];
    }

    /**
     * Chunked iteration: flat memory and, unlike cursor(), honours eager loads.
     */
    public function records(Builder $query): \Illuminate\Support\LazyCollection
    {
        return $query->lazy(500);
    }

    private function buildCsv(Builder $query, array $columns, string $type): array
    {
        $count = (clone $query)->count();

        $out = fopen('php://temp/maxmemory:'.(16 * 1024 * 1024), 'r+');

        fwrite($out, "\xEF\xBB\xBF");

        fputcsv($out, $columns['headers']);

        $row = $columns['row'];
        foreach ($this->records($query) as $record) {
            fputcsv($out, $row($record));
        }

        $content = (string) stream_get_contents($out, offset: 0);
        fclose($out);

        return [
            'filename' => 'exospace-'.$type.'-'.now()->format('Ymd-His').'.csv',
            'content' => $content,
            'count' => $count,
        ];
    }
}
