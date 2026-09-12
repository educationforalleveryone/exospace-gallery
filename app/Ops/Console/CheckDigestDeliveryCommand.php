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

class CheckDigestDeliveryCommand extends Command
{
    protected $signature = 'ops:check-digest-delivery';

    protected $description = 'Watchdog: verify the 08:15 morning digest actually went out; alert + record an event when the silence contract is broken';

    private const EVENT_TITLE = 'Digest watchdog: morning digest missing';

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
                true,
            );
        } catch (Throwable $e) {
            $this->warn('Could not send watchdog alert: '.mb_substr($e->getMessage(), 0, 200));
        }

        $this->warn('Digest MISSED — '.$reason.($event !== null ? ' (event #'.$event->id.')' : ''));

        return self::SUCCESS;
    }

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
