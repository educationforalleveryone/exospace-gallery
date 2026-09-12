<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\BillingDigestRecipient;
use App\Models\ProcessedWebhook;
use App\Models\Transaction;
use App\Services\BillingExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BillingController extends Controller
{
    private function exportService(): BillingExportService
    {
        return app(BillingExportService::class);
    }

    private const MONEY_STATUSES = ['refunded', 'partial_refund', 'chargeback'];

    public function index(Request $request)
    {
        $status = $request->query('status');

        $transactions = Transaction::query()
            ->with(['user', 'invoice'])
            ->when(
                in_array($status, [...self::MONEY_STATUSES, 'completed', 'manual'], true),
                fn ($q) => $q->where('status', $status),
                fn ($q) => $q->whereIn('status', self::MONEY_STATUSES),
            )
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        $webhooks = ProcessedWebhook::query()
            ->when(
                $request->query('webhook_status') === 'failed',
                fn ($q) => $q->where('status', 'failed'),
            )
            ->orderByDesc('id')
            ->paginate(20, ['*'], 'webhooks_page')
            ->withQueryString();

        $since = now()->subDays(90);
        $stats = [
            'refunds'        => Transaction::where('status', 'refunded')->where('created_at', '>=', $since)->count(),
            'partial'        => Transaction::where('status', 'partial_refund')->where('created_at', '>=', $since)->count(),
            'chargebacks'    => Transaction::where('status', 'chargeback')->where('created_at', '>=', $since)->count(),
            'failed_webhooks'=> ProcessedWebhook::where('status', 'failed')->count(),
            'replayed'       => ProcessedWebhook::where('replay_count', '>', 0)->count(),
            'revenue_90d'    => (float) Transaction::where('status', 'completed')
                ->where('created_at', '>=', $since)
                ->sum('amount'),
        ];

        $digestRecipients = Schema::hasTable('billing_digest_recipients')
            ? BillingDigestRecipient::with('addedBy')->orderBy('email')->get()
            : collect();
        $envDigestRecipients = $this->parseEnvRecipients();

        return view('super-admin.billing.index', compact('transactions', 'webhooks', 'stats', 'status', 'digestRecipients', 'envDigestRecipients'));
    }

    public function export(Request $request)
    {
        $type = (string) $request->query('export', 'transactions');
        if (! in_array($type, ['transactions', 'webhooks'], true)) {
            $type = 'transactions';
        }

        $days = (string) $request->query('days', '90');
        $since = $days === 'all'
            ? null
            : now()->subDays(max(1, min(730, (int) $days)));

        $status = $request->query('status');
        $webhookStatus = $request->query('webhook_status');

        if ($type === 'webhooks') {
            $query = $this->exportService()->webhooksQuery(
                $webhookStatus === 'failed' ? 'failed' : null,
                $since,
            );

            $columns = $this->exportService()->webhooksColumns();
            $headers = $columns['headers'];
            $row = $columns['row'];
        } else {
            $query = $this->exportService()->transactionsQuery(
                in_array($status, [...self::MONEY_STATUSES, 'completed', 'manual'], true) ? $status : null,
                $since,
            );

            $columns = $this->exportService()->transactionsColumns();
            $headers = $columns['headers'];
            $row = $columns['row'];
        }

        $count = (clone $query)->count();

        AdminAuditLog::record('billing.exported', $request->user(), [
            'export_type' => $type,
            'status'      => $type === 'webhooks' ? $webhookStatus : $status,
            'days'        => $days,
            'row_count'   => $count,
        ]);

        $filename = 'exospace-' . $type . '-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($query, $headers, $row) {
            $out = fopen('php://output', 'w');

            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, $headers);

            // cursor() keeps memory flat no matter how large the window is.
            foreach ($query->cursor() as $record) {
                fputcsv($out, $row($record));
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function replayWebhook(Request $request, int $webhook)
    {
        $row = ProcessedWebhook::find($webhook);
        if (! $row) {
            abort(404);
        }

        if (! $row->payload) {
            return back()->with('error', 'Webhook #' . $row->id . ' has no stored payload (pre-Iteration-4 row, or the payload was oversized) — replay is not possible. Use the 2Checkout merchant dashboard instead.');
        }

        $payload = is_string($row->payload) ? json_decode($row->payload, true) : $row->payload;
        if (! is_array($payload)) {
            return back()->with('error', 'Webhook #' . $row->id . ' has a corrupted stored payload — replay is not possible.');
        }

        $synthetic = \Illuminate\Http\Request::create('/webhooks/2checkout', 'POST', $payload);

        try {
            $response = app(\App\Http\Controllers\WebhookController::class)->processReplay($synthetic);
            $ok = $response->status() < 500;
            $httpStatus = $response->status();
        } catch (\Throwable $e) {
            $ok = false;
            $httpStatus = 500;
            \Illuminate\Support\Facades\Log::error('BillingController: webhook replay threw', [
                'webhook_id' => $row->id,
                'error'      => $e->getMessage(),
            ]);
        }

        $row->update([
            'status'          => $ok ? 'processed' : 'failed',
            'replay_count'    => ($row->replay_count ?? 0) + 1,
            'last_replayed_at'=> now(),
            'updated_at'      => now(),
        ]);

        AdminAuditLog::record('webhook.replayed', $row, [
            'message_type'   => $row->message_type,
            'invoice_id'     => $row->invoice_id,
            'outcome'        => $ok ? 'processed' : 'failed',
            'http_status'    => $httpStatus,
            'replay_number'  => $row->replay_count,
        ]);

        return back()->with(
            $ok ? 'success' : 'error',
            $ok
                ? 'Replayed ' . $row->message_type . ' (webhook #' . $row->id . ') — pipeline returned ' . $httpStatus . '.'
                : 'Replay of ' . $row->message_type . ' (webhook #' . $row->id . ') returned ' . $httpStatus . ' — the ledger row stays marked failed; check the logs.'
        );
    }

    public function storeRecipient(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $email = trim(strtolower($data['email']));

        $exists = Schema::hasTable('billing_digest_recipients')
            && BillingDigestRecipient::where('email', $email)->exists();

        if ($exists) {
            return back()
                ->withInput()
                ->withErrors(['email' => '"' . $email . '" is already a recipient.']);
        }

        $recipient = null;
        try {
            $recipient = Schema::hasTable('billing_digest_recipients')
                ? BillingDigestRecipient::create([
                    'email'   => $email,
                    'added_by' => $request->user()->id,
                ])
                : null;
        } catch (\Illuminate\Database\UniqueConstraintViolationException | \Illuminate\Database\QueryException $e) {
            return back()
                ->withInput()
                ->withErrors(['email' => '"' . $email . '" is already a recipient (concurrent add detected).']);
        }

        if ($recipient !== null) {
            AdminAuditLog::record('billing.digest_recipient_added', $recipient, [
                'recipients_total' => BillingDigestRecipient::count(),
            ]);

            \App\Services\OutboundWebhookService::dispatch(
                'billing.recipient_added',
                [
                    'recipient_email'   => $recipient->email,
                    'actor_admin_id'    => $request->user()->id,
                    'actor_admin_email' => $request->user()->email,
                    'recipients_total'  => BillingDigestRecipient::count(),
                ],
            );
        }

        return back()->with('success', 'Added ' . $email . ' to the billing digest recipient list.');
    }

    public function destroyRecipient(Request $request, BillingDigestRecipient $recipient)
    {
        AdminAuditLog::record('billing.digest_recipient_removed', $recipient, [
            'recipients_remaining' => max(0, BillingDigestRecipient::count() - 1),
        ]);

        $email = $recipient->email;
        $remainingAfter = max(0, BillingDigestRecipient::count() - 1);
        $recipient->delete();

        \App\Services\OutboundWebhookService::dispatch(
            'billing.recipient_removed',
            [
                'recipient_email'    => $email,
                'actor_admin_id'     => $request->user()->id,
                'actor_admin_email'  => $request->user()->email,
                'recipients_remaining'=> $remainingAfter,
            ],
        );

        $remaining = BillingDigestRecipient::count();
        $envHasAny = $this->parseEnvRecipients() !== [];

        if ($remaining === 0 && ! $envHasAny) {
            return back()->with('warning', 'Removed ' . $email . ' — the recipient list is now empty and no BILLING_EXPORT_EMAIL fallback is configured. The weekly billing digest is effectively disabled until a recipient is re-added.');
        }

        if ($remaining === 0) {
            return back()->with('warning', 'Removed ' . $email . ' — the UI-managed recipient list is now empty. The digest will fall back to BILLING_EXPORT_EMAIL until new recipients are added here.');
        }

        return back()->with('success', 'Removed ' . $email . ' from the billing digest recipient list.');
    }

    private function parseEnvRecipients(): array
    {
        $raw = (string) (config('services.billing_export.email') ?? '');
        if (trim($raw) === '') {
            return [];
        }

        $out = [];
        foreach (explode(',', $raw) as $email) {
            $email = trim(strtolower($email));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && ! in_array($email, $out, true)) {
                $out[] = $email;
            }
        }

        return $out;
    }
}
