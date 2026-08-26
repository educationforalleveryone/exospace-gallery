<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\ProcessedWebhook;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        return view('super-admin.billing.index', compact('transactions', 'webhooks', 'stats', 'status'));
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
}
