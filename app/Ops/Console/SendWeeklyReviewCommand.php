<?php

declare(strict_types=1);

namespace App\Ops\Console;

use App\Ops\Services\OpsWeeklyReviewService;
use Illuminate\Console\Command;
use Throwable;

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
