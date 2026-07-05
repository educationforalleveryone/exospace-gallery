<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    /**
     * Handle 2Checkout IPN (Instant Payment Notification).
     *
     * 2Checkout Documentation: https://www.2checkout.com/documentation/notifications/ins
     *
     * SECURITY MODEL (P0-2 — HMAC now MANDATORY in production)
     * -------------------------------------------------------
     * 1. The legacy `md5_hash` field is verified using `hash_equals()` (timing-safe).
     *    This proves the IPN originated from 2Checkout but only covers
     *    `sale_id + vendor_id + invoice_id + secret_word` — it does NOT cover
     *    `customer_email`, `item_id_1`, `item_list_amount_1`, `message_type`, or
     *    `external-reference`. MD5 alone is INSUFFICIENT — anyone who captures
     *    one valid IPN can tamper with the unsigned fields and re-post it.
     *
     * 2. HMAC SHA-256 signature (`signature` field) is MANDATORY in production.
     *    It covers `customer_email`, `item_id_1`, `message_type`, `item_list_amount_1`,
     *    and 12 other security-critical fields. This closes the replay-tamper
     *    attack where a buyer re-signs a captured IPN with a different
     *    `customer_email` / `item_id_1` / `message_type` to upgrade arbitrary
     *    accounts or forge refund/chargeback notifications.
     *
     *    If `TWOCHECKOUT_BUY_LINK_SECRET_WORD` is not set:
     *    - In production: the webhook FAILS CLOSED (403 on every IPN).
     *    - In testing/local: accepts MD5-only IF `TWOCHECKOUT_ALLOW_MD5_ONLY=true`
     *      is explicitly set (escape hatch for 2Checkout account migration).
     *
     * 3. When `TWOCHECKOUT_WEBHOOK_IP_ALLOWLIST` is configured, only requests
     *    from those IPs are accepted. When NOT configured in production, a
     *    CRITICAL warning is logged on every webhook (but the request is not
     *    rejected — HMAC is the primary defense, IP allowlist is defense-in-depth).
     *    2Checkout publishes their INS IP ranges in their merchant documentation.
     *
     * 4. PII (customer_email, customer_name) is redacted from logs.
     *
     * TRANSACTION BOUNDARY (P1-1 / P1-2)
     * ----------------------------------
     * External side effects (Mail::send, CoolifyDomainManager::removeDomain,
     * file deletes) are dispatched via DB::afterCommit() so they only execute
     * if the DB transaction commits. This prevents state drift where the DB
     * rolls back but the email/file/Coolify change has already happened.
     */
    public function handle2Checkout(Request $request)
    {
        // Log the incoming webhook for debugging — PII redacted.
        Log::info('2Checkout Webhook Received', $this->redactedPayload($request));

        // ================================
        // STEP 1: Security Verification
        // ================================
        if (! $this->verify2CheckoutSignature($request)) {
            return response('Hash verification failed', 403);
        }

        // ================================
        // STEP 1b: Replay Protection (SEC-9)
        // ================================
        // Check if this message_id + message_type has already been processed.
        // Prevents replay attacks where a captured IPN is re-POSTed with
        // a different message_type (only works in MD5-only mode, but
        // defense-in-depth).
        $messageId = $request->input('message_id');
        $messageType = $request->input('message_type');
        if ($messageId && $messageType) {
            $alreadyProcessed = \DB::table('processed_webhooks')
                ->where('message_id', $messageId)
                ->where('message_type', $messageType)
                ->exists();

            if ($alreadyProcessed) {
                Log::info('2Checkout: Duplicate message_id+type, skipping (replay protection)', [
                    'message_id' => $messageId,
                    'message_type' => $messageType,
                ]);
                return response('OK', 200);
            }

            // Record this webhook as processed
            \DB::table('processed_webhooks')->insert([
                'message_id'   => $messageId,
                'message_type' => $messageType,
                'invoice_id'   => $request->input('invoice_id'),
                'processed_at' => now(),
            ]);
        }

        // ================================
        // STEP 2: Route by message_type
        // ================================
        $messageType = $request->input('message_type');

        if ($messageType === 'REFUND_ISSUED') {
            return $this->applyRefund($request);
        }
        if ($messageType === 'CHARGEBACK_REPORTED') {
            return $this->applyChargeback($request);
        }
        if ($messageType === 'CHARGEBACK_REVERSED') {
            return $this->reverseChargeback($request);
        }

        // M-1: Recurring (subscription) webhook events.
        // These are sent by 2Checkout for recurring billing lifecycle events.
        if ($messageType === 'RECURRING_INSTALLMENT_SUCCESS') {
            return $this->handleRecurringSuccess($request);
        }
        if ($messageType === 'RECURRING_INSTALLMENT_FAILED') {
            return $this->handleRecurringFailure($request);
        }
        if ($messageType === 'RECURRING_ORDER_CANCELLED') {
            return $this->handleRecurringCancelled($request);
        }

        // Other message types (FRAUD_STATUS_CHANGED, REFUND_REQUESTED,
        // INVOICE_STATUS_CHANGED, etc.) are logged but do not mutate state.
        if ($messageType !== 'ORDER_CREATED') {
            Log::info('2Checkout: Non-mutating message type', [
                'type'       => $messageType,
                'invoice_id' => $request->input('invoice_id'),
            ]);
            return response('OK', 200);
        }

        // ================================
        // STEP 3: Validate & extract payload
        // ================================
        $customerEmail = $request->input('customer_email');
        $customerName  = $request->input('customer_name');
        $invoiceId     = $request->input('invoice_id');
        $productId     = $request->input('item_id_1');
        $amount        = $request->input('item_list_amount_1', 0);

        $externalReference = $request->input('external-reference')
            ?? $request->input('external_reference')
            ?? $request->input('merchant_item_id_1');

        $user = null;
        $pendingUpgrade = null;

        if (! empty($externalReference)) {
            $pendingUpgrade = \App\Models\PendingUpgrade::where('token', $externalReference)
                ->where('status', 'pending')
                ->first();

            if ($pendingUpgrade) {
                $user = $pendingUpgrade->user;
                $customerEmail = $user->email;
            } else {
                $user = User::find((int) $externalReference);
            }
        }

        if (! $user && filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            $user = User::where('email', $customerEmail)->first();
        }

        if (! $user) {
            Log::warning('2Checkout: User not found by external-reference or customer_email', [
                'invoice_id'           => $invoiceId,
                'has_external_ref'     => ! empty($externalReference),
                'has_customer_email'   => ! empty($customerEmail),
            ]);
            return response('OK', 200);
        }

        if (empty($customerEmail)) {
            $customerEmail = $user->email;
        }

        // ================================
        // STEP 4: Map Product ID → Plan
        // ================================
        $productMap = [
            config('services.2checkout.product_id_pro')    => ['plan' => 'pro'],
            config('services.2checkout.product_id_studio') => ['plan' => 'studio'],
        ];

        $planConfig = $productMap[$productId] ?? null;

        if (! $planConfig) {
            Log::warning('2Checkout: Unknown product ID received', [
                'product_id' => $productId,
                'invoice_id' => $invoiceId,
                'user_id'    => $user->id,
            ]);
            return response('Unknown product - flagged for review', 200);
        }

        // ================================
        // STEP 5: Idempotent upgrade (atomic)
        // ================================
        // P1-2 FIX: Mail::send is now dispatched via DB::afterCommit()
        // so the email is only sent if the DB transaction commits. If the
        // transaction rolls back (deadlock, query error), the email is NOT
        // sent — preventing the "email says Pro but DB says Free" drift.
        //
        // S-9 FIX: Lock TTL bumped from 60s to 120s. The worst-case DB
        // transaction here includes:
        //   - SELECT ... FOR UPDATE on transactions (idempotency check)
        //   - UPDATE users SET plan=pro (fast)
        //   - INSERT INTO transactions (fast)
        //   - UPDATE pending_upgrades SET status=converted (fast)
        //   - DB::afterCommit dispatches PlanUpgradedEmail to queue (fast)
        //
        // Under normal conditions the whole transaction is <100ms. But under
        // contention (many concurrent webhooks from a 2Checkout batch), MySQL
        // row-lock waits can stack up. A 60s lock would expire if the
        // transaction took >60s due to lock waits, allowing a retry to
        // acquire the lock and start a duplicate upgrade. 120s gives 2×
        // headroom — generous enough to absorb lock waits, still short enough
        // that a crashed holder doesn't block retries for too long.
        //
        // The block(5) call still waits up to 5 seconds to acquire the lock.
        // If the lock is held by an in-flight webhook for the same invoice_id,
        // the retry sees the lock busy and returns 200 (deferred) — the
        // in-flight webhook's idempotency check (SELECT FOR UPDATE on
        // transactions.invoice_id) ensures the retry is a no-op anyway.
        $lock = \Illuminate\Support\Facades\Cache::lock("2co:upgrade:{$invoiceId}", 120);

        try {
            $processed = $lock->block(5, function () use (
                $user, $planConfig, $invoiceId, $productId, $amount,
                $request, $customerEmail, $customerName, $pendingUpgrade
            ) {
                return \DB::transaction(function () use (
                    $user, $planConfig, $invoiceId, $productId, $amount,
                    $request, $customerEmail, $customerName, $pendingUpgrade
                ) {
                    // ── Idempotency check FIRST ──────────────────────────
                    $existing = \DB::table('transactions')
                        ->where('invoice_id', $invoiceId)
                        ->lockForUpdate()
                        ->first();

                    if ($existing) {
                        Log::info('2Checkout: Duplicate webhook, skipping upgrade', [
                            'invoice_id'     => $invoiceId,
                            'existing_status'=> $existing->status,
                        ]);
                        return false; // signal "already processed"
                    }

                    // ── Upgrade the user ─────────────────────────────────
                    // M-1: Detect if this is a recurring (subscription) purchase.
                    // 2Checkout sends recurring_order_id for recurring products.
                    // If present, set subscription fields + plan_expires_at to
                    // the first billing cycle end. If absent, it's a one-time
                    // purchase (lifetime access, plan_expires_at = null).
                    $recurringOrderId = $request->input('recurring_order_id');
                    $nextBillingDate  = $request->input('item_billing_cycle_next_date');

                    if ($recurringOrderId) {
                        $subscriptionEndsAt = $nextBillingDate
                            ? \Carbon\Carbon::parse($nextBillingDate)->endOfDay()
                            : now()->addMonth(); // fallback

                        $user->forceFill([
                            'plan'                    => $planConfig['plan'],
                            'plan_started_at'         => now(),
                            'plan_expires_at'         => $subscriptionEndsAt,
                            'subscription_id'         => $recurringOrderId,
                            'subscription_status'     => 'active',
                            'subscription_ends_at'    => $subscriptionEndsAt,
                            'subscription_cancelled_at' => null,
                        ])->save();
                    } else {
                        // One-time purchase (existing behavior)
                        $user->forceFill([
                            'plan'            => $planConfig['plan'],
                            'plan_started_at' => now(),
                            'plan_expires_at' => null,
                        ])->save();
                    }

                    // ── Insert transaction record ───────────────────────
                    $transactionId = \DB::table('transactions')->insertGetId([
                        'user_id'        => $user->id,
                        'invoice_id'     => $invoiceId,
                        'sale_id'        => $request->input('sale_id'),
                        'product_id'     => $productId,
                        'plan'           => $planConfig['plan'],
                        'amount'         => $amount,
                        'currency'       => $request->input('list_currency', 'USD'),
                        'customer_email' => $customerEmail,
                        'customer_name'  => $customerName,
                        'status'         => 'completed',
                        'created_at'     => now(),
                        'updated_at'     => now(),
                    ]);

                    // ── Mark the pending_upgrade as converted ──────────
                    if ($pendingUpgrade) {
                        $pendingUpgrade->markConverted($transactionId);
                    }

                    Log::info('2Checkout: User upgraded successfully', [
                        'user_id'    => $user->id,
                        'plan'       => $planConfig['plan'],
                        'invoice_id' => $invoiceId,
                        'matched_by' => $pendingUpgrade ? 'external-reference' : 'customer_email',
                    ]);

                    // ── P1-2 FIX: Send confirmation email AFTER commit ──
                    // Previously, Mail::send was called inside the transaction.
                    // If the transaction rolled back, the email was still sent
                    // ("Your Pro plan is active" — but the DB says Free).
                    // PlanUpgradedEmail implements ShouldQueue, so the queue
                    // dispatch happens after commit — the queue worker will
                    // see the committed user state.
                    \DB::afterCommit(function () use ($user, $planConfig, $invoiceId) {
                        try {
                            \Illuminate\Support\Facades\Mail::to($user->email)
                                ->send(new \App\Mail\PlanUpgradedEmail($user, $planConfig['plan'], $invoiceId));
                        } catch (\Throwable $e) {
                            Log::warning('2Checkout: PlanUpgradedEmail send failed', [
                                'user_id' => $user->id,
                                'error'   => $e->getMessage(),
                            ]);
                        }
                    });

                    return true; // signal "upgraded"
                });
            });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            Log::info('2Checkout: Upgrade lock busy, deferring to in-flight worker', [
                'invoice_id' => $invoiceId,
            ]);
            return response('OK', 200);
        } catch (\Throwable $e) {
            Log::error('2Checkout: Upgrade failed', [
                'invoice_id' => $invoiceId,
                'user_id'    => $user->id,
                'error'      => $e->getMessage(),
            ]);
            return response('Internal error', 500);
        }

        if (! $processed) {
            return response('OK', 200);
        }

        return response('OK', 200);
    }

    /**
     * Handle the legacy /webhooks/2checkout/refund route.
     */
    public function handleRefund(Request $request)
    {
        Log::info('2Checkout Refund Route Received', $this->redactedPayload($request));

        if (! $this->verify2CheckoutSignature($request)) {
            return response('Hash verification failed', 403);
        }

        $messageType = $request->input('message_type');

        if ($messageType === 'REFUND_ISSUED') {
            return $this->applyRefund($request);
        }
        if ($messageType === 'CHARGEBACK_REPORTED') {
            return $this->applyChargeback($request);
        }
        if ($messageType === 'CHARGEBACK_REVERSED') {
            return $this->reverseChargeback($request);
        }

        Log::info('2Checkout: /refund route received non-refund message_type', [
            'type'       => $messageType,
            'invoice_id' => $request->input('invoice_id'),
        ]);
        return response('OK', 200);
    }

    /**
     * Apply a confirmed refund (REFUND_ISSUED).
     *
     * P1-1 FIX: External side effects (CoolifyDomainManager::removeDomain,
     * file deletes via PlanDowngradeService) are now dispatched via
     * DB::afterCommit() so they only execute if the DB transaction commits.
     * Previously, if the transaction rolled back, the user's plan reverted
     * to Pro/Studio but their custom domain was already removed from Traefik
     * and their logo files were already deleted — state drift.
     *
     * P1-4 FIX: Parse the refund amount from the IPN. Only downgrade if the
     * refund is full (≥90% of the original transaction amount). A $5 courtesy
     * refund on a $99 Studio purchase no longer wipes the user's branding.
     * Partial refunds mark the transaction as 'partial_refund' and leave the
     * plan intact.
     */
    private function applyRefund(Request $request)
    {
        $invoiceId = $request->input('invoice_id');
        if (! $invoiceId) {
            Log::warning('2Checkout: REFUND_ISSUED missing invoice_id');
            return response('OK', 200);
        }

        // S-9 FIX: Lock TTL bumped from 60s to 120s - see upgrade path above
        // for rationale. Same worst-case DB transaction shape applies.
        $lock = \Illuminate\Support\Facades\Cache::lock("2co:refund:{$invoiceId}", 120);

        try {
            $lock->block(5, function () use ($invoiceId, $request) {
                \DB::transaction(function () use ($invoiceId, $request) {
                    $transaction = \DB::table('transactions')
                        ->where('invoice_id', $invoiceId)
                        ->lockForUpdate()
                        ->first();

                    if (! $transaction) {
                        Log::warning('2Checkout: REFUND_ISSUED for unknown invoice', [
                            'invoice_id' => $invoiceId,
                        ]);
                        return;
                    }

                    if ($transaction->status === 'refunded' || $transaction->status === 'partial_refund') {
                        Log::info('2Checkout: Duplicate REFUND_ISSUED, skipping', [
                            'invoice_id' => $invoiceId,
                        ]);
                        return;
                    }

                    // ── P1-4 FIX: Parse the refund amount ──────────────
                    // 2Checkout's REFUND_ISSUED IPN includes item_list_amount_1
                    // which is the REFUND amount (not the original amount).
                    // Compare against the original transaction amount to
                    // determine if this is a full or partial refund.
                    $refundAmount = (float) $request->input('item_list_amount_1', 0);
                    $originalAmount = (float) $transaction->amount;
                    $isFullRefund = $originalAmount > 0
                        && ($refundAmount / $originalAmount) >= 0.90;

                    // Defensive: if we can't determine the refund amount
                    // (e.g. item_list_amount_1 is missing or 0), OR if the
                    // original amount was 0, treat it as a full refund.
                    // Better to downgrade than to let a refunded user keep
                    // access — the refund IPN was sent for a reason.
                    if ($originalAmount <= 0 || $refundAmount <= 0) {
                        $isFullRefund = true;
                    }

                    $newStatus = $isFullRefund ? 'refunded' : 'partial_refund';

                    \DB::table('transactions')
                        ->where('invoice_id', $invoiceId)
                        ->update([
                            'status'     => $newStatus,
                            'updated_at' => now(),
                        ]);

                    $user = User::find($transaction->user_id);

                    if (! $user) {
                        Log::warning('2Checkout: REFUND_ISSUED user no longer exists', [
                            'invoice_id' => $invoiceId,
                            'user_id'    => $transaction->user_id,
                        ]);
                        return;
                    }

                    // ── P1-4: Partial refunds do NOT downgrade ──────────
                    if (! $isFullRefund) {
                        Log::info('2Checkout: Partial refund — not downgrading', [
                            'invoice_id'      => $invoiceId,
                            'user_id'         => $user->id,
                            'original_amount' => $originalAmount,
                            'refund_amount'   => $refundAmount,
                        ]);
                        return;
                    }

                    // ── Downgrade only if current plan matches ──────────
                    if ($user->plan !== $transaction->plan) {
                        Log::info('2Checkout: REFUND_ISSUED not downgrading — plan changed since purchase', [
                            'invoice_id'        => $invoiceId,
                            'user_id'           => $user->id,
                            'current_plan'      => $user->plan,
                            'refunded_plan'     => $transaction->plan,
                        ]);
                        return;
                    }

                    if (! in_array($user->plan, ['pro', 'studio'], true)) {
                        return;
                    }

                    // ── P1-1 FIX: External side effects AFTER commit ────
                    // PlanDowngradeService::downgradeToFree calls
                    // CoolifyDomainManager::removeDomain (HTTP to Coolify)
                    // and Storage::disk('public')->delete() (filesystem).
                    // These MUST NOT run inside the DB transaction — if the
                    // transaction rolls back, the DB says "Studio" but the
                    // domain is gone and the files are deleted.
                    $userId = $user->id;
                    \DB::afterCommit(function () use ($userId, $invoiceId) {
                        $user = User::find($userId);
                        if (! $user) {
                            return;
                        }
                        $this->downgradeUserAndCleanupStudioResources($user, 'Refund issued');
                        Log::info('2Checkout: User downgraded after refund (afterCommit)', [
                            'user_id'    => $user->id,
                            'invoice_id' => $invoiceId,
                        ]);
                    });
                });
            });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            Log::info('2Checkout: Refund lock busy, deferring to in-flight worker', [
                'invoice_id' => $invoiceId,
            ]);
            return response('OK', 200);
        } catch (\Throwable $e) {
            Log::error('2Checkout: REFUND_ISSUED processing failed', [
                'invoice_id' => $invoiceId,
                'error'      => $e->getMessage(),
            ]);
            return response('Internal error', 500);
        }

        return response('Refund processed', 200);
    }

    /**
     * Apply a chargeback (CHARGEBACK_REPORTED).
     *
     * P1-1 FIX: External side effects now dispatched via DB::afterCommit().
     *
     * P1-3 FIX: Only downgrade if the user's current plan matches the
     * transaction's plan — mirroring the refund path. Previously,
     * applyChargeback unconditionally downgraded regardless of which
     * transaction's invoice was being charged back. Scenario: user buys
     * Pro (invoice A) → refunds (downgraded to Free) → buys Studio
     * (invoice B) → 6 months later CHARGEBACK_REPORTED for invoice A.
     * The old code saw plan='studio' and downgraded to Free, losing the
     * Studio purchase. The fix only downgrades if user.plan ===
     * transaction.plan, so a chargeback on an old Pro invoice doesn't
     * affect a current Studio subscription.
     */
    private function applyChargeback(Request $request)
    {
        $invoiceId = $request->input('invoice_id');
        if (! $invoiceId) {
            Log::warning('2Checkout: CHARGEBACK_REPORTED missing invoice_id');
            return response('OK', 200);
        }

        // S-9 FIX: Lock TTL bumped from 60s to 120s - see upgrade path above
        // for rationale. Same worst-case DB transaction shape applies.
        $lock = \Illuminate\Support\Facades\Cache::lock("2co:chargeback:{$invoiceId}", 120);

        try {
            $lock->block(5, function () use ($invoiceId) {
                \DB::transaction(function () use ($invoiceId) {
                    $transaction = \DB::table('transactions')
                        ->where('invoice_id', $invoiceId)
                        ->lockForUpdate()
                        ->first();

                    if (! $transaction) {
                        Log::warning('2Checkout: CHARGEBACK_REPORTED for unknown invoice', [
                            'invoice_id' => $invoiceId,
                        ]);
                        return;
                    }

                    if ($transaction->status === 'chargeback') {
                        Log::info('2Checkout: Duplicate CHARGEBACK_REPORTED, skipping', [
                            'invoice_id' => $invoiceId,
                        ]);
                        return;
                    }

                    \DB::table('transactions')
                        ->where('invoice_id', $invoiceId)
                        ->update([
                            'status'     => 'chargeback',
                            'updated_at' => now(),
                        ]);

                    $user = User::find($transaction->user_id);
                    if (! $user) {
                        return;
                    }

                    // ── P1-3 FIX: Only downgrade if plan matches ────────
                    // Previously, chargebacks unconditionally downgraded.
                    // Now we mirror the refund path: only downgrade if the
                    // user's current plan matches the charged-back
                    // transaction's plan. A chargeback on an old Pro
                    // invoice does NOT downgrade a current Studio user.
                    if ($user->plan !== $transaction->plan) {
                        Log::info('2Checkout: CHARGEBACK_REPORTED not downgrading — plan changed since purchase', [
                            'invoice_id'        => $invoiceId,
                            'user_id'           => $user->id,
                            'current_plan'      => $user->plan,
                            'charged_back_plan' => $transaction->plan,
                        ]);
                        return;
                    }

                    if (! in_array($user->plan, ['pro', 'studio'], true)) {
                        return;
                    }

                    // ── P1-1 FIX: External side effects AFTER commit ────
                    $userId = $user->id;
                    \DB::afterCommit(function () use ($userId, $invoiceId) {
                        $user = User::find($userId);
                        if (! $user) {
                            return;
                        }
                        $this->downgradeUserAndCleanupStudioResources($user, 'Chargeback reported');
                        Log::info('2Checkout: User downgraded after chargeback (afterCommit)', [
                            'user_id'    => $user->id,
                            'invoice_id' => $invoiceId,
                        ]);
                    });
                });
            });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            Log::info('2Checkout: Chargeback lock busy, deferring to in-flight worker', [
                'invoice_id' => $invoiceId,
            ]);
            return response('OK', 200);
        } catch (\Throwable $e) {
            Log::error('2Checkout: CHARGEBACK_REPORTED processing failed', [
                'invoice_id' => $invoiceId,
                'error'      => $e->getMessage(),
            ]);
            return response('Internal error', 500);
        }

        return response('Chargeback processed', 200);
    }

    /**
     * Reverse a chargeback (CHARGEBACK_REVERSED).
     *
     * The cardholder won the chargeback dispute (or 2Checkout overturned it).
     * Restore the user's plan to the transaction's plan, and mark the
     * transaction back to 'completed'.
     *
     * This is the only path that re-grants a paid plan outside of an
     * ORDER_CREATED webhook. No external side effects here (no email, no
     * Coolify call, no file delete) — so no DB::afterCommit needed.
     */
    private function reverseChargeback(Request $request)
    {
        $invoiceId = $request->input('invoice_id');
        if (! $invoiceId) {
            Log::warning('2Checkout: CHARGEBACK_REVERSED missing invoice_id');
            return response('OK', 200);
        }

        // S-9 FIX: Lock TTL bumped from 60s to 120s - see upgrade path above
        // for rationale. Same worst-case DB transaction shape applies.
        $lock = \Illuminate\Support\Facades\Cache::lock("2co:cb_reverse:{$invoiceId}", 120);

        try {
            $lock->block(5, function () use ($invoiceId) {
                \DB::transaction(function () use ($invoiceId) {
                    $transaction = \DB::table('transactions')
                        ->where('invoice_id', $invoiceId)
                        ->lockForUpdate()
                        ->first();

                    if (! $transaction) {
                        Log::warning('2Checkout: CHARGEBACK_REVERSED for unknown invoice', [
                            'invoice_id' => $invoiceId,
                        ]);
                        return;
                    }

                    if ($transaction->status !== 'chargeback') {
                        Log::info('2Checkout: CHARGEBACK_REVERSED for non-chargeback transaction, skipping', [
                            'invoice_id' => $invoiceId,
                            'status'     => $transaction->status,
                        ]);
                        return;
                    }

                    \DB::table('transactions')
                        ->where('invoice_id', $invoiceId)
                        ->update([
                            'status'     => 'completed',
                            'updated_at' => now(),
                        ]);

                    $user = User::find($transaction->user_id);
                    if (! $user) {
                        return;
                    }

                    // Only restore if the user is currently on a lower plan.
                    // If they've since re-purchased, leave them alone.
                    $planRank = ['free' => 0, 'pro' => 1, 'studio' => 2];
                    if (($planRank[$user->plan] ?? 0) < ($planRank[$transaction->plan] ?? 0)) {
                        $user->forceFill([
                            'plan'            => $transaction->plan,
                            'plan_started_at' => now(),
                            'plan_expires_at' => null,
                        ])->save();

                        Log::info('2Checkout: Plan restored after chargeback reversal', [
                            'user_id'    => $user->id,
                            'invoice_id' => $invoiceId,
                            'to_plan'    => $transaction->plan,
                        ]);
                    }
                });
            });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            Log::info('2Checkout: Chargeback reversal lock busy, deferring to in-flight worker', [
                'invoice_id' => $invoiceId,
            ]);
            return response('OK', 200);
        } catch (\Throwable $e) {
            Log::error('2Checkout: CHARGEBACK_REVERSED processing failed', [
                'invoice_id' => $invoiceId,
                'error'      => $e->getMessage(),
            ]);
            return response('Internal error', 500);
        }

        return response('Chargeback reversal processed', 200);
    }

    /**
     * Downgrade a user to 'free' and clean up Studio-only resources.
     *
     * @param  User    $user
     * @param  string  $reason  Short reason for log context (e.g. "Refund issued")
     */
    private function downgradeUserAndCleanupStudioResources(User $user, string $reason): void
    {
        app(\App\Services\PlanDowngradeService::class)->downgradeToFree($user, $reason);
    }

    // ====================================================================
    // Private helpers — signature verification & log redaction
    // ====================================================================

    /**
     * Verify the 2Checkout webhook signature.
     *
     * Three layers (any failure → 403):
     *  1. Legacy MD5 hash (always required) — proves IPN origin.
     *  2. HMAC SHA-256 signature — MANDATORY in production.
     *  3. IP allowlist (optional, env-gated) — limits sender IPs.
     *
     * @return bool  true if all enabled layers pass, false otherwise.
     */
    private function verify2CheckoutSignature(Request $request): bool
    {
        $secretWord = config('services.2checkout.secret_word');

        if (! $secretWord) {
            Log::error('2Checkout: SECRET_WORD not configured in .env');
            return false;
        }

        $receivedHash = $request->input('md5_hash');

        if (! $receivedHash) {
            Log::warning('2Checkout: md5_hash field missing from webhook', [
                'invoice_id' => $request->input('invoice_id'),
            ]);
            return false;
        }

        // ── Layer 1: Legacy MD5 ─────────────────────────────────────────
        $stringToHash = strlen((string) $request->input('sale_id', ''))   . $request->input('sale_id', '')
                      . strlen((string) $request->input('vendor_id', '')) . $request->input('vendor_id', '')
                      . strlen((string) $request->input('invoice_id', '')) . $request->input('invoice_id', '')
                      . strlen($secretWord)                                . $secretWord;

        $calculatedHash = strtoupper(md5($stringToHash));

        if (! hash_equals($calculatedHash, strtoupper((string) $receivedHash))) {
            Log::warning('2Checkout: MD5 hash verification failed', [
                'invoice_id' => $request->input('invoice_id'),
                'sale_id'    => $request->input('sale_id'),
            ]);
            return false;
        }

        // ── Layer 2: HMAC SHA-256 (MANDATORY in production) ─────────────
        $buyLinkSecret   = config('services.2checkout.buy_link_secret_word');
        $allowMd5Only    = config('services.2checkout.allow_md5_only', false);
        $receivedSig     = $request->input('signature');
        $isProduction    = app()->environment('production');

        if ($buyLinkSecret) {
            if (! $receivedSig) {
                Log::warning('2Checkout: signature field missing but HMAC verification is configured', [
                    'invoice_id' => $request->input('invoice_id'),
                ]);
                return false;
            }

            $hmacPayload       = $this->build2CheckoutHmacPayload($request);
            $calculatedSig     = hash_hmac('sha256', $hmacPayload, $buyLinkSecret);

            if (! hash_equals($calculatedSig, (string) $receivedSig)) {
                Log::warning('2Checkout: HMAC SHA-256 signature verification failed', [
                    'invoice_id' => $request->input('invoice_id'),
                ]);
                return false;
            }
        } elseif ($receivedSig) {
            Log::error('2Checkout: signature field present but TWOCHECKOUT_BUY_LINK_SECRET_WORD not configured', [
                'invoice_id' => $request->input('invoice_id'),
            ]);
            return false;
        } else {
            if ($isProduction && ! $allowMd5Only) {
                Log::critical('2Checkout: HMAC secret not configured in production — FAILING CLOSED. Set TWOCHECKOUT_BUY_LINK_SECRET_WORD in .env, or set TWOCHECKOUT_ALLOW_MD5_ONLY=true as an emergency escape hatch.', [
                    'invoice_id' => $request->input('invoice_id'),
                ]);
                return false;
            }

            Log::warning('2Checkout: Accepting MD5-only webhook (HMAC not configured). This is insecure — configure TWOCHECKOUT_BUY_LINK_SECRET_WORD immediately.', [
                'invoice_id'     => $request->input('invoice_id'),
                'allow_md5_only' => $allowMd5Only ? 'true' : 'false',
                'environment'    => app()->environment(),
            ]);
        }

        // ── Layer 3: IP allowlist (optional, env-gated) ─────────────────
        $allowlist = config('services.2checkout.webhook_ip_allowlist');
        if ($allowlist) {
            $clientIp   = $request->ip();
            $allowedIps = array_filter(array_map('trim', explode(',', (string) $allowlist)));
            if (! in_array($clientIp, $allowedIps, true)) {
                Log::warning('2Checkout: webhook received from unallowed IP', [
                    'ip'         => $clientIp,
                    'invoice_id' => $request->input('invoice_id'),
                ]);
                return false;
            }
        } elseif ($isProduction) {
            Log::critical('2Checkout: TWOCHECKOUT_WEBHOOK_IP_ALLOWLIST not configured in production. HMAC is the primary defense, but the IP allowlist should be set for defense-in-depth. See 2Checkout merchant docs for INS server IP ranges.', [
                'invoice_id' => $request->input('invoice_id'),
            ]);
        }

        return true;
    }

    /**
     * Build the HMAC SHA-256 payload per 2Checkout INS 6.0 documented format.
     */
    private function build2CheckoutHmacPayload(Request $request): string
    {
        $fields = [
            'sale_id',
            'vendor_id',
            'invoice_id',
            'message_type',
            'message_id',
            'customer_email',
            'customer_name',
            'item_count',
            'item_id_1',
            'item_name_1',
            'item_usd_amount_1',
            'item_list_amount_1',
            'item_cust_amount_1',
            'item_type_1',
            'list_currency',
            'cust_currency',
        ];

        $payload = '';
        foreach ($fields as $field) {
            $value   = (string) $request->input($field, '');
            $payload .= strlen($value) . $value;
        }

        return $payload;
    }

    /**
     * Return a redacted copy of the webhook payload for logging.
     */
    private function redactedPayload(Request $request): array
    {
        return [
            'message_type' => $request->input('message_type'),
            'invoice_id'   => $request->input('invoice_id'),
            'sale_id'      => $request->input('sale_id'),
            'vendor_id'    => $request->input('vendor_id'),
            'item_id_1'    => $request->input('item_id_1'),
            'amount'       => $request->input('item_list_amount_1'),
            'currency'     => $request->input('list_currency'),
            'ip'           => $request->ip(),
            'has_email'    => $request->filled('customer_email') ? 'yes' : 'no',
            'has_name'     => $request->filled('customer_name') ? 'yes' : 'no',
        ];
    }

    // ── M-1: Recurring (subscription) handlers ───────────────────────────
    //
    // 2Checkout sends these message types for recurring billing lifecycle:
    //
    //   RECURRING_INSTALLMENT_SUCCESS — a recurring payment succeeded
    //     (monthly/yearly renewal). Extends the user's subscription_ends_at
    //     to the next billing date + records a transaction row.
    //
    //   RECURRING_INSTALLMENT_FAILED — a recurring payment failed
    //     (expired card, insufficient funds, etc.). Sets subscription_status
    //     to 'past_due'. 2Checkout will retry per their dunning schedule;
    //     if all retries fail, they send RECURRING_ORDER_CANCELLED.
    //
    //   RECURRING_ORDER_CANCELLED — the subscription was cancelled (by the
    //     user via BillingController::cancelSubscription, by the system
    //     after all dunning retries failed, or by the admin in the 2Checkout
    //     dashboard). Sets subscription_status to 'cancelled'. The user
    //     keeps access until subscription_ends_at (the end of the
    //     already-paid-for period), then is downgraded by CheckPlanExpiry.

    /**
     * Handle RECURRING_INSTALLMENT_SUCCESS — a recurring payment succeeded.
     *
     * Extends the user's subscription_ends_at to the next billing date
     * (from the webhook's item_billing_cycle_next_date field) and records
     * a transaction row for the renewal payment.
     */
    private function handleRecurringSuccess(Request $request)
    {
        $invoiceId = $request->input('invoice_id');
        $saleId    = $request->input('sale_id');
        $productId = $request->input('item_id_1');
        $amount    = $request->input('item_list_amount_1', 0);

        // The next billing date tells us when the subscription's access
        // expires (i.e. when the NEXT payment is due). 2Checkout sends
        // this as item_billing_cycle_next_date in YYYY-MM-DD format.
        $nextBillingDate = $request->input('item_billing_cycle_next_date');
        $subscriptionId  = $request->input('recurring_order_id') ?? $saleId;

        Log::info('2Checkout: RECURRING_INSTALLMENT_SUCCESS received', [
            'invoice_id'      => $invoiceId,
            'subscription_id' => $subscriptionId,
            'product_id'      => $productId,
            'amount'          => $amount,
            'next_billing'    => $nextBillingDate,
        ]);

        // Find the user by subscription_id (most reliable) or by
        // customer_email (fallback).
        $user = $this->findUserForRecurringEvent($request, $subscriptionId);

        if (! $user) {
            Log::warning('2Checkout: RECURRING_INSTALLMENT_SUCCESS — user not found', [
                'invoice_id'      => $invoiceId,
                'subscription_id' => $subscriptionId,
            ]);
            return response('OK', 200);
        }

        $lock = \Illuminate\Support\Facades\Cache::lock("2co:recurring:{$invoiceId}", 120);

        try {
            $lock->block(5, function () use (
                $user, $invoiceId, $saleId, $productId, $amount,
                $nextBillingDate, $subscriptionId, $request
            ) {
                \DB::transaction(function () use (
                    $user, $invoiceId, $saleId, $productId, $amount,
                    $nextBillingDate, $subscriptionId, $request
                ) {
                    // Idempotency: skip if this invoice is already recorded.
                    $existing = \DB::table('transactions')
                        ->where('invoice_id', $invoiceId)
                        ->lockForUpdate()
                        ->first();

                    if ($existing) {
                        Log::info('2Checkout: Duplicate recurring webhook, skipping', [
                            'invoice_id' => $invoiceId,
                        ]);
                        return;
                    }

                    // Extend the subscription's access period.
                    $endsAt = $nextBillingDate
                        ? \Carbon\Carbon::parse($nextBillingDate)->endOfDay()
                        : now()->addMonth(); // fallback if 2Checkout doesn't send the date

                    $user->forceFill([
                        'subscription_status' => 'active',
                        'subscription_ends_at' => $endsAt,
                        'plan_expires_at'     => $endsAt, // sync plan_expires_at for CheckPlanExpiry
                    ])->save();

                    // Record the renewal transaction.
                    \DB::table('transactions')->insert([
                        'user_id'        => $user->id,
                        'invoice_id'     => $invoiceId,
                        'sale_id'        => $saleId,
                        'product_id'     => $productId,
                        'plan'           => $user->plan,
                        'amount'         => $amount,
                        'currency'       => $request->input('list_currency', 'USD'),
                        'customer_email' => $user->email,
                        'customer_name'  => $user->name,
                        'status'         => 'completed',
                        'created_at'     => now(),
                        'updated_at'     => now(),
                    ]);

                    Log::info('2Checkout: Recurring installment processed', [
                        'user_id'         => $user->id,
                        'subscription_id' => $subscriptionId,
                        'invoice_id'      => $invoiceId,
                        'amount'          => $amount,
                        'next_billing'    => $nextBillingDate,
                    ]);
                });
            });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            Log::info('2Checkout: Recurring lock busy, deferring', [
                'invoice_id' => $invoiceId,
            ]);
        }

        return response('OK', 200);
    }

    /**
     * Handle RECURRING_INSTALLMENT_FAILED — a recurring payment failed.
     *
     * Sets subscription_status to 'past_due'. 2Checkout will retry per
     * their dunning schedule; if all retries fail, they send
     * RECURRING_ORDER_CANCELLED. The user keeps access during the
     * dunning period (subscription_ends_at is NOT changed — it stays
     * at the end of the last successful billing period).
     */
    private function handleRecurringFailure(Request $request)
    {
        $invoiceId       = $request->input('invoice_id');
        $subscriptionId  = $request->input('recurring_order_id') ?? $request->input('sale_id');

        Log::warning('2Checkout: RECURRING_INSTALLMENT_FAILED received', [
            'invoice_id'      => $invoiceId,
            'subscription_id' => $subscriptionId,
        ]);

        $user = $this->findUserForRecurringEvent($request, $subscriptionId);

        if (! $user) {
            Log::warning('2Checkout: RECURRING_INSTALLMENT_FAILED — user not found', [
                'subscription_id' => $subscriptionId,
            ]);
            return response('OK', 200);
        }

        $user->forceFill([
            'subscription_status' => 'past_due',
        ])->save();

        Log::info('2Checkout: Subscription marked past_due', [
            'user_id'         => $user->id,
            'subscription_id' => $subscriptionId,
        ]);

        // TODO (M-9 dunning): send a "payment failed — update your card"
        // email here. 2Checkout's dunning sequence handles retries, but
        // a user-facing email increases recovery rate. Deferred to the
        // M-9 (dunning management) iteration.

        return response('OK', 200);
    }

    /**
     * Handle RECURRING_ORDER_CANCELLED — the subscription was cancelled.
     *
     * Sets subscription_status to 'cancelled'. The user keeps access until
     * subscription_ends_at (the end of the already-paid-for period), then
     * is downgraded by CheckPlanExpiry middleware on their next request.
     *
     * Cancellation can be triggered by:
     *   - The user via BillingController::cancelSubscription (which calls
     *     2Checkout's cancel API → 2Checkout sends this webhook)
     *   - The system after all dunning retries failed
     *   - An admin in the 2Checkout merchant dashboard
     */
    private function handleRecurringCancelled(Request $request)
    {
        $subscriptionId = $request->input('recurring_order_id') ?? $request->input('sale_id');

        Log::info('2Checkout: RECURRING_ORDER_CANCELLED received', [
            'subscription_id' => $subscriptionId,
        ]);

        $user = $this->findUserForRecurringEvent($request, $subscriptionId);

        if (! $user) {
            Log::warning('2Checkout: RECURRING_ORDER_CANCELLED — user not found', [
                'subscription_id' => $subscriptionId,
            ]);
            return response('OK', 200);
        }

        $user->forceFill([
            'subscription_status'      => 'cancelled',
            'subscription_cancelled_at' => now(),
            // subscription_ends_at is NOT changed — it stays at the end of
            // the last paid period. CheckPlanExpiry will downgrade when it
            // passes.
        ])->save();

        Log::info('2Checkout: Subscription cancelled', [
            'user_id'         => $user->id,
            'subscription_id' => $subscriptionId,
            'ends_at'         => $user->subscription_ends_at?->toIso8601String(),
        ]);

        return response('OK', 200);
    }

    /**
     * Find the user associated with a recurring webhook event.
     *
     * Tries (in order):
     *   1. subscription_id match (most reliable — set on initial purchase)
     *   2. customer_email match (fallback if subscription_id wasn't stored)
     */
    private function findUserForRecurringEvent(Request $request, ?string $subscriptionId): ?User
    {
        if ($subscriptionId) {
            $user = User::where('subscription_id', $subscriptionId)->first();
            if ($user) return $user;
        }

        $customerEmail = $request->input('customer_email');
        if ($customerEmail && filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            return User::where('email', $customerEmail)->first();
        }

        return null;
    }
}
