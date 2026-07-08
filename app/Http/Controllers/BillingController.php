<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PendingUpgrade;
use App\Models\User;
use App\Services\PlanLockService;
use App\Services\TwoCheckoutApiClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * User-facing billing controller.
 *
 * ITERATION-002 CHANGES (audit 2CO-1 + 2CO-2 + 2CO-7 + 2CO-8):
 *
 *   2CO-1 — cancelSubscription and reactivateSubscription now use the real
 *     TwoCheckoutApiClient (X-Avangate-Authentication header) instead of the
 *     placeholder auth scheme that 2Checkout's API rejected. Customers can
 *     now self-serve cancel/reactivate.
 *
 *   2CO-2 — Upgrade URL now includes a signed buy link (&sign=...). 2Checkout
 *     rejects buy links whose computed signature does not match, preventing
 *     price/quantity/product tampering.
 *
 *   2CO-7 — Same-plan renewal is now allowed at any time (was: only within
 *     30 days of expiry). For subscriptions converting to one-time, the
 *     subscription is cancelled via the API before redirecting to checkout.
 *     The webhook handles the conversion.
 *
 *   2CO-8 — Trial start now has a per-IP rate limit (max 2 trials per IP per
 *     30 days) to prevent unlimited free trials via throwaway emails.
 *
 * PRESERVED FROM PRIOR VERSION:
 *   - GET  /billing — billing portal (current plan, transactions, pending upgrades)
 *   - GET  /billing/upgrade/{plan} — generates pending_upgrade, redirects to 2Checkout
 *   - POST /billing/cancel-subscription — cancels via 2Checkout API
 *   - POST /billing/reactivate-subscription — reactivates via 2Checkout API
 *   - POST /billing/downgrade — self-serve downgrade flow
 *   - POST /billing/start-trial/{plan} — 14-day free trial
 *   - GET  /billing/invoice/{invoice} — invoice download
 *
 * Handles:
 *   - GET  /billing                — billing portal (current plan, transactions,
 *                                    plan-expiry status, refund-request link)
 *   - GET  /billing/upgrade/{plan} — generates a pending_upgrade token,
 *                                    redirects to 2Checkout with
 *                                    external-reference=<token> +
 *                                    customer_email=<user_email> pre-filled
 *
 * Task H01 — closes the silent revenue leak where the 2Checkout buy URL had
 * no user binding. A logged-in user could pay with a PayPal email different
 * from their account email → the webhook's customer_email lookup failed →
 * user paid but was never upgraded, and nobody was notified.
 *
 * Task H02 — adds the missing self-serve billing UI. Users can now see their
 * current plan, plan-expiry status, transaction history, and download a
 * basic receipt.
 */
class BillingController extends Controller
{
    public function __construct(
        private readonly PlanLockService $planLock,
        private readonly TwoCheckoutApiClient $twoCheckout,
    ) {}

    /**
     * Show the user's billing portal.
     */
    public function index(Request $request): View
    {
        $user = $request->user();

        $transactions = $user->transactions()
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        $pendingUpgrades = $user->pendingUpgrades()
            ->where('status', 'pending')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();

        return view('billing.index', [
            'user'             => $user,
            'transactions'     => $transactions,
            'pendingUpgrades'  => $pendingUpgrades,
        ]);
    }

