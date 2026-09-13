<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AdminAuditLog;
use App\Models\PendingUpgrade;
use App\Models\User;
use App\Services\PlanLockService;
use App\Services\TwoCheckoutApiClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

class BillingController extends Controller
{
    public function __construct(
        private readonly PlanLockService $planLock,
        private readonly TwoCheckoutApiClient $twoCheckout,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        $transactions = $user->transactions()
            ->with('invoice')
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

    public function upgrade(Request $request, string $plan): RedirectResponse
    {
        $user = $request->user();

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

        $planRank = config('plans.rank', ['free' => 0, 'pro' => 1, 'studio' => 2]);
        if (($planRank[$user->plan] ?? 0) > ($planRank[$plan] ?? 0)) {
            return redirect()->route('billing.index')
                ->with('warning', "You're currently on the " . ucfirst($user->plan) . " plan, which is a higher tier than " . ucfirst($plan) . ". Downgrades are not available via the upgrade flow — please contact support if you need to downgrade.");
        }

        if ($user->plan === $plan) {
            // Same-plan purchase is allowed (renewal). If the user is converting
            // an active subscription to a one-time purchase, the webhook cancels
            // the replaced 2Checkout subscription once the new payment confirms,
            // so nothing is cancelled before money actually changes hands.
            Log::info('BillingController: same-plan purchase initiated', [
                'user_id'         => $user->id,
                'plan'            => $plan,
                'is_recurring'    => $isRecurring,
                'plan_expires_at' => $user->plan_expires_at?->toIso8601String(),
            ]);
        }

        $result = $this->planLock->withUserLock($user->id, function () use ($user, $plan, $productId, $request, $isRecurring) {
            $user->refresh();

            $planRank = config('plans.rank', ['free' => 0, 'pro' => 1, 'studio' => 2]);
            if (($planRank[$user->plan] ?? 0) > ($planRank[$plan] ?? 0)) {
                return redirect()->route('billing.index')
                    ->with('warning', "You're currently on the " . ucfirst($user->plan) . " plan, which is a higher tier than " . ucfirst($plan) . ". Downgrades are not available via the upgrade flow — please contact support if you need to downgrade.");
            }

            // Create the pending upgrade
            $pending = PendingUpgrade::createForUser($user, $plan, $productId);

            $sid = config('services.2checkout.account_number');
            $secretWord = config('services.2checkout.secret_word');

            $buyUrl = sprintf(
                'https://www.2checkout.com/checkout/purchase?sid=%s&product_id=%s&quantity=1&external-reference=%s&merchant_item_id_1=%s',
                urlencode((string) $sid),
                urlencode((string) $productId),
                urlencode($pending->plaintext_token), // plaintext token (runtime attr, not the stored hash)
                urlencode((string) $user->id),
            );

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

    private function getProductPrice(string $plan, bool $isRecurring): ?string
    {
        if ($isRecurring) {
            $price = $plan === 'pro'
                ? config('services.2checkout.recurring_price_pro_monthly')
                : ($plan === 'studio' ? config('services.2checkout.recurring_price_studio_monthly') : null);
        } else {
            $price = $plan === 'pro'
                ? config('services.2checkout.price_pro')
                : ($plan === 'studio' ? config('services.2checkout.price_studio') : null);
        }

        return $price !== null ? (string) $price : null;
    }

    public function cancelSubscription(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user->hasActiveSubscription()) {
            return redirect()->route('billing.index')
                ->with('error', 'You do not have an active subscription to cancel.');
        }

        $result = $this->planLock->withUserLock($user->id, function () use ($user) {
            // Re-check inside the lock — a webhook may have just cancelled it.
            $user->refresh();

            if (! $user->hasActiveSubscription()) {
                return redirect()->route('billing.index')
                    ->with('info', 'Your subscription has already been cancelled.');
            }

            // Use the real TwoCheckoutApiClient.
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

            $user->forceFill([
                'subscription_status'       => 'cancelled',
                'subscription_cancelled_at' => now(),
            ])->save();

            AdminAuditLog::record('subscription.cancelled', $user, [
                'subscription_id' => $subscriptionId,
                'ends_at'         => $user->subscription_ends_at?->toIso8601String(),
            ]);

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

        // Log subscription reactivation.
        AdminAuditLog::record('subscription.reactivated', $user, [
            'subscription_id' => $subscriptionId,
        ]);

        Log::info('BillingController: subscription reactivated', [
            'user_id'         => $user->id,
            'subscription_id' => $subscriptionId,
        ]);

        return redirect()->route('billing.index')
            ->with('success', 'Your subscription has been reactivated. The next billing date remains unchanged.');
    }

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

                AdminAuditLog::record('subscription.cancelled', $user, [
                    'subscription_id'      => $user->subscription_id,
                    'downgrade_target_plan' => $targetPlan,
                    'ends_at'              => $user->subscription_ends_at?->toIso8601String(),
                ]);

                return redirect()->route('billing.index')
                    ->with('success', "Your subscription has been cancelled. You'll keep " . ucfirst($user->plan)
                        . " access until {$user->subscription_ends_at?->format('M j, Y')}, after which your account moves to Free."
                        . ' To move to ' . ucfirst($targetPlan) . ' right away, use the upgrade options on this page.');
            }

            // One-time purchase: downgrade immediately
            $oldPlan = $user->plan; // Capture before mutation
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

            AdminAuditLog::record('plan.downgraded', $user, [
                'from' => $oldPlan,
                'to'   => $targetPlan,
            ]);

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

        AdminAuditLog::record('trial.started', $user, [
            'plan'              => $plan,
            'trial_ends_at'     => $user->fresh()->trial_ends_at?->toIso8601String(),
            'trial_count_for_ip' => RateLimiter::attempts($ipKey),
        ]);

        // Notification
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

        $local  = \Illuminate\Support\Facades\Storage::disk('local');
        $public = \Illuminate\Support\Facades\Storage::disk('public');

        if ($local->exists($invoice->pdf_path)) {
            $disk = $local;
        } elseif ($public->exists($invoice->pdf_path)) {
            $disk = $public;
        } else {
            Log::warning('BillingController: invoice file missing on disk', [
                'invoice_id' => $invoice->id,
                'pdf_path'   => $invoice->pdf_path,
            ]);
            abort(404, 'Invoice file not found.');
        }

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
