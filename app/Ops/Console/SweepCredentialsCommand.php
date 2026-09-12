<?php

declare(strict_types=1);

namespace App\Ops\Console;

use App\Ops\Models\OpsEvent;
use App\Ops\Services\OpsCredentialInventoryService;
use App\Ops\Services\OpsEventIngestor;
use App\Services\OperationalAlertService;
use Illuminate\Console\Command;
use Throwable;

class SweepCredentialsCommand extends Command
{
    protected $signature = 'ops:sweep-credentials';

    protected $description = 'Sweep the credential rotation ledger; alert + record a SECURITY event when rotations are overdue or exposed-unrotated, auto-resolve when clean';

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