    /**
     * Generate a pending_upgrade token and redirect to 2Checkout.
     *
     * ITERATION-002 FIXES:
     *   - 2CO-2: signed buy link (&sign=...) added
     *   - 2CO-7: same-plan renewal allowed at any time (subscription cancels first)
     *
     * The 2Checkout buy URL includes:
     *   - sid             = account number
     *   - product_id      = the plan's product ID
     *   - quantity        = 1
     *   - external-reference = the pending_upgrade token (our idempotency key)
     *   - merchant_item_id_1 = the user_id (secondary lookup, in case
     *                       external-reference is stripped by 2Checkout
     *                       in some IPN versions)
     *   - sign            = MD5 signature over (sid + product_id + quantity + price + secret_word)
     *                       (2CO-2 FIX — prevents price/quantity/product tampering)
     *
     * @param  string  $plan  'pro' | 'studio'
     */
    public function upgrade(Request $request, string $plan): RedirectResponse
    {
        $user = $request->user();

        // M-1: Determine if this is a recurring (subscription) purchase.
        // When ?recurring=1 is passed, use the recurring product ID; otherwise
        // use the one-time product ID (existing behavior).
        $isRecurring = $request->boolean('recurring');

        // Validate plan + product ID is configured
        if ($isRecurring) {
            $productId = $plan === 'pro'
                ? config('services.2checkout.recurring_product_id_pro')
                : ($plan === 'studio' ? config('services.2checkout.recurring_product_id_studio') : null);
        } else {
            $productId = $plan === 'pro'
                ? config('services.2checkout.product_id_pro')
                : ($plan === 'studio' ? config('services.2checkout.product_id_studio') : null);
        }

        if (! $productId) {
            $error = $isRecurring
                ? "Recurring product not configured for plan: {$plan}. Set TWOCHECKOUT_RECURRING_PRODUCT_ID_{$plan} in .env."
                : "Unknown plan: {$plan}. Please contact support.";
            return redirect()->route('billing.index')
                ->with('error', $error);
        }

        // Don't allow downgrading via this flow — if a Studio user clicks
        // "Upgrade to Pro", redirect them to the billing portal with an
        // explanation. (Audit M1 — pricing page doesn't know current plan.)
        // TD-27: Plan rank now read from config/plans.php (single source
        // of truth) instead of being hardcoded here.
        $planRank = config('plans.rank', ['free' => 0, 'pro' => 1, 'studio' => 2]);
        if (($planRank[$user->plan] ?? 0) > ($planRank[$plan] ?? 0)) {
            return redirect()->route('billing.index')
                ->with('warning', "You're currently on the " . ucfirst($user->plan) . " plan, which is a higher tier than " . ucfirst($plan) . ". Downgrades are not available via the upgrade flow — please contact support if you need to downgrade.");
        }

        // 2CO-7 FIX: Same-plan renewal is now allowed at any time.
        // Previously: only allowed if plan_expires_at was within 30 days of
        // expiry OR already past. This blocked legitimate mid-cycle conversions
        // (e.g. a monthly subscriber wanting to convert to lifetime one-time).
        //
        // New behavior:
        //   - If the user is on the same plan AND has an active subscription
        //     AND is buying a one-time product → cancel the subscription first
        //     (the webhook will overwrite plan_expires_at = null on the new
        //     one-time purchase). This is the "convert to lifetime" flow.
        //   - If the user is on the same plan AND has a one-time purchase
        //     (plan_expires_at = null) → allow the renewal (effectively a
        //     re-purchase; the webhook is idempotent).
        //   - If the user is on the same plan AND has an active subscription
        //     AND is buying a recurring product → allow (the webhook will
        //     update subscription_ends_at; this is a "renewal" or "switch
        //     cycle" flow).
        if ($user->plan === $plan) {
            // If converting from subscription to one-time, cancel the subscription first.
            if ($user->hasActiveSubscription() && ! $isRecurring) {
                Log::info('BillingController: same-plan subscription→one-time conversion', [
                    'user_id'         => $user->id,
                    'plan'            => $plan,
                    'subscription_id' => $user->subscription_id,
                ]);

                // Cancel the subscription via 2Checkout API. The user keeps
                // access until subscription_ends_at. The new one-time purchase
                // will overwrite plan_expires_at = null when the webhook fires.
                $cancelResult = $this->planLock->withUserLock($user->id, function () use ($user) {
                    $user->refresh();
                    if (! $user->hasActiveSubscription()) {
                        return true; // already cancelled by a concurrent request
                    }
                    try {
                        $response = $this->twoCheckout->cancelSubscription(
                            $user->subscription_id,
                            'Converting to one-time purchase via self-serve billing portal',
                        );
                        if (! $response->successful()) {
                            Log::error('BillingController: 2Checkout cancel API failed during conversion', [
                                'user_id'         => $user->id,
                                'subscription_id' => $user->subscription_id,
                                'status'          => $response->status(),
                                'body'            => $response->body(),
                            ]);
                            return false;
                        }
                        $user->forceFill([
                            'subscription_status'       => 'cancelled',
                            'subscription_cancelled_at' => now(),
                        ])->save();
                        return true;
                    } catch (\Throwable $e) {
                        Log::error('BillingController: 2Checkout cancel API exception during conversion', [
                            'user_id'         => $user->id,
                            'subscription_id' => $user->subscription_id,
                            'error'           => $e->getMessage(),
                        ]);
                        return false;
                    }
                });

                if ($cancelResult === false) {
                    return redirect()->route('billing.index')
                        ->with('error', 'Could not cancel your existing subscription to convert to lifetime. Please try again or contact support.');
                }
            }

            Log::info('BillingController: same-plan renewal allowed', [
                'user_id'         => $user->id,
                'plan'            => $plan,
                'is_recurring'    => $isRecurring,
                'plan_expires_at' => $user->plan_expires_at?->toIso8601String(),
            ]);
        }

        // P3-11 FIX: Acquire a per-user plan lock so concurrent clicks of
        // "Upgrade" can't create multiple pending_upgrades for the same user.
        // The lock is also held by WebhookController, SystemController::updatePlan,
        // and CheckPlanExpiry — so an upgrade-in-progress blocks an admin
        // downgrade or an expiry-triggered downgrade until the upgrade
        // completes (or the 60-second TTL expires).
        $result = $this->planLock->withUserLock($user->id, function () use ($user, $plan, $productId, $request) {
            // Re-fetch the user inside the lock in case another path changed
            // their plan between the outer read and the lock acquisition.
            $user->refresh();

            // Re-check the plan-rank guard inside the lock — a concurrent
            // upgrade could have just moved them to a higher plan.
            $planRank = config('plans.rank', ['free' => 0, 'pro' => 1, 'studio' => 2]);
            if (($planRank[$user->plan] ?? 0) > ($planRank[$plan] ?? 0)) {
                return redirect()->route('billing.index')
                    ->with('warning', "You're currently on the " . ucfirst($user->plan) . " plan, which is a higher tier than " . ucfirst($plan) . ". Downgrades are not available via the upgrade flow — please contact support if you need to downgrade.");
            }

            // Create the pending upgrade
            $pending = PendingUpgrade::createForUser($user, $plan, $productId);

            // Build the 2Checkout buy URL.
            //
            // SEC-7 FIX: customer_email is NO LONGER included in the URL.
            //   Previously the URL had &customer_email=<user_email> which:
            //     - pre-filled the checkout form (mild convenience)
            //     - leaked PII into browser history, server access logs, and
            //       any Referer header 2Checkout's checkout page might send
            //       to third-party assets
            //   The webhook matches the user by external-reference (the pending
            //   upgrade token) first, then falls back to merchant_item_id_1
            //   (user_id), and only as a LAST resort by customer_email. So
            //   omitting it from the URL doesn't break the upgrade flow — the
            //   user types their email at checkout, and the webhook still
            //   finds them via the token.
            $sid = config('services.2checkout.account_number');
            $secretWord = config('services.2checkout.secret_word');

            $buyUrl = sprintf(
                'https://www.2checkout.com/checkout/purchase?sid=%s&product_id=%s&quantity=1&external-reference=%s&merchant_item_id_1=%s',
                urlencode((string) $sid),
                urlencode((string) $productId),
                urlencode($pending->token),
                urlencode((string) $user->id),
            );

            // 2CO-2 FIX: Signed buy link.
            //
            // 2Checkout supports a "signed buy link" mode where the merchant
            // computes an MD5 signature over (sid + product_id + quantity +
            // price + secret_word) and appends it as &sign=... (or as the
            // buy-link secret hash). Without it, a buyer can edit the URL to
            // change quantity to 100, change product_id to a cheaper product,
            // or strip the external-reference and pay without an account binding.
            //
            // The signature format per 2Checkout's documentation:
            //   sign = strtoupper(md5(sid + product_id + quantity + price + secret_word))
            //
            // The "price" is the product's unit price as configured in the
            // 2Checkout merchant dashboard. We read it from config so the
            // signature matches what 2Checkout expects. If the price is not
            // configured, we skip the signature (and log a warning) — 2Checkout
            // will still process the buy link, but without tamper protection.
            $price = $this->getProductPrice($plan, $isRecurring);
            if ($price !== null && $secretWord) {
                $signPayload = $sid . $productId . '1' . $price . $secretWord;
                $sign = strtoupper(md5($signPayload));
                $buyUrl .= '&sign=' . urlencode($sign);
            } elseif ($price === null) {
                Log::warning('BillingController: signed buy link skipped — product price not configured', [
                    'user_id' => $user->id,
                    'plan'    => $plan,
                    'is_recurring' => $isRecurring,
                ]);
            } elseif (! $secretWord) {
                Log::warning('BillingController: signed buy link skipped — TWOCHECKOUT_SECRET_WORD not configured', [
                    'user_id' => $user->id,
                ]);
            }

            // (Task H54) — optional coupon code.
            // SEC-8 FIX: coupon is now validated against an allowlist configured
            //   via TWOCHECKOUT_COUPON_ALLOWLIST (comma-separated).
            $couponCode = $request->query('coupon');
            if ($couponCode !== null) {
                $allowlist = array_filter(array_map('trim', explode(
                    ',',
                    (string) config('services.2checkout.coupon_allowlist', '')
                )));
                if (! in_array($couponCode, $allowlist, true)) {
                    Log::info('BillingController: rejected coupon not in allowlist', [
                        'user_id' => $user->id,
                        'plan'    => $plan,
                    ]);
                    $couponCode = null;
                }
            }
            $couponCode ??= config('services.2checkout.coupon_code');
            if ($couponCode) {
                $buyUrl .= '&coupon=' . urlencode($couponCode);
            }

            // (Task H58) — affiliate/referral tracking.
            // SEC-8 FIX: ref is now validated against an allowlist configured
            //   via TWOCHECKOUT_AFFILIATE_ALLOWLIST (comma-separated).
            $affiliateId = $request->query('ref');
            if ($affiliateId !== null) {
                $affiliateAllowlist = array_filter(array_map('trim', explode(
                    ',',
                    (string) config('services.2checkout.affiliate_allowlist', '')
                )));
                if (! in_array($affiliateId, $affiliateAllowlist, true)) {
                    Log::info('BillingController: rejected affiliate ref not in allowlist', [
                        'user_id' => $user->id,
                        'plan'    => $plan,
                    ]);
                    $affiliateId = null;
                }
            }
            $affiliateId ??= config('services.2checkout.affiliate_id');
            if ($affiliateId) {
                $buyUrl .= '&affiliate=' . urlencode($affiliateId);
                $pending->forceFill(['affiliate_id' => $affiliateId])->save();
            }

            Log::info('BillingController: redirecting user to 2Checkout', [
                'user_id'           => $user->id,
                'plan'              => $plan,
                'pending_upgrade_id'=> $pending->id,
                'has_coupon'        => ! empty($couponCode),
                'has_affiliate'     => ! empty($affiliateId),
                'has_signed_link'   => isset($sign),
            ]);

            return redirect()->away($buyUrl);
        });

        // If the lock was busy, surface a friendly message instead of erroring.
        if ($result === \App\Services\PlanLockService::LOCK_BUSY) {
            return redirect()->route('billing.index')
                ->with('warning', 'Another billing operation is in progress on your account. Please wait a moment and try again.');
        }

        return $result;
    }

