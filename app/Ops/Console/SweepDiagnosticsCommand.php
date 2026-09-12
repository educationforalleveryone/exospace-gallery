<?php

declare(strict_types=1);

namespace App\Ops\Console;

use App\Ops\Diagnostics\DiagnosticEngine;
use App\Ops\Diagnostics\DiagnosticRegistry;
use App\Ops\Models\OpsEvent;
use App\Ops\Services\OpsEventIngestor;
use App\Services\OperationalAlertService;
use Illuminate\Console\Command;
use Throwable;

class SweepDiagnosticsCommand extends Command
{
    protected $signature = 'ops:sweep-diagnostics';

    protected $description = 'Sweep the allow-listed self diagnostics; record deduplicated events + Slack alerts for degraded/failed findings, auto-resolve on recovery';

    private const MIN_CADENCE_MINUTES = 15;

    /**
      * @var array<string, string>
      */
    private const EVENT_CATEGORIES = [
        // Explicit per-id mappings where the domain differs from the prefix.
        'app.cache' => 'REDIS',
        'app.filesystem' => 'STORAGE',
        'app.scheduler' => 'INFRASTRUCTURE',
        // Prefix fallbacks.
        'database' => 'DATABASE',
        'redis' => 'REDIS',
        'queue' => 'QUEUE',
        'server' => 'INFRASTRUCTURE',
        'app' => 'APPLICATION',
    ];

    public function handle(DiagnosticEngine $engine, OpsEventIngestor $ingestor): int
    {
        if (! config('ops.sweeps.enabled')) {
            $this->info('Sweeps disabled (OPS_SWEEP_ENABLED=false) — nothing to do.');

            return self::SUCCESS;
        }

        $configured = (array) config('ops.sweeps.diagnostics', []);

        // Resolve the sweep set: registry-known, self-scoped ids only.
        $ids = [];
        foreach ($configured as $id) {
            if (! is_string($id) || trim($id) === '') {
                continue;
            }
            $id = trim($id);

            $definition = DiagnosticRegistry::get($id);
            if ($definition === null) {
                $this->warn("Skipping unknown diagnostic '{$id}' — not in the allow-list.");

                continue;
            }
            if ($definition['scope'] !== DiagnosticRegistry::SCOPE_SELF) {
                $this->warn("Skipping '{$id}' — only self-scoped diagnostics can be swept (application-scoped checks need a target).");

                continue;
            }

            $ids[] = $id;
        }

        if ($ids === []) {
            $this->info('No sweepable diagnostics configured — nothing to do.');

            return self::SUCCESS;
        }

        // Resolve + validate the per-check cadences (Iteration 6).
        $cadences = $this->resolveCadences($ids);

        $counts = ['healthy' => 0, 'degraded' => 0, 'failed' => 0, 'inconclusive' => 0, 'recovered' => 0, 'skipped' => 0];

        foreach ($ids as $id) {
            // Cadence throttle (healthy path only — see shouldProbe()).
            if (! $this->shouldProbe($id, $cadences)) {
                $counts['skipped']++;
                $this->line(sprintf('[%s] skipped — cadence %d min not yet elapsed, no open event', $id, $cadences[$id]));

                continue;
            }

            try {
                $status = $this->sweepOne($id, $engine, $ingestor);
                $counts[$status]++;
            } catch (Throwable $e) {
                $this->warn("Sweep of '{$id}' aborted unexpectedly: ".mb_substr($e->getMessage(), 0, 200));
                $counts['inconclusive']++;
            }
        }

        $summary = sprintf(
            'Sweep complete: %d healthy, %d degraded, %d failed, %d inconclusive, %d recovered, %d skipped (cadence)',
            $counts['healthy'],
            $counts['degraded'],
            $counts['failed'],
            $counts['inconclusive'],
            $counts['recovered'],
            $counts['skipped'],
        );

        ($counts['degraded'] + $counts['failed']) > 0
            ? $this->warn($summary)
            : $this->info($summary);

        return self::SUCCESS;
    }

    private function resolveCadences(array $ids): array
    {
        $configured = (array) config('ops.sweeps.cadences', []);
        $cadences = [];

        foreach ($configured as $id => $minutes) {
            $id = (string) $id;
            $minutes = (int) $minutes;

            if (! in_array($id, $ids, true)) {
                $this->warn("Ignoring cadence for '{$id}' — not in the sweep set (unknown id, not self-scoped, or not swept).");

                continue;
            }

            if ($minutes < self::MIN_CADENCE_MINUTES) {
                $this->warn("Ignoring cadence for '{$id}' — {$minutes} min is below the {$this->minCadenceLabel()} sweep interval.");

                continue;
            }

            $cadences[$id] = $minutes;
        }

        return $cadences;
    }

    private function minCadenceLabel(): string
    {
        return self::MIN_CADENCE_MINUTES.' min';
    }

    private function shouldProbe(string $id, array $cadences): bool
    {
        if (! isset($cadences[$id])) {
            return true; // no cadence → every sweep (Iteration-4 behavior)
        }

        if ($this->hasOpenEvent($id)) {
            return true; // active problem — always re-probe for recovery
        }

        try {
            $lastProbe = \Illuminate\Support\Facades\Cache::get('ops:sweep:last:'.$id);
        } catch (Throwable) {
            return true; // cache unavailable — probing is always safe
        }

        if (! $lastProbe instanceof \Illuminate\Support\Carbon) {
            return true; // never probed (or cache flushed) — due now
        }

        return $lastProbe->diffInMinutes(now()) >= $cadences[$id];
    }

