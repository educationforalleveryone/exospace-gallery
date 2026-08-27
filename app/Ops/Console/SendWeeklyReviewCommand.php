<?php

declare(strict_types=1);

namespace App\Ops\Console;

use App\Ops\Services\OpsWeeklyReviewService;
use Illuminate\Console\Command;
use Throwable;

/**
 * OpsCenter — SendWeeklyReviewCommand (Iteration 8).
 *
 * ops:send-weekly-review — the Monday deep-dive (08:30, fifteen minutes
 * after the daily digest, still inside the morning-briefing block and
 * ahead of the 08:45 watchdog + the 09:00 credential reminder): the
 * trailing-7-day trends the daily cadence cannot show — error volume by
 * category, incident throughput with MTTA/MTTR, deployment activity,
 * the sweep's finding history, current backup freshness and the week's
 * operator activity.
 *
 * NOT a dead-man's switch: the daily digest + the watchdog carry the
 * silence contract; this is the long view. Kill switch
 * OPS_WEEKLY_REVIEW_ENABLED=false stops the scheduled send only — the
 * /ops/digest preview and the manual button keep working.
 *
 * Never fatal: a review failure must never break the schedule chain.
 */
class SendWeeklyReviewCommand extends Command
{
    protected $signature = 'ops:send-weekly-review';

    protected $description = 'Send the weekly review to the operational Slack channel: 7-day error volume by category, incident MTTA/MTTR, deployments, sweep findings, backup freshness and operator activity';

    public function handle(OpsWeeklyReviewService $review): int
    {
        if (! config('ops.weekly_review.enabled')) {
            $this->info('Weekly review disabled (OPS_WEEKLY_REVIEW_ENABLED=false) — nothing to send.');

            return self::SUCCESS;
        }

        try {
            $result = $review->send('scheduled');
        } catch (Throwable $e) {
            $this->warn('Weekly review failed: '.mb_substr($e->getMessage(), 0, 200));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Weekly review sent (%d sections, %d characters).',
            (int) $result['sections'],
            mb_strlen((string) $result['text']),
        ));

        return self::SUCCESS;
    }
}