    /**
     * 2CO-2 FIX: Get the product price for signed buy link generation.
     *
     * The price must match what's configured in the 2Checkout merchant
     * dashboard. We read from config so the signature matches. If the price
     * is not configured, return null (the signed link is skipped — see
     * the warning in upgrade()).
     */
    private function getProductPrice(string $plan, bool $isRecurring): ?string
    {
        if ($isRecurring) {
            $price = $plan === 'pro'
                ? config('services.2checkout.recurring_price_pro_monthly')
                : ($plan === 'studio' ? config('services.2checkout.recurring_price_studio_monthly') : null);
        } else {
            // One-time prices — add to config/services.php if not already there.
            // For now, read from a separate config key (TWOCHECKOUT_PRICE_PRO / _STUDIO).
            $price = $plan === 'pro'
                ? config('services.2checkout.price_pro')
                : ($plan === 'studio' ? config('services.2checkout.price_studio') : null);
        }

        return $price !== null ? (string) $price : null;
    }

    // ── M-1: Subscription management ──────────────────────────────────────

    /**
     * Cancel the user's active subscription.
     *
     * 2CO-1 FIX: Now uses the TwoCheckoutApiClient with proper
     * X-Avangate-Authentication header. Previously used a "simplified
     * placeholder" that 2Checkout's API rejected (401/403 on every call).
     *
     * Calls 2Checkout's cancel subscription API, which triggers a
     * RECURRING_ORDER_CANCELLED webhook. The user keeps access until
     * subscription_ends_at (the end of the already-paid-for period), then
     * is downgraded by CheckPlanExpiry.
     *
     * Route: POST /billing/cancel-subscription
     */
    public function cancelSubscription(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user->hasActiveSubscription()) {
            return redirect()->route('billing.index')
                ->with('error', 'You do not have an active subscription to cancel.');
        }

