<?php

declare(strict_types=1);

namespace App\Ops\Console;

use App\Ops\Models\OpsEvent;
use App\Ops\Services\OpsCredentialInventoryService;
use App\Ops\Services\OpsEventIngestor;
use App\Services\OperationalAlertService;
use Illuminate\Console\Command;
use Throwable;

/**
 * OpsCenter — SweepCredentialsCommand (Iteration 6).
 *
 * ops:sweep-credentials — the credential-rotation counterpart of the
 * diagnostic sweep. Iteration 5 made the §15 checklist LIVE on
 * /ops/credentials (status chips computed on every page view); this
 * command makes cadence lapses find the OPERATOR — daily, in Slack,
 * without anyone having to remember the page exists. The same
 * "problems find the operator" philosophy as ops:sweep-diagnostics.
 *
 * Daily semantics (09:00, via the Coolify scheduled task):
 *
 *   ROTATE NOW or OVERDUE chips → ONE warning Slack alert (dedup key
 *      ops.credentials.rotation — the service's 2 h TTL suppresses
 *      intra-day duplicates, so the reminder lands once per day, not
 *      once per credential) + ONE deduplicated SECURITY event (source
 *      'sweep', category SECURITY): "Credential rotation overdue: N of
 *      M surfaces need attention", listing the keys and their ages.
 *      Recurrence bumps occurrence_count (the ingestor's fingerprint
 *      dedup), so the event reads "seen 14×" while a lapse drags on.
 *
 *   previously alerted, now clean → the event is RESOLVED and an
 *      info-level "recovered" note goes to Slack — working the page
 *      closes the loop by itself (a recorded rotation moves the chip to
 *      OK, the next sweep notices).
 *
 *   DUE SOON only (nothing overdue) → a gentle info nudge, throttled to
 *      WEEKLY by an explicit cache gate (the service's info dedup TTL
 *      is only 6 h — far too chatty for a daily planning reminder).
 *      NO event: due-soon is a plan-ahead signal, not a problem.
 *
 *   everything OK → nothing at all. Silence is the reward.
 *
 * Guarantees (the OpsCenter contract):
 *   - read-only against the world: reads the inventory (config presence
 *     booleans + the ops_credentials ledger), writes only the event row,
 *     its resolution, and Slack alerts. No secret VALUE can appear in
 *     any of them — the inventory never exposes one by construction.
 *   - never fatal: every step is individually guarded; the command
 *     always exits 0 (a broken reminder must not break the schedule
 *     chain).
 *   - kill switch: OPS_CREDENTIAL_REMINDERS_ENABLED=false exits
 *     immediately (the /ops/credentials PAGE keeps working — the switch
 *     gates the proactive nudge, not the surface).
 */
class SweepCredentialsCommand extends Command
{
    protected $signature = 'ops:sweep-credentials';

    protected $description = 'Sweep the credential rotation ledger; alert + record a SECURITY event when rotations are overdue or exposed-unrotated, auto-resolve when clean';

    /** Stable event title — the ingestor's fingerprint + resolution lookups key off it. */
    private const EVENT_TITLE = 'Credential rotation overdue';

    public function handle(OpsCredentialInventoryService $inventory, OpsEventIngestor $ingestor): int
    {
        if (! config('ops.credentials.reminders_enabled')) {
            $this->info('Credential reminders disabled (OPS_CREDENTIAL_REMINDERS_ENABLED=false) — nothing to do.');

            return self::SUCCESS;
        }

        try {
            $data = $inventory->inventory();
        } catch (Throwable $e) {
            $this->warn('Could not read the credential inventory: '.mb_substr($e->getMessage(), 0, 200));

            return self::SUCCESS;
        }

        // Bucket the actionable chips. UNTRACKED (optional tokens, never
        // rotated) and OK are deliberately invisible here — the reminder
        // reports PROBLEMS, not inventory.
        $rotateNow = array_values(array_filter($data['items'], fn ($item) => $item['status'] === 'rotate_now'));
        $overdue = array_values(array_filter($data['items'], fn ($item) => $item['status'] === 'overdue'));
        $dueSoon = array_values(array_filter($data['items'], fn ($item) => $item['status'] === 'due_soon'));

        $actionable = array_merge($rotateNow, $overdue);

        if ($actionable !== []) {
            $this->alertOverdue($rotateNow, $overdue, count($data['items']), $ingestor);
        } else {
            $this->info(sprintf(
                'Credential rotation clean (%d due soon, %d surfaces tracked).',
                count($dueSoon),
                count($data['items']),
            ));
            $this->resolvePriorEvent();

            // Due-soon only → the weekly planning nudge (no event).
            if ($dueSoon !== []) {
                $this->nudgeDueSoon($dueSoon);
            }
        }

        return self::SUCCESS;
    }

