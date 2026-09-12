<?php

declare(strict_types=1);

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Services\CohortRetentionMetricsService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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

        $members = $data['members']->paginate(25)->appends([
            'week' => $weekIndex,
        ]);

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

        $curve = $service->cohortCurve($data['week_start']->toDateString(), 8);

        return view('super-admin.retention-cohort', [
            'cohort'        => $data['week_start'],
            'weekIndex'     => $weekIndex,
            'periodStart'   => $data['period']['start'],
            'periodEnd'     => $data['period']['end'],
            'size'          => $data['size'],
            'activeCount'   => $data['active_count'],
            'pct'           => $pct,
            'members'       => $members,
            'curve'         => $curve,
        ]);
    }

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

        $members = $data['members']->cursor();

        $rowCount = $data['size'];

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

        $filename = 'exospace-cohort-'
            . $data['week_start']->format('Ymd')
            . '-w' . $weekIndex
            . '-' . Str::random(4)
            . '.csv';

        $weekStartStr = $data['week_start']->toDateString();
        $periodStartStr = $data['period']['start']->toDateString();
        $periodEndStr = $data['period']['end']->toDateString();

        $asOf = now()->toIso8601String();

        return response()->streamDownload(function () use ($members, $headers, $weekStartStr, $weekIndex, $periodStartStr, $periodEndStr, $asOf) {
            $out = fopen('php://output', 'w');

            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, ['# Exospace retention cohort export']);
            fputcsv($out, [
                '# cohort_week_start=' . $weekStartStr,
                'week_index=' . $weekIndex,
                'period_start=' . $periodStartStr,
                'period_end=' . $periodEndStr,
                'exported_at=' . $asOf,
            ]);
            fputcsv($out, ['# active_in_period is computed live at export time, not read from a snapshot']);

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
