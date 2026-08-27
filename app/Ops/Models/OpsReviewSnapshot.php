<?php

declare(strict_types=1);

namespace App\Ops\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * App\Ops\Models\OpsReviewSnapshot
 *
 * The weekly review's long memory (Iteration 9): one immutable row per
 * weekly review DELIVERY, written by OpsWeeklyReviewService::send() and
 * read by the /ops/digest 8-week trend strip. A row never changes after
 * creation — a manual re-send of the same week appends a new row, and
 * readers take the latest row per week_start (the ledger stays truthful
 * about what was actually sent, the strip stays truthful about what the
 * week looked like when it was last reported).
 *
 * metrics holds aggregate COUNTS only (total_errors, category counts,
 * incident throughput incl. nullable MTTA/MTTR minutes, deployments,
 * sweep findings, operator activity) — never titles, contexts or
 * payloads, so no redaction surface exists on this table at all.
 */
class OpsReviewSnapshot extends Model
{
    protected $table = 'ops_review_snapshots';

    // created_at only — snapshots are immutable point-in-time facts.
    public $timestamps = false;

    protected $fillable = [
        'week_start', 'week_end', 'trigger', 'metrics', 'created_at',
    ];

    protected $casts = [
        'week_start' => 'date',
        'week_end' => 'date',
        'metrics' => 'array',
        'created_at' => 'datetime',
    ];
}
