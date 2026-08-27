<?php

declare(strict_types=1);

namespace App\Ops\Console;

use App\Ops\Services\OpsMorningDigestService;
use Illuminate\Console\Command;
use Throwable;

/**
 * OpsCenter — SendMorningDigestCommand (Iteration 7).
 *
 * ops:send-morning-digest — the daily briefing, one Slack message a
 * day (08:15, via the Coolify scheduled task; withoutOverlapping +
 * onOneServer keep a single delivery per day per box). Everything the
 * control plane watches, in one place, at one predictable moment:
 *
 *   PLATFORM   the health score + verdict + the weakest components
 *   INCIDENTS  what is correlated and still open
 *   ERRORS     untriaged events (outside incidents)
 *   APPS       running/degraded/stopped rollup + worst offenders
 *   SWEEP      the autonomous watch's open findings
 *   BACKUPS    per-disk freshness
 *   WEBHOOKS   the billing ledger's failed count
 *   SENTRY     the 24 h error trend (omitted when unconfigured)
 *   CREDS      rotation cadence state (the 09:00 reminder adds detail)
 *   ACTIVITY   what the operators actually did in the last 24 h
 *
 * The silence contract (§16.4): alerts fire on PROBLEMS, the digest
 * fires on TIME. An "all quiet" morning still gets its message — so a
 * silent morning becomes a signal in itself. Kill switch:
 * OPS_MORNING_DIGEST_ENABLED=false (the /ops/digest preview page keeps
 * working; only the proactive send stops).
 *
 * Never fatal: a digest failure must never break the schedule chain —
 * the command always exits 0 and says what went wrong.
 */
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