    private function hasOpenEvent(string $id): bool
    {
        $label = DiagnosticRegistry::label($id);
        $title = "Automated sweep: {$label}";

        try {
            return OpsEvent::query()
                ->where('source', 'sweep')
                ->whereIn('status', ['open', 'acknowledged'])
                ->where('title', $title)
                ->exists();
        } catch (Throwable) {
            return true; // DB trouble — probe anyway (probing is harmless)
        }
    }

    private function sweepOne(string $id, DiagnosticEngine $engine, OpsEventIngestor $ingestor): string
    {
        $result = $engine->probe($id);

        if ($result === null) {
            $this->warn("Skipping unknown diagnostic '{$id}'.");

            return 'inconclusive';
        }

        // Cadence bookkeeping: this check was PROBED now (Iteration 6).
        try {
            \Illuminate\Support\Facades\Cache::put(
                'ops:sweep:last:'.$id,
                now(),
                now()->addDay(),
            );
        } catch (Throwable) {
            // Cache unavailable — the next sweep simply probes again.
        }

        // The finding line for scheduler.log / the console.
        $line = sprintf('[%s] %s — %s', $id, $result->status, $result->summary);

        if ($result->status === 'healthy') {
            if ($this->resolvePriorEvent($id)) {
                $this->line($line.' (recovered — event resolved)');
                $this->alertRecovery($id, $result->summary);

                return 'recovered';
            }

            $this->line($line);

            return 'healthy';
        }

        if ($result->status === 'inconclusive') {
            // Not a problem signal — record nothing, alert nothing.
            $this->line($line);

            return 'inconclusive';
        }

        // degraded | failed → record the deduplicated event + alert.
        $severity = $result->status === 'failed' ? 'error' : 'warning';
        $label = DiagnosticRegistry::label($id);

        $event = null;
        try {
            $event = $ingestor->record([
                'source' => 'sweep',
                'category' => $this->categoryFor($id),
                'severity' => $severity,
                'title' => "Automated sweep: {$label}",
                'message' => $result->summary.' — '.$result->interpretation,
                'context' => [
                    'sweep' => true,
                    'diagnostic' => $id,
                    'status' => $result->status,
                    'findings' => $this->notableFindings($result->findings),
                ],
            ]);
        } catch (Throwable $e) {
            $this->warn('Could not record sweep event: '.mb_substr($e->getMessage(), 0, 200));
        }

        if ($event !== null) {
            try {
                \Illuminate\Support\Facades\Cache::put(
                    'ops:sweep:event:'.$id,
                    $event->id,
                    now()->addDay(),
                );
            } catch (Throwable) {
            }
        }

        try {
            app(OperationalAlertService::class)->alert(
                "OpsCenter sweep: {$label} {$result->status}",
                $result->summary."\n".$this->notableFindingsText($result->findings),
                $severity,
                'ops.sweep.'.$id,
            );
        } catch (Throwable $e) {
            $this->warn('Could not send sweep alert: '.mb_substr($e->getMessage(), 0, 200));
        }

        $this->warn($line.($event !== null ? ' (event #'.$event->id.')' : ' (event recording failed)'));

        return $result->status;
    }

    private function resolvePriorEvent(string $id): bool
    {
        $label = DiagnosticRegistry::label($id);
        $title = "Automated sweep: {$label}";
        $event = null;

        try {
            $cachedId = \Illuminate\Support\Facades\Cache::pull('ops:sweep:event:'.$id);
            if (is_numeric($cachedId) && (int) $cachedId > 0) {
                $event = OpsEvent::find((int) $cachedId);
            }

            $event = $event
                ?? OpsEvent::query()
                    ->where('source', 'sweep')
                    ->whereIn('status', ['open', 'acknowledged'])
                    ->where('title', $title)
                    ->latest('id')
                    ->first();

            if ($event === null || $event->status === 'resolved') {
                return false; // nothing to resolve (never recorded, already resolved, or resolved manually)
            }

            $event->status = 'resolved';
            $event->resolved_at = now();
            $event->save();

            return true;
        } catch (Throwable) {
            return false; // DB/cache trouble — the 7-day auto-resolve is the safety net
        }
    }

    private function alertRecovery(string $id, string $summary): void
    {
        $label = DiagnosticRegistry::label($id);

        try {
            app(OperationalAlertService::class)->alert(
                "OpsCenter sweep: {$label} recovered",
                "The check is healthy again: {$summary}. The sweep event has been resolved automatically.",
                'info',
                'ops.sweep.recovered.'.$id,
            );
        } catch (Throwable) {
            // Never fatal on the happy path.
        }
    }

    private function notableFindings(array $findings): array
    {
        $notable = array_values(array_filter(
            $findings,
            fn ($f) => in_array($f['status'] ?? '', ['warn', 'fail'], true),
        ));

        return array_slice($notable, 0, 5);
    }

    private function notableFindingsText(array $findings): string
    {
        $lines = [];
        foreach ($this->notableFindings($findings) as $finding) {
            $lines[] = '• '.mb_substr((string) $finding['detail'], 0, 220);
        }

        return $lines === [] ? '' : implode("\n", $lines);
    }

    private function categoryFor(string $id): string
    {
        if (isset(self::EVENT_CATEGORIES[$id])) {
            return self::EVENT_CATEGORIES[$id];
        }

        $prefix = explode('.', $id)[0];

        return self::EVENT_CATEGORIES[$prefix] ?? 'INFRASTRUCTURE';
    }
}
