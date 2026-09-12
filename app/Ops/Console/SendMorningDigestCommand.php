<?php

declare(strict_types=1);

namespace App\Ops\Console;

use App\Ops\Services\OpsMorningDigestService;
use Illuminate\Console\Command;
use Throwable;

class SendMorningDigestCommand extends Command
{
    protected $signature = 'ops:send-morning-digest';

    protected $description = 'Send the unified morning digest to the operational Slack channel: health score, incidents, untriaged errors, applications, sweep findings, backups, webhooks, Sentry trend, credential cadence and 24h operator activity';

    public function handle(OpsMorningDigestService $digest): int
    {
        if (! config('ops.digest.enabled')) {
            $this->info('Morning digest disabled (OPS_MORNING_DIGEST_ENABLED=false) — nothing to send.');

            return self::SUCCESS;
        }

        try {
            $result = $digest->send('scheduled');
        } catch (Throwable $e) {
            $this->warn('Morning digest failed: '.mb_substr($e->getMessage(), 0, 200));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Morning digest sent (%d sections, %d characters).',
            (int) $result['sections'],
            mb_strlen((string) $result['text']),
        ));

        return self::SUCCESS;
    }
}
