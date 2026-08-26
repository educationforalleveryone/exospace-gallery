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

/**
 * ITERATION 4 — Billing Review (super-admin).
 *
 * The gap this closes: refunds, chargebacks and webhook-driven plan
 * changes had NO admin surface. The evidence lived in three disconnected
 * places (transactions.status flips, a PII-redacted log channel, and —
 * until this iteration — no stored webhook payload at all), so a support
 * conversation like "the customer says they refunded in March, why are
 * they still billed?" required grepping logs.
 *
 * This page joins the three sources of truth:
 *   - transactions (completed / refunded / partial_refund / chargeback)
 *   - the webhook ledger (what 2CO sent, when, and whether we handled it)
 *   - admin_audit_logs (webhook.* records written by the handlers since
 *     Iteration 4, plus manual admin actions like plan_changed)
 *
 * Replay: a stored webhook can be re-dispatched through the exact same
 * processing pipeline (WebhookController::processReplay) — guarded by
 * password.confirm, audited with the admin as actor, and safe because
 * every handler is idempotent (unique invoice_id + SELECT FOR UPDATE +
 * status guards + per-invoice locks).
 */
class BillingController extends Controller
{
    /**
     * ITERATION 6: the export column sets / row mappers moved into
     * BillingExportService so the weekly scheduled digest command
     * produces byte-identical CSVs from the same code path. The page +
     * streamed export behavior is unchanged.
     */
    private function exportService(): BillingExportService
    {
        return app(BillingExportService::class);
    }

    /**
     * Money-event statuses surfaced by default. 'completed' is excluded
     * from the default view (it's every purchase ever) but reachable via
     * the filter.
     */
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

        // 90-day money-event snapshot (small aggregate queries; the page is
        // super-admin + MFA gated and not hot).
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

        // ITERATION 7 — digest recipient management surface. The page
        // shows BOTH the UI-managed list AND the env fallback so an
        // operator is never surprised by which source is currently
        // effective. resolveRecipients() (in SendBillingExport) uses
        // the same precedence: DB list non-empty → DB; empty → env.
        $digestRecipients = Schema::hasTable('billing_digest_recipients')
            ? BillingDigestRecipient::with('addedBy')->orderBy('email')->get()
            : collect();
        $envDigestRecipients = $this->parseEnvRecipients();

