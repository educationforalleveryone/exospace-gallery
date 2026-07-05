<?php

namespace App\Http\Controllers;

use App\Models\PendingUpgrade;
use App\Models\User;
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

        // Validate plan + product ID is configured
        $productId = $plan === 'pro'
            ? config('services.2checkout.product_id_pro')
            : ($plan === 'studio' ? config('services.2checkout.product_id_studio') : null);

        if (! $productId) {
            return redirect()->route('billing.index')
                ->with('error', "Unknown plan: {$plan}. Please contact support.");
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
        //   via TWOCHECKOUT_COUPON_ALLOWLIST (comma-separated). Previously
        //   any string from ?coupon=... was passed straight to 2Checkout,
        //   which could be abused to:
        //     - probe for valid coupon codes via 2Checkout's error responses
        //     - inject unexpected characters into the URL
        //   When the allowlist is not configured, the site-wide default
        //   coupon from config('services.2checkout.coupon_code') is still
        //   applied (it's set by the founder, not the user).
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

        // (Task H58) — affiliate/referral tracking. 2Checkout supports an
        // `affiliate` URL parameter that credits an affiliate account
        // configured in the merchant dashboard. The affiliate ID can be:
        //   - Site-wide default: TWOCHECKOUT_AFFILIATE_ID env var
        //   - Per-campaign: ?ref=AFFILIATE_ID on the billing.upgrade route
        // The ref param is stored on the pending_upgrade for reporting.
        //
        // SEC-8 FIX: ref is now validated against an allowlist configured
        //   via TWOCHECKOUT_AFFILIATE_ALLOWLIST (comma-separated). Same
        //   rationale as coupon validation above.
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
    }
}
