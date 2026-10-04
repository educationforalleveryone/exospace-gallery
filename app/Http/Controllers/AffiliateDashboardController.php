<?php

namespace App\Http\Controllers;

use App\Models\PendingUpgrade;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AffiliateDashboardController extends Controller
{
    public function index(Request $request): View
    {

        // Query 1: per-affiliate counts grouped by status.
        $counts = PendingUpgrade::query()
            ->whereNotNull('affiliate_id')
            ->where('affiliate_id', '!=', '')
            ->selectRaw('affiliate_id, status, COUNT(*) as cnt')
            ->groupBy('affiliate_id', 'status')
            ->get();

        // Paid conversions only: a fully refunded, charged-back or manually
        // granted upgrade earned the affiliate nothing, so it must not count
        // as revenue or as a conversion. A partial refund keeps the plan
        // active (the webhook only downgrades full refunds), so the sale
        // stuck and still counts.
        $unpaidStatuses = ['refunded', 'chargeback', 'manual'];

        $paid = PendingUpgrade::query()
            ->join('transactions', 'pending_upgrades.transaction_id', '=', 'transactions.id')
            ->where('pending_upgrades.status', 'converted')
            ->whereNotNull('pending_upgrades.affiliate_id')
            ->where('pending_upgrades.affiliate_id', '!=', '')
            ->whereNotIn('transactions.status', $unpaidStatuses)
            ->selectRaw('pending_upgrades.affiliate_id as affiliate_id, COUNT(*) as cnt, SUM(transactions.amount) as revenue')
            ->groupBy('pending_upgrades.affiliate_id')
            ->get()
            ->keyBy('affiliate_id');

        // Build per-affiliate rows from the counts collection, merging in paid conversions and revenue.
        $byAffiliate = [];

        foreach ($counts as $row) {
            $id = $row->affiliate_id;
            if (! isset($byAffiliate[$id])) {
                $byAffiliate[$id] = [
                    'id'              => $id,
                    'total'           => 0,
                    'converted'       => (int) ($paid[$id]->cnt ?? 0),
                    'pending'         => 0,
                    'revenue'         => (float) ($paid[$id]->revenue ?? 0),
                ];
            }
            // 'total' counts ALL pending_upgrades for the affiliate (any status).
            $byAffiliate[$id]['total'] += $row->cnt;
            if ($row->status === 'pending') {
                $byAffiliate[$id]['pending'] = $row->cnt;
            }
        }

        // Compute conversion_rate + assemble the array (sorted by revenue DESC).
        $affiliates = array_map(function ($row) {
            $row['conversion_rate'] = $row['total'] > 0
                ? round(($row['converted'] / $row['total']) * 100, 1)
                : 0;
            $row['revenue'] = (float) $row['revenue'];
            return $row;
        }, array_values($byAffiliate));

        usort($affiliates, fn($a, $b) => $b['revenue'] <=> $a['revenue']);

        // Compute totals from the assembled rows (single pass).
        $totals = [
            'referrals'       => array_sum(array_column($affiliates, 'total')),
            'converted'       => array_sum(array_column($affiliates, 'converted')),
            'pending'         => array_sum(array_column($affiliates, 'pending')),
            'revenue'         => array_sum(array_column($affiliates, 'revenue')),
            'conversion_rate' => 0,
        ];
        $totals['conversion_rate'] = $totals['referrals'] > 0
            ? round(($totals['converted'] / $totals['referrals']) * 100, 1)
            : 0;

        return view('super-admin.affiliates.index', compact('affiliates', 'totals'));
    }
}