        return view('super-admin.billing.index', compact('transactions', 'webhooks', 'stats', 'status', 'digestRecipients', 'envDigestRecipients'));
    }

    /**
     * ITERATION 5 — streamed CSV export of the billing review data.
     *
     * The gap: refunds/chargebacks/webhook-ledger data could only leave
     * the system via pagination and copy-paste — useless for finance
     * reconciliation ("give me every refund since March for the 2CO
     * statement match") or for attaching evidence to a dispute.
     *
     * Two exports, same filters as the page:
     *   ?export=transactions (default) — money events, ?status= filter
     *   ?export=webhooks             — the ledger, ?webhook_status= filter
     *   &days=90 (default, max 730)  — time window; days=all → everything
     *                                    (bounded by the 90-day webhook
     *                                    retention for the ledger)
     *
     * Streamed via cursor() + fputcsv so a full-history export never
     * loads the result set into memory. BOM prefix for Excel UTF-8.
     * Every export is audit-logged (billing.exported, actor = exporter)
     * — the CSV contains customer PII (email/name on transactions), so
     * data leaving the system must be attributable, same trust bar the
     * page itself sits behind (super-admin + MFA).
     */
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

            // BOM for Excel UTF-8 compatibility (same convention as the
            // user-facing GDPR export).
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, $headers);

            // cursor() keeps memory flat no matter how large the window is.
            foreach ($query->cursor() as $record) {
                fputcsv($out, $row($record));
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Replay a stored webhook through the live processing pipeline.
     * Route is password.confirm-gated (see routes/web.php).
     */
    public function replayWebhook(Request $request, int $webhook)
    {
        $row = ProcessedWebhook::find($webhook);
        if (! $row) {
            abort(404);
        }

        if (! $row->payload) {
            return back()->with('error', 'Webhook #' . $row->id . ' has no stored payload (pre-Iteration-4 row, or the payload was oversized) — replay is not possible. Use the 2Checkout merchant dashboard instead.');
        }

        // Normalize the payload shape: the array cast decodes model-read
        // rows, but rows written through DB::table (the webhook ingress)
        // may surface as pre-encoded strings depending on how the model was
        // hydrated. Both shapes mean "the stored IPN body".
        $payload = is_string($row->payload) ? json_decode($row->payload, true) : $row->payload;
        if (! is_array($payload)) {
            return back()->with('error', 'Webhook #' . $row->id . ' has a corrupted stored payload — replay is not possible.');
        }

        // The stored payload is already the signature-VERIFIED bytes from
        // the original ingress; replay deliberately skips re-verification
        // and dedupe (see WebhookController::processReplay docblock).
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

    // ── Digest recipients (ITERATION 7) ───────────────────────────────────
    //
    // The weekly billing digest emails a CSV of money events to every
    // recipient on this list. Managed here (not env-only) so changes
    // are attributable to an admin and survive across deploys without
    // touching Coolify env vars. Precedence: DB list non-empty → DB;
    // empty → BILLING_EXPORT_EMAIL env fallback.

    /**
     * Add a recipient. Validates, de-dupes (case-insensitive on
     * insert via the model mutator + the unique index), audit-logs.
     *
     * ITERATION 8: the explicit `where(...)->exists()` check is a
     * TOCTOU race window — two super-admins submitting the same
     * email in the same ~50ms both pass the exists check, the loser
     * throws a QueryException on the unique index that propagates as
     * a 500. The race is now caught: a UniqueConstraintViolationException
     * is re-routed to the same withErrors path (audit-fix B-2).
     */
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

        // ITERATION 8: catch the (rare) race where two super-admins
        // submit the same email within the TOCTOU window — the unique
        // index throws UniqueConstraintViolationException (Laravel 10+)
        // or QueryException; both are re-routed to the same friendly
        // error so the form doesn't blow up as a 500.
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
        }

        return back()->with('success', 'Added ' . $email . ' to the billing digest recipient list.');
    }

    /**
     * Remove a recipient. Audited BEFORE the delete so the audit row
     * captures the target row's id + email (scrubbed in payload as
     * PII, but target_id preserves the attribution). If the removal
     * empties the list, warn the operator about the env-fallback
     * state so a silent change to "nobody receives the digest" can't
     * happen by accident.
     */
    public function destroyRecipient(Request $request, BillingDigestRecipient $recipient)
    {
        // Audit before the delete — the target_id will then point at
        // a no-longer-existing row, which is fine for audit log
        // attribution (same pattern as a deleted webhook row).
        AdminAuditLog::record('billing.digest_recipient_removed', $recipient, [
            'recipients_remaining' => max(0, BillingDigestRecipient::count() - 1),
        ]);

        $email = $recipient->email;
        $recipient->delete();

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

    /**
     * Parse the BILLING_EXPORT_EMAIL env var the same way the
     * SendBillingExport command does — comma-separated, validated
     * and de-duped. Surfaces the fallback state in the UI.
     *
     * ITERATION 8: env recipients are now LOWERCASED (audit-fix A-5)
     * so the Billing Review page displays them in the same form the
     * UI-managed list does — an operator comparing the two columns
     * can't tell whether mixed-case `Finance@Example.com` is a
     * duplicate of UI-managed `finance@example.com` (it is, but the
     * case difference obscured it). The model mutator lowercases UI-
     * managed entries; this method now does the same for env entries
     * so the comparison is apples-to-apples.
     *
     * @return list<string>
     */
    private function parseEnvRecipients(): array
    {
        $raw = (string) (config('services.billing_export.email') ?? '');
        if (trim($raw) === '') {
            return [];
        }

        $out = [];
        foreach (explode(',', $raw) as $email) {
            // lowercase + trim BEFORE validation/dedupe so the
            // case-insensitive comparison below actually catches
            // case-different duplicates.
            $email = trim(strtolower($email));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && ! in_array($email, $out, true)) {
                $out[] = $email;
            }
        }

        return $out;
    }
}