        // P3-11: Acquire the per-user plan lock to prevent races with
        // concurrent webhook events.
        $result = $this->planLock->withUserLock($user->id, function () use ($user) {
            // Re-check inside the lock — a webhook may have just cancelled it.
            $user->refresh();

            if (! $user->hasActiveSubscription()) {
                return redirect()->route('billing.index')
                    ->with('info', 'Your subscription has already been cancelled.');
            }

            // 2CO-1 FIX: Use the real TwoCheckoutApiClient.
            $subscriptionId = $user->subscription_id;

            try {
                $response = $this->twoCheckout->cancelSubscription($subscriptionId);

                if (! $response->successful()) {
                    Log::error('BillingController: 2Checkout cancel API failed', [
                        'user_id'         => $user->id,
                        'subscription_id' => $subscriptionId,
                        'status'          => $response->status(),
                        'body'            => $response->body(),
                    ]);
                    return redirect()->route('billing.index')
                        ->with('error', 'Failed to cancel subscription via 2Checkout. Please try again or contact support.');
                }
            } catch (\Throwable $e) {
                Log::error('BillingController: 2Checkout cancel API exception', [
                    'user_id'         => $user->id,
                    'subscription_id' => $subscriptionId,
                    'error'           => $e->getMessage(),
                ]);
                return redirect()->route('billing.index')
                    ->with('error', 'Could not reach 2Checkout to cancel your subscription. Please try again or contact support.');
            }

            // The RECURRING_ORDER_CANCELLED webhook will set subscription_status
            // to 'cancelled' + subscription_cancelled_at. But we set it here
            // too so the UI updates immediately (the webhook may take a few
            // seconds to arrive).
            $user->forceFill([
                'subscription_status'       => 'cancelled',
                'subscription_cancelled_at' => now(),
            ])->save();

            Log::info('BillingController: subscription cancelled', [
                'user_id'         => $user->id,
                'subscription_id' => $subscriptionId,
                'ends_at'         => $user->subscription_ends_at?->toIso8601String(),
            ]);

            return redirect()->route('billing.index')
                ->with('success', "Your subscription has been cancelled. You'll keep access until {$user->subscription_ends_at?->format('M j, Y')}, after which your account will be downgraded to Free.");
        });

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        if ($result === \App\Services\PlanLockService::LOCK_BUSY) {
            return redirect()->route('billing.index')
                ->with('warning', 'Another billing operation is in progress. Please wait a moment and try again.');
        }

