<?php

namespace App\Http\Controllers;

use App\Models\PendingUpgrade;
use App\Models\Transaction;
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

        $revenues = PendingUpgrade::query()
            ->join('transactions', 'pending_upgrades.transaction_id', '=', 'transactions.id')
            ->where('pending_upgrades.status', 'converted')
            ->whereNotNull('pending_upgrades.affiliate_id')
            ->where('pending_upgrades.affiliate_id', '!=', '')
            ->selectRaw('pending_upgrades.affiliate_id as affiliate_id, SUM(transactions.amount) as revenue')
            ->groupBy('pending_upgrades.affiliate_id')
            ->pluck('revenue', 'affiliate_id');

        // Build per-affiliate rows from the counts collection, merging in revenue.
        $byAffiliate = [];

        foreach ($counts as $row) {
            $id = $row->affiliate_id;
            if (! isset($byAffiliate[$id])) {
                $byAffiliate[$id] = [
                    'id'              => $id,
                    'total'           => 0,
                    'converted'       => 0,
                    'pending'         => 0,
                    'revenue'         => (float) ($revenues[$id] ?? 0),
                ];
            }
            // 'total' counts ALL pending_upgrades for the affiliate (any status).
            $byAffiliate[$id]['total'] += $row->cnt;
            if ($row->status === 'converted') {
                $byAffiliate[$id]['converted'] = $row->cnt;
            }
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
