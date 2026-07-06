<?php

namespace App\Http\Controllers;

use App\Models\PendingUpgrade;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * M-5: Affiliate program dashboard.
 *
 * Shows affiliate performance metrics for the super-admin:
 *   - Total referrals (pending upgrades with affiliate_id)
 *   - Conversion rate (pending → completed transactions)
 *   - Revenue attributed to each affiliate
 *   - Per-affiliate breakdown
 *
 * Affiliate IDs are stored on pending_upgrades.affiliate_id (set by
 * BillingController when ?ref=AFFILIATE_ID is passed on the upgrade URL).
 * The SEC-8 allowlist controls which affiliate IDs are accepted.
 */
class AffiliateDashboardController extends Controller
{
    /**
     * Super-admin: affiliate dashboard.
     * GET /master-control/affiliates
     */
    public function index(Request $request): View
    {
        // Get all affiliate IDs with at least 1 pending upgrade
        $affiliateIds = PendingUpgrade::whereNotNull('affiliate_id')
            ->where('affiliate_id', '!=', '')
            ->distinct()
            ->pluck('affiliate_id');

        $affiliates = [];
        $totals = [
            'referrals'    => 0,
            'converted'    => 0,
            'revenue'      => 0.00,
            'pending'      => 0,
        ];

        foreach ($affiliateIds as $affiliateId) {
            $pending = PendingUpgrade::where('affiliate_id', $affiliateId)->get();
            $converted = $pending->where('status', 'converted')->count();
            $pendingCount = $pending->where('status', 'pending')->count();

            // Get transaction amounts for converted upgrades
            $transactionIds = $pending->where('status', 'converted')->pluck('transaction_id')->filter();
            $revenue = Transaction::whereIn('id', $transactionIds)->sum('amount');

            $affiliates[] = [
                'id'           => $affiliateId,
                'total'        => $pending->count(),
                'converted'    => $converted,
                'pending'      => $pendingCount,
                'revenue'      => $revenue,
                'conversion_rate' => $pending->count() > 0 ? round(($converted / $pending->count()) * 100, 1) : 0,
            ];

            $totals['referrals'] += $pending->count();
            $totals['converted'] += $converted;
            $totals['pending']   += $pendingCount;
            $totals['revenue']   += $revenue;
        }

        // Sort by revenue descending
        usort($affiliates, fn($a, $b) => $b['revenue'] <=> $a['revenue']);

        $totals['conversion_rate'] = $totals['referrals'] > 0
            ? round(($totals['converted'] / $totals['referrals']) * 100, 1)
            : 0;

        return view('super-admin.affiliates.index', compact('affiliates', 'totals'));
    }
}
