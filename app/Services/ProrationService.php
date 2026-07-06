<?php

namespace App\Services;

use App\Models\User;

/**
 * M-3: Proration service.
 *
 * Calculates the credit for the remaining period when a user upgrades
 * mid-cycle. For example, if a user paid $29 for Pro and upgrades to
 * Studio ($99) after 15 days, they get a credit of ~$14.50 (half the
 * Pro period) applied to the Studio purchase.
 *
 * 2Checkout handles the actual proration on their side (configured in
 * the merchant dashboard). This service calculates the DISPLAY value —
 * shown to the user on the upgrade page so they know what to expect.
 *
 * The actual charge adjustment is done by 2Checkout when the user
 * completes checkout. This service is informational only.
 */
class ProrationService
{
    /**
     * Plan prices (display-only — must match 2Checkout product prices).
     */
    private const PLAN_PRICES = [
        'free'   => 0,
        'pro'    => 29.00,
        'studio' => 99.00,
    ];

    /**
     * Calculate the proration credit for upgrading from one plan to another.
     *
     * @param  User   $user         The user upgrading
     * @param  string $newPlan      The target plan ('pro' or 'studio')
     * @return array  { 'credit_amount' => float, 'credit_description' => string, 'new_price' => float }
     */
    public function calculateUpgradeCredit(User $user, string $newPlan): array
    {
        $currentPlan = $user->plan;
        $currentPrice = self::PLAN_PRICES[$currentPlan] ?? 0;
        $newPrice = self::PLAN_PRICES[$newPlan] ?? 0;

        // No credit if upgrading from free (free has no payment to prorate)
        if ($currentPlan === 'free' || $currentPrice === 0) {
            return [
                'credit_amount'     => 0.00,
                'credit_description'=> 'No credit (upgrading from Free plan)',
                'new_price'         => $newPrice,
            ];
        }

        // For one-time purchases (plan_expires_at = null), no proration —
        // the user paid for lifetime access. They're paying full price for
        // the new tier.
        if (! $user->plan_expires_at) {
            return [
                'credit_amount'     => 0.00,
                'credit_description'=> 'No credit (lifetime plan — full price for new tier)',
                'new_price'         => $newPrice,
            ];
        }

        // For subscriptions, calculate remaining days + proportional credit
        $now = now();
        $expiresAt = $user->plan_expires_at;

        // Already expired — no credit
        if ($expiresAt->isPast()) {
            return [
                'credit_amount'     => 0.00,
                'credit_description'=> 'No credit (current plan expired)',
                'new_price'         => $newPrice,
            ];
        }

        // Calculate remaining fraction of the billing period
        $planStarted = $user->plan_started_at ?? $expiresAt->copy()->subMonth();
        $totalDays = $planStarted->diffInDays($expiresAt);
        $remainingDays = $now->diffInDays($expiresAt);

        if ($totalDays <= 0 || $remainingDays <= 0) {
            return [
                'credit_amount'     => 0.00,
                'credit_description'=> 'No credit (billing period ended)',
                'new_price'         => $newPrice,
            ];
        }

        $remainingFraction = $remainingDays / $totalDays;
        $creditAmount = round($currentPrice * $remainingFraction, 2);
        $adjustedPrice = max(0, $newPrice - $creditAmount);

        return [
            'credit_amount'     => $creditAmount,
            'credit_description'=> sprintf(
                'Credit for %d remaining days of %s ($%.2f → $%.2f adjusted price)',
                $remainingDays,
                ucfirst($currentPlan),
                $creditAmount,
                $adjustedPrice
            ),
            'new_price'         => $adjustedPrice,
        ];
    }
}
