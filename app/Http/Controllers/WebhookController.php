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
     * SECURITY MODEL
     * --------------
     * 1. The legacy `md5_hash` field is verified using `hash_equals()` (timing-safe).
     *    This proves the IPN originated from 2Checkout but only covers
     *    `sale_id + vendor_id + invoice_id + secret_word` — it does NOT cover
     *    `customer_email`, `item_id_1`, or `item_list_amount_1`.
     *
     * 2. When `TWOCHECKOUT_BUY_LINK_SECRET_WORD` is configured, an additional
     *    HMAC SHA-256 signature (`signature` field) is required and verified
     *    over the security-critical IPN fields. This closes the replay attack
     *    where a buyer re-signs a captured IPN with a different
     *    `customer_email` / `item_id_1` to upgrade arbitrary accounts.
     *
     * 3. When `TWOCHECKOUT_WEBHOOK_IP_ALLOWLIST` is configured, only requests
     *    from those IPs are accepted. 2Checkout publishes its INS server IP
     *    ranges in their merchant documentation.
     *
     * 4. PII (customer_email, customer_name) is redacted from logs.
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
        // STEP 2: Route by message_type
        // ================================
        $messageType = $request->input('message_type');

        // ORDER_CREATED triggers an upgrade (handled below).
        // Refunds and chargebacks are dispatched to dedicated handlers so
        // they cannot be confused with order creation.
        // Previously these message types were silently 200-OK'd here, which
        // meant refunds were never applied (the separate /refund route was
        // never actually hit by 2Checkout's standard INS configuration) and
        // chargebacks were ignored entirely — a customer could file a
        // chargeback and keep the paid plan forever.
        if ($messageType === 'REFUND_ISSUED') {
            return $this->applyRefund($request);
        }
        if ($messageType === 'CHARGEBACK_REPORTED') {
            return $this->applyChargeback($request);
        }
        if ($messageType === 'CHARGEBACK_REVERSED') {
            return $this->reverseChargeback($request);
        }

        // Other message types (FRAUD_STATUS_CHANGED, REFUND_REQUESTED,
        // INVOICE_STATUS_CHANGED, etc.) are logged but do not mutate state.
        // REFUND_REQUESTED is explicitly NOT a downgrade trigger — it fires
        // when the customer merely asks for a refund, before 2Checkout
        // approves it. Only REFUND_ISSUED is authoritative.
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
        $productId     = $request->input('item_id_1'); // Product ID from 2Checkout
        $amount        = $request->input('item_list_amount_1', 0);

        // ── Task H01: prefer external-reference for user matching ─────────
        // The buy URL (BillingController::upgrade) passes external-reference
        // = <pending_upgrade.token>. This is the authoritative binding —
        // it proves the payment originated from THIS user's click, even if
        // they paid with a different email at checkout (PayPal, gift card,
        // typo, etc.). Without this, customer_email mismatches silently
        // orphan the payment.
        $externalReference = $request->input('external-reference')
            ?? $request->input('external_reference')
            ?? $request->input('merchant_item_id_1');

        $user = null;
        $pendingUpgrade = null;

        if (! empty($externalReference)) {
            // Try as pending_upgrade token first (preferred path)
            $pendingUpgrade = \App\Models\PendingUpgrade::where('token', $externalReference)
                ->where('status', 'pending')
                ->first();

            if ($pendingUpgrade) {
                $user = $pendingUpgrade->user;
                // Use the user's account email for the transaction record,
                // NOT the customer_email from 2Checkout — the account email
                // is what we'll send future notifications to.
                $customerEmail = $user->email;
            } else {
                // Fallback: try as a bare user_id (merchant_item_id_1 path)
                $user = User::find((int) $externalReference);
            }
        }

        // Fallback: match by customer_email (legacy path for buyers who
        // somehow reached 2Checkout without going through BillingController)
        if (! $user && filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            $user = User::where('email', $customerEmail)->first();
        }

        if (! $user) {
            Log::warning('2Checkout: User not found by external-reference or customer_email', [
                'invoice_id'           => $invoiceId,
                'has_external_ref'     => ! empty($externalReference),
                'has_customer_email'   => ! empty($customerEmail),
                // Do not log the email here — it is PII
            ]);
            return response('OK', 200); // 200 so 2Checkout does not retry
        }

        // Validate customer_email if we don't have one yet (defensive)
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
            // Still return 200 so 2Checkout doesn't keep retrying,
            // but flag it for manual review
            return response('Unknown product - flagged for review', 200);
        }

        // ================================
        // STEP 5: Idempotent upgrade (atomic)
        // ================================
        // The idempotency check MUST run BEFORE the user upgrade, not after.
        // 2Checkout retries IPNs aggressively (their docs say "retry until
        // 200 OK, up to several days"). Previously the upgrade ran first and
        // the duplicate check ran second — so every retry re-stamped
        // `plan_started_at = now()` and two concurrent webhooks for the same
        // invoice both passed the upgrade step before either hit the unique
        // constraint on `transactions.invoice_id`.
        //
        // The fix: acquire a per-invoice cache lock (cross-process safe under
        // Redis/database cache), then run the duplicate check + upgrade +
        // transaction insert inside a single DB transaction. If the lock
        // cannot be acquired (another worker is processing the same invoice),
        // we return 200 'OK' so 2Checkout does not retry immediately — the
        // in-flight worker will complete the upgrade.
        $lock = \Illuminate\Support\Facades\Cache::lock("2co:upgrade:{$invoiceId}", 60);

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
                    // Lock the transactions row for the duration of the
                    // transaction so a concurrent worker cannot insert a
                    // duplicate between our SELECT and INSERT. The unique
                    // index on `transactions.invoice_id` is the final
                    // safety net — but we want a clean return path, not a
                    // QueryException.
                    $existing = \DB::table('transactions')
                        ->where('invoice_id', $invoiceId)
                        ->lockForUpdate()
                        ->first();

                    if ($existing) {
                        // Already processed — do NOT re-stamp plan_started_at.
                        Log::info('2Checkout: Duplicate webhook, skipping upgrade', [
                            'invoice_id'     => $invoiceId,
                            'existing_status'=> $existing->status,
                        ]);
                        return false; // signal "already processed"
                    }

                    // ── Upgrade the user ─────────────────────────────────
                    // NOTE: max_galleries / max_images are intentionally NOT
                    // set here. The User model's `updating` hook (boot())
                    // is the single source of truth for plan limits via
                    // User::planLimits($plan). Setting them here causes a
                    // silent conflict where the boot hook overwrites
                    // whatever values we pass.
                    $user->forceFill([
                        'plan'            => $planConfig['plan'],
                        'plan_started_at' => now(),
                        'plan_expires_at' => null, // Lifetime / one-time purchase
                    ])->save();

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

                    // ── Mark the pending_upgrade as converted (task H01) ──
                    // This links the IPN back to the original click, so
                    // BillingController's "pending upgrades" list knows
                    // to hide this one.
                    if ($pendingUpgrade) {
                        $pendingUpgrade->markConverted($transactionId);
                    }

                    Log::info('2Checkout: User upgraded successfully', [
                        'user_id'    => $user->id,
                        'plan'       => $planConfig['plan'],
                        'invoice_id' => $invoiceId,
                        'matched_by' => $pendingUpgrade ? 'external-reference' : 'customer_email',
                    ]);

                    // ── Send confirmation email (task H03 / audit H5) ──────
                    // Previously users got NO confirmation after a successful
                    // upgrade. They had to log in and check their dashboard.
                    // For a $29–$99 purchase, a confirmation email is a basic
                    // customer expectation and reduces support tickets.
                    try {
                        \Illuminate\Support\Facades\Mail::to($user->email)
                            ->send(new \App\Mail\PlanUpgradedEmail($user, $planConfig['plan'], $invoiceId));
                    } catch (\Throwable $e) {
                        Log::warning('2Checkout: PlanUpgradedEmail send failed', [
                            'user_id' => $user->id,
                            'error'   => $e->getMessage(),
                        ]);
                        // Don't fail the upgrade — the user is upgraded in
                        // the DB, the email is a nice-to-have.
                    }

                    return true; // signal "upgraded"
                });
            });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            // Another worker is processing this same invoice. Tell 2Checkout
            // "OK" so they stop retrying — the in-flight worker will finish.
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
            // Return 500 so 2Checkout retries — transient failures (DB down,
            // deadlocks, etc.) should not silently lose the upgrade.
            return response('Internal error', 500);
        }

        if (! $processed) {
            // Already processed — return OK without re-upgrading.
            return response('OK', 200);
        }

        return response('OK', 200);
    }

    /**
     * Handle the legacy /webhooks/2checkout/refund route.
     *
     * 2Checkout's standard INS configuration sends ALL message types to a
     * single URL — so refunds arrive at /webhooks/2checkout and are dispatched
     * by message_type inside handle2Checkout(). The /refund route is kept for
     * backward compatibility with any 2Checkout account that was historically
     * configured with two separate URLs.
     *
     * SECURITY: Same verification as handle2Checkout — see verify2CheckoutSignature().
     */
    public function handleRefund(Request $request)
    {
        Log::info('2Checkout Refund Route Received', $this->redactedPayload($request));

        if (! $this->verify2CheckoutSignature($request)) {
            return response('Hash verification failed', 403);
        }

        // Dispatch by message_type. If 2Checkout sends a REFUND_ISSUED to this
        // route, we handle it properly. If it sends something else (e.g.
        // ORDER_CREATED by misconfiguration), we route accordingly.
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
     * Behavior:
     *  - Look up the transaction by invoice_id (NOT by customer_email + latest).
     *  - If the transaction is already marked 'refunded', return OK (idempotent).
     *  - Mark the transaction 'refunded'.
     *  - Downgrade the user ONLY IF their current plan matches the plan the
     *    refunded transaction was for. A user who bought Pro, then later
     *    upgraded to Studio, then refunds the old Pro purchase should NOT
     *    lose Studio — they only lose Pro, which they already replaced.
     *  - Downgrade target is 'free' (we have no concept of "partial refund
     *    back to Pro from Studio" today).
     *
     * Uses the same per-invoice Cache::lock + DB::transaction pattern as the
     * upgrade path to prevent concurrent-webhook races.
     */
    private function applyRefund(Request $request)
    {
        $invoiceId = $request->input('invoice_id');
        if (! $invoiceId) {
            Log::warning('2Checkout: REFUND_ISSUED missing invoice_id');
            return response('OK', 200);
        }

        $lock = \Illuminate\Support\Facades\Cache::lock("2co:refund:{$invoiceId}", 60);

        try {
            $lock->block(5, function () use ($invoiceId, $request) {
                \DB::transaction(function () use ($invoiceId, $request) {
                    // ── Match the transaction by invoice_id (NOT by email+latest) ──
                    $transaction = \DB::table('transactions')
                        ->where('invoice_id', $invoiceId)
                        ->lockForUpdate()
                        ->first();

                    if (! $transaction) {
                        // Refund for an invoice we never recorded. This can
                        // happen if the original ORDER_CREATED webhook failed
                        // (e.g. CSRF before C01 was deployed) but the user was
                        // manually upgraded. Log for ops review — we have no
                        // transaction to mark refunded.
                        Log::warning('2Checkout: REFUND_ISSUED for unknown invoice', [
                            'invoice_id' => $invoiceId,
                        ]);
                        return;
                    }

                    if ($transaction->status === 'refunded') {
                        // Idempotent — already processed.
                        Log::info('2Checkout: Duplicate REFUND_ISSUED, skipping', [
                            'invoice_id' => $invoiceId,
                        ]);
                        return;
                    }

                    // ── Mark the specific transaction as refunded ──
                    \DB::table('transactions')
                        ->where('invoice_id', $invoiceId)
                        ->update([
                            'status'     => 'refunded',
                            'updated_at' => now(),
                        ]);

                    // ── Downgrade only if current plan matches refunded plan ──
                    // If the user is on a different plan now (e.g. they
                    // upgraded Pro→Studio and refunded the old Pro), do NOT
                    // touch their current plan.
                    $user = User::find($transaction->user_id);

                    if (! $user) {
                        Log::warning('2Checkout: REFUND_ISSUED user no longer exists', [
                            'invoice_id' => $invoiceId,
                            'user_id'    => $transaction->user_id,
                        ]);
                        return;
                    }

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
                        // Already on free — nothing to downgrade.
                        return;
                    }

                    $this->downgradeUserAndCleanupStudioResources($user, 'Refund issued');

                    Log::info('2Checkout: User downgraded after refund', [
                        'user_id'    => $user->id,
                        'invoice_id' => $invoiceId,
                        'from_plan'  => $transaction->plan,
                    ]);
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
     * Treat identically to a refund for plan-downgrade purposes, but mark
     * the transaction as 'chargeback' (not 'refunded') so finance can
     * distinguish the two in reports.
     */
    private function applyChargeback(Request $request)
    {
        $invoiceId = $request->input('invoice_id');
        if (! $invoiceId) {
            Log::warning('2Checkout: CHARGEBACK_REPORTED missing invoice_id');
            return response('OK', 200);
        }

        $lock = \Illuminate\Support\Facades\Cache::lock("2co:chargeback:{$invoiceId}", 60);

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

                    // Chargebacks always downgrade — the cardholder is
                    // disputing the charge, so we cannot keep the paid plan.
                    // (Unlike refunds, we do not check plan_match — the
                    // customer is contesting the purchase itself.)
                    if (in_array($user->plan, ['pro', 'studio'], true)) {
                        $this->downgradeUserAndCleanupStudioResources($user, 'Chargeback reported');
                        Log::info('2Checkout: User downgraded after chargeback', [
                            'user_id'    => $user->id,
                            'invoice_id' => $invoiceId,
                            'from_plan'  => $transaction->plan,
                        ]);
                    }
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
     * ORDER_CREATED webhook.
     */
    private function reverseChargeback(Request $request)
    {
        $invoiceId = $request->input('invoice_id');
        if (! $invoiceId) {
            Log::warning('2Checkout: CHARGEBACK_REVERSED missing invoice_id');
            return response('OK', 200);
        }

        $lock = \Illuminate\Support\Facades\Cache::lock("2co:cb_reverse:{$invoiceId}", 60);

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
     * Used by applyRefund() and applyChargeback() to ensure consistent
     * post-downgrade state. Delegates to PlanDowngradeService which:
     *   - downgrades the user's plan + limits + plan_expires_at
     *   - clears galleries.custom_domain and calls CoolifyDomainManager::removeDomain
     *   - forgets the cached custom_domain:{host} lookup
     *   - deletes custom_logo_path / curtain_logo_path / audio_path files
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
     *  2. HMAC SHA-256 signature (env-gated) — covers full payload.
     *  3. IP allowlist (env-gated) — limits sender IPs.
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
        // Format: strlen(sale_id) + sale_id + strlen(vendor_id) + vendor_id
        //       + strlen(invoice_id) + invoice_id + strlen(secret) + secret
        // This is the algorithm 2Checkout documents for INS `md5_hash`.
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

        // ── Layer 2: HMAC SHA-256 (optional, env-gated) ─────────────────
        // When TWOCHECKOUT_BUY_LINK_SECRET_WORD is set, the IPN must also
        // carry a `signature` field whose HMAC SHA-256 over the documented
        // INS parameter set we verify below.
        //
        // This closes the replay-tamper attack where an attacker captures a
        // valid IPN, then changes `customer_email` / `item_id_1` and re-POSTs.
        // The MD5 hash still validates (those fields aren't signed), but the
        // HMAC SHA-256 fails because the changed fields ARE signed.
        $buyLinkSecret   = config('services.2checkout.buy_link_secret_word');
        $receivedSig     = $request->input('signature');

        if ($buyLinkSecret) {
            if (! $receivedSig) {
                Log::warning('2Checkout: signature field missing but HMAC verification enabled', [
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
            // 2Checkout is sending a `signature` field but we're not verifying
            // it — fail closed until the operator configures the buy link
            // secret word. Otherwise we are silently accepting unsigned data
            // while 2Checkout tells us signing is available.
            Log::error('2Checkout: signature field present but TWOCHECKOUT_BUY_LINK_SECRET_WORD not configured', [
                'invoice_id' => $request->input('invoice_id'),
            ]);
            return false;
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
        }

        return true;
    }

    /**
     * Build the HMAC SHA-256 payload per 2Checkout INS 6.0 documented format.
     *
     * Concatenates each field as `strlen(value) + value` (same length-prefix
     * scheme as the MD5 algorithm) over the security-critical IPN fields.
     *
     * If 2Checkout's actual `signature` parameter uses a different field set
     * for your account (some accounts use a different whitelist), update the
     * `$fields` array below to match the 2Checkout dashboard's documented
     * signature parameters for your account.
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
     *
     * customer_email and customer_name are PII and must not appear in logs
     * (GDPR Art. 5(1)(c) data minimisation). We keep the security-relevant
     * fields (invoice_id, sale_id, message_type, item_id, amount) so ops
     * can still correlate webhook events to user records via the DB.
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
}
