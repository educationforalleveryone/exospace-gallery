<?php

declare(strict_types=1);

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Services\CohortRetentionMetricsService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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

        // ITERATION 8: audit target = the admin themselves (not the
        // first-row user, which rotates per page and mis-attributes).
        // The payload already carries the cohort coordinates + row count;
        // target_id pointing at the actor is the stable, attributable
        // convention for view-only audit rows (mirrors the iter-8
        // codification of "view/export = audit-logged, target = actor;
        // mutation = password.confirm + audit, target = subject").
        $auditTarget = $request->user();

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

    /**
     * ITERATION 8 — streamed CSV export of a retention cohort's members.
     *
     * Mirrors BillingController::export (the Iteration-5 billing-CSV
     * precedent): read-only PII surface, audit-logged BEFORE the stream
     * starts (so an interrupted export is still attributable), BOM-prefixed
     * UTF-8 for Excel compatibility, cursor() for flat memory.
     *
     * Trust bar: same as the cohort drill-down view itself (super-admin +
     * MFA, audit-logged, no password.confirm — the CSV carries the SAME
     * PII the page already reveals; the audit row's payload carries the
     * cohort coordinates + row count, NOT the emails).
     *
     * The CSV column shape is intentionally narrower than the on-page
     * table: NO user_id (a stable-enough PII to keep out of spreadsheets
     * forwarded to finance/ops), NO is_super_admin (irrelevant to churn
     * analysis). Banned status IS included (banned users SHOULD read as
     * non-retention; the CSV needs to make that visible to a teammate
     * who wasn't there when the cohort was reviewed).
     */
    public function exportCsv(Request $request, string $cohort)
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

        // Pull the count via cursor() — re-use the same members query the
        // page paginates, so the CSV's content reconciles with the page
        // the operator just viewed. (The cell-active count from the
        // matrix cache may be 30/60 min stale; the per-row active flag
        // is the live truth.)
        $members = $data['members']->cursor();

        $rowCount = $data['size'];

        // Audit BEFORE the stream — an interrupted export is still a PII
        // reveal. Same target = admin convention as the view audit row
        // (payload carries cohort coordinates + row count only).
        AdminAuditLog::record('retention.cohort_exported', $request->user(), [
            'cohort_week_start' => $data['week_start']->toDateString(),
            'week_index'        => $weekIndex,
            'cohort_size'       => $rowCount,
            'active_count'      => $data['active_count'],
        ]);

        $headers = [
            'name',
            'email',
            'plan',
            'registered_at',
            'last_login_at',
            'active_in_period',
            'banned',
        ];

        // Str::random(4) suffix prevents same-second collision between two
        // admins exporting simultaneously (audit-fix E-3 from the iter-7
        // audit: BillingController::export had the same risk).
        $filename = 'exospace-cohort-'
            . $data['week_start']->format('Ymd')
            . '-w' . $weekIndex
            . '-' . Str::random(4)
            . '.csv';

        $weekStartStr = $data['week_start']->toDateString();
        $periodStartStr = $data['period']['start']->toDateString();
        $periodEndStr = $data['period']['end']->toDateString();

        return response()->streamDownload(function () use ($members, $headers, $weekStartStr, $weekIndex, $periodStartStr, $periodEndStr) {
            $out = fopen('php://output', 'w');

            // BOM for Excel UTF-8 compatibility (same convention as the
            // billing CSV + the user-facing GDPR export).
            fwrite($out, "\xEF\xBB\xBF");

            // Header comment row — preserves context when the CSV is
            // forwarded to a teammate without the cohort URL. Two
            // leading comment lines so a strict CSV parser still sees
            // the data headers at row 3.
            fputcsv($out, ['# Exospace retention cohort export']);
            fputcsv($out, [
                '# cohort_week_start=' . $weekStartStr,
                'week_index=' . $weekIndex,
                'period_start=' . $periodStartStr,
                'period_end=' . $periodEndStr,
            ]);

            fputcsv($out, $headers);

            foreach ($members as $member) {
                fputcsv($out, [
                    $member->name,
                    $member->email,
                    $member->plan,
                    optional($member->created_at)->toIso8601String(),
                    optional($member->last_login_at)->toIso8601String(),
                    (int) ($member->active_in_period ?? 0) === 1 ? 'yes' : 'no',
                    $member->banned_at ? 'yes' : 'no',
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