    /**
     * The overdue alert + SECURITY event. One event, one Slack message —
     * the list rides in the message/context, dedup handles recurrence.
     *
     * @param  array<int, array<string, mixed>>  $rotateNow
     * @param  array<int, array<string, mixed>>  $overdue
     */
    private function alertOverdue(array $rotateNow, array $overdue, int $total, OpsEventIngestor $ingestor): void
    {
        $lines = [];
        foreach (array_merge($rotateNow, $overdue) as $item) {
            $lines[] = sprintf(
                '• %s — %s%s (rotate: %s)',
                $item['name'],
                $item['status'] === 'rotate_now' ? 'exposed at kickoff, never rotated' : 'rotation overdue',
                $item['days_since'] !== null ? ", {$item['days_since']} days ago" : '',
                $item['env'][0] ?? '',
            );
        }

        $message = sprintf(
            "%d of %d credential surfaces need rotation:\n%s",
            count($rotateNow) + count($overdue),
            $total,
            implode("\n", $lines),
        );

        $event = null;
        try {
            $event = $ingestor->record([
                'source' => 'sweep',
                'category' => 'SECURITY',
                'severity' => 'warning',
                'title' => self::EVENT_TITLE,
                'message' => $message,
                'context' => [
                    'sweep' => true,
                    'credentials' => true,
                    'rotate_now' => array_map(fn ($i) => $i['key'], $rotateNow),
                    'overdue' => array_map(fn ($i) => $i['key'], $overdue),
                ],
            ]);
        } catch (Throwable $e) {
            $this->warn('Could not record the credential-rotation event: '.mb_substr($e->getMessage(), 0, 200));
        }

        try {
            app(OperationalAlertService::class)->alert(
                'OpsCenter: credential rotation overdue',
                $message."\nWork the list at /ops/credentials — record each rotation as you go.",
                'warning',
                'ops.credentials.rotation',
            );
        } catch (Throwable $e) {
            $this->warn('Could not send the credential-rotation alert: '.mb_substr($e->getMessage(), 0, 200));
        }

        $this->warn(sprintf(
            '[credentials] %d rotate-now, %d overdue — event %s',
            count($rotateNow),
            count($overdue),
            $event !== null ? '#'.$event->id : 'recording failed',
        ));
    }

    /**
     * Resolve the prior open SECURITY event when the list is clean (the
     * same recovery contract as the diagnostic sweep — fires exactly
     * once per lapse, idempotent after that).
     */
    private function resolvePriorEvent(): void
    {
        try {
            $event = OpsEvent::query()
                ->where('source', 'sweep')
                ->whereIn('status', ['open', 'acknowledged'])
                ->where('title', self::EVENT_TITLE)
                ->latest('id')
                ->first();

            if ($event === null) {
                return; // nothing was ever open — nothing to do
            }

            $event->status = 'resolved';
            $event->resolved_at = now();
            $event->save();

            try {
                app(OperationalAlertService::class)->alert(
                    'OpsCenter: credential rotation back in cadence',
                    'Every tracked credential surface is within its rotation cadence again. The rotation-overdue event has been resolved automatically.',
                    'info',
                    'ops.credentials.recovered',
                );
            } catch (Throwable) {
                // Never fatal on the happy path.
            }

            $this->line('[credentials] previously overdue — event #'.$event->id.' resolved (recovered).');
        } catch (Throwable $e) {
            $this->warn('Could not resolve the prior credential event: '.mb_substr($e->getMessage(), 0, 200));
        }
    }

    /**
     * The weekly due-soon nudge: info severity, no event, throttled by an
     * explicit 6-day cache gate (the alert service's info dedup TTL is
     * only 6 h — a daily command would otherwise nudge every day).
     *
     * @param  array<int, array<string, mixed>>  $dueSoon
     */
    private function nudgeDueSoon(array $dueSoon): void
    {
        $gate = 'ops:sweep-credentials:nudge';

        try {
            if (\Illuminate\Support\Facades\Cache::has($gate)) {
                $this->line('[credentials] due-soon nudge already sent this week — staying quiet.');

                return;
            }
        } catch (Throwable) {
            // Cache unavailable — sending the nudge is harmless (info).
        }

        $names = array_map(fn ($i) => $i['name'].' ('.$i['days_since'].'d)', $dueSoon);

        try {
            // No service dedup key here ON PURPOSE: the weekly gate above
            // is the single throttle (the service's info TTL is only 6 h,
            // which would fight the gate instead of helping it).
            app(OperationalAlertService::class)->alert(
                'OpsCenter: '.count($dueSoon).' credential(s) due for rotation soon',
                "Within their cadence window:\n• ".implode("\n• ", $names)."\nPlan the rotation(s) on /ops/credentials.",
                'info',
            );
        } catch (Throwable) {
            // Never fatal.
        }

        try {
            \Illuminate\Support\Facades\Cache::put($gate, now()->timestamp, now()->addDays(6));
        } catch (Throwable) {
            // Gate write failed — worst case the nudge repeats tomorrow.
        }

        $this->line('[credentials] due soon: '.implode(', ', $names));
    }
}
