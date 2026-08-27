<?php

declare(strict_types=1);

namespace App\Ops\Console;

use App\Ops\Models\OpsEvent;
use App\Ops\Services\OpsEventIngestor;
use App\Ops\Services\OpsMorningDigestService;
use App\Services\OperationalAlertService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * OpsCenter — CheckDigestDeliveryCommand (Iteration 8).
 *
 * ops:check-digest-delivery — the digest WATCHDOG: meta-monitoring the
 * monitor. The morning digest's silence contract (§16.4) says a missing
 * digest IS the alarm — but until now the only thing that could notice
 * the silence was a human reading Slack. This command closes that loop:
 * 30 minutes after the 08:15 send, it checks the ops:morning-digest:last
 * stamp and raises the alarm itself when the briefing did not arrive.
 *
 * What "missed" means and why each failure mode matters:
 *   stamp absent        → the digest has NEVER gone out (fresh install
 *                         with the switch on, or a cache flush wiped the
 *                         stamp — either way, worth one look)
 *   stamp not from today → the scheduler is stale, the send path threw,
 *                         or someone flipped OPS_MORNING_DIGEST_ENABLED
 *                         off and back on — the dead-man's switch fired
 *   stamp from today     → the contract held: resolve any prior watchdog
 *                         event + ONE recovery note, then stay silent
 *
 * The watchdog stays QUIET when healthy — exactly like every other
 * monitor. Only the digest itself fires on time (that is the contract);
 * a daily "watchdog OK" message would double the morning noise and
 * teach the operator to ignore the channel.
 *
 * Guardrails (the OpsCenter family rules):
 *   - OPS_MORNING_DIGEST_ENABLED=false → clean no-op: the contract is
 *     suspended, an absent digest means nothing by design (§16.4).
 *   - OPS_DIGEST_WATCHDOG_ENABLED=false → clean no-op (the safety net
 *     itself has a switch).
 *   - ONE warning alert (dedup key ops.digest.missed — a double-fired
 *     schedule must not double-post) + ONE deduplicated INFRASTRUCTURE
 *     event (source 'watchdog') the correlation sweep can group.
 *   - Never fatal: a watchdog crash must never break the schedule
 *     chain — always exits 0 and says what went wrong.
 */
class CheckDigestDeliveryCommand extends Command
{
    protected $signature = 'ops:check-digest-delivery';

    protected $description = 'Watchdog: verify the 08:15 morning digest actually went out; alert + record an event when the silence contract is broken';

    /** The stable event title — the resolution key, same trick as the sweep. */
    private const EVENT_TITLE = 'Digest watchdog: morning digest missing';

    /** Cache slot for the recorded event's id (cache-flush fallback: title lookup). */
    private const EVENT_ID_KEY = 'ops:watchdog:digest:event';

    public function handle(OpsMorningDigestService $digest, OpsEventIngestor $ingestor): int
    {
        if (! config('ops.digest.enabled')) {
            // The contract is suspended — silence means nothing.
            $this->info('Morning digest disabled — the silence contract is suspended; watchdog skipped.');

            return self::SUCCESS;
        }

        if (! config('ops.digest.watchdog_enabled')) {
            $this->info('Digest watchdog disabled (OPS_DIGEST_WATCHDOG_ENABLED=false) — check skipped.');

            return self::SUCCESS;
        }

        try {
            $lastSent = $digest->lastSent();
        } catch (Throwable) {
            $lastSent = null; // cache unavailable — treated as "cannot verify" below
        }

        $delivered = $lastSent !== null && $lastSent['at']->isSameDay(now());

        if ($delivered) {
            $this->resolvePrior($ingestor);

            $this->info(sprintf(
                'Digest delivered today at %s (trigger: %s) — watchdog quiet.',
                $lastSent['at']->format('H:i'),
                $lastSent['trigger'],
            ));

            return self::SUCCESS;
        }

        // ── MISSED ────────────────────────────────────────────────────────
        // Two honest variants: never sent (fresh install / flushed cache)
        // vs stale (sent before today — the switch is broken or off).
        $reason = $lastSent === null
            ? 'No digest delivery has ever been recorded (fresh install, or a cache flush wiped the stamp).'
            : sprintf(
                'The last digest went out %s (%s) — nothing arrived this morning.',
                $lastSent['at']->diffForHumans(),
                $lastSent['trigger'],
            );

        $message = $reason.' While OPS_MORNING_DIGEST_ENABLED is on, the silence contract (§16.4) expects a message EVERY morning: check the scheduler is running, the OPERATIONAL_ALERT_WEBHOOK still accepts posts, and /ops/digest shows a recent "last sent".';

        $event = null;
        try {
            $event = $ingestor->record([
                'source' => 'watchdog',
                'category' => 'INFRASTRUCTURE',
                'severity' => 'warning',
                'title' => self::EVENT_TITLE,
                'message' => $reason,
                'context' => [
                    'watchdog' => true,
                    'last_sent_at' => $lastSent !== null ? $lastSent['at']->toIso8601String() : null,
                    'last_sent_trigger' => $lastSent !== null ? $lastSent['trigger'] : null,
                ],
            ]);
        } catch (Throwable $e) {
            $this->warn('Could not record watchdog event: '.mb_substr($e->getMessage(), 0, 200));
        }

        if ($event !== null) {
            try {
                Cache::put(self::EVENT_ID_KEY, $event->id, now()->addDay());
            } catch (Throwable) {
                // Cache unavailable — resolution falls back to the title lookup.
            }
        }

        try {
            app(OperationalAlertService::class)->alert(
                'OpsCenter digest watchdog: morning digest MISSING',
                $message,
                'warning',
                'ops.digest.missed',
            );
        } catch (Throwable $e) {
            $this->warn('Could not send watchdog alert: '.mb_substr($e->getMessage(), 0, 200));
        }

        $this->warn('Digest MISSED — '.$reason.($event !== null ? ' (event #'.$event->id.')' : ''));

        return self::SUCCESS;
    }

    /**
     * Healthy path: resolve the prior open watchdog event (cached id
     * first, stable-title fallback second) and send exactly ONE recovery
     * note — only when a row was actually resolved RIGHT NOW. An absent
     * or already-resolved event stays silent: a healthy morning must not
     * generate watchdog noise.
     */
    private function resolvePrior(OpsEventIngestor $ingestor): void
    {
        $event = null;

        try {
            $cachedId = Cache::pull(self::EVENT_ID_KEY);
            if (is_numeric($cachedId) && (int) $cachedId > 0) {
                $event = OpsEvent::find((int) $cachedId);
            }

            $event = $event
                ?? OpsEvent::query()
                    ->where('source', 'watchdog')
                    ->whereIn('status', ['open', 'acknowledged'])
                    ->where('title', self::EVENT_TITLE)
                    ->latest('id')
                    ->first();

            if ($event === null || $event->status === 'resolved') {
                return; // nothing to resolve — stay quiet
            }

            $event->status = 'resolved';
            $event->resolved_at = now();
            $event->save();
        } catch (Throwable) {
            // DB/cache trouble — the 7-day auto-resolve is the safety net.
            return;
        }

        try {
            app(OperationalAlertService::class)->alert(
                'OpsCenter digest watchdog: delivery recovered',
                "Today's morning digest arrived — the silence contract holds again. The watchdog event has been resolved automatically.",
                'info',
                'ops.digest.watchdog.recovered',
            );
        } catch (Throwable) {
            // Never fatal on the happy path.
        }

        $this->line('Prior watchdog event #'.$event->id.' resolved (digest delivery recovered).');
    }
}