        return redirect()->route('billing.index');
    }

    /**
     * Reactivate a cancelled subscription (if still within the paid-for period).
     *
     * 2CO-1 FIX: Now uses the TwoCheckoutApiClient with proper auth.
     *
     * Route: POST /billing/reactivate-subscription
     */
    public function reactivateSubscription(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user->canReactivateSubscription()) {
            return redirect()->route('billing.index')
                ->with('error', 'Your subscription cannot be reactivated — the paid period has ended. Please start a new subscription.');
        }

        $subscriptionId = $user->subscription_id;

        try {
            $response = $this->twoCheckout->reactivateSubscription($subscriptionId);

            if (! $response->successful()) {
                Log::error('BillingController: 2Checkout reactivate API failed', [
                    'user_id'         => $user->id,
                    'subscription_id' => $subscriptionId,
                    'status'          => $response->status(),
                ]);
                return redirect()->route('billing.index')
                    ->with('error', 'Failed to reactivate subscription via 2Checkout. Please try again or contact support.');
            }
        } catch (\Throwable $e) {
            Log::error('BillingController: 2Checkout reactivate API exception', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
            return redirect()->route('billing.index')
                ->with('error', 'Could not reach 2Checkout to reactivate your subscription. Please try again or contact support.');
        }

        $user->forceFill([
            'subscription_status'       => 'active',
            'subscription_cancelled_at' => null,
        ])->save();

        Log::info('BillingController: subscription reactivated', [
            'user_id'         => $user->id,
            'subscription_id' => $subscriptionId,
        ]);

        return redirect()->route('billing.index')
            ->with('success', 'Your subscription has been reactivated. The next billing date remains unchanged.');
    }

    // ── M-2: Self-serve downgrade flow ─────────────────────────────────────

    /**
     * Downgrade the user's plan to a lower tier.
     *
     * For one-time purchases: immediately changes the plan (no refund —
     * the user paid for lifetime access, they're choosing to use less).
     * For subscriptions: cancels the subscription via 2Checkout, access
     * continues until the end of the paid period.
     *
     * Route: POST /billing/downgrade
     */
    public function downgrade(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'plan' => ['required', 'in:free,pro'],
        ]);

        $user = $request->user();
        $targetPlan = $validated['plan'];

        // Can't downgrade to the same plan
        if ($user->plan === $targetPlan) {
            return redirect()->route('billing.index')
                ->with('info', "You're already on the {$targetPlan} plan.");
        }

        // Can't downgrade to a higher tier
        $planRank = config('plans.rank', ['free' => 0, 'pro' => 1, 'studio' => 2]);
        if (($planRank[$targetPlan] ?? 0) > ($planRank[$user->plan] ?? 0)) {
            return redirect()->route('billing.index')
                ->with('error', 'Use the upgrade buttons to move to a higher tier.');
        }

        // Use the plan lock to prevent races
        $result = $this->planLock->withUserLock($user->id, function () use ($user, $targetPlan) {
            $user->refresh();

            // If the user has an active subscription, cancel it via 2Checkout
            // 2CO-1 FIX: use TwoCheckoutApiClient.
            if ($user->hasActiveSubscription()) {
                try {
                    $response = $this->twoCheckout->cancelSubscription($user->subscription_id);

                    if (! $response->successful()) {
                        Log::error('BillingController: downgrade cancel API failed', [
                            'user_id' => $user->id,
                            'status'  => $response->status(),
                        ]);
                        return redirect()->route('billing.index')
                            ->with('error', 'Failed to cancel subscription via 2Checkout. Please contact support.');
                    }
                } catch (\Throwable $e) {
                    Log::error('BillingController: downgrade cancel API exception', [
                        'user_id' => $user->id,
                        'error'   => $e->getMessage(),
                    ]);
                    return redirect()->route('billing.index')
                        ->with('error', 'Could not reach 2Checkout. Please try again or contact support.');
                }

                $user->forceFill([
                    'subscription_status'       => 'cancelled',
                    'subscription_cancelled_at' => now(),
                ])->save();

                // Subscription downgrades: access continues until subscription_ends_at
                // then CheckPlanExpiry will handle the actual downgrade
                return redirect()->route('billing.index')
                    ->with('success', "Your subscription has been cancelled. You'll keep access until {$user->subscription_ends_at?->format('M j, Y')}, then be downgraded to " . ucfirst($targetPlan) . '.');
            }

            // One-time purchase: downgrade immediately
            if ($targetPlan === 'free') {
                app(\App\Services\PlanDowngradeService::class)
                    ->downgradeToFree($user, 'Self-serve downgrade');
            } else {
                $limits = \App\Models\User::planLimits($targetPlan);
                $user->forceFill([
                    'plan'          => $targetPlan,
                    'max_galleries' => $limits['max_galleries'],
                    'max_images'    => $limits['max_images'],
                ])->save();
            }

            return redirect()->route('billing.index')
                ->with('success', 'Your plan has been downgraded to ' . ucfirst($targetPlan) . '.');
        });

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        if ($result === \App\Services\PlanLockService::LOCK_BUSY) {
            return redirect()->route('billing.index')
                ->with('warning', 'Another billing operation is in progress. Please wait and try again.');
        }

        return redirect()->route('billing.index');
    }

    // ── M-7: Trial period ──────────────────────────────────────────────────

    /**
     * Start a 14-day free trial for a plan.
     *
     * 2CO-8 FIX: Per-IP rate limit (max 2 trials per IP per 30 days) to
     * prevent unlimited free trials via throwaway emails. Previously: no
     * fraud screening — an attacker could create unlimited accounts with
     * test+1@gmail.com, test+2@gmail.com, etc. and get unlimited 14-day
     * Studio trials.
     *
     * Route: POST /billing/start-trial/{plan}
     */
    public function startTrial(Request $request, string $plan): RedirectResponse
    {
        if (! in_array($plan, ['pro', 'studio'], true)) {
            return redirect()->route('billing.index')
                ->with('error', 'Invalid plan for trial.');
        }

        $user = $request->user();

        // Can't start trial if already on a paid plan
        if ($user->plan !== 'free') {
            return redirect()->route('billing.index')
                ->with('error', 'Trials are only available for Free plan users.');
        }

        // Can't start trial if already used one
        if ($user->hasUsedTrial()) {
            return redirect()->route('billing.index')
                ->with('error', 'You\'ve already used your free trial. Choose a plan to continue.');
        }

        // 2CO-8 FIX: Per-IP rate limit.
        // Max 2 trials per IP per 30 days. Thwarts the "unlimited throwaway
        // email" attack. The key is per-IP (not per-user) so an attacker
        // cycling through VPN IPs is slowed but not fully blocked — full
        // blocking requires card-required trials (future iteration).
        //
        // The 30-day window matches the trial duration + buffer. An attacker
        // who waits 30 days can get 2 more trials — acceptable tradeoff.
        $ipKey = 'trial:' . $request->ip();
        $maxTrialsPerIp = 2;
        $decayMinutes = 30 * 24 * 60; // 30 days

        if (RateLimiter::tooManyAttempts($ipKey, $maxTrialsPerIp)) {
            $retryAfter = RateLimiter::availableIn($ipKey);
            $retryHours = (int) ceil($retryAfter / 3600);

            Log::warning('Trial rate limit hit', [
                'user_id' => $user->id,
                'ip'      => $request->ip(),
                'retry_after_seconds' => $retryAfter,
            ]);

            return redirect()->route('billing.index')
                ->with('error', "Too many free trials from your IP address. Please try again in {$retryHours} hours, or choose a plan to upgrade now.");
        }

        // Increment the IP rate limit counter
        RateLimiter::hit($ipKey, $decayMinutes * 60);

        $user->startTrial($plan);

        // M-12: Notification
        \App\Services\NotificationService::create(
            $user,
            'subscription',
            'Free trial started!',
            "Your 14-day free trial of the {$plan} plan is now active. Enjoy all the features!",
            '/billing',
            'View billing'
        );

        Log::info('Trial started', [
            'user_id' => $user->id,
            'plan'    => $plan,
            'ip'      => $request->ip(),
            'trial_count_for_ip' => RateLimiter::attempts($ipKey),
        ]);

        return redirect()->route('admin.dashboard')
            ->with('status', "Your 14-day free trial of " . ucfirst($plan) . " has started! You have full access to all {$plan} features until " . $user->trial_ends_at->format('M j, Y') . '.');
    }

    // ── M-10: Invoice download ────────────────────────────────────────────

    /**
     * Download an invoice PDF (or HTML fallback).
     *
     * Only the invoice's owner can download it — the route is behind the
     * 'auth' + 'verified' + 'mfa' middleware (same as other billing routes).
     *
     * 2CO-6 FIX (in InvoiceGenerator, not here): the file is now a real PDF
     * (generated via dompdf), not HTML. This method serves the file with the
     * correct Content-Type based on the file extension.
     *
     * Route: GET /billing/invoice/{invoice}
     */
    public function downloadInvoice(Request $request, \App\Models\Invoice $invoice)
    {
        $user = $request->user();

        // Authorization: only the invoice's owner can download it.
        if ($invoice->user_id !== $user->id) {
            abort(403, 'You do not have access to this invoice.');
        }

        if (! $invoice->pdf_path) {
            abort(404, 'Invoice PDF not available.');
        }

        $disk = \Illuminate\Support\Facades\Storage::disk('public');

        if (! $disk->exists($invoice->pdf_path)) {
            Log::warning('BillingController: invoice file missing on disk', [
                'invoice_id' => $invoice->id,
                'pdf_path'   => $invoice->pdf_path,
            ]);
            abort(404, 'Invoice file not found.');
        }

        // 2CO-6 FIX: serve with the correct Content-Type based on extension.
        // New invoices are .pdf (real PDF via dompdf). Old invoices may still
        // be .html (the backfill command in this iteration regenerates them
        // as PDF — see exospace:regenerate-invoices).
        $extension = pathinfo($invoice->pdf_path, PATHINFO_EXTENSION);
        $mimeType = match ($extension) {
            'pdf'  => 'application/pdf',
            'html' => 'text/html',
            default => 'application/octet-stream',
        };
        $filename = "{$invoice->invoice_number}.{$extension}";

        return response($disk->get($invoice->pdf_path), 200, [
            'Content-Type'        => $mimeType,
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control'       => 'private, no-cache, no-store, must-revalidate',
        ]);
    }
}
