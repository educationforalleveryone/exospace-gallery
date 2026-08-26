<?php

declare(strict_types=1);

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Services\CohortRetentionMetricsService;
use Illuminate\Http\Request;

/**
 * ITERATION 7 — retention cohort drill-down.
 *
 * The Master Control retention matrix shows aggregates only (cohort
 * size + active count + retained %). Clicking a cell opens this page,
 * which lists the underlying users so an operator can investigate
 * churn ("which 3 of the 12 from Aug 25 came back in week 2?").
 *
 * Trust model: this is a READ of PII that already appears on the
 * dashboard's user table (name + email + plan). The same group
 * middleware gates it (super-admin + MFA), no password.confirm —
 * but EVERY page view is audit-logged (retention.cohort_viewed)
 * with the actor + cohort coordinates + row count, so a PII reveal
 * is attributable the same way billing.exported makes a CSV export
 * attributable. The payload carries IDs/counts only — AdminAuditLog
 * scrubs email anyway, but we don't pass user emails into the audit
 * payload in the first place.
 *
 * Reconciliation: cohort size and active count are re-derived LIVE
 * from the same bounded activity definition as countActive() (not
 * read from the 30/60-min matrix cache) so the drill-down reflects
 * the moment of the click. Tiny drift from the cached matrix is
 * possible if users registered in the meantime; documented in-page.
 */
class RetentionController extends Controller
{
    public function cohort(Request $request, string $cohort)
    {
        $weekIndex = (int) $request->query('week', 0);
        if ($weekIndex < 0 || $weekIndex > 156) {
            abort(404);
        }

        $service = app(CohortRetentionMetricsService::class);
        $data = $service->cohortDrilldown($cohort, $weekIndex);

        if ($data === null) {
            abort(404);
        }

        // Paginate the members — same convention as Billing Review
        // (25/page, query string preserved for the period selector).
        $members = $data['members']->paginate(25)->appends([
            'week' => $weekIndex,
        ]);

        // Audit the PII reveal — system actor would be wrong here:
        // the ADMIN chose to look at the cohort list. Target = the
        // first cohort member (a real row, same convention as the
        // webhook audit target which uses the newest transaction) —
        // falls back to the admin themselves for an empty cohort
        // (a view of "no members this week" is still an action).
        $auditTarget = $members->items()[0] ?? $request->user();

        AdminAuditLog::record('retention.cohort_viewed', $auditTarget, [
            'cohort_week_start' => $data['week_start']->toDateString(),
            'week_index'        => $weekIndex,
            'cohort_size'       => $data['size'],
            'active_count'      => $data['active_count'],
            'page'              => $members->currentPage(),
            'row_count'         => $members->count(),
        ]);

        $pct = $data['size'] > 0
            ? round(($data['active_count'] / $data['size']) * 100, 1)
            : 0.0;

        return view('super-admin.retention-cohort', [
            'cohort'        => $data['week_start'],
            'weekIndex'     => $weekIndex,
            'periodStart'   => $data['period']['start'],
            'periodEnd'     => $data['period']['end'],
            'size'          => $data['size'],
            'activeCount'   => $data['active_count'],
            'pct'           => $pct,
            'members'       => $members,
        ]);
    }
}
