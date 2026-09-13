<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\TwoCheckoutApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function handle2Checkout(Request $request)
    {
        // Log the incoming webhook for debugging — PII redacted.
        Log::info('2Checkout Webhook Received', $this->redactedPayload($request));

        if (! $this->verify2CheckoutSignature($request)) {
            return response('Hash verification failed', 403);
        }

        $messageId = $request->input('message_id');
        $messageType = $request->input('message_type');
        if ($messageId && $messageType) {
            $inserted = \DB::table('processed_webhooks')->insertOrIgnore([
                'message_id'   => $messageId,
                'message_type' => $messageType,
                'invoice_id'   => $request->input('invoice_id'),
                'payload'      => $this->storablePayload($request),
                'status'       => 'processing',
                'processed_at' => now(),
                'updated_at'   => now(),
            ]);

            if (! $inserted) {
                if (! $this->claimExistingWebhook($messageId, $messageType)) {
                    Log::info('2Checkout: Duplicate message_id+type, skipping (replay protection)', [
                        'message_id'   => $messageId,
                        'message_type' => $messageType,
                    ]);
                    return response('OK', 200);
                }
            }
        }

        $response = $this->processVerifiedWebhook($request, $messageId, $messageType);
        $this->finalizeWebhook($messageId, $messageType, $response);

        return $response;
    }

    public function processReplay(Request $request)
    {
        return $this->processVerifiedWebhook(
            $request,
            null,
            $request->input('message_type') !== null ? (string) $request->input('message_type') : null,
        );
    }

    private function processVerifiedWebhook(Request $request, ?string $messageId, ?string $messageType)
    {
        if ($this->isDemoNotification($request)) {
            return response('OK', 200);
        }

        try {
            if ($messageType === 'REFUND_ISSUED') {
                return $this->applyRefund($request);
            }
            if ($messageType === 'CHARGEBACK_REPORTED') {
                return $this->applyChargeback($request);
            }
            if ($messageType === 'CHARGEBACK_REVERSED') {
                return $this->reverseChargeback($request);
            }

            if ($messageType === 'RECURRING_INSTALLMENT_SUCCESS') {
                return $this->handleRecurringSuccess($request);
            }
            if ($messageType === 'RECURRING_INSTALLMENT_FAILED') {
                return $this->handleRecurringFailure($request);
            }
            if ($messageType === 'RECURRING_ORDER_CANCELLED') {
                return $this->handleRecurringCancelled($request);
            }
        } catch (\Throwable $e) {
            $this->markWebhookFailed($messageId, $messageType);
            Log::error('2Checkout: Webhook handler failed — ledger row marked failed so retry can reprocess', [
                'message_type' => $messageType,
                'invoice_id'   => $request->input('invoice_id'),
                'error'        => $e->getMessage(),
            ]);
            return response('Internal error', 500);
        }

        if ($messageType !== 'ORDER_CREATED') {
            Log::info('2Checkout: Non-mutating message type', [
                'type'       => $messageType,
                'invoice_id' => $request->input('invoice_id'),
            ]);
            return response('OK', 200);
        }

        $customerEmail = $request->input('customer_email');
        $customerName  = $request->input('customer_name');
        $invoiceId     = $request->input('invoice_id');
        $productId     = $request->input('item_id_1');
        $amount        = $request->input('item_list_amount_1', 0);

        $externalReference = $request->input('external-reference')
            ?? $request->input('external_reference');

        $user = null;
        $pendingUpgrade = null;

        if (! empty($externalReference)) {
            $pendingUpgrade = \App\Models\PendingUpgrade::findByToken($externalReference);

            if ($pendingUpgrade && $pendingUpgrade->status !== 'pending') {
                $pendingUpgrade = null;
            }

            if ($pendingUpgrade) {
                $user = $pendingUpgrade->user;
                $customerEmail = $user->email;
            }
        }

        if (! $user && filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            $user = User::where('email', $customerEmail)->first();
        }

        if (! $user) {
            Log::warning('2Checkout: User not found by external-reference or customer_email', [
                'invoice_id'           => $invoiceId,
                'has_external_ref'     => ! empty($externalReference),
                'ref_was_valid_token'  => $pendingUpgrade !== null,
                'has_customer_email'   => ! empty($customerEmail),
            ]);
            return response('OK', 200);
        }

        if (empty($customerEmail)) {
            $customerEmail = $user->email;
        }

        $previousSubscriptionId = $user->subscription_id;
        $previousSubscriptionActive = $user->hasActiveSubscription() || $user->subscription_status === 'past_due';

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

        $lock = \Illuminate\Support\Facades\Cache::lock("2co:upgrade:{$invoiceId}", 120);

        try {
            $processed = $lock->block(5, function () use (
                $user, $planConfig, $invoiceId, $productId, $amount,
                $request, $customerEmail, $customerName, $pendingUpgrade,
                $previousSubscriptionId, $previousSubscriptionActive
            ) {
                return \DB::transaction(function () use (
                    $user, $planConfig, $invoiceId, $productId, $amount,
                    $request, $customerEmail, $customerName, $pendingUpgrade,
                    $previousSubscriptionId, $previousSubscriptionActive
                ) {
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

                        if ($previousSubscriptionActive && $previousSubscriptionId) {
                            $user->forceFill([
                                'subscription_status'       => 'cancelled',
                                'subscription_cancelled_at' => now(),
                            ])->save();
                        }
                    }

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

                    $this->auditWebhook('webhook.order_processed', $user, [
                        'invoice_id'   => $invoiceId,
                        'plan'         => $planConfig['plan'],
                        'amount'       => $amount,
                        'billing_type' => $recurringOrderId ? 'subscription' : 'one_time',
                        'matched_by'   => $pendingUpgrade ? 'external-reference' : 'customer_email',
                    ]);

                    \DB::afterCommit(function () use ($user, $planConfig, $invoiceId, $transactionId, $recurringOrderId, $previousSubscriptionId, $previousSubscriptionActive) {
                        try {
                            \Illuminate\Support\Facades\Mail::to($user->email)
                                ->send(new \App\Mail\PlanUpgradedEmail($user, $planConfig['plan'], $invoiceId));
                        } catch (\Throwable $e) {
                            Log::warning('2Checkout: PlanUpgradedEmail send failed', [
                                'user_id' => $user->id,
                                'error'   => $e->getMessage(),
                            ]);
                        }

                        // A confirmed new purchase supersedes any prior live subscription,
                        // otherwise 2Checkout would keep billing the replaced one.
                        if ($previousSubscriptionActive
                            && $previousSubscriptionId
                            && $previousSubscriptionId !== $recurringOrderId) {
                            $this->cancelSupersededSubscription($user, $previousSubscriptionId);
                        }

                        try {
                            $transaction = \App\Models\Transaction::find($transactionId);
                            if ($transaction) {
                                app(\App\Services\InvoiceGenerator::class)
                                    ->generateForTransaction($transaction, $user, [
                                        'billing_type' => $recurringOrderId ? 'subscription' : 'one_time',
                                        'customer_country' => strtoupper((string) (
                                            $request->input('customer_country')
                                            ?? $request->input('country')
                                            ?? ''
                                        )) ?: null,
                                    ]);
                            }
                        } catch (\Throwable $e) {
                            Log::warning('2Checkout: Invoice generation failed', [
                                'transaction_id' => $transactionId,
                                'error'          => $e->getMessage(),
                            ]);
                        }

                        // Create in-app notification for the upgrade
                        \App\Services\NotificationService::create(
                            $user,
                            'billing',
                            ucfirst($planConfig['plan']) . ' plan activated!',
                            'Your ' . ucfirst($planConfig['plan']) . ' plan is now active. Enjoy the new features!',
                            '/billing',
                            'View billing'
                        );
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
            $this->markWebhookFailed($messageId, $messageType);
            Log::error('2Checkout: Upgrade failed — ledger row marked failed so retry can reprocess', [
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

    public function handleRefund(Request $request)
    {
        Log::info('2Checkout Refund Route Received', $this->redactedPayload($request));

        if (! $this->verify2CheckoutSignature($request)) {
            return response('Hash verification failed', 403);
        }

        if ($this->isDemoNotification($request)) {
            return response('OK', 200);
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

    private function applyRefund(Request $request)
    {
        $invoiceId = $request->input('invoice_id');
        if (! $invoiceId) {
            Log::warning('2Checkout: REFUND_ISSUED missing invoice_id');
            return response('OK', 200);
        }

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

                    $rawRefundField = $request->input('item_list_amount_1', 0);
                    $refundAmount = (float) $rawRefundField;
                    $originalAmount = (float) $transaction->amount;
                    $ratio = $originalAmount > 0 ? $refundAmount / $originalAmount : 0.0;
                    $isFullRefund = $originalAmount > 0 && ($ratio >= 0.90 - 0.000001);

                    Log::info('2Checkout: REFUND_ISSUED amount analysis', [
                        'invoice_id'           => $invoiceId,
                        'raw_refund_field'     => $rawRefundField,
                        'parsed_refund_amount' => $refundAmount,
                        'original_amount'      => $originalAmount,
                        'ratio'                => $originalAmount > 0 ? round($refundAmount / $originalAmount, 4) : null,
                        'is_full_refund'       => $isFullRefund,
                        'note'                 => 'Verify item_list_amount_1 is the refund amount (not original).',
                    ]);

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

                    $this->auditWebhook(
                        $isFullRefund ? 'webhook.refund_applied' : 'webhook.partial_refund_applied',
                        $user,
                        [
                            'invoice_id'      => $invoiceId,
                            'refund_amount'   => $refundAmount,
                            'original_amount' => $originalAmount,
                            'new_status'      => $newStatus,
                        ]
                    );

                    // ── Partial refunds do NOT downgrade ────────────────
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

                    $userId = $user->id;
                    \DB::afterCommit(function () use ($userId, $invoiceId) {
                        $user = User::find($userId);
                        if (! $user) {
                            return;
                        }
                        $this->downgradeUserAndCleanupStudioResources($user, 'Refund issued');
                        $this->expireStaleSubscription($user);
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

    private function applyChargeback(Request $request)
    {
        $invoiceId = $request->input('invoice_id');
        if (! $invoiceId) {
            Log::warning('2Checkout: CHARGEBACK_REPORTED missing invoice_id');
            return response('OK', 200);
        }

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

                    // Billing audit trail.
                    $this->auditWebhook('webhook.chargeback_applied', $user, [
                        'invoice_id'       => $invoiceId,
                        'charged_back_plan'=> $transaction->plan,
                        'amount'           => $transaction->amount,
                    ]);

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

                    // ── External side effects AFTER commit ──────────────
                    $userId = $user->id;
                    \DB::afterCommit(function () use ($userId, $invoiceId) {
                        $user = User::find($userId);
                        if (! $user) {
                            return;
                        }
                        $this->downgradeUserAndCleanupStudioResources($user, 'Chargeback reported');
                        $this->expireStaleSubscription($user);
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

    private function reverseChargeback(Request $request)
    {
        $invoiceId = $request->input('invoice_id');
        if (! $invoiceId) {
            Log::warning('2Checkout: CHARGEBACK_REVERSED missing invoice_id');
            return response('OK', 200);
        }

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

                    $restoredToPlan = null;
                    $restoredBilling = null;
                    $planRank = ['free' => 0, 'pro' => 1, 'studio' => 2];
                    if (($planRank[$user->plan] ?? 0) < ($planRank[$transaction->plan] ?? 0)) {
                        $invoice = \DB::table('invoices')
                            ->where('transaction_id', $transaction->id)
                            ->value('billing_type');
                        $wasSubscription = ($invoice === 'subscription')
                            || ($invoice === null && ! empty($user->subscription_id));

                        $expiresAt = $wasSubscription ? now()->addMonth() : null;

                        $user->forceFill([
                            'plan'            => $transaction->plan,
                            'plan_started_at' => now(),
                            'plan_expires_at' => $expiresAt,
                            ...( $wasSubscription && ! empty($user->subscription_id) ? [
                                'subscription_status'     => 'active',
                                'subscription_ends_at'    => $expiresAt,
                                'subscription_cancelled_at' => null,
                            ] : []),
                        ])->save();

                        Log::info('2Checkout: Plan restored after chargeback reversal', [
                            'user_id'    => $user->id,
                            'invoice_id' => $invoiceId,
                            'to_plan'    => $transaction->plan,
                            'billing'    => $wasSubscription ? 'subscription (finite restore)' : 'one_time (lifetime)',
                        ]);

                        $restoredToPlan = $transaction->plan;
                        $restoredBilling = $wasSubscription ? 'subscription' : 'one_time';
                    }

                    $this->auditWebhook('webhook.chargeback_reversed', $user, [
                        'invoice_id'    => $invoiceId,
                        'restored_plan' => $restoredToPlan,
                        'billing_type'  => $restoredBilling,
                    ]);
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

    private function downgradeUserAndCleanupStudioResources(User $user, string $reason): void
    {
        app(\App\Services\PlanDowngradeService::class)->downgradeToFree($user, $reason);
    }

    private function expireStaleSubscription(User $user): void
    {
        if (! $user->hasSubscription() || ! in_array($user->subscription_status, ['active', 'past_due'], true)) {
            return;
        }

        $user->forceFill([
            'subscription_status'    => 'expired',
            'subscription_ends_at'   => now(),
        ])->save();
    }

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

        // Official 2Checkout INS md5_hash formula:
        // UPPER(MD5(UPPER(MD5(SALE_ID)) . VENDOR_ID . INVOICE_ID . SECRET_WORD))
        $stringToHash = strtoupper(md5((string) $request->input('sale_id', '')))
                      . (string) $request->input('vendor_id', '')
                      . (string) $request->input('invoice_id', '')
                      . $secretWord;

        $calculatedHash = strtoupper(md5($stringToHash));

        if (! hash_equals($calculatedHash, strtoupper((string) $receivedHash))) {
            Log::warning('2Checkout: MD5 hash verification failed', [
                'invoice_id' => $request->input('invoice_id'),
                'sale_id'    => $request->input('sale_id'),
            ]);
            return false;
        }

        // 2Checkout INS does not send a `signature` field, so a valid md5_hash
        // is the authoritative provider mechanism. If a signature IS present
        // (e.g. an edge/proxy injecting one), it must still verify.
        $buyLinkSecret = config('services.2checkout.buy_link_secret_word');
        $receivedSig   = $request->input('signature');

        if ($receivedSig) {
            if (! $buyLinkSecret) {
                Log::error('2Checkout: signature field present but TWOCHECKOUT_BUY_LINK_SECRET_WORD not configured', [
                    'invoice_id' => $request->input('invoice_id'),
                ]);
                return false;
            }

            $calculatedSig = hash_hmac('sha256', $this->build2CheckoutHmacPayload($request), $buyLinkSecret);

            if (! hash_equals($calculatedSig, (string) $receivedSig)) {
                Log::warning('2Checkout: HMAC SHA-256 signature verification failed', [
                    'invoice_id' => $request->input('invoice_id'),
                ]);
                return false;
            }
        }

        // ── IP allowlist (optional, env-gated) ──────────────────────
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
        } elseif (app()->environment('production')) {
            Log::warning('2Checkout: TWOCHECKOUT_WEBHOOK_IP_ALLOWLIST not configured in production. md5_hash is the primary defense; the IP allowlist is recommended defense-in-depth. See 2Checkout merchant docs for INS server IP ranges.', [
                'invoice_id' => $request->input('invoice_id'),
            ]);
        }

        return true;
    }

    private function isDemoNotification(Request $request): bool
    {
        if (strtoupper((string) $request->input('demo', 'N')) !== 'Y') {
            return false;
        }

        Log::info('2Checkout: demo-mode notification ignored', [
            'message_type' => $request->input('message_type'),
            'invoice_id'   => $request->input('invoice_id'),
        ]);

        return true;
    }

    private function cancelSupersededSubscription(User $user, string $previousSubscriptionId): void
    {
        try {
            $response = app(TwoCheckoutApiClient::class)
                ->cancelSubscription($previousSubscriptionId, 'Superseded by a new purchase on the account');

            if (! $response->successful()) {
                Log::error('2Checkout: failed to cancel superseded subscription', [
                    'user_id'         => $user->id,
                    'subscription_id' => $previousSubscriptionId,
                    'status'          => $response->status(),
                ]);
                $this->alertSupersededCancelFailed($user, $previousSubscriptionId);
                return;
            }

            Log::info('2Checkout: superseded subscription cancelled at 2Checkout', [
                'user_id'         => $user->id,
                'subscription_id' => $previousSubscriptionId,
            ]);
        } catch (\Throwable $e) {
            Log::error('2Checkout: exception while cancelling superseded subscription', [
                'user_id'         => $user->id,
                'subscription_id' => $previousSubscriptionId,
                'error'           => $e->getMessage(),
            ]);
            $this->alertSupersededCancelFailed($user, $previousSubscriptionId);
        }
    }

    private function alertSupersededCancelFailed(User $user, string $subscriptionId): void
    {
        try {
            app(\App\Services\OperationalAlertService::class)->alert(
                '2Checkout: superseded subscription still billing',
                "User {$user->id} ({$user->email}) completed a new purchase, but the previous 2Checkout "
                . "subscription {$subscriptionId} could not be cancelled via the API. It may still be billing "
                . 'the customer — cancel it from the 2Checkout merchant dashboard.',
                'warning',
                "superseded-subscription:{$subscriptionId}",
            );
        } catch (\Throwable $e) {
            Log::warning('2Checkout: failed to raise superseded-subscription alert', [
                'subscription_id' => $subscriptionId,
                'error'           => $e->getMessage(),
            ]);
        }
    }

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

    private function handleRecurringSuccess(Request $request)
    {
        $invoiceId = $request->input('invoice_id');
        $saleId    = $request->input('sale_id');
        $productId = $request->input('item_id_1');
        $amount    = $request->input('item_list_amount_1', 0);

        $nextBillingDate = $request->input('item_billing_cycle_next_date');
        $subscriptionId  = $request->input('recurring_order_id') ?? $saleId;

        Log::info('2Checkout: RECURRING_INSTALLMENT_SUCCESS received', [
            'invoice_id'      => $invoiceId,
            'subscription_id' => $subscriptionId,
            'product_id'      => $productId,
            'amount'          => $amount,
            'next_billing'    => $nextBillingDate,
        ]);

        [$user, $matchedBy] = $this->findUserForRecurringEvent($request, $subscriptionId);

        if (! $user) {
            Log::warning('2Checkout: RECURRING_INSTALLMENT_SUCCESS — user not found', [
                'invoice_id'      => $invoiceId,
                'subscription_id' => $subscriptionId,
            ]);
            return response('OK', 200);
        }

        if ($this->recurringEventSubscriptionMismatch($user, $subscriptionId, $matchedBy, $invoiceId)) {
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
                        'dunning_step'         => null,
                        'dunning_last_sent_at' => null,
                    ])->save();

                    // Record the renewal transaction.
                    $transactionId = \DB::table('transactions')->insertGetId([
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

                    // Billing audit trail.
                    $this->auditWebhook('webhook.recurring_renewed', $user, [
                        'invoice_id'      => $invoiceId,
                        'subscription_id' => $subscriptionId,
                        'amount'          => $amount,
                        'access_until'    => $endsAt?->toIso8601String(),
                    ]);

                    // Generate an invoice for this renewal transaction.
                    \DB::afterCommit(function () use ($transactionId, $user) {
                        try {
                            $transaction = \App\Models\Transaction::find($transactionId);
                            if ($transaction) {
                                app(\App\Services\InvoiceGenerator::class)
                                    ->generateForTransaction($transaction, $user, [
                                        'billing_type' => 'subscription',
                                    ]);
                            }
                        } catch (\Throwable $e) {
                            Log::warning('2Checkout: Recurring invoice generation failed', [
                                'transaction_id' => $transactionId,
                                'error'          => $e->getMessage(),
                            ]);
                        }
                    });
                });
            });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            Log::info('2Checkout: Recurring lock busy, deferring', [
                'invoice_id' => $invoiceId,
            ]);
        }

        return response('OK', 200);
    }

    private function handleRecurringFailure(Request $request)
    {
        $invoiceId       = $request->input('invoice_id');
        $subscriptionId  = $request->input('recurring_order_id') ?? $request->input('sale_id');

        Log::warning('2Checkout: RECURRING_INSTALLMENT_FAILED received', [
            'invoice_id'      => $invoiceId,
            'subscription_id' => $subscriptionId,
        ]);

        [$user, $matchedBy] = $this->findUserForRecurringEvent($request, $subscriptionId);

        if (! $user) {
            Log::warning('2Checkout: RECURRING_INSTALLMENT_FAILED — user not found', [
                'subscription_id' => $subscriptionId,
            ]);
            return response('OK', 200);
        }

        if ($this->recurringEventSubscriptionMismatch($user, $subscriptionId, $matchedBy, $invoiceId)) {
            return response('OK', 200);
        }

        $lock = \Illuminate\Support\Facades\Cache::lock("2co:dunning:{$user->id}", 60);

        try {
            $lock->block(5, function () use ($user, $invoiceId, $subscriptionId) {
                $user->refresh();

                $user->forceFill([
                    'subscription_status' => 'past_due',
                ])->save();

                Log::info('2Checkout: Subscription marked past_due', [
                    'user_id'         => $user->id,
                    'subscription_id' => $subscriptionId,
                ]);

                $this->auditWebhook('webhook.recurring_failed', $user, [
                    'invoice_id'      => $invoiceId,
                    'subscription_id' => $subscriptionId,
                ]);

                if (! $user->dunning_step || $user->dunning_step < 1) {
                    $user->forceFill([
                        'dunning_step'         => 1,
                        'dunning_last_sent_at' => now(),
                    ])->save();

                    try {
                        \Illuminate\Support\Facades\Mail::to($user->email)
                            ->send(new \App\Mail\DunningEmail($user, 1));

                        Log::info('Dunning: sent step 1 email (immediate)', [
                            'user_id' => $user->id,
                        ]);
                    } catch (\Throwable $e) {
                        Log::warning('Dunning: step 1 email send failed', [
                            'user_id' => $user->id,
                            'error'   => $e->getMessage(),
                        ]);
                    }

                    \App\Services\NotificationService::create(
                        $user,
                        'dunning',
                        'Subscription payment failed',
                        'Your recent payment for the ' . ucfirst($user->plan) . ' plan failed. Please update your payment method to avoid losing access.',
                        '/billing',
                        'Update payment method'
                    );
                }
            });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            Log::info('2Checkout: dunning lock busy, another worker is handling this failure', [
                'user_id'    => $user->id,
                'invoice_id' => $invoiceId,
            ]);
        }

        return response('OK', 200);
    }

    private function handleRecurringCancelled(Request $request)
    {
        $invoiceId      = $request->input('invoice_id');
        $subscriptionId = $request->input('recurring_order_id') ?? $request->input('sale_id');

        Log::info('2Checkout: RECURRING_ORDER_CANCELLED received', [
            'subscription_id' => $subscriptionId,
        ]);

        [$user, $matchedBy] = $this->findUserForRecurringEvent($request, $subscriptionId);

        if (! $user) {
            Log::warning('2Checkout: RECURRING_ORDER_CANCELLED — user not found', [
                'subscription_id' => $subscriptionId,
            ]);
            return response('OK', 200);
        }

        if ($this->recurringEventSubscriptionMismatch($user, $subscriptionId, $matchedBy, $invoiceId)) {
            return response('OK', 200);
        }

        $user->forceFill([
            'subscription_status'      => 'cancelled',
            'subscription_cancelled_at' => now(),
        ])->save();

        Log::info('2Checkout: Subscription cancelled', [
            'user_id'         => $user->id,
            'subscription_id' => $subscriptionId,
            'ends_at'         => $user->subscription_ends_at?->toIso8601String(),
        ]);

        // Billing audit trail.
        $this->auditWebhook('webhook.recurring_cancelled', $user, [
            'subscription_id' => $subscriptionId,
            'access_until'    => $user->subscription_ends_at?->toIso8601String(),
        ]);

        // Create in-app notification for the cancellation
        \App\Services\NotificationService::create(
            $user,
            'subscription',
            'Subscription cancelled',
            'Your ' . ucfirst($user->plan) . ' subscription has been cancelled. You\'ll keep access until ' . ($user->subscription_ends_at?->format('M j, Y') ?? 'the end of your billing period') . '.',
            '/billing',
            'View billing'
        );

        return response('OK', 200);
    }

    private function findUserForRecurringEvent(Request $request, ?string $subscriptionId): array
    {
        if ($subscriptionId) {
            $user = User::where('subscription_id', $subscriptionId)->first();
            if ($user) {
                return [$user, 'subscription_id'];
            }
        }

        $customerEmail = $request->input('customer_email');
        if ($customerEmail && filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            $user = User::where('email', $customerEmail)->first();
            if ($user) {
                return [$user, 'customer_email'];
            }
        }

        return [null, 'none'];
    }

    private function recurringEventSubscriptionMismatch(User $user, ?string $subscriptionId, string $matchedBy, ?string $invoiceId): bool
    {
        if ($matchedBy !== 'customer_email' || empty($subscriptionId)) {
            return false;
        }

        if (empty($user->subscription_id) || $user->subscription_id === $subscriptionId) {
            return false;
        }

        Log::warning('2Checkout: recurring event belongs to a replaced subscription — skipping', [
            'invoice_id'            => $invoiceId,
            'event_subscription_id' => $subscriptionId,
            'local_subscription_id' => $user->subscription_id,
            'user_id'               => $user->id,
        ]);

        return true;
    }

    private function storablePayload(Request $request): ?string
    {
        try {
            $json = json_encode($request->all());
        } catch (\Throwable) {
            return null;
        }
        if ($json === false || strlen($json) > 256 * 1024) {
            Log::warning('2Checkout: webhook payload not persisted (empty or oversized)', [
                'invoice_id' => $request->input('invoice_id'),
            ]);
            return null;
        }
        return $json;
    }

    private function claimExistingWebhook(string $messageId, string $messageType): bool
    {
        try {
            $claimed = \DB::table('processed_webhooks')
                ->where('message_id', $messageId)
                ->where('message_type', $messageType)
                ->where(function ($q) {
                    $q->where('status', 'failed')
                        ->orWhere(function ($q2) {
                            $q2->where('status', 'processing')
                                ->where('updated_at', '<', now()->subMinutes(10));
                        });
                })
                ->update(['status' => 'processing', 'updated_at' => now()]);

            return (bool) $claimed;
        } catch (\Throwable $e) {
            Log::warning('2Checkout: failed to inspect ledger row for claim', [
                'message_id'   => $messageId,
                'message_type' => $messageType,
                'error'        => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function markWebhookFailed(?string $messageId, ?string $messageType): void
    {
        if (! $messageId || ! $messageType) {
            return; // Replay context or payload lacked the fields — nothing to mark.
        }

        try {
            \DB::table('processed_webhooks')
                ->where('message_id', $messageId)
                ->where('message_type', $messageType)
                ->update(['status' => 'failed', 'updated_at' => now()]);
        } catch (\Throwable $e) {
            Log::warning('2Checkout: failed to mark ledger row failed after processing error', [
                'message_id'   => $messageId,
                'message_type' => $messageType,
                'error'        => $e->getMessage(),
            ]);
        }
    }

    private function finalizeWebhook(?string $messageId, ?string $messageType, $response): void
    {
        if (! $messageId || ! $messageType) {
            return;
        }

        $status = $response instanceof \Illuminate\Http\Response ? $response->status() : 200;

        try {
            \DB::table('processed_webhooks')
                ->where('message_id', $messageId)
                ->where('message_type', $messageType)
                ->update([
                    'status'       => $status >= 500 ? 'failed' : 'processed',
                    'processed_at' => now(),
                    'updated_at'   => now(),
                ]);
        } catch (\Throwable $e) {
            Log::warning('2Checkout: failed to finalize ledger row', [
                'message_id' => $messageId,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    private function auditWebhook(string $action, ?User $user, array $payload = []): void
    {
        if (! $user) {
            return;
        }

        try {
            \App\Models\AdminAuditLog::record($action, $user, $payload);
        } catch (\Throwable $e) {
            Log::warning('2Checkout: webhook audit record failed', [
                'action' => $action,
                'error'  => $e->getMessage(),
            ]);
        }
    }
}
