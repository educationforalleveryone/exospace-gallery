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
        $planRank = ['free' => 0, 'pro' => 1, 'studio' => 2];
        if (($planRank[$user->plan] ?? 0) > ($planRank[$plan] ?? 0)) {
            return redirect()->route('billing.index')
                ->with('warning', "You're currently on the " . ucfirst($user->plan) . " plan, which is a higher tier than " . ucfirst($plan) . ". Downgrades are not available via the upgrade flow — please contact support if you need to downgrade.");
        }

        // If the user is already on this plan, redirect with an info message
        if ($user->plan === $plan) {
            return redirect()->route('billing.index')
                ->with('info', "You're already on the " . ucfirst($plan) . " plan.");
        }

        // Create the pending upgrade
        $pending = PendingUpgrade::createForUser($user, $plan, $productId);

        // Build the 2Checkout buy URL
        $sid = config('services.2checkout.account_number');
        $buyUrl = sprintf(
            'https://www.2checkout.com/checkout/purchase?sid=%s&product_id=%s&quantity=1&external-reference=%s&merchant_item_id_1=%s&customer_email=%s',
            urlencode((string) $sid),
            urlencode((string) $productId),
            urlencode($pending->token),
            urlencode((string) $user->id),
            urlencode($user->email),
        );

        Log::info('BillingController: redirecting user to 2Checkout', [
            'user_id'           => $user->id,
            'plan'              => $plan,
            'pending_upgrade_id'=> $pending->id,
        ]);

        return redirect()->away($buyUrl);
    }
}
