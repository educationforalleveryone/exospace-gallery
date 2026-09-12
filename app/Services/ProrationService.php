<?php

namespace App\Services;

use App\Models\User;

class ProrationService
{
    private function planPrice(string $plan): float
    {
        $price = config("plans.display.{$plan}.price");
        if (! is_numeric($price)) {
            return 0.00;
        }
        return (float) $price;
    }

    public function calculateUpgradeCredit(User $user, string $newPlan): array
    {
        $currentPlan = $user->plan;
        $currentPrice = $this->planPrice($currentPlan);
        $newPrice = $this->planPrice($newPlan);

        // No credit if upgrading from free (free has no payment to prorate)
        if ($currentPlan === 'free' || $currentPrice === 0) {
            return [
                'credit_amount'     => 0.00,
                'credit_description'=> 'No credit (upgrading from Free plan)',
                'new_price'         => $newPrice,
            ];
        }

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
                (int) ceil($remainingDays),
                ucfirst($currentPlan),
                $creditAmount,
                $adjustedPrice
            ),
            'new_price'         => $adjustedPrice,
        ];
    }
}
