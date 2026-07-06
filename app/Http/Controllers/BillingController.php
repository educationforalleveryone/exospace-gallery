<?php

namespace App\Http\Controllers;

use App\Models\PendingUpgrade;
use App\Models\User;
use App\Services\PlanLockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * User-facing billing controller.
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
     * The 2Checkout buy URL includes:
     *   - sid             = account number
     *   - product_id      = the plan's product ID
     *   - quantity        = 1
     *   - external-reference = the pending_upgrade token (our idempotency key)
     *   - customer_email  = the user's account email (pre-filled, but the
     *                       user can change it at checkout — the webhook
     *                       matches by external-reference first)
     *   - merchant_item_id_1 = the user_id (secondary lookup, in case
     *                       external-reference is stripped by 2Checkout
     *                       in some IPN versions)
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

        // If the user is already on this plan, redirect with an info message
        // — UNLESS their plan is expiring within 30 days, in which case we
        // allow the "upgrade" as a renewal. The webhook will re-grant the
        // plan with plan_expires_at = null (lifetime), so paying again
        // effectively extends/replaces the expiring plan.
        // CONV-5 FIX: Previously users with an expiring paid plan could not
        // renew via the self-serve flow — they were blocked by this check
        // and had to email support. The plan-expiring email now deep-links
        // to /billing/upgrade/{current_plan}, which only works if we allow
        // same-plan upgrades when the plan is near expiry.
        if ($user->plan === $plan) {
            $canRenew = $user->plan_expires_at
                && $user->plan_expires_at->isPast()
                ? true
                : ($user->plan_expires_at
                    && $user->plan_expires_at->subDays(30)->isPast()
                    ? true
                    : false);

            if (! $canRenew) {
                return redirect()->route('billing.index')
                    ->with('info', "You're already on the " . ucfirst($plan) . " plan.");
            }

            // Plan is expiring soon (or already expired) — fall through and
            // create a new pending_upgrade. The webhook will overwrite
            // plan_expires_at with null (lifetime) when payment completes.
            Log::info('BillingController: same-plan renewal allowed (plan expiring)', [
                'user_id'         => $user->id,
                'plan'            => $plan,
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
            $buyUrl = sprintf(
                'https://www.2checkout.com/checkout/purchase?sid=%s&product_id=%s&quantity=1&external-reference=%s&merchant_item_id_1=%s',
                urlencode((string) $sid),
                urlencode((string) $productId),
                urlencode($pending->token),
                urlencode((string) $user->id),
            );

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

    // ── M-1: Subscription management ──────────────────────────────────────

    /**
     * Cancel the user's active subscription.
     *
     * Calls 2Checkout's cancel subscription API (via a server-to-server
     * HTTP call), which triggers a RECURRING_ORDER_CANCELLED webhook.
     * The user keeps access until subscription_ends_at (the end of the
     * already-paid-for period), then is downgraded by CheckPlanExpiry.
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

            // Call 2Checkout's cancel subscription API.
            // 2Checkout API docs: https://api.2checkout.com/rest/6.0/subscriptions/{subscription_id}/cancel
            $apiBaseUrl = rtrim((string) config('services.2checkout.api_base_url', 'https://api.2checkout.com'), '/');
            $merchantCode = config('services.2checkout.account_number');
            $secret = config('services.2checkout.secret_word');
            $subscriptionId = $user->subscription_id;

            try {
                $response = \Illuminate\Support\Facades\Http::withHeaders([
                    'Content-Type' => 'application/json',
                ])->post("{$apiBaseUrl}/rest/6.0/subscriptions/{$subscriptionId}/cancel", [
                    'merchant_code' => $merchantCode,
                    // 2Checkout requires authentication via a hash header.
                    // The exact auth mechanism depends on the 2Checkout API
                    // version — some use merchant_code + date + hash, others
                    // use a bearer token. This is a simplified placeholder;
                    // the founder must configure the correct auth per their
                    // 2Checkout account's API settings.
                ]);

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
     * Calls 2Checkout's reactivate subscription API. Only works if the
     * subscription hasn't ended yet (subscription_ends_at is in the future).
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
        $apiBaseUrl = rtrim((string) config('services.2checkout.api_base_url', 'https://api.2checkout.com'), '/');

        try {
            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->post("{$apiBaseUrl}/rest/6.0/subscriptions/{$subscriptionId}/reactivate", [
                'merchant_code' => config('services.2checkout.account_number'),
            ]);

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

    // ── M-10: Invoice download ────────────────────────────────────────────

    /**
     * Download an invoice PDF (or HTML fallback).
     *
     * Only the invoice's owner can download it — the route is behind the
     * 'auth' + 'verified' + 'mfa' middleware (same as other billing routes).
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

        // Serve the file. Currently stored as HTML (see InvoiceGenerator);
        // when the founder adds a PDF library, this will serve a real PDF.
        $mimeType = str_ends_with($invoice->pdf_path, '.pdf') ? 'application/pdf' : 'text/html';
        $filename = "{$invoice->invoice_number}." . (str_ends_with($invoice->pdf_path, '.pdf') ? 'pdf' : 'html');

        return response($disk->get($invoice->pdf_path), 200, [
            'Content-Type'        => $mimeType,
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control'       => 'private, no-cache, no-store, must-revalidate',
        ]);
    }
}
